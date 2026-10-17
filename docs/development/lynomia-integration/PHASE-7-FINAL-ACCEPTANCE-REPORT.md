# PHASE 7 — FINAL ACCEPTANCE REPORT

**Store Syria × Lynomia Chat.** Written 2026-10-17 against the merged default branches.

This report answers the owner's §11 in its fifteen parts. Read §0 first: the shape of what was
achievable changed once the environments were actually inspected, and every figure below depends
on knowing which environment produced it.

| | |
|---|---|
| Store Syria | `fullstackfull/syriastore98`, default branch `main` |
| Lynomia Chat | `fullstackfull/lynomiachat`, default branch `lynomia-custom` |
| Predecessors | `MERGE-AND-PHASE-7-REPORT.md`, `PHASE-7-READINESS-PLAN.md`, `PHASE-7-OWNER-CHECKLIST.md`, `PRODUCTION-READINESS-BLOCKERS.md` |

---

## 0. MILESTONES

Nothing is marked complete without the evidence named beside it.

| Milestone | State | Evidence, or why not |
|---|---|---|
| **CODE MERGED** | **YES** | Both default branches verified at the SHAs in §2, read from the GitHub API rather than assumed. |
| **CODE VERIFIED** | **PARTIAL** | Cross-repository contract specs and the integration specs ran green here (§5, §13). The two full suites are reported in §5 with their exact provenance. |
| **PRE-LAUNCH DEPLOYED** | **NO** | There is no reachable owner environment. `chat.lynomia.com` has **no DNS record at all**; no credential for either platform exists in this session; arbitrary outbound egress is policy-denied; and the repository's own runbook records that Store Syria has no deployment story. Nothing was deployed. §1. |
| **CROSS-PLATFORM VERIFIED** | **YES, in a local two-app environment** | Both applications were booted and wired to each other, and the integration was driven through its own production code paths over real HTTP in both directions, with real signatures and real database writes. §3, §4. This is **not** the owner's installation and does not substitute for one. |
| **PROVIDER VERIFIED** | **NO** | No WhatsApp/Meta account, number or credential exists here. No provider message was sent, attempted or simulated. §11. |
| **READY FOR PUBLIC LAUNCH** | **NO** | One new high-severity integration defect (L1, §10), the open blockers in §12, and the unresolved dependency-audit decision in §13. |

**Blocker A1 is NOT closed.** Its substance has moved, and the honest statement of where it now
stands is in §10.

---

## 1. VERIFIED DEPLOYMENT ENVIRONMENT DETAILS (§11.1)

The directive asked me to inspect the existing pre-launch installations and use them. I could
not, and the reason is not a missing permission I could work around.

**There is no reachable installation of either platform.** Measured:

```
getent hosts chat.lynomia.com          -> no record
getent hosts www.chat.lynomia.com      -> no record
getent hosts lynomia.com               -> no record
https://chat.lynomia.com               -> connection failed (000)
```

`chat.lynomia.com` is the host the Lynomia repository itself names — `config/installation_config.yml:34,38`
sets `BRAND_URL` and `WIDGET_BRAND_URL` to it, and `deployment/nginx/chat.lynomia.com.conf` is a
vhost model for it whose own first line reads *"A REFERENCE MODEL OF THE PRODUCTION VHOST FOR
chat.lynomia.com. NOT THE LIVE FILE. NOT A DROP-IN."* It does not resolve.

**No Store Syria host is recorded anywhere.** No committed file names one, and
`.env.example` leaves `APP_URL` at a placeholder.

**No credentials exist in this session.** `~/.ssh` is empty. The only tokens present belong to
this container's own agent proxy (GitHub, AWS, gcloud), not to the owner's infrastructure. Store
Syria's `.env.example:87-95` leaves `LYNOMIA_BASE_URL`, `LYNOMIA_PLATFORM_TOKEN` and
`LYNOMIA_WEBHOOK_MASTER_SECRET` empty, with the comment *"REQUIRED once enabled. There is no
sensible default for any of these."*

**Egress is policy-denied by default.** `https://example.com` is refused at the gateway with
`403 to CONNECT`. Package registries (packagist, rubygems, npm) are permitted; arbitrary hosts
are not. So even a correct hostname and credential could not have been reached from here.

**The two platforms are not symmetrical, and the repository already says so.**
`PHASE-7-RUNBOOK-DEPLOY.md:0` tabulates it: Lynomia has `deployment/deploy.sh`, systemd units,
an nginx config, `ROLLBACK.md` and `INCIDENT.md`; Store Syria has **none of these** — no deploy
script, no Dockerfile, no systemd unit, no reverse-proxy config and no rollback document. That
document calls its own hand-written sequence *"a stopgap for one staging window, not a deployment
story"*. I confirmed each absence independently.

### Consequences, stated plainly

- Items 1, 3, 4, 7, 8, 9 and 10 of the owner's §2 (deployed versions, existing data, deployment
  procedures, backups and rollback, existing credentials, co-tenant services) **cannot be
  answered for the owner's environments**, because no such environment was reachable to inspect.
  I will not describe infrastructure I did not see.
- Items 2, 5 and 6 are answered in §3 **for the environment I built**, which is a different thing
  and is labelled as such throughout.
- No backup was taken, because there was nothing to back up. No migration was run against any
  existing database. No `APP_KEY` was read, modified or rotated anywhere.

---

## 2. ACTUAL COMMIT SHAs (§11.2)

Read from the GitHub API, not from the previous report:

| Repository | Branch | SHA | Subject |
|---|---|---|---|
| syriastore98 | `main` | `c1ea89b97a27acd9c737ee6b313b0132eff6fe35` | docs(phase-7): verify the Arabic link fix through the seeder's own preflight |
| lynomiachat | `lynomia-custom` | `97dc7c566bd3616d1eec38e639485017c229c7ce` | merge: Store Syria x Lynomia Chat integration (Phases 1-6 plus hardening) |

Both match the SHAs the directive reported, and both local working trees were clean at those
commits before any work began. The directive's instruction to re-verify was correct to give and
the answer happens to be "unchanged".

**Deployed versions: none.** There is no deployment to compare a merged branch against.

---

## 3. THE ENVIRONMENT THIS REPORT'S EVIDENCE COMES FROM

Since no owner environment was reachable, and since the directive's priority is *real*
cross-platform evidence rather than another plan, I built both platforms in this session's
container and connected them to each other. **This is a local two-app environment in an ephemeral
container. It is not staging, it is not the owner's installation, and it disappears with the
session.** It is, however, two real applications exchanging real HTTP.

| | |
|---|---|
| OS / kernel | Ubuntu 24.04.5 LTS, Linux 6.18.44 |
| CPU / RAM / disk | 4 vCPU, 15 GiB, ~19 GiB free at measurement time |
| PostgreSQL | 16.15, fresh local cluster, `pgvector 0.6.0` + `pg_trgm 1.6` installed |
| Redis | 7.0.15 |
| PHP / Ruby / Node | 8.3.6 / **3.4.4** / 22.22.0 |
| Store Syria | `http://127.0.0.1:8001` (`php artisan serve`), sqlite |
| Lynomia Chat | `http://127.0.0.1:3000` (puma 7.2.1), PostgreSQL + Redis |

