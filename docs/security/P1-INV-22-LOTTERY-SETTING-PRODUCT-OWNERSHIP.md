# P1-INV-22 item 5 — a merchant can point their own lottery at another store's product

**Issue:** #70 item 5 · **Task:** P1-INV-22 · **Status:** reproduced by execution, closed host-side,
gated.

Evidence: `tests/Feature/Security/LotterySettingProductOwnershipTest.php` — **19 tests, 76
assertions**. Fix: `app/Http/Middleware/LotterySettingProductOwnershipGuard.php`, appended to the
`web` group. No `packages/workdo/` file touched.

> **Revised after an adversarial review that found three real problems in the first version** — one
> of them live and user-facing, one of them a comment at the registration site instructing exactly
> the change a removal check existed to prevent. §7 records what changed and why. Two of the
> review's own claims were then refuted by measurement, and that is recorded too.

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

### An empty repeater is allowed through — but not for the reason first given

The first version of this document said `[]` is "how a merchant clears their lotteries". **That is
false.** `[]` has no urlencoded representation at all
(`http_build_query(['lottery_repeater_basic' => []]) === ''`), and the shipped form cannot send it:
`index.blade.php:20` is a jquery.repeater whose delete buttons remove the whole
`[data-repeater-item]`, every `lottery_repeater_basic[i][...]` name lives inside one and is renamed
at runtime by `public/js/repeater.js`, with `initEmpty: false` and no undeletable first row. **A
zero-row Update submits only `_token`** — no `lottery_repeater_basic` key — which the guard refuses
at its `! is_array()` limb.

**So the UI clear-all has never worked, and this guard did not break it.** Measured with only the
registration line removed: the same missing-key request is `foreach (null)` at
`LotteryPrizesController:95` → `ErrorException` → **HTTP 500**. The guard turned a vendored 500 into
a clean refusal. Making clear-all work needs `?? []` at `:93`, which is upstream's — and must not be
smuggled in here, because that would newly enable a destructive bulk delete that has never been
reachable.

The `[]` pass-through is therefore reachable only by a hand-crafted JSON or ParameterBag caller.
Both were driven; both reach `store()` and delete only the caller's own rows, which is safe because
`:107` already scoped them. It is kept because refusing it closes nothing while breaking the one
caller shape that works.

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

Scripted: each control deleted or weakened **alone**, the suite re-run, the file restored and
verified byte-identical. **9 of 10 isolate.**

| | control | result |
|---|---|---|
| R1 | the `appendToGroup` registration | 8 fail |
| R2 | the ownership predicate | 4 fail |
| R3 | the `TARGETS` method+uri key | 8 fail |
| R4 | the nested subkey extraction | 8 fail |
| R5 | the non-array refusal | 1 fail |
| R6 | refuse-not-skip an unreadable product value | 2 fail |
| R8 | **re-adding** the operator pass-through | 1 fail |
| R9 | the clear-all pass-through | 1 fail |
| R10 | the per-reason `deny()` messages | 4 fail |
| R7 | the permission check | **GREEN — subsumed by the handler** |

**R7** is subsumed by `store():92`, which makes the same check, so no test can tell the two apart.
Kept as defence in depth and for consistency with the siblings — if upstream ever drops `:92`, this
one still holds. Labelled, not counted.

**R6 used to be green and labelled subsumed, and that labelling was wrong in an instructive way.**
It was correct as far as it went: the limb only *skipped* a value it could not read, and an
all-skipped post reached the trait's empty-set refusal anyway. What it missed is that the skip was
invisible **only when every row was unreadable** — with one valid row alongside, the unreadable row
was written unexamined. A subsumption label is a claim about today's controls, not a permanent
property.

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

---

## 7. What the adversarial review changed, and what it got wrong

The review (CLAUDE.md requires one for any change touching authorization or tenancy) raised eleven
findings. Six behavioural ones were then verified by execution and each adversarially attacked.
**It found no request the guard allows that reaches another tenant's product** — but three of its
findings were real, and two of its own claims did not survive the attack.

### Acted on

**A live, user-facing defect: every refusal said "Permission denied."** The `lottery_products`
select is the **only** control in the repeater row without `required`, and
`Form::select(..., ['placeholder' => ...])` renders `<option value="">` selected. So a merchant who
fills the ticket numbers and forgets the product — an ordinary mistake — was told they lacked
permission, with no indication which field was wrong. The same applied to a submit carrying no
settings. `deny()` already took `string $reason` and discarded it; it now carries a message per
reason. My own claim that "the shipped form always supplies a product" was simply false.

