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

The app runs on one small Ubuntu 24.04 VM behind Caddy with automatic HTTPS. The default size is
`Standard_B2ats_v2` (2 vCPU, 1 GB RAM, with a swap file); `Standard_B2als_v2` (4 GB) gives more room. The older
B1s/B1ms sizes cannot be created on new Azure subscriptions. GitHub Actions tests every push and deploys `main`.

```
GitHub Actions (.github/workflows/erp-web.yml)
  test:    composer install, pint --test, php artisan test
  build:   deploy/build-release.sh   git archive of the commit + composer install --no-dev   (no secrets)
  deploy:  deploy/deploy.sh ──ssh──> VM: setup-vm.sh (idempotent), activate-release.sh <tarball>
           (runs no Composer or artisan code; the VM scripts come from git, not from the build)

VM
  browser ──https──> Caddy ──fastcgi──> PHP-FPM pool "erp-web" ──> /opt/erp-web/current -> releases/<time>-<sha>
                                                                    /var/lib/erp-web/storage, database/database.sqlite
  systemd: erp-web-queue (queue:work), erp-web-scheduler.timer (every minute), erp-web-backup.timer (nightly)
```

A deploy extracts the release next to the old ones, snapshots the database, migrates it (if that fails, the old
release keeps serving), caches config/events/routes/views, switches the `current` symlink, reloads PHP-FPM (running
requests may finish) and the queue worker, and checks `https://<domain>/up` and `/admin/login`. If the check fails
it switches back. The last 5 releases are kept. Switching back never undoes migrations; the snapshot does (see
Backups).

### 1. Create the VM

With the Azure CLI (`az login` first):

```bash
erp-web/deploy/azure-create-vm.sh erp-rg erp-vm my-pharmacy-erp            # [location] [size]
```

This checks that the size is available to your subscription, then creates an Ubuntu 24.04 VM in `uaenorth` with
SSH key login only, a public IP with the DNS name `my-pharmacy-erp.uaenorth.cloudapp.azure.com`, and ports 80/443
open. It prints the exact commands for the next steps. In the portal instead: Ubuntu Server 24.04 LTS, an x64 size,
SSH public key, inbound ports 22/80/443, then set a DNS name label on the VM's public IP. A custom domain also
works: point its DNS record at the VM's IP first.

### 2. First deploy from your laptop

From a clone with the deploy kit committed (only committed files are shipped):

```bash
erp-web/deploy/deploy.sh azureuser@<fqdn> <fqdn>
SSH_OPTS="-i ~/.ssh/my_key" erp-web/deploy/deploy.sh azureuser@<fqdn> <fqdn>   # another key
```

The first run installs PHP 8.3, Caddy and SQLite, creates the `erpweb` user and `/etc/erp-web/erp-web.env`,
the database and the system data. Then read the admin password (user `admin`), log in at
`https://<fqdn>/admin` and change it. You can then delete the file; later deploys do not create it again.

```bash
ssh azureuser@<fqdn> sudo cat /etc/erp-web/initial-admin-password
ssh azureuser@<fqdn> sudo rm /etc/erp-web/initial-admin-password     # after changing the password
```

### 3. Let GitHub Actions deploy

Create a dedicated deploy key, allow it on the VM, and add these settings (repo Settings > Secrets and
variables > Actions and Settings > Environments, or the `gh` commands that `azure-create-vm.sh` prints):

| Name | Kind | Value |
|---|---|---|
| `AZURE_VM_USER` | variable | `azureuser` |
| `DEPLOY_DOMAIN` | variable | the public domain, e.g. `my-pharmacy-erp.uaenorth.cloudapp.azure.com`; SSH goes there too |
| `AZURE_VM_SSH_KEY` | secret (environment `production`) | the deploy private key |
| `AZURE_VM_KNOWN_HOSTS` | secret (environment `production`) | `ssh-keyscan -t ed25519 <fqdn>` output |
| `AZURE_VM_HOST` | secret (environment `production`), optional | only when SSH must go to another name or an IP than `DEPLOY_DOMAIN`. Never make it a variable: variables appear in the public logs. |

```bash
ssh-keygen -t ed25519 -N '' -C erp-web-deploy -f ~/.ssh/erp_web_deploy
ssh azureuser@<fqdn> 'cat >> ~/.ssh/authorized_keys' < ~/.ssh/erp_web_deploy.pub
```

The deploy job uses the `production` environment. Limit its deployment branches to `main` (the printed `gh api`
commands do that), and add required reviewers there if every deploy should wait for an approval.

From then on every push to `main` that touches `erp-web/` deploys after the tests pass. Actions > ERP Web >
Run workflow redeploys by hand, from `main` only. Runs on `main` queue behind each other, so an older commit never
deploys after a newer one. Re-running `deploy.sh` from a laptop works too; on the VM one lock covers setup and
activation, so deploys never overlap.

**This repo is public, so its Actions logs are public.** The workflow never prints secrets, and the build job,
which runs Composer and artisan, has none. Command output on the VM (apt, artisan, migrations: an SQL error can
quote customer data) goes only to the root-only `/var/log/erp-web-deploy.log`; the log shows short messages that
point there. `AZURE_VM_HOST` is a secret, so it is masked (if it equals the domain, the domain shows as `***`).
The admin password is never printed (only root can read it on the VM). Keep it that way when you change the
scripts.

### Backups

