---
title: "Store Syria — Phase 0 Executive Summary"
phase: "Phase 0 — Discovery & Audit"
date: "2026-10-11"
repo_a: "Main Platform — github.com/fullstackfull/syriastore98, branch claude/pensive-cerf-h6rizn, HEAD efe05d9f"
repo_b: "Associated App — github.com/fullstackfull/StoreSyria_app98, branch claude/pensive-cerf-h6rizn, HEAD d745ec08"
evidence_basis: >
  All findings are CODE-LEVEL, read from the two checkouts named above. Sixteen specialist dossiers
  were produced and the 26 highest-stakes claims were re-tested by independent skeptics; where a
  skeptic corrected a claim, the corrected wording is used here. BLOCKED, with no result claimed:
  Repo A has no vendor/ or node_modules/, so the test suite, php artisan and static analysis could
  not run (php -l across all 3,113 PHP files did run and reported zero syntax errors); no Flutter/Dart
  SDK exists here, so flutter analyze/test/build could not run on Repo B; no database access, so the
  live production schema can be neither confirmed nor denied; and outbound HTTPS to storesyria.com was
  refused by the sandbox egress proxy, so the deployed server was never probed. Statements about the
  deployed host are inference from committed artefacts and are labelled as such.
---

# Executive Summary

## What Store Syria is today

Store Syria is a customised copy of the commercial WorkDo **"eCommerceGo SaaS"** product, v8.8.2 (`repo-A config/verification.php:4-5`), on a current Laravel 11 / PHP 8.2 base, plus a separate Flutter **merchant admin app**. It is a genuine multi-tenant SaaS store-builder: merchants self-register and get their own store, plans with enforced quotas exist, and there is a super-admin panel, a merchant panel and three storefront themes. On top of the purchased product your team wrote a Syria-specific layer — a Syrian card rail (Paymera), a Syrian courier rail (Delivery Syria), a merchant wallet and withdrawal ledger, currency rates, and custom-domain provisioning. Repo B is definitively the merchant console, not a shopper app: its tabs are Home, Orders, Products, Categories, More (`repo-B lib/core/controller/dashboard_manager_controller/dashboard_manager_controller.dart:15-19`) and there is no cart or checkout anywhere in `repo-B lib/view/screen/`. The two repos are one system: all 83 endpoint paths the app calls resolve to real routes in Repo A, against `https://storesyria.com/api/admin` (`repo-B lib/utils/base_api.dart:3`).
## Current project condition

The breadth is real and the Syrian localisation already exists. The problem is that the localisation is the **weakest** code in the repository, and the purchased product's security assumptions have been broken rather than reinforced. Three conditions shape everything below.

**No engineering process**: no `.github/` and therefore no CI, no `docs/`, no deploy script, and the whole `tests/` tree is 10 files of unmodified Laravel Breeze scaffolding — zero business-logic tests across the application and all 69 addon modules. **Not a reproducible source of truth**: 15 tables that live code reads and writes have no migration, so the real schema exists only on the live server. **Git history is a snapshot, not a history**: six commits — one squashed import plus five "adjustments on server" — with a `.env` carrying live credentials committed in the import. The custom rails are also pointed at non-production endpoints, so payment and fulfilment are blocked by configuration rather than by sanctions, which is cheap to fix.
## The 10 most important findings

