# PHASE 7 — STAGING DEPLOYMENT RUNBOOK

**NOT EXECUTED. This document is written to be run by an operator on two staging hosts AFTER
the owner's explicit staging execution authorization. No step in it has been performed.**

Nothing here touches production. No `.env` or `APP_KEY` of any existing system is modified; the
staging hosts get their own. No provider is connected, no message is sent and no charge is
created by any step in this document — those are §9 and §10 of the test runbook and they are
separately gated.

| | |
|---|---|
| Companion | `PHASE-7-RUNBOOK-TEST.md` — what to verify once this has run |
| Prerequisites | `PHASE-7-PREREQUISITES.md` — must all be satisfied before step 0 |
| Plan | `PHASE-7-READINESS-PLAN.md` — the approved scope this executes |
| Branch | `claude/store-syria-lynomia-integration-1oa1tt` in both repositories |

---

## 0. The two deployments are not symmetrical, and that matters

**Lynomia has a real deployment story. Store Syria has none.** This is not a stylistic
observation; it changes what the operator has to do and it is an owner item.

| | Lynomia Chat | Store Syria |
|---|---|---|
| Deploy script | `deployment/deploy.sh`, ten numbered stages | **none in the repository** |
| Service management | systemd: `chatwoot.target`, `chatwoot-web.1.service`, `chatwoot-worker.1.service` | **none** |
| Reverse proxy config | `deployment/nginx_chatwoot.conf` | **none** |
| Host setup | `deployment/setup_20.04.sh` | **none** |
| Rollback procedure | `deployment/ROLLBACK.md`, `deployment/INCIDENT.md` | **none** |
| Pre-deploy backup | taken by the script, path printed | **not automated** |
| Readiness verification | `HEALTH_URL` polled after restart | **none** |

A search of the Store Syria repository finds no deploy script, no Dockerfile, no systemd unit
and no rollback document; `composer.json` carries only Laravel's stock lifecycle scripts. §2
below therefore spells the sequence out by hand. **That is a stopgap for one staging window,
not a deployment story**, and `PHASE-7-OWNER-CHECKLIST.md` carries it as an item that needs an
owner decision before any production conversation.

---

## 1. Lynomia Chat — deploy first

### 1.1 Why Lynomia goes first

Its additions are **additive to the wire**: `message_delivery_status` joins
`Webhook::ALLOWED_WEBHOOK_EVENTS`, `Delivery::StatusFeed` and `Partner::EventFan` are new, and
nothing an older Store Syria already depends on changes shape.

Reverse the order and the window is wasted rather than broken: Store Syria's reconciliation
sweep would try to register a webhook subscribed to `message_delivery_status`, Lynomia's
`validate_webhook_subscriptions` would reject the event as not in its allowlist, and the
`createWebhook` call would be refused. The sweep tolerates that and carries on — so the
operator would see a working deployment with a feed that never fires, which is precisely the
failure mode this release exists to eliminate.

### 1.2 The script does this already — use it, do not improvise

`bash deployment/deploy.sh`, run as root on the staging host. It is parameterised for exactly
this case:

```bash
APP_USER=chatwoot-staging \
APP_DIR=/home/chatwoot-staging/lynomiachat \
BACKUP_DIR=/var/backups/lynomia-staging \
TARGET=chatwoot-staging.target \
HEALTH_URL=http://127.0.0.1:3000/api \
EXPECT_SHA=<the exact commit you intend to deploy> \
bash deployment/deploy.sh
```

**Set `EXPECT_SHA`.** The script compares it against the upstream head and refuses the deploy
on a mismatch, changing nothing. On a staging host that several people can push to, that guard
is the difference between deploying the revision you reviewed and deploying whatever landed
last.

What it does, in its own order, and why each stage is not skippable:

