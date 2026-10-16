# PHASE 7 — VERIFIED ENVIRONMENT PREREQUISITES

**Preparation. Nothing here has been executed against a staging system, because no staging
system exists yet.** Every fact below was measured in the development container against the
branch, or read out of the code with a file:line. Where something could not be measured, it
says so.

| | |
|---|---|
| Authority for | what must exist, be configured and be running before Phase 7 can start |
| Supersedes | `PHASE-7-READINESS-PLAN.md` §2, which this corrects in the places noted |
| Executed by | `PHASE-7-RUNBOOK-DEPLOY.md`, then `PHASE-7-RUNBOOK-TEST.md` |
| Owner actions | `PHASE-7-OWNER-CHECKLIST.md` |

---

## 1. Store Syria — the module's environment surface

**Thirty-one variables, not the nineteen the plan's first table listed.** The full annotated
set now lives in `.env.example`, which carried **none** of them before this phase: an operator
standing the integration up from the template had no record that any of it existed.

### 1.1 Required — no usable default exists

| Variable | Missing / wrong ⇒ | Where that is decided |
|---|---|---|
| `LYNOMIA_BASE_URL` | `LynomiaApiException::notConfigured`, every remote call refused | `PlatformApiClient::assertUsable()` |
| `LYNOMIA_PLATFORM_TOKEN` | same | `PlatformApiClient::assertUsable()` |
| `LYNOMIA_WEBHOOK_MASTER_SECRET` | `RuntimeException` on **any** signature derivation, inbound or outbound. Deliberate: an unconfigured master key must not quietly yield a predictable secret that anybody could compute, because the signature check would then still appear to pass | `EventSignature::deriveFor()` |
| `APP_URL` | **the dangerous one — it fails silently.** The webhook URL registered with Lynomia is built from a named route, which is built from this. A wrong value registers a subscription pointing somewhere else: Lynomia accepts it, posts into the void, and this side simply never hears anything. Nothing errors | `EventSubscriptionManager::ingressUrl()`, `route('lynomia.platform.events.ingress')` |

### 1.2 Required for platform operational notices — null until provisioned

`LYNOMIA_PLATFORM_WORKSPACE_ACCOUNT_ID`, `_SENDER_USER_ID`, `_INBOX_ID`.

Null is the **correct** default and the correct state for an installation that has not
provisioned a workspace: `PlatformWorkspaceGateway::configured()` answers false and platform
communications are simply unavailable, which is better than a misconfigured id silently
addressing a merchant's workspace.

**Since B5 closed, a wrong id is caught rather than obeyed.** `assertPlatformOwned()` reads the
account back through `GET /platform/api/v1/accounts/{id}` before any client is built:

| What the operator typed | What happens |
|---|---|
| the platform workspace | `platform_owned: true` → proceeds |
| a merchant's account of ours | `platform_owned: false` → refused, `kind_not_configured`, nothing sent |
| any other account | upstream answers 401 (permissible check) → refused |
| correct id, but Lynomia predates the field | no field → refused as *unconfirmed*, with a different message. Deploy Lynomia first |

This is the programmatic half of B5. **It does not close B5's remaining half**: the operator
still chooses which account to provision as the platform workspace in the first place, and
`PHASE-7-RUNBOOK-TEST.md` §10.1.1 keeps the human confirmation.

### 1.3 Identity — changing it after any store is linked is a breaking change

`LYNOMIA_INSTALLATION_REF` — **absent from the plan's original table and load-bearing.**

It forms the qualified store identifier `{installation_ref}:{store_id}` that Lynomia persists.
It **defaults to the host of `APP_URL`**, so moving `APP_URL` silently changes it — and because
the qualified id is written to `lynomia_store_links.commerce_external_store_id` on first use
and kept (closed blocker C7), the two then disagree. Misreading this identifier's shape is the
Phase 5 defect that broke four features at once.

**Set it explicitly on any environment that will outlive one hostname.** Staging will.

### 1.4 Feature flags that are off by default

| Variable | Default | Consequence of leaving it |
|---|---|---|
| `LYNOMIA_INTEGRATION_ENABLED` | `false` | nothing calls Lynomia at all. Also the rollback lever |
| `LYNOMIA_COMMERCE_EVENTS_ENABLED` | `false` | **the order and cart feed is inert.** `PHASE-7-RUNBOOK-TEST.md` §6 cannot be judged without it |

