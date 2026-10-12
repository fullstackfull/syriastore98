# Store Syria — Master Stabilization Register

One row per remaining finding, by its original ID. Classifications use exactly six values:
`FIXED AND VERIFIED`, `FIXED BUT NOT FULLY VERIFIED`, `OPEN`,
`BLOCKED -- EXTERNAL DEPENDENCY`, `ACCEPTED RISK -- OWNER DECISION`,
`NOT APPLICABLE -- WITH EVIDENCE`.

Owner: agent5-QA, branch `p1d/agent5-qa`, branched from `p1b/integration` @ `17cb2f91`.
IDs come from `docs/discovery/` (13 files) and `docs/development/phase-1/` (9 files); the
authoritative status table they feed is
`docs/development/phase-1/02-P0-SECURITY-REVALIDATION.md:151-235`. No ID here is invented
except rows prefixed `NEW-`, which are findings first measured in this phase.

## READ THIS BEFORE QUOTING ANY NUMBER IN THIS FILE

**Every test number in this document comes from a `vendor/` tree that does NOT match
`composer.lock`.** Measured here, not assumed: of 239 locked packages, **148 are installed
at a different version**, **6 locked packages are not installed at all**, and **2 installed
packages are absent from the lock**. Four of the drifts cross a major version
(`phpseclib` 3.0.42→4.0.2, `firebase/php-jwt` v6.10.2→v7.2.1, `hamcrest` v2→v3,
`sabberworm/php-css-parser` v8.7→v9.5), and the test runner itself differs
(`phpunit` 11.4.4 locked vs 11.5.57 installed). See `NEW-VENDOR-DRIFT-01`.

**No claim of full test coverage or security readiness is made or implied by this file.**

## Measured baseline on this branch

| Suite | Result | vs. inherited baseline |
|---|---|---|
| Unit | 30 tests, 232 assertions, 0 failures | was 25 / 212 / 0 — +5 tests, no regression |
| Feature | 661 tests, 3366 assertions, **0 errors**, 15 failures, 9 skipped | was 658 / 3353 / 0 / 13 / 9 |
| `tests/Feature/Security` | 231 tests, 1965 assertions, 0 failures, 3 skipped | — |
| `tests/Feature/Tenancy` | 235 tests, 748 assertions, 0 failures, 6 skipped | — |
| `tests/Feature/Commerce` | 151 tests, 545 assertions, 0 failures, 0 skipped | — |

Failure delta is **+2, fully accounted**: both are the new deliberate-red canaries in
`tests/Feature/Platform/NullTenantGuardCanaryTest.php`. All 13 inherited failures are still
present and still red. **No test was skipped, disabled, deleted or weakened.**

## Register

### Verification integrity

| ID | Finding | Classification | Evidence |
|---|---|---|---|
| `NEW-VENDOR-DRIFT-01` | `vendor/` does not match `composer.lock`: 148/239 packages at a different version, 6 locked packages not installed, 2 installed packages not in the lock, 4 major-version crossings, and the test runner differs. Every number this project has reported comes from this tree. | `BLOCKED -- EXTERNAL DEPENDENCY` | Measured by diffing `composer.lock` against `vendor/composer/installed.json`. Cannot be resolved here: `vendor/` is 1.5 GB with 1.7 GB free on `/`, so an isolated lock-consistent install does not fit; the shared `vendor/` must not be touched; and 6 locked packages are unfetchable. |
| `NEW-ADDON-SCHEMA-01` | No test in the suite exercises addon-module schema. The test database holds 95 tables; of the 80 distinct tables declared by addon-module migrations, **1 is present and 79 are absent**. | `OPEN` | Measured with a throwaway probe over `sqlite_master` against `packages/workdo/*/src/database/migrations/*.php`. Cause is two-part, below. |
| `NEW-ADDON-SCHEMA-02` | `LandingPage` is the one module loaded unconditionally (`app/Providers/PackageServiceProvider.php:16`), but its provider registers the **wrong case**: `packages/workdo/LandingPage/src/Providers/LandingPageServiceProvider.php:28` loads `__DIR__.'/../database/Migrations'` while the directory is `src/database/migrations`. On a case-sensitive filesystem that path does not exist, so the three `owner_menu*` migrations register from nowhere. It is the only one of 69 modules using `src/Providers/` and capital `Migrations`. | `OPEN` | file:line above; directory casing confirmed with `ls -d`. `packages/workdo/` is protected by `.github/scripts/guard-no-workdo-changes.sh` and must not be edited — needs an owner decision on the remedy. |
| `NEW-ADDON-SCHEMA-03` | The other 68 modules register migrations correctly but load only when listed in `add_on_managers` (`PackageServiceProvider::loadDynamicAddons()`), which is empty in a fresh test database, so their providers never register. | `OPEN` | `app/Providers/PackageServiceProvider.php:25-42`; 69 `loadMigrationsFrom` occurrences under `packages/`. |