| Stage | What | Why it matters here |
|---|---|---|
| 0 | pre-deploy checks: clean tree, record `PREVIOUS_SHA`, disk on both filesystems | `PREVIOUS_SHA` is what a rollback aims at; without it you are reading `git reflog` under pressure |
| 1 | fetch, compare against `EXPECT_SHA`, return early if already deployed | a re-run costs nothing |
| 2 | `pg_dump` to `BACKUP_DIR`, credentials passed only into `pg_dump`'s own environment | the staging database is cheap to lose and the backup proves the procedure works before production needs it |
| 3 | check out the release | |
| 4 | `bundle install`, `pnpm install --frozen-lockfile` | without the frozen lockfile the Vite build runs against stale `node_modules` |
| 4b | libvips precondition | Active Storage **refuses to load** below libvips 8.13 — it raises on `require`, so every Rails process aborts, web and worker alike. A system package no deploy can install |
| 5 | migrations with `POSTGRES_STATEMENT_TIMEOUT=0` | a `CREATE INDEX CONCURRENTLY` killed by the statement timeout leaves the index INVALID and the table silently unindexed |
| 6 | `pnpm vite build` **and** `pnpm build:sdk` | `public/packs/js/sdk.js` comes from its own pipeline and is gitignored; skipping it leaves every embedding site on the previous widget |
| 6b | SIGTSTP the worker and drain the in-flight set | |
| 7 | `systemctl restart $TARGET` | |
| 8 | poll `HEALTH_URL`, then assert `chatwoot-web.1.service` and `chatwoot-worker.1.service` are active | |
| 9 | done; prints outgoing and incoming revisions |

**Failure before stage 7 changes nothing and the previous version keeps serving.** Failure
after stage 7 is a failure of the new code while the new code is serving; the script says so
in its own message, and the next step is `deployment/ROLLBACK.md`, not more debugging.

### 1.3 Migrations — thirteen, not two

**This branch carries all six phases, not just the post-Phase-6 hardening.** Measured against
`origin/lynomia-custom` (the repository's default branch — it is *not* `develop`, and the
difference matters when you run the command yourself):

```bash
git diff --name-only origin/lynomia-custom...HEAD -- db/migrate custom/db/migrate
```

| Where | Count | Notable |
|---|---|---|
| `db/migrate/` | 6 | `create_commerce_messaging_consents`, `create_partner_consents`, `create_partner_consent_events`, `add_lock_version_to_flow_versions`, **`add_agent_bot_created_at_index_to_flow_sessions`**, **`add_platform_owned_to_accounts`** |
| `custom/db/migrate/` | 7 | access-token scoping and expiry, channel identity claims, entitlement grants, campaign cancellation, billing usage counters, consent revision, partner-consent FK cascade |

Two of these need naming before the window:

**`20261020000002_add_agent_bot_created_at_index_to_flow_sessions.rb` is blocker B1.** It is
`add_index ... algorithm: :concurrently` with `disable_ddl_transaction!`. This is exactly why
the deploy script sets `POSTGRES_STATEMENT_TIMEOUT=0` at stage 5: a `CREATE INDEX CONCURRENTLY`
killed by the server's statement timeout leaves the index **INVALID** and the table silently
unindexed — it does not error in a way the migration notices. On staging the table is small
and this will complete in moments, which is precisely why staging **cannot** close B1: the
blocker is about this statement on a production-sized `flow_sessions`. Record how long it
takes and on how many rows; that is the only honest data staging can produce about it.

**`20261027000100_add_platform_owned_to_accounts.rb` is what blocker B5 is about.** It adds
the column that marks an account as the installation's own, and Lynomia's UTILITY-only gate
keys on it. The B5 gap is that *Store Syria* has no way to check it.

Only one Lynomia migration is destructive, and only on the way down:
`20261024000000_add_revision_to_commerce_messaging_consents.rb` drops the column in `down`.
Nothing drops on the way up.

### 1.4 What must exist in Lynomia before Store Syria can talk to it

Created through Lynomia's own Super Admin, **after** the deploy and **before** §2.6. These are
enumerated with their code requirements in `PHASE-7-PREREQUISITES.md`; the deploy runbook only
records that they are a precondition for the next repository, not for this one.

1. A `PlatformApp` for Store Syria. Its token becomes `LYNOMIA_PLATFORM_TOKEN`.
2. `installation_configs` populated for this installation.
3. A billing plan carrying a `campaign_sends` limit, for the platform workspace.
4. A WhatsApp channel on the **staging** number with its templates synced.
5. The platform-owned account, its sender user and its inbox — the three ids §2.4 needs.

