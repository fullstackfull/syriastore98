# Store Syria — rollback and recovery

Written in Phase 7, because this repository had no rollback procedure at all. It is the counterpart
to `deployment/deploy.sh`, which records everything this document needs.

**Read this before you need it.** The worst time to work out which migrations are reversible is
while the site is down.

---

## What `deploy.sh` leaves you for a rollback

| Artefact | Where |
|---|---|
| The outgoing revision | `$BACKUP_DIR/last-deployed-revision` (default `/var/backups/storesyria/`) |
| A verified database dump | `$BACKUP_DIR/storesyria-<stamp>-<revision>.sql` |
| The deploy's own log | wherever you redirected it — redirect it |

If any of those is missing, the deploy did not get far enough to have changed anything that needs
rolling back, with one exception: a deploy that failed **at or after step 6 (migrate)** has changed
the schema. Start at §2.

---

## 1. Code-only rollback — the common case

Use this when the new revision is bad but the migrations it ran are additive (which is the normal
case — see §3).

```bash
cd /path/to/app
git merge --ff-only "$(cat /var/backups/storesyria/last-deployed-revision)" 2>/dev/null \
  || git checkout "$(cat /var/backups/storesyria/last-deployed-revision)"
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl restart "$FPM_SERVICE"
php artisan queue:restart
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/login     # expect 200
```

**Leave the schema alone.** Rolling back the code while leaving additive columns and tables in
place is safe and is the *preferred* rollback: the old code ignores a nullable column it does not
know about, and dropping it is strictly riskier than keeping it.

---

## 2. Rollback with a schema restore — only when you must

Only if a migration was **not** additive, or the database is actually corrupt.

```bash
# MySQL / MariaDB
MYSQL_PWD=... mysql -h HOST -u USER DBNAME < /var/backups/storesyria/<dump>.sql

# PostgreSQL (custom format)
PGPASSWORD=... pg_restore --clean --if-exists -h HOST -U USER -d DBNAME /var/backups/storesyria/<dump>.sql
```

Then do §1.

> **This discards every write made since the dump.** On a pre-launch installation with no real
> users that is usually acceptable; after launch it is not, and the correct move is a forward fix
> rather than a restore. Decide which situation you are in *before* running it.

---

## 3. Migration reversibility — the Phase 7 set

| Migration | What it does | Reversible? |
|---|---|---|
| The six `LandingPage` migrations (`landing_page_settings`, `join_us`, `marketplace_page_settings`, `owner_menus`, `owner_menu_items`, `owner_menu_settings`) | `Schema::create` only; two additionally `hasTable`-guarded. **No data writes.** | **Yes, and you should not.** They are additive, and `/login` and `/register` return HTTP 500 without `owner_menu_settings` — that was blocker L2. Dropping them re-creates the outage |
| `add_recovery_window_to_lynomia_store_links` | Adds **four nullable columns** to `lynomia_store_links`. No backfill, no default rewrite | **Yes**, `down()` drops the four columns. But leave them: without them a suspended installation cannot be resumed through the supported workflow (blocker L1) |

**Both Phase 7 migrations are additive, so §1 is sufficient for a Phase 7 rollback.** Neither needs
§2.

### Before running any other migration set

`php artisan migrate --pretend` and read it. Store Syria's migration set changed in Phase 7: addon
migrations are now registered by the host loader (`app/Providers/PackageServiceProvider.php`), so a
module whose own service provider declared a path that does not exist now contributes its
migrations. Measured, that newly affects exactly one module — `LandingPage` — and the other fifty
paths the loader registers were already registered by their own providers. But "migrate was clean
last time" is not evidence about a module activated since.

---

## 4. Recovery procedures

### The site is up but queued work is not moving

```bash
php artisan queue:restart          # graceful: workers finish the current job and exit
sudo systemctl status "$QUEUE_SERVICE"
php artisan queue:failed           # inspect, then queue:retry
```

### Scheduled integration work has silently stopped

The failure mode to suspect first, because it is silent. Five commands are scheduled by
`LynomiaChatServiceProvider`; **all five depend on one cron entry**:

```bash
crontab -l | grep schedule:run
# * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
php artisan schedule:list
```

Without it, commerce events and consent decisions accumulate in their outboxes, nothing reaches
Lynomia, and no error is raised anywhere.

### An installation is stuck suspended

**This is now a supported workflow rather than a hand-edited row** — that was blocker L1. Resume it
through the application's own lifecycle path (Super Admin → the installation → Resume), which opens
a single-use, seconds-long recovery window so Lynomia's health check can complete, and shuts it
again afterwards.

Do **not** set `state = 'active'` directly in the database. The recovery window exists so that the
installation is only ever marked active after Lynomia has actually verified it; writing the state by
hand reintroduces exactly the inconsistency the push-before-write ordering prevents. Check the audit
log for `lynomia_recovery_window_opened` / `_spent` / `_closed` to see what happened.

### `/login` returns HTTP 500 after a deploy

Almost certainly a missing table from a stranded module migration — blocker L2's signature.

```bash
php artisan migrate:status | grep -i pending
php artisan tinker --execute="echo Schema::hasTable('owner_menu_settings') ? 'present' : 'MISSING';"
```

If it is missing, `php artisan migrate --force` and re-check. If migrations report nothing pending
yet the table is absent, the host migration registration in
`app/Providers/PackageServiceProvider.php` has been removed or narrowed —
`tests/Feature/Platform/FreshInstallAddonMigrationsTest.php` is the tripwire for exactly that.

---

## 5. What this document deliberately does not cover

- **Provisioning a new server.** This is for an installation that already exists.
- **`APP_KEY` rotation.** Blocker A2, and a separate operation with its own plan: anything encrypted
  with the old key must be re-encrypted. Never part of a deploy or a rollback.
- **Seeding.** No deployment or rollback step runs a seeder. `Seeders::AccountSeeder` and Lynomia's
  `db/seeds.rb` must not be run against the existing servers (blocker B11).
