# E14 — independent security and financial review of the payment callbacks

**Date:** 2026-10-26
**Authority:** owner approval of 2026-10-26 (D-08) — *"أوافق على تنفيذ مراجعة أمنية ومالية مستقلة
لجميع بوابات الدفع التسع ومسارات Callback المتعلقة بها"*, with the standing instruction
*"حافظ على توافق بوابات الدفع القائمة ولا تغيّر قواعد التسوية أو رد الأموال دون ضرورة مثبتة ومراجعة
مستقلة"* and *"لا تفترض أن Ratchet يقرأ الكود فقط يكفي لإثبات عدم تكرار عملية مالية"*.
**Status:** in progress. Part 1 (below) is complete and verified first-hand. No code has been
changed.

---

## 0. Two corrections to the premise, both measured

**There are 44 configured payment methods, not nine.** `Setting::paymentList()` builds
`$Setting_array[0..43]`, enumerated: `cod`, `bank_transfer`, `other_payment`, `stripe`,
`paystack`, `mercado`, `skrill`, `paymentwall`, `Razorpay`, `paypal`, `flutterwave`, `paytm`,
`mollie`, `coingate`, `Sspay`, `toyyibpay`, `Paytabs`, `iyzipay`, `payfast`, `benefit`,
`cashfree`, `aamarpay`, `telegram`, `whatsapp`, `paytr`, `yookassa`, `Xendit`, `midtrans`,
`Nepalste`, `khalti`, `PayHere`, `AuthorizeNet`, `Tap`, `PhonePe`, `Paddle`, `Paiementpro`,
`FedPay`, `CinetPay`, `SenagePay`, `CyberSource` and four more. Two of those (`telegram`,
`whatsapp`) are notification relays rather than gateways. The review covers the measured
population, not the figure in the request.

**Most "payment gateway" controllers in this tree never touch a customer order.** This is the
more consequential correction. Of the ten controllers reviewed in part 1, **nine write only
`App\Models\PlanOrder`** — the SaaS subscription table, which is Phase 06 scope — and exactly one
contains a customer-`Order` write. Verified by counting `new Order(` and `Order::create(` with a
word boundary (an unbounded grep for `Order::create(` also matches `PlanOrder::create(`, which
inflated a first count and is recorded here because it is the kind of mistake that makes a census
read as complete when it is not):

| Controller | customer-`Order` writes |
|---|---|
| `PaymentWallPaymentController` | 1 |
| Paystack, Paytabs, Paytm, Paytr, Razorpay, SSLCommerz, Skrill, Stripe, Yookassa | **0 each** |

So E14's customer-order surface is **not** "the gateway controllers". It is `PaymentController`,
`PaymentService`, `Api\ApiController`, `Api\DashboardController`, `OrderController`,
`AppliesCartStockChanges` and the handful of gateway controllers that really do write an order —
and that is where parts 2 and 3 of this review are directed.

---

## 1. Part 1 — the 18 providers classified "provider verified"

### What the project already has

Phase 01 built a deliberate fail-closed posture: `config/payments.php` disables plan activation
for **26** providers whose return handlers could not be verified from this environment, each entry
carrying its env key, a reason and re-enable conditions, enforced by
`GuardsPlanActivation::assertPlanActivationEnabled()` (a **403**, never a 401 — correct for the
Flutter client). `PlanPaymentPostureTest` pins the posture in both directions and
`PlanPaymentFailClosedTest` drives each route and asserts the 403, the absent `plan_orders` row and
the unchanged entitlement. A second list, `plan_activation_provider_verified`, holds the **18**
providers that *do* verify with the gateway and are therefore left enabled.

### The measurement

Every one of those 18 markers was read, and two were checked against their code rather than taken
on trust:

- `FlutterwaveController:168` — `if (isset($response['status']) && $response['status'] == 'success')`,
  with `assignPlan()` at `:215` inside it. Matches its marker.
