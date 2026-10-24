# P1-ORD-01: what the coupon-policy deferral covers, and the evidence that it is safe

*Written 2026-10-23. This session READ source; it executed nothing, because another process held the
tree. Every statement below is attributed: a file and line read at `a2a5b9ca`, a test whose source
was read, or a measurement recorded by an earlier package and quoted with its source. Claims that
could not be settled by reading say NOT VERIFIED, and section 6 lists them together.*

Issue [#20](https://github.com/fullstackfull/syriastore98/issues/20), ledger row `P1-ORD-01`
(`docs/development/phase-1/12-PHASE-01-TASK-LEDGER.md:2665`), status BACKLOG, owner decision
required.

---

## 1. What the owner decided

> *"Follow your recommendation to defer the broader coupon-policy redesign until after Phase 01.
> However, do not defer any confirmed payment-total integrity defect, unauthorized discount, or
> incorrect gateway charge. Document the deferral and its safety evidence."*

That is two instructions, and this document answers both. Section 2 states exactly what is inside
the deferral. Section 3 gives, per item, the evidence that it is none of the three named classes.
Section 4 lists what was found that IS one of them, and is therefore outside the deferral whatever
else is postponed.

The same carve-out was applied once already in this workstream: commit `13bec7d3` quotes this
directive verbatim and closes the posted-`coupon_info` discount on the web checkout rather than
folding it into P1-ORD-01. Section 4 is written to the same standard.

---

## 2. What is deferred, item by item

The deferral is the **arithmetic and gate redesign** named by P1-ORD-01's four open acceptance
criteria. It is not a deferral of anything that moves money today.

| | Deferred item | Where it lives | Criterion |
|---|---|---|---|
| **D1** | `apply_coupon()` refuses almost every coupon, for two independent reasons | `app/Http/Controllers/Api/ApiController.php:3963-4833` | 2 |
| **D2** | The cart key the charge is built from is PRE-coupon | `ApiController.php:728`, `:751-752`, `:851-852` | 1, 4 |
| **D3** | `place_order` never applies the coupon to its stored totals | `ApiController.php:3356-3362` (the comment), `:3663`, `:3670-3680` | 1, 4 |
| **D4** | `final_sub_total` is the caller's on the direct coupon endpoints | `ApiController.php:4015`, `:4023`, `:4080`, `:4358` | 3 |
| **D5** | Two parallel spend-gate structures inside an 871-line method | `ApiController.php:4021-4022` and `:4316-4320`, `:4642-4645` | 2 |
| **D6** | Unifying the pricing bases so `OrderTotalReconciler` can reject instead of warn | `app/Http/Controllers/PaymentController.php:2830-2840`, `:4151-4161` | P1-ORD-02 criteria 1 and 3 |

### D1. `apply_coupon()` refuses almost every coupon

Two causes, both recorded as MEASURED by the package that fixed the three fatals hiding them
(ledger `:2724`, and `tests/Feature/Commerce/OrderTotalCouponTest.php:226-262`):

1. **The usage-limit query reads an unset limit as expiry.** `ApiController.php:3989-3992` is
   `whereDate('coupon_expiry_date', '>=', today)->where('coupon_limit', '>', $coupon_count)`.
   `coupons.coupon_limit` defaults to `'0'`
   (`database/migrations/2023_12_29_092412_create_coupons_table.php:27`), so with no uses yet the
   predicate is `0 > 0`, false, and the answer is `This coupon has expired.`
2. **A mispaired `else`.** `if ($coupon->coupon_type == 'fixed product discount')` opens at
   `ApiController.php:4088`; its `else` is at `:4316` and returns
   `' The minimum spend for this coupon is ...'`. The `else` belongs to the TYPE test, not to a
   spend test, so a `percentage` or `flat` coupon is refused with a message about a threshold it
   never failed. The genuine spend gates are one level out at `:4021-4022` and are null-tolerant.

### D2. The cart key the charge uses is pre-coupon

In `cart_list`, `$cart_final_price += $final_price` runs at `:728`, BEFORE the coupon block
reassigns `$final_price = $coupon_apply_price` at `:751-752`. So the discount reaches `$coupon_price`
and `$final_total` (`:792`, published as `total_sub_price` at `:853`) and never reaches
`total_final_price` / `final_price` (`:851-852`), which is the figure the web checkout and
`payment_sheet` charge. Recorded as measured in `docs/security/P01-SECURITY-WORK-QUEUE.md` S21 item
2 (`coupon_code=<a 50% coupon>` on a 100.00 basket gave `total_final_price` 100.00 and
`total_sub_price` 50.00) and restated in the code at `ApiController.php:7419-7435`.

### D3. `place_order` prints a discount beside a full-price total

Its own comment says so at `ApiController.php:3356-3362`: `$order->coupon_price` is
`$apply_coupon['amount']` (`:3663`) while `final_price` keeps the pre-coupon `total_final_price`
(`:3676-3680`).

### D4. `final_sub_total` on the direct coupon endpoints

`apply_coupon()` prefers `$request->final_sub_total` over `sub_total` wherever it reads a
subtotal: both spend gates and, twice, `$price`, the base a percentage discount is taken from. A
grep of the method for `$request->final_sub_total` returns SIX reads (`:4015`, `:4023`, `:4080`,
`:4358`, `:4398`, `:4710`); the four named by the existing documentation are the ones in the
non-free-shipping branch. The web checkout pins
that key to the server subtotal before calling (`PaymentController.php:1787-1791`, pinned by
`CouponAmountSourceTest::test_the_checkout_pins_final_sub_total_to_the_server_subtotal`). The
DIRECT endpoints do not: `POST {slug}/applycoupon` (`OrderController.php:550-570`) and
`api/{slug}/apply-coupon` pass the body through.

**A correction to the existing note, found while reading.** `PaymentController.php:1774-1776` says
of `final_sub_total` that "a repo-wide search of app/ and resources/views/ finds it ONLY in those
reads ... no view posts it". That search did not cover `public/`. The shipped storefront DOES post
the key: `public/assets/js/front-theme.js:652` reads it out of the DOM
(`var final_sub_total = $('#subtotal').val();`, the hidden input at
`resources/views/front_end/pages/checkout-cart-list.blade.php:71`) and `:666` sends it to the
coupon endpoint, with the same pair at `:594` and `:608` for the shipping endpoint. The value a
normal browser sends is the server's own subtotal, so the shipped flow is honest, but the key is on
the wire. Pinning or rejecting it on the direct endpoints is therefore a client-visible change and
needs that treated as such rather than assumed free.

### D5 and D6

The structural work: one gate structure instead of two, and one pricing base across the writers so
the reconciler at `PaymentController.php:2840` and `:4161` can reject rather than warn. The reason
it still warns is written at `:2830-2839`: the branches build `final_price` from different bases, so
a rejecting assertion would fail legitimate orders until they are unified.

### Explicitly NOT deferred

Everything in section 4, and the three controls already closed on this path and pinned by tests:
the posted `coupon_info` short-circuit (`13bec7d3`), the declined-coupon 500, and the
`final_sub_total` pin on the checkout.

---

## 3. The safety evidence, item by item

The question asked of each item is the owner's: can it (a) corrupt a payment total, (b) grant a
discount the server did not authorise, or (c) make the gateway charge a wrong amount?

| Item | (a) payment-total integrity | (b) unauthorized discount | (c) incorrect gateway charge | Evidence |
|---|---|---|---|---|
| D1 | No. A refusal leaves the server's own total untouched | No. A refused coupon produces no discount anywhere the charge reads | No. The gateway is handed the undiscounted server figure | `CouponAmountSourceTest::test_a_declined_coupon_does_not_break_the_checkout` asserts the stored payable is the full 100.00 and `coupon_info` is `[]`; `OrderTotalCouponTest::test_known_bad_a_flat_coupon_is_refused_and_the_total_is_undiscounted` asserts `orders.coupon_price = 0.0` and `final_price = 200.0` |
| D2 | No, in the direction that matters: the key is NOT caller-settable | No | Overcharges relative to a discount the shopper may believe applies. See 3.4 | `ApiController.php:7427-7433` records both properties, with `shipping_final_price=-999` leaving `total_final_price` at 100.00 while `total_sub_price` goes to -899.00 |
| D3 | No | No. `$order->coupon_price` is `apply_coupon()`'s own answer (`:3663`) | No | `OrderTotalCouponTest::test_known_bad_...` drives `place-order` with a 50.00 `coupon_info` and gets `coupon_price 0.0`, `final_price 200.0` |
| D4 | Not on the checkout: the key is overwritten before `apply_coupon()` sees it | Not today: the value only reaches arithmetic that refuses first (D1) | No: the direct endpoints return JSON, they write nothing | `PaymentController.php:1787-1791`; `apply_coupon()` lines `3963-4833` contain ZERO write constructs, verified by scanning that range for `->save(`, `->update(`, `::create(`, `->insert(`, `->delete(`, `->increment(`, `->decrement(` and `DB::statement`: no matches |
| D5 | No | No | No | Same as D1; the structure is the cause of the refusals, not of a payment |
| D6 | The reconciler is warn-only, so a mismatch is logged and not refused | No | No | `PaymentController.php:2830-2840`, `:4151-4161`; `OrderTotalCouponTest::test_the_stored_order_reconciles_against_its_own_components` |

### 3.1 Can a deferred item corrupt a payment total?

Not by caller input, which is the form this project has repeatedly found. The three keys that decide
money on the checkout are all server-written before anything deferred runs:

- `cartlist_final_price` is rebuilt at `PaymentController.php:1896` as
  `$cartlist_final_price + $delivery_price + $tax_price`, from the cart read and the server tax
  resolver.
- `shipping_final_price` is zeroed at `PaymentController.php:1218` before the cart is read.
- `final_sub_total` is overwritten at `PaymentController.php:1787-1791`.

What remains inside the deferral is an arithmetic that is wrong by a KNOWN, server-side, bounded
figure (the coupon), not one the caller supplies.

### 3.2 Can a deferred item grant an unauthorized discount?

Not through `apply_coupon()`, because `apply_coupon()` grants nothing: it refuses, for the two
reasons in D1, and the refusal is pinned in two suites. The commit message of `13bec7d3` records the
attempt to write a positive test and its failure: "NO coupon applies on this codebase today ...
refuses every shape tried (nulls, explicit 0/999999, min 1/max 999999, with and without per-item
limits)".

**The important qualification, and it is the reason section 4 exists.** `apply_coupon()` is not the
only coupon arithmetic in the codebase. `Cart::getCouponTotalAmount()`
(`app/Models/Cart.php:1015-1114`), reached from `cart_list` (`ApiController.php:749`), computes a
discount on a different and much weaker gate. Everything that reads `apply_coupon()` is safe by
refusal. Everything that reads the CART's figure is not covered by that argument, and one of those
readers is an order total. That is F1 below.

### 3.3 Can a deferred item make the gateway charge a wrong amount?

The amount handed to a gateway is `cartlist_final_price` from the stored checkout state, built at
`PaymentController.php:1896` and, for the partial-payments and pending-balance limbs, by the two
concerns documented in `docs/security/P01-ORD-02-PARTIAL-PAYMENT-TOTAL-SOURCE.md`. No deferred item
writes that key. The charge is therefore the server's own undiscounted total: wrong commercially
while D1 and D2 stand, and not forgeable.

### 3.4 What the deferral does cost, stated rather than minimised

Three costs, none of which is in the owner's three classes, and all of which are customer-visible:

1. **Coupons do not work.** A shopper who types a valid code is refused with a message that names a
   threshold they did not fail (D1).
2. **An order can RECORD a discount it did not GIVE.** This is visible inside the existing suite,
   which is the strongest available evidence because it is executed rather than reasoned:
   `CouponAmountSourceTest::test_a_posted_discount_does_not_reach_the_orders_coupon_records` asserts
   `order_coupon_details.coupon_discount_amount` is the server's **10.00**, while
   `CouponAmountSourceTest::test_the_amount_charged_was_and_remains_the_servers` asserts the stored
   payable for the same checkout is the full **100.00**. The 10.00 comes from the cart payload's own
   `coupon_info` (`ApiController.php:833-840`) by way of `EasebuzzController.php:978-994`; the
   100.00 comes from `apply_coupon()` refusing. Both tests pass, so the divergence is current
   behaviour, not a hypothesis. That is acceptance criterion 4, "no writer charges full price while
   printing a discount", open and measured.
3. **The storefront can DISPLAY a discounted total while the charge is undiscounted.** The checkout
   panel prints `total_sub_price` as the order total
   (`resources/views/front_end/pages/checkout-cart-list.blade.php:93`) and `total_coupon_price` as
   the discount (`:84`), both post-coupon. `get_cartlist()` and `getshippingdata()` post the coupon
   code straight from the visible input on every cart refresh and every shipping change
   (`public/assets/js/front-theme.js:186-208`, `:587-611`), and `get_shipping_method()` answers with
   `total_sub_price + shipping` (`app/Http/Controllers/CartController.php:668-682`). The hidden
   `coupon_code` field that the ORDER form submits is set only inside the success arm of the coupon
   AJAX (`front-theme.js:681` clears it, `:690` sets it), so after a refusal the display can carry a
   discount that the submitted order does not. READ-DERIVED, NOT EXECUTED: the browser sequence was
   not driven. See section 6.

Cost 3 is a presentation defect and cost 2 a record defect; neither moves money away from the
merchant. They are listed because "safe to defer" should not be read as "harmless".

---

## 4. What is NOT deferrable

Three findings. All are READ-DERIVED at `a2a5b9ca` and NOT EXECUTED, and each carries the probe that
would settle it in one request. They are reported here rather than fixed because this task was to
write one file; none of them should be folded into the deferral without an explicit owner decision,
because each is in a class the owner named.

### F1. The cash-on-delivery order total takes the cart's UNVALIDATED coupon figure, and a refused coupon can zero it

**Class: unauthorized discount, and for `cod` it is real money.**

The chain, link by link, all read:

1. `cart_list` looks the coupon up on `store_id` plus `coupon_expiry_date` only
   (`ApiController.php:743-747`). There is no `status`, no `coupon_limit`, no `coupon_limit_user`
   and no spend check on this lookup. The second, limit-checking lookup at `:795-830` runs AFTER the
   accumulation and only nulls the `coupon_info` echo.
2. It calls `Cart::getCouponTotalAmount()` (`ApiController.php:749`). That method applies its own
   checks and, when they fail, **returns 0**: `app/Models/Cart.php:1039` (per-user limit reached)
   and `:1043` (date expired or `coupon_limit > uses` false). It also returns its initial `0` at
   `:1114` when a `fixed product discount` coupon matches none of its product or category arms.
3. The caller reads that 0 as the discounted LINE PRICE:
   `$coupon_price += $final_price - $coupon_apply_price; $final_price = $coupon_apply_price;`
   (`ApiController.php:751-752`). A returned 0 therefore makes the line **free** and records the
   whole line value as the discount. This is the same zero-means-two-things inversion that P01-SEC-14
   E6 recorded at `ApiController.php:3320-3328`, in the other direction.
4. `$final_total = $cart_final_price - $coupon_price + $shipping_price + $tax_price` (`:792`) is
   published as `total_sub_price` (`:853`).
5. `PaymentService::getProductStatus()` takes `$total_sub_price = $response['total_sub_price']`
   (`app/Services/PaymentService.php:2290`), where `$response` is the cart payload the controller
   passes in (`:2147`), and writes `$order->final_price = $total_sub_price` (`:2744`, or
   `:2742` plus delivery when the plan's shipping method is on; `:2706-2708` for the guest arm).
6. `PaymentService::process()` routes `cod` and `bank_transfer` to that method (`:378-381`).

`orders.final_price` on a `cod` order is the cash the driver collects: the project states this at
`PaymentController.php:1201-1203`. So a request that carries `coupon_code` for a coupon that
`apply_coupon()` would refuse still reduces what is collected, and for an exhausted, per-user
exhausted, or non-applicable fixed-product coupon it reduces it to the tax and delivery only.

The gateway arms are not exposed the same way, because they charge `cartlist_final_price`, which is
pre-coupon. The divergence instead lands in the record: `PaymentController::getProductStatus` writes
`$order->final_price = $final_sub_total_price` (`:2762`, `:2802`), and `$final_sub_total_price` is
`$cartlist['total_sub_price']` (`:2381`, `:2409`), so for a store whose plan has shipping on the
order can RECORD less than the card was charged.

**Why no test caught it.** `OrderTotalCouponTest` drives `api/{slug}/place-order`, whose total is
`total_final_price` (`ApiController.php:3670-3680`), a different key. `CouponAmountSourceTest`
drives the easebuzz pair, whose order total is `$final_price + $tax_price - $coupon_price`
(`EasebuzzController.php:800`). Neither reaches the `total_sub_price` writer.

**The probe.** One `POST {slug}/process-order` with `payment_type=cod`, signed in, a 100.00 basket,
and `coupon_code` naming a store coupon whose `coupon_limit` equals its recorded uses; then read
`orders.final_price`. If it is the tax alone rather than 100.00 plus tax, this is confirmed.

### F2. `place_order` writes the shopper's own coupon history into the merchant's records

**Class: unauthorized (recorded) discount. Same defect class as the one `13bec7d3` closed, on the
handler the fix did not touch.**

`ApiController.php:3754-3781`: `if (!empty($request->coupon_info)) { $coupon_data = $request->coupon_info; ... }`
and then `order_coupon_details.coupon_discount_amount`, `.coupon_discount_number`,
`.coupon_final_amount`, `.coupon_name`, `.coupon_code` and `user_coupons.amount` are all written from
that array. The only derived field is `user_coupons.user_id` (`:3778`). The charge is not affected:
`$order->coupon_price` is `apply_coupon()`'s answer (`:3663`) and `final_price` is the pre-coupon
cart key, which is what `OrderTotalCouponTest::test_known_bad_...` pins.

This is already written down, two files away from the fix: `OrderTotalCouponTest.php:158-164` says
"those recorded fields are the CLIENT's copy, so the coupon HISTORY row is client-supplied ... a
reporting-integrity gap, not a charging one, and it is not this task's to close". The owner's
directive arrived after that note, and `13bec7d3` then closed exactly this shape on the web path,
calling it "a FALSIFIED FINANCIAL RECORD". No test asserts the contents of `order_coupon_details`
written by `place_order`: grepping `tests/` for `order_coupon_details` returns
`OrderTotalCouponTest` (a comment only), `CouponAmountSourceTest` (the easebuzz path),
`OrderCancelRestockTest`, two migration-hygiene unit tests and two tenancy inventories.

Two aggravating details on the same lines:

- `Coupon::find($coupon_data['coupon_id'])` (`:3756`) has **no store predicate**, and `Coupon` is in
  no `scoped_models` entry (`config/tenancy.php:141-377` lists eleven models; `Coupon` is not among
  them) and declares no global scope (`app/Models/Coupon.php:9-11`). So the `user_coupons` row can
  be attached to ANOTHER store's coupon, and `Coupon::UsesCouponCount()`
  (`app/Models/Coupon.php:68`) counts `user_coupons` rows, so a forged write consumes another
  tenant's coupon allowance.
- `$UserCoupon->coupon_id = $Coupon->id` (`:3779`) dereferences a `find()` that may be null, after
  the order row is already written. The ledger already names "500 after the order is written" as the
  retry-duplication hazard this handler had three of.

**The probe.** `POST api/{slug}/place-order` with a customer token, a real `coupon_id` of the store
and `coupon_discount_amount: 99999`; then read the latest `order_coupon_details` row.

### F3. `processCoupon()`'s early return leaves a posted `coupon_info` in the checkout state

**Class: unauthorized (recorded) discount. A one-key bypass of the fix in `13bec7d3`.**

`PaymentController::processCoupon()` returns at `:1722-1727` when `coupon_code` is empty, and that
return carries **no `coupon_info` key**:

```php
if (empty($request['coupon_code'])) {
    return [
        'cartlist_final_price' => $cartlist_final_price,
        'coupon_price' => 0
    ];
}
```

The caller merges the result with `$request->merge((array)$couponData)` (`:1319`), and a merge only
overwrites keys the array holds. So a `coupon_info` posted WITHOUT a `coupon_code` survives into the
request, into the stored checkout state (`PaymentService` stores `$request->except('attachment',
'payment_receipt')`, `:481`, `:682`, `:705`, `:722`, `:896` and others) and into the record writers
that read it: `PaymentService.php:2859-2884` (the `cod` and `bank_transfer` writer),
`EasebuzzController.php:936-963`, `AuthorizeNetPaymnetController.php:1131-1156`,
`KhaltiPaymnetController.php:785`, `NMIPayController.php:1137`, `PayUPaymentController.php:824`,
`CinetPayController.php:992`, `PaiementProPaymentController.php:930` and
`PaymentController.php:2945`, `:3751`, `:4268`. A grep of `app/` for
`if (!empty($requests_data['coupon_info']))` returns 18 sites in 9 files.

The charge is not affected on this route either: `PaymentService.php:2355` gates its
`$coupon_price` on a non-empty `coupon_code`, so `orders.coupon_price` stays 0. What is affected is
the same pair of tables as F2.

The regression suite does not cover it: every fixture in `CouponAmountSourceTest` posts
`coupon_code` and `coupon_info` together (`:160-163`, `:206-209`, `:288-291`), which is the branch
that was fixed.

**The probe.** `POST {slug}/process-order` with `payment_type=cod`, a forged `coupon_info` and no
`coupon_code`; then read `order_coupon_details` and `user_coupons`.

### Adjacent faults found while reading, not in the three classes

- **`PaymentService.php:2356-2357` still contains the short-circuit that was removed from
  `PaymentController::processCoupon`**: `$coupon_price = $requests_data['coupon_info']['coupon_discount_amount'] ?? 0;`.
  It is not reachable with caller input today, because the only writer of that state is
  `processOrder`, which now overwrites `coupon_info` whenever `coupon_code` is non-empty, and this
  branch requires a non-empty `coupon_code`. It becomes live the moment any other initiation stores
  a checkout state. Recorded, not fixed.
- **`EasebuzzController.php:938` and `:958`** take `Coupon::find()` and then `$Coupon->id` with no
  null check, after the order row is written. `PaymentService.php:2861-2862` has the `if ($Coupon)`
  that this copy lacks.

---

## 5. What a future session needs to pick this up

1. **Read in this order**: this file, the ledger row at
   `docs/development/phase-1/12-PHASE-01-TASK-LEDGER.md:2665`,
   `docs/security/P01-ORD-02-PARTIAL-PAYMENT-TOTAL-SOURCE.md` (what is already closed on the total),
   then S21, S23 and S24 in `docs/security/P01-SECURITY-WORK-QUEUE.md`.
2. **Close section 4 first, and separately.** F1, F2 and F3 are narrow and each has a probe. Folding
   them into the redesign would make the redesign unreviewable and would leave the owner's carve-out
   unserved for the length of it.
3. **There are two coupon arithmetics, not one.** `apply_coupon()` (`ApiController.php:3963-4833`)
   and `Cart::getCouponTotalAmount()` (`app/Models/Cart.php:1015-1114`), with different gates and
   different zero semantics. The redesign is not finished while both exist; deciding which is the
   authority is the first design decision, and F1 is the argument for making it the validating one.
4. **Fixing D1 changes charged amounts.** The ledger's compatibility line says so, and the owner's
   acceptance of that is still required. The first store to notice will be one whose coupons have
   silently never worked.
5. **Tests that must change when the fix lands, by name.**
   `OrderTotalCouponTest::test_known_bad_a_flat_coupon_is_refused_and_the_total_is_undiscounted`
   fails on purpose and carries the instruction in its own failure message.
   `CouponAmountSourceTest`'s docblocks at `:174-186` and `:299-320` state which of their assertions
   are expected to survive the fix (the server-is-the-source ones) and which become measurable for
   the first time (the `final_sub_total` pin, currently a source ratchet whose removal check
   SURVIVED and says so).
6. **The tests still owed**, from the ledger row: a positive test per coupon type; a negative
   proving a caller-supplied `sub_total` or `final_sub_total` cannot change the charge; and a
   regression proving `place_order` and `ordersave` agree on one basket.
7. **Then D6.** Once the writers build `final_price` from one base, `OrderTotalReconciler` can move
   from `warnIfInconsistent` to `assertConsistent` at `PaymentController.php:2840` and `:4161`, which
   is what P1-ORD-02 criteria 1 and 3 are waiting on.
8. **Criterion 3 is not free.** The storefront posts `final_sub_total` on two AJAX calls (see D4),
   so pinning it on the direct endpoints changes what a shipped page sends, unlike the checkout pin
   which overwrote a key no client supplied.
9. **Engine.** `coupons.minimum_spend`, `maximum_spend`, `coupon_limit` and `coupon_limit_user` are
   `integer` columns compared against money (`create_coupons_table.php:23-27`). SQLite's affinity
   will not reproduce MySQL's coercion, so any gate change needs a run from
   `tests/Support/Security/bin/` as well as the suite.

---

## 6. What was NOT verified, and why

- **Nothing in this document was executed.** The instruction for this task was to read, because
  another process held the tree. Every "the code does X" statement is a reading of the file and line
  cited; every number quoted as measured is attributed to the package that measured it. The findings
  in section 4 are read-derived and each carries a probe for exactly that reason.
- **F1, F2 and F3 are unconfirmed by execution.** Their chains were traced link by link and the line
  numbers are given so a reader can check each link, but no request was driven and no row was read.
  Treat them as high-confidence readings that need one probe each, not as measurements.
- **The browser sequence behind cost 3 in section 3.4 was not driven.** The JavaScript, the blade
  and the two controller endpoints were read; what a real page does after a refused coupon,
  including whether `get_cartlist()` fires before submission in the shipped theme, was not observed.
- **The themes directory and the module themes were not audited for coupon fields.** A grep for
  `coupon_info` over `themes/` and `packages/workdo/` returns five files, none of them a storefront
  checkout form, and the hidden inputs the coupon AJAX writes to (`#coupon_info_id` and its
  siblings, `front-theme.js:698-704`) exist in NO blade in this repository. Whether a deployed theme
  supplies them was not established, so "the shipped UI does not post `coupon_info`" is NOT
  VERIFIED. The documented theme API does carry the field as a request key
  (`packages/workdo/Api/src/documentation/theme.json`), so third-party clients post it by contract,
  which is what makes F2 reachable in practice.
- **Whether any coupon works in production today is NOT VERIFIED.** D1's first cause depends on
  `coupon_limit`, which the merchant form marks `required`
  (`app/Http/Controllers/CouponController.php:77`, `:183`), so production rows may well carry a
  positive limit and refuse for the second reason instead, or not at all for
  `fixed product discount`. One read-only query over `coupons` would settle it, and it is an
  environment prerequisite, not an engineering question.
- **The six gateway controllers' coupon record writers were read, not driven.** Three of the six are
  recorded by `docs/security/P01-ORD-02-PARTIAL-PAYMENT-TOTAL-SOURCE.md` section 4 as not drivable
  from the existing fixture at all, and that document is explicit that this is not evidence of
  safety.
- **No MySQL run was made.** Everything about integer-typed money columns is a reading of the
  migration.
