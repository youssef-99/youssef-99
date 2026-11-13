#!/usr/bin/env bash
# Installs a release tarball from build-release.sh on the VM and switches traffic to it.
#
#   sudo bash activate-release.sh <tarball>        # deploy (deploy.sh runs this after setup-vm.sh)
#   sudo bash /opt/erp-web/current/deploy/activate-release.sh --rollback   # back to the previous release
#   sudo bash /opt/erp-web/current/deploy/activate-release.sh --refresh    # re-read the env file
#
# Deploy: extract into releases/<UTC time>-<sha>, link the shared .env, storage and SQLite database,
# snapshot the database, migrate (if that fails the old release keeps serving), create the system data
# on the very first install only (never on a database that already has users, such as a restored backup),
# cache config/events/routes/views, switch the "current" symlink atomically, reload PHP-FPM and the queue
# worker, then check https://<domain>/up and /admin/login and switch back if that fails. The newest 5
# releases are kept. Neither the switch back nor --rollback undoes migrations: the pre-deploy snapshot in
# /var/backups/erp-web is the way back for the database.
#
# Command output (artisan errors can quote SQL with real data) goes only to the root-only log
# /var/log/erp-web-deploy.log, because deploy.sh may run in public CI logs. Root never creates or changes
# files inside erpweb's directories (storage/, database/): erpweb does that itself.
#
# Overrides (tests): ERP_NO_SYSTEMD=1 skips systemctl, ERP_RUN_AS_SELF=1 runs artisan as the current
# user without chown, ERP_HEALTH_URL replaces the health URLs, ERP_HEALTH_RETRIES, ERP_DEPLOY_LOG, and the
# paths of setup-vm.sh: ERP_APP_DIR, ERP_DATA_DIR, ERP_ETC_DIR, ERP_BACKUP_DIR, ERP_USER, ERP_PHP.
set -euo pipefail
export LC_ALL=C
umask 022

ERP_APP_DIR="${ERP_APP_DIR:-/opt/erp-web}"
ERP_DATA_DIR="${ERP_DATA_DIR:-/var/lib/erp-web}"
ERP_ETC_DIR="${ERP_ETC_DIR:-/etc/erp-web}"
ERP_BACKUP_DIR="${ERP_BACKUP_DIR:-/var/backups/erp-web}"
ERP_DEPLOY_LOG="${ERP_DEPLOY_LOG:-/var/log/erp-web-deploy.log}"
ERP_USER="${ERP_USER:-erpweb}"
ERP_PHP="${ERP_PHP:-php8.3}"
ERP_NO_SYSTEMD="${ERP_NO_SYSTEMD:-0}"
ERP_RUN_AS_SELF="${ERP_RUN_AS_SELF:-0}"
PHP_VERSION="${ERP_PHP_VERSION:-${ERP_PHP#php}}"

KEEP_RELEASES=5
RELEASES="$ERP_APP_DIR/releases"
CURRENT="$ERP_APP_DIR/current"
ENV_FILE="$ERP_ETC_DIR/erp-web.env"
ADMIN_PASSWORD_FILE="$ERP_ETC_DIR/initial-admin-password"
SEED_MARKER="$ERP_DATA_DIR/.system-seeded"
RELEASE_NAME_RE='^[0-9]{14}-[0-9a-f]{7}$'
LOG_HINT="details: sudo tail -n 80 $ERP_DEPLOY_LOG on the VM"

usage() {
  echo "usage: activate-release.sh <tarball> | --rollback | --refresh" >&2
  exit 2
}

log() {
  printf '==> %s\n' "$*"
}

die() {
  printf 'activate-release: %s\n' "$*" >&2
  exit 1
}

[ "$#" -eq 1 ] || usage
MODE=deploy
case "$1" in
  --rollback) MODE=rollback ;;
  --refresh) MODE=refresh ;;
  -*) usage ;;
esac

if [ "$ERP_RUN_AS_SELF" != 1 ] && [ "$(id -u)" -ne 0 ]; then
  die "run as root (sudo bash activate-release.sh ...)"
fi
[ -d "$RELEASES" ] || die "$RELEASES is missing: run setup-vm.sh first"
[ -f "$ENV_FILE" ] || die "$ENV_FILE is missing: run setup-vm.sh first"
PHP_BIN="$(command -v "$ERP_PHP")" || die "$ERP_PHP is not installed"

