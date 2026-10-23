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

**Three write the posted figure as the order total.** How much of that a *shopper* can arrange is a
separate question, which the first version of this document ran together with the first and got
wrong — see §2a.

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

## 2a. The calibration, corrected — which initiation paths a caller can actually choose a total on

The measurement in §1 drives the **handler** with a state supplied by the test
(`withSession([...])`). That proves what the handler does with such a state. It does **not** prove a
shopper can produce one, and the first version of this document said "MEASURED: a posted
`cartlist_final_price=1.00` wrote `final_price=1.00`" as though it did. A read-only review called
that, and it was right to.

So the question was put to the real route. `POST {slug}/process-order`, signed in as a customer,
posting `cartlist_final_price = 1.00`, inspecting the stored checkout state:

| configuration | stored `cartlist_final_price` |
|---|---|
| partial payments **off**, plain checkout | **100.00** — the server's figure |
| partial payments **on**, plain checkout | **10.00** — the module's deposit |
| partial payments **on**, `partial_payment_type=pending_payment&payment_amount=1.00` | **1.00 — the caller's own figure** |

The first two are `PaymentController::prepareFinalRequestData()` overwriting the key with a server
value before `Session::put()` (`:1802`, `:1826`), which the codebase already records at `:1212`. **So
on the ordinary paths the repair in §2 is defence in depth, not a live fix**, and this document should
not have claimed otherwise.

**The third row is live**, and it is the narrower path the overclaim accidentally described
correctly. All three are now pinned by
`test_what_a_real_initiation_stores_as_the_checkout_total`, so the calibration is executed rather
than asserted in prose.

---

## 2b. The regression this package introduced, and the review that caught it

The first cut of the fix copied each controller's **write-site condition** to the new early block
verbatim. `AuthorizeNetPaymnetController` and `NMIPayController` gate on a **bare**
`module_is_active('PartialPayments')` — and on a route carrying a slug that helper means *"the store
owner's plan bundles the module"* (`app/Helper/helper.php:1359-1381`), **not** "this store turned
partial payments on".

For a store in that state the module does not refuse. Its per-product body is skipped
(`PartialPaymentsController.php:562`), control reaches the `else` at `:660`, and it returns:

```
ManageDeposit for a plan-bundled-but-DISABLED store => {"deposite_amount":"0.00","pending_amount":"0.00",...}
deposite_amount = '0.00'   is_numeric = true
```

`is_numeric("0.00")` is true, so `depositFromModuleAnswer()` accepts it, `$partialPaymentDeposit` is
non-null, the write site's `??` never fires — and the order persists **`final_price = 0.00`** while
the card is charged the real amount. `OrderFinancialService::createForOrder()` then writes that zero
into `store_order_financials` as `paid_amount`.

**That is a money regression introduced by the fix**, on two routed endpoints, for a configuration
that is simply "a merchant whose plan includes the module and who never opened its settings page".

Both blocks now carry the same two limbs the other four already had — the store's
`enable_partial_payment` being on, and a signed-in customer — so `$partialPaymentDeposit` stays null
for such a store and the `??` restores the server figure. The customer limb matters for a second
reason: the module's cart read refuses a guest, so without it **every guest checkout logged "deposit
recomputation failed"**, poisoning the one signal that is supposed to mean a money path could not be
priced.

NMI's fallback was also corrected from `$total_sub_price` (`:459`, the cart subtotal) to
`$final_sub_total_price` (`:474`, `$serverPayableAmount` — the payable the card was actually
charged), which is the figure the pre-change expression carried on that path.

**Five more findings from the same review, all acted on:** a false statement in NMI's docblock
(`$total_sub_price` *is* assigned in that one file); a `Log::shouldNotHaveReceived()` expectation that
could never match because Mockery matches its second argument by arity, so the `never()` was
vacuous; a dead `RETURNS` constant; a citation slip; and — found by a removal check afterwards — a
census keyed on the *spelling* of the customer guard, which the write site's own copy satisfied, so
dropping it from the early block survived. The census is now one regex over the whole
`if (...) { $partialPaymentDeposit =` construct.

