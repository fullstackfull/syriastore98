# P1-INV-14 — six anonymous admin mutations in two vendored modules

**Issue:** #57 · **Guard:** `app/Http/Middleware/ModuleOrderMutationGuard.php` ·
**Suite:** `tests/Feature/Security/ModuleOrderMutationGuardTest.php` (23 tests) ·
**Script:** `tests/Support/Security/bin/verify-module-order-mutation-route-cache.php`

## What was found, and why the issue as filed understated it

The task reached this defect from P1-INV-02's caller trace, which led to **one** route. A census of
both modules' route files — every `POST`, printed with the middleware group enclosing it — found
**six** admin-shaped mutations declared in `['web']`-only groups: no `auth`, no `PlanModuleCheck`,
no permission check anywhere in the chain.

| route | handler | request key | writes |
|---|---|---|---|
| `lottery-order-status-change/{id}` | `App\Models\Order::order_status_change` | `id` | `delivered_status`, `payment_status='Unpaid'` → `OrderObserver` → `OrderFinancialService::syncFromOrder` |
| `lottery-order-return` | `App\Models\Order::product_return` | `order_id` | product / variant stock |
| `auction-order-status-change/{id}` | `AuctionProductOrder::order_status_change` | `id` | `delivered_status` |
| `auction-order-return` | `AuctionProductOrder::product_return` | `order_id` | stock |
| `auction-order-assign` | `AuctionProductOrder::find` → `save` | `order_id` | `delivery_id` |
| `update-auction-status` | `AuctionProductSetting::findOrFail` → `save` | `auctionId` | `is_active` |

Declared at `packages/workdo/LotteryPrizes/src/routes/web.php:56,59` (group at `:32`) and
`packages/workdo/AuctionProduct/src/routes/web.php:28,31,32,33` (group at `:24`).

**The two lottery routes are the worst because they drive the HOST `App\Models\Order`.** The
`cancel` limb writes `payment_status = 'Unpaid'`, and `OrderObserver::updated` fires
`OrderFinancialService::syncFromOrder` on exactly that column changing — so an anonymous caller
could cancel any store's order *and* walk back its finance record. The storefront POSTs in the same
groups (`{storeSlug}/product-bid`, the ticket-payment callbacks, `{storeSlug}/lottery-form-data`)
are shopper-facing and public by design; only the admin surfaces are guarded.

**Five of the six are live. `auction-order-return` is LATENT, and that correction came from the
adversarial review after this package's first green suite.** Its handler iterates `product_json` as
a list of order lines, but the column's only writer on an auction order
(`packages/workdo/AuctionProduct/src/app/Console/Auction.php:97`) stores a single product
*object* — `json_encode($item)` where `$item` is a `Product` model, confirmed by the reader at
`AuctionProductOrder.php:34-48`. So `foreach ($product_json as $key => $product)` yields scalars,
`$product['product_id']` is an offset read on a scalar, and `HandleExceptions::handleError` turns
the warning into an `ErrorException`: a 500 before any write, for every principal and every
genuine auction order. Guarding an anonymous entry point to a broken handler is still right;
describing it as a live hole would not be. Raised as **#61**.

## Why a `web`-group middleware

`packages/workdo/` is vendored and `.github/scripts/guard-no-workdo-changes.sh` forbids editing it.
A host re-declaration of the same uri does not work either: module route files are required from
`$this->app->booted()` (`app/Providers/PackageServiceProvider.php:35`), i.e. **after**
`routes/web.php`, and `RouteCollection::addToCollections` keys on method+domain+uri, so the later
(module) declaration wins the slot. The same vehicle and the same reasoning as
`OrderDocumentAccessGuard` — see `P01-SEC-2-ROUTE-CACHE-VERIFICATION.md`.

## The load-bearing detail: the guard reads the key the handler reads

`Request::__get` reads `all()` before the route parameters, so `$request->id` in a handler resolves
to the **posted body** when both are present. A guard that authorised the route segment would wave
through a request that mutates a different row. That is not hypothetical: **P1-ORD-04 shipped
exactly that bug for one commit** — it pinned the id for the authorization and left two later sinks
reading the posted value. So each target's key is taken from its handler's own source line, and
`resolveId()` reads the body first with the route parameter only as a fallback.