- `PaypalPaymentController:150` — `if (isset($response['status']) && $response['status'] == 'COMPLETED')`.
  Matches its marker.

**17 of the 18 markers describe a check on the PAYMENT STATE** — `'PAID'`, `'COMPLETED'`,
`paid == 1 && captured == 1`, `isSuccessful()`, `isPaid()`, `== 'success'`, `response == 1`,
`"AUTHORIZED"`, `"CAPTURED"`, `getResultCode() == "Ok"`, `$result->success`, `code == '00'`.

**One does not.**

### F87 — `PaystackPaymentController::getPaymentStatus` gates the API envelope, not the payment

Verified first-hand, line by line.

`app/Http/Controllers/PaystackPaymentController.php:150` is the only gate:

```php
if (isset($result['status']) && $result['status'] == true)
```

That is the **envelope** of Paystack's verify response — whether the API call succeeded. The
handler then records the real transaction state three lines into the body:

```php
$order->payment_status = $result['data']['status'];   // :183
```

so the handler demonstrably knows where the payment state lives. **Nothing requires it to be
`success`.** Between the gate at `:150` and `$user->assignPlan($plan->id)` at `:204` the only
conditionals are coupon bookkeeping and the result of `assignPlan` itself — confirmed by listing
every `if (` in that range. The amount is likewise never compared to the plan price; it is simply
recorded as `$result['data']['amount'] / 100` at `:179`.

Three aggravating facts:

1. **No kill switch.** `PaystackPaymentController` does not use `GuardsPlanActivation` and has no
   `config/payments.php` activation entry, so the 403 that protects 26 other providers does not
   apply. It is on the **verified** list.
2. **The reference is caller-chosen.** `{pay_id}` is a route parameter (`routes/web.php:549`) and
   the plan is `Crypt::decrypt($plan)` — which per CLAUDE.md is an encoding, not a control, because
   `APP_KEY` is in git history and unrotated.
3. **No replay guard.** The project owns one — `GuardsPlanActivation::existingPlanOrderForProviderTxn()`
   — and this handler does not call it, so the same reference activates again on every call.

**Scenario.** A merchant begins a real Paystack checkout for the cheapest plan and lets it fail or
abandons it, keeps the reference, then requests
`/plan-paystack-payment/<that reference>/<encrypted expensive plan id>`. Paystack answers the
verify call successfully — envelope `status: true` — carrying `data.status` of `failed` or
`abandoned`. A `plan_orders` row is written with `payment_status = 'failed'` and **the expensive
plan is activated**, repeatably.

**Confidence, stated separately because the two halves differ.**
*Certain* — the handler never tests `data.status`, never compares the amount, has no activation
guard and no replay guard. That is structural and readable in the file.
*Not executed* — that Paystack's envelope really is `true` for a failed transaction. It is
Paystack's documented contract (`status` describes the API call, `data.status` the transaction) but
this environment makes no outbound call to Paystack and this review did not fabricate one. The
structural half is sufficient on its own: a gate that ignores the field the handler itself records
as the payment status is wrong whatever the provider returns.

### F88 — the posture test asserts that a marker EXISTS, not that it is adequate

The more durable finding, and the reason F87 survived a dedicated security pass.

`PlanPaymentPostureTest::test_every_verified_provider_is_recorded_with_its_verification_marker()`
requires each verified provider to carry a marker. Paystack carries one — and the marker
**accurately describes an inadequate check**:

```
'PaystackPaymentController' => "outbound GET api.paystack.co/transaction/verify/{ref} then $result['status'] == true"
```

So the test passes, the provider is documented, and the classification is still wrong. The
classification conflates *"calls the gateway"* with *"checks that the payment succeeded"*. This is
the shape CLAUDE.md warns about — a test that passes for the wrong reason — and it is the one
finding here that is not about a single provider: the same marker list would absorb the next weak
gate just as quietly.

### Why neither was fixed

