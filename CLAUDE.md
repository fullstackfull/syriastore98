# Store Syria — project instructions

Multi-tenant Laravel e-commerce SaaS. Laravel 11.47 on PHP 8.3, built on the vendored **WorkDo
eCommerceGo** addon platform (70 modules under `packages/workdo/`). A second repository,
`StoreSyria_app98`, is the shipped **admin** Flutter app. **Resuming or new account? Read `docs/development/HANDOFF-CURRENT.md` §13 first — Phase 01 engineering is CLOSED.**

The full analysis lives in `docs/`. This file is the short version of what will bite you, and it
records only things verified by reading or executing the code.

---

## 1. Architecture facts that are counter-intuitive

Each of these has produced a real defect. Do not assume the framework default.

**Authorization**

- **Laratrust, not spatie.** `permissions_as_gates => false`, so **`@can('Some Permission')` is
  always false**. Use `$user->isAbleTo('Permission Name')`.
- **There is no super-admin bypass.** Operator status is a *string comparison* on `users.type`
  (`'super admin'` / `'admin'`). `isAbleTo()` alone **refuses the platform operator**, so an
  authorization check must test the operator first:
  ```php
  $isOperator = strtolower(trim((string) $user->type)) === 'super admin';
  if (! $user || (! $isOperator && ! $user->isAbleTo('Manage Order'))) { /* refuse */ }
  ```
- `User::creatorId()` returns `$this->id` for type `admin`/`super admin`, else `created_by`. A
  user whose type is `admin` is the root of their own tenant.

**Tenancy**

- Shared database, `store_id` discriminator. `config/tenancy.php` allowlists models in
  `scoped_models` — **not every model is scoped**. `Customer` is; `Store`, `DeliveryBoy` and
  `Order` are not.
- **`null_tenant => 'open'`.** When no tenant resolves, the global scope **adds no predicate**. So
  a handler that relies on the scope for isolation is unprotected for any principal that doesn't
  resolve a tenant. Never let the scope be your only tenant check.
- A tenant predicate built from **caller-supplied input** (`$request->store_id`, a query-string
  `store_id`) is not a tenant check — it only compares the caller's own two values. This has
  produced four separate defects.

**Authentication**

- Three *session* guards: `web`, `customers`, `deliveryboy`. There is **no `api` or `sanctum`
  guard**.
- `app/Http/Middleware/CustomAuth.php` (`custom.auth`) resolves a Sanctum token and logs its owner
  into whichever guard matches the **tokenable class**: `Customer`→`customers`, `User`→`web`,
  `DeliveryBoy`→`deliveryboy`. **A merchant's or driver's token passes `custom.auth` while
  `auth('customers')` stays null.** Any handler shaped
  `if (! auth('customers')->user()) { $id = $request->some_id; }` is therefore reachable by a
  non-customer and takes the attacker-controlled branch. This was the single most productive
  pattern in the codebase: five handlers in S16, then **twenty-six more** in S19. Always derive the
  target from the authenticated principal; never from request input. The storefront's shared
  predicate is `ApiController::resolveStoreCustomer()` — reuse it rather than copying the check.
- **A `-guest` route suffix does not mean the route is public.** `place-order-guest`,
  `cart-check-guest`, `product-detail-guest` and the rest all carry `custom.auth`, which refuses a
  missing bearer token before the handler runs. The suffix means "the handler has an
  `auth('customers')` fallback" — and that fallback was the defect.
- **A customer-token probe cannot test the trap.** A `Customer` token takes the *safe* branch, so a
  test that only drives one proves nothing. Drive a merchant and a driver token too.
- **Sanctum tokens never expire** (no `config/sanctum.php`) and every one carries `['*']`.
  `CheckAbilities` is imported in `routes/api.php` and never used. There is **no refresh path for
  any client**, and `Api\AuthController::login` is **unrouted** — customers have no login endpoint.
  See `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` before touching token lifetime.
