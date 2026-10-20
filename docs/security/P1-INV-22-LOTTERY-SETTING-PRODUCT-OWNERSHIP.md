# P1-INV-22 item 5 — a merchant can point their own lottery at another store's product

**Issue:** #70 item 5 · **Task:** P1-INV-22 · **Status:** reproduced by execution, closed host-side,
gated.

Evidence: `tests/Feature/Security/LotterySettingProductOwnershipTest.php` — 16 tests, 63 assertions.
Fix: `app/Http/Middleware/LotterySettingProductOwnershipGuard.php`, appended to the `web` group.
No `packages/workdo/` file touched.

---

## 1. Reproduced before anything was written

A merchant holding `Edit Lottery Setting`, entitled to the module, posting two repeater rows — one
naming their own product, one naming another store's:

```
POST /lotteryproduct  ->  302  'Lottery Setting successfully updated.'
  lottery_settings id=1 store_id=1 lottery_products=2 (OWN PRODUCT,    store 1) price=50
  lottery_settings id=2 store_id=1 lottery_products=1 (VICTIM PRODUCT, store 2) price=7
```

`store():107` scopes the **existing** rows by `getCurrentStore()` and `:114` writes that same
`store_id`, so the forged row lands in the attacker's **own** tenant. That is precisely why no
`store_id` predicate anywhere notices: the row is not misplaced — its **reference** is.
`lottery_products` names a product the caller does not own, and both `ticket_price` and the draw key
on that lottery.

`store():93-126` has no validation of `lottery_products` at all: the only check in the method is
`min_ticket > max_ticket_per_user` at `:99`.

## 2. Why it is financial, asserted rather than argued

```php
// lottery_form_data, LotteryPrizesController.php
:535   $product = Product::find($request->product_id);          // no store predicate at all
:537   $lottery_setting = LotterySetting::where('store_id', $store->id)
:538       ->where('lottery_products', $product->id)
:539       ->first();
```

`Product` is in no `scoped_models` list, so `StoreScope::isActiveFor()` short-circuits and
`Product::find()` reaches every tenant's catalogue — the standing fact in `CLAUDE.md`. With the
forged row present, the `(store_id, lottery_products)` pair **matches**, so the attacker's own
storefront resolves a lottery for the victim's product at the attacker's own `ticket_price`.
Measured: a lottery at 7 on a product priced 500. The draw then awards the victim's product.

The test asserts this chain directly, inserting the forged row and driving the same two expressions,
because the chain is what makes item 5 a money defect rather than a cosmetic one.

## 3. The fix

One middleware, keyed on `POST lotteryproduct`, using the shared `ComparesRowOwnership` set
predicate rather than a fourth hand-written comparison — two of the defects in the first two such
guards were found by review and not by test, which is why the predicate lives in one place.

```
route null / not a target        -> pass through (inert on every other route)
no web-guard principal          -> refuse
permission not held             -> refuse       (the handler checks the same at :92)
lottery_repeater_basic non-array-> refuse       (today a 500 in the handler's foreach)
lottery_repeater_basic == []    -> PASS THROUGH (the clear-all case; see below)
no usable product id in any row -> refuse
any named product outside the caller's store -> refuse
```

Values are passed to the predicate **raw**, not filtered through `usableId`. The trait's first limb
runs `whereIn('id', $values)` against `products`, which is the database's own coercion — the same
coercion `lottery_form_data:538` will later perform on the stored string. So `'2abc'` is caught as a
reference to product 2 rather than waved through as "not a usable id". A guard and its handler
disagreeing about which row a value denotes is the bug that made `ModuleOrderMutationGuard`
worthless before it was fixed.

### The whole call is refused, not filtered

The handler re-reads the raw input itself at `:112`, so a guard that authorised a subset would
authorise two rows and let the handler act on three. The rule is **all or nothing**, asserted: in
the headline test the attacker's own-product row is not written either.

### An empty repeater is allowed through, deliberately

`[]` is how a merchant **clears** their lotteries: `:127-131` deletes every existing row not named
in the post, over rows `:107` already scoped to the caller's store. Refusing it would be the
regression `LotteryRefundOwnershipGuard` shipped and had to withdraw — closing a working feature to
close nothing. Two tests cover it: the clear-all works, and a clear-all cannot reach another store's
rows.