Both sit in **plan activation**, which is **Phase 06** scope, and the owner's scope precedence is
explicit that Phase 06 must not be started inside Phase 02. Beyond scope, each candidate fix is a
decision rather than a correction:

- **Requiring `data.status === 'success'`** changes what a payment callback does. It is a one-line
  change and it is almost certainly right, but it is a payment-gate behaviour change and the
  standing limits reserve those.
- **Moving Paystack onto the fail-closed map** uses machinery that already exists and needs no new
  code — but it makes Paystack plan purchases return 403, which breaks the flow for any merchant
  actually buying a subscription through Paystack. That is a commercial impact, not a cleanup.

So the finding is reported with the evidence and the two options, and nothing is changed. See
**decision point E** in `PHASE-02-DECISION-REGISTER.md` (D-12).

### Verified clean, stated positively

- The other **17** verified providers gate on the payment state, and the two spot-checked against
  their code match their markers exactly.
- The **26-provider fail-closed posture is real**, documented per entry, and driven by
  `PlanPaymentFailClosedTest` rather than asserted.
- **No handler in part 1 reads a customer id, order id or store id from request input to decide
  ownership of a customer order**, because none of them touches a customer order. The codebase's
  most productive defect shape — `if (! auth('customers')->user()) { $id = $request->some_id; }` —
  does not appear in this part at all: these are `web`-guard session routes behind
  `['auth','xss','setlocate','verified']` (`routes/web.php:400`), so no unauthenticated caller, and
  no `Customer` or `DeliveryBoy` token, can reach any of them.
- `PaymentWallPaymentController:171` does build a customer `Order` for a **plan** sale, which would
  be a real contamination of the storefront orders table — but it cannot occur. Three independent
  blockers: the provider is fail-closed by default; the `Paymentwall_Charge` class is not installed;
  and **none of the eleven attributes it sets is a column of `orders`** — measured:
  `plan_name`, `plan_id`, `price`, `txn_id`, `user_id`, `card_number`, `receipt` all **ABSENT**, so
  `save()` raises rather than writes. (Measured against the migration-built schema. `orders` is a
  managed table so production should match, but CLAUDE.md warns the migrations are not the schema;
  the check to settle it in production is `Schema::hasColumn('orders', 'plan_name')`.) This
  **downgrades** the implication at `config/payments.php:455-456` that the handler writes.

### A trap for parts 2 and 3, recorded now

Four handlers mint their order reference as `$orderID = time()` — a **one-second** resolution. Two
invocations inside the same wall-clock second collide on the unique `plan_orders.order_id` index,
and the resulting `QueryException` is swallowed by a surrounding `catch (\Exception)`, producing a
redirect. **A replay test that fires two requests back-to-back will therefore see the second one
fail and may read that as idempotency.** It is not: wait one second and both succeed. Any replay
test written for this review must space its requests, or it will manufacture exactly the false
green the owner warned against.

The same hazard applies to the customer-order side: `product_order_id` is
`$customerId . date('YmdHis')` — also one-second resolution — and `orders.product_order_id` is
`string NULLABLE` with **no unique index** (`2024_01_23_061635_create_orders_table.php:16`), so
there the collision does not even fail; it silently produces two orders sharing a reference.

---

## 2. Parts 2 and 3 — the customer-order writers

Three independent censuses covered 26 gateway controllers. The result reframes the review again,
and it converges from two directions, which is why it is stated as a finding rather than a list.

### Which controllers actually write a customer order

Of the 26, **seven** contain a live customer-`Order` writer:

| Controller | Handler | Route | Reachable unauthenticated |
|---|---|---|---|
| `AuthorizeNetPaymnetController` | `getOrderPaymentStatus` | `ANY {slug}/authorizenet-status` | **yes** |
| `CinetPayController` | `getProductStatus` | `ANY {slug}/get-payment-cinetPay` | **yes** |
| `EasebuzzController` | `getProductStatus` | `ANY {slug}/get-payment-easebuzz` | **yes**, and CSRF-exempt |
| `KhaltiPaymnetController` | `getOrderPaymentStatus` | `POST {slug}/order-khalti` | **yes** (CSRF token needed) |
| `PaiementProPaymentController` | `getProductStatus` | `ANY {slug}/get-payment-paiementpro` | **yes** |
| `NMIPayController` | `getNMIProductStatus` | `ANY {slug}/get-payment-NMI` | **yes** — and it is the CHARGE, not a return |
| `CyberSourceController` | `storePayWithCyberSourceData` | `ANY store-get-cybersource-status` | **yes**, writes no order but hands off to the writer |

`routes/customer.php` is required at `routes/web.php:1469`, **after** the
`['auth','xss','setlocate','verified']` group closes, inside `Route::middleware(['web'])`. Its
payment routes sit in `Route::middleware([ActiveTheme::class, 'themelanguage'])`, and `ActiveTheme`
authenticates nothing. The web group is **unthrottled**. So all of these are anonymous surfaces.

The remaining 19 write only `PlanOrder` — SaaS subscriptions, Phase 06 — which is the single
biggest correction this review makes to the premise.

### The cross-cutting finding: the money axis is defended, the STOCK axis is not

Every live writer has a **replay guard** — `claimGatewayReturn()`, a `Cache::add()` insert-if-absent
on the checkout nonce stamped by `PaymentService::stampCheckoutNonce`. So the owner's first
question, replay resistance, is largely answered *yes*, and the grep-level hypothesis "no
idempotency guards anywhere" was wrong for every one of them. Khalti and CinetPay additionally key
on a gateway reference.

And the money axis holds up better than expected, by a default that was deliberately inverted in
Phase 01: `PaymentVerificationService::resolveOrderPaymentStatus()` returns **`Pending`** when no
verification record exists. Since nothing records a verification for easebuzz, cinetpay or
paiementpro, an order written on those paths is `Pending`, so `OrderObserver` credits no wallet.

**But stock moves before, and independently of, any of that.** Each writer decrements stock inside
its cart loop, and on several of them the decrement happens with no payment evidence at all and is
never given back:

| Writer | Stock primitive | Give-back on failure | Payment evidence before the decrement |
|---|---|---|---|
| `NMIPayController` | `decrementStockOrFail` (atomic, `AppliesCartStockChanges`) | **yes** — `restoreStockDecrements` on every mid-loop exit | the charge is in this request |
| `KhaltiPaymnetController` | inline read-modify-write | **no** | **none** — the verify call comes *after* the loop |
| `PaiementProPaymentController` | inline read-modify-write | **no** | **none at all** — no gateway call exists on the path |
| `CinetPayController` | inline read-modify-write | **no** | `$request->responsecode == 0` — a request field, and `null == 0` is true in PHP 8 |
| `EasebuzzController` | inline read-modify-write | **no** | **none** — no status field, no hash check, no gateway call |
| `AuthorizeNetPaymnetController` | inline read-modify-write | **no** | the charge is in this request |

So on Khalti, PaiementPro, CinetPay and Easebuzz an anonymous caller who initiates a checkout and
then calls the return route **consumes inventory without paying**. The replay guard stops the same
return being replayed; it does not stop a *new* checkout, and nothing throttles the route. The
order that results is `Pending`, so this is **denial of inventory and order-hygiene damage rather
than free paid goods** — an important bound, and the reason this is reported as one finding with a
measured ceiling rather than six separate catastrophes.

**One liveness dependency, stated because it may make two of them latent.** Khalti and PaiementPro
assign `$plan` only inside `if ($user->type == 'admin')` and then dereference `$plan->shipping_method`
unguarded, *before* the stock loop. If the platform's first `admin` row has no resolvable plan both
handlers 500 before any stock moves. NMI repaired exactly this (`:388`, `?? 'off'`); these two did
not. Whether production resolves a plan here is **NOT ESTABLISHED** from the repository and needs
one query. It should be settled before either is escalated as live.