### The 15 Feature failures, by measured root cause

| ID | Finding | Classification | Evidence |
|---|---|---|---|
| `NEW-NULLGUARD-01` | `get_file()` dereferences a null super admin: `app/Helper/helper.php:143-145` reads `->current_store` off `User::where('type','super admin')->first()` with no guard. `get_file()` is called from the shared Blade layouts, so **every page render** 500s when that row is absent. Accounts for 7 of the 15 failures. | `OPEN` (owned by another agent; `app/` is not editable here) | `ErrorException: Attempt to read property "current_store" on null at app/Helper/helper.php:145`, reproduced by a direct call in `tests/Feature/Platform/NullTenantGuardCanaryTest.php`. **Proven fixable**: a temporary local guard turned that canary green, then was reverted (`git status app/` clean). |
| `NEW-NULLGUARD-02` | `Utility::addNewData()` has **two** unguarded role dereferences, not one: `$companyRole` (`app/Models/Utility.php:2899`) used at `:3098`/`:3099`, and `$superadminRole` (`:3103`) used at `:3144`/`:3145`. `addNewData()` runs on the login path, so login itself 500s. | `OPEN` (owned by another agent) | `Error: Call to a member function hasPermission() on null at app/Models/Utility.php:3098`. **A fix limited to `:3098` is incomplete**: with `:3098` guarded locally the error moved to `:3144`. Reverted. |
| `NEW-NULLGUARD-03` | Registration leaves a **half-provisioned merchant**. `RegisteredUserController.php:248-249` does `Role::where('name','admin')->first()` then `$user->addRole($role_r)` with no guard, *after* the user row, the store row and the login have been committed. The merchant ends authenticated with no role and sees a 500. | `OPEN` (owned by another agent) | `TypeError: App\Models\User::addRole(): Argument #1 ($role) ... null given, called in .../RegisteredUserController.php on line 249`; measured `status=500` with `auth=YES`. Covered by `RegistrationTest::test_new_users_can_register`. |
| `NEW-ROUTE-SHADOW-01` | `profile.edit` is declared then silently dropped. `routes/web.php:417` declares `GET /profile` as `profile.edit`; `routes/web.php:865` re-declares `GET profile` as `profile`, replacing it in `RouteCollection`'s method+URI index. `ProfileController@edit` is unreachable dead code, and `ProfileController.php:37` `Redirect::route('profile.edit')` makes **every successful merchant profile save write the record and then 500**. | `OPEN` (`routes/` owned by another agent) | Both declarations cited at file:line; re-measured against the booted router: 1357 routes, 1253 named, 508 distinct declared `->name()` literals, exactly 2 lost. One-line fix: rename one of the two colliding `GET profile` declarations — do not delete either, both actions are referenced. |
| `NEW-ROUTE-SHADOW-02` | `contact.us` likewise lost: `routes/customer.php:57` and `:68` declare the same method and URI `GET {storeSlug?}/contact-us` under different names; `:68`'s `page.contact_us` wins. Latent, not live — confirmed with an **escaped-dot** grep that no code calls `route('contact.us')`. | `OPEN` (`routes/` owned by another agent) | file:line above; `grep -rn "contact\.us"` returns only the one declaration site. |
| `NEW-BREEZE-CONTRACT-01` | Stock Breeze `test_new_users_can_register` asserted a contract this product never had: registration is **store** registration and `store_name` is required. **Corrected, not deleted**, and the rejected-payload case is now asserted deliberately as the contract it is. | `FIXED AND VERIFIED` | `RegisteredUserController::store()` validator requires `store_name`; measured stock payload → `status=302 auth=NO errors=["The store name field is required."]`. New `test_registration_is_rejected_without_a_store_name` passes (5 assertions) and has teeth: supplying `store_name` makes it fail with `Session is missing expected key [errors]`. |
| `NEW-STALE-DOC-01` | `PasswordResetTest`'s docblock named `owner_menu_settings` as the render failures' cause and claimed "no `loadMigrationsFrom` registers any addon module". Both were wrong as written. **Corrected in place with measured evidence**; assertions untouched and still red. | `FIXED AND VERIFIED` | `owner_menu_settings` appears **0 times** in today's failure output (`grep -c`); the proximate cause is `helper.php:145`. All 69 modules do call `loadMigrationsFrom`. The `owner_menu_settings` gap is real but **masked** — it reappears once `helper.php:145` is guarded. |

