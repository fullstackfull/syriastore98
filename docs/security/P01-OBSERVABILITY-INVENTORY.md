# P01 — security and financial observability: what is logged, where it goes, and who sees it

> **The `file:line` pointers below move whenever code above them does.**
> `tests/Feature/Security/SecurityLogInventoryTest` fails when they drift, which is the
> intended behaviour rather than a false positive. To repair them:
> `python3 docs/development/phase-1/tools/resync-observability-inventory.py --write`.
> It re-points rows by (file, message) and refuses to write when a log line was added or
> deleted rather than moved -- that is a real inventory change, and a row needs a stated
> destination and reader, which only a person can give it.

**P1-PLT-05.** Phase 01 added a large number of refusal and anomaly log lines across the order,
payment, refund, stock and tenancy paths. Each was added with its own package and justified there.
Nobody had ever asked the next question: **when one of them fires in production, where does it
land and who reads it?**

This document answers that, and every claim in it was executed rather than read off a config file.
Three things follow, and the third is the one that matters most.

---

## 1. The inventory

`tests/Feature/Security/SecurityLogInventoryTest.php` regenerates the census below from `app/` and
fails if this document and the source disagree in **either** direction — a line added to `app/` and
not listed here, or a line listed here that no longer exists. So this is a ratchet, not a snapshot
that silently rots.

The two headings below carry their own row counts. They are **not** asserted by the ratchet, which
compares the table against `app/` row by row -- so the `warning` count had drifted to 111 against 116
actual rows before this was noticed at closure. Corrected to 117; if you add a row, fix the heading.

Only `critical` and `warning` are enumerated. They are the levels Phase 01 used for *a control
fired* or *something happened that a human should look at*. `error` (95 call sites) is almost
entirely pre-existing third-party-integration failure reporting, `info` (22) and `debug` (1) are
diagnostics; none of them is a security signal and enumerating them would bury the ones that are.


### `critical` — 17 lines

| line | message |
|---|---|
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:115` | Insufficient stock on an order path that cannot refuse; order kept, stock oversold |
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:164` | Stock compensation failed: inventory was consumed and could not be returned. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:954` | Gateway return gave up after the capture was classified: |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:966` | Gateway return refused: the basket names a product or variant row that no longer exists. |
| `app/Http/Controllers/KhaltiPaymnetController.php:1349` | Khalti order written for RECONCILIATION: the gateway could not be asked whether money moved |
| `app/Http/Controllers/PaymentController.php:1376` | Paymera write threw and a committed order cannot be ruled out; the claim is kept so no replay can write a second order |
| `app/Http/Controllers/PaymentController.php:469` | Paymera status poll ABANDONED: no API credential is configured |
| `app/Http/Controllers/PaymentController.php:580` | Paymera callback REJECTED: the payment status could not be established |
| `app/Http/Controllers/PaymentController.php:808` | Paymera write threw and a committed order cannot be ruled out; the claim is kept so no replay can write a second order |
| `app/Http/Controllers/PaymentController.php:922` | Paymera callback REJECTED: inbound signature not verified |
| `app/Http/Controllers/PaymentController.php:951` | Paymera callback REJECTED: captured amount not verified |
| `app/Http/Controllers/PaymentController.php:3395` | Order write failed and was rolled back; stock decrements were given back |
| `app/Http/Controllers/PaymentController.php:4786` | Order write failed and was rolled back; stock decrements were given back |
| `app/Services/PaymentService.php:187` | Insufficient stock on an order path that cannot refuse; order kept, stock oversold |
| `app/Services/PaymentService.php:3021` | Order write failed and was rolled back; stock decrements were given back |
| `app/Services/ProductStockService.php:127` | Stock decremented below zero on an order path that cannot refuse |
| `app/Services/ProductStockService.php:210` | Stock decremented below zero on an order path that cannot refuse |

### `warning` — 126 lines