### 1.5 The one that must not be changed

`LYNOMIA_CREDENTIAL_CACHE_STORE` defaults to `array` and must stay there. `file` or `database`
writes a live Lynomia bearer token to disk, or into a database whose `APP_KEY` is published
(blocker A2). Verified at `config/lynomia.php:154`.

### 1.6 The rest, with their real defaults

Rate limits `LYNOMIA_INGRESS_UNVERIFIED_RATE_PER_MINUTE` 300 (per IP, in front of the signature
check), `LYNOMIA_INGRESS_RATE_PER_MINUTE` 600 (per verified store),
`LYNOMIA_PLATFORM_INGRESS_RATE_PER_MINUTE` 600 (one global bucket; only a verified delivery
reaches it), `LYNOMIA_CONTACT_VERIFY_RATE_PER_HOUR` 10 (per person, not per number),
`LYNOMIA_STUDIO_RATE_PER_MINUTE` 240. Timing `LYNOMIA_WEBHOOK_TOLERANCE_SECONDS` 300,
`LYNOMIA_INBOX_POLL_SECONDS` 10, `LYNOMIA_LINK_TTL_MINUTES` 60,
`LYNOMIA_PROVISIONING_LOCK_MINUTES` 5, `LYNOMIA_CREDENTIAL_TTL_SECONDS` 3600. HTTP
`LYNOMIA_HTTP_TIMEOUT` 10, `_CONNECT_TIMEOUT` 5, `_RETRIES` 2, `_RETRY_DELAY_MS` 250.
Retention `LYNOMIA_EVENT_RETENTION_DAYS` 14, `LYNOMIA_COMMERCE_EVENT_RETENTION_DAYS` 14.
Commerce `LYNOMIA_COMMERCE_PHONE_REGION` `SY`. Templates
`LYNOMIA_NOTICE_INTEGRATION_SUSPENDED`, `LYNOMIA_NOTICE_CONTACT_VERIFICATION`,
`LYNOMIA_NOTICE_LANGUAGE` `en_US` — these are **names of templates that must exist and be
approved** on the platform workspace's WhatsApp channel; a name that is not there fails at
send time, not at boot.

---

## 2. Store Syria — processes that must be running

### 2.1 The scheduler is not optional

Five commands, verified present in the **booted** scheduler (`php artisan schedule:list`), not
read off the provider:

| Command | Cadence | What breaks without it |
|---|---|---|
| `lynomia:deliver-commerce-events` | every minute | orders and carts never reach an agent's panel |
| `lynomia:deliver-consent-decisions` | every minute | a shopper's opt-in never reaches Lynomia, so the next campaign may reach somebody who refused |
| `lynomia:reconcile-events` | every 5 min | inbox events lost to an outage stay lost |
| `lynomia:reconcile-deliveries` | every 15 min | **two things**: a notice whose delivery feed was lost sits at `accepted` for ever, and the platform webhook subscription is never re-registered |
| `lynomia:reconcile-consents` | hourly | a withdrawal lost to an outage stays lost — Lynomia does not retry an account webhook |

**A deployment without the scheduler passes most of the test runbook and fails silently in
§12.** This is the single easiest prerequisite to forget.

### 2.2 A queue worker

`QUEUE_CONNECTION` in `.env.example` is `database`. Whatever the staging driver, a worker must
be running or queued work accumulates unprocessed.

### 2.3 Migrations — twenty, all additive on the way up

Measured `git diff --name-only origin/main...HEAD` across both migration paths. Every
`dropColumn` / `dropIfExists` is inside a `down()`; nothing is dropped by `migrate`. One
touches a **core** table: `2026_10_30_000100_add_mobile_verified_at_to_users.php` adds a
nullable timestamp to `users`, deliberately **not** in `User::$fillable`, so only
`MerchantContactVerification::confirm` can write it.

---

## 3. Lynomia — what must exist before Store Syria can talk to it

Read out of `custom/app/services/billing/platform_workspace.rb`, which is the code that
creates it and therefore the authority on what a usable workspace is.