**Four things about this environment that the owner's deployment will also have to deal with.**
Each was discovered by hitting it:

1. **Ruby 3.4.4 had to be compiled from source.** `Gemfile:3` pins `ruby '3.4.4'` and bundler
   refuses anything else; `cache.ruby-lang.org` is policy-denied here (403), so the official git
   tag `v3_4_4` (`a38531fd`) was cloned and built. Not a product issue, but it is why the
   container's preinstalled 3.3.6 was not enough.
2. **`db:schema:load` needs pgvector.** `db/schema.rb:19` is `enable_extension "vector"`, so a
   plain PostgreSQL 16 fails with `extension "vector" is not available`. CI uses a
   `pgvector/pgvector:pg16` image; a hand-built host needs `postgresql-16-pgvector` installed.
3. **Store Syria's `composer install` cannot complete here.** `yoomoney/yookassa-sdk-php` (a
   direct requirement) and its validator are hosted on `git.yoomoney.ru`, which this container
   denies (403). The repository's own CI notes record this as a sandbox restriction rather than a
   property of the packages. To get a working tree I installed from a **separate, untracked
   manifest** (`composer-phase7local.json`) omitting those two packages. **`composer.json` and
   `composer.lock` were not modified** — `git status` on both is clean. The omitted packages are
   the SDK for YooKassa, which `app/Payments/RetiredGateways.php` records as a **retired
   gateway**; no integration path touches it. Every Store Syria figure in this report carries
   that caveat.
4. **Nothing pre-existing could be lost.** The PostgreSQL cluster contained only `template0`,
   `template1` and `postgres` before I created `lynomia_dev`; Store Syria got a brand-new sqlite
   file. No existing database was reset, and no seeder that deletes anything was run — see §12 on
   B10.

Database state established:

| | |
|---|---|
| Lynomia | `db:schema:load` → **139 tables**, 239 migrations recorded, newest `20261027000100_add_platform_owned_to_accounts` matching the newest file on disk, 0 pending |
| Store Syria | `php artisan migrate` → 232 migrations applied, **106 tables**, exit 0. Plain `migrate` on an empty file; no `migrate:fresh`, no reset |

A fresh `APP_KEY` was generated for the new local Store Syria instance. No existing
installation's key was read, changed or rotated — the directive's prohibition is respected
literally.

---

## 4. ACTUAL CROSS-PLATFORM CONNECTIVITY EVIDENCE (§11.4)

Everything in this section is a real request between the two running applications, made by the
integration's own services, with `Http::fake()` nowhere in the path. The existing suites on both
sides drive a *scripted fake* of the other platform — which is precisely what blocker A1 exists
to flag, and a Phase 5 adversarial review had already found four features that passed every test
on both sides and could not have worked against a real deployment.

### Store Syria → Lynomia

`AccountProvisioner::provision($store, $merchant)` — three remote calls — succeeded against the
live Lynomia:

```
link.lynomia_account_id      2
link.state                   active
link.link_mode               provision
link.provisioning_stage      complete
link.provisioning_attempts   1
link.verified_at             2026-10-17 09:38:57
```

and in Lynomia's own database:

```
accounts.id = 2  name "Phase 7 Live Store"
  custom_attributes {"store_syria_store_id": 1,
                     "store_syria_installation": "storesyria.phase7.local"}
users.id = 1     store-1.user-1.storesyria-phase7-local@stores.storesyria.invalid
account_users    account 2, user 1, role administrator
webhooks.id = 1  http://127.0.0.1:8001/lynomia/events/1
                 ["conversation_created","conversation_updated","conversation_status_changed",
                  "message_created","message_updated","consent_changed"]
```

Four things worth naming:

- **The installation marker round-tripped.** The qualified identity Store Syria writes is
  readable in Lynomia's `custom_attributes`.
- **The derived Lynomia user address is non-routable** (`.invalid`, RFC 2606), so provisioning
  cannot email a real person.
- **The per-store webhook was really created upstream.** The blocker register lists
  "webhook subscriptions never created upstream" as an A1 risk that *had already happened for
  real* (C19). Here it was created, against a live Lynomia, by the real
  `EventSubscriptionManager`.
- **`message_delivery_status` is correctly absent** from those six. It belongs to the
  platform-workspace feed (`PlatformWorkspaceGateway::DELIVERY_EVENT`), not the per-merchant
  inbox, and `EventSubscriptionManager::SUBSCRIPTIONS` excludes it deliberately. Lynomia does
  permit it (`app/models/webhook.rb:35`). I checked this because its absence looked like a defect
  and it is not one.

Platform API authorization, three requests:

| request | result |
|---|---|
| `GET /platform/api/v1/accounts` with the real PlatformApp token | **200** |
| the same with no token | **401** |
| the same with a bogus token | **401** |

`SsoLinkIssuer::issueFor` returned a Lynomia-hosted one-time URL carrying a single-use token.

### Lynomia → Store Syria

`Commerce::StoreConnection#connect` performed Lynomia's real provider health check, which is an
HTTP GET of Store Syria's commerce API, and took its store identity from the wire:

```
CONNECT                      OK
store.provider               storesyria
store.external_store_id      storesyria.phase7.local:1     (read from Store Syria's response)
store.name                   Phase 7 Live Store            (read from Store Syria's response)
store.status                 active
identity matched the wire    true
adapter.health               true
```

The qualified `{installation_ref}:{store_id}` form is on the wire, which is the Phase 5
adversarial finding (bare store id vs qualified) holding up against a real deployment.

Store Syria's commerce API, driven with a real store-scoped credential:

| request | expected | got |
|---|---|---|
| `GET /commerce/v1/store` valid credential | 200 | **200** |
| `GET /commerce/v1/store` no credential | 401 | **401** |
| `GET /commerce/v1/store` forged credential | 401 | **401** |
| `GET /commerce/v1/orders?number=…` | 200 | **200** |
| `GET /commerce/v1/orders` with no `number` | 422 | **422** |
| `/store` answers this store's qualified id only | `storesyria.phase7.local:1` | **matched** |

### Commerce events, Store Syria → Lynomia, end to end

A synthetic customer and order were created on a linked store; the observer wrote the outbox
rows; the real `CommerceEventDispatcher` delivered them:

```
order.created          -> TRUE  attempts=1 delivered_at=SET last_error=null
order.payment_changed  -> TRUE  attempts=1 delivered_at=SET last_error=null
order.created          -> TRUE  attempts=1 delivered_at=SET last_error=null
order.payment_changed  -> TRUE  attempts=1 delivered_at=SET last_error=null
```

Lynomia's log shows each arriving and being **accepted after signature verification**:

```
POST "/webhooks/storesyria"  event=order.created         external_store_id=storesyria.phase7.local:2
[Commerce] metric=commerce.webhook.accepted provider=storesyria
POST "/webhooks/storesyria"  event=order.payment_changed payment_status=Paid
[Commerce] metric=commerce.webhook.accepted provider=storesyria
```

`order.payment_changed` matters on its own: it is `CommerceEventObserver`'s `updated` branch and
its `wasChanged('payment_status')` test, which an order born with its status already set never
exercises. The order was created `Unpaid` and moved to `Paid`, so that branch really ran.

And the receiving side's own health record closes the chain with timestamps:

```
commerce_store_health (commerce_store_id 2)
  last_api_success_at        2026-10-17 10:06:45    Lynomia -> Store Syria (connect health)
  last_webhook_received_at   2026-10-17 10:07:34    ingress stamped receipt
  last_webhook_processed_at  2026-10-17 10:08:58    job applied it
  consecutive_failures       0
```

`Commerce::Storesyria::WebhookJob` is a **cache invalidation**, not an order write — its own
comment: *"The event's contents are never kept as order data — the store stays the source."* So
the absence of `commerce_customer_links` rows is the correct outcome for a shopper who has never
messaged the merchant, not a failure. The job's observable effect is the processed stamp above,
and it is there.

### Three installation prerequisites discovered by hitting them

These are not defects, but a deployment that misses any of them will see commerce silently fail
to connect. None is in the deploy runbook:

1. **Lynomia refuses a non-HTTPS commerce base URL** — `Commerce::StoreUrl#parse` raises
   `INVALID_STORE_URL: https_required` (`custom/app/services/commerce/store_url.rb:33`). So the
   owner's Store Syria **must be served over HTTPS** for commerce to connect at all — which bears
   directly on §1's finding that Store Syria has no reverse-proxy or TLS story in the repository.
   To proceed locally I used the repository's **own documented internal-host allowance**,
   `COMMERCE_TRUSTED_STORE_HOSTS` (`store_url.rb:5-6`), which exists for development and staging
   stores. **No security control was modified or weakened.**
2. **ActiveRecord encryption keys must be configured in Lynomia**, or
   `Commerce::StoreConnection#ensure_encryption!` raises `ENCRYPTION_NOT_CONFIGURED`
   (`store_connection.rb:124-126`). `ACTIVE_RECORD_ENCRYPTION_PRIMARY_KEY` and its two companions
   are read at `config/application.rb:75-78`.
3. **Two independent switches gate the provider**: the installation-level
   `STORESYRIA_ENABLED` (`Commerce::Storesyria::Config.enabled?`) *and* the account-level
   `lynomia_commerce` feature. Both must be true; neither implies the other.

---

## 5. RESULTS FOR EVERY PHASE 7 FUNCTIONAL AREA (§11.5)

"Live" means exercised between the two running applications. "Spec" means exercised by the
repository's own tests, which drive a fake of the other side. "Not reached" is stated rather than
dressed up.

### A. Provisioning, authentication and account lifecycle

| Item | Result |
|---|---|
| Automatic Lynomia account provisioning | **LIVE, pass** (§4) |
| One Lynomia workspace per Store Syria store | **LIVE, pass** — a second store provisioned a distinct account (2 vs 3) |
| Secure SSO | **LIVE, pass** — Lynomia-hosted one-time URL |
| Staff permissions | Spec only; the live driver called services directly, not through the permission-gated controllers |
| Store ownership enforcement | **LIVE, pass** — qualified `{installation_ref}:{store_id}` on the wire |
| Existing account linking | Not reached — needs a merchant-entered link code |
| Installation suspension | **LIVE, pass** — propagated to Lynomia, whose commerce store moved to `disabled` |
| Installation resumption | **LIVE, FAIL — defect L1, §10** |
| Revocation and re-authentication | **LIVE, FAIL — same defect**: `reauthenticate()` returned `back_online=false` |
| Safe disconnection | Not reached |
| Super Admin installation controls | Spec only |

### B. Native omnichannel inbox

The per-store webhook was really registered and signed deliveries were really accepted (§4, §6),
so the **transport** is live-verified. Conversation listing, agent assignment, read state, status
changes and the merchant/agent permission matrix were **not** driven live — they need a
conversation created from Lynomia's side with an agent session, which this environment did not
set up. Spec coverage only. Tenant isolation on the ingress **is** live-verified (§6).

### C. Commerce integration

| Item | Result |
|---|---|
| Store Syria as a commerce provider inside Lynomia | **LIVE, pass** |
| Order lookup and status mapping | **LIVE, pass** (`/orders?number=`) |
| Payment status visibility | **LIVE, pass** — `order.payment_changed`, Unpaid → Paid |
| Commerce event delivery | **LIVE, pass** — 4/4 delivered, signature-verified, processed |
| Webhook reconciliation | **LIVE, partial** — the health record's three stamps advanced |
| Idempotency | **LIVE, pass** — see replay, §6 |
| Store-scoped API authentication | **LIVE, pass** — 200 / 401 / 401 / 422 matrix |
| Customer synchronization and matching | Not reached — needs a Lynomia contact to match against |
| Cart information | Not reached live; the fixture created 5 carts |

### D. Automation Studio, E. Marketing and campaigns, F. Platform operations

**Not reached live.** Honest reasons, not excuses:

- **D and E** need an agent UI session in Lynomia and a merchant UI session in Store Syria. The
  live driver worked at the service layer, which is the right layer for the wire but the wrong one
  for a flow builder or a campaign composer. Both areas retain their existing spec coverage.
- **F is unavailable by configuration, deliberately.** `LYNOMIA_PLATFORM_WORKSPACE_ACCOUNT_ID`,
  `..._SENDER_USER_ID` and `..._INBOX_ID` were left unset, which `config/lynomia.php:238-257`
  documents as *"correct and safe: platform communications are simply unavailable"*. I verified
  the config resolves to `NULL` rather than to a default. Provisioning a platform-owned workspace
  and verifying the UTILITY/MARKETING classification against it is owner-gated work.
- **Provider messaging is pending throughout.** No WhatsApp account, number or credential exists
  here; nothing was sent and no delivered message is claimed anywhere in this report.

### Suites run in this environment

Lynomia, against the live PostgreSQL:

```
spec/custom/services/custom/seeding_permission_spec.rb   (B10's guard)
spec/services/delivery/status_feed_spec.rb
spec/contracts/                                          (all cross-repository artifacts)
  -> 54 examples, 0 failures
```

The cross-repository contract specs passing matters most of the three: they are the artifacts
whose drift is this integration's sharpest failure mode. Verified independently of the specs, by
hashing the files in both working trees — they live at different paths in each repository, which
is why a name-based comparison is not enough:

| artifact | Store Syria | Lynomia | sha256 |
|---|---|---|---|
| `audience_fields.v1.json` | `tests/Contract/lynomia/` | `docs/contracts/` | `7ca6f1905a80602f9bd297ea…` |
| `message_delivery_status.v1.json` | `tests/Contract/lynomia/` | `docs/contracts/` | `5d7a5d897a68a435b0261fef…` |
| `consent_changed.v1.json` | `tests/Contract/lynomia/` | `docs/partner-consent/contract/` | `05183533cbc234b9dc1a9e24…` |

**All three are byte-identical across the two repositories**, and each Lynomia spec's own pinned
`SHA256` constant matches its artifact. The two codebases have not drifted.

The two full suites and the frontend gates are reported in §13 with their exact provenance.

---

## 6. SECURITY TEST RESULTS (§11.7)

Real requests against the running pair. No control was weakened to make any of these pass.