The review also verified and recorded useful negatives: the `$request->merge()` of server components
has **no** later consumer in any of the six (traced key by key, including the module's own
`$request['status'] = false`); every local used at the new call sites is assigned before it, per
file; and `$slug` comes from the stored state in all six, so a store-A deposit on a store-B order is
not reachable.

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
4. **`prepareFinalRequestData()`'s `pending_payment` limb writes `$request->payment_amount` into the
   stored checkout total**, with nothing checking it against the amount actually outstanding —
   measured in §2a. This was the one *live* caller-chosen-total path found. **CLOSED on 2026-10-23
   under the owner's decision 2 — see §5.**
5. **A behavioural question this package leaves open.** Before the fix, a `pending_payment` checkout
   through these six wrote the caller's `payment_amount` as the order total; now it writes the
   module's deposit. Neither is obviously "the outstanding amount", and these six create a *new*
   order rather than settling an existing one, so the flow looks incoherent on them either way. It is
   recorded rather than guessed at. **Also addressed in §5.**

Criterion 2 is closed for the order **total**. Criteria 1 and 3 of P1-ORD-02 — one seam for every
order writer, and the reconciler's exact-decimal assertion across all nine — remain open, and the
warn-only posture recorded at `PaymentController.php:2613-2621` explains why: the pricing branches
build `final_price` from different bases, so a rejecting assertion would fail legitimate orders until
that is unified. That unification is gated on P1-ORD-01, which is an owner decision.


---

## 5. The `pending_payment` limb, closed — owner decision 2, 2026-10-23

> *"The server alone determines the payable amount. The customer must not be able to override it
> through `payment_amount` or any other submitted field. Implement server-authoritative validation
> and ensure the actual gateway charge matches the stored financial records. Reject mismatches
> safely."*

### 5.1 What was wrong, measured on the real route

`POST {slug}/process-order`, signed in as a real customer of the store, PartialPayments active
through the store owner's plan and `enable_partial_payment = on`, against an
`order_partial_payments` row recording an outstanding balance of **40.00**:

| posted | stored, and therefore charged |
|---|---|
| `partial_payment_type=pending_payment`, `payment_amount=1.00` | **1.00** |

`cartlist_final_price` is not a label on the money — it *is* the money. Every gateway arm in
`App\Services\PaymentService` reads that one key for the amount it hands the provider (`:447`,
`:554`, `:841`, `:963`, `:1037` and twenty more), and `GuardsGatewayReturn::serverPayableAmount()`
reads the same key to decide what AuthorizeNet captures.

**Two more defects in the same limb, both found while fixing the first.**

- **The row was never checked for ownership.** `partial_payment_id` travelled with the body into the
  stored checkout state and, on the way back, straight into `PartialPayments::ManagePartialPayment()`
  (`PaymentController`, `PaymentService` and six gateway controllers). That method's entire body is
  `OrderPartialPayments::find($request_data['partial_payment_id'])` followed by
  `payment_status = 'On Hold'` — no `store_id` predicate, no `customer_id` predicate, on a model that
  is in no `config/tenancy.php` `scoped_models` entry, so no global scope narrows it either. One
  shopper could mark **any tenant's** outstanding balance settled by naming its id.
- **`||` where every reading of the intent says `&&`.** All eight sites gated on
  `isset($request->partial_payment_type) || $request->partial_payment_type == 'pending_payment'`.
  `isset()` alone is true for *any* value of a caller-supplied key, so posting
  `partial_payment_type=x` on an ordinary checkout diverted the gateway return into the
  settle-a-balance branch — which answers `redirect()->back()` without writing an order, and whose
  first statement is `find(null)`, a fatal on the next line. Either way the shopper is charged and no
  order row exists.

### 5.2 The fix

`App\Http\Controllers\Concerns\ResolvesPendingPartialPayment` derives the payable from the
`order_partial_payments` row, refusing unless the row is **this store's**, **this signed-in
customer's**, still `payment_status = 'Pending payment'`, and carries a **positive** amount. It
returns `null` rather than a fallback: there is no safe default payable for a balance that cannot be
identified, and charging the full cart instead would simply be a different wrong number.

A posted `payment_amount` that disagrees with the record by more than half a cent **refuses the
checkout outright** rather than being silently corrected. That is the owner's "reject mismatches
safely", and it is also the kinder behaviour: the module's own view posts the row's own figure, so an
honest body always agrees; a disagreement is tampering or a page left open while the balance moved,
and charging a different amount from the one on screen would be a surprise either way.

The `||` became `&&`, and the predicate now reads the **stored state** rather than the live request.
`Request::merge()` only overwrites keys the state *holds*, so an ordinary checkout — whose state
carries no `partial_payment_type` — let a posted one survive the merge and divert the return; by then
the replay claim is consumed and the state forgotten, so the payment stands with no order and no
retry.