### GATE F — the six undeclared money tables

| ID | Finding | Classification | Evidence |
|---|---|---|---|
| `SCHEMA-01` | `store_wallets`, `store_wallet_transactions`, `store_withdrawals`, `store_order_financials`, `currency_rates`, `delivery_earnings` are read and written by live code and created by **no migration**. Schema is not recoverable in this environment and must not be guessed: the eventual correct migration is a `Schema::hasTable()` no-op, so a wrong guess becomes permanent and invisible. | `BLOCKED -- EXTERNAL DEPENDENCY` | Re-verified independently: **0** discoverable migrations create any of the six (`database/migrations/*.php` + `packages/workdo/*/src/database/migrations/*.php`); only parked `*.php.draft` files in `database/migrations/_gate_f_pending/`. All six proven live-reachable by `GateFCoverageLedgerTest`. Containment guarded by `tests/Unit/Database/GateFSchemaContainmentTest.php` + `.github/scripts/guard-gate-f-containment.sh` in the **blocking** Unit job. |
| `NEW-GATEF-COVERAGE-01` | **The reported coverage gap is real, but was recorded the wrong way round.** 8 of the 9 skips are GATE F and they name only **three** tables — `store_wallets`, `store_wallet_transactions`, `store_withdrawals`. The three with **no skip at all** are `store_order_financials`, `currency_rates`, `delivery_earnings` (the prior note named the already-covered three). So the gate looks better covered than it is. | `FIXED AND VERIFIED` (gap now explicit and ratcheted; the underlying schema stays `BLOCKED`) | Measured with `--display-skipped`. Per table: `delivery_earnings` — `OrderMutationTest.php:160-173` wraps its financial assertion in `if ($hasEarnings)` and reports no skip (deliberate and argued at `:147`, but invisible); `store_order_financials` — only a comment and an absence-tripwire; `currency_rates` — only a static model-to-table mapping, despite 6 live call sites. Now asserted by `tests/Unit/Database/GateFCoverageLedgerTest.php` (5 tests, 16 assertions), teeth proven twice. |
| `NEW-GATEF-GUARD-01` | The containment guard omitted `WithdrawalAuthorizationTest`, which carries a GATE F skip, so that skip could be removed unnoticed; its docblock counts were stale ("seven of eight skips"). | `FIXED AND VERIFIED` | `tests/Unit/Database/GateFSchemaContainmentTest.php:187` now guards it; counts corrected to eight of nine. `vendor/bin/phpunit tests/Unit/Database/` → 22 tests, 216 assertions, OK. |