---

## 2. Store Syria — deploy second

### 2.1 Before anything

```bash
cd <store-syria-staging-checkout>
git fetch origin
git rev-parse HEAD            # record this: it is the rollback target
git status --porcelain        # must be empty
```

Record the outgoing revision by hand. Nothing in this repository does it for you, which is
item 1 of what §0 says is missing.

### 2.2 Take a database backup by hand

```bash
mysqldump --single-transaction --quick --routines \
  -h "$DB_HOST" -u "$DB_USERNAME" -p "$DB_DATABASE" \
  | gzip > /var/backups/storesyria-staging/pre-phase7-$(date +%Y%m%d-%H%M%S).sql.gz
```

Adjust for the configured driver. **Do not skip this because it is staging**: §12 of the test
runbook deliberately breaks a running system, and the backup is what makes those tests
repeatable rather than one-shot.

### 2.3 Check out the release with the integration OFF

```bash
git checkout claude/store-syria-lynomia-integration-1oa1tt
git rev-parse HEAD            # confirm it is the revision you intended
```

Confirm `LYNOMIA_INTEGRATION_ENABLED=false` in the staging environment **before** the first
request is served. It is the kill switch, it ships `false`, and no surface or scheduled sweep
performs a remote call while it is off. Everything up to §2.6 therefore runs with the
integration inert, which is deliberate: a misconfiguration found at §2.6 has done nothing yet.

### 2.4 Install dependencies and write the environment

```bash
composer install --prefer-dist --no-interaction --no-progress
```

Then set the module's variables. `PHASE-7-PREREQUISITES.md` is the authority on which are
required, what each default is, and what breaks if one is wrong — read it rather than copying
values from here. Two deserve repeating at the point of use:

- **`APP_URL`** must be the staging host's real public URL. The webhook URL registered with
  Lynomia is built from a named route, which is built from this. A wrong value registers a
  subscription pointing somewhere else and **fails silently** — Lynomia accepts the
  registration, posts to the wrong host, and this side simply never hears anything.
- **`LYNOMIA_CREDENTIAL_CACHE_STORE`** must stay at its default `array`. Setting it to `file`
  or `database` writes a live Lynomia bearer token to disk or to a database whose `APP_KEY` is
  published (blocker A2). That is the mistake the retired Chatwoot integration made.

### 2.5 Migrate, then clear and rebuild caches

```bash
php artisan migrate --force
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan config:cache && php artisan route:cache
```

**Nineteen migrations, not two — and ONE IS DESTRUCTIVE ON THE WAY UP.** The branch carries
all six phases. Confirm the set before the window:

```bash
git diff --name-only origin/main...HEAD -- \
  packages/workdo/LynomiaChat/src/database/migrations database/migrations
```

(Twenty paths come back; one is `database/migrations/_gate_f_pending/README.md`.)

**Eighteen are additive on the way up** — new tables (`lynomia_store_links`,
`lynomia_store_identities`, `lynomia_inbox_events`, `lynomia_commerce_events`,
`lynomia_platform_notices`, `lynomia_contact_verifications`, `lynomia_platform_events`) and
nullable columns on existing ones.

**The nineteenth is not.** `2026_10_21_000200_scope_lynomia_consent_states_by_origin.php` calls
`Schema::dropIfExists('lynomia_consent_states')` in `up()` and recreates it with `origin` in
the unique key. It is recoverable by design — the table is a projection, the evidence lives in
the append-only `lynomia_consent_decisions`, and the sweep rebuilds it — but **it is not
additive, and an earlier version of this runbook said it was.**

> **After §2.5, run `php artisan lynomia:reconcile-consents` and confirm
> `lynomia_consent_states` refills.** On a fresh staging database it will be empty either way;
> on one restored from a snapshot it will not be, and an empty table afterwards is expected
> rather than alarming.

One touches a **core** table rather than a module table:
`2026_10_30_000100_add_mobile_verified_at_to_users.php` adds a nullable timestamp to `users`.
It is additive and the column is deliberately **not** in `User::$fillable`, so no mass
assignment can set it — only `MerchantContactVerification::confirm` writes it.