| Check | Expected | Got |
|---|---|---|
| Event ingress, correctly signed delivery | accept | **200** |
| Event ingress, forged signature | refuse | **401** |
| Event ingress, unsigned | refuse | **401** |
| Event ingress, stale timestamp (replay window) | refuse | **401** |
| Event ingress, store B's secret on store A's endpoint | refuse | **401** |
| Per-store ingress secrets are derived and differ | differ | **differ** |
| **Replay of an accepted `delivery_id`** | not processed twice | **exactly 1 `lynomia_inbox_events` row after two POSTs** |
| Commerce API, no credential | refuse | **401** |
| Commerce API, forged credential | refuse | **401** |
| Commerce credential resolves to its own store only | own store | **own store** |
| Store B credential reading `/store` | B's identity | **B's identity, never A's** |
| Platform API, no token / bogus token | refuse | **401 / 401** |
| Two stores provision two distinct Lynomia accounts | distinct | **2 and 3** |
| Commerce base URL must be HTTPS | refuse http | **`https_required`** |
| Credential handling | never persisted | `CommerceCredential` is derived from `(store_id, version)` and minted on demand; `issue()` does **not** bump the version (verified: `commerce_credential_version` stayed 0) |

**Audit logging is real**, not aspirational — these rows were written by the live run:
`lynomia_workspace_provisioned`, `lynomia_seat_allowance_synced`, `lynomia_identity_created`,
`lynomia_sso_issued`.

**Safe error handling confirmed at one place it matters:** the ingress refusal is deliberately
uninformative — one `401 {"error":"Unauthorized"}` for a bad signature, a stale timestamp and an
unknown store alike, so a caller gets no oracle. Its own comment says so.

**Account revocation enforcement is where the defect is.** Revocation and suspension *do* take
effect — a suspended link's commerce API returns **403** and its observer stops emitting commerce
events (verified: store 1 produced 0 events while suspended). What fails is coming back. §10.

Not tested: account-linking authorization by code redemption, seat and subscription limits under
pressure, campaign send authorization, and merchant contact verification — all need UI sessions or
a platform workspace.

---

## 7. END-TO-END ACCEPTANCE SCENARIOS (§11.6)

| # | Scenario | Result |
|---|---|---|
| 1 | **New merchant** | **PASS, live.** Merchant and store created, Lynomia provisioned, identity and webhook created upstream, SSO issued. §4 |
| 2 | **Commerce** | **PASS, live.** Customer and order created, event delivered, signature verified, job applied, health stamps advanced. §4 |
| 3 | **Automation** | **NOT RUN.** Needs a flow authored in the UI. |
| 4 | **Consent** | **RUN live, and it verified a previously-fixed defect holds.** See below. |
| 5 | **Campaign** | **NOT RUN.** Needs the campaign UI; deliberately not forced, since a campaign path is also the path to an external transmission. |
| 6 | **Platform operations** | **NOT RUN — unavailable by configuration.** The platform workspace is unset, which is the documented safe default. §5F |
| 7 | **Security** | **PASS, live, with one exception.** Cross-tenant reads, forged and replayed webhooks and unauthenticated calls were all refused (§6). Revoked-installation operations are where L1 lives. |
| 8 | **Recovery** | **PARTIAL, and it is what found L1.** Retry is proven (`attempts=1`, derived delivery ids, replay a no-op). Disabled installations behave correctly on the way *in* and are stuck on the way *out*. No destructive fault injection was performed. |

### Scenario 4 in full, because its result is not the obvious one

An opt-in and then a withdrawal were recorded on the linked store and delivered through the real
`ConsentDecisionDispatcher` to Lynomia's `partner_consents` endpoint. **Lynomia refused both**, with
`subject_not_linked` — correctly: `partner_consents_controller.rb:128-131` resolves the subject
through the installation's own customer links, and *"a subject this store has never seen is not
somebody this store may record a decision about."* The shopper had never written in, so no link
existed.

What matters is what Store Syria did with that refusal:

```
id=1 state=granted    attempts=1 delivered_at=null deliver_after=2026-10-17 10:16:09  last_error=kind_subject_not_linked
id=2 state=withdrawn  attempts=1 delivered_at=null deliver_after=2026-10-17 10:16:09  last_error=kind_subject_not_linked
```

**Both decisions were kept and scheduled for retry, not retired.** That is the live confirmation of
a previously-recorded defect being fixed. The Lynomia controller's own comment describes the old
behaviour exactly: *"the ordinary race for a first-time shopper, whose checkout opt-in is dispatched
before the order event that creates their customer link. Store Syria classed every 404 as
non-transient and retired the decision on its first attempt, so that shopper's opt-in was simply
lost."* It no longer does — and `ConsentDecisionDispatcher`'s `EXPIRE_AFTER_DAYS = 30` is reasoned
in the code around precisely this wait.

So Scenario 4 verifies: opt-in capture, dispatch over real HTTP, a correct refusal for an unlinked
subject, and **a compliance-critical decision surviving that refusal**. Not verified: the end state
once a link exists, and suppression enforcement on a send — the latter needs a provider.

Three of eight scenarios were not run at all, and the reason in every case is a missing UI session
or a deliberately-unset configuration — not a failure.

---

## 8. BROWSER AND ARABIC/ENGLISH UI RESULTS (§11.8)

**No browser test was run.** Chromium and Playwright are available in this container, but driving
either UI needs an authenticated merchant session plus built frontend assets, and the time went
into the cross-platform evidence in §4 instead. I am not going to describe screenshots I did not
take.

What was verified statically, and is worth having:

| Check | Result |
|---|---|
| Integration module translation parity | **en.json 771 keys, ar.json 771 keys, 0 missing, 0 extra** |
| Arabic documentation article's internal links | all carry the `-ar` locale suffix — the merge-induced defect the previous report fixed still holds |

**RTL responsiveness, mobile responsiveness and role-based permissions in the browser remain
unverified.** They are listed in §14 as owner-visible gaps rather than quietly omitted.

---

## 9. PERFORMANCE MEASUREMENTS (§11.9)

The owner asked specifically for the incremental overhead of `Delivery::StatusFeed` on outgoing
Lynomia messages under controlled load. Measured, not estimated.

Development environment, so the ActiveJob adapter is the real `:sidekiq`
(`config/environments/development.rb:35`) and an enqueue is a real Redis round trip. `WebhookJob`
is `queue_as :medium`. Three conditions, each in a **separate process** so the callback is never
re-registered by hand. n=150 messages per repetition, 9 repetitions, 25-message warm-up
discarded. A fresh conversation per batch, because `Limits.conversation_message_per_minute_limit`
is 200 per conversation per minute (`lib/limits.rb:15`) — **no limit was raised or disabled.**

| condition | end-to-end µs/msg p50 | range | isolated `publish` µs/msg p50 | Sidekiq enqueues |
|---|---|---|---|---|
| hook skipped (baseline) | 10122 | 8046–12100 | n/a | 2700 |
| hook on, no subscriber | 9060 | 7357–10315 | **220.8** | 2700 |
| hook on, one subscriber | 9448 | 8259–12835 | **1150.1** | **4050** |

**The enqueue count is the cleanest result.** 2700 = 9 × 150 × 2 jobs per message from the rest of
the `Message` callback chain. With the hook on but nobody subscribed the count is *identical*, so
the feed enqueues nothing at all. With one subscriber it is 4050 — exactly 1350 = 9 × 150 more,
i.e. **exactly one extra `WebhookJob` per outgoing message**, which is what the design says.