| line | message |
|---|---|
| `app/Http/Controllers/Api/ApiController.php:129` | Coupon refused: the basket could not be priced |
| `app/Http/Controllers/Api/ApiController.php:3432` | order: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:6950` | order: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:7041` | coupon: refused an implausible discounted total |
| `app/Http/Controllers/Api/ApiController.php:7817` | payment_sheet refused: basket names a product from another store |
| `app/Http/Controllers/Api/ApiController.php:7843` | payment_sheet: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:8453` | order: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:8578` | coupon: refused an implausible discounted total |
| `app/Http/Controllers/Api/AuthController.php:186` | register: social identity verification refused |
| `app/Http/Controllers/Api/DeliverySyriaOrderController.php:82` | Delivery Syria invalid credentials |
| `app/Http/Controllers/AuthorizeNetPaymnetController.php:339` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/AuthorizeNetPaymnetController.php:381` | AuthorizeNet capture refused: no server-computed payable in the stored state. |
| `app/Http/Controllers/CartController.php:222` | product_cart: refused a non-positive or non-integer quantity |
| `app/Http/Controllers/CartController.php:275` | product_cart: refused a product belonging to another store |
| `app/Http/Controllers/CinetPayController.php:516` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:133` | Gateway return refused: no server-stored checkout state. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:282` | Gateway return carries no claimable identity; falling back to the session control alone. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:296` | Gateway return refused: already claimed. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:1035` | Gateway return refused: basket names a product from another store. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:1071` | Gateway return refused: the gateway request could not be built. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:1088` | Gateway return refused: basket holds a non-positive quantity. |
| `app/Http/Controllers/Concerns/GuardsPlanActivation.php:85` | Plan activation refused: provider is disabled pending verification. |
| `app/Http/Controllers/Concerns/ResolvesPendingPartialPayment.php:135` | Pending partial payment lookup failed; the payment is refused. |
| `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:716` | customer login: refused a social row still holding its module-assigned password |
| `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:941` | Newsletter subscription failed during customer registration |
| `app/Http/Controllers/DeliverySyriaStatusController.php:179` | Zid store not found for order status sync. |
| `app/Http/Controllers/DeliverySyriaStatusController.php:95` | Delivery Syria status webhook: invalid credentials. |
| `app/Http/Controllers/ESewaPaymentController.php:196` | Plan payment return rejected: claimed amount does not match the plan price. |
| `app/Http/Controllers/EasebuzzController.php:386` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/KhaltiPaymnetController.php:255` | Khalti plan purchase NOT activated: the capture was not confirmed for the server price |
| `app/Http/Controllers/KhaltiPaymnetController.php:452` | Khalti verification refused: no server-computed payable in the stored state. |
| `app/Http/Controllers/KhaltiPaymnetController.php:497` | Khalti order refused before any stock moved: the gateway rejected the capture |
| `app/Http/Controllers/NMIPayController.php:428` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/OrderController.php:1308` | Return approval restored no stock: order carries no readable product_json |
| `app/Http/Controllers/OrderController.php:1354` | Return approval skipped a line whose stock was already credited |
| `app/Http/Controllers/OrderController.php:1374` | Return approval skipped a line with an unusable product id or quantity |
| `app/Http/Controllers/OrderController.php:1395` | Return approval skipped a line whose product is not in the order |
| `app/Http/Controllers/OrderController.php:1422` | Return approval skipped a line whose variant does not belong to its product |
| `app/Http/Controllers/OrderController.php:1768` | Order cancellation skipped a line with an unusable product id or quantity |
| `app/Http/Controllers/OrderController.php:1783` | Order cancellation skipped a line whose product is not in the order |
| `app/Http/Controllers/OrderController.php:1805` | Order cancellation skipped a line whose variant does not belong to its product |
| `app/Http/Controllers/OrderController.php:1974` | Order assignment refused: the delivery boy is not in a store this caller owns |
| `app/Http/Controllers/OrderController.php:322` | Manual order total did not match its components; the computed total was stored |
| `app/Http/Controllers/OrderRefundController.php:1167` | Refund settled but the order totals were left unchanged: product_json is unparseable |
| `app/Http/Controllers/OrderRefundController.php:180` | Refund transition refused: unrecognised current state |
| `app/Http/Controllers/OrderRefundController.php:202` | Refund transition refused: illegal transition |
| `app/Http/Controllers/OrderRefundController.php:416` | Refund cancellation refused: the record does not match the order |
| `app/Http/Controllers/OrderRefundController.php:749` | Refund restock skipped a line with an unusable quantity |
| `app/Http/Controllers/OrderRefundController.php:765` | Refund restock clamped to the units the order lines lost |
| `app/Http/Controllers/OzowController.php:217` | Ozow plan return refused: no TransactionId to key on. |
| `app/Http/Controllers/PaiementProPaymentController.php:356` | PaiementPro return recorded PENDING: this gateway is not provider-verified |
| `app/Http/Controllers/PaiementProPaymentController.php:469` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/PayHerePaymnetController.php:64` | PayHere plan checkout refused: the integration is retired (owner decision). |
| `app/Http/Controllers/PayHerePaymnetController.php:76` | PayHere plan checkout refused: the PayHere SDK is not installed. |
| `app/Http/Controllers/PayUPaymentController.php:287` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/PaymentController.php:1362` | Paymera trigger could not identify the order it created; the pending record names none |
| `app/Http/Controllers/PaymentController.php:1287` | Paymera trigger refused: this payment is already being written. |
| `app/Http/Controllers/PaymentController.php:1570` | checkout: refused, basket names a product from another store |
| `app/Http/Controllers/PaymentController.php:1610` | checkout: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/PaymentController.php:2264` | Checkout refused: this payment method cannot settle an outstanding balance. |
| `app/Http/Controllers/PaymentController.php:2281` | Checkout refused: no recorded balance matches this pending payment. |
| `app/Http/Controllers/PaymentController.php:2299` | Checkout refused: the posted pending-payment amount disagrees with the record. |
| `app/Http/Controllers/PaymentController.php:2615` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/PaymentController.php:3789` | Telegram order message not sent: the store has no bot token or chat id |
| `app/Http/Controllers/PaymentController.php:3805` | Telegram refused an order message |
| `app/Http/Controllers/PaymentController.php:3811` | Telegram order message could not be sent |
| `app/Http/Controllers/PaymentController.php:3879` | Whatsapp order writer: posted slug does not match the route slug; the route wins. |
| `app/Http/Controllers/PaymentController.php:3995` | Order intake refused: this basket is already being written. |
| `app/Http/Controllers/PaymentController.php:550` | Paymera replay refused: the session presenting the reference did not place the order |
| `app/Http/Controllers/PaymentController.php:693` | Paymera callback refused: this payment is already being written. |
| `app/Http/Controllers/PaymentController.php:775` | Paymera callback could not identify the order it created; the pending record names none |
| `app/Http/Controllers/PaymentController.php:867` | Paymera pending record vanished before the order was created |
| `app/Http/Controllers/PaymentController.php:937` | Paymera callback signature NOT verified and not enforced |
| `app/Http/Controllers/PaystackPaymentController.php:301` | Paystack plan activation refused: the transaction is not a matching success. |
| `app/Http/Controllers/PaystackPaymentController.php:388` | Paystack plan activation refused: this reference is already recorded against a different buyer or plan. |
| `app/Http/Controllers/PaystackPaymentController.php:455` | Paystack plan activation refused: the captured payment does not match the plan being activated. |
| `app/Http/Controllers/PosController.php:246` | POS sale skipped a line whose product is not this store |
| `app/Http/Controllers/SavedViewController.php:117` | Saved view apply refused: not the owner or no such view |
| `app/Http/Controllers/SavedViewController.php:136` | Saved view delete refused: not the owner or no such view |
| `app/Http/Controllers/SenangPayController.php:140` | SenangPay plan return refused: the return hash could not be verified. |
| `app/Http/Controllers/SenangPayController.php:150` | SenangPay plan return refused: return hash mismatch. |
| `app/Http/Controllers/SofortController.php:106` | Sofort return rejected: plaintext plan parameter disagrees with the signed token. |
| `app/Http/Controllers/SofortController.php:233` | Plan payment return rejected: claimed amount does not match the plan price. |
| `app/Http/Controllers/ZidOAuthController.php:31` | DS exchange failed |
| `app/Http/Middleware/ActiveTheme.php:35` | Storefront visit log failed |
| `app/Http/Middleware/FlashedMessageSafety.php:74` | an exception object was flashed to a session and was replaced before it could be rendered |
| `app/Http/Middleware/LotteryDrawOwnershipGuard.php:260` | lottery draw refused |
| `app/Http/Middleware/ModuleOrderMutationGuard.php:378` | module order mutation refused |
| `app/Http/Middleware/ModuleResourceOwnershipGuard.php:374` | module resource mutation refused |
| `app/Models/Order.php:906` | Order return refused: the order carries no readable product_json |
| `app/Models/Order.php:917` | Order return refused: the order lines are not in a shape this workflow can return |
| `app/Models/Utility.php:2174` | New Order webhook skipped: no store could be resolved for the order |
| `app/Models/Utility.php:2184` | New Order webhook skipped: the resolved store does not exist |
| `app/Providers/PackageServiceProvider.php:144` | addon loader: a module failed to load and its features are absent |
| `app/Providers/PackageServiceProvider.php:80` | addon loader: the dynamic add-on pass failed, so some modules are not loaded |
| `app/Services/CodCollectionService.php:161` | COD collection refused: the event is not verifiable |
| `app/Services/CodCollectionService.php:176` | COD collection refused: the amount collected does not settle the order |
| `app/Services/CodCollectionService.php:187` | COD collection cannot be recorded: cod_collection_events has no table |
| `app/Services/DeliveryBoyNotificationService.php:65` | No active Delivery Firebase devices found for delivery boy. |
| `app/Services/OrderTaxResolver.php:101` | Checkout: client-supplied tax_price discarded (server value used) |
| `app/Services/OrderTaxResolver.php:88` | Checkout: non-numeric client tax_price discarded |
| `app/Services/OrderTotalReconciler.php:173` | Order total reconciliation mismatch (warn-only) |
| `app/Services/PaymentFailure.php:22` | Payment could not be started |
| `app/Services/PaymentService.php:2223` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Services/PaymentService.php:2326` | cod/bank_transfer order writer refused: basket names a product from another store |
| `app/Services/PaymentService.php:2345` | cod/bank_transfer order writer refused: basket holds an unusable quantity |
| `app/Services/PaymentService.php:2438` | Checkout: client-supplied shipping_final_price discarded (server value used) |
| `app/Services/PaymentService.php:3663` | PayHere storefront checkout refused: the integration is retired (owner decision). |
| `app/Services/PaymentVerificationService.php:340` | Order recorded as PENDING: no gateway verification for this payment |
| `app/Services/PaymentVerificationService.php:365` | Order recorded as PENDING: the verification belongs to a different checkout |
| `app/Services/PaymentVerificationService.php:378` | Order recorded as PENDING: the verification record is for another payment type |
| `app/Services/PaymentVerificationService.php:802` | COD_SETTLEMENT=order is NO LONGER HONOURED and is ignored |
| `app/Services/PaymentVerificationService.php:810` | Unrecognised COD settlement policy; settle-on-delivery is in force regardless |
| `app/Services/ProductStockService.php:110` | Stock decrement rejected: insufficient stock |
| `app/Services/ProductStockService.php:192` | Variant stock decrement rejected: insufficient stock |
| `app/Services/ProductStockService.php:330` | Could not flush the model cache after a stock change |
| `app/Services/RefundIntegrityService.php:147` | Refund recorded as approved but NOT settled: no gateway refund was made |
| `app/Services/ShopifyAdminApi.php:137` | a Shopify Admin API address was refused before the request was made |
| `app/Services/ShopifyAdminApi.php:173` | a Shopify Admin API call answered with a redirect, which was not followed |
| `app/Services/SocialIdentity/JwksProvider.php:74` | social-identity: provider key fetch failed |
| `app/Services/SocialIdentity/SocialIdentityVerifier.php:239` | social-identity: facebook debug_token call failed |
| `app/Services/StoreNotificationService.php:311` | No active Firebase devices found for store. |
| `app/Services/StoredUpload.php:108` | a stored upload path names another tenant |
| `app/Services/StoredUpload.php:81` | a stored upload path points outside the uploads directory and was not touched |
| `app/Services/StoredUpload.php:90` | a stored upload path names a platform default and was not touched |
| `app/Services/WebuzoDomainService.php:170` | New Webuzo domain added but old domain could not be deleted |