### F89 — fixed: a charged card ended in a 500 for any variant product

The one defect in this review that was **driven, fixed and revert-checked**, because it needed no
decision: it is a crash, not a rule.

`NMIPayController:1328` read `$product->variant_id` — object syntax on an array. This handler's
basket is `storedCheckoutCartList()`, i.e. `json_decode(json_encode($cartlist), true)`, and the
sibling line in the same loop correctly reads `$product['product_id']`. So `$variant_data` was
null and the next line raised, which Laravel promotes to an `ErrorException` — **after
`chargeSale()` captured and after `$order->save()` committed**. The shopper is debited, the order
exists, and they get a stack trace instead of the thank-you page: no `OrderCompleteBinding::remember`,
no reward-points event. A shopper who sees a server error on a successful charge is likely to pay
again.

Reproduced as **HTTP 500 with the capture recorded** (`$gateway->calls` = 1), then fixed, then
revert-checked. Two limits recorded rather than glossed:

- **Only the accessor is proven.** Reverting `['variant_id']` fails the new case; reverting the
  `?? ''` null guard alone does not, so the guard is hardening this suite does not exercise.
- **The siblings are not affected**, and the bare grep says otherwise. CinetPay, Easebuzz,
  AuthorizeNet and Khalti read `$product->variant_id` too, but their basket is
  `(array) $cartlist_response->getData()->data` — and that cast is **shallow**, so `product_list`
  stays an array of stdClass and object syntax is right for them. F89 is a **one-file regression**
  from NMI's move to the array-returning stored basket, not a shared defect class.

**Why the suite could not see it.** `UnguardedOrderWriterTrustBoundaryTest` already carried a
docblock titled *"THREE WAYS A CHARGED CARD USED TO END IN A 500"* and a `!empty()` sweep eighty
lines above the bug describing this exact family — *"promoted to an ErrorException AFTER
`$order->save()` has committed"*. It missed this instance because the spelling differed: the three
it fixed were missing array keys, this one is object syntax on an array. And no fixture could reach
it: the OrderNote loop branches on the **product's** `variant_product`, and `productIn()` hardcodes
`variant_product => 0`. The existing `'variant line whose variant row is gone'` case sets the
*line's* `variant_id` but leaves the product non-variant, and is refused before the loop. A fourth
case with a real variant product now covers it.

### Findings recorded and NOT acted on, with the reason

| | Finding | Why not acted on |
|---|---|---|
| **F87** | Paystack gates the API envelope, not the payment; no kill switch, no replay guard | plan activation = Phase 06; both candidate fixes are decisions (D-12) |
| **F88** | the posture test asserts a marker exists, not that it is adequate | same decision; it is the durable half |
| **F90** | Khalti/PaiementPro/CinetPay/Easebuzz consume stock with no payment evidence and never give it back | the fix is either moving the stock loop below verification or adopting `AppliesCartStockChanges` — both change what a payment path does to stock, which is reserved |
| **F91** | `CyberSourceController:263` takes `$data['store_id']` from request input and uses it to select **which merchant's gateway credentials sign the charge** (`Utility::GetValueByName(..., $id)` → `Setting::where('store_id', $callerSupplied)`) | the classic caller-supplied tenant predicate, on credentials. Bounded today: the endpoint host is hardcoded to `apitest.cybersource.com` (sandbox) at `:272`, `:308`, `:315`, so no live capture occurs — which also means a genuine CyberSource shopper is never charged yet still reaches the order writer. Fixing it means deciding what CyberSource should do at all |
| **F92** | AuthorizeNet captures `serverPayableAmount()` but writes `final_price` from a **fresh** cart read, and `'authorizenet'` is absent from `ENFORCED_PAYMENT_TYPES`, so the two are never compared | one-line fix (enrol it), but it changes whether a payment is accepted — reserved. Not drivable here: it needs a real approved capture |
| **F93** | three stale comments state the status derivation "returns exactly the `Paid` the literal did"; the default was inverted to `Pending` | comment-only, but it would mislead the next reviewer; safe to fix and queued |
| **F94** | `PayUPaymentController::getProductStatus` is a complete ~580-line unguarded order writer, **unrouted** | latent; it would also fatal on six unimported classes. Right disposition is deletion, already concluded elsewhere |