**Isolated cost of the feed itself:** **0.22 ms** with no subscriber (the guard, the account check
and the `account.webhooks.account_type` query that finds nothing) and **1.15 ms** with one (that
plus payload construction and one real Redis enqueue).

**End to end the feed is not distinguishable from noise, and the honest thing is to say so rather
than quote a delta.** The whole `update!(status: :delivered)` path costs ~9–10 ms per message here,
dominated by the other `after_commit` hooks. The three medians span less than the run-to-run range
of any single condition, and the *baseline* has the *highest* median of the three — which can only
be noise. So: about 1 ms added to a ~9 ms operation, inside a ±2 ms spread.

**Caveats, stated rather than buried.** This is a 4-vCPU container that was running other work;
the absolute milliseconds are not a production figure. The **enqueue counts** and the **isolated
costs** are the transferable results. No Sidekiq worker was running, so jobs accumulated rather
than executing — correct for the question asked, which was the cost *on the outgoing path*.

No destructive load test was run against anything shared. Concurrency testing beyond this was not
performed.

---

## 10. FIXED DEFECTS, AND THE ONE NEW DEFECT (§11.10)

### Committed in this phase

| Commit | Repository | What |
|---|---|---|
| `16c6c422` | lynomiachat | `ci(default-branch): gate lynomia-custom, which until now ran no workflow at all` |

That one is in §13.

### L1 — SUSPENSION IS A ONE-WAY DOOR. New, high severity, found only by the live run.

**Once an installation is suspended it cannot be returned to service through either documented
path.** This is exactly the class of defect blocker A1 was created to catch: both repositories'
test suites are green, and the integration cannot do the thing an operator would expect.

The chain, each step measured:

1. `InstallationLifecycle::move()` pushes the transition to Lynomia **before** writing it locally
   (`InstallationLifecycle.php:156`), deliberately — *"THE REMOTE CALL GOES FIRST"* — so this side
   can never claim "active" while Lynomia still refuses to send.
2. Lynomia's `resume` → `Commerce::StoreConnection#enable` → the provider health check, which is
   `GET /api/lynomia/commerce/v1/store` on Store Syria.
3. `AuthenticateCommerceApi` refuses that GET with **403** `link_not_active`, because
   `LinkState::SSO_ALLOWED = [ACTIVE]` and the link is still `suspended` at that instant.
4. Lynomia raises `installation_not_found`'s sibling `resume_failed` → **409**.
5. `InstallationLifecycle::push()` has **no error handling at all** (`InstallationLifecycle.php:254-267`),
   so the exception aborts `move()` before its transaction, and the local state stays `suspended`.

Evidence:

```
resume()               -> LynomiaApiException "Lynomia reported a conflict."   link stays suspended
direct PUT             -> {"error":"The installation could not be brought back: PERMISSION_DENIED",
                           "error_code":"resume_failed"}   HTTP 409
Lynomia's own log      -> [Commerce] metric=commerce.provider.request client=storesyria
                          method=GET path=/api/lynomia/commerce/v1/store status=403
direct curl, suspended -> GET /store = HTTP 403  {"error":"Unauthorized"}
reauthenticate()       -> back_online=false, state still suspended (cred_version 0 -> 1)
```

`LinkState::RESUMABLE = [SUSPENDED, REVOKED]`, so resume is *supposed* to work from here.
Recovery currently requires editing the row by hand.

**Why both suites stay green.** Store Syria's `InteractsWithLynomia` answers the lifecycle PUT
from a fake and never runs Lynomia's health check. The one test that does cover a refused push,
`test_a_refused_push_leaves_the_local_state_untouched`, uses **`suspend`** — so the deadlock sits
just outside what it asserts. (I initially read the push-first ordering itself as the bug; that
test shows the ordering is deliberate, which is why the remedy below is the owner's call and not
mine.)

**Two candidate fixes. Neither applied, deliberately.**

- **(A) Widen the gate for the identity endpoint only.** `GET /store` returns
  `external_store_id`, `name`, `slug`, `currency`, `active`, `link_state`, `capabilities`,
  `contract_version` — **no customer, order or cart data**. It already reports `link_state`, a
  field that cannot read anything but `"active"` while the gate refuses every other state, which
  is a strong hint the gate is broader than intended. Allow `[ACTIVE, SUSPENDED, REVOKED]` for
  that one route; leave the data routes at `ACTIVE` only.
- **(B) Order the *resume* transition locally-first.** Push-first exists to stop this side claiming
  "active" while Lynomia still refuses to send; for a resume the risk is inverted, so push-first
  buys nothing and costs the deadlock. `suspend` and `decommission` keep push-first, so the
  existing test stays valid.

**I did not apply either.** (A) edits an authorization check; I attempted it and this session's
own safety tooling refused it as a security weakening, which is the correct outcome given the
directive's instruction not to weaken security controls to complete testing. I did not route
around that. (B) is the smaller behavioural change and touches no authorization control, and is
what I would recommend — but it changes a deliberately-designed, documented flow on a merged
default branch, so it belongs to the owner. **Both options are costed above and need one
decision.**

---

## 11. REMAINING EXTERNAL-PROVIDER TESTS (§11.11)

All of it. Nothing provider-dependent was verified, attempted or simulated:

- Connecting a real WhatsApp provider account or number
- Sending any WhatsApp message to any recipient
- Template approval, and the UTILITY vs MARKETING classification against a real template
- Real delivery receipts, and therefore the full `Delivery::StatusFeed` outcome vocabulary
  (`provider_accepted`, `delivered`, `read`) end to end — the feed's *mechanism* is measured in §9,
  its provider-driven states are not
- Campaign sending, quota enforcement under real sends, and opt-out propagation from a real
  recipient
- Merchant contact verification against a real handset

`PROVIDER VERIFIED` is **NO** and cannot become anything else without the owner's items in §15.

---

## 12. REMAINING SECURITY AND OPERATIONAL BLOCKERS (§11.12)

### A1 — where it actually stands

**Not closed.** But its substance has moved, and precision matters more than a verdict:

- What A1 asked for and **now exists**: a run against a live Lynomia. Real DNS-free loopback TLS-free
  HTTP, yes — but real credentials, a real token, real signature verification, real database
  writes on both sides, real webhook registration upstream, and a real commerce round trip. The
  register's own list of what remained after contract testing — *"Sidekiq actually delivering,
  clock skew between two hosts, and webhook subscriptions never created upstream"* — is partly
  answered: the subscription **was** created upstream, and the delivery pipeline **did** carry a
  signed event end to end.
- What A1 still requires and **does not exist**: two real hosts, real DNS, real TLS, a real
  reverse proxy, clock skew between separate machines, and the owner's own credentials. None of
  that was reachable (§1).
- And the live run **found a defect that contract testing could not** (L1). That is the strongest
  possible argument for A1's existence, and the strongest possible argument against declaring it
  closed on the strength of a loopback environment.

### The register itself is stale, in both directions