### And the first version of `resolveId()` had the hole anyway

It required `is_numeric()` in **both** loops, so a body value that was *present but not numeric*
was skipped by the body loop and the route-parameter loop then authorised the route segment:

    POST /lottery-order-status-change/<own order>    body: id=5abc&delivered=cancel
      guard   : input('id') = '5abc' is not numeric -> route param 42 -> authorises the OWN order
      handler : $request->id = '5abc' -> Order::find('5abc')
      MySQL   : coerces '5abc' to 5 and returns ANOTHER STORE'S order, which is then cancelled

Found by the adversarial review, **after this package's first green suite of 23 tests**. Measured
on MySQL 8.0.46 by
`tests/Support/Security/bin/verify-module-order-mutation-id-coercion-on-mysql.php`:

| posted value | MySQL resolves to | old predicate authorised | now |
|---|---|---|---|
| `'5abc'` | **row 5 (store 2)** | 42 (own) | DENY |
| `'5 OR 1=1'` | **row 5 (store 2)** | 42 (own) | DENY |
| `'5e0'` | row 5 | 5 | DENY |
| `'5.9'` | no row | 5 | DENY |
| `'0x5'` / `'abc'` | no row | 42 (own) | DENY |
| `'5'` / `' 5'` | row 5 | 5 | 5 |

**SQLite hides it completely** — under INTEGER affinity `'5abc'` is not converted, the row does not
match, and the attack silently no-ops. No test in the Feature suite could have found it and none
can prove it fixed; the same engine-hiding that let P01-SEC-13 survive. The in-suite test
(`test_a_present_but_unusable_id_denies_rather_than_falling_back`) therefore asserts the **status**,
which is 403 only with the fix, and says so in its own docblock.

The rule now: **presence in the input hands a key the decision**, and `filter_var(FILTER_VALIDATE_INT)`
decides whether it is usable. `is_numeric` was too loose in the other direction as well — it accepts
`'5.9'` and `' 5'`, which the guard and MySQL normalise differently — so the strict filter closes
that divergence too. An *absent* key still falls back to the route parameter, which four of the six
routes depend on.

## The permission limb fires only when the permission row exists (and this section was wrong first)

**What this document originally claimed, and the review refuted:** that the four auction routes
could carry no permission limb because `Manage Auction Product Order` and `Manage Auction Product`
are "not seeded", `Utility::addNewData()` being the only mechanism that creates `Permission` rows.

That premise is false, and the refutation was **measured**:

- `packages/workdo/AuctionProduct/src/database/seeders/PermissionTableSeeder.php:19-51` creates all
  ten auction permissions and grants every one to the global `admin` role — the role every
  self-registered merchant holds.
- `AuctionProductDatabaseSeeder.php:19` calls it; `app/Console/Commands/PackageSeed.php:75-83`
  resolves that seeder; and `ModuleController` runs `Artisan::call('package:seed ' . $name)` at
  `:131`, `:151`, `:162` and `:182` — the module **enable** path.
- Executed against a throwaway database: **0 rows before, 2 rows after**, and the merchant `admin`
  role holds both.

(The seeder's namespace casing differs from its directory's, which looks fatal and is not: PHP
class names are case-insensitive, so either spelling resolves once the file is included. Checked,
because it would have changed the answer.)

**What the false premise cost.** The guard shipped its first commit leaving a real within-tenant
gap: any authenticated principal whose `current_store` matched the row — including staff on a
hand-built role that deliberately *lacks* `Manage Auction Product Order` — could cancel, confirm
and return auction orders, reassign the driver and toggle auction settings. The lesson is about the
evidence, not the permission: **a scan of one file answers a question about that file, never about
the application.** The test that "verified" the premise read only `app/Models/Utility.php`, so it
was green while the condition it existed to detect was already true.

**The limb is now existence-conditional, uniformly across all six targets.** The `permissions` row
is checked first; only then is `isAbleTo()` required.

| route | permission required |
|---|---|
| the two lottery routes | `Manage Order` (host-seeded, `Utility.php:2755`, granted at `:2918`) |
| the three auction order routes | `Manage Auction Product Order` — what `AuctionOrdersController:34` itself checks |
| `update-auction-status` | `Edit Auction Setting` — this guard's choice from the seeder's list, since `AuctionProductSettingController` checks nothing anywhere |