| # | Must exist | Created by | Why the code requires it |
|---|---|---|---|
| 3.1 | A `PlatformApp` for Store Syria, with an access token | Lynomia Super Admin | becomes `LYNOMIA_PLATFORM_TOKEN`; every platform call is scoped to this app's permissibles |
| 3.2 | `installation_configs` populated | Lynomia Super Admin | note the pre-existing upstream fragility below |
| 3.3 | A billing plan that is **not sellable** and **does** set `campaign_sends` | operator | `Billing::PlatformWorkspace` refuses to provision otherwise: a plan that is `active` with a `stripe_price_id` appears in every merchant's upgrade list, and a plan without `campaign_sends` makes platform sending unlimited and unrecorded |
| 3.4 | The platform-owned account | `Billing::PlatformWorkspace.provision!` | `platform_owned: true` must be set **at creation** — it is what stops the merchant trial, the sales figures and the trial backfill from picking the account up |
| 3.5 | A service sender user, `account_user` on that account, role `administrator` | same service, `grant_access` | `Platform::Api::V1::UsersController#authorized_account_for_scope` requires **both** that the app holds a permissible on the account **and** that the sender is an account_user of it. A workspace provisioned without the pair looks fine and cannot send a thing |
| 3.6 | Permissibles on **both** the account and the sender | same service | as above — and the account permissible is also what lets B5's ownership check read the account at all |
| 3.7 | A WhatsApp channel on the **staging** number, templates synced | Lynomia Super Admin | the UTILITY/MARKETING category is read off the provider's synced list, never from anything an operator types |
| 3.8 | Sidekiq running, webhook queue drained | deploy | `WebhookJob` is how every event leaves Lynomia |

**3.9 — `api_and_webhooks_enabled?`.** `Partner::EventFan.publish` returns immediately when an
account does not have it, so a platform workspace on a plan without that capability produces a
feed that silently never fires. It is a plan/feature question on the Lynomia side and must be
confirmed for the platform workspace specifically, not assumed from a merchant account.

**3.10 — thirteen Lynomia migrations.** Against `origin/lynomia-custom` (the default branch; it
is **not** `develop`). Two matter at deploy time: `add_agent_bot_created_at_index_to_flow_sessions`
is `CREATE INDEX CONCURRENTLY` and is blocker B1 — which is why `deployment/deploy.sh` sets
`POSTGRES_STATEMENT_TIMEOUT=0`; and `add_platform_owned_to_accounts` is the column B5 is about.

---

## 4. Network, DNS, certificates and clocks

### 4.1 Two directions, both needed

| Direction | Paths | Carries |
|---|---|---|
| Store Syria → Lynomia | `/platform/api/v1/**` | provisioning, users, SSO, scoped tokens, grants, consents, installation lifecycle, health, **and the B5 account read** |
| Store Syria → Lynomia | `/api/v1/accounts/{id}/**` | the merchant inbox, conversations, messages, webhooks, templates, campaigns — everything the platform workspace does, because there is no platform messaging endpoint |
| Lynomia → Store Syria | `POST /lynomia/events/{store}` | the per-store feed (conversations, messages, `consent_changed`) |
| Lynomia → Store Syria | `POST /lynomia/platform-events` | the delivery-outcome feed |
| Lynomia → Store Syria | `GET /lynomia/commerce/**` | the commerce API, with the store-bound credential |

**The Lynomia → Store Syria direction has never been exercised by any test on either side.**
Both ingress routes are unauthenticated by design and refuse everything unsigned, so the
network path must genuinely work: a firewall that drops it produces exactly the symptom a
working-but-quiet feed produces.

### 4.2 Real certificates, not a tunnel

A tunnel or a self-signed certificate changes the TLS path being tested, and TLS is one of the
unknowns blocker A1 names explicitly. A staging run over a tunnel does not close that part of
A1.

### 4.3 Clocks

`EventSignature::fresh()` allows `max(60, LYNOMIA_WEBHOOK_TOLERANCE_SECONDS)` seconds of skew,
default 300, measured against the signed `X-Chatwoot-Timestamp`. Both hosts must be
NTP-synchronised. **Lynomia does not retry an account webhook**, so a delivery rejected for
skew is destroyed, not delayed — only the reconciliation sweeps recover from it, and only for
what they cover.

---

## 5. The CI baseline, measured

Ran every gate in `.github/workflows/ci.yml` locally against the branch, base `origin/main`
(merge-base `0d2ca628`).