| # | Finding | Severity | Status | Evidence |
|---|---|---|---|---|
| 1 | **API authentication does not work.** The middleware guarding 189 of the 211 API route registrations in `routes/api.php` looks a Sanctum token up by its row **id** and never compares the token secret; with no `Authorization` header it passes the request through unauthenticated. `Bearer <id>\|anything` acts as that token's owner, up to super admin. The correct check exists 20 lines away in the same codebase, so this is a defect, not a design. | CRITICAL / P0 | VERIFIED | `repo-A app/Http/Middleware/CustomAuth.php:32-34, :54`; correct version at `app/Http/Controllers/Api/DashboardController.php:620-633` |
| 2 | **Seven admin API endpoints need no credentials and take the tenant from the query string.** Their only middleware is a pass-through logger, so `GET /api/admin/orderlist?store_id=N` returns any merchant's orders and customer names to an anonymous caller; iterating `store_id` walks the whole platform. No rate limiting exists. | CRITICAL / P0 | VERIFIED | `repo-A routes/api.php:163-169`, group at `:133` (no middleware); `app/Http/Middleware/AdminApiLog.php:19-22` |
| 3 | **Platform takeover by any logged-in user.** `POST /store-reset-password-update/{id}` sets any user's password — including the super admin's — with no permission, ownership or type check, on a raw integer id. Public self-registration means an anonymous visitor can create an account and reach it. | CRITICAL / P0 | VERIFIED | `repo-A app/Http/Controllers/StoreController.php:646-667`; route `routes/web.php:663`, inside the auth-only group at `:326` |
| 4 | **The payment path never verifies that payment happened.** Of ~52 wired payment types only 3 verify the gateway's answer, none verifies the amount, and none verifies any inbound signature. The shared return handler sets `payment_status = 'Paid'` on the straight-line path, so a cancelled Stripe checkout or a declined Paymera transaction still creates a paid order. Separately, `tax_price` is taken straight from the HTTP request into the amount charged. | CRITICAL / P0 | VERIFIED | `repo-A app/Http/Controllers/PaymentController.php:1354`, `:764`; unread marker written at `app/Services/PaymentService.php:365-366` |
| 5 | **Order and refund mutation endpoints sit outside the authentication group.** Lines 940–973 of the web routes are inside `web` but outside `auth` (brace-depth verified: depth 2 at `:326`, depth 1 after `:938`). An anonymous caller who first fetches one public page for a session and CSRF token can mark any order of any store Paid, Delivered or Refunded, and inflate stock. | CRITICAL / P0 | VERIFIED | `repo-A routes/web.php:950, :957-961`; handler `app/Http/Controllers/OrderController.php:556-564` — no auth, no store filter |
| 6 | **The repository cannot rebuild its own database.** 15 tables that routed code queries have no `Schema::create` anywhere — six of them money-bearing (`store_wallets`, `store_withdrawals`, `store_order_financials`, `currency_rates`, `delivery_earnings`, `store_wallet_transactions`) — and `Customer` applies `SoftDeletes` while `deleted_at` appears in zero migrations repo-wide. Migrations run clean; the application then fails against the schema they produce. | CRITICAL / P0 | VERIFIED | `repo-A app/Models/StoreWallet.php:9`, `app/Services/OrderFinancialService.php:28`, `app/Models/Customer.php:16,20`; zero matches for `deleted_at`/`softDeletes` across all 105 + 102 migrations |
| 7 | **Two anonymous-to-remote-code-execution paths.** `POST /theme-addon` and `POST /install-modules` accept a zip from any logged-in user with no authorization check and extract it into `themes/` and `packages/workdo/` — directories under the web-served application root. Sibling methods in the same controllers *do* check permissions, so these are omissions. | CRITICAL / P0 | VERIFIED | `repo-A app/Http/Controllers/AddonController.php:65`, `app/Http/Controllers/ModuleController.php:251`; routes `routes/web.php:787, :913` |
| 8 | **Tenant isolation has no enforcement layer, and the role table is a tenant-escape primitive.** Zero global scopes, no `app/Policies`, zero `authorize()` calls — isolation rests on **487** hand-written `where('store_id', getCurrentStore())` filter sites (**1,260** counting every form of the `where('store_id', …)` clause), spread across the **119 of 188** model files that carry `store_id` (**57 of 95** in `app/Models/` alone). Where one is missing it breaks: `orderComplete` is an unauthenticated cross-tenant order lookup that also hands back the capability token for the full order page, and `RoleController::update` lets an ordinary merchant rewrite the global `super admin` role and overwrite `users.type` for its holders. | CRITICAL / P0 | VERIFIED | `repo-A app/Http/Controllers/OrderController.php:360-362, :373` (route `routes/customer.php:144`); `app/Http/Controllers/RoleController.php:156, :182-186` (route `routes/web.php:335`) |
| 9 | **Both Syrian rails point at non-production hosts.** The card gateway targets a test host; both Delivery Syria calls post to an ephemeral ngrok free tunnel; and the courier is told `cash_collection => '0'`, so it is never told how much cash to collect on delivery. | CRITICAL / P0 | VERIFIED | `repo-A app/Services/PaymentService.php:216, :2805, :2756`; `app/Http/Controllers/DeliverySyriaController.php:54` |
| 10 | **The mobile app cannot be shipped as it stands.** The Android release build is signed with the **debug** keystore, and the login request body — e-mail and plaintext password — plus every API response body including the bearer token are written to the device log, with **zero** `kReleaseMode`/`kDebugMode` guards anywhere in `lib/`. | CRITICAL / P0 | VERIFIED | `repo-B android/app/build.gradle.kts:38-41`; `lib/core/controller/auth/auth_controller.dart:152`; `lib/network/network.dart:125-158` |

Every count in this summary was measured in this checkout; the exact commands that reproduce the tenancy-filter, model-file and API-route figures are published beside those figures in `02-SYSTEM-ARCHITECTURE.md` §6 and `04-DATABASE-AND-MULTITENANCY.md` §3.
## Top risks, ranked

