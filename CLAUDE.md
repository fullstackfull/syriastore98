# Store Syria — project instructions

Multi-tenant Laravel e-commerce SaaS. Laravel 11.47 on PHP 8.3, built on the vendored **WorkDo
eCommerceGo** addon platform (70 modules under `packages/workdo/`). A second repository,
`StoreSyria_app98`, is the shipped **admin** Flutter app.

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

- **`User` is `Cachable`** (`GeneaLabs\LaravelModelCaching\Traits\Cachable`, `app/Models/User.php:16`).
  A raw-query write — `DB::table('users')->update(...)` — leaves Eloquent reads serving the
  **pre-write** row, and with `CACHE_STORE=array` that stale entry lives for the rest of the test.
  This has produced two measured false readings: a fixture that looked like it had not applied, and
  a security test that passed against unfixed code because the handler's own `User::find()` also
  read stale. Write through Eloquent (which flushes), or call `(new User)->flushCache()`.
- Several handlers read the platform admin via `Cache::remember('admin_details', 3600, ...)`.
  `Cache::forget('admin_details')` between a fixture and a request, or the request sees a `User`
  captured before the fixture existed.

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

- Some `$fillable` columns **do not exist**. `delivery_boys` lacks `commission_percentage` and
  `is_commission_enabled` though both are fillable and two handlers write them — so those handlers
  500 for everyone. Check `Schema::hasColumn()` before believing a model.
- `customers.password_otp` is `INTEGER` but the web reset writes a 60-char bcrypt hash (P01-SEC-13,
  migration prepared and **not applied**).
- **GATE F:** 15 tables that live code reads and writes have **no migration**, 6 of them
  money-bearing. Drafts are held unrunnable in `database/migrations/_gate_f_pending/`.

---

## 2. Development rules

- **Branch:** `claude/store-syria-p01a-ci-foundation`. Commit and push there.
- **Never merge into `main`. Never deploy. Never run migrations against the development server.
  Never modify live data.**
- **Never edit `packages/workdo/`.** Vendored commercial code: an in-place edit is silently
  reverted by the next upstream update and leaves no patch record. A fix that seems to need a
  module file belongs in the host app — a Policy, FormRequest, Gate, query scope or route
  middleware. **`LynomiaChat` is the one exemption** (first-party, written here). Enforced by a
  PreToolUse hook locally and by `.github/scripts/guard-no-workdo-changes.sh` in CI, which is the
  authority.
- **Do not weaken a CI guard or delete a test to get green.** `.github/known-test-outcomes.json`
  declares the accepted failures; adding an entry to obtain green is explicitly forbidden by its
  own README.
- **Preserve first.** This is a remediation project on a live business. Prefer the narrowest change
  that closes the defect, and keep the request/response contract unless security requires otherwise
  — then say so explicitly.
- **A test must bite.** After writing a regression test, revert the fix and confirm the test fails.
  A removal that doesn't isolate the control proves nothing. This has caught vacuous tests
  repeatedly: a test asserting "the row didn't change" passes when the endpoint 500s for everyone.
- **Claim only what you executed.** Say which engine, which command, and what the output was.

---

## 3. Tests

```bash
php artisan test --testsuite=Unit        # 190 tests
php artisan test --testsuite=Feature     # ~2390 tests, ~6 min
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

## 4. The six phases

| Phase | Scope | Status |
|---|---|---|
| **01 Foundation & Stability** | CI, regression gates, security, design-system foundation | **in progress** |
| 02 Merchant Web Experience | adopt the design system screen by screen | not started — needs Phase 01 acceptance |
| 03 Storefront & Theme Builder | storefront and themes | not started |
| 04 Advanced Commerce | advanced commerce, merchant Odoo | not started |
| 05 Marketing & Analytics | marketing, analytics | not started |
| 06 SaaS & Super Admin | SaaS operations, platform Odoo | not started |

Phase 00 (discovery) is complete. Single owner; the roadmap's parallel tracks are sequencing, not
concurrent assignments. **Do not start the next phase before Phase 01 acceptance.**

---

## 5. Where to look

| | |
|---|---|
| open security findings | `docs/security/P01-SECURITY-WORK-QUEUE.md` |
| the next work package | `docs/development/phase-1/10-NEXT-WORK-PACKAGES.md` |
| token lifetime | `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` |
| the OTP-length decision | `docs/security/P01-OTP-LENGTH-COMPATIBILITY.md` |
| design system | `docs/design-system/README.md` |
| schema gaps | `docs/GATE-F-SCHEMA-EXPORT.md`, `database/migrations/_gate_f_pending/README.md` |
| phase plan and evidence | `docs/development/phase-1/` |
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

**Account-level, so it may be absent:**

- **`frontend-design`** — aesthetic direction for UI work. Present in some environments, not all.

### What this section used to claim, and why it was wrong

It named `laravel-best-practices`, `ui-ux-pro-max` and `claude-automation-recommender` and said to
use them "without being asked". **None of the three exists in this environment.** They were
account-level installs on another machine; the only capabilities that survived the move were the
ones committed to `.claude/`. A missing skill is not an error — it is simply never offered — so a
session proceeds silently without it.

So: do not write code or reviews as if those three were available. If you need what they covered,
the project-local skill above holds what is specific to this codebase, and `vendor/` holds the
framework's own truth for the version actually installed.

The full evaluation of candidate additions — twelve tools, each read from its real upstream for
license, install mechanics, hooks, egress and credentials — is in `docs/tooling/SKILLS-MANIFEST.md`,
with routing rules and the hard prohibitions in `docs/tooling/SKILL-USAGE-POLICY.md`. **Nothing from
that list is installed**, and four items there need an owner decision. Do not install any of them
without one.

The storefront and admin are **Blade + Tailwind**, bilingual **Arabic (RTL) and English (LTR)**.
Any UI change must be checked in both directions, and contrast-checked — Claude B's verification
found option names rendering at 1.00:1 white-on-white in dark mode.

For security-sensitive changes, use the **`tenant-authz-reviewer`** subagent (`.claude/agents/`).
