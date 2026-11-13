#!/usr/bin/env bash
# One-time Azure setup: an Ubuntu 24.04 VM with a public DNS name, SSH key login only and ports 80/443 open.
#
#   erp-web/deploy/azure-create-vm.sh <resource-group> <vm-name> <dns-label> [location] [size]
#   erp-web/deploy/azure-create-vm.sh erp-rg erp-vm my-pharmacy-erp          # uaenorth, Standard_B1ms
#   SSH_PUBLIC_KEY=~/.ssh/other.pub erp-web/deploy/azure-create-vm.sh ...     # another public key
#
# Needs the Azure CLI, logged in (az login). Safe to re-run: an existing group or VM is kept.
# The site becomes https://<dns-label>.<location>.cloudapp.azure.com
#   location  uaenorth (closest generally available region to Saudi Arabia); any region works
#   size      Standard_B1ms (2 GB RAM). Standard_B1s (1 GB) also works thanks to the swap file.
set -euo pipefail

usage() {
  echo "usage: azure-create-vm.sh <resource-group> <vm-name> <dns-label> [location] [size]" >&2
  exit 2
}

die() {
  printf 'azure-create-vm: %s\n' "$*" >&2
  exit 1
}

if [ "$#" -lt 3 ] || [ "$#" -gt 5 ]; then
  usage
fi
RESOURCE_GROUP="$1"
VM_NAME="$2"
DNS_LABEL="$3"
LOCATION="${4:-uaenorth}"
SIZE="${5:-Standard_B1ms}"
ADMIN_USER=azureuser
IMAGE=Canonical:ubuntu-24_04-lts:server:latest

[[ "$DNS_LABEL" =~ ^[a-z][a-z0-9-]{1,61}[a-z0-9]$ ]] \
  || die "dns-label must be 3-63 lowercase letters, digits or hyphens, starting with a letter"
command -v az >/dev/null 2>&1 || die "install the Azure CLI first: https://learn.microsoft.com/cli/azure/install-azure-cli"
az account show --output none 2>/dev/null || die "log in first: az login"

if [ "$(az group exists --name "$RESOURCE_GROUP")" != true ]; then
  echo "Creating resource group $RESOURCE_GROUP in $LOCATION"
  az group create --name "$RESOURCE_GROUP" --location "$LOCATION" --output none
fi

if az vm show --resource-group "$RESOURCE_GROUP" --name "$VM_NAME" --output none 2>/dev/null; then
  echo "VM $VM_NAME already exists; keeping it"
else
  if [ -n "${SSH_PUBLIC_KEY:-}" ]; then
    [ -f "$SSH_PUBLIC_KEY" ] || die "no such file: $SSH_PUBLIC_KEY"
    key_args=(--ssh-key-values "$SSH_PUBLIC_KEY")
  elif [ -f "$HOME/.ssh/id_ed25519.pub" ]; then
    key_args=(--ssh-key-values "$HOME/.ssh/id_ed25519.pub")
  else
    key_args=(--generate-ssh-keys)
  fi
  echo "Creating VM $VM_NAME ($SIZE, Ubuntu 24.04) in $LOCATION; this takes a few minutes"
  az vm create \
    --resource-group "$RESOURCE_GROUP" \
    --name "$VM_NAME" \
    --location "$LOCATION" \
    --image "$IMAGE" \
    --size "$SIZE" \
    --admin-username "$ADMIN_USER" \
    --authentication-type ssh \
    "${key_args[@]}" \
    --public-ip-sku Standard \
    --public-ip-address-dns-name "$DNS_LABEL" \
    --nsg-rule SSH \
    --output none
fi

echo "Opening ports 80 and 443"
az vm open-port --resource-group "$RESOURCE_GROUP" --name "$VM_NAME" --port 80,443 --priority 1010 --output none

FQDN="$(az vm show --show-details --resource-group "$RESOURCE_GROUP" --name "$VM_NAME" --query fqdns --output tsv)"
[ -n "$FQDN" ] || die "the VM has no DNS name; set the DNS label on its public IP in the Azure portal"

cat <<EOF

VM ready: $FQDN
  ssh $ADMIN_USER@$FQDN

Next steps (from the repo root):

1. First deploy from this machine:
     erp-web/deploy/deploy.sh $ADMIN_USER@$FQDN $FQDN
   Admin password (user: admin):
     ssh $ADMIN_USER@$FQDN sudo cat /etc/erp-web/initial-admin-password

2. A dedicated SSH key for GitHub Actions, allowed on the VM:
     ssh-keygen -t ed25519 -N '' -C erp-web-deploy -f ~/.ssh/erp_web_deploy
     ssh $ADMIN_USER@$FQDN 'cat >> ~/.ssh/authorized_keys' < ~/.ssh/erp_web_deploy.pub

3. GitHub settings (gh auth login first). AZURE_VM_KNOWN_HOSTS comes from ssh-keyscan:
     gh api --method PUT 'repos/{owner}/{repo}/environments/production' --silent
     gh variable set AZURE_VM_HOST --body $FQDN
     gh variable set AZURE_VM_USER --body $ADMIN_USER
     gh variable set DEPLOY_DOMAIN --body $FQDN
     gh secret set AZURE_VM_SSH_KEY --env production < ~/.ssh/erp_web_deploy
     ssh-keyscan -t ed25519 $FQDN | gh secret set AZURE_VM_KNOWN_HOSTS --env production

Then every push to main that touches erp-web/ deploys after the tests pass.
EOF