---

## 3. Still to do

| Part | Scope | State |
|---|---|---|
| 1 | the 18 "provider verified" markers, and 10 controllers | **done** — F87, F88 |
| 2 | the remaining gateway controllers (26 censused in all) | **done** — F89 (fixed), F90, F91, F92, F93, F94 |
| 3 | `PaymentController` — the storefront's main path | **done** — F95, F96; `getProductStatus` and Paymera verified GUARDED |
| 3b | `PaymentService`, `Api\ApiController`, `Api\DashboardController`, `OrderController` | not started |
| 4 | **driven** replay tests, with the `time()` spacing trap respected, plus concurrency | partly — F89 driven and fixed; F96 needs MySQL + `CACHE_STORE=database` |

---

## 4. Part 3 — `PaymentController`, the storefront's main path

Three order-writing entry points, and they do not share a posture. A per-file verdict would be
misleading, so they are reported separately.

### `getProductStatus` — the shared return URL for about twenty payment types — **GUARDED**

`routes/customer.php:186`, `Route::any {storeSlug?}/get-payment-status`, anonymous. This is the
single return/callback/notify URL that `PaymentService` hands to roughly twenty gateways, so it is
the highest-traffic callback in the product. Three layers, all above `new Order()`:

1. `:2151` `storedCheckoutState($request, 'storefront-return')` with a refusal when it is null —
   **the `$request->all()` fallback is gone**, so a caller with no session writes nothing.
2. `:2247` `claimGatewayReturn()` — `Cache::add()`, an atomic insert-if-absent on the checkout
   nonce. Production runs `CACHE_STORE=database` with a `cache_locks` table, so the add really is
   atomic and this covers the **overlapping** case.
3. `:2262` `Session::forget('request_data')` makes the stored state single-use, covering the
   **sequential** case (refresh, back button).

**Residual, exact:** `gatewayTransactionReference()` returns a reference only for khalti and
cinetpay, so for `'storefront-return'` the nonce is the only key — and `claimGatewayReturn()`
**fails open** when the stored state carries no nonce. `PaymentService:317` stamps one on every web
checkout, so the open window is states written before that shipped, and it is logged.

### `handlePaymeraCallback` — the first-party gateway — **GUARDED, with F96**

The reference implementation for the rest of this review, and the one path with inbound signature
verification. Its one weakness is the lock's duration, not its logic — see **F96**: the
10-second TTL is held across the writer's SMTP and SMS calls, and the project's own comment at
`:2878-2886` establishes the premise by refusing to hold *database* locks across those same calls.

**F97**, found alongside it, is two defects in one. `PaymentController:1055` asserts in prose that
the `store/paymera/trigger/{orderId}` route is *"anonymous and CSRF-exempt"*, and the handler's
P1-ORD-13 reasoning is built on that premise. The CSRF half is right; the **anonymous** half is
wrong. The route is declared at `routes/web.php:514`, which is **inside** the
`['auth','xss','setlocate','verified']` group opened at `:400` and closed at `:1434` — confirmed
against both boundaries and against `route:list`. So Paymera's server-to-server poke arrives
unauthenticated, is redirected to `/login`, and never reaches the handler.

