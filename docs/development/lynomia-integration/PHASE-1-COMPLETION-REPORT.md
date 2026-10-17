# Store Syria × Lynomia Chat — Phase 1 Completion Report

**Phase:** module foundation · secure account mapping · verified provisioning & linking · one-way SSO
**Branch:** `claude/store-syria-lynomia-integration-1oa1tt` (pushed) · **Not merged, not deployed.**

---

## 1. Baseline

| | HEAD audited | Notes |
|---|---|---|
| **Store Syria** | branch @ `656cd975`, on top of `origin/main` @ `0d2ca628` | The remediation branch is **still unmerged** into `main` (main +0 / branch +5). Per instruction, work continued on the verified branch rather than merging unrelated work. |
| **Lynomia Chat** | `origin/lynomia-custom` @ **`86a43252`** | Advanced **77 commits** since the last report. `claude/platform-support` is now merged. |

Verified before coding:
- **Chatwoot retirement holds** — no `chatwoot` route in the booted router; the only residual references are the intentional retirement comments.
- **Historical mapping data preserved** — `chatwoot_integrations` / `chatwoot_notification_templates` are still un-dropped, as recorded in `database/migrations/_gate_f_pending/README.md`.
- **Lynomia APIs confirmed present at `86a43252`**: `POST /platform/api/v1/accounts`, `POST /platform/api/v1/users`, `POST …/accounts/{id}/account_users`, `GET …/users/{id}/login`; SSO token still `SecureRandom.hex(32)` with a 5-minute Redis TTL.

---

## 2. What was built

A new Workdo module, `packages/workdo/LynomiaChat` — **29 files, 2,054 PHP LOC**, plus a 557-line test suite.

| Concern | Implementation |
|---|---|
| Platform API client | `PlatformApiClient` — the only place the token is read; server-side only; typed failures; transport-only retry (a 4xx is never retried, because re-sending a rejected create is how duplicate accounts appear) |
| New workspace | `AccountProvisioner` |
| Existing workspace | `AccountLinker` — a **claim**, not a link (see §4) |
| One-way SSO | `SsoLinkIssuer` |
| Mapping | `lynomia_store_links` + `LynomiaStoreLink` |
| Tenancy | `EnsureStoreOwnsLink` middleware |
| Merchant UI | Communication & Marketing Center (overview + onboarding) |
| Super Admin | dedicated **Lynomia Chat Integration** page, tabbed |
| i18n | `en.json` / `ar.json`, 23 keys each, **key parity verified** |

**Lynomia required zero changes.** Its working tree is clean at `86a43252` and no branch was created there. Phase 1 is pure reuse: no messaging, automation, campaign, bot or channel engine exists in Store Syria.

---

## 3. Business rules, and where each is actually enforced

| Rule | Enforcement |
|---|---|
| One Lynomia account per store | `lynomia_store_links.store_id` **UNIQUE** |
| One account cannot back two stores | `lynomia_store_links.lynomia_account_id` **UNIQUE** |
| One-way SSO only | Only `GET /users/{id}/login` is consumed; no reverse endpoint exists |
| Independent Lynomia subscriptions intact | Nothing touches billing; the claim flow attaches no permissible without operator approval |
| Store Syria owns commercial allocation | Entitlement is `PlanModuleCheck:LynomiaChat` against the plan's module list |
| No unauthorized cross-store sharing | `EnsureStoreOwnsLink` scopes by `store_id` **AND** `owner_user_id` |
| Ownership transfer needs approval | `link_existing` cannot activate without a platform operator + the issued code |
| No auto-activation of un-UAT'd providers | Integration ships **disabled**; no channel is connected in this phase |

Both unique constraints are database indexes rather than service checks, because check-then-write loses races — the pattern this codebase already loses quota races on.

---

## 4. The one design decision worth your attention

**"Link my existing Lynomia account" is implemented as a claim requiring administrative approval, not as an automatic link.**

The obvious implementation — POST the merchant's Lynomia email to `/platform/api/v1/users` — is an **account-takeover primitive**. Upstream, that endpoint resolves `User.from_email(...) || User.new(...)` and then grants the calling app a permissible on whatever it resolved. Handed an address belonging to an independently-registered Lynomia customer, it does not fail: it hands Store Syria control of **their** account, after which `/users/{id}/login` mints a working session for them.

Store Syria cannot detect that case — a platform token may only read accounts it already holds a permissible for, so "does this merchant control that account?" is a question this app is structurally unable to answer. So:

- `AccountLinker` **never calls `createUser()`**. It records a pending claim, shows a one-time code once (only the hash is stored), grants nothing, and requires a platform operator to approve with that code.
- `AccountProvisioner` — the only caller of `createUser()` — uses a deterministic store-scoped service address that cannot belong to a real person. The merchant never signs in with it; access is the SSO link.

This is slower than an automatic link and it is the only version that satisfies "no account may be linked on email similarity alone."

---

## 5. Credential handling under the unresolved APP_KEY exposure

Per your scope restriction, nothing was rotated and no production credential was created.

- The platform token is read **from the environment on each use** and is never persisted, cast, logged or serialised. The retired integration's pattern — an `encrypted` DB column — would provide **no confidentiality** here, because APP_KEY is public and unrotated.
- The integration ships **disabled** (`LYNOMIA_INTEGRATION_ENABLED=false`) and performs **no remote call** while disabled — asserted by a test.
- `base_url` has **no default**: the retired integration hardcoded `chat.lynomia.com` in three places, which is how an environment-specific endpoint reaches a public repository.
- All development and testing used `Http::fake()` with a dummy host and a dummy token.

---

## 6. What was reused versus what was written

The brief said *"prioritize reusable existing Lynomia code."* **The Lynomia repository has zero commits on this branch** — working tree clean at `86a43252`, 0 commits ahead. Phase 1 is pure reuse on that side.

| Capability | Source |
|---|---|
| Workspace creation | **Reused** — `POST /platform/api/v1/accounts` |
| User creation | **Reused** — `POST /platform/api/v1/users` |
| Member attachment | **Reused** — `POST /platform/api/v1/accounts/{id}/account_users` (idempotent upstream) |
| One-time SSO link | **Reused** — `GET /platform/api/v1/users/{id}/login` |
| Authorization of all four | **Reused** — `PlatformApp` + `platform_app_permissibles`; Store Syria structurally cannot reach an account it did not create |
| Store↔account mapping | **Written** — no equivalent exists on either side |
| Verified claim flow | **Written** — the takeover mitigation below |
| Entitlement / tenancy gating | **Reused** — `PlanModuleCheck`, `PlatformOperatorOnly`, `creatorId()`, the standard admin stack |

**No OIDC provider was built.** There is no Doorkeeper or OIDC gem in Lynomia's Gemfile, so an OIDC flow would have been net-new code on both sides. The existing SSO primitive is a 256-bit token with a 5-minute Redis TTL, single-use and user-bound, exchanged server-to-server — for one-way SSO that is stronger than what a hand-rolled OIDC layer would have given, and it is already in production use.

---

## 7. Tests — what ran, and what the numbers actually were

All figures below are from runs executed in this session, not projected.

### New suite

`tests/Feature/Lynomia/LynomiaConnectionTest.php` — **25 tests, 136 assertions, all passing.**

| # | Covers |
|---|---|
| 1–3 | Route-middleware stacks read **off the booted router**, not the routes file; anonymous refusal on all five surfaces |
| 4–6 | Provisioning creates exactly one active link; the merchant's email is **never** sent to Lynomia; provisioning twice does not create a second workspace |
| 7–8 | Both unique indexes, exercised as races rather than asserted as schema |
| 9 | The disabled kill switch sends **nothing** |
| 10–11 | A claim grants nothing; a claim on an account another store holds is refused |
| 12–15 | Wrong code, correct code (activates and burns it), expired claim, merchant-cannot-self-approve |
| 16–19 | SSO refused for an unapproved claim; entitlement denial; redirect for an active link; the SSO URL is never persisted or flashed |
| 20–21 | Two cross-tenant cases where a merchant points `current_store` at another tenant's store |
| 22–25 | The four review findings in §7a, each failing first against the code as shipped |

### Full suites

| Suite | Result |
|---|---|
| Unit | **173 tests, 1,010 assertions — 173 passing, 0 failures** |
| Feature | **1,437 tests, 13 failures, 17 skipped** |

The 13 Feature failures are the **identical pre-existing set** documented in `656cd975`. They were compared against the pre-phase baseline *name by name and numerically*, not merely by count: the raw-query census reports `app=113` against an expected 114 on both runs, and the no-table model census reports the same 12 models in the same order on both. Phase 1 introduces **no new failure** and moves **no census number**.

Per your scope restriction, those 13 were not touched.

### One real defect the new tests caught