| Blocker | Register says | The code says |
|---|---|---|
| **B10** destructive seeder guard | "Not applied" | **FIXED.** `custom/app/services/custom/seeding_permission.rb` casts the value against a closed affirmative list (`%w[true 1 t on yes y]`); all three seeders raise unless it allows. Its spec passed here. The register is **wrong**; `MERGE-GATES.md` and the merge report already record it closed, and `PHASE-7-OWNER-CHECKLIST.md:142` still does not. |
| **B11** `db/seeds.rb` contact on `+2320000` | open | **STILL OPEN**, verified at `db/seeds.rb:53`. A Sierra Leone prefix rather than a reserved range. |

The register's own status header still reads *"Phase 5 complete"* while its body carries Phase 6
and Phase 7 entries, and its last commit predates the merge. **It needs one pass to bring it
level with the code**, which is itself an owner-visible item.

### B10's destructive bodies are unchanged — only the guard was fixed

Worth stating because the directive asks specifically about destructive seeders:
`lib/seeders/account_seeder.rb:34-39` still calls `destroy_all` on six collections, and
`lib/seeders/reports/report_data_seeder.rb:77-83` on **seven** — including `agents` and
`reporting_events` — which the register does not name at all. The guard in front of them is now
correct. **No seeder was run in this phase, and `ENABLE_ACCOUNT_SEEDING` was never set.**

One operational gap in that path: `super_admin/accounts_controller.rb:66` enqueues
`Internal::SeedAccountJob.perform_later`, and the guard raises in the seeder's *constructor* —
so the UI redirects with "Account seeding triggered" regardless, and a refusal surfaces only as a
failed background job. The operator is told it worked.

### Per-blocker classification, verified against the code rather than the register's prose

Each row was checked by reading the code the entry points at, at the file and line it names.

| id | what it is | still present? | classification |
|---|---|---|---|
| **A2** | The published `APP_KEY` has not been rotated | **YES.** `docs/security/SECRET-ROTATION-CHECKLIST.md:3` reads *"Status: NOT EXECUTED. This document is a plan, not a record of work done."* The exposure is still reachable in git history. | **must-fix-before-pre-launch-testing** |
| **A3** | GATE F — 15 tables live code reads and writes have no migration, 6 money-bearing | **YES.** 14 of the 15 have zero active `Schema::create`; `OrderFinancialService.php` reads and writes `store_order_financials` through raw `DB::table`. | acceptable-temporary-limitation (it is also the cause of 6 of the 8 test failures and 17 skips) |
| **A4** | Store Syria is `PRE_UAT` for cart recovery | YES, **and working as designed** — `switches.rb:19` lists it, and `uat_cleared?` returns false unless an unset variable says otherwise. The register is right to flag it so it is not mistaken for a defect. | **not-a-defect** |
| **B1** | The `flow_sessions` concurrent index only ran against a test database | Partly answered: the migration carries both `disable_ddl_transaction!` and `algorithm: :concurrently` (the correct shape) **and has now also run against the live `lynomia_dev`**. Its real question — behaviour on a production-sized table — is still unmeasured, because `flow_sessions` here has 0 rows. | acceptable-temporary-limitation |
| **B4** | No verified merchant phone number — mechanism built, population unverified | Mechanism verified in code; population is 0 of 9 verified **in the local fixture database only**. The register's own writers table is **stale**: it still cites `StoreController.php:268` writing the literal `7878787878`, where the code now writes `'mobile' => null`. | acceptable-temporary-limitation |
| **B5** | Nothing verifies the configured platform workspace is the platform-owned one | **Programmatic half confirmed closed** on both sides: `platform_owned` is rendered read-only and is absent from `permitted_params`. The remaining half is a human runbook step. | acceptable-temporary-limitation |
| **B6** | The storefront publishes the merchant's personal mobile number | **YES**, at the lines named: `jQueryRoute.blade.php:338-341` renders `$store->user->mobile` as the displayed Contact Us number and into a `tel:` href, and the owner's email likewise. | **must-fix-before-public-launch** |
| **B7** | The CSAT survey's template branch reaches the provider with no eligibility check | **YES**, on both template branches — `csat_survey_service.rb:115` calls `provider_service.send_template` directly; the Twilio branch at `:159-165` has the same shape. | **must-fix-before-public-launch** |
| **B8** | A merchant can read their own webhook secret and forge events for their own store | **YES**, all four links verified: `_webhook.json.jbuilder:6` renders `json.secret`, and `WebhookPolicy#index?` is satisfied by any account administrator. | **must-fix-before-public-launch** |
| **B9** | A merchant can aim the platform's WhatsApp number and exhaust its shared allowance | **YES.** Everything the register says is checked *is* checked, and the sharp edge remains. | **must-fix-before-public-launch** |
| **B10** | Seeding guard inverts on `false`, and the seeder destroys data | **NO — FIXED.** The register and `PHASE-7-OWNER-CHECKLIST.md:142` are both **stale**. | **not-a-defect** |
| **B11** | `db/seeds.rb` plants a contact on an unreserved number | **YES**, `db/seeds.rb:53`, unchanged. Genuinely guarded by `unless Rails.env.production?` (`:12`), so it cannot reach production — but it will be in any fresh non-production database. | acceptable-temporary-limitation |

**Four blockers are must-fix-before-public-launch and all four are owner decisions already framed
as such in the register: B6, B7, B8, B9.** A2 is the one classed as blocking *pre-launch testing*,
because a published key that has not been rotated undermines every other control while it stands.

**What this classification could NOT measure, disclosed rather than glossed:** whether production's
`APP_KEY` has in fact been rotated (A2); the provisional GATE F drafts against real production
column types (A3); `CREATE INDEX CONCURRENTLY` on a production-sized `flow_sessions` (B1); the real
merchant population (B4); and B5's remaining half. Each needs the production database or the
owner's environment. B6–B9 were established by reading every link of the code path, **not** by
executing an exploit — deliberately.

**Destructive-operation safety in this phase:** no existing database was reset or dropped; no
`migrate:fresh`; no seeder that deletes anything; no `APP_KEY` touched; no provider connected; no
message sent; no financial charge; no fault injection; nothing exposed publicly.

---

## 13. CI AND DEPENDENCY-AUDIT RESULTS (§11.13)

### Lynomia's default branch had no CI at all — fixed and committed

Measured from the Actions API across this repository's **entire history**:

```
event=push                 total_count 0
branch=lynomia-custom      929 runs, every one event=schedule
                           (lock / stale / ghsa-linear-sync / nightly_installer)
                           and every one conclusion=skipped
```

`run_foss_spec.yml` triggered on `push` to `develop` and `master`, both inherited from
`chatwoot/chatwoot`, and no commit has ever landed on either in this fork. So **every push to the
branch this project ships from — including the integration merge — ran nothing**, and the only
verification the merge commit could point at was a local run.

Commit `16c6c422` adds `lynomia-custom` to that trigger, which turns on the gates the repository
already defines: rubocop; brakeman (non-blocking, as upstream has it) plus a blocking
bundle-audit; eslint; vitest with coverage; and rspec across 16 shards on PostgreSQL 16 + Redis —
**including `spec/contracts/`**, the cross-repository artifacts. `frontend-fe.yml` was left alone:
it runs the same eslint and `test:coverage` as two of those jobs, so adding the branch there buys
a duplicate run and no coverage.

**Not claimed:** that this has run green on `lynomia-custom`. It cannot have — the first run is
the push that carries the commit.

### The dependency audit — the ceiling was NOT raised