### The 9 skips — all legitimate, all reasons re-verified

| ID | Finding | Classification | Evidence |
|---|---|---|---|
| `NEW-SKIP-AUDIT-01` | **The split is 8 + 1, not 6 + 3.** Eight skips are GATE F money tables; the ninth is `DeliverySyriaWebhookTest`, blocked on `zid_orders`/`zid_stores` — a different undeclared-schema family. Every one of the 9 reasons was re-verified and still holds; none is skipping for a reason that has lapsed. | `FIXED AND VERIFIED` (audit complete) | `--display-skipped` full output; re-confirmed **0** discoverable creators for `zid_orders`, `zid_stores`, `store_wallets`, `store_wallet_transactions`, `store_withdrawals`. |
| `NEW-STALE-DOC-02` | `DeliverySyriaWebhookTest`'s docblock still claims `users.deliverysyria_secret` "is not a declared column either (verified -- no match in database/migrations)". That is now false — it **is** declared. The skip itself remains correct, because its `Schema::hasTable()` guard is on `zid_orders`/`zid_stores`, which are still undeclared. | `OPEN` (`tests/Feature/Security/` owned by another agent) | `database/migrations/2026_10_12_000103_add_deliverysyria_secret_to_users_table.php:65` adds the column and is discoverable. Docblock claim at `tests/Feature/Security/DeliverySyriaWebhookTest.php:25-27`. |

### Dependency security

| ID | Finding | Classification | Evidence |
|---|---|---|---|
| `NEW-AUDIT-01` | **70 advisories across 22 packages** re-verified independently: 3 critical, 22 high, 34 medium, 10 low, 1 unscored. Top: `league/commonmark` 14, `guzzlehttp/guzzle` 9, `phpoffice/phpspreadsheet` 9, `dompdf/dompdf` 6, `phpseclib` 5. | `OPEN` | `composer audit --locked` (no install needed). The CI ceiling of 70 is therefore still correct and is left unchanged. |
| `NEW-AUDIT-02` | **The prior "65 of 70 clear with no composer.json change" plan does not hold as stated, and the CI comment predicting "expect 4" is wrong.** A full `composer update --no-install` does reach 4 — but only by resolving `laravel/framework` to **`11.x-dev`** and `firebase/php-jwt` to **`6.x-dev`**, i.e. moving dev branches. That is not a security fix and must not be committed. | `OPEN` | Run in an isolated scratch copy; `vendor/` never touched (verified no vendor dir was created). |
| `NEW-AUDIT-03` | **There is no advisory-free stable Laravel 11.** With `minimum-stability: stable`, resolution **fails outright**: composer refuses every stable `laravel/framework` from `v11.9.0` to `v11.57.0` as advisory-affected. The 4 residual framework advisories are fixed only in `>=12.60.0` / `>=12.61.1` / `>=12.69.0`. A real fix needs a Laravel 12/13 major upgrade, which is product expansion and out of scope this phase. | `BLOCKED -- EXTERNAL DEPENDENCY` | Resolver output quoted in the isolated run: "found laravel/framework[v11.9.0, ..., v11.57.0] but these were not loaded, because they are affected by security advisories". |
| `NEW-AUDIT-04` | A **compatible** update IS available and is prepared but **not applied**: updating 17 transitive packages takes **70 → 24 advisories (5 packages)** with `laravel/framework` held at `v11.47.0`, **zero major-version crossings** and **zero new dev pins**. Not committed because it cannot be verified — the suite cannot be run against it (see `NEW-VENDOR-DRIFT-01`), and an unverified lock change could break the platform. Residual 24: `guzzle` 9, `phpspreadsheet` 9, `laravel/framework` 4, `firebase/php-jwt` 1, `phpunit` 1. | `BLOCKED -- EXTERNAL DEPENDENCY` | Reproducible recipe, isolated copy, `--no-install`: `composer update --no-install -- league/commonmark guzzlehttp/psr7 dompdf/dompdf phpseclib/phpseclib symfony/yaml symfony/mime symfony/routing aws/aws-sdk-php league/flysystem livewire/livewire mtdowling/jmespath.php psy/psysh symfony/http-foundation symfony/http-kernel symfony/mailer symfony/polyfill-intl-idn symfony/process`. Adding `guzzle`, `phpspreadsheet` or `phpunit` to that set either introduces a dev-commit pin (`guzzle 7.10.x-dev`) or collapses the resolution to zero changes. |
| `NEW-AUDIT-05` | `maatwebsite/excel` is pinned at **`3.1.x-dev`** in the committed lock — a moving dev branch in the shipped dependency set, independent of any advisory. | `OPEN` | `composer.lock`; present in the pristine lock before any experiment. |
| `DEP-PHPSPREADSHEET` | The phpspreadsheet file-import concern does **not** apply: `maatwebsite/excel` never calls `IOFactory::load()`; it calls `createReader($type)` then `$reader->load($path)` where the path is a server-generated name, so it is not attacker-influenced. | `NOT APPLICABLE -- WITH EVIDENCE` | Prior refutation, re-stated unchanged; 9 `phpspreadsheet` advisories remain counted in `NEW-AUDIT-01` rather than waved away. |