`test_a_merchant_without_the_module_entitlement_is_refused` passed in isolation and **failed after an entitled test had run**. That was not a test-ordering artifact — it was a genuine cross-tenant entitlement leak in `ActivatedModule()`, which memoised into `static $active_module` keyed on nothing. The first non-empty module list resolved in a process was reused for every later principal in that process.

Under PHP-FPM each request is a fresh process, so it never surfaced in production. It surfaces anywhere a process outlives a request: **a queue worker, `artisan` in a loop, Octane, and a PHPUnit run.** Fixed in `f7826014` by moving the memo into the container, keyed by resolved owner id. This is the second defect in this project found by a test written for something else.

---

## 7a. Adversarial security review, and what it found

Phase 1 was reviewed adversarially after it was written. **It found two exploitable defects in my own new code.** Both are fixed in `302ae6bc`, each with a test that failed first against the code as shipped.

### HIGH — every merchant route was open to any staff user in the tenant

The module seeds a permission (`Manage Lynomia Chat`), hides its menu on it, and I had documented that the routes enforced it. **They did not.** None of the four layers is a role check:

- `PlanModuleCheck` resolves the module list of the tenant **owner**, so every staff member inherits the owner's entitlement and passes.
- `EnsureStoreOwnsLink` resolves staff to their owning admin via `creatorId()` — deliberately, so a merchant's staff reach the merchant's store — so staff match the owner's link too.

Hiding a menu item is not authorization. A staff member with an empty role could type `/lynomia/open` and be redirected into the merchant's workspace **signed in as its administrator** (the provisioned service user is attached with that role), with full read/write over every customer conversation, contact and channel. The same gap let any staff user consume the tenant's single provisioning slot and read a claim code out of the flash response.

Fixed with Laratrust's permission middleware on the merchant group. The route-stack test now pins the permission layer **separately** from entitlement and tenancy, because treating those three as one control is precisely how the gap arose.

### MEDIUM — a revoked link could be re-claimed back into the old workspace

`AccountLinker::request()` refused only an `ACTIVE` row, and the attribute set it force-filled rewrote `lynomia_account_id` while saying nothing about `lynomia_user_id`. A revoked row therefore kept the **original** workspace's user id while presenting itself as a fresh claim for an unrelated account. `allowsSso()` asks only that both ids are non-null — so an operator who approved "account 777777" was in fact handing back the workspace they had just revoked, and the approval screen showed them the new account id, not the inherited user id.

Fixed at the cause rather than by scrubbing the symptom: a claim may be opened only on nothing, or on an unapproved claim, and such a row has no identifiers to inherit. That also makes the states mean what `LinkState` documents — `REVOKED` is "terminal" and an operator's deliberate act; `SUSPENDED` keeps its ids so the *same* workspace is reconnected. Neither is the merchant's to undo by filing a new claim.

### Two further gaps, found alongside

**`EnsureStoreOwnsLink` authorized the link but never the store.** With no link to compare against — a foreign store that has never connected — the foreign store id reached the controller and provisioning claimed it, writing an active row with that tenant's `store_id` and the caller's `owner_user_id`. Because `store_id` is unique, the real owner would then have been *permanently* unable to connect. I proved this with a test before fixing it. **It was not a live exploit:** no HTTP path writes `users.current_store` without an ownership check today, and the test sets the column directly. But not depending on those checks staying correct was this middleware's entire premise, so the store is now authorized from `Store.created_by` first.

**The module's seeders declared `Database\Seeders` where the directories on disk — and every other module in `packages/workdo` — are lower case.** PSR-4 maps a namespace onto a path, so on a case-sensitive filesystem the capitalised spelling resolved to a directory that does not exist: `$this->call(PermissionTableSeeder::class)` would fatal and `package:seed` would find nothing. Harmless on its own; **combined with the permission gate it is a platform-wide lockout**, because the permission would never be granted and every merchant owner would get a 403 on a feature their plan includes. Aligned to the repo convention and pinned by a test.

### What the review checked and found clean

No SSRF (the base URL is env-only; every path is static with integer segments), no SQL injection, command injection or path traversal, no unescaped Blade output — both views escape everything including `lynomia_account_name`, which comes from the remote API — no secret reaching a response, view, log or database row, the SSO URL used solely as a redirect target and never persisted, no mass assignment from request data, CSRF present via the explicit `web` group, and no IDOR on the merchant routes because no id is accepted.

### One review suggestion I adopted, and one I did not