- `ApiResponser::error()` returns **HTTP 200** with `{status: 0, ...}` by default. 401 must be
  passed explicitly — and the shipped Flutter app **forces a logout on any 401**, so a new 401 on a
  path a client calls is a user-visible regression.

**Routing**

- `routes/api.php` lines 133–387 sit inside `Route::prefix('admin')`, so those paths are
  `api/admin/<x>`. The admin app's base URL is `https://storesyria.com/api/admin`. Routes outside
  that group are the **storefront** API (`api/{slug}/...`), which no client in either repository
  calls.
- Later route declarations **replace** earlier ones for the same method+URI, silently dropping the
  earlier middleware. Check for duplicates before trusting a route's middleware.
- `APP_KEY` is in git history and unrotated, so **`Crypt::encrypt()` is an encoding, not a
  control**. An encrypted id in a URL is forgeable.

**Tests and modules**

- **No WorkDo module loads in any test run** unless the test inserts an `add_on_managers` row. The
  whole suite is therefore structurally blind to module routes and module code: a test that
  "passes" against a module surface may never have reached it. Enable the module through the
  production loader and add a premise test that asserts it really is active.

**Model caching**

- **79 of the 95 models in `app/Models/` are `Cachable`** — assume cached; the 16 exceptions are
  `AuditLog`, `CurrencyRate`, `DeliveryBoy{Device,Notification}`, `DeliveryEarning`,
  `OtpIntegration`, `Permission`, `Role`, `SavedView`, `Store{Device,Notification,Wallet,
  WalletTransaction,Withdrawal}`, `Zid{Event,Store}`. A raw write (`DB::table('products')->update()`)
  leaves Eloquent reads, **route-model binding included**, serving the pre-write row, and under
  `CACHE_STORE=array` it lives for the rest of the test: **three measured false readings**, the last
  a test that passed against a live bite. Write through Eloquent (it flushes) or
  `(new User)->flushCache()`; `$model->timestamps = false` keeps an explicit past `updated_at`.- Several handlers read the platform admin via `Cache::remember('admin_details', 3600, ...)`, so
  `Cache::forget('admin_details')` between a fixture and a request or it sees the pre-fixture `User`.

**Scoped models — check before asserting absence**

**`getGlobalScopes()` is not the same question as "is it scoped".** Two checks, both needed:

| Model | `StoreScope` registered | In `scoped_models` | Actually filters |
|---|---|---|---|
| `Customer` | yes (+ SoftDeletingScope) | yes | **yes** |
| `Cart` | yes | yes | **yes** |
| `Product` | yes | **no** | **NO** |
| `Order`, `Wishlist`, `DeliveryAddress`, `User`, `ActivityLog`, `Testimonial` | no | no | no |

`StoreScope::isActiveFor()` short-circuits for a model that is not in `config/tenancy.php`'s
`scoped_models`, so **`Product` adds no predicate for any principal** — not a customer, not a
merchant, not the operator, not an admin-context request. `Product::find($id)` reaches every
tenant's catalogue, and the open-tenant rule does not even come into it. That produced a live
cross-tenant stock drain (S19) that the `customer_id` sweep missed entirely, because it was looking
at the wrong identifier.

The two that *do* filter matter for **assertions**: a plain `Customer::find()` or `Cart::find()` in
a test answers against whichever tenant happens to be resolved, not against the table, so an "it is
gone" assertion passes whether or not the fix works. That cost a real false reading — a cart test
reported a store-B row deleted while the raw table still held it. Assert through
`withoutGlobalScopes()`.

**Schema**

- **A `$fillable` column may be missing — but the migrations are not the schema.** This file said
  `delivery_boys` lacks both commission columns so two handlers 500 for everyone; the restored
  production dump refutes it. Check `Schema::hasColumn()`, and a dump before the migrations.