### Credentials and owner decisions

| ID | Finding | Classification | Evidence |
|---|---|---|---|
| `SEC-ENV-01` | `.env` is committed in history and **still tracked at HEAD**. Reported accurately, never "fixed", never allowlisted away. Rotation deferred by the owner and must not block development. | `ACCEPTED RISK -- OWNER DECISION` | `git ls-files --error-unmatch .env` succeeds. `.gitignore:34-35` now lists `.env` and `.env.*`, which does **not** untrack an already-tracked file. No secret value is printed anywhere in this register. |
| `NEW-CI-01` | Secret-scanning posture is as the owner set it and is left intact: the gitleaks **history** scan reports (`continue-on-error: true`, `ci.yml:400`) because rotation is deferred, while the **diff screen blocks**, so a new secret still fails the build. | `ACCEPTED RISK -- OWNER DECISION` | `.github/workflows/ci.yml:360-405`. |
| `GATE-F` | No deployment, no commercial launch, never merge to `main`. | `ACCEPTED RISK -- OWNER DECISION` | Binding owner instruction; nothing in this branch deploys or merges. |

### Previously confirmed P0/P1 findings with regression coverage green on this branch

These were fixed by other agents on branches merged into `p1b/integration`, which this
branch inherits. The classification reflects **what I personally ran here**, not a review of
their diffs; `tests/Feature/Security`, `tests/Feature/Tenancy` and `tests/Feature/Commerce`
are 0-failure on this branch (617 tests, 3258 assertions, 9 skips between them).