**Why conditional rather than a hard gate.** A permission with no row can be held by no role, so a
hard gate on a missing name is a permanent 403 for every principal including the platform
operator — the lockout `App\Http\Middleware\FinancePermission` records having been caused once
already by six unseeded finance names. A missing row is a real state here: an install whose module
was enabled before the seeder existed, or whose seeding failed, has the routes and not the
permissions.

**It cannot be turned into a bypass**, and that is asserted in both directions by
`test_with_no_permission_row_the_limb_degrades_to_ownership_and_not_to_open`: deleting a
`permissions` row needs platform access the attacker would already have to hold, and with the row
gone the **ownership** limb still refuses every other tenant's row. The degraded state is
"authenticated and same-store", never "open".

`Manage Lottery Order` is also seeded by the lottery module, and is deliberately **not** required:
it governs that module's own listing screen, while these two routes write the host `orders` table,
and requiring it would break this guard's stated equivalence with `OrderPolicy`.

Whether a merchant's staff *should* hold a money-adjacent auction power remains a privilege
decision for the owner. What the guard settles is that the question is asked at all.

## Evidence

### 1. The suite was structurally blind

`test_without_the_modules_none_of_the_six_routes_exist` measures it: with no `add_on_managers` row,
none of the six uris is in the route table. All 2880 existing testcases ran against a route table
that did not contain them. Both modules are enabled in these tests through the **production
loader** (`AddOnManager` row, then `PackageServiceProvider::loadAddons`).

### 2. Removal checks — 12 of 14 controls isolate

Each control was deleted alone, the suite re-run, and the file restored byte-for-byte. Re-run in
full after the review's fixes, against the 29-test suite.

| # | control removed | result |
|---|---|---|
| R1 | the `bootstrap/app.php` registration | **20 of 29 fail** |
| R2 | the anonymous limb | 7 fail |
| R3 | the store comparison | 8 fail |
| R4 | the permission limb | 2 fail (host-order + auction) |
| R5 | operator-first ordering | 1 fail |
| R6 | body-first id resolution | 5 fail |
| R6b | the present-key-decides rule | 1 fail |
| R7 | the fail-closed `try`/`catch` | 1 fail |
| R8 | the uniform refusal body | 1 fail |
| R9 | the inertness early return | 1 fail — **only after the test was fixed** |
| R11 | the `auth('web')` principal lookup | 1 fail |
| R13 | the permission-row existence check | 1 fail |
| R10 | the missing-row refusal | **GREEN — subsumed, not covered** |
| R12 | `getCurrentStore($user->id)` | **GREEN here — covered by a source ratchet** |

**R9 is recorded because it caught a weak test.** The first version of
`test_the_guard_is_inert_on_a_non_target_route` drove only `/order-status-change/{id}` — the same
table, the same permission, the same store, a numeric id in the same key — so a *non-inert* guard
allowed it for the same reasons the real one ignores it, and the suite stayed green with the control
deleted. An id-less route is what a non-inert guard cannot help refusing; the test now also drives
`GET /dashboard`, and R9 bites.

**R10 is subsumed, and says so rather than claiming coverage.** `empty($row->store_id)` is a
language construct that suppresses the null-property warning and short-circuits before the `(int)`
cast, so a null row already denies through the store comparison. (Without that suppression it would
be an `ErrorException`, because `HandleExceptions::handleError` throws on any warning inside
`error_reporting()` — a 500 rather than a refusal.) The explicit check is kept as defence in depth:
it names the case in the log, and it does not depend on `empty()`'s semantics surviving a rewrite of
that condition. Writing a test for it would be writing a test for `empty()`.

**R12 is unreachable from a behavioural test, and is pinned by a source assertion instead.**
Passing the user id to `getCurrentStore()` only matters when the *default* guard holds a different
principal from `auth('web')` — and in that case the guard has already denied at its null check,
before that line. So the control is defensive against a future reordering of the very limbs that
make it unreachable. It is asserted in `OwnedPathRawQueryInventoryTest` alongside the sibling
guard's, and that assertion was **verified to bite**: removing the argument reddens it.

