# P1-ORD-02 criterion 2 — a posted money field reaching a persisted order total

*Written 2026-10-23, from execution. Every number below is a measurement and the command that
produced it is named.*

Issue [#21](https://github.com/fullstackfull/syriastore98/issues/21), acceptance criterion 2: **"A
posted money field cannot reach a persisted total on any writer."**

---

## 1. The defect, and why it is a *second* sighting

Commit `569494d2` ("PartialPayments: recompute the order total server-side") recorded this in its own
words — *"With that module active, the order TOTAL ITSELF was read from the request"* — and repaired
**two** sites: `PaymentService::getProductStatus()` and `PaymentController`.

**It missed six.** `AuthorizeNetPaymnetController`, `CinetPayController`, `EasebuzzController`,
`NMIPayController`, `PaiementProPaymentController` and `PayUPaymentController` each still carried the
pre-repair line verbatim:

```php
$order->final_price = $requests_data['cartlist_final_price'] ?? $total_sub_price;
```

**`$requests_data` is server-*stored*, which is not server-*derived*,** and that distinction is the
whole defect. `PaymentService` stores the checkout state as
`$request->except('attachment', 'payment_receipt')` (`:479`, `:680`, `:703`, `:720`, `:894`) — the
whole POST body of the checkout request, verbatim. Every key the shopper posts at initiation is in the
array the gateway return reads back. The session laundered nothing.

**And in five of the six, `$total_sub_price` was never assigned at all** — grepped, zero assignments
per file — so the `??` limb was an undefined variable: an `ErrorException` and a 500, not a safe
fallback.

### Measured

`tests/Feature/Commerce/PartialPaymentDepositSourceTest.php`, module active, 100.00 basket, the
checkout posting `cartlist_final_price=1.00`:

```
authorizenet   status=EX(Undefined variable $requestNet)   orders=0
cinetpay       status=302   orders=1   final_price=1.0     product_price=100.0
easebuzz       status=302   orders=1   final_price=1.0     product_price=100.0
nmi            status=302   orders=0
paiementpro    status=302   orders=1   final_price=1.0     product_price=100.0
payu           status=EX(DecryptException)                 orders=0
```

**Three write the shopper's figure as the order total.** A shopper pays 1.00 for a 100.00 basket.

Easebuzz carried a **second** posted money field in the same branch —
`$order->delivery_price = $requests_data['shipping_price'] ?? $delivery_price` — the one `569494d2`'s
message also named, repaired on the storefront path and left here.

### Why the suite never saw it

Two independent reasons, both recorded traps:

1. `GatewayReturnTrustBoundaryTest::test_posted_money_cannot_change_the_order_total` drives the
   **guest** arm. This branch sits on the `elseif` after `auth('customers')->user()`, so it is
   *structurally* unreachable from that test — which its own docblock honestly describes as a
   regression fence rather than evidence of a guard.
2. **No WorkDo module loads in any test run** without an `add_on_managers` row, so nothing in the
   suite had ever entered a `module_is_active('PartialPayments')` branch on these controllers.

---

## 2. The fix, and the mistake the first cut made

`App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit` calls the module's own
`ManageDeposit()` — which prices the deposit from `Product` rows and the store's deposit settings —
with the **server-derived** components merged in first, so neither the deposit nor its inputs come
from the caller. Nothing under `packages/workdo/` is modified; the existing module method is called,
exactly as `PaymentService::resolvePartialPaymentDeposit()` already does.

**The first cut put the call at the order's write site, and it silently did nothing.** `ManageDeposit()`
re-reads the basket through `ApiController::cart_list()`, and these controllers **delete the cart rows
before** `new Order()` is reached (`EasebuzzController:678`/`:687` versus `:707`). So it found an empty
basket, fell off the end of its own `if (! empty($products))` and **returned NULL** — no exception,
nothing logged, the order simply charged 100.00 where the module prices 10.00.

```
[trait] updated=NULL                     # called at the write site
ManageDeposit => {"deposite_amount":"10.00","pending_amount":"90.00",...}   # same call, before the loop
```

`PaymentService` gets away with the write-site call only because a previous package **deferred its
cart delete** to after the order commits (`PaymentService.php:2592-2615`); `PaymentController` does the
same. These six still delete first. So the deposit is resolved **before the cart-consuming loop** and
carried down in a local.

That failure mode is the reason the test asserts the module's own **10.00** rather than merely "not
1.00": a test that only refused the posted figure would have passed against the broken first cut.

---

## 3. What was executed

```
php artisan test tests/Feature/Commerce/PartialPaymentDepositSourceTest.php   18 passed (80 assertions)
php artisan test --testsuite=Unit                                            351 passed
php artisan test --testsuite=Contract                                         26 passed
php artisan test --testsuite=Feature                   5549 passed / 2 failed / 17 skipped
php .github/scripts/assert-known-test-outcomes.php                            OK, nothing undeclared
bash .github/scripts/guard-no-workdo-changes.sh                               packages/workdo/ unmodified
```

**Removal checks — 10 mutants, 10 bite**, after closing four gaps that the first run exposed:

| | mutant | outcome |
|---|---|---|
| P1–P3 | each of the three reachable controllers reads the posted total again | BITES |
| P4 | easebuzz reads the posted shipping again | BITES |
| P5 | the resolver is never called | BITES |
| P6 | the resolver does not merge the server components | **SURVIVED**, then BITES |
| P7 | the resolver returns the caller's posted total on failure | **SURVIVED**, then BITES |
| P8 | the resolver accepts a non-numeric deposit | **SURVIVED**, then BITES |
| P9 | the deposit is resolved at the write site, after the cart is gone | BITES |
| P10 | the module-answer guard is dropped | BITES |

The three survivors are the useful record:

- **P6** survived because the default fixture posts none of the component keys, and because *with* a
  per-product deposit rule the module never reads `total_sub_price` at all. The test that bites uses
  **no** per-product rule, so the module takes the limb that returns that subtotal and the posted 1.00
  and the server's 100.00 are the only two possible answers.
- **P7** survived because nothing entered the catch limb. The only configuration that makes
  `ManageDeposit()` throw from a real request — `deposit_selection = pay_deposit` with no
  `deposit_type` row — **crashes these handlers earlier**, at the unguarded
  `PartialPayments::ManagePartialPayment()` call they make around line 300 (`ErrorException:
  Undefined array key "deposit_type" @ PartialPaymentsController.php:623`). Rather than ship an
  untested fallback on a money path, the single module call is behind a named `askModuleForDeposit()`
  so the limb can be driven.
- **P8** survived because the module always answers with a `SetNumber()`-formatted figure, so no
  end-to-end fixture can produce the shape the `is_numeric` guard exists for. It is a type check on a
  money field across a vendored boundary whose contract is not ours — and `ManageDeposit()` has
  already been observed returning `NULL` in this very package — so it was made testable
  (`depositFromModuleAnswer()`) rather than removed.

---

## 4. What is NOT claimed, and the three findings this turned up

**Three of the six are not drivable from this fixture, and that is not evidence they are safe.**
`authorizenet` raises `Undefined variable $requestNet` (`AuthorizeNetPaymnetController.php:394`)
before the order write; `payu` expects an encrypted payload this fixture does not mint; `nmi`
redirects with no order. The line was wrong in all six and is repaired in all six, and the census test
asserts that property over all six files rather than only the ones execution reaches.

Three adjacent faults were found and are **reported rather than fixed**, because each is a different
defect and widening this package would make it unreviewable:

1. **`AuthorizeNetPaymnetController:394` reads an undefined `$requestNet`** — that gateway's return
   path 500s before writing anything.
2. **The unguarded `PartialPayments::ManagePartialPayment()` call around line 300** of these
   controllers crashes the whole return for a store that has `deposit_selection = pay_deposit` and no
   `deposit_type` row — a configuration the settings screen can produce, since it writes the two
   independently.
3. **`$tax_price` in these handlers is read from the stored checkout state**
   (`$tax_price = ! empty($requests_data['tax_price']) ? ... : ''`), so it is posted money too. It is
   a separate surface from the total this criterion names, and it is not closed here.

Criterion 2 is closed for the order **total**. Criteria 1 and 3 of P1-ORD-02 — one seam for every
order writer, and the reconciler's exact-decimal assertion across all nine — remain open, and the
warn-only posture recorded at `PaymentController.php:2613-2621` explains why: the pricing branches
build `final_price` from different bases, so a rejecting assertion would fail legitimate orders until
that is unified. That unification is gated on P1-ORD-01, which is an owner decision.