| ID | Finding | Classification | Evidence |
|---|---|---|---|
| `SEC-R1-01` / `R2-02` / `R2-01` | `CustomAuth` authenticated on `personal_access_tokens.id` without comparing the secret, and passed anonymous requests through. | `FIXED BUT NOT FULLY VERIFIED` | `tests/Feature/Security/CustomAuthMiddlewareTest.php` green in a 231-test, 0-failure Security run. Not independently re-derived from the middleware source here. |
| `SEC-F6` | Unrestricted impersonation. Routes are named `login-with-admin` / `login.with.admin`, **not** `impersonate` — a name-based grep wrongly REFUTED this once. | `FIXED BUT NOT FULLY VERIFIED` | `routes/web.php:851-852`; `vendor/bin/phpunit tests/Feature/Security/ImpersonationTest.php` → **OK (7 tests, 15 assertions)**, run here. |
| `SEC-R1-02` / `R2-07` | `ThrottleRequests` absent from the `api` group; `RateLimiter::for('api')` was dead code. | `FIXED BUT NOT FULLY VERIFIED` | `bootstrap/app.php:43` now calls `$middleware->throttleApi('120,1')`; `AuthRateLimiterTest` green. Not load-tested. |
| `R4-01` | Zero global scopes, zero `Scope` classes, no `app/Policies`, zero `authorize()` calls. | `FIXED BUT NOT FULLY VERIFIED` | Re-counted here: 4 files in `app/Policies/`, 1 class implementing `Scope`, 22 `authorize(` call sites in `app/Http/Controllers/`. Coverage of each policy not individually audited. |
| `R4-03` / `R2-05` | `changeStore` / `ChangeStore` wrote `users.current_store` to any store id with no ownership check. | `FIXED BUT NOT FULLY VERIFIED` | `tests/Feature/Security/TenantSwitchTest.php` green in the 0-failure Security run. |
| `R4-04` | Cross-tenant IDOR through implicit route-model binding across 68 bound tenant-owned models. | `OPEN` | Partially covered by the green Security suite, but **no evidence here** that all 68 bound models are ownership-checked. Do not read the green suite as closing this. |
| `R4-02` | `getCurrentStore()` returns `null` for anonymous, customer, delivery-boy, job and command contexts; the scope **fails open** on a null tenant by documented decision in four contexts. | `ACCEPTED RISK -- OWNER DECISION` | `app/Helper/helper.php:357-381`; `tests/Feature/Tenancy/NullTenantContextTest.php` green. |
| `R4-05` | 780 manual `where('store_id', …)` sites pass a foreign store id, 40 `orWhere` sites may escape scoping, 146 `DB::table()` sites bypass Eloquent. | `OPEN` | Not re-measured here. Tenancy enforcement covers **six models only** (`Menu`, `SupportTicket`, `SupportConversion`, `FlashSaleCondition`, `ThemeCustomizeOrder`, `NotifyUser`); the other 11 trait-carrying models are **not** enforced, and `config/tenancy.php` defines no `store_scope_enabled` key — defining it as true would scope all 17 at once, including `Product` and `Customer`. Guarded by a test; do not define it. |
| `R4-06` | No evidence forces abandoning the shared-database model. | `NOT APPLICABLE -- WITH EVIDENCE` | Prior conclusion, unchanged; shared DB with `store_id` discriminator preserved throughout. |
| `SEC-CFG-02` | Hardcoded credential literals in `config/`. | `OPEN` | Narrowed previously to four literals of uneven severity. `config/` is owned by another agent this phase; not re-verified here, and no value is reproduced in this register. |

## Independent verification of the other P1D branches

Read as diffs against `origin/p1b/integration`; their suites were **not** run (the
coordinator does that on the integration tree). Two claims were tested empirically by
copying the agent's own files into this worktree, running, and reverting
(`git status app/` clean afterwards). **No cross-branch file collision exists**: no file is
touched by more than one P1D branch, and none of mine overlaps theirs.