The single biggest risk is **risk 1**: the platform is exploitable by anonymous internet users today, and because there is no audit trail you cannot establish whether it already has been.

| # | Risk | Why it matters | Evidence |
|---|---|---|---|
| 1 | **Live, anonymously exploitable platform with no forensic trail** | Findings 1, 2, 3, 5 and 8 each give an anonymous or self-registered actor other merchants' orders, customer PII and money state. No CI, no tests, no audit-log table and debug mode on means a breach would leave no evidence. Treat every credential in the committed `.env` and in `config/services.php:31, :33-34` as compromised. | `repo-A .env:4` (`APP_DEBUG=true`); `audit_logs` has no migration |
| 2 | **If the production database is lost, the product is lost** | The real schema exists only on the live server, and the sole backup module has no restore function at all. | finding 6 |
| 3 | **Taking real money is unsafe** | Payment is booked successful without verification, totals are partly client-supplied, refunds never call any gateway, COD is booked as collected at checkout, and checkout runs with no DB transactions or row locking. | findings 4, 5 |
| 4 | **The repos are technically coupled and organisationally uncoupled** | No API contract of any kind — no OpenAPI, no collection covering the custom endpoints, no shared types, no version in the path, no contract test — so a server-side rename silently bricks the shipped app. | `repo-A routes/api.php`; `repo-B lib/utils/base_api.dart` |
| 5 | **Licensing is unresolved** | No WorkDo/Envato licence file exists in Repo A, so resale terms cannot be read from code; Repo B uses commercial Syncfusion charting on two live screens with no licence evidence. | absence verified repo-wide |
| 6 | **Arabic is incomplete in a way translation will not fix** | `resources/lang/ar/` has no `validation.php`, `auth.php`, `passwords.php` or `pagination.php` while `resources/lang/he/` has all four, so server-side validation messages fall back to English; and no Arabic-capable webfont exists in the repo. | `repo-A resources/lang/ar/` vs `resources/lang/he/` |
| 7 | **Deployment layout leaks** | The application root, not `public/`, is web-served and the only access rule in the repo is a single-filename deny for `.env`. Live confirmation was BLOCKED. Established impact is disclosure of `composer.lock`, internal paths and 407 customer uploads — reconnaissance, not takeover. | `repo-A index.php:17-21`, `.htaccess:25`, `error_log:1` (inference) |
## Main recommendations

**Reuse, do not rewrite.** The purchased product is current, the addon modules are untouched and still upgradeable, and all 69 ship Blade views — a framework migration would invalidate the paid addon ecosystem and the theme system at once. Repair the custom layer and restore the boundaries the product assumed, in this order:

1. **Recover the schema into version control.** Dump production, write migrations for the 15 missing tables and columns, prove a from-source rebuild works. Everything else depends on this.
2. **Replace `CustomAuth` with `auth:sanctum`, add rate limiting, stop taking identity from the request body.** This closes most of findings 1, 2 and 8.
3. **Close the unauthenticated surfaces.** Move routes 940–973 inside the auth group; add permission and ownership checks to the payout, password-reset, role, module-install and theme-install endpoints; add an enforced tenant scope plus Policies instead of hand-written filters.
4. **Rebuild the money path.** Recompute totals server-side at order creation, verify every gateway return server-side, wrap order creation in a transaction with row locking, stop hardcoding `payment_status = 'Paid'`.
5. **Rotate every secret**, move the document root to `public/`, remove secrets from `config/services.php`.
6. **Establish the basics**: CI, staging, business-logic tests starting with checkout and tenancy, and one API contract shared by both repos.
7. **Finish the Syrian rails**: production Paymera credentials and verification, a production Delivery Syria endpoint, a correct COD cash-collection amount.
8. **Make Repo B shippable**: real release keystore, strip logging in release builds, enable Crashlytics, wire `flutter_localizations` for RTL.

Two inputs sit outside engineering: `05-SECURITY-AND-QUALITY-AUDIT.md` §8 sets out compliance and regulatory exposure — PCI-DSS scope, data-protection regime, breach-notification duty — as engineering-identified exposure **requiring qualified legal advice and not itself legal advice**; and `09-DEVELOPMENT-ROADMAP.md` §16 (team shape, effort aggregation and how to turn this into a budget) carries team-shape and effort-aggregation guidance for converting the relative phase points into a budget.
## Verdict 1 — Beginning development: **CONDITIONAL GO**

Start work. The asset is real: a current Laravel base, a licensed and upgradeable addon ecosystem, a working multi-tenant SaaS model with enforced quotas, a proven API contract between the repos, a Syria-viable billing rail (offline bank transfer with receipt approval), and Syrian payment and courier integrations that are written and merely misconfigured. Nothing found forces a rewrite; the defects are concentrated in identifiable files, and the codebase already contains correct implementations of several things it does wrong, so the fixes are known rather than speculative.