Nothing under `packages/workdo/` is modified.

### 5.3 The gateway that could not settle, and was being charged anyway

Found by the independent read-only review of this change, and verified before being acted on.
**Eight** handlers in `app/` read the stored checkout state and write an order. Seven carry the
settle branch. `KhaltiPaymnetController::getOrderPaymentStatus` carries **no `partial_payment`
reference at all** — grepped, zero matches in the file.

So a khalti pending-balance checkout was priced at the outstanding balance *correctly, from the
server record*, khalti charged that figure (`resources/views/payment/khalti.blade.php:16` reads
`cartlist_final_price`), and the return then wrote a **full-price order from the cart** and left the
balance owing. The payable was a server fact and it priced the wrong thing.

Refused **at initiation**, not at the return: by the time the return runs the card has been charged,
so refusing there would take the money and record nothing. A settle branch was deliberately *not*
added to khalti — that would be a new money path on a gateway this environment cannot drive, which is
a worse trade than refusing.

`PendingPaymentGatewayCensusTest` derives both sets from the source at run time and fails if a ninth
stored-state order writer appears, if khalti gains a settle branch while still being refused, or if
the declared list and the constant that does the refusing drift apart.

### 5.4 What this does NOT close, stated so it is not mistaken for closed

**The guard proves ownership, not payment.** It establishes that the balance is this customer's and
still owed. It does **not** establish that it was paid. On AuthorizeNet — where the file's own
docblock records that the handler *is* the charge rather than a return — the settle branch returns
before the capture starts, so a customer can still mark their **own** balance settled without being
charged. The same shape holds on easebuzz, cinetpay and paiementpro;
`docs/security/P01-SECURITY-WORK-QUEUE.md` independently records all three as "verifies payment:
**no**". This is **pre-existing** — the branch sat above verification before this change too — and it
is not closed here. It belongs with the payment-verification work, not with the payable's source.

**The settle flow may already be broken upstream.** The module's settle form posts no cart, and
`getCartData()` refuses an empty basket before the limb is reached, so the `pending_payment` limb is
only reachable when the shopper *also* has items in their cart. The journey this change preserves may
therefore not be the production journey. One measurement against production data would settle it; it
is an environment prerequisite, not an engineering question.

### 5.5 Evidence

| | |
|---|---|
| behaviour | `tests/Feature/Commerce/PendingPaymentAmountSourceTest.php` — 20 tests |
| census | `tests/Feature/Commerce/PendingPaymentGatewayCensusTest.php` — 5 tests, both sets derived from source |
| engine | `tests/Support/Security/bin/verify-pending-partial-payment-lookup-on-mysql.php` — 27/27 assertions on MySQL 8.0.46 |
| removal checks | 17 mutants: 16 bite in the suite; the 17th (relaxing `FILTER_VALIDATE_INT`) is invisible on SQLite and is closed by the MySQL script |

**Why the seventeenth mutant needs a script.** `order_partial_payments.id` is BIGINT: MySQL coerces
`'5abc'` in `WHERE id = ?` and returns **row 5**, while SQLite returns nothing. And
`customer_id` is declared `string()` while the module inserts an integer into it — Laravel binds an
integer as `PDO::PARAM_INT`, so MySQL converts the column to a number and `->where('customer_id', 7)`
matched rows holding `'7'`, `'7abc'`, `' 7'` **and** `'07'`, where SQLite matched only `'7'`. A
SQL-side ownership predicate is therefore unsound on the engine that matters and well behaved on the
engine the suite runs — the worst combination available — which is why the customer comparison is
made in PHP with the same `filter_var()` applied to the input.

### 5.6 Before deploying: one production measurement

Rows that can no longer be settled through this path are rows the guard cannot identify. If any
exist, they refuse permanently rather than silently taking a wrong amount — the right failure, but a
visible one. Read-only, and it is an **environment prerequisite**, not something this session can run:

```sql
SELECT COUNT(*) FROM order_partial_payments
 WHERE payment_status = 'Pending payment'
   AND (store_id IS NULL OR store_id = 0 OR customer_id NOT REGEXP '^[1-9][0-9]*$');
```

If that count is zero, the change is free. If it is not, those rows need their `store_id` and
`customer_id` repaired before the fix ships, and that repair is a data change requiring owner
authorization.