`route:cache` matters more than usual here: the two ingress routes and the module's rate
limiters are registered by the module's provider, and a stale route cache is the one way to
deploy a build whose ingress is not served.

### 2.5a Activate the module, or every merchant route 403s

**A fresh staging install has no `add_on_managers` row and no module permissions**, so
`ActivatedModule()` answers nothing, `PlanModuleCheck` refuses, and every merchant surface
returns 403 — before any of the test runbook can start. Nothing in the deploy does this for
you.

```bash
php artisan db:seed --class="Workdo\LynomiaChat\database\seeders\LynomiaChatDatabaseSeeder" --force
```

That registers the add-on (`MarketPlaceSeederTableSeeder`) and seeds the module's permissions
(`PermissionTableSeeder`). Then confirm a merchant can actually open the module before going
further — a 403 here is a seeding problem, not an integration problem, and the two look
identical from the browser.

**Merchant workspaces also need a Lynomia plan carrying `lynomia_commerce`**, or test runbook
§6 and §7 fail with a 403 raised by `Pundit` inside Lynomia rather than by Store Syria.

### 2.6 Bring up the worker and the scheduler — the scheduler is the one that matters

```bash
php artisan queue:work --tries=3            # under a process supervisor, not a terminal
* * * * * cd <app> && php artisan schedule:run >> /dev/null 2>&1   # crontab
```

**A deployment without the scheduler passes most of the test runbook and fails silently in
§12.** Five module commands depend on it, including both reconciliation sweeps and the
commerce and consent outbox drains.

**The integration dispatches no queued job at all** — everything runs in the request or in one
of those five commands. The worker is wanted for the rest of the application; if something in
the integration is not happening, look at the scheduler. Verify it from the booted scheduler
rather than from a config file:

```bash
php artisan schedule:list | grep lynomia
```

Expect all five: `lynomia:reconcile-events` (5m), `lynomia:deliver-commerce-events` (1m),
`lynomia:deliver-consent-decisions` (1m), `lynomia:reconcile-consents` (hourly),
`lynomia:reconcile-deliveries` (15m). A missing entry means a sweep that will never run, and
a test runbook section that would pass by accident.

### 2.7 Turn the integration on

```bash
# set LYNOMIA_INTEGRATION_ENABLED=true
php artisan config:clear && php artisan config:cache
# restart php-fpm / the web process and the queue worker
```

This is the first moment either system can call the other.

### 2.8 Register the delivery feed by hand, once, and watch it

```bash
php artisan lynomia:reconcile-deliveries
```

Do not wait fifteen minutes for the schedule to do it. Run it in the foreground so its output
is visible, because this is the step whose **absence** every unit test on both sides missed:
the ingress, the projector and the ordering rule were all complete and green while nothing had
ever told Lynomia the endpoint existed.

Expect either `Registered the delivery feed webhook on the platform workspace.` or silence
(already registered). Then **confirm it in Lynomia** rather than trusting the message: the
platform workspace must now carry a webhook pointing at
`https://<store-syria-staging>/lynomia/platform-events` subscribed to
`message_delivery_status`.

If it reports `Could not check the delivery feed subscription: <kind>`, stop and resolve it.
The sweep deliberately continues past that failure — reconciliation is what covers a feed that
is not arriving — but a staging run that begins with no feed is not testing the feed.

---

## 3. Post-deploy smoke, before the test runbook

Five checks. Each one has a cheap failure and an expensive one if deferred.

| # | Check | Expected |
|---|---|---|
| 3.1 | `curl -sS -o /dev/null -w '%{http_code}\n' https://<store-syria-staging>/` | 200 |
| 3.2 | `curl -sS -o /dev/null -w '%{http_code}\n' https://<lynomia-staging>/api` | 200 |
| 3.3 | `curl -sS -o /dev/null -w '%{http_code}\n' -X POST https://<store-syria-staging>/lynomia/platform-events` | **401**, not 404 and not 500. 404 means the route is not served (stale route cache, module not loaded); 500 means the guard is not first in the stack |
| 3.3a | Confirm the Store Syria host resolves to a **public** address, and that the ingress answers in well under five seconds | Lynomia delivers webhooks through `SafeFetch` / `ssrf_filter` with a five-second timeout. **A private or VPC address is refused outright**, and a slow one is dropped — in both cases silently, because Lynomia does not retry an account webhook. The symptom is identical to a subscription that was never registered |
| 3.4 | `php artisan schedule:list \| grep -c lynomia` | 5 |
| 3.5 | Super Admin → Lynomia integration page renders, and the notices tab shows the three configured workspace ids | a blank or 500 page here is a deploy problem, not a feature problem |

