#!/usr/bin/env bash
# Builds a release of the committed code and ships it to the VM over SSH (from a laptop or CI).
#
#   erp-web/deploy/deploy.sh azureuser@<host> <domain>
#   SSH_OPTS="-i ~/.ssh/erp_web_deploy -o Port=22" erp-web/deploy/deploy.sh ...   # extra ssh flags
#   RELEASE_TARBALL=erp-web-abc1234.tar.gz erp-web/deploy/deploy.sh ...           # ship a prebuilt release
#
# Only committed files are sent (build-release.sh uses git archive), so local data and secrets never
# leave this machine. The setup-vm.sh and activate-release.sh that run on the VM come from the release
# itself. On the VM: setup-vm.sh (idempotent), then activate-release.sh, which only switches to the new
# release when the migrations and the health check pass.
set -euo pipefail

usage() {
  echo "usage: deploy.sh <user@host> <domain>" >&2
  exit 2
}

die() {
  printf 'deploy: %s\n' "$*" >&2
  exit 1
}

[ "$#" -eq 2 ] || usage
TARGET="$1"
DOMAIN="$(printf '%s' "$2" | tr '[:upper:]' '[:lower:]')"
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "invalid domain: $2"
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# SSH_OPTS is split on spaces into separate ssh arguments.
read -r -a ssh_opts <<<"${SSH_OPTS:-}"
# Remote commands are built here on purpose, with every value quoted by printf %q.
# shellcheck disable=SC2029
remote() {
  ssh ${ssh_opts[@]+"${ssh_opts[@]}"} "$TARGET" "$@"
}

LOCAL_TMP="$(mktemp -d "${TMPDIR:-/tmp}/erp-web-deploy.XXXXXX")"
REMOTE_TMP=""
cleanup() {
  if [ -n "$REMOTE_TMP" ]; then
    remote "rm -rf -- $(printf '%q' "$REMOTE_TMP")" >/dev/null 2>&1 || true
  fi
  rm -rf "$LOCAL_TMP"
}
trap cleanup EXIT

UPLOAD="$LOCAL_TMP/upload"
mkdir "$UPLOAD"
if [ -n "${RELEASE_TARBALL:-}" ]; then
  [ -f "$RELEASE_TARBALL" ] || die "no such file: $RELEASE_TARBALL"
  cp "$RELEASE_TARBALL" "$UPLOAD/release.tar.gz"
else
  "$DEPLOY_DIR/build-release.sh" "$UPLOAD/release.tar.gz" >/dev/null
fi

# The VM scripts that match the release being deployed.
tar -xzf "$UPLOAD/release.tar.gz" -C "$LOCAL_TMP" ./REVISION ./deploy/setup-vm.sh ./deploy/activate-release.sh 2>/dev/null \
  || die "the release has no REVISION or deploy/ scripts (commit erp-web/deploy first)"
cp "$LOCAL_TMP/deploy/setup-vm.sh" "$LOCAL_TMP/deploy/activate-release.sh" "$UPLOAD/"
SHORT_SHA="$(head -c 7 "$LOCAL_TMP/REVISION")"

echo "Uploading release $SHORT_SHA"
REMOTE_TMP="$(remote 'mktemp -d')"
[[ "$REMOTE_TMP" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "unexpected remote temp directory"
tar -C "$UPLOAD" -cf - release.tar.gz setup-vm.sh activate-release.sh \
  | remote "tar -xf - -C $(printf '%q' "$REMOTE_TMP")"

echo "Setting up the VM and activating $SHORT_SHA"
remote "cd $(printf '%q' "$REMOTE_TMP") && sudo bash setup-vm.sh $(printf '%q' "$DOMAIN") && sudo bash activate-release.sh $(printf '%q' "$REMOTE_TMP/release.tar.gz")"

echo "Deployed $SHORT_SHA to https://$DOMAIN"