- **Back up `/etc/erp-web/erp-web.env` now.** Its `APP_KEY` decrypts the ZATCA EGS secrets stored in the
  database; it must never change, and without it every EGS unit has to be onboarded again. If the file goes
  missing while the VM has data, `setup-vm.sh` stops rather than generating a new key.
- Every night `erp-web-backup.timer` writes `database-<time>.sqlite3.gz` (SQLite online backup, integrity
  checked), `storage-app-<time>.tar.gz` (uploads) and `erp-web-env-<time>` (the env file) to `/var/backups/erp-web`,
  kept 14 days. Each deploy also keeps a `pre-deploy-<release>.sqlite3.gz` snapshot (last 5). These sit on the
  same disk, so copy them off the VM (`ssh azureuser@<fqdn> sudo ls /var/backups/erp-web`, then
  `ssh azureuser@<fqdn> sudo cat /var/backups/erp-web/<file> > <file>`) or enable Azure Backup for the VM.

**Restore** (on the VM; `<db>` is a `database-*.sqlite3.gz` or `pre-deploy-*.sqlite3.gz` file, `<uploads>` the
`storage-app-*.tar.gz` of the same night). Do not deploy while restoring. The file operations run as `erpweb`, so
ownership stays right:

```bash
sudo systemctl stop erp-web-scheduler.timer erp-web-backup.timer erp-web-queue php8.3-fpm
sudo cat <db> | sudo -u erpweb sh -c 'gunzip -c > /var/lib/erp-web/database/database.sqlite.new'
sudo -u erpweb sqlite3 /var/lib/erp-web/database/database.sqlite.new 'PRAGMA integrity_check'   # must print ok
sudo -u erpweb rm -f /var/lib/erp-web/database/database.sqlite-journal \
  /var/lib/erp-web/database/database.sqlite-wal /var/lib/erp-web/database/database.sqlite-shm
sudo -u erpweb mv /var/lib/erp-web/database/database.sqlite.new /var/lib/erp-web/database/database.sqlite
sudo -u erpweb chmod 660 /var/lib/erp-web/database/database.sqlite
# Uploads: keep the current files aside (delete app.before-restore once all is well).
sudo -u erpweb mv /var/lib/erp-web/storage/app /var/lib/erp-web/storage/app.before-restore
sudo cat <uploads> | sudo -u erpweb tar -xzf - -C /var/lib/erp-web/storage
sudo systemctl start php8.3-fpm erp-web-queue erp-web-scheduler.timer erp-web-backup.timer
```

Then deploy again (Actions > Run workflow, or `deploy.sh`): if the backup is older than the release, that runs the
newer migrations on it.

**Restore onto a new VM** (the old one is gone): create the VM (step 1), and **before the first deploy** put the
old env file in place, so the new VM keeps the `APP_KEY` (`setup-vm.sh` only changes `APP_URL` to the new domain):

```bash
scp erp-web.env azureuser@<new-fqdn>:
ssh azureuser@<new-fqdn> 'sudo install -d -m 0750 /etc/erp-web && sudo install -m 0640 erp-web.env /etc/erp-web/erp-web.env && rm erp-web.env'
```

Then deploy (step 2), which creates an empty database, copy the backups to the VM (`scp`) and restore them as
above with those paths. The system data is never created again: the first deploy marks it as created, and a deploy
never seeds a database that already has users. Delete `/etc/erp-web/initial-admin-password` (it belonged to the
empty database) and log in with your old accounts. If you deployed before copying the env file, put it back, run
`sudo bash /opt/erp-web/current/deploy/activate-release.sh --refresh`, and only then restore.

### Rollback, settings, logs

```bash
sudo bash /opt/erp-web/current/deploy/activate-release.sh --rollback   # previous release; migrations are not reverted
sudo bash /opt/erp-web/current/deploy/activate-release.sh --refresh    # after editing /etc/erp-web/erp-web.env
sudo tail -n 80 /var/log/erp-web-deploy.log                             # what the last deploy ran, in full
journalctl -u erp-web-queue -u erp-web-scheduler -u php8.3-fpm -u caddy --since today
sudo tail -f /var/lib/erp-web/storage/logs/laravel-$(date +%F).log
```

A rollback (or the automatic switch back) leaves the database as the newer release migrated it. If the older code
cannot work with that schema, restore the `pre-deploy-<release>.sqlite3.gz` snapshot taken just before that deploy
(see Restore). The same applies when a migration fails: Laravel runs SQLite migrations outside a transaction, so the
part of the failed migration that already ran stays in the database. If it changed the schema, restore the snapshot
that the error message names before deploying the fixed code.

Run artisan as the app user so file ownership stays right:
`cd /opt/erp-web/current && sudo -u erpweb php artisan <command>`.

`setup-vm.sh` re-runs on every deploy and only changes `APP_URL` in the env file. Settings for ZATCA
(environment, submission mode) live in General > System Settings; the env values are only the defaults. Caddy's
admin API is off, so after editing `/etc/caddy` use `sudo systemctl restart caddy` (not reload).

### Moving to PostgreSQL or MySQL later

SQLite suits one VM and a handful of concurrent users. To move: install `php8.3-pgsql` (or `php8.3-mysql`) on the
VM, create the database (Azure Database for PostgreSQL works), set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `/etc/erp-web/erp-web.env`, copy the data across (for example
with `pgloader` from the SQLite file), and deploy. Keep `APP_KEY`. The deploy then skips the SQLite steps and
turns off the nightly SQLite backup, so use `pg_dump` or the managed database's backups instead.
