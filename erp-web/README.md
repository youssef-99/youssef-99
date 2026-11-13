# ERP Web

Pharmacy ERP for Saudi Arabia: accounting, sales and POS, purchases, inventory, cash transactions and reports,
with ZATCA e-invoicing (phase 2) and Rasd. Arabic RTL by default, with an English switch.

Stack: **Laravel 13**, **Filament 5** (Livewire 4), PHP 8.3, SQLite (MySQL or PostgreSQL also work).

```
app/Services/            all business logic: posting, stock ledger, numbering, ZATCA
app/Filament/Resources/  the panel at /admin, grouped by module
app/Reports/             the report catalog (/admin/reports)
database/seeders/        SystemSeeder (chart of accounts, defaults, admin user), DemoSeeder
deploy/                  build, VM setup and release scripts (see "Deploy" below)
docs/                    CONVENTIONS.md (read first), ROADMAP.md (status)
```

Modules: General (companies, branches, users and permission groups, ZATCA EGS units, system settings),
Accounting, Sales (including POS), Purchases, Cash Transactions, Inventory, Reports and a Dashboard.

## Run locally

Requirements: PHP 8.3 with `intl`, `pdo_sqlite`, `mbstring`, `zip`, `bcmath`, `gd`, and Composer.

```bash
cd erp-web
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # creates database/database.sqlite; system data plus demo data
php artisan serve               # http://localhost:8000/admin
```

Log in as `admin` / `password` (local installs only; production gets a generated password).
The scheduler (`zatca:submit-pending` every 10 minutes) runs with `php artisan schedule:work`.

```bash
php artisan test --compact      # the whole suite
vendor/bin/pint --dirty         # code style
```

## Deploy: Azure VM + GitHub Actions

The app runs on one small Ubuntu 24.04 VM (Azure B1s with 1 GB RAM works thanks to a swap file;
B1ms with 2 GB is the default) behind Caddy with automatic HTTPS. GitHub Actions tests every push and
deploys `main`.

```
GitHub Actions (.github/workflows/erp-web.yml)
  test:   composer install, pint --test, php artisan test
  deploy: deploy/build-release.sh   git archive of the commit + composer install --no-dev
          deploy/deploy.sh ──ssh──> VM: setup-vm.sh (idempotent), activate-release.sh <tarball>

VM
  browser ──https──> Caddy ──fastcgi──> PHP-FPM pool "erp-web" ──> /opt/erp-web/current -> releases/<time>-<sha>
                                                                    /var/lib/erp-web/storage, database/database.sqlite
  systemd: erp-web-queue (queue:work), erp-web-scheduler.timer (every minute), erp-web-backup.timer (nightly)
```

A deploy extracts the release next to the old ones, migrates the database (if that fails, the old release keeps
serving), caches config/routes/views, switches the `current` symlink, reloads PHP-FPM and the queue worker, and
checks `https://<domain>/up`. If the check fails it switches back. The last 5 releases are kept.

### 1. Create the VM

With the Azure CLI (`az login` first):

```bash
erp-web/deploy/azure-create-vm.sh erp-rg erp-vm my-pharmacy-erp            # [location] [size]
```

This creates an Ubuntu 24.04 VM in `uaenorth` with SSH key login only, a public IP with the DNS name
`my-pharmacy-erp.uaenorth.cloudapp.azure.com`, and ports 80/443 open. It prints the exact commands for the
next steps. In the portal instead: Ubuntu Server 24.04 LTS, SSH public key, inbound ports 22/80/443, then set a
DNS name label on the VM's public IP. A custom domain also works: point its DNS record at the VM's IP first.

### 2. First deploy from your laptop

From a clone with the deploy kit committed (only committed files are shipped):

```bash
erp-web/deploy/deploy.sh azureuser@<fqdn> <fqdn>
SSH_OPTS="-i ~/.ssh/my_key" erp-web/deploy/deploy.sh azureuser@<fqdn> <fqdn>   # another key
```

The first run installs PHP 8.3, Caddy and SQLite, creates the `erpweb` user and `/etc/erp-web/erp-web.env`,
the database and the system data. Then read the admin password (user `admin`), log in at
`https://<fqdn>/admin`, change it, and delete the file:

