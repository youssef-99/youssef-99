#!/usr/bin/env bash
# Builds a release of the committed code and ships it to the VM over SSH (from a laptop or CI).
#
#   erp-web/deploy/deploy.sh azureuser@<host> <domain>
#   SSH_OPTS="-i ~/.ssh/erp_web_deploy -o Port=22" erp-web/deploy/deploy.sh ...   # extra ssh flags
#   RELEASE_TARBALL=erp-web-abc1234.tar.gz erp-web/deploy/deploy.sh ...           # ship a prebuilt release
#
# Only committed files are sent (build-release.sh uses git archive), so local data and secrets never
# leave this machine. The scripts that run as root on the VM, setup-vm.sh and activate-release.sh, are
# taken from git at the release's commit, never from the build output (Composer scripts and artisan run
# during a build), and the copies inside the release must match them. That commit must be in this clone.
# On the VM, under one lock: setup-vm.sh (idempotent), then activate-release.sh, which only switches to
# the new release when the migrations and the health check pass.
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
# user@host with a DNS name or IPv4 address; nothing ssh could read as an option.
[[ "$TARGET" =~ ^[A-Za-z_][A-Za-z0-9._-]*@[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] \
  || die "invalid target: expected user@host (a DNS name or IPv4 address)"
DOMAIN="$(printf '%s' "$2" | tr '[:upper:]' '[:lower:]')"
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "invalid domain: $2"
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(git -C "$DEPLOY_DIR" rev-parse --show-toplevel 2>/dev/null)" || die "$DEPLOY_DIR is not inside a git clone"
DEPLOY_PREFIX="$(git -C "$DEPLOY_DIR" rev-parse --show-prefix)" # "erp-web/deploy/"

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

tar -xzf "$UPLOAD/release.tar.gz" -C "$LOCAL_TMP" ./REVISION ./deploy/setup-vm.sh ./deploy/activate-release.sh 2>/dev/null \
  || die "the release has no REVISION or deploy/ scripts (commit erp-web/deploy first)"
SHA="$(head -n 1 "$LOCAL_TMP/REVISION")"
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || die "the release has an invalid REVISION"
SHORT_SHA="${SHA:0:7}"

# The VM scripts of the release's commit, from git (run from the repo root: inside a subdirectory
# git archive only archives that path).
git -C "$REPO_ROOT" archive --format=tar "$SHA:${DEPLOY_PREFIX%/}" setup-vm.sh activate-release.sh 2>/dev/null \
  | tar -x -C "$UPLOAD" 2>/dev/null \
  || die "commit $SHORT_SHA is not in this clone (git fetch it first) or has no deploy scripts"
for script in setup-vm.sh activate-release.sh; do
  cmp -s "$UPLOAD/$script" "$LOCAL_TMP/deploy/$script" \
    || die "deploy/$script in the release differs from commit $SHORT_SHA; refusing to deploy"
done

echo "Uploading release $SHORT_SHA"
REMOTE_TMP="$(remote 'mktemp -d')"
[[ "$REMOTE_TMP" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "unexpected remote temp directory"
tar -C "$UPLOAD" -cf - release.tar.gz setup-vm.sh activate-release.sh \
  | remote "tar -xf - -C $(printf '%q' "$REMOTE_TMP")"

echo "Setting up the VM and activating $SHORT_SHA"
# One deploy at a time, setup included: a laptop deploy and a CI deploy could otherwise run setup-vm.sh
# together. The lock lives in root's /run (activate-release.sh also takes its own, different lock).
# shellcheck disable=SC2016 # "$1" and "$2" are expanded by bash -c on the VM.
remote_steps='bash setup-vm.sh "$1" && bash activate-release.sh "$2"'
status=0
remote "cd $(printf '%q' "$REMOTE_TMP") && sudo flock -w 900 -E 75 /run/erp-web-deploy.lock bash -c $(printf '%q' "$remote_steps") deploy $(printf '%q' "$DOMAIN") $(printf '%q' "$REMOTE_TMP/release.tar.gz")" \
  || status=$?
[ "$status" -ne 75 ] || die "another deploy has held the VM's deploy lock for 15 minutes"
[ "$status" -eq 0 ] || die "the deploy failed on the VM (see the messages above; full output: sudo tail -n 80 /var/log/erp-web-deploy.log on the VM)"

echo "Deployed $SHORT_SHA to https://$DOMAIN"
