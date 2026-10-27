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


### `critical` — 12 lines

| line | message |
|---|---|
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:111` | Insufficient stock on an order path that cannot refuse; order kept, stock oversold |
| `app/Http/Controllers/KhaltiPaymnetController.php:1205` | Khalti order written for RECONCILIATION: the gateway could not be asked whether money moved |
| `app/Http/Controllers/PaymentController.php:469` | Paymera status poll ABANDONED: no API credential is configured |
| `app/Http/Controllers/PaymentController.php:580` | Paymera callback REJECTED: the payment status could not be established |
| `app/Http/Controllers/PaymentController.php:840` | Paymera callback REJECTED: inbound signature not verified |
| `app/Http/Controllers/PaymentController.php:869` | Paymera callback REJECTED: captured amount not verified |
| `app/Http/Controllers/PaymentController.php:3115` | Order write failed and was rolled back; stock decrements were given back |
| `app/Http/Controllers/PaymentController.php:4447` | Order write failed and was rolled back; stock decrements were given back |
| `app/Services/PaymentService.php:187` | Insufficient stock on an order path that cannot refuse; order kept, stock oversold |
| `app/Services/PaymentService.php:3020` | Order write failed and was rolled back; stock decrements were given back |
| `app/Services/ProductStockService.php:127` | Stock decremented below zero on an order path that cannot refuse |
| `app/Services/ProductStockService.php:210` | Stock decremented below zero on an order path that cannot refuse |

### `warning` — 120 lines

| line | message |
|---|---|
| `app/Http/Controllers/Api/ApiController.php:129` | Coupon refused: the basket could not be priced |
| `app/Http/Controllers/Api/ApiController.php:3432` | order: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:6949` | order: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:7040` | coupon: refused an implausible discounted total |
| `app/Http/Controllers/Api/ApiController.php:7816` | payment_sheet refused: basket names a product from another store |
| `app/Http/Controllers/Api/ApiController.php:7842` | payment_sheet: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:8452` | order: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:8577` | coupon: refused an implausible discounted total |
| `app/Http/Controllers/Api/AuthController.php:186` | register: social identity verification refused |
| `app/Http/Controllers/Api/DeliverySyriaOrderController.php:82` | Delivery Syria invalid credentials |
| `app/Http/Controllers/AuthorizeNetPaymnetController.php:339` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/AuthorizeNetPaymnetController.php:381` | AuthorizeNet capture refused: no server-computed payable in the stored state. |
| `app/Http/Controllers/CartController.php:222` | product_cart: refused a non-positive or non-integer quantity |
| `app/Http/Controllers/CartController.php:275` | product_cart: refused a product belonging to another store |
| `app/Http/Controllers/CinetPayController.php:480` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:133` | Gateway return refused: no server-stored checkout state. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:282` | Gateway return carries no claimable identity; falling back to the session control alone. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:296` | Gateway return refused: already claimed. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:823` | Gateway return refused: basket names a product from another store. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:859` | Gateway return refused: the gateway request could not be built. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:876` | Gateway return refused: basket holds a non-positive quantity. |
| `app/Http/Controllers/Concerns/GuardsPlanActivation.php:85` | Plan activation refused: provider is disabled pending verification. |
| `app/Http/Controllers/Concerns/ResolvesPendingPartialPayment.php:135` | Pending partial payment lookup failed; the payment is refused. |
| `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:716` | customer login: refused a social row still holding its module-assigned password |
| `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:941` | Newsletter subscription failed during customer registration |
| `app/Http/Controllers/DeliverySyriaStatusController.php:179` | Zid store not found for order status sync. |
| `app/Http/Controllers/DeliverySyriaStatusController.php:95` | Delivery Syria status webhook: invalid credentials. |
| `app/Http/Controllers/ESewaPaymentController.php:196` | Plan payment return rejected: claimed amount does not match the plan price. |
| `app/Http/Controllers/EasebuzzController.php:350` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/KhaltiPaymnetController.php:251` | Khalti plan purchase NOT activated: the capture was not confirmed for the server price |
| `app/Http/Controllers/KhaltiPaymnetController.php:774` | Khalti verification refused: no server-computed payable in the stored state. |
| `app/Http/Controllers/NMIPayController.php:428` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/OrderController.php:1091` | Return approval restored no stock: order carries no readable product_json |
| `app/Http/Controllers/OrderController.php:1137` | Return approval skipped a line whose stock was already credited |
| `app/Http/Controllers/OrderController.php:1157` | Return approval skipped a line with an unusable product id or quantity |
| `app/Http/Controllers/OrderController.php:1178` | Return approval skipped a line whose product is not in the order |
| `app/Http/Controllers/OrderController.php:1205` | Return approval skipped a line whose variant does not belong to its product |
| `app/Http/Controllers/OrderController.php:1551` | Order cancellation skipped a line with an unusable product id or quantity |
| `app/Http/Controllers/OrderController.php:1566` | Order cancellation skipped a line whose product is not in the order |
| `app/Http/Controllers/OrderController.php:1588` | Order cancellation skipped a line whose variant does not belong to its product |
| `app/Http/Controllers/OrderController.php:1754` | Order assignment refused: the delivery boy is not in a store this caller owns |
| `app/Http/Controllers/OrderController.php:306` | Manual order total did not match its components; the computed total was stored |
| `app/Http/Controllers/OrderRefundController.php:1167` | Refund settled but the order totals were left unchanged: product_json is unparseable |
| `app/Http/Controllers/OrderRefundController.php:180` | Refund transition refused: unrecognised current state |
| `app/Http/Controllers/OrderRefundController.php:202` | Refund transition refused: illegal transition |
| `app/Http/Controllers/OrderRefundController.php:416` | Refund cancellation refused: the record does not match the order |
| `app/Http/Controllers/OrderRefundController.php:749` | Refund restock skipped a line with an unusable quantity |
| `app/Http/Controllers/OrderRefundController.php:765` | Refund restock clamped to the units the order lines lost |
| `app/Http/Controllers/OzowController.php:217` | Ozow plan return refused: no TransactionId to key on. |
| `app/Http/Controllers/PaiementProPaymentController.php:320` | PaiementPro return recorded PENDING: this gateway is not provider-verified |
| `app/Http/Controllers/PaiementProPaymentController.php:433` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/PayHerePaymnetController.php:64` | PayHere plan checkout refused: the integration is retired (owner decision). |
| `app/Http/Controllers/PayHerePaymnetController.php:76` | PayHere plan checkout refused: the PayHere SDK is not installed. |
| `app/Http/Controllers/PayUPaymentController.php:287` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/PaymentController.php:1098` | Paymera trigger could not identify the order it created; the pending record names none |
| `app/Http/Controllers/PaymentController.php:1291` | checkout: refused, basket names a product from another store |
| `app/Http/Controllers/PaymentController.php:1331` | checkout: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/PaymentController.php:1985` | Checkout refused: this payment method cannot settle an outstanding balance. |
| `app/Http/Controllers/PaymentController.php:2002` | Checkout refused: no recorded balance matches this pending payment. |
| `app/Http/Controllers/PaymentController.php:2020` | Checkout refused: the posted pending-payment amount disagrees with the record. |
| `app/Http/Controllers/PaymentController.php:2336` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/PaymentController.php:3509` | Telegram order message not sent: the store has no bot token or chat id |
| `app/Http/Controllers/PaymentController.php:3525` | Telegram refused an order message |
| `app/Http/Controllers/PaymentController.php:3531` | Telegram order message could not be sent |
| `app/Http/Controllers/PaymentController.php:3599` | Whatsapp order writer: posted slug does not match the route slug; the route wins. |
| `app/Http/Controllers/PaymentController.php:550` | Paymera replay refused: the session presenting the reference did not place the order |
| `app/Http/Controllers/PaymentController.php:718` | Paymera callback could not identify the order it created; the pending record names none |
| `app/Http/Controllers/PaymentController.php:785` | Paymera pending record vanished before the order was created |
| `app/Http/Controllers/PaymentController.php:855` | Paymera callback signature NOT verified and not enforced |
| `app/Http/Controllers/PaystackPaymentController.php:301` | Paystack plan activation refused: the transaction is not a matching success. |
| `app/Http/Controllers/PaystackPaymentController.php:388` | Paystack plan activation refused: this reference is already recorded against a different buyer or plan. |
| `app/Http/Controllers/PaystackPaymentController.php:455` | Paystack plan activation refused: the captured payment does not match the plan being activated. |
| `app/Http/Controllers/PosController.php:246` | POS sale skipped a line whose product is not this store |
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
| `app/Models/Order.php:849` | Order return refused: the order carries no readable product_json |
| `app/Models/Order.php:860` | Order return refused: the order lines are not in a shape this workflow can return |
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
| `app/Services/PaymentService.php:3662` | PayHere storefront checkout refused: the integration is retired (owner decision). |
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
| `app/Services/StoreNotificationService.php:157` | No active Firebase devices found for store. |
| `app/Services/StoredUpload.php:108` | a stored upload path names another tenant |
| `app/Services/StoredUpload.php:81` | a stored upload path points outside the uploads directory and was not touched |
| `app/Services/StoredUpload.php:90` | a stored upload path names a platform default and was not touched |
| `app/Services/WebuzoDomainService.php:170` | New Webuzo domain added but old domain could not be deleted |

<!-- counts: error=95 info=22 debug=1 -->

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