<!-- counts: error=95 info=22 debug=1 -->

---

### The three rows added 2026-10-28 (D-13 / D-22), with their destination and reader

The ratchet's resync tool refuses to write when a line is **added** rather than moved, because a new
row needs a destination and a reader that only a person can state. These are those statements.

**`GuardsGatewayReturn.php:946` — `logGatewayExitAfterCapture()`, `critical`.** Fires when a gateway
return gives up *after* the capture has been classified. **Why it has to exist:** D-13 hoisted the
Khalti verification above the cart read, which is what stops stock being taken before the payment is
known — and in doing so opened roughly 450 lines between *"the gateway confirmed this capture"* and
the order write. Several exits in that window previously returned `{status: 0}` with no log at all,
and before the hoist they ran *before* the verify call, so their silence cost nothing. Now it would.
**Reader:** whoever reconciles payments. `capture_was_confirmed` is the field to search on — `true`
means money moved and that shopper has no order. **Destination:** the same channel as every other
row here (section 2), so it reaches larabug with the same caveat recorded there.

**`GuardsGatewayReturn.php:958` — `gatewayReturnMissingProductRefusal()`, `critical`.** Fires when a
gateway return's basket names a `products` or `product_variants` row that no longer exists — F121,
which used to answer with an unhandled `ErrorException` from the cart read. Wired on all four
handlers D-18 names. **Reader:** the same person, for the same reason: on Khalti it carries the
verification outcome, so a confirmed capture with no order is searchable; on PaiementPro, CinetPay
and Easebuzz it carries `null` there, which is the honest value — those gateways verify nothing, so
the capture state is genuinely **unknown** rather than known-absent. **Destination:** as above.