```bash
ssh azureuser@<fqdn> sudo cat /etc/erp-web/initial-admin-password
```

### 3. Let GitHub Actions deploy

Create a dedicated deploy key, allow it on the VM, and add these settings (repo Settings > Secrets and
variables > Actions, or the `gh` commands that `azure-create-vm.sh` prints):

| Name | Kind | Value |
|---|---|---|
| `AZURE_VM_HOST` | variable | the VM's DNS name (or IP) |
| `AZURE_VM_USER` | variable | `azureuser` |
| `DEPLOY_DOMAIN` | variable | the public domain, e.g. `my-pharmacy-erp.uaenorth.cloudapp.azure.com` |
| `AZURE_VM_SSH_KEY` | secret (environment `production`) | the deploy private key |
| `AZURE_VM_KNOWN_HOSTS` | secret (environment `production`) | `ssh-keyscan -t ed25519 <host>` output |

```bash
ssh-keygen -t ed25519 -N '' -C erp-web-deploy -f ~/.ssh/erp_web_deploy
ssh azureuser@<fqdn> 'cat >> ~/.ssh/authorized_keys' < ~/.ssh/erp_web_deploy.pub
```

The deploy job uses the `production` environment. In Settings > Environments > production you can add
required reviewers (every deploy then waits for an approval) and limit deployment branches to `main`.

From then on every push to `main` that touches `erp-web/` deploys after the tests pass. Actions > ERP Web >
Run workflow redeploys by hand. Re-running `deploy.sh` from a laptop works too; deploys never overlap.

**This repo is public, so its Actions logs are public.** The workflow never prints secrets, the VM host is
masked when it differs from the public domain, and the admin password is never printed (it is only readable
by root on the VM). Keep it that way when you change the scripts.

### Backups

- **Back up `/etc/erp-web/erp-web.env` now.** Its `APP_KEY` decrypts the ZATCA EGS secrets stored in the
  database; it must never change, and without it every EGS unit has to be onboarded again.
- Every night `erp-web-backup.timer` writes `database-<time>.sqlite3.gz` (SQLite online backup, integrity
  checked) and `storage-app-<time>.tar.gz` (uploads) to `/var/backups/erp-web`, kept 14 days. Each deploy also
  keeps a `pre-deploy-<release>.sqlite3.gz` snapshot (last 5). These sit on the same disk, so copy them off the
  VM (`ssh azureuser@<fqdn> sudo ls /var/backups/erp-web`, then
  `ssh azureuser@<fqdn> sudo cat /var/backups/erp-web/<file> > <file>`) or enable Azure Backup for the VM.
- Restore: `sudo systemctl stop erp-web-queue`, `gunzip` the copy over
  `/var/lib/erp-web/database/database.sqlite` (owner `erpweb`, mode 660), then `sudo systemctl reload php8.3-fpm`
  and `sudo systemctl start erp-web-queue`.

### Rollback, settings, logs

```bash
sudo bash /opt/erp-web/current/deploy/activate-release.sh --rollback   # previous release; migrations are not reverted
sudo bash /opt/erp-web/current/deploy/activate-release.sh --refresh    # after editing /etc/erp-web/erp-web.env
journalctl -u erp-web-queue -u erp-web-scheduler -u php8.3-fpm -u caddy --since today
sudo tail -f /var/lib/erp-web/storage/logs/laravel-$(date +%F).log
```

Run artisan as the app user so file ownership stays right:
`cd /opt/erp-web/current && sudo -u erpweb php artisan <command>`.

`setup-vm.sh` re-runs on every deploy and only changes `APP_URL` in the env file. Settings for ZATCA
(environment, submission mode) live in General > System Settings; the env values are only the defaults.

### Moving to PostgreSQL or MySQL later

SQLite suits one VM and a handful of concurrent users. To move: install `php8.3-pgsql` (or `php8.3-mysql`) on the
VM, create the database (Azure Database for PostgreSQL works), set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `/etc/erp-web/erp-web.env`, copy the data across (for example
with `pgloader` from the SQLite file), and deploy. Keep `APP_KEY`. The deploy then skips the SQLite steps and
turns off the nightly SQLite backup, so use `pg_dump` or the managed database's backups instead.