**Adopted:** `activated_modules_for_owner()` now memoises with `scoped()` rather than `instance()`. Both key by owner, so neither can leak across tenants — that was the bug the helper exists to fix. They differ in lifetime: an `instance()` binding lives as long as the application object, which in a queue worker is many jobs, so an owner whose plan lapsed mid-run kept passing `module_is_active()` until the worker restarted. `scoped()` is cleared between jobs. Under PHP-FPM the two are identical.

**Not adopted:** the reviewer suggested also nulling `lynomia_user_id` in the claim attribute set as a second guard. With the state check in place, no row reaching that write can carry identifiers, so it would be unreachable code — and this codebase's own guidance is to enforce at one point rather than repeat backup guards downstream.

---

## 7b. Two honest gaps in this phase that are not security defects

Neither was introduced by the review fixes; both are functional limits of Phase 1 that you should know about before approving Phase 2.

**1. A claim-linked workspace cannot actually be opened.** A `link_existing` claim never learns a Lynomia *user* id — only an account id — so after a clean operator approval `allowsSso()` is false and "Open Lynomia Chat" will refuse. This fails safe, and it is also exactly what Finding 2 exploited from the other side. Closing it is a **design decision for you**, not a bug fix: Store Syria cannot discover which Lynomia user the merchant signs in as, so either the operator captures that during approval, or the merchant signs into their pre-existing workspace directly and Store Syria never mints SSO for a claimed account. I did not pick one unilaterally.

**2. Provisioning does not resume after a transient failure, despite a comment saying it does.** `AccountProvisioner::provision()` returns early when a row exists, and a failed attempt leaves a pending row behind. A merchant whose first attempt hits a timeout therefore gets that same stale row back on every retry and can never connect without an operator clearing it. Resuming properly means recording the account id the moment it is created so a retry does not orphan a second workspace — a slightly larger change than I was willing to make unreviewed at the end of this phase. Recorded rather than quietly rewritten.

---

## 8. Files and commits

Three commits, pushed to `claude/store-syria-lynomia-integration-1oa1tt`:

| Commit | Scope |
|---|---|
| `f7826014` | `fix(entitlement): key the activated-module memo to the owner, not to the process` — 2 files |
| `f2d16cab` | `feat(lynomia): phase 1 — module foundation, account mapping, verified linking, one-way SSO` — 31 files, +2,712 / −1 |
| `302ae6bc` | `fix(lynomia): enforce the permission the module ships, and close three gaps found in review` — 9 files, +370 / −39 |

**29 module files, 2,054 PHP LOC; 557 LOC of tests.** Core files touched outside the module: exactly two — `app/Providers/PackageServiceProvider.php` (one line plus its rationale) and `app/Helper/helper.php` (the memo fix). `tests/Unit/Packages/AddonMigrationRegistrationTest.php` moved its module count 69 → 70, which is the update that tripwire's own failure message sanctions for a genuinely added module; its scanner pattern, which the message forbids relaxing, was not touched.

### Seven routes registered

```
lynomia.overview            GET  /lynomia
lynomia.connect.provision   POST /lynomia/connect/provision
lynomia.connect.link        POST /lynomia/connect/link
lynomia.open                GET  /lynomia/open
lynomia.admin.index         GET  /super-admin/lynomia-integration
lynomia.admin.links.approve POST /super-admin/lynomia-integration/links/{link}/approve
lynomia.admin.links.revoke  POST /super-admin/lynomia-integration/links/{link}/revoke
```

Four layers guard every merchant route — `web` (session + CSRF) / `auth` / `xss` / `setlocate` / `verified`, then `PlanModuleCheck:LynomiaChat`, then `EnsureStoreOwnsLink`, and no route parameter at all, so there is no IDOR surface. `web` is listed explicitly because `loadRoutesFrom()` applies no middleware group: measured on the booted router before it was added, the stack had **no StartSession and no ValidateCsrfToken**, which would have left every POST above unprotected. That is the same class of defect the retired Chatwoot integration shipped with, and it was caught here by asserting on the router rather than reading the file.

---

## 9. What was deliberately not built

No inbox, customers, channels, campaigns, flows, reports, quotas, commerce events, or fifth commerce provider. **No placeholder route, controller, model or view exists for any of them** — the merchant page names them as later phases rather than rendering empty shells. Nothing in this phase connects a channel, creates a production credential, or sends a message.

---

## 10. Blockers and open items

### Blocking — must be resolved before this phase can be activated

**1. The APP_KEY / `.env` exposure is still unresolved.** `.env` was tracked in git history; `APP_KEY` is in it and has not been rotated. This phase was built so that it does **not** depend on that key for confidentiality — the platform token lives in the environment and is never written to the database — but it remains true that:

- Every value Laravel currently holds under an `encrypted` cast in this installation is readable by anyone with the repository history. That includes the retired integration's `chatwoot_integrations.api_access_token`, which is why the retirement commit recorded that it must be **re-issued, not migrated**.
- Session cookies and signed URLs are forgeable until the key is rotated.

`docs/security/SECRET-ROTATION-CHECKLIST.md` (commit `29f8e928`) is the checklist. Nothing on it was executed, per your instruction.

**2. No Lynomia `PlatformApp` token exists yet for Store Syria.** One must be created on the Lynomia side and placed in the Store Syria environment. It should be a **new, dedicated** PlatformApp, not a shared one — its permissible set is the entire boundary that keeps Store Syria out of accounts it did not create. This is a production-credential act and was deliberately not performed.

**3. `php artisan package:seed LynomiaChat` must run before the module is enabled for anyone.** This is new, and it is a consequence of the permission gate added in `302ae6bc`. The merchant routes now require `Manage Lynomia Chat`, and nothing grants a merchant owner that permission except the module's `PermissionTableSeeder`, which attaches it to the single global `admin` role every owner carries. If the seeder has not run, the gate does **not** fail open — it fails **closed**, and every merchant owner gets a 403 on a feature their plan includes. A test asserts the seeder grants it and that `package:seed` can resolve the class, but no test can assert that you ran the command.

### Non-blocking — recorded, not acted on

**3. The 13 pre-existing Feature failures remain.** Two clusters: eight are the `no such table: owner_menu_settings` family (the same undeclared-table issue as GATE F), five are tenancy census tests whose pinned counts had already drifted before this branch. Out of scope per your instruction; recorded rather than silently re-baselined, because adjusting a census to match the code is how a census stops being evidence.

**4. `config/tenancy.php` sets `null_tenant => 'open'`.** `StoreScope` therefore adds no predicate when the tenant is unresolved. `LynomiaStoreLink` deliberately does **not** use `BelongsToStore` for that reason, and scopes explicitly by `store_id` **and** `owner_user_id` instead. The global setting is a pre-existing platform-wide issue and is out of this phase's scope, but it is the reason the model looks less idiomatic than its neighbours.

**5. The two functional gaps in §7b** — a claim-linked workspace cannot be opened, and provisioning does not resume after a transient failure. The first needs a decision from you; the second needs a change I judged too large to make unreviewed at the end of the phase.

**6. The module's migration has not been run against production.** It creates one new table and touches nothing existing. It is additive and reversible.

---

## 11. Rollback

This phase is reversible without data loss:

1. `git revert 302ae6bc f2d16cab f7826014` — removes the module, the static-load line and the memo fix.
2. `php artisan migrate:rollback` for the single Lynomia migration, if it was ever run — drops `lynomia_store_links`, a table nothing else references.

No existing table was altered. No existing row was written. No provider account was connected or disconnected. The historical `chatwoot_integrations` and `chatwoot_notification_templates` tables are still present and untouched, so the retirement is independently reversible too.

---

## 12. Recommended next phase

My recommendation for Phase 2 is **the merchant-facing inbox surface and the channel-exclusivity rail**, in that order, for one reason: channel exclusivity is the rule you marked FINAL, and it is the one rule that cannot be retrofitted cheaply. Like the two unique indexes in this phase, it wants to be a database constraint before any code depends on the behaviour — once channels exist without it, every later feature becomes a place that has to re-check it.

I would also fold in the quota rail at the same time, because hard quotas with no automatic overage are an *admission* decision, and the cheapest correct place for an admission decision is the same entry point that already resolves entitlement — `PlanModuleCheck` — rather than a check repeated across the jobs and writes downstream of it.

**Not recommended for Phase 2:** anything that needs a live channel. Until the APP_KEY rotation is done, connecting a real WhatsApp or Instagram account means putting a provider credential into an installation whose encryption key is public.

---

## 13. Status

Phase 1 is **complete, reviewed, tested and pushed** to `claude/store-syria-lynomia-integration-1oa1tt`. It is **not merged and not deployed**, and Phase 2 has not been started. Both await your approval.

The two exploitable defects the review found were in code I had written and described as well-defended in the same breath. They were found because the phase was reviewed adversarially rather than declared finished, and the four regression tests added with the fixes each failed first against the code as shipped. I would not treat the module as safe to enable until the §10 blockers are closed — in particular the seeder run, which is the one that turns a security fix into a platform-wide lockout if it is skipped.