- `customers.password_otp` is `INTEGER` but the web reset writes a 60-char bcrypt hash (P01-SEC-13,
  migration prepared and **not applied**).
- **GATE F:** 15 tables live code reads and writes have **no migration**, 6 money-bearing; drafts
  parked in `database/migrations/_gate_f_pending/`. **All 15 exist in production with live data** —
  *unmanaged*, not absent. Read `docs/GATE-F-VERIFIED-SCHEMA.md` first.

---

## 2. Phase 02 — streamlined working rules (owner instruction, 2026-10-25)

**Three standing principles**

1. Preserve every approved change; inventory existing work and do not blindly merge experimental or redundant branches.
2. Preserve Flutter and Lynomia Chat compatibility, including their API contracts, and existing WorkDo functionality.
3. Green CI is necessary evidence, not proof that a production release is ready.

**Autonomous execution:** Phase 02 Merchant Experience design and isolated engineering are authorised. Review existing Discovery/design work, implement small coherent slices, run relevant tests, perform independent review on risky changes, and commit/push to a dedicated Phase 02 branch **without repeated owner check-ins**. Prefer targeted UI/contract tests per slice; run the complete required CI gates at integration milestones and final review. Do not manufacture green results or weaken checks.

**Only escalate genuine decisions:** ask the owner for production deployment, merges to `main`, persistent migrations or destructive live-data operations, secret/credential rotation, material changes to prices/payments/stock/business rules or public API compatibility, vendor licence issues, or departures from the six-phase approved scope. Existing approved decisions need no repeated approval.