| ID | Finding | Classification | Evidence |
|---|---|---|---|
| `REV-A1-01` | `p1d/agent1-tenancy` holds all three invariants that have broken this project before: `use Illuminate\Database\Eloquent\Scope` (the correct FQCN — `Scopes\Scope` does not exist in Laravel 11 and fatalled 11 models once), `StoreScope::$enabled = false` (`app/Scopes/StoreScope.php:156`), and **`store_scope_enabled` is still not defined** in `config/tenancy.php` despite that file growing 158 lines — it is only read at `StoreScope.php:347`. | `FIXED AND VERIFIED` | `git show origin/p1d/agent1-tenancy:…` for each; `config/tenancy.php:102` defines only `enforcement_enabled`. |
| `REV-A1-02` | Agent1 guards **both** role dereferences in `Utility::addNewData()` (`:3112` and `:3161`), independently finding the `$superadminRole` site that my own first pass at `:3098` alone did not cover. Their 7-of-13 attribution for `helper.php:145` matches my measurement exactly. | `FIXED AND VERIFIED` | Their file:lines; my independent count of 7. |
| `REV-A1-03` | **A claim that outruns its evidence.** Agent1's comment credits `helper.php:145` with 7 of the 13 failures, which is true as *proximate cause* — but their guards clear only **3** (the 2 canaries plus `test_users_can_authenticate_using_the_login_screen`). Running their actual `helper.php` and `Utility.php` in this worktree leaves **10 of 13 still failing**, six of them now on `no such table: owner_menu_settings`. The board will not clear from these guards alone. | `OPEN` (not a defect in their fix, which is correct and necessary — a correction to what it is expected to achieve) | Measured: `tests/Feature/Auth` + `ProfileTest` + canaries with agent1's files → 28 tests, 10 failures; remaining causes `owner_menu_settings` ×30, `Route [profile.edit] not defined` ×4. Reverted. |
| `NEW-NULLGUARD-04` | A **further** unguarded null dereference, unmasked by agent1's correct fix: `app/Helper/helper.php` guards with `if (!empty($user))` and then dereferences a **different** variable, `$user_not_com->id`. `$user_not_com` is `User::find($user->created_by)` via `Cache::remember` and can be null. **Pre-existing, not an agent1 regression** — the same wrong guard variable is at baseline. | `OPEN` | Baseline `origin/p1b/integration:app/Helper/helper.php:1343-1348`; agent1 branch `:1373-1378`. Surfaced as `Attempt to read property … on null in app/Helper/helper.php:1378` once the earlier 500 stops short-circuiting. |
| `REV-A2-01` | `p1d/agent2-security` observes the **403-never-401** rule correctly. `EnsureCustomerAuthenticated` answers `expectsJson()` with **403** (`:56-60`) and redirects otherwise. All 21 added lines mentioning 401 are explanatory comments about why 401 must not be used, not responses. | `FIXED AND VERIFIED` | `git show origin/p1d/agent2-security:app/Http/Middleware/EnsureCustomerAuthenticated.php`; `git diff … | grep '^+.*401'` inspected line by line. |
| `REV-A2-02` | Agent2 fixes `NEW-NULLGUARD-03`: `addRole` is guarded by `if ($role_r)` at `RegisteredUserController.php:178-179`, at both call sites. My `RegistrationTest::test_new_users_can_register` is the integration signal and should go green when this lands. | `FIXED BUT NOT FULLY VERIFIED` | Their file:lines read; not executed here, because their branch also rewrites `routes/customer.php` and `DashboardController` and I did not run their suite. |
| `REV-A2-03` | Agent2 rewrites `routes/customer.php` (+158) and gates the whole customer account area. The brief records that a storefront-breaking control has already shipped twice here (a brace move behind `auth` killed newsletter signup; scoping `Menu` blanked navigation). **I did not verify that legitimate customer workflows still pass** — their suite was not run here and `routes/` is not mine. This must not be merged on the strength of my review. | `OPEN` | Diffstat only. Requires the coordinator to run `tests/Feature/Security/CustomerAccountBoundaryTest.php` plus a storefront smoke path on the integration tree. |
| `REV-A3-01` | `p1d/agent3-commerce` adds 4,669 lines across 18 files including a COD state machine described as **OFF by default** and an `ENFORCED_PAYMENT_TYPES` guard. Behaviour-changing commerce work with its own new tests. **Not verified here at all** — no commerce file is mine and I did not run their suite. | `OPEN` | Diffstat and commit subjects only. Recorded so it is not mistaken for reviewed. |
| `REV-ALL-01` | No P1D branch touches a file any other P1D branch touches, and none touches a file this branch touches. The 10 ownership violations and 7 logical conflicts found last phase have no structural analogue here. This is a **file-level** result only: it does not rule out a semantic conflict between, say, agent1's scope changes and agent2's route gating. | `FIXED AND VERIFIED` | `git diff --name-only` per branch, intersected; `uniq -d` across all three returns empty. |