TARBALL=""
if [ "$MODE" = deploy ]; then
  [ -f "$1" ] || die "no such file: $1"
  TARBALL="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
fi
# Every path below is absolute; erpweb may not be able to enter the caller's directory.
cd /

(umask 077 && : >>"$ERP_DEPLOY_LOG") || die "cannot write $ERP_DEPLOY_LOG"
chmod 0600 "$ERP_DEPLOY_LOG"
printf '\n=== %s activate-release.sh %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$MODE" >>"$ERP_DEPLOY_LOG"

# One deploy at a time (a laptop deploy and a CI deploy could otherwise overlap).
exec 9>"$ERP_APP_DIR/.deploy.lock"
flock -w 900 9 || die "another deploy is still running"

# env_value <KEY>: the last KEY=value in the env file, without surrounding quotes.
env_value() {
  sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

as_app() {
  if [ "$ERP_RUN_AS_SELF" = 1 ]; then
    "$@"
  else
    runuser -u "$ERP_USER" -- "$@"
  fi
}

# artisan <release dir> <args...>: all output goes to the deploy log.
artisan() {
  local dir="$1"
  shift
  (cd "$dir" && as_app "$PHP_BIN" artisan "$@") >>"$ERP_DEPLOY_LOG" 2>&1
}

# artisan_stdout <release dir> <args...>: stdout to the caller, errors to the deploy log. Tinker needs
# a writable config directory, and erpweb's home ($ERP_DATA_DIR) belongs to root.
artisan_stdout() {
  local dir="$1"
  shift
  (cd "$dir" && as_app env XDG_CONFIG_HOME="$APP_TMP/config" "$PHP_BIN" artisan "$@") 2>>"$ERP_DEPLOY_LOG"
}

set_owner() {
  [ "$ERP_RUN_AS_SELF" = 1 ] && return 0
  chown "$@"
}

run_systemctl() {
  [ "$ERP_NO_SYSTEMD" = 1 ] && return 0
  systemctl "$@"
}

refuse_link() {
  [ ! -L "$1" ] || die "$1 is a symbolic link; refusing to use it (inspect and remove it, then run setup-vm.sh)"
}

current_name() {
  if [ -L "$CURRENT" ]; then
    basename "$(readlink "$CURRENT")"
  fi
}

# Release directory names, oldest first (the UTC timestamp prefix sorts chronologically).
release_names() {
  local dir name
  for dir in "$RELEASES"/*/; do
    name="$(basename "$dir")"
    if [[ "$name" =~ $RELEASE_NAME_RE ]]; then
      echo "$name"
    fi
  done
}

# The newest release older than <name>.
release_before() {
  local name candidate result=""
  name="$1"
  while IFS= read -r candidate; do
    if [[ "$candidate" < "$name" ]]; then
      result="$candidate"
    fi
  done < <(release_names)
  echo "$result"
}

switch_to() {
  ln -sfn "releases/$1" "$CURRENT.tmp"
  mv -T "$CURRENT.tmp" "$CURRENT"
}

# Cached config, events, routes and views, then Filament components and icons. Each cache command on its
# own: "php artisan optimize" exits 0 even when one of its steps fails.
cache_config() {
  local command
  for command in config:cache event:cache route:cache view:cache filament:optimize; do
    artisan "$1" "$command" --no-interaction || { echo "php artisan $command failed" >&2; return 1; }
  done
  [ -f "$1/bootstrap/cache/config.php" ] || { echo "config cache was not written" >&2; return 1; }
}

# New code everywhere: PHP-FPM (opcache) and the queue worker. Returns non-zero on failure.
reload_services() {
  run_systemctl reload-or-restart "php$PHP_VERSION-fpm" || return 1
  artisan "$CURRENT" queue:restart --no-interaction || return 1
  run_systemctl restart erp-web-queue.service || return 1
}

# health_check <retries>: /up (PHP boots), then /admin/login, which renders Filament and writes a
# database session row (so erpweb can write the database). Asks Caddy on this machine, with the real
# certificate. A redirect is not followed: it would count as a failure.
health_check() {
  local domain app_url
  local -a args=(-fsS -o /dev/null --max-time 10 --retry-delay 3 --retry-all-errors)
  if [ -n "${ERP_HEALTH_URL:-}" ]; then
    curl "${args[@]}" --retry "$1" "$ERP_HEALTH_URL" || return 1
    return 0
  fi
  app_url="$(env_value APP_URL)"
  domain="${app_url#*://}"
  domain="${domain%%/*}"
  domain="${domain%%:*}"
  [ -n "$domain" ] || { echo "APP_URL is not set in $ENV_FILE" >&2; return 1; }
  args+=(--resolve "$domain:443:127.0.0.1")
  curl "${args[@]}" --retry "$1" "https://$domain/up" || return 1
  curl "${args[@]}" --retry 2 "https://$domain/admin/login" || return 1
}

# Uses ERP_HEALTH_RETRIES or the given default.
health_retries() {
  echo "${ERP_HEALTH_RETRIES:-$1}"
}

if [ "$MODE" = rollback ] || [ "$MODE" = refresh ]; then
  name="$(current_name)"
  if [ -z "$name" ] || [ ! -d "$RELEASES/$name" ]; then
    die "there is no current release"
  fi
  if [ "$MODE" = rollback ]; then
    target="$(release_before "$name")"
    [ -n "$target" ] || die "no release older than $name to roll back to"
  else
    target="$name"
  fi
  log "Caching configuration for $target"
  cache_config "$RELEASES/$target" || die "caching the configuration failed ($LOG_HINT)"
  if [ "$target" != "$name" ]; then
    switch_to "$target"
  fi
  reload_services || die "reloading the services failed ($LOG_HINT)"
  health_check "$(health_retries 10)" || die "health check failed on $target"
  if [ "$target" != "$name" ]; then
    rm -rf "${RELEASES:?}/$name"
    log "Rolled back from $name to $target (database migrations were not reverted)"
  else
    log "Reloaded $target with the current $ENV_FILE"
  fi
  exit 0
fi

# --- Deploy ------------------------------------------------------------------------------------

NEW_DIR=""
SWITCHED=0
APP_TMP=""
cleanup() {
  local status=$?
  if [ "$status" -ne 0 ] && [ "$SWITCHED" = 0 ] && [ -n "$NEW_DIR" ] && [ -d "$NEW_DIR" ]; then
    rm -rf "$NEW_DIR"
  fi
  rm -f "$CURRENT.tmp"
  if [ -n "$APP_TMP" ]; then
    rm -rf "$APP_TMP"
  fi
}
trap cleanup EXIT

# Scratch space erpweb can write to: tinker's config directory and the database snapshot.
APP_TMP="$(mktemp -d "${TMPDIR:-/tmp}/erp-web-activate.XXXXXX")"
set_owner -h "$ERP_USER:$ERP_USER" "$APP_TMP"

NEW_DIR="$(mktemp -d "$RELEASES/.incoming.XXXXXX")"
tar --no-same-owner --no-same-permissions -xzf "$TARBALL" -C "$NEW_DIR"
# The archive's modes follow the build machine's umask: make the code readable by erpweb and Caddy.
chmod 0755 "$NEW_DIR"
chmod -R u+rwX,go+rX,go-w "$NEW_DIR"
if [ ! -f "$NEW_DIR/artisan" ] || [ ! -f "$NEW_DIR/REVISION" ]; then
  die "$TARBALL is not a release built by build-release.sh"
fi
SHA="$(head -n 1 "$NEW_DIR/REVISION")"
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || die "invalid REVISION in $TARBALL"

name="$(date -u +%Y%m%d%H%M%S)-${SHA:0:7}"
while [ -e "$RELEASES/$name" ]; do
  sleep 1
  name="$(date -u +%Y%m%d%H%M%S)-${SHA:0:7}"
done
mv "$NEW_DIR" "$RELEASES/$name"
NEW_DIR="$RELEASES/$name"
PREVIOUS="$(current_name)"
log "Installing ${SHA:0:7} as $name"

# Shared state lives outside the release. $ERP_DATA_DIR belongs to root (setup-vm.sh), so erpweb cannot
# swap storage/ for a link; inside storage/, erpweb itself creates whatever is missing.
refuse_link "$ERP_DATA_DIR"
if [ ! -d "$ERP_DATA_DIR" ]; then
  install -d -m 0751 "$ERP_DATA_DIR"
  set_owner -h "root:$ERP_USER" "$ERP_DATA_DIR"
fi
if [ "$ERP_RUN_AS_SELF" != 1 ] && [ "$(stat -c %u "$ERP_DATA_DIR")" != 0 ]; then
  die "$ERP_DATA_DIR must belong to root: run setup-vm.sh first"
fi
refuse_link "$ERP_DATA_DIR/storage"
if [ ! -d "$ERP_DATA_DIR/storage" ]; then
  install -d -m 0751 "$ERP_DATA_DIR/storage"
  set_owner -h "$ERP_USER:$ERP_USER" "$ERP_DATA_DIR/storage"
fi
as_app mkdir -p "$ERP_DATA_DIR"/storage/{app/public,app/private,framework/cache/data,framework/sessions,framework/views,logs} \
  || die "cannot create the storage directories in $ERP_DATA_DIR/storage: run setup-vm.sh to repair them"
ln -sfn "$ENV_FILE" "$NEW_DIR/.env"
rm -rf "$NEW_DIR/storage" "$NEW_DIR/public/storage"
ln -sfn "$ERP_DATA_DIR/storage" "$NEW_DIR/storage"
ln -sfn "$ERP_DATA_DIR/storage/app/public" "$NEW_DIR/public/storage"
mkdir -p "$NEW_DIR/bootstrap/cache"
set_owner -R "$ERP_USER:$ERP_USER" "$NEW_DIR/bootstrap/cache"
# The cached config holds APP_KEY: only the app user may read it.
chmod 0750 "$NEW_DIR/bootstrap/cache"

# take_snapshot <destination .sqlite3.gz>: sqlite3 runs as erpweb and writes into $APP_TMP; root only
# moves the finished copy into the root-only backup directory.
take_snapshot() {
  local staged="$APP_TMP/snapshot.sqlite3"
  # shellcheck disable=SC2016 # $1 and $2 are expanded by sh -c.
  as_app sh -c 'umask 077 && sqlite3 -cmd ".timeout 30000" "$1" ".backup \"$2\"" && gzip -f "$2"' \
    sh "$DB_FILE" "$staged" >>"$ERP_DEPLOY_LOG" 2>&1 || return 1
  if [ ! -f "$staged.gz" ] || [ -L "$staged.gz" ]; then
    return 1
  fi
  mv -f "$staged.gz" "$1" || return 1
  if [ -L "$1" ]; then
    rm -f "$1"
    return 1
  fi
  set_owner -h root:root "$1"
  chmod 0600 "$1"
}

snapshot=""
DB_CONNECTION="$(env_value DB_CONNECTION)"
if [ "${DB_CONNECTION:-sqlite}" = sqlite ]; then
  DB_FILE="$(env_value DB_DATABASE)"
  DB_FILE="${DB_FILE:-$ERP_DATA_DIR/database/database.sqlite}"
  DB_DIR="$(dirname "$DB_FILE")"
  refuse_link "$DB_DIR"
  if [ ! -d "$DB_DIR" ]; then
    install -d -m 0750 "$DB_DIR"
    set_owner -h "$ERP_USER:$ERP_USER" "$DB_DIR"
  fi
  # The directory is erpweb's: erpweb creates the file and sets its mode, root never follows it.
  # shellcheck disable=SC2016 # $1 is expanded by sh -c.
  as_app sh -c 'umask 007 && { [ -e "$1" ] || : >"$1"; } && chmod 0660 "$1"' sh "$DB_FILE" >>"$ERP_DEPLOY_LOG" 2>&1 \
    || die "$DB_FILE must be a file that $ERP_USER owns (sudo chown $ERP_USER:$ERP_USER $DB_FILE)"

  # A copy from just before the migrations; the newest 5 are kept.
  if [ -s "$DB_FILE" ] && command -v sqlite3 >/dev/null 2>&1; then
    install -d -m 0700 "$ERP_BACKUP_DIR"
    chmod 0700 "$ERP_BACKUP_DIR"
    snapshot="$ERP_BACKUP_DIR/pre-deploy-$name.sqlite3.gz"
    take_snapshot "$snapshot" || die "the database snapshot failed ($LOG_HINT)"
    find "$ERP_BACKUP_DIR" -maxdepth 1 -type f -name 'pre-deploy-*.sqlite3.gz' | sort | head -n -5 | xargs -r rm -f
  fi
fi

log "Migrating the database"
if ! artisan "$NEW_DIR" migrate --force --no-interaction; then
  message="migrations failed ($LOG_HINT); the previous release is still serving"
  if [ -n "$snapshot" ]; then
    # Laravel runs SQLite migrations outside a transaction.
    message="$message. SQLite keeps whatever part of the failed migration already ran: if it changed the schema, restore $snapshot (README > Backups > Restore) before deploying again"
  fi
  die "$message"
fi

write_seed_marker() {
  rm -f "$SEED_MARKER.tmp"
  date -u +%Y-%m-%dT%H:%M:%SZ >"$SEED_MARKER.tmp"
  mv -f "$SEED_MARKER.tmp" "$SEED_MARKER"
  rm -f "$SEEDING_MARKER"
}

# Present while the first seeding runs: SystemSeeder is not one transaction, so a seeding that was
# interrupted after it created the admin user must run again rather than look like a restored database.
SEEDING_MARKER="$ERP_DATA_DIR/.system-seeding"
if [ ! -f "$SEED_MARKER" ]; then
  # First install only: SystemSeeder re-sets the default posting accounts every time it runs, so it must
  # never run on a database that is already in use (for example a backup restored onto a new VM).
  if [ -f "$SEEDING_MARKER" ]; then
    has_users=no
  else
    has_users="$(artisan_stdout "$NEW_DIR" tinker --execute 'echo Illuminate\Support\Facades\DB::table("users")->exists() ? "yes" : "no";' | tail -n 1)" \
      || die "could not check whether the database already has users ($LOG_HINT)"
  fi
  case "$has_users" in
    yes)
      log "The database already has users (a restored backup?): not creating the system data"
      write_seed_marker
      # The generated password belongs to no account in this database.
      rm -f "$ADMIN_PASSWORD_FILE"
      ;;
    no)
      # Before the config is cached, so that the seeder reads ERP_ADMIN_PASSWORD from the environment.
      [ -s "$ADMIN_PASSWORD_FILE" ] || die "$ADMIN_PASSWORD_FILE is missing: run setup-vm.sh first"
      log "First install: creating the system data and the admin user"
      rm -f "$SEEDING_MARKER"
      : >"$SEEDING_MARKER"
      (
        ERP_ADMIN_PASSWORD="$(head -n 1 "$ADMIN_PASSWORD_FILE")"
        export ERP_ADMIN_PASSWORD
        artisan "$NEW_DIR" db:seed --class='Database\Seeders\SystemSeeder' --force --no-interaction
      ) || die "seeding the system data failed ($LOG_HINT)"
      write_seed_marker
      ;;
    *) die "could not check whether the database already has users ($LOG_HINT)" ;;
  esac
fi

log "Caching configuration, routes and views"
cache_config "$NEW_DIR" 2>>"$ERP_DEPLOY_LOG" || die "caching the configuration failed ($LOG_HINT)"

switch_to "$name"
SWITCHED=1
log "Switched to $name"

# A first install gets more retries: Caddy may still be obtaining the certificate.
retries=10
[ -n "$PREVIOUS" ] || retries=40
healthy=1
if ! reload_services; then
  healthy=0
elif ! health_check "$(health_retries "$retries")"; then
  healthy=0
fi

if [ "$healthy" = 0 ]; then
  if [ -n "$PREVIOUS" ] && [ -d "$RELEASES/$PREVIOUS" ]; then
    switch_to "$PREVIOUS"
    reload_services || true
    rm -rf "$NEW_DIR"
    die "health check failed for ${SHA:0:7}; switched back to $PREVIOUS (its migrations stay applied; $LOG_HINT)"
  fi
  die "health check failed for ${SHA:0:7} and there is no previous release to switch back to ($LOG_HINT)"
fi

# Keep the newest releases, and never the current or the previous one.
mapfile -t all_releases < <(release_names)
excess=$((${#all_releases[@]} - KEEP_RELEASES))
for old in "${all_releases[@]:0:$((excess > 0 ? excess : 0))}"; do
  if [ "$old" != "$name" ] && [ "$old" != "$PREVIOUS" ]; then
    rm -rf "${RELEASES:?}/$old"
  fi
done
find "$RELEASES" -maxdepth 1 -name '.incoming.*' -mmin +60 -exec rm -rf {} +
# Compiled views are shared by all releases; old ones are recompiled on demand if still needed.
as_app find "$ERP_DATA_DIR/storage/framework/views" -maxdepth 1 -type f -name '*.php' -mtime +30 -delete \
  >>"$ERP_DEPLOY_LOG" 2>&1 || true

log "Release ${SHA:0:7} is live"
