# P1-INV-17 — four cross-tenant writes behind AuctionProduct's `Route::resource` routes

**Issue:** #63 · **Guard:** `app/Http/Middleware/ModuleResourceOwnershipGuard.php` ·
**Shared predicate:** `app/Http/Middleware/Concerns/ComparesRowOwnership.php` ·
**Suite:** `tests/Feature/Security/ModuleResourceOwnershipTest.php` (22 tests) ·
**Script:** `tests/Support/Security/bin/verify-module-resource-ownership-on-mysql.php`

## What was found

Found by the adversarial review of P1-INV-14's guard (#57), then widened by reading every
`update()` and `destroy()` body behind the two modules' six `Route::resource` declarations rather
than only the three the review named. **Pre-existing**, not introduced by #57 — but #57's census
ratchet could not have found them, which was the second half of the issue.

Both resource groups carry `auth` + `PlanModuleCheck:AuctionProduct` and **no store predicate
anywhere**.

| route + method | handler | what it does |
|---|---|---|
| `PUT/PATCH auctionproduct/{id}` | `AuctionProductController::update:861` | **host `Product::find($id)`**, bare id |
| `DELETE auctionproduct/{id}` | `AuctionProductController::destroy:1509` | **host `Product`** + its `ProductImage` rows |
| `PUT/PATCH auctionsetting/{id}` | `AuctionProductSettingController::update:115` | **MOVES `store_id`** to the caller's store |
| `DELETE auctionsetting/{id}` | `AuctionProductSettingController::destroy:140` | delete |
| `DELETE auctionorder/{id}` | `AuctionOrdersController::destroy:105` | delete |

**The `auctionproduct` pair is the worst, and it is not an auction table.** Both resolve
`App\Models\Product::find($id)` — the host catalogue model, which carries **no effective global
scope** (absent from `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()`
short-circuits). So this is a cross-tenant catalogue **edit and delete**, taking images and
whatever `products.category_id`'s `ON DELETE CASCADE` takes with it. P1-INV-01 closed the same
defect on the host `ProductController`; this is a separate copy in vendored code it could not
reach.

**The `auctionsetting` update is the subtlest.** It writes `$setting->store_id = getCurrentStore()`
(`:126`), so it does not merely modify another store's row — it *moves* it into the caller's
tenant. The victim's row vanishes from their own `where('store_id', …)` listings and appears in the
attacker's, which is far harder to notice than an overwrite. Refusing the request before the
handler runs is therefore the whole fix; there is no "scope the lookup" half-measure.

**The permissions are no barrier to a merchant.** `Edit Auction Product` and
`Delete Auction Product` are both created by the module's own
`database/seeders/PermissionTableSeeder.php:19-51` and **granted to the global `admin` role**,
which every self-registered merchant holds. They are a barrier only to staff on a narrower role.
`AuctionProductSettingController` and `AuctionOrdersController::destroy` check **no permission at
all** — the guard requires one anyway, because a guard that drops a check the handler makes is a
regression even when the check is weak.

**Not anonymous.** These sit in the module's own `auth` group, so they need a signed-in principal
*and* a plan carrying AuctionProduct (`PlanModuleCheck` resolves `user_active_modules` through
`activated_modules_for_owner`). That is the realistic condition and the precondition the severity
rests on.

## Reproduced by execution, and two ways the first attempt proved less than it looked like

Four of five cases wrote across tenants on the first run against unfixed code: another store's
product **deleted**, another store's auction setting **moved into the caller's store**, and another
store's auction setting and auction order both deleted.

Two corrections were needed first, and each is a way a security test passes for the wrong reason:

1. **No plan entitlement.** `PlanModuleCheck` refuses any non-operator whose `user_active_modules`
   rows do not include the module, so three of five cases "passed" without the request ever
   reaching a handler. The fixture now seeds the row and asserts `ActivatedModule()` contains the
   module before proceeding.
2. **A validator mistaken for a control.** The product-update case posted only `name`, and the
   handler answered `{"flag":"error","msg":"The category id field is required."}`. A validator is
   not an ownership check; all four required fields are attacker-supplied.

### The fifth case cannot be reproduced in the Feature suite at all

With the required fields supplied, `AuctionProductController::update` writes NULL into
`products.trending` and `products.track_stock` from request fields the attacker need not post.
**SQLite refuses the statement; production MySQL has `strict => false`
(`config/database.php:55`) and coerces, so the write lands.** Measured on MySQL 8.0.46 by
`verify-module-resource-ownership-on-mysql.php`:

```
MySQL  strict=''  -> UPDATE accepted, name = 'HIJACKED-BY-AUCTION', store_id still 2,
                     trending and track_stock COERCED to 0
SQLite            -> NOT NULL constraint failed: products.trending, victim's name unchanged
```

**Production is more exposed than the test database** — the same direction and the same class as
P1-INV-01 / S37-1, which CLAUDE.md records. So the in-suite test asserts the **refusal** (a 403,
which only the guard produces) and the script establishes the write.

## The fix, and why a second guard rather than one

A second guard, because these routes differ from #57's six in two ways that cannot be folded in:

- **The id comes from the route parameter only.** Every handler takes `$id` as a *method argument*
  (`update(Request $request, $id)`, `destroy($id)`), so the route parameter is what they act on.
  #57's guard reads the **body** first because its handlers read `$request->id` and
  `Request::__get` reads `all()` before route parameters. Same principle — *read what the handler
  reads* — opposite answer. Reading the body here would be the P1-ORD-04 bug in mirror image: a
  crafted `auctionsetting=<own id>` would authorise the caller's own row while the handler deleted
  another store's. `test_a_posted_body_key_cannot_redirect_the_guard_off_the_route_parameter`
  pins it.
- **One uri, four methods, different permissions.** `Route::resource` puts GET, PUT, PATCH and
  DELETE on the same uri. `TARGETS` is keyed on **method+uri** so each can require its own
  permission and so `show()` — a read this guard does not claim to have analysed — passes through
  untouched.

**The ownership predicate is shared, not copied.** `ComparesRowOwnership` holds the operator check,
the existence-conditional permission check, the fail-closed row read, the store comparison and
strict id parsing. #57's guard shipped with *both* a false "the permission is not seeded" premise
and a `resolveId()` that fell through to the route segment, and both were found by review rather
than by a test — a second copy would have inherited whichever version was current that day. Id
*resolution* stays per-guard, because that is the one question that genuinely differs.

## Evidence

### Removal checks — 11 of 13 isolate, and four tests exist because a check found them missing

| # | control removed | result |
|---|---|---|
| R1 | the `bootstrap/app.php` registration | **10 of 22 fail** |
| R2 | the store comparison (trait) | 7 fail |
| R3 | the null-store fail-closed (trait) | 1 fail — only after a test was **added** |
| R4 | the permission limb | 1 fail |
| R5 | operator-first ordering | 1 fail — only after the operator test was **fixed** |
| R6 | the route-parameter id source | 1 fail |
| R8 | the fail-closed `try`/`catch` (trait) | 1 fail — only after a test was **added** |
| R9 | the uniform refusal body | 1 fail |
| R10 | method+uri keying | 1 fail — only after a test was **added** |
| R11 | the inertness early return | 1 fail |
| R12 | the permission-row existence check | 1 fail |
| R7 | strict id parsing (trait) | **GREEN — not isolatable here** |
| R13 | `getCurrentStore($user->id)` (trait) | **GREEN — covered by a source ratchet** |

**R3 is the instructive one.** The obvious test — a NULL-store row against a merchant who has a
store — did *not* isolate the limb: `(int) null === (int) 1` is `0 === 1`, so the cast comparison
refuses anyway. The limb is load-bearing only when **both** sides are absent, which makes the
comparison `0 === 0` and **allows**. Both absences are real states: the auction tables declare
`store_id` nullable, and `getCurrentStore()` returns null for a merchant with no `current_store`
and no store of their own. `test_a_storeless_caller_cannot_claim_a_storeless_row` is what actually
bites — a test that looked sufficient was not.

**R5** needed the operator test to have a `permissions` row to check against: with no row the limb
is skipped anyway, so moving the operator check after it changed nothing.

**R7 is not isolatable here, structurally rather than for want of a test.** Strict id parsing
matters when guard and handler could disagree about which row a value denotes — which is what
`'5abc'` did on #57's guard, where the id comes from the body and the handler re-reads it. Here the
handler receives the *identical* route-parameter string, so the two cannot diverge. The control is
inherited from the trait, where it **is** isolated, and it is defence against a future entry that
takes a body key.

**R13 is unreachable** for the same shape of reason as on the sibling guard, and is pinned by a
source assertion in `OwnedPathRawQueryInventoryTest` — verified to bite.

### The raw-query inventory followed the code, and nearly did not

Moving the predicate into `app/Http/Middleware/Concerns/` took its `DB::table()` read with it —
and `OwnedPathRawQueryInventoryTest`'s glob was `app/Http/Middleware/*.php`, **non-recursive**. The
raw query would have left the inventory silently, which is exactly the escape that file exists to
prevent. The **site count caught it** (11 → 10). The glob now covers subdirectories, the trait is
classified `'comparison'`, its five controls are asserted there, and a new assertion requires both
guards to still `use ComparesRowOwnership` — because a guard that stopped using the trait would
take its own copy of the predicate with it.

`RawQueryTenancyInventoryTest`'s platform-wide count is unchanged at 158: the site moved, it was
not added.

## What is NOT closed

- **`show()` on the three resources.** The guard targets write methods only, and says so. Whether
  those reads leak across tenants is a separate question this package did not analyse.
- **The other 68 vendored modules' `Route::resource` declarations.** The same shape may exist
  there, and #63's acceptance criterion keeps it open. Adding `update`/`destroy` to #57's census
  ratchet platform-wide was **tried and reverted**: it matched generic CRUD in seventeen modules —
  19 extra uris, each needing an exemption that would say nothing. The ratchet's resource branch is
  therefore scoped to the two modules these guards cover, and the sweep stays real work rather
  than a regex widening.
