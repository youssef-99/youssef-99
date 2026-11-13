#!/usr/bin/env bash
# Installs a release tarball from build-release.sh on the VM and switches traffic to it.
#
#   sudo bash activate-release.sh <tarball>        # deploy (deploy.sh runs this after setup-vm.sh)
#   sudo bash /opt/erp-web/current/deploy/activate-release.sh --rollback   # back to the previous release
#   sudo bash /opt/erp-web/current/deploy/activate-release.sh --refresh    # re-read the env file
#
# Deploy: extract into releases/<UTC time>-<sha>, link the shared .env, storage and SQLite database,
# snapshot the database, migrate (if that fails the old release keeps serving), create the system data
# on the very first install only, cache config/routes/views, switch the "current" symlink atomically,
# reload PHP-FPM and the queue worker, then check https://<domain>/up and switch back if it fails.
# The newest 5 releases are kept. --rollback discards the current release and does not undo migrations.
#
# Overrides (tests): ERP_NO_SYSTEMD=1 skips systemctl, ERP_RUN_AS_SELF=1 runs artisan as the current
# user without chown, ERP_HEALTH_URL replaces the health URL, ERP_HEALTH_RETRIES, and the paths of
# setup-vm.sh: ERP_APP_DIR, ERP_DATA_DIR, ERP_ETC_DIR, ERP_BACKUP_DIR, ERP_USER, ERP_PHP.
set -euo pipefail
export LC_ALL=C
umask 022

ERP_APP_DIR="${ERP_APP_DIR:-/opt/erp-web}"
ERP_DATA_DIR="${ERP_DATA_DIR:-/var/lib/erp-web}"
ERP_ETC_DIR="${ERP_ETC_DIR:-/etc/erp-web}"
ERP_BACKUP_DIR="${ERP_BACKUP_DIR:-/var/backups/erp-web}"
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

# artisan <release dir> <args...>
artisan() {
  local dir="$1"
  shift
  (cd "$dir" && as_app "$PHP_BIN" artisan "$@")
}

set_owner() {
  [ "$ERP_RUN_AS_SELF" = 1 ] && return 0
  chown "$@"
}