**`KhaltiPaymnetController.php:461` — the rejected-capture refusal, `warning`.** Fires when Khalti
was reached and did not confirm, now *before* any stock moves. `warning` rather than `critical`
because on this branch the gateway answered and the answer was no: nothing was taken and nothing
needs settling. One caveat worth having in writing — `verifyKhaltiCapture()` classifies **any**
non-200, non-zero HTTP status as REJECTED, a 502 included, so a run of these may mean Khalti is
unwell rather than that shoppers are being refused. **Reader:** whoever watches gateway health.
**Destination:** as above.

---

### The three rows added 2026-10-29 (F95 / F96), with their destination and reader

All three are `warning`, and they share one shape: **a duplicate write was refused**. None of them
means money or stock moved — each means the *second* caller was turned away before it could — so
`warning` rather than `critical` is the honest level. What makes them worth logging at all is that
the refusal is **invisible to the shopper**: each answers with the same success-shaped response the
legitimate first request gets, deliberately, so a replay cannot be told apart from a completion.
Without the line there is no record that the guard ever fired.

**`PaymentController.php:687` — `handlePaymeraCallback`, and `:1107` — `handlePaymeraTrigger`,
`warning`.** Fire when the durable claim on a Paymera payment reference could not be taken, i.e.
another request is already inside the order writer for that same payment (F96). **Reader:** whoever
reconciles payments. `paymera_reference` is the field to search on, and these two lines are the only
evidence that the ten-second lock would otherwise have lapsed mid-write — **a run of them on one
reference means the writer is slow**, most likely waiting on SMTP or SMS inside its stock loop,
which is the restructuring F96's row still has open. One line per refused replay is proportionate:
a replay is a browser refresh or a gateway retry, not an attack.