**Measured at commit `e34c442a`.** Two commits landed after that measurement: `303fc54d`
(documentation and `.env.example` only) and `250e7864` (the B5 application change). The
known-outcome gate is being re-run against the current head; §5.1's figures move by the five
Feature tests B5 added and by nothing else, and the figure to trust is the one in the re-run,
recorded in the commit that follows this document.

### 5.1 The question the owner asked: no undeclared test failure

```
KNOWN-OUTCOME GATE
testcases observed   : 2020  (manifest floor 1000)
failures observed    : 8     (manifest declares 8)
errors observed      : 0     (always expected: 0)
skips observed       : 17    (manifest declares 17)
OK: the observed outcome set matches the manifest exactly
```

Verified independently by set-differencing the JUnit names against
`.github/known-test-outcomes.json` rather than trusting the gate's own verdict: **no undeclared
failure, no undeclared skip, no manifest entry that has started passing**, and all 17 skip
reasons still name their recorded `blocked_on` token.

**All 8 failures are pre-existing** — each reproduces identically on the merge-base in a clean
worktree. Six are the `packages/workdo/LandingPage` path-casing bug; two are app defects at
`RegisteredUserController` and `HomeController.php:367`. The branch added 622 Feature tests,
all passing.

### 5.2 Two CI gates DO fail today, and neither is a test regression

**(a) `composer audit must not grow` — BLOCKING.** `ci.yml:326` sets `AUDIT_CEILING: '70'` and
the lock now audits at **72 advisories across 22 packages**. The two newest are both
`league/commonmark`, reported 2026-09-30.

This is **not** this branch: `composer.json` and `composer.lock` are byte-identical to
`origin/main` and to the merge-base, and the merge-base audits at 72 too. No new vulnerable
dependency entered the lock; the advisory *database* grew for packages already present — which
the ratchet cannot distinguish, and whose error text ("A new vulnerable dependency entered the
lock") therefore misattributes the cause.

**This is an owner decision and is deliberately not applied here.** Silently raising the
ceiling is the drift the ratchet exists to catch. The two honest options are in
`PHASE-7-OWNER-CHECKLIST.md`.

**(b) `flag-sensitive-diff.sh` — BLOCKING until a human acts.** The branch's own
security-diff step exits 1 until `SECURITY-DIFF-REVIEW: completed` appears in the PR body. This
is a required human step, not a CI bug, and it belongs on the owner checklist. The item most
worth the human read is the abandoned-cart route retirement (`routes/web.php -59`).

### 5.3 Two record-keeping corrections

- The manifest's `_README` still quotes `107` Unit and `1126` Feature tests; the real figures
  are `181` and `2020`. Stale prose, not a gate defect — `minimum_testcases` is a floor of 1000
  and is satisfied. It also still says the required job "cannot pass until the dead
  lahirulhr/laravel-payhere package is resolved", which is no longer true.
- The PayHere apparatus that two entire CI jobs (`tests-degraded`, `lockfile-reproducibility`)
  exist to work around is dead weight: the strict install from the lock now succeeds and
  PayHere is absent from it. Pre-production housekeeping, recorded not done.

### 5.4 A developer trap worth one line

`guard-no-env-files.sh` fails in a working copy where `composer install` has already run,
because composer's `post-root-package-install` copies `.env.example` to `.env` and the guard
treats an untracked `.env` as one the build wrote. **It passes in CI**, which runs the guard
*before* `composer install`. Deleting the local file is the fix — and it must never be done to
a production `.env`.

---

## 6. What could not be verified here, and will not be before staging

| | Why |
|---|---|
| The Lynomia → Store Syria network path | there is no second host |
| Real TLS, real DNS, real certificates | same |
| Clock skew between two hosts | same |
| Sidekiq actually delivering a webhook | same |
| That a registered subscription is reachable | the defect this phase already produced once |
| B1 at production-sized `flow_sessions` | staging will not have production-sized data |
| B4's merchant population | only a merchant can verify their own number |
| `gitleaks` full-history scan | a GitHub Action, not a script; `continue-on-error` in CI |
| The two `Refuse to run as root` assertions | this container runs as uid 0, so they fail here by construction |

**None of these is a reason to delay. They are the reason Phase 7 exists**, and blocker A1 is
precisely the statement that they are unmeasured.