### 3. The guard survives `route:cache`, and refuses

    DB_CONNECTION=sqlite DB_DATABASE=/tmp/p1inv14.sqlite \
        php tests/Support/Security/bin/verify-module-order-mutation-route-cache.php

`tests/Support` is not one of the three directories `phpunit.xml` collects, so this never runs as
part of a suite and cannot disturb `.github/known-test-outcomes.json`. It could not be a PHPUnit
test anyway: the claim is about what a **separate** process does with the cache file an earlier
process wrote. It never touches a real database — `DB_CONNECTION`/`DB_DATABASE` come from the
environment, Laravel's Dotenv is immutable, and step 1 aborts before any write if the override did
not take. `APP_KEY` is generated random per run; the repository's own key is never read. Step 6
clears the route cache and asserts the file is gone.

**Result: ALL CHECKS PASSED (exit 0).** The cache file names neither `ModuleOrderMutationGuard` nor
the two pre-existing group guards — each route's declared middleware is just `web`, and the group is
resolved per request from `bootstrap/app.php`. A probe process that booted **from** the cache
(`$app->routesAreCached() === true`) resolved this stack on all six:

    [EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession,
     ValidateCsrfToken, SubstituteBindings, SetLocate, BulkDeleteTenantGuard,
     OrderDocumentAccessGuard, ModuleOrderMutationGuard, InjectDebugbar, ThemeLanguage]

and none of the six carries an `Authenticate` of its own. The same process then dispatched a real
anonymous `POST` to each uri through the HTTP kernel: **403 on all six.** CSRF is replaced with a
pass-through inside that probe process only, which makes the check *stricter* — `ValidateCsrfToken`
sits ahead of the guard and would otherwise answer 419 and mask whatever the guard did, so a 403 can
have come from nothing else.

### 4. The script bites, and shows the live exposure end-to-end

With only the `bootstrap/app.php` registration removed, the same script against a fresh throwaway
database reports:

    POST lottery-order-status-change/{id}  -> 500  ErrorException: Attempt to read property
                                                   "lottery_code" on null
    POST lottery-order-return              -> 200  no throwable surfaced
    POST auction-order-status-change/{id}  -> QueryException: no such table: auction_product_orders
    POST auction-order-return              -> QueryException: no such table: auction_product_orders
    POST auction-order-assign              -> QueryException: no such table: auction_product_orders
    POST update-auction-status             -> QueryException: no such table: auction_product_settings

**`lottery-order-return` answered 200 to an anonymous caller** — the handler ran to completion. The
other five reached their vendored model and died on a table the throwaway schema does not hold,
which is itself proof the handler was reached. On a production install those tables exist.

### 5. What the adversarial review found after the first green suite

The suite was green at 23/23 and the gates had passed when the review ran. It found six things, and
**two of them were defects in this package's own work**:

| # | finding | outcome |
|---|---|---|
| 1 | `resolveId()` fell through to the route parameter on a present-but-non-numeric body id — **a live cross-tenant write** | **fixed here**, measured on MySQL (above) |
| 2 | `auction-order-status-change` reads the HOST `orders` table with the auction id and POSTs it, PII included, to a merchant-configured webhook URL | raised as **#62** — not closable by this guard; colliding id spaces |
| 3 | the "auction permissions are not seeded" premise was **false** — the module seeds them | **fixed here**; the permission limb now exists, conditionally |
| 4 | the census ratchet cannot see `Route::resource`; three (then four) live cross-tenant writes sit behind those routes | raised as **#63**; the ratchet now covers both modules' resource routes |
| 5 | `auction-order-return` is latent, not live — `product_json` is a single object | **corrected here and on #61** |
| 6 | `order_assign` writes an unvalidated `delivery_id`; nothing reads it back | hardening; the anonymous half is already pinned |

Plus six test defects, all addressed: the permission test that could not fail (3 above), two
vacuous row assertions whose comments now say the *status* is the control, no coverage of a
`customers`/`deliveryboy` principal, no coverage of the query-string id source, and a
policy-equivalence ratchet that read any non-403 as "allow" and so would have silently inverted on
a 419.