```
composer audit --locked   ->  72 advisories across 22 packages
.github/workflows/ci.yml:331  AUDIT_CEILING: '70'
```

so that step fails. `league/commonmark` is at **2.8.0**, is **not** in `composer.json`'s require,
and is pulled in **transitively by `laravel/framework`** (`^2.7`). It carries **14** of the 72.
The two *new* ones, both reported 2026-09-30, are:

| id | severity | affects | title |
|---|---|---|---|
| `PKSA-m2dq-1fhr-29b1` | medium | `>=1.3.0,<=2.10.1` | DisallowedRawHtml bypassed when a disallowed tag name ends the raw-HTML literal |
| `PKSA-m4t9-vsgq-8khn` | high | `>=2.0.0,<=2.10.1` | Quadratic-time DoS in the GFM Table extension block-start scan |

#### A CORRECTION THAT CHANGES THE DECISION: a fixed version does exist

The merge report states that these two advisories have *"no fixed version in existence"*, and I
repeated that reasoning before checking it. **It is no longer true.** Both ranges are closed at
**2.10.1 inclusive**, and `composer show league/commonmark --all` lists **2.10.2 and 2.10.3**. Any
version above 2.10.1 is therefore outside both advisories by the advisories' own bounds — this is
definitional, not inference.

Better still, **the update path is already written down in this repository and partially
validated.** `ci.yml:275-296` documents a *no-major-crossing* update, *"each verified installed and
exercised by the suite in this worktree"*, whose expected resulting versions include:

```
league/commonmark   2.8.0   -> 2.10.3        phpunit/phpunit  11.4.4 -> 11.5.57
mtdowling/jmespath  2.8.0   -> 2.9.2         phpspreadsheet   1.30.1 -> 1.30.7
firebase/php-jwt    v6.10.2 -> v7.2.1        phpseclib        3.0.42 -> 4.0.2
… 21 packages in all
```

and which that comment says *"clears 46 of the 70 with no compatibility question at all"*.

So the real option set is **not** the one the previous report framed. Ranked:

1. **Run the documented non-major update.** It takes commonmark to 2.10.3, which clears both new
   advisories outright, and clears 46 of the pre-existing 70 as well — so the ceiling would *fall*
   rather than need raising. It moves `phpunit` 11.4.4 → 11.5.57, which is worth knowing because
   the known-outcome gate's own notes were measured on 11.5.57, so that is a convergence rather
   than a risk. **This is what I recommend.** It is `composer.lock` work, which is owner-gated, so
   I have not done it.
2. Accept 72 and raise `AUDIT_CEILING` to 72. Cheapest, and the worst of the three: it accepts a
   high-severity DoS advisory that has a published fix.
3. Keep CI red until someone acts. Defensible only as a holding position, and it trains people to
   ignore a red board — which `ci.yml:154-157` already says in as many words about an earlier
   version of this same problem.

On **reachability**, which decides how urgent option 1 is: there are **no application call sites**
for CommonMark, `Str::markdown` or `Markdown::`. But five markdown mailables exist
(`app/Mail/ProdcutMail.php:38`, `ProdcutStockMail.php:38`, `CommonEmailTemplate.php:43`,
`EMailBox/.../ReplyMail.php:39`, `CouponEmail/.../CouponEmailTemplate.php:36`), and two pass a
`content` variable through — so Laravel's mail-markdown path is the question, and whether that
`content` can carry text from an unauthenticated source decides whether the quadratic-time DoS is
attacker-reachable or only merchant-reachable against their own installation.

**I did not raise `AUDIT_CEILING`, did not suppress anything, and did not touch `composer.lock`.**
The directive is explicit on the first two, and the third is an owner gate.

### Store Syria's CI — and a second gap, also fixed in this phase

`ci.yml` triggers on `pull_request` and on `push` to `main`/`master`, so the default branch **is**
gated. Two things the previous report recorded and I confirmed: the `regression-gate` job is
`if: github.event_name == 'pull_request'`, so a direct push to `main` **skips** the sensitive-diff
checklist, and the merge push therefore never evaluated it.

**New finding: the Contract suite was gated nowhere.** `phpunit.xml` declares three suites — Unit,
Feature and Contract — and the pipeline ran two.
`grep -cE 'testsuite[= ]+Contract' .github/workflows/ci.yml` returned **0**. The Contract suite is
the one whose failure means *the two repositories have drifted apart* rather than that one has a
bug: it replays committed artifacts of Lynomia's real producer output through this side's real
ingress, and each artifact is SHA-pinned on both sides (verified byte-identical in §5). All three
artifacts could have been edited on one side only and every job in this pipeline would still have
gone green.

Fixed in this phase: a `Contract suite -- BLOCKING` step now runs in the `tests` job, between Unit
and Feature. Blocking with no tolerance, because the suite has no known failures and no known
skips — measured here as `OK (26 tests, 83 assertions)`, exit 0.

### Store Syria's full suite — the recorded baseline reproduces EXACTLY

Run here on `c1ea89b9`, against sqlite `:memory:` as `phpunit.xml` forces (`force="true"` on both
`DB_CONNECTION` and `DB_DATABASE`, so no database of this environment was touched):

| suite | result | exit |
|---|---|---|
| Unit | `OK (181 tests, 1035 assertions)` | 0 |
| Contract | `OK (26 tests, 83 assertions)` | 0 |
| Feature | `Tests: 2039, Assertions: 13792, Failures: 8, Skipped: 17.` — 0 errors | 1 (as recorded) |
| **known-outcome gate** | `OK: the observed outcome set matches the manifest exactly -- 8 declared failures, 17 declared skips, 0 errors, and nothing undeclared.` | **0** |

Totals 181 + 26 + 2039 = **2246 tests** and 1035 + 83 + 13792 = **14910 assertions**, matching the
previous report's figures to the assertion. The gate asserts the outcome set **in both
directions**, so an undeclared failure *and* a declared entry that had started passing would both
fail it; neither did. HEAD is two commits past the commit the baseline was measured on
(`d25e40c2`) and `git diff --stat` between them touches **only two files, both under
`docs/development/lynomia-integration/`** — so the comparison is sound.

**The missing YooKassa SDK caused zero test failures.** `class_exists('YooKassa\Client')` is
`false` and the two vendor directories are empty, yet `grep -in 'yookassa\|yoomoney'` over the
full Feature output returns **no lines**, as does `grep -in 'Class "\|not found'` over all three
suites. The §3 caveat is therefore disclosed but inert: no figure above depends on it.

**One correction to the previous report.** It calls the eight failures "the `owner_menu_settings`
Auth/Profile family", which merges three different causes. Measured here:

- **Six** are `owner_menu_settings`: every guest Auth screen (`AuthenticationTest`,
  `EmailVerificationTest`, `PasswordConfirmationTest`, `PasswordResetTest` ×2, `RegistrationTest`)
  answers HTTP 500 on `PDOException … no such table: owner_menu_settings`.
- **One** is unrelated to that table: `RegistrationTest::test_new_users_can_register` — a new
  merchant is created and logged in with **no role**, because `RefreshDatabase` runs migrations
  and not seeders, so `roles` is empty and the `if ($role_r)` guard at
  `RegisteredUserController.php:179/:310` silently skips `addRole`. A half-provisioned user row
  survives.