**`PaymentController.php:3802` — `whatsapp()` (serving both the WhatsApp and Telegram intake
routes), `warning`.** Fires when the intake lock for one basket could not be taken, i.e. two
concurrent posts are racing the same cart (F95). **Reader:** whoever watches the storefront. It
carries `path`, `store_id` and `authenticated_customer`, and `path` is the field that distinguishes
the two routes. **This one doubles as the only signal on an unthrottled route** — F95's rate half is
still the owner's decision, so until a throttle exists this line is what a repeated-submission
pattern would show up in. **Destination:** the same channel as every other row here (section 2),
with the same caveat.

### The two rows added 2026-10-29 by the review of that change, `critical`

**`PaymentController.php`, in `handlePaymeraCallback` and in `handlePaymeraTrigger` — the
post-commit throw.** (No line numbers here on purpose: the table above carries them and is
ratcheted; this slice rotted hand-written line references four times, so the prose names the
methods instead.) These are `critical` where the three refusals above are `warning`, and the
difference is the whole point of the line: it fires when **a committed order cannot be ruled out**
and the request then threw — so money may have moved, stock may have moved, and the buyer was handed
a 500.

**THE WORDING IS DELIBERATE AND IT WAS CORRECTED ONCE.** The test behind it is a high-water mark
over the store's paymera orders, not a per-payment identity — an `orders` row carries no paymera
reference — so with two Paymera payments running in one store it can read the *other* payment's
row. An earlier version of this line asserted *"AFTER the order committed"*, which on that path
would have told the reconciler a commit happened when none had. A reader must therefore **check**,
not assume: the line narrows the search to one store and one reference, it does not settle it. The duplicate-write claim is
deliberately **kept** in that arm — releasing it would let the buyer's refresh write a second order
— so the shopper's refresh lands on the completion page with **no order number**, because
`OrderCompleteBinding::remember()` is the last statement of the tail that threw and was never
reached.

Which makes this line the **only** record that the order exists. **Reader:** whoever reconciles
payments, and this one is not a health signal to watch for trends — a single occurrence is a real
order a real buyer does not know they have. Search on `paymera_reference`; `error` carries the
throw's message, and the plausible causes are all in the writer's unguarded post-commit tail (the
store notification, the activity log, `$owner->email` on a store whose owner row is missing, three
order notes, an event with synchronous listeners, the affiliate transaction).