### No operator pass-through, and that is a departure from the two sibling guards

Both siblings `return $next($request)` for a platform operator, and they are right to: **their**
handlers let the operator in, so refusing would be the lockout `CLAUDE.md` warns about. **This
handler does not.** Measured: `store():92` is `isAbleTo('Edit Lottery Setting')` with no operator
limb, and a bare `super admin` returns **false** — that is #70 item 3, a vendored fail-closed defect
this guard cannot fix and must not paper over.

So a pass-through here would change nothing observable today (the handler refuses two statements
later, with the same message) while creating a **latent gap**: an operator would reach `store()`
with no ownership check at all.

**And the gap is live, not hypothetical.** Measured: a `super admin` granted a Laratrust role
carrying the permission returns **true** from `isAbleTo`. So the principal that would slip through
is constructible today. The pass-through was in the first version of this guard; removal check R8
moved no test, which is what a control that buys nothing looks like. It was removed rather than kept
with a note, and two tests were added so R8 now bites.

### Only `POST lotteryproduct` is targeted, and the other verbs were read

`update():165` and `destroy():175` are both `//` — empty stubs, asserted by line number, brace and
body. This is stated explicitly because an exemption scoped to the methods somebody happened to look
at is the fourth distinct way a census in this repository has missed what it claimed. If upstream
fills either stub, that assertion goes red here.

## 4. Removal checks, as measured

Scripted: each control deleted or weakened **alone**, the suite re-run, the file restored and
verified byte-identical with `diff -q`. **7 of 9 isolate.**

| | control | result |
|---|---|---|
| R1 | the `appendToGroup` registration | 5 fail |
| R2 | the ownership predicate | 3 fail |
| R3 | the `TARGETS` method+uri key | 5 fail |
| R4 | the nested subkey extraction | 3 fail |
| R5 | the non-array refusal | 1 fail |
| R9 | the clear-all pass-through | 1 fail |
| R8 | **re-adding** the operator pass-through | 1 fail |
| R6 | the no-usable-product refusal | **GREEN — subsumed** |
| R7 | the permission check | **GREEN — subsumed** |

**R6** is subsumed by the trait: with it gone, an empty `$values` reaches the set predicate, which
returns false on an empty set, so the request is refused anyway — with the wrong reason. Kept for
the accurate reason, not because it is load-bearing.

**R7** is subsumed by the handler: `store():92` makes the same check, so no test can tell the two
apart. Kept as defence in depth and for consistency with the siblings — if upstream ever drops
`:92`, this one still holds.

Both are labelled, not counted.

## 5. What is not closed

- **#70 item 3**, the operator lockout at `store():92` and `LotteryOrderController:103`. Inside
  vendored controllers, fail-closed, no host seam. Upstream.
- **#70 item 4**, the commented-out permission check at `LotteryOrderController:31`. Upstream.
- **#70 item 1**, the payment-method casing mismatch. Verified by execution separately; its
  correction is designed and held for an owner decision — see
  `docs/security/P01-LOTTERY-PAYMENT-SELECTION-PROPOSAL.md`.
- **The unscoped `Product::find($request->product_id)` at `lottery_form_data:535` itself.** This
  guard stops the forged *reference* being created; it does not make that lookup tenant-aware. A
  caller can still name a foreign `product_id` there, and is contained only by `:537-539` finding no
  setting and `:541` then throwing on `->ticket_price` — i.e. by a 500, not by a check. Latent
  hardening, recorded rather than fixed, because fixing it means either a `Product` scope (which
  `CLAUDE.md` records as absent by design and far-reaching) or a second guard on the storefront
  route.

## 6. Gates

```
php artisan test tests/Feature/Security/LotterySettingProductOwnershipTest.php
  16 passed (63 assertions)

php artisan test --testsuite=Feature --filter=Tenancy
  432 passed, 6 skipped  -- no raw-query inventory moved, which is expected: the guard makes no
                            DB:: call of its own, it calls the shared trait
```

Full-suite results are in the commit message.