As documentation that is the **F93** family, and here it also underpins a security argument: the
handler's anonymous-caller reasoning is correct as written but defends a door nobody can open. As
behaviour it is the larger half — one of the two routed Paymera entry points is **inert for its
intended caller**, so the gateway's confirmation arrives only if the buyer's browser completes the
GET callback. There is no server-to-server fallback when the buyer closes the tab. Not changed:
moving a payment route out of the `auth` group converts it from authenticated to anonymous, which
is exactly the kind of payment-path change the standing limits reserve, and the right remedy may be
a signed route rather than plain anonymity. Carried on D-13.

**The method note worth keeping:** this was found by checking the prose against `route:list`, not
against the route file alone. The declaration's indentation gives no hint that it sits 900 lines
inside a middleware group.

### `whatsapp` — serving `{slug}/whatsapp` and `{slug}/telegram` — **NO replay guard**

And this is the one where careful reading changes the answer, so it is set out in full as **F95**.

Verified by reading all 903 lines: no `claimGatewayReturn`, no `Cache::lock`, no `Cache::add`, no
lookup of an existing order. The only thing stopping a sequential resubmit is **incidental** — the
cart rows were deleted after the first commit, so the second attempt answers `'Cart is empty.'`
Two *concurrent* posts both read a non-empty cart before either delete runs: two orders, two stock
decrements.

**But most of what looks alarming is decided design, and saying so is the point.** The
`$request->all()` fallback exists because WhatsApp/Telegram intake must accept a post with no prior
session — so a nonce-based replay guard **cannot** exist on this path. The fallback arm is already
adversarially hardened: the route slug beats the body's, the caller's `shipping_final_price` is
zeroed, and ownership comes from `auth('customers')` or the server's stored state and never the
body. And a whatsapp order being **`Paid`** is an **owner decision** —
`PaymentVerificationService::OFFLINE_PAYMENT_TYPES` means "settles off-platform by arrangement, so
record Paid at once", governed by D-06, whose own comment is explicit that the no-unverified-Paid
rule covers **gateway** payments and an off-gateway arrangement is not one.

So the finding is narrow and real: **an anonymous, unthrottled, un-deduplicated order intake whose
orders are recorded Paid and whose stock moves.** The evidence that this is an oversight rather
than a position is the route file itself — `routes/customer.php:183`, `POST {slug}/get-massage`,
which only builds a WhatsApp URL and **writes nothing**, carries
`->middleware('throttle:storefront-relay')`; `:184` and `:185`, which write an order and decrement
stock, carry no throttle. The throttle went on the neighbour.

The fix is one `->middleware('throttle:...')` and the named limiters already exist, but choosing
the rate needs traffic data this environment does not have, and a limit set too low returns 429 on
a legitimate checkout. **The owner sets the rate.**

### A correction to this review's own method, recorded

The workflow that censused these files returned a headline of "0 guarded, 18 unguarded". Its own
per-file detail contradicts it — it describes `getProductStatus` as guarded in three layers and
Paymera as guarded — because the per-file verdict marked a whole file unguarded if **any** entry
point was. That headline, taken at face value, would have reported the best-guarded path in the
codebase as unguarded. Agent output is a claim to verify; every verdict above was re-read
first-hand.

Part 3 is where the owner's actual question lives, and part 4 is the owner's explicit condition
that reading the code is not proof. One reference point already established first-hand:
`PaymentController::handlePaymeraCallback` — the first-party Syrian gateway — **is** hardened, with
a `processed` flag on the pending record, a `Cache::lock`, a **re-check inside the lock**, fail-closed
status polling, amount and signature verification before the order is created, and
`OrderCompleteBinding` so a replay cannot disclose the buyer's order to a stranger. It is the
pattern parts 3 and 4 should measure the others against.

**A method note worth keeping.** A grep for `idempoten|txn_id|transaction_id` across the payment
controllers returns nothing, and on that basis this review's first pass recorded "zero idempotency
guards anywhere". That was wrong: Paymera's guard is a `processed` flag inside a cached pending
record and a lock, which no such grep would find. **A grep is a hypothesis, not a finding** —
the handler has to be read.