**A UI-reachable hole in my own limb.** The loop *skipped* a `lottery_products` value it could not
read. `ConvertEmptyStringsToNull` is in Laravel's **global** stack, so a blank select arrives as
`null`, and `is_scalar(null)` is false. Fill one row, leave a second row's product blank, press
Update: the predicate was satisfied by row one alone and the handler wrote a NULL-product lottery
the guard never examined — invisible to every `where('lottery_products', ...)`, so it can never sell
a ticket, reported to the merchant as success. The same skip swallowed an array-valued value
(measured: HTTP 500 **after** row one was written). The limb now refuses. Neither case reaches
another tenant, but "every value must be an owned product" was the stated contract, and silently
exempting the values the guard cannot read was not that contract. This is also what made R6 become
load-bearing.

**Three comments said the guard passes the operator through. It does not.** `bootstrap/app.php`, the
guard's own docblock (contradicting itself three lines later) and the test docblock all described the
**withdrawn first version** — the one R8 removed. The registration-site comment is the one a future
reader consults, and following it would have re-opened the gap R8 exists to catch. Six independent
verification agents each flagged it unprompted. All three corrected.

**Also corrected:** a vacuous inertness test (`get()` cannot produce `deny()`'s 403, so it stayed
green under a uri-only key — now `getJson()`, measured 302 today and 403 under the broken key, and
its docblock no longer promises cross-module coverage it never had); the claim that a junk row stores
`''` (it stores NULL); "both sibling guards pass the operator through" (**three** of five do, and
`LotteryRefundOwnershipGuard` has none); "keyed on method+uri like the two sibling guards"
(`ModuleOrderMutationGuard` keys on uri alone and `LotteryRefundOwnershipGuard` on
`getActionName()`); and the shared trait's own "shared by the two `web`-group guards" (five users).

### Refuted by measurement, and left alone

**The `input()` / `all()` divergence is real and bounded.** `store():93` reads `input()` but `:112`
reads `$request[...]` → `all()` → `array_replace_recursive(input(), allFiles())`, so files outrank
input and a multipart POST can add a repeater row the guard never inspected. The proposed fix — read
`all()` in the guard — was **not** made: `allFiles()` yields only `UploadedFile` objects, which are
not scalar and are now refused outright by the widened limb, and whose string form is a PHP-assigned
temp path denoting no product. No multipart construction was found that names another tenant's
product while the guard allows the request. Recorded in the docblock; no code change.

**The fail-closed refusal for a storeless principal needs no test.** The review proposed pinning it.
Measured: deleting the trait's `empty($currentStore)` limb leaves such a caller refused anyway,
because limb 1 compiles to `("store_id" is null or "store_id" is not null)`. A test whose control
can be removed while it stays green is the vacuous test this project exists to avoid. Not added.

**The `current_store` dependency is shared, not introduced.** The guard is as strong as
`users.current_store`. A sweep of every write to that column found no path by which a merchant or
staff principal points it at a store they do not own, and the **handler carries the identical
dependency** at `:107`/`:114`. Noted in one sentence rather than the four-part paragraph proposed.

### Raised separately, not fixed here

- **Ticking "Refund Ticket Option" is a 500 for every merchant** on a legitimate own-product save.
  `name="refund_ticket"` is a checkbox renamed into the repeater row, so a ticked box posts the
  scalar `'on'`, and `store():115` does `implode(',', ...)` on it — a PHP 8 TypeError. Measured:
  ticked → 500, unticked → 302 and a row. **This suite's own `repeaterRow()` fixture passed
  `'refund_ticket' => []`, so all nineteen other tests drove the one shape that works.** Vendored;
  pinned by `test_pins_a_vendored_defect_ticking_refund_ticket_is_a_500` so it cannot be forgotten.
- **The UI clear-all is a 500 upstream** (`:93` lacks `?? []`), as above.
- **Role-name validation is case-sensitive.** `RoleController::store:124` uses
  `Rule::notIn(RolePolicy::RESERVED_NAMES)`, an exact comparison, so a merchant holding `Create
  Role` can create roles named "Super admin" / "SUPER Admin". The chain is stopped one layer later
  by `UserController`. Outside #70 — its own finding.
- **A multi-store merchant is refused their own other store's product.** Correct (it matches what
  the handler writes), and now stated in the docblock as a behaviour limit rather than left to be
  discovered.