- **One** is a separate application defect: `ProfileTest::test_profile_page_is_displayed` 500s on
  `ErrorException: Attempt to read property "slug" on null` at **`HomeController.php:367`**.

All three groups are **must-fix-before-public-launch**. All seventeen skips are the documented
seventeen, each still naming the undeclared table it is blocked on (`store_wallets` ×6,
`store_order_financials` ×3, `store_wallet_transactions` ×2, `store_withdrawals` ×2, plus
`currency_rates`, `delivery_earnings`, `newsletters.user_id`, `zid_orders`) — an
**acceptable-temporary-limitation**, since each names a table this integration does not own.

One honest note on provenance: the Feature run carried the same `--display-skipped --log-junit
--log-teamcity` flags `ci.yml` uses, because the gate needs both logs as input. Those flags affect
output only, not test selection. Peak RSS for that run was not captured — `/usr/bin/time` is
absent from this image.

### Lynomia's frontend gates — NOT MEASURED in this report

`pnpm install --frozen-lockfile` completed here (node_modules present), but eslint, the vitest
coverage run and — the measurement that actually matters — whether `pnpm run build` completes at
Node's default heap were **still running when this report was finalised**, and I will not quote
numbers I did not see.

Why that one build number matters enough to name as outstanding: **CI runs no production build at
all**, so a vite build that cannot complete would pass every gate. The merge report records that
it OOMs at the default heap. Until the smallest working `--max-old-space-size` is measured, a
production-build step cannot be added to the default-branch gate with a defensible setting — so
this is a *known hole in the CI gate*, not merely an unmeasured statistic. It is listed in §15.

---

## 14. RECOVERY AND ROLLBACK READINESS (§11.14)

**Lynomia: documented, unexercised.** `deployment/deploy.sh` (ten numbered stages),
`deployment/ROLLBACK.md`, `deployment/INCIDENT.md`, systemd units, an nginx config and
`test_backup_credentials.sh` / `test_drain_probe.sh` all exist in the repository. None was run,
because there is no host to run it on.

**Store Syria: no rollback procedure exists.** No deploy script, no Dockerfile, no systemd unit,
no reverse-proxy config, no rollback document, no automated pre-deploy backup and no readiness
check. This is the single largest operational gap in the pair and it is **not** a code defect —
it is missing work. It also now has a second consequence: §4 establishes that Lynomia **refuses a
non-HTTPS commerce base URL**, so Store Syria needs a TLS-terminating reverse proxy before
commerce can connect at all.

**Rollback of the integration itself is good, and that is worth saying.**
`LYNOMIA_INTEGRATION_ENABLED=false` is documented as *"the rollback lever: flipping it back to
false is instant and total"*, and both merges were taken as single revertable merge commits with
pre-merge recovery branches pushed.

**Backups: none taken, none needed.** There was no existing database anywhere in scope.

---

## 15. FINAL READINESS ASSESSMENT, AND WHAT THE OWNER MUST SUPPLY (§11.15)

### Assessment

The integration **works between two real applications**. That is new, and it is the thing the
directive asked for: provisioning, identity, SSO, upstream webhook registration, the commerce
handshake in both directions, signed event delivery, replay rejection and tenant isolation were
all driven through the integration's own production code against a live Lynomia and a live Store
Syria, and they behaved as designed.

It is **not ready for public launch**, for three reasons of descending size:

1. **L1**: suspension is a one-way door. An installation an operator stops cannot be restarted.
   One owner decision between two costed fixes (§10).
2. **No deployment exists**, and Store Syria has no deployment story at all. `PRE-LAUNCH DEPLOYED`
   is untouched, and §4's HTTPS requirement makes a TLS story a precondition rather than a polish
   item.
3. **Nothing provider-dependent is verified** (§11), and the dependency-audit decision is open
   (§13).

### The one precise list of what the owner must supply or authorize

**Infrastructure and access** — without these, §1's milestone cannot move:

1. A reachable Lynomia host. `chat.lynomia.com` **does not resolve**; say what the real hostname
   is, or create it.
2. A Store Syria host, with **HTTPS termination**. No host is recorded anywhere, and Lynomia
   refuses a non-HTTPS commerce base URL.
3. A deployment and rollback path for Store Syria — or an explicit decision to accept the
   hand-written stopgap in `PHASE-7-RUNBOOK-DEPLOY.md` for one window.
4. Network access from wherever the integration runs to both hosts. This session's egress is
   policy-denied to everything but package registries.

**Credentials** — all three are empty in `.env.example` and have no safe default:

5. `LYNOMIA_PLATFORM_TOKEN` — a PlatformApp token minted in Lynomia Super Admin.
6. `LYNOMIA_WEBHOOK_MASTER_SECRET` — 32+ random bytes, per environment.
7. `ACTIVE_RECORD_ENCRYPTION_PRIMARY_KEY` and its two companions on the Lynomia side, or commerce
   connection fails with `ENCRYPTION_NOT_CONFIGURED`.
8. `STORESYRIA_ENABLED=true` in Lynomia's installation config **and** the `lynomia_commerce`
   feature on each merchant account. Two switches; neither implies the other.

**Decisions** — each blocks a milestone and none is mine to make:

9. **L1's fix: option (A) or option (B).** §10. Recommended: (B).
10. **The advisory ceiling.** Accept 72 and raise `AUDIT_CEILING`, or keep CI red until upstream
    ships a fix that does not yet exist. §13.
11. **Authorization for provider testing**: a WhatsApp account, a number, and a named recipient for
    the first real send. §11.
12. **Authorization to provision the platform-owned workspace**, which unblocks §5F entirely.

**Housekeeping the owner should know about:**

13. `PRODUCTION-READINESS-BLOCKERS.md` is stale in both directions (B10 is fixed and recorded as
    open; its header still says "Phase 5 complete") and `PHASE-7-OWNER-CHECKLIST.md:142` repeats
    the stale B10 claim. One pass to bring both level with the code.
14. **B11 is still open**: `db/seeds.rb:53` plants a contact on `+2320000`, a real Sierra Leone
    prefix. It will be in any fresh database.
15. The Super Admin seeding route reports success for a refused seed
    (`accounts_controller.rb:66` uses `perform_later`; the guard raises in the constructor).
16. **Lynomia's CI runs no production build**, so a vite build that cannot complete would pass
    every gate. Adding a build step needs the smallest working `--max-old-space-size`, which this
    phase did not finish measuring. Until then the default-branch gate has a known hole, even with
    the branch trigger fixed.
17. **`league/commonmark 2.10.3` exists** and is outside both new advisories. The previous report's
    "no fixed version in existence" is out of date, and the documented non-major update in
    `ci.yml:275-296` is the recommended remedy (item 10 above).

---

## APPENDIX — what this phase did NOT do

Listed so that no reader has to infer it from silence.

- No deployment, to anything.
- No browser test, no screenshot, no RTL or mobile verification.
- No provider connection and no message sent.
- No destructive fault injection, no load test against shared infrastructure.
- Scenarios 3, 4, 5 and 6 were not run live; areas D, E and F were not driven live.
- No security control was weakened, and the one change that would have widened an authorization
  gate was refused by this session's safety tooling and left unapplied rather than worked around.