The qualifier is part of the verdict: this is **CONDITIONAL GO**, not GO, and the wording of both verdicts in this document is the canonical form — the conditions below are binding, not advisory.

Conditions: (a) all work on **staging with a copied, scrubbed database**, never production; (b) **schema recovery is the first Phase 1 deliverable**; (c) **nobody runs the test suite from a checkout carrying the committed `.env`** — `repo-A phpunit.xml:25-26` has the SQLite override commented out and no `.env.testing` exists, so the 8 `RefreshDatabase` test classes would run `migrate:fresh` against the live database; (d) licensing answered before any resale commitment.
## Verdict 2 — Production launch: **NO-GO**

A different decision, and it comes out differently. Launching now would put a platform with unauthenticated cross-tenant access to merchants' orders and customer PII, two anonymous-to-RCE paths, an unverified payment path and no audit trail in front of real merchants and real shoppers' money. Findings 1–8 are each independently launch-blocking; finding 6 means that if anything goes wrong you cannot rebuild; finding 9 means that even after the security work, card payment and courier fulfilment would not function on production configuration today.

A prior question has to be answered before this verdict can be acted on: whether a live deployment with real merchants and real data exists today is **UNKNOWN** (owner item 6 below), so `11-RELEASE-READINESS.md` §6 — “Day-one containment: should the platform be taken offline?” — is the decision that comes *before* the launch decision.

The verdicts differ because **beginning development is reversible and launching is not**. Development is contained in staging, where a defect costs time; a launch exposes third parties' money and personal data, and a breach cannot be withdrawn. The gap is a defined remediation programme, not a final polish. Revisit when recommendations 1–5 are complete and test-verified and the Syrian rails run against production endpoints.
## Project maturity — an honest assessment

This is best described as **a working production deployment with the engineering discipline of a prototype**. That combination is the heart of the problem: the system plainly runs and serves traffic, which creates a reasonable but false impression that it is finished.

Evidence for "working": a current framework (Laravel 11.47), 69 addon modules, 105 migrations, 95 models, a 211-route API, three themes, a merchant app whose 83 endpoints all resolve against the backend, and zero PHP syntax errors across 3,113 files.

Evidence for "prototype discipline", which is what the judgement rests on: no CI, no documentation, no deployment process, a stock Laravel README, zero business-logic tests, six commits with messages like "adjustments on server", Repo B commit messages reading "new", "ي" and "ب", secrets committed to git, 15 tables created by hand outside version control, 1,028-line controller methods, 2 FormRequest validation classes against 1,330 controller methods, no `app/Jobs` at all, and 88.3% of the repository (52,107 of 58,982 tracked files, 535 MB) being one bundled module that is WorkDo's *digital business card* product, unrelated to stores.

The most telling indicator is the pattern behind the missing migrations: code was edited directly on the production server and committed back afterwards. That is a working-practice problem, not a code-quality one, and it is what Phase 1 must change first — because every technical fix here is undone by the next direct edit on the server.
## What we need from the owner before Phase 1

| # | What we need | Why |
|---|---|---|
| 1 | **Read access to the production database**, or a dump | The hardest blocker. The schema cannot be recovered without it, and recommendation 1 gates everything else. |
| 2 | **Host / cPanel access** | To answer two questions the repository cannot: does `/home/<redacted-account>/public_html/.git` exist (if yes, the committed secrets are web-reachable and severity rises sharply), and can the document root move to `public/`? |
| 3 | **WorkDo / Envato licence documentation**, and a Syncfusion decision for Repo B | Resale terms cannot be read from the code; no licence file exists in either repo. |
| 4 | **Production Paymera credentials and commercial agreement**, plus the production Delivery Syria API base URL and contract | Both Syrian rails currently point at a test host and an ephemeral tunnel (finding 9). |
| 5 | **Authorisation to rotate every secret** in the committed `.env` and `config/services.php`, and a window to do it | They are in git history and must be treated as compromised. |
| 6 | **Confirmation of live exposure** | Are there live merchants, real orders and real money on `storesyria.com` today; is the Flutter app published and how many installs; have any customer-data or payment incidents been reported? This sets the urgency of every security item. |
| 7 | **Access to the original developers** — the Syrian custom-layer author and "tala" in Repo B — or confirmation they are unavailable | Changes Phase 1 sizing materially. |
| 8 | **Budget decision on staging and CI** | The cheapest items in this report and the precondition for everything else. |
| 9 | **A scope decision** | Is Store Syria sold as multi-tenant SaaS to third-party merchants — making tenant isolation non-negotiable and P0 — or operated as your own stores? This changes the roadmap. |