**3.3 is the one worth dwelling on.** An unsigned POST to the platform ingress must be refused
with 401 by `GuardEventIngress` before the body is parsed. Any other answer means the stack is
wrong, and every later section would be testing something other than what ships.

---

## 4. Rollback

### 4.1 The lever comes before the rollback

```bash
# set LYNOMIA_INTEGRATION_ENABLED=false
php artisan config:clear && php artisan config:cache   # then restart
```

Instant, and total: no surface and no sweep performs a remote call while it is off. **For any
problem that is integration-shaped, this is the response** — not a redeploy. Lynomia's own
`docs/p-final/09-rollback-and-rollout.md` makes the same distinction for its release: a
feature-shaped problem is a lever, a rollback is for "nothing serves".

### 4.2 Store Syria rollback

```bash
git checkout <the revision recorded at §2.1>
composer install --prefer-dist --no-interaction
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan config:cache && php artisan route:cache
# restart web + worker
```

**Do not roll the migrations back to recover from an application bug.** Both are additive;
their columns and table are inert when nothing reads them, and `lynomia_platform_events` is the
append-only record of what Lynomia reported. Dropping it to fix a rendering bug destroys the
evidence you would need to explain the bug.

### 4.3 Lynomia rollback

`deployment/ROLLBACK.md` is the procedure; it is a deploy of the previous revision by hand,
because this server deploys in place with no releases directory and no `current` symlink
(`cap deploy:rollback` does not exist here despite the vestigial `Capfile`).

One consequence specific to this release: rolling back removes `message_delivery_status` from
`ALLOWED_WEBHOOK_EVENTS` while webhook rows that carry that subscription remain in the
database. Those rows are not re-validated on read, so the feed simply stops — acceptable, and
the subscription is re-registered by Store Syria's sweep on the way back up. But an operator
who then **edits** one of those webhooks in the Lynomia dashboard will hit a validation error
on a field they did not touch. Note it; do not pre-emptively delete the rows.

### 4.4 Decision tree

| Symptom | Action |
|---|---|
| Anything in the test runbook fails in a way that is not understood | §4.1, the lever. Then diagnose with both systems still up |
| A Store Syria page 500s | §4.2 |
| Lynomia does not serve | `deployment/ROLLBACK.md` |
| The delivery feed is not arriving | **not** a rollback. §2.8, then `PHASE-7-RUNBOOK-TEST.md` §10.3 |
| A merchant received something unexpected | §4.1 immediately, then preserve `lynomia_platform_events` and `lynomia_platform_notices` before changing anything |

---

## 5. What this runbook deliberately does not do

| | Why |
|---|---|
| Connect a production WhatsApp number | D2: the staging number is dedicated and is never the production one |
| Send any message | the first real send is test runbook §9/§10 and needs the D3 named recipient, who is **not yet identified** |
| Create any charge | D4: billing stays in test mode |
| Modify any production `.env`, `APP_KEY` or credential | standing restriction |
| Merge or deploy to production | standing restriction; Phase 7 completing closes blocker A1 and authorises nothing else |
| Rotate the published production `APP_KEY` | blocker A2, a separate change under its own window |

---

## 6. Evidence to capture while running this

Phase 7's value is the record, not the green ticks. Capture into the window's log:

1. Both revisions deployed, and the two rollback targets.
2. The Lynomia deploy script's full output, including the backup path it printed.
3. The output of §2.6's `schedule:list | grep lynomia`.
4. The output of §2.8, and a screenshot or API read of the webhook as Lynomia now holds it.
5. The five §3 results verbatim.
6. Anything that differed from this document — each difference is either an error in the
   document or a surprise in the environment, and both are findings.