**Destination:** the same channel as every other row here (section 2), with the same caveat — which
matters more for these two than for most, because nothing else in the system knows.

---

## 2. Where these lines actually go

**One file. Unrotated. Mixed with everything else. Nothing alerts.**

Traced through `.env.example` and `config/logging.php` rather than assumed:

| setting | value | consequence |
|---|---|---|
| `LOG_CHANNEL` | `stack` | the stack is the only channel in play |
| `LOG_STACK` | `single` | …so the stack is `['single']` plus the `larabug` channel the config hard-codes |
| `single` | `storage_path('logs/laravel.log')` | **one file, no rotation, no retention, no size bound** |
| `LOG_LEVEL` | `debug` | every framework and vendor debug line shares that file |
| `daily` | defined, **unused** | the rotating channel exists and nothing selects it |
| `API_log` | `daily`, 15 days | used by the API paths only |
| `PayTabs` | `single`, own file | one gateway has its own file; the other fifty do not |

So a `Log::critical('Order write failed and was rolled back; stock decrements were given back')`
lands in the same unrotated file as every `debug` line the framework emits, and is found only by a
human who already knows to go looking. **There is no channel, no retention policy and no alerting
for any of the lines in section 1.**

### The expectation this records

This document does **not** change `config/logging.php`. Logging configuration is deployment
configuration, and changing it is a production change outside what this task authorises. What it
records is the expectation the next operator decision should meet:

1. **A dedicated channel.** These lines deserve their own destination, not `laravel.log`. The
   `daily` driver already in `config/logging.php` is the smallest step; a `security` channel at
   `warning` with its own path is the honest one.
2. **A retention.** `single` has none. `API_log` already demonstrates the pattern (`'days' => 15`).
   A financial-dispute window is longer than fifteen days; the `critical` lines in section 1 touch
   money that a merchant may query months later.
3. **Alerting on `critical`.** Eleven call sites. Every one of them means money or stock has
   already moved in a way the code could not refuse — an oversold unit, a rolled-back order write,
   a Paymera callback rejected for an unverifiable amount or signature. These are not "look at the
   log next week" events. `config/logging.php` already ships a `slack` channel definition, unused.
4. **A volume estimate before 1–3 are acted on.** Not done here, because it needs production
   traffic this session cannot see. The `warning` set includes refusals a storefront can emit on
   ordinary bad input, so routing all 81 somewhere alerting would be noisy; the split between
   "a control refused something routine" and "a control refused something that should not have
   happened" is an operator judgement on real volumes.

---

## 3. larabug — and the finding that matters

`config/larabug.php`'s `blacklist` was already reviewed and substantially extended under P1-SEC-14:
cookies and sessions, every gateway secret and key, card numbers and expiries under each spelling
the payment forms use, one-time codes, pins, signatures and CSRF values. That review stands.

**But the blacklist is not what governs the lines in section 1, and enabling larabug would not send
them anywhere.** Read from the installed package rather than from its README:

- `vendor/larabug/larabug/src/Logger/LaraBugHandler::write()` returns immediately unless
  `$record['context']['exception']` is an instance of `Throwable`. **Every line in section 1 is a
  `Log::warning`/`Log::critical` with a plain context array and no exception**, so the handler
  discards all of them.
- The handler is constructed as `new LaraBugHandler($app['larabug'])`
  (`src/ServiceProvider.php:62`), taking the constructor default `Logger::ERROR`, so even an
  exception logged at `warning` would not reach it.
- `config/larabug.php` gates on `'environments' => ['production']` and on `LB_KEY` /
  `LB_PROJECT_KEY`, both of which default to `''`. The channel is therefore inert in every
  environment this repository configures.
- The blacklist is applied by `LaraBug::filterVariables()` to **request variables on an exception
  report** — not to log context.

So the standing question "review larabug's blacklist before anyone enables it" has two answers:
the blacklist itself is in good order, **and** enabling larabug would not be an answer to section 2,
because it is an exception reporter and these are not exceptions. Anyone who turns it on expecting
to see the Phase 01 security signals will see none of them.

### What a reader should take from this

The security logging Phase 01 added is real and it is in the right places. The gap is entirely on
the receiving side, and it is a deployment decision rather than a code one — which is exactly why
it is written down here instead of being quietly fixed.
