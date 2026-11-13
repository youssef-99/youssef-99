#!/usr/bin/env bash
# Builds a release tarball of the committed erp-web code with production dependencies only.
#
#   erp-web/deploy/build-release.sh                        # -> ./erp-web-<shortsha>.tar.gz
#   erp-web/deploy/build-release.sh /tmp/release.tar.gz    # choose the output file
#   REF=v1.2.0 erp-web/deploy/build-release.sh             # another commit, tag or branch (default HEAD)
#
# Needs git, PHP 8.3 and Composer. Only committed files are packaged (git archive), so .env,
# storage/, database.sqlite and the working-tree vendor/ never end up in a release. Composer's
# post-autoload-dump scripts publish the Filament assets into public/.
# Progress goes to stderr; the last line on stdout is the tarball's absolute path. OUTPUT_TARBALL may be
# an existing directory: the default file name is then written there.
set -euo pipefail
# Release files must be readable by the app user and Caddy on the VM, whatever this machine's umask.
umask 022

REF="${REF:-HEAD}"
CALLER_DIR="$PWD"
APP_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

die() {
  printf 'build-release: %s\n' "$*" >&2
  exit 1
}

command -v git >/dev/null 2>&1 || die "git is required"
command -v php >/dev/null 2>&1 || die "php 8.3 is required"
command -v composer >/dev/null 2>&1 || die "composer is required"

cd "$APP_SRC"
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "$APP_SRC is not inside a git work tree"
SHA="$(git rev-parse --verify --quiet "${REF}^{commit}")" || die "unknown git ref: $REF"
SHORT_SHA="${SHA:0:7}"
REPO_ROOT="$(git rev-parse --show-toplevel)"
APP_PREFIX="$(git rev-parse --show-prefix)" # "erp-web/": where the app lives inside the repo

OUTPUT="${1:-erp-web-$SHORT_SHA.tar.gz}"
case "$OUTPUT" in
  /*) ;;
  *) OUTPUT="$CALLER_DIR/$OUTPUT" ;;
esac
if [ -d "$OUTPUT" ]; then
  OUTPUT="${OUTPUT%/}/erp-web-$SHORT_SHA.tar.gz"
fi
case "$OUTPUT" in
  */) die "output directory does not exist: $OUTPUT" ;;
esac
OUTPUT_DIR="$(dirname "$OUTPUT")"
[ -d "$OUTPUT_DIR" ] || die "output directory does not exist: $OUTPUT_DIR"
OUTPUT="$(cd "$OUTPUT_DIR" && pwd)/$(basename "$OUTPUT")"

if [ "$REF" = HEAD ] && [ -n "$(git status --porcelain --untracked-files=no -- .)" ]; then
  echo "build-release: uncommitted changes are not included; packaging commit $SHORT_SHA" >&2
fi

WORK="$(mktemp -d "${TMPDIR:-/tmp}/erp-web-build.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
STAGE="$WORK/app"
mkdir "$STAGE"

echo "build-release: packaging $SHORT_SHA" >&2
# git archive must run from the repo root: inside a subdirectory it only archives that path of the tree.
git -C "$REPO_ROOT" archive --format=tar "${SHA}:${APP_PREFIX%/}" | tar -x -C "$STAGE"
if [ ! -f "$STAGE/artisan" ] || [ ! -f "$STAGE/composer.lock" ]; then
  die "commit $SHORT_SHA has no Laravel app at ${APP_PREFIX:-the repo root}"
fi

echo "build-release: composer install --no-dev (output in case of failure only)" >&2
if ! (cd "$STAGE" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress) >"$WORK/composer.log" 2>&1; then
  cat "$WORK/composer.log" >&2
  die "composer install failed"
fi

cd "$STAGE"
# Composer skips scripts when it runs as root without COMPOSER_ALLOW_SUPERUSER; run them directly then.
if [ ! -f bootstrap/cache/packages.php ] || [ ! -d public/js/filament ]; then
  echo "build-release: composer scripts did not run; running package:discover and filament:assets" >&2
  php artisan package:discover --no-interaction >&2
  php artisan filament:assets --no-interaction >&2
fi
if [ ! -f bootstrap/cache/packages.php ] || [ ! -d public/js/filament ] || [ ! -d public/css/filament ]; then
  die "Filament assets or the package manifest are missing"
fi
php artisan --version >/dev/null || die "the release does not boot with production dependencies"

# Composer scripts and artisan just ran inside the release: take deploy/, whose scripts run as root on
# the VM, from git again (deploy.sh also checks them against the commit).
rm -rf deploy
mkdir deploy
git -C "$REPO_ROOT" archive --format=tar "${SHA}:${APP_PREFIX}deploy" | tar -x -C deploy

# Development-only files. deploy/ stays: the VM uses it for rollbacks.
rm -rf tests .github .claude .mcp.json boost.json AGENTS.md CLAUDE.md phpunit.xml docs \
  .editorconfig .styleci.yml CHANGELOG.md .phpunit.result.cache .phpunit.cache
# Never ship local state: secrets, logs, compiled views, SQLite files or cached config.
rm -f .env .env.backup .env.production
find storage -type f ! -name .gitignore -delete
find database -maxdepth 1 -name '*.sqlite*' -delete
rm -f bootstrap/cache/config.php bootstrap/cache/events.php bootstrap/cache/routes-*.php

printf '%s\n' "$SHA" >REVISION
# The build machine's umask or git's modes must not make files unreadable on the VM.
chmod -R u+rwX,go+rX,go-w "$STAGE"

COPYFILE_DISABLE=1 tar -C "$STAGE" -czf "$WORK/release.tar.gz" .
mv -f "$WORK/release.tar.gz" "$OUTPUT"
echo "build-release: wrote $(du -h "$OUTPUT" | cut -f1) release $SHORT_SHA" >&2
echo "$OUTPUT"