The suite is now **29 tests, 290 assertions**. The review's own "clean" findings are worth
recording because they are the categories that did *not* need work: the `customers` and
`deliveryboy` guards, Sanctum tokens via `custom.auth` (which appears only in `routes/api.php`, and
the `api` group has no `StartSession`), duplicate or `Route::any` declarations of the six uris
(each is declared exactly once), uri keying under `route:cache`, `_method` spoofing, `deny()`'s
inability to leak or to fail to deny, second-row writes on the two **host-order** routes (P1-INV-02
pinned those to `$Order->store_id`), and `getCurrentStore($user->id)` as a tenant predicate (every
writer of `users.current_store` is ownership-checked).

### 6. Three CI ratchets this change tripped, and why each is a correction rather than a concession

The full Feature run came back **5 failures against a manifest declaring 2**. All three extras were
ratchets doing their job:

1. **`OwnedPathRawQueryInventoryTest`** — the guard's `DB::table($target['table'])` is a new raw
   query entry point in an owned path. Classified `'comparison'`, the same category
   `OrderDocumentAccessGuard` already uses: the read deliberately carries no store predicate
   *because it exists to fetch `store_id` and compare it*. Its four controls (the comparison, both
   fail-closed limbs, and the explicit `getCurrentStore($user->id)`) are now asserted there, pinned
   on the comparison rather than on whole statements — for the reason that file records at its own
   expense: a ratchet that reddens on a safe refactor teaches people to edit the ratchet.
2. **`RawQueryTenancyInventoryTest`** — the platform-wide count, `app` 112 → 113 and the total
   157 → 158. It is a fifth *variable-table* site, so the per-table breakdown is correctly
   unchanged. The test method was renamed to match its number.
3. **`ResidualTenancyExposureTest::test_the_addon_models_in_the_map_cannot_be_measured_in_this_install`**
   — **test pollution this package caused.** It asserted `assertCount(23, $unresolvable)`, and
   enabling LotteryPrizes and AuctionProduct registers their PSR-4 prefixes on the **Composer
   ClassLoader singleton**, which is process-global and survives every app teardown. Their two
   models then resolve and the count drops to 21. It passed in isolation and failed in the full
   run. Fixed by replacing the count with a claim that holds in both orders: every recorded class
   is either unresolvable, or its module's prefix is registered in this process — which is the only
   way it could have become resolvable. (The first attempt asked
   `$this->app->getLoadedProviders()` and was wrong: the Application is rebuilt per test, so the
   provider is gone while the autoloader entry is not.) `OrderDocumentRouteResolutionTest` had been
   doing the same thing for `AutomaticOrderPrinting` all along without tripping it, only because
   that module contributes no model to the map.

## What is NOT asserted

- **The finance half.** `OrderFinancialService::syncFromOrder` reads `store_order_financials`, a
  GATE F table created by no migration
  (`database/migrations/_gate_f_pending/0004_draft_create_store_order_financials_table.php.draft`),
  so in any test database the query throws and the observer's own `try`/`catch` swallows it. The
  money consequence is real in production and invisible here; the tests pin the **order** columns,
  which are the trigger for it. P1-MIG-01 (#38) is what would make the finance half testable.
- **Any second row a vendored handler picks out of the first one's data.** The guard authorises the
  row the request *names*. `AuctionProductOrder::product_return`'s unscoped `Product` restock
  (#61) and `auction-order-status-change`'s host-`orders` webhook read (#62) are both beyond it,
  and widening the guard is the wrong fix for each — the first needs a control over `Product`
  writes, the second would false-deny on every id collision between two independent sequences.
- **The `Route::resource` mutations on the same two modules** (#63), including
  `AuctionProductController::update`/`destroy`, which reach the host `Product` by bare id. The
  census ratchet now *records* them; it does not guard them.
- **Whether either module is enabled on the live server.** That is the same server-side question as
  #42: `SELECT module, is_enable FROM add_on_managers WHERE module IN ('LotteryPrizes',
  'AuctionProduct');`. It does not change whether the guard should exist —
  `loadDynamicAddons()` does `AddOnManager::pluck('module')` with **no `is_enable` predicate**, so a
  row with `is_enable = 0` still registers the routes. "Disabled" is not containment.