run_systemctl() {
  [ "$ERP_NO_SYSTEMD" = 1 ] && return 0
  systemctl "$@"
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

# Cached config, routes, views, Filament components and icons. "optimize" runs filament:optimize too,
# so it is excluded there and run on its own. "optimize" exits 0 even when a step fails: check the result.
cache_config() {
  artisan "$1" optimize --except=filament --no-interaction >/dev/null || return 1
  artisan "$1" filament:optimize --no-interaction >/dev/null || return 1
  [ -f "$1/bootstrap/cache/config.php" ] || { echo "config cache was not written" >&2; return 1; }
}

# New code everywhere: PHP-FPM (opcache) and the queue worker. Returns non-zero on failure.
reload_services() {
  run_systemctl reload-or-restart "php$PHP_VERSION-fpm" || return 1
  artisan "$CURRENT" queue:restart --no-interaction >/dev/null || return 1
  run_systemctl restart erp-web-queue.service || return 1
}

# health_check <retries>
health_check() {
  local url domain app_url
  local -a args=(-fsS -o /dev/null --max-time 10 --retry "$1" --retry-delay 3 --retry-all-errors)
  if [ -n "${ERP_HEALTH_URL:-}" ]; then
    url="$ERP_HEALTH_URL"
  else
    app_url="$(env_value APP_URL)"
    domain="${app_url#*://}"
    domain="${domain%%/*}"
    domain="${domain%%:*}"
    [ -n "$domain" ] || { echo "APP_URL is not set in $ENV_FILE" >&2; return 1; }
    url="https://$domain/up"
    # Ask Caddy on this machine directly, with the real certificate.
    args+=(--resolve "$domain:443:127.0.0.1")
  fi
  curl "${args[@]}" "$url"
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
  cache_config "$RELEASES/$target" || die "caching the configuration failed"
  if [ "$target" != "$name" ]; then
    switch_to "$target"
  fi
  reload_services || die "reloading the services failed"
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

TARBALL="$1"
[ -f "$TARBALL" ] || die "no such file: $TARBALL"

NEW_DIR=""
SWITCHED=0
cleanup() {
  local status=$?
  if [ "$status" -ne 0 ] && [ "$SWITCHED" = 0 ] && [ -n "$NEW_DIR" ] && [ -d "$NEW_DIR" ]; then
    rm -rf "$NEW_DIR"
  fi
  rm -f "$CURRENT.tmp"
}
trap cleanup EXIT

NEW_DIR="$(mktemp -d "$RELEASES/.incoming.XXXXXX")"
chmod 0755 "$NEW_DIR"
tar --no-same-owner --no-same-permissions -xzf "$TARBALL" -C "$NEW_DIR"
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

# Shared state lives outside the release (setup-vm.sh creates it; recreate what is missing).
for dir in app/public app/private framework/cache/data framework/sessions framework/views logs; do
  if [ ! -d "$ERP_DATA_DIR/storage/$dir" ]; then
    mkdir -p "$ERP_DATA_DIR/storage/$dir"
    set_owner -R "$ERP_USER:$ERP_USER" "$ERP_DATA_DIR/storage"
  fi
done
ln -sfn "$ENV_FILE" "$NEW_DIR/.env"
rm -rf "$NEW_DIR/storage" "$NEW_DIR/public/storage"
ln -sfn "$ERP_DATA_DIR/storage" "$NEW_DIR/storage"
ln -sfn "$ERP_DATA_DIR/storage/app/public" "$NEW_DIR/public/storage"
mkdir -p "$NEW_DIR/bootstrap/cache"
set_owner -R "$ERP_USER:$ERP_USER" "$NEW_DIR/bootstrap/cache"
# The cached config holds APP_KEY: only the app user may read it.
chmod 0750 "$NEW_DIR/bootstrap/cache"

DB_CONNECTION="$(env_value DB_CONNECTION)"
if [ "${DB_CONNECTION:-sqlite}" = sqlite ]; then
  DB_FILE="$(env_value DB_DATABASE)"
  DB_FILE="${DB_FILE:-$ERP_DATA_DIR/database/database.sqlite}"
  mkdir -p "$(dirname "$DB_FILE")"
  chmod 0750 "$(dirname "$DB_FILE")"
  set_owner "$ERP_USER:$ERP_USER" "$(dirname "$DB_FILE")"
  [ -f "$DB_FILE" ] || : >"$DB_FILE"
  chmod 0660 "$DB_FILE"
  set_owner "$ERP_USER:$ERP_USER" "$DB_FILE"

  # A copy from just before the migrations; the newest 5 are kept.
  if [ -s "$DB_FILE" ] && command -v sqlite3 >/dev/null 2>&1; then
    mkdir -p "$ERP_BACKUP_DIR"
    chmod 0700 "$ERP_BACKUP_DIR"
    snapshot="$ERP_BACKUP_DIR/pre-deploy-$name.sqlite3"
    (umask 077 && sqlite3 "$DB_FILE" ".backup '$snapshot'" && gzip -f "$snapshot") || die "the database snapshot failed"
    find "$ERP_BACKUP_DIR" -maxdepth 1 -type f -name 'pre-deploy-*.sqlite3.gz' | sort | head -n -5 | xargs -r rm -f
  fi
fi

log "Migrating the database"
artisan "$NEW_DIR" migrate --force --no-interaction || die "migrations failed; the previous release is still serving"

if [ ! -f "$SEED_MARKER" ]; then
  # First install only: SystemSeeder re-sets the default posting accounts every time it runs.
  # It runs before the config is cached so that it reads ERP_ADMIN_PASSWORD from the environment.
  [ -s "$ADMIN_PASSWORD_FILE" ] || die "$ADMIN_PASSWORD_FILE is missing: run setup-vm.sh first"
  log "First install: creating the system data and the admin user"
  (
    ERP_ADMIN_PASSWORD="$(head -n 1 "$ADMIN_PASSWORD_FILE")"
    export ERP_ADMIN_PASSWORD
    artisan "$NEW_DIR" db:seed --class='Database\Seeders\SystemSeeder' --force --no-interaction >/dev/null
  ) || die "seeding the system data failed"
  date -u +%Y-%m-%dT%H:%M:%SZ >"$SEED_MARKER"
fi

log "Caching configuration, routes and views"
cache_config "$NEW_DIR" || die "caching the configuration failed"

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
    die "health check failed for ${SHA:0:7}; switched back to $PREVIOUS"
  fi
  die "health check failed for ${SHA:0:7} and there is no previous release to switch back to"
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
find "$ERP_DATA_DIR/storage/framework/views" -maxdepth 1 -type f -name '*.php' -mtime +30 -delete

log "Release ${SHA:0:7} is live"