**Safety boundaries:** Keep tenant isolation, permissions, payments and personal data protected. Do not rewrite history or force-push. Never commit secrets, dumps, or real customer data. Do not edit vendored `packages/workdo/` (except first-party `LynomiaChat`) as a shortcut. Coordinate security incident P1-SEC-40 (#120) independently; UI work does not remediate the database dump exposure. No production access, migration or deployment is implied by this Phase 02 authorisation.

**Scope precedence:** The owner-approved six-phase Master Product Plan / Decision Register v1.0 (2026-10-14) defines the product scope. Phase 02 is Merchant Experience for both merchant web and Flutter; Phase 03 is Storefront, Phase 04 Commerce, Phase 05 Marketing & Analytics, Phase 06 SaaS & Super Admin. Discovery and `docs/design-system/` supply evidence and reusable foundations; older differently numbered roadmaps do not override the approved plan.

---

## 3. Tests

```bash
php artisan test --testsuite=Unit        # 339 tests (2026-10-21; counts drift)
php artisan test --testsuite=Feature     # ~3670 tests, ~9 min
php artisan test --testsuite=Contract    # 26 tests — the Flutter API contract
/tenant-check                            # tenancy + security suites (see .claude/commands)
```

**The Feature suite is not green and is not meant to be.** It has **2 accepted failures and 17
accepted skips**, declared by name in `.github/known-test-outcomes.json`. The gate asserts the
outcome *set* in both directions — an undeclared failure *and* an unexpectedly-passing declared
failure both fail it:

```bash
php artisan test --testsuite=Feature \
  --log-junit storage/logs/feature-junit.xml \
  --log-teamcity storage/logs/feature-teamcity.txt
php .github/scripts/assert-known-test-outcomes.php \
  storage/logs/feature-junit.xml .github/known-test-outcomes.json \
  storage/logs/feature-teamcity.txt
```

Tests run on **SQLite `:memory:`** with `CACHE_STORE=array` (`phpunit.xml`). Production is MySQL
with `CACHE_STORE=database`. **SQLite's type affinity hides real MySQL defects** — that is how
P01-SEC-13 survived. For anything schema-sensitive, use a real MySQL:
`tests/Support/Security/bin/verify-sec13-migration-on-mysql.php` shows the pattern (it creates and
drops its own throwaway database).

Evidence that cannot live in the suite goes in `tests/Support/Security/bin/` as an executable
script that states its own preconditions — a conditionally-skipped test would break the gate's
declared skip set.

---

## 4. The six phases — owner-approved product plan

| Phase | Scope | State |
|---|---|---|
| 01 Foundation | security, CI, regression gates, design-system foundation | engineering complete (2026-10-24), **NOT release-ready or accepted** |
| 02 Merchant Experience | merchant dashboard, product/order UX, preferences, search, notifications, onboarding, web and Flutter parity | **owner authorised isolated engineering (2026-10-25); not authorised to merge/release** |
| 02.5 Premium Merchant UI/UX | a separately approved FUTURE polish stage over Phase 02's surfaces | **not started — must not be merged into Phase 02** |
| 03 Storefront | themes, visual editor, shopper experience, checkout UX, SEO, **plus a separately scoped Official Website and a bilingual Documentation Center** | not started |
| 04 Commerce | products, multi-warehouse stock, pricing, fulfilment, COD, returns, channels | not started |
| 05 Marketing & Analytics | campaigns, loyalty, reports and trustworthy analytics | not started |
| 06 SaaS & Super Admin | SaaS subscriptions, support, controls, operations, platform admin, **plus premium Super Admin UX** | not started |

Phase 02 engineering may proceed on its own branch; this does **not** waive Phase 01 acceptance criteria or authorise release to production.

**Phase 02 keeps its original Merchant Experience scope.** Phase 2.5, 03 and 06 are separate and
must not be started during it, nor absorbed into it — see *Scope boundaries* in the ledger. Phase 02
is not complete until every master-prompt requirement has a verified status or an authorised
exception; `PHASE-02-COVERAGE-AUDIT-2026-10-25.md` traces which do and which do not. Preserve and separately track the outstanding release gates in `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md` and the security incident #120.

---

## 5. Where to look

| | |
|---|---|
| open security findings | `docs/security/P01-SECURITY-WORK-QUEUE.md` |
| the next work package | `docs/development/phase-1/10-NEXT-WORK-PACKAGES.md` |
| token lifetime | `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` |
| the OTP-length decision | `docs/security/P01-OTP-LENGTH-COMPATIBILITY.md` |
| design system | `docs/design-system/README.md` |
| schema gaps | **`docs/GATE-F-VERIFIED-SCHEMA.md`** (measured), `docs/GATE-F-SCHEMA-EXPORT.md`, `_gate_f_pending/README.md` |
| phase plan and evidence | `docs/development/phase-1/` |
| **what Phase 02 was actually asked for** | **`docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md`** — the owner's master execution prompt, verbatim and not to be edited. Workstreams A–K, slices P2-00…P2-08, and the completion criteria. Later clarifications are appended to it in their own dated section; status lives in `PHASE-02-TASK-LEDGER.md`, never there. |
| **which master-prompt requirements are tracked** | **`docs/development/phase-2/PHASE-02-COVERAGE-AUDIT-2026-10-25.md`** — the A–K requirement-to-row trace. 50 tracked, 23 partial, **31 missing**; the missing ones became ledger rows in the same change |
| Phase 02 status, evidence, parity, handoff | `docs/development/phase-2/` |
| the Phase 02 acceptance report | `docs/development/phase-2/PHASE-02-ACCEPTANCE-REPORT-AR.md` (Arabic, for the owner) |
| the Phase 0 audit | `docs/discovery/` |

---

## 6. Skills and tooling

### What the repository owns, and therefore what you actually have

**Committed, so it works in every environment:**

- **`.claude/skills/store-syria-laravel-tenancy/`** — the counter-intuitive facts of this codebase
  (Laratrust vs spatie, the operator-first ordering, which models are really scoped, which request
  key a handler reads, the four ways a test passes for the wrong reason, how censuses fail). Read
  it before adding a controller, policy, middleware or query scope, and before reviewing anything
  that touches authorization or tenancy.
- **`.claude/agents/tenant-authz-reviewer.md`** — the read-only reviewer. Use it before committing
  any change to authorization, tenancy, a route's middleware, an order, a payment, or a handler
  that reads an id from request input. Three of the last four packages had a defect found only by
  this review, two of them *in the fix itself*.
- **`.claude/commands/tenant-check.md`** — `/tenant-check`.

**Vendored from `superpowers` (MIT), owner-approved as a three-skill subset. Committed, so these
work everywhere too** — pinned by content hash with every modification disclosed in
`.claude/skills/PROVENANCE.md`:

- **`test-driven-development`** — failing test first. **This project is stricter:** after the fix,
  revert it and confirm the test fails again. The skill's rule catches a test that never ran; ours
  catches a test that passes for the wrong reason, which is the one that has actually bitten here.
- **`systematic-debugging`** — root cause before fix, one hypothesis at a time, stop and reconsider
  the architecture after three failed fixes. Its `root-cause-tracing.md` was localised on vendoring
  to name the pollution this codebase really produces: a process-global Composer `ClassLoader` after
  `loadAddons()`, `Cachable` models under `CACHE_STORE=array`, and memoised container bindings.
- **`verification-before-completion`** — evidence before claims. The same rule as "claim only what
  you executed" below, and worth having twice.

Twelve of upstream's fifteen skills were read and rejected, most for introducing a second plan tree
or progress ledger beside the Phase 01 ledger, two for running `git merge`/`git push`. **There is
no vendored planning skill and that is deliberate** — upstream's planning skills are inseparable
from their own ledger. Use the ledger and the Issues.

**Design skills, committed on the owner's instruction (Phase 02), so these work everywhere too:**
`frontend-design` (hash-pinned in `skills-lock.json`), `ui-ux-pro-max`, `design`, `design-system`,
`ui-styling`, `brand`, `banner-design`, `slides`. `frontend-design` for aesthetic direction,
`ui-ux-pro-max` for patterns, typography and accessibility. They **advise** the Store Syria Design
System (`public/css/store-syria-ds.css`, `docs/design-system/`), which stays authoritative.

**Unlike the vendored subset they are not egress-free** — they reference external documentation,
font and API hosts, one of them an AI image API behind a credential. The host set is ratcheted by
`ClaudeConfigurationTest::test_the_design_skills_declare_every_external_host_they_reference`: a new
host fails CI so somebody reads it. Phase 02 has called none of them.

**Still absent, so do not write against them:** `laravel-best-practices`,
`claude-automation-recommender`. This section has now been wrong in both directions — #66 found it
instructing three skills that did not exist, and the fix then went stale by calling `ui-ux-pro-max`
and `frontend-design` absent after they were committed. It is a claim about the filesystem, so a
test asserts it rather than prose being trusted.

The full evaluation of candidate additions — twelve tools, each read from its real upstream for
license, install mechanics, hooks, egress and credentials — is in `docs/tooling/SKILLS-MANIFEST.md`,
with routing rules and nine hard prohibitions in `docs/tooling/SKILL-USAGE-POLICY.md`. **One is
adopted** (the superpowers subset above). Every other candidate is rejected or deferred, and all of
them are now settled by an owner decision recorded in the manifest — so do not re-ask, and do not
install anything else without a new one.

Two standing conditions worth knowing without opening the policy: **`context7` is permitted for
public framework documentation only** — a query may name a package and version, never this
repository's code, schema, config or customer data; and **`gstack`, `claude-mem` and `strix` must
not be installed**, by owner decision.

The storefront and admin are **Blade + Tailwind**, bilingual **Arabic (RTL) and English (LTR)**.
Any UI change must be checked in both directions, and contrast-checked — Claude B's verification
found option names rendering at 1.00:1 white-on-white in dark mode.

For security-sensitive changes, use the **`tenant-authz-reviewer`** subagent (`.claude/agents/`).
