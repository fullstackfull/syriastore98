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

**THE CENSUS READS `app/` AND NOTHING ELSE, and one load-bearing warning therefore lives outside
it.** `routes/web.php`'s `zid/events` closure logs a warning when `application.uninstall` appears in
a webhook body but NOT in the resolved event name. That line is the entire reason P1-SEC-41 could be
fixed fail-safe rather than escalated untouched: not unlinking a merchant is visible and reversible
*because of it*. It is named here rather than added as a row, because a row would put the documented
count one above the source count the ratchet measures and fail the test for the opposite reason. It
is not unasserted, though -- `ZidWebhookSecretTest` pins it through the logger's own `MessageLogged`
event. If a second security warning is ever added under `routes/`, widening the scan is a better
answer than a second paragraph here.

Only `critical` and `warning` are enumerated. They are the levels Phase 01 used for *a control
fired* or *something happened that a human should look at*. `error` (95 call sites) is almost
entirely pre-existing third-party-integration failure reporting, `info` (22) and `debug` (1) are
diagnostics; none of them is a security signal and enumerating them would bury the ones that are.


### `critical` — 37 lines

> **[2026-11-01] THIS COUNT WAS STALE, AND NOT BY THIS CHANGE'S DOING.** It read **21** against a
> table of **28** rows before F241 and F263 added two more; the `warning` heading read **166**
> against **174**. The rows themselves were right — `SecurityLogInventoryTest` asserts the table
> against `app/` in both directions and has been green — so what drifted was a number in prose that
> nothing checked, while the thing that matters was ratcheted. Recorded rather than quietly
> corrected, because "the heading is stale and the table is not" is the shape of drift to expect in
> this file, and the next reader should know which half to trust. Measured now: 30 + 179 = **209**,
> which is what the resync tool reports as the source total.

| line | message |
|---|---|
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:346` | Insufficient stock on an order path that cannot refuse; order kept, stock oversold |
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:437` | Stock compensation could not determine whether the order row survived; assuming it did and leaving the stock consumed. |
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:490` | Stock compensation failed: inventory was consumed and could not be returned. |
| `app/Observers/OrderObserver.php:63` | Order financial record was not written; the wallet credit for this order is lost. |
| `app/Services/OrderEntitlementReversal.php:133` | Entitlement reversal failed; the order was still cancelled or refunded. |
| `app/Services/OrderEntitlementReversal.php:191` | Entitlement reversal found no financial record for the order. |
| `app/Services/OrderEntitlementReversal.php:256` | Entitlement reversal could not find the wallet named by the financial record. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:954` | Gateway return gave up after the capture was classified: |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:1004` | Gateway return refused: the basket names a product or variant row that no longer exists. |
| `app/Http/Controllers/KhaltiPaymnetController.php:1376` | Khalti order written for RECONCILIATION: the gateway could not be asked whether money moved |
| `app/Http/Controllers/PaymentController.php:4191` | Order write failed before the transaction opened; stock decrements were given back |
| `app/Http/Controllers/PaymentController.php:5692` | Order write failed before the transaction opened; stock decrements were given back |
| `app/Http/Controllers/PaymentController.php:829` | Paymera write threw and a committed order cannot be ruled out; the claim is kept so no replay can write a second order |
| `app/Http/Controllers/PaymentController.php:473` | Paymera status poll ABANDONED: no API credential is configured |
| `app/Http/Controllers/PaymentController.php:584` | Paymera callback REJECTED: the payment status could not be established |
| `app/Http/Controllers/PaymentController.php:795` | Paymera payment verified but no order was written; the capture is unreconciled |
| `app/Http/Controllers/PaymentController.php:1772` | Paymera write threw and a committed order cannot be ruled out; the claim is kept so no replay can write a second order |
| `app/Http/Controllers/PaymentController.php:1757` | Paymera trigger verified the payment but wrote no order; the capture is unreconciled |
| `app/Http/Controllers/PaymentController.php:943` | Paymera callback REJECTED: inbound signature not verified |
| `app/Http/Controllers/PaymentController.php:972` | Paymera callback REJECTED: captured amount not verified |
| `app/Http/Controllers/PaymentController.php:4390` | Order write failed and was rolled back; stock decrements were given back |
| `app/Http/Controllers/PaymentController.php:5882` | Order write failed and was rolled back; stock decrements were given back |
| `app/Services/OrderFinancialService.php:321` | OrderFinancialService: an order line names a product that is not |
| `app/Services/PaymentService.php:232` | Insufficient stock on an order path that cannot refuse; order kept, stock oversold |
| `app/Services/PaymentService.php:3368` | Order write failed and was rolled back; stock decrements were given back |
| `app/Services/ProductStockService.php:139` | Stock decremented below zero on an order path that cannot refuse |
| `app/Services/ProductStockService.php:250` | Stock decremented below zero on an order path that cannot refuse |
| `app/Helper/helper.php:444` | paid download url not issued |
| `app/Http/Controllers/PaidDownloadController.php:353` | paid download refused: resolved path escapes the uploads root |
| `app/Http/Controllers/PaidDownloadController.php:373` | paid download refused |
| `app/Services/GatewayReference.php:156` | gateway reference not recorded: the table is absent |
| `app/Services/GatewayReference.php:174` | gateway reference not recorded: the store id or reference is unusable |
| `app/Services/GatewayReference.php:198` | gateway capture replayed: the reference is already recorded |
| `app/Services/GatewayReference.php:213` | gateway capture replayed (23000): the reference is already recorded |
| `app/Http/Controllers/PaymentCallbackController.php:327` | Paytm plan callback: the payment is verified but could not be settled. Nothing was activated and this needs a human. |

### `warning` — 238 lines

> **[2026-11-25, third pass] 237 -> 238.** One `warning` line on the admin order-status door, for a
> payment status moved on an order that is already cancelled or returned. Its destination and reader
> are in their own section below.
>
> **[2026-11-25, second pass] 235 -> 236.** The independent financial review of #204 found that a
> refunded Paytm capture still reported `TXN_SUCCESS` and would have been settled days later; the
> verifier now refuses any refund-shaped field it cannot read as zero, and says so. Its destination
> and reader are in the same section as the other eighteen.
>
> **[2026-11-25] 218 -> 235, and `critical` 36 -> 37 (#204).** Seventeen `warning` lines and one
> `critical` added by the disabled-application payment boundary and the payment reconciliation
> surface; six existing rows moved and two were deleted because the lines they named are gone. The
> moves were re-pointed by (file, message), and the deletions are real: the callback's
> "the purchaser no longer exists" and "assignPlan refused" lines were replaced by one line that
> covers every way a verified payment fails to settle, because the settlement is now one
> transaction and the two cases are no longer distinguishable from outside it. Destination and
> reader for all eighteen are in their own section below.
>
> **[2026-11-24] 217 -> 218.** One line added by the P06-2B review follow-up
> (`ModuleAvailability could not read the enable flag`, with its destination and reader in its own
> section below). `resync-observability-inventory.py` REFUSED to write it, correctly and for the
> third time: the line is NEW, not moved.

> **[2026-11-21] 216 -> 217.** One line added by ANA-009 (`Metric threshold alert could not be recorded`, with its destination
> and reader in its own section below). `resync-observability-inventory.py` REFUSED to write it, correctly: the line is NEW, not
> moved, and a new row needs a stated destination and reader that only a person can give it.

> **[2026-11-21] 215 -> 216.** One line added by GAP-223(b) (`Report refused: operator has selected no merchant store`, with
> its destination and reader in its own section below). `resync-observability-inventory.py` REFUSED to write it, which is the
> tool working as designed: it re-points rows that MOVED and will not invent a destination for a row that is NEW.

> **[2026-11-19] 212 -> 213.** One line added by P1-SEC-73 (the second row below, `a platform upload was refused`, with its
> destination and reader in its own section, after the P1-SEC-72 one). The tables now hold **36 `critical` and 212 `warning`** --
> 248 -- plus this one.

> **[2026-11-18] 211 -> 212, AND BOTH HEADINGS HAD DRIFTED AGAIN.** One line added by P1-SEC-72 (the first row below, with its
> destination and reader in its own section). Counting the rows for that edit: the tables held **36 `critical` and 211
> `warning`** -- 247, the figure `resync-observability-inventory.py` reports as `source lines` -- while the headings read 31 and
> 204. Both are corrected here. Nothing was wrong with the rows, which the ratchet compares against `app/` one by one; what
> drifted is, once more, the number in prose that nothing asserts.

> **[2026-11-02, second edit] 186 -> 190, and BOTH HEADINGS WERE STALE BEFORE IT.** Measured
> against `app/` with the test's own regex: **27 `critical`, 190 `warning`, 217 total** — which is
> the total `resync-observability-inventory.py` reports. The headings read 30 and 183, so
> `critical` over-counted by three and `warning` under-counted by seven, and they had been wrong
> *before* this change too (186 actual against 183 claimed). Part of the `critical` discrepancy
> is now explained rather than just corrected: the `OtpIntegrationController` refusal row was
> sitting in the `critical` table while the source line is a `Log::warning`. It has been moved
> down here, where the code puts it.
>
> **The headings are not asserted by the ratchet** — the test compares table rows against `app/`
> and never reads a heading — which is exactly why they drift. Recount before trusting one:
> `php` the regex in `SecurityLogInventoryTest::censusFromSource()`, or read
> `resync-observability-inventory.py`'s `source lines` figure.

> **[2026-11-02] 181 -> 183.** Two lines added by the GATE F wave-5 security fixes, both below
> with their own destination. `SecurityLogInventoryTest` is what required this edit rather than
> permitting it: it failed on the two undocumented lines, and
> `resync-observability-inventory.py` **refused** to add them -- by design, because it re-points
> rows that MOVED and will not invent a destination for a row that is NEW. That is the right
> division: the tool can follow code, only a person can say who reads a line.

| line | message |
|---|---|
| `app/Helper/helper.php:687` | an image upload was refused |
| `app/Http/Controllers/Api/DeliverySyriaOrderController.php:323` | Delivery Syria order status transition refused |
| `app/Http/Controllers/Api/DashboardController.php:1044` | Payment status changed on a settled order |
| `app/Services/PlatformUploadPolicy.php:314` | a platform upload was refused |
| `app/Http/Controllers/ThemeCustomizeController.php:407` | theme settings write refused |
| `app/Http/Controllers/ThemeCustomizeController.php:138` | Theme editor read refused. |
| `app/Http/Controllers/ThemeVersionController.php:341` | Theme publication refused. |
| `app/Http/Middleware/ThemeEditorAccess.php:59` | Theme editor access refused. |
| `app/Http/Middleware/ThemeDraftAccess.php:56` | Theme draft access refused. |
| `app/Http/Controllers/Api/ApiController.php:6542` | shipping quote refused a cart line naming another store |
| `app/Models/Cart.php:1331` | flat-rate shipping refused a cart line naming another store |
| `app/Http/Controllers/PaymentCallbackController.php:107` | Paytm plan callback refused: no usable order reference. |
| `app/Http/Controllers/PaymentCallbackController.php:159` | Paytm plan callback refused: the provider did not confirm the payment. |
| `app/Http/Controllers/PaymentCallbackController.php:173` | Paytm plan callback refused at the intent. |
| `app/Http/Controllers/ShippingController.php:148` | shipping class write refused: the platform operator has read rights only |
| `app/Http/Controllers/TaxMethodController.php:129` | tax method write refused: the platform operator has read rights only |
| `app/Http/Controllers/PaytmPaymentController.php:270` | Paytm plan purchase refused: the purchase intent could not be recorded. |
| `app/Http/Controllers/CashfreeController.php:170` | Cashfree plan purchase refused: the purchase intent could not be recorded. |
| `app/Http/Controllers/CashfreeController.php:307` | Cashfree plan activation refused: the payment does not match a recorded purchase intent. |
| `app/Http/Controllers/CashfreeController.php:322` | Cashfree plan activation refused: the intent names a plan that no longer exists. |
| `app/Http/Controllers/MolliePaymentController.php:187` | Mollie plan purchase refused: the purchase intent could not be recorded. |
| `app/Http/Controllers/MolliePaymentController.php:285` | Mollie plan activation refused: the payment does not match a recorded purchase intent. |
| `app/Http/Controllers/MolliePaymentController.php:298` | Mollie plan activation refused: the intent names a plan that no longer exists. |
| `app/Http/Controllers/PaytmPaymentController.php:479` | Paytm plan activation refused: the provider did not confirm the payment. |
| `app/Http/Controllers/PaytmPaymentController.php:535` | Paytm plan activation refused: the payment does not match the recorded purchase intent. |
| `app/Http/Controllers/PaytmPaymentController.php:563` | Paytm plan activation refused: the intent names a plan that no longer exists. |
| `app/Http/Controllers/PaytmPaymentController.php:825` | Paytm plan return refused: the route names a plan that no longer exists. |
| `app/Services/PaytmTransactionVerifier.php:198` | Paytm transaction status could not be verified. |
| `app/Services/PaytmTransactionVerifier.php:217` | Paytm transaction status carried a checksum that did not verify. |
| `app/Services/PlanPurchaseIntent.php:243` | Plan purchase intent not recorded: provider or currency missing. |
| `app/Services/PlanPurchaseIntent.php:252` | Plan purchase intent not recorded: no usable purchaser or plan. |
| `app/Services/PlanPurchaseIntent.php:263` | Plan purchase intent not recorded: the expected amount is not numeric. |
| `app/Services/PlanPurchaseIntent.php:309` | Plan purchase intent not recorded. |
| `app/Services/PlanPurchaseIntent.php:783` | Plan purchase intent refusal not recorded. |
| `app/Services/PlanPurchaseIntent.php:822` | Plan purchase intent recoverable refusal not recorded. |
| `app/Services/PlanPurchaseIntent.php:843` | Plan purchase intent order not attached. |
| `app/Http/Controllers/CyberSourceController.php:122` | CyberSource plan purchase refused: the posted plan id matches no plan. |
| `app/Http/Controllers/Concerns/GuardsPlanActivation.php:159` | Plan purchase refused before any write: the provider has no usable credentials. |
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:179` | stock evidence not attributed: the order carries no usable id or store |
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:208` | stock evidence incomplete: fewer movements recorded than stock decrements applied |
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:230` | stock evidence not attributed: stock moved for this request and no row now owns the order |
| `app/Http/Controllers/Concerns/AppliesCartStockChanges.php:240` | stock evidence not attributed: the attempt threw |
| `app/Http/Controllers/Api/ApiController.php:3746` | Storefront order intake refused as a duplicate submission |
| `app/Http/Controllers/Api/ApiController.php:4326` | Storefront intake claim could not record the order id; a retry inside the window will be refused rather than answered. |
| `app/Http/Controllers/Api/ApiController.php:4464` | Storefront intake claim could not be released; it will expire on its own. |
| `app/Http/Controllers/Api/ApiController.php:10743` | Storefront intake claim could not record the order id; a retry inside the window will be refused rather than answered. |
| `app/Http/Controllers/Api/ApiController.php:10792` | Storefront intake claim could not be released; it will expire on its own. |
| `app/Http/Controllers/Api/ApiController.php:9996` | Storefront order intake refused as a duplicate submission |
| `app/Http/Controllers/PaymentController.php:2174` | Web checkout intake refused as a duplicate submission |
| `app/Http/Controllers/Api/ApiController.php:135` | Coupon refused: the basket could not be priced |
| `app/Http/Controllers/Api/ApiController.php:3553` | order: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:7952` | order: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:8043` | coupon: refused an implausible discounted total |
| `app/Http/Controllers/Api/ApiController.php:9019` | payment_sheet refused: basket names a product from another store |
| `app/Http/Controllers/Api/ApiController.php:9045` | payment_sheet: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:9682` | order: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/Api/ApiController.php:9844` | coupon: refused an implausible discounted total |
| `app/Http/Controllers/Api/AuthController.php:186` | register: social identity verification refused |
| `app/Http/Controllers/Api/DeliverySyriaOrderController.php:153` | Delivery Syria invalid credentials |
| `app/Http/Controllers/AuthorizeNetPaymnetController.php:356` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/AuthorizeNetPaymnetController.php:398` | AuthorizeNet capture refused: no server-computed payable in the stored state. |
| `app/Http/Controllers/CartController.php:262` | product_cart: refused a non-positive or non-integer quantity |
| `app/Http/Controllers/CartController.php:315` | product_cart: refused a product belonging to another store |
| `app/Http/Controllers/CartController.php:1553` | abandoned-cart e-mail nudge failed |
| `app/Http/Controllers/CartController.php:1756` | abandoned-cart WhatsApp nudge failed |
| `app/Http/Controllers/WishlistController.php:316` | abandoned-wishlist e-mail nudge failed |
| `app/Http/Controllers/WishlistController.php:734` | abandoned-wishlist WhatsApp nudge failed |
| `app/Services/Reports/ScheduledReportRunner.php:197` | scheduled analytics report: stored recipient differs from the owner address; using the owner |
| `app/Services/Reports/ScheduledReportRunner.php:247` | scheduled analytics report failed |
| `app/Http/Controllers/CinetPayController.php:535` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:133` | Gateway return refused: no server-stored checkout state. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:282` | Gateway return carries no claimable identity; falling back to the session control alone. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:296` | Gateway return refused: already claimed. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:1055` | Gateway return refused: basket names a product from another store. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:1091` | Gateway return refused: the gateway request could not be built. |
| `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:1108` | Gateway return refused: basket holds a non-positive quantity. |
| `app/Http/Controllers/Concerns/GuardsPlanActivation.php:85` | Plan activation refused: provider is disabled pending verification. |
| `app/Http/Controllers/Concerns/GuardsPlanActivation.php:576` | Plan activation refused: this provider reference has already activated a plan. |
| `app/Http/Controllers/ShippingMethodController.php:120` | shipping method write refused: the platform operator has read rights only |
| `app/Http/Controllers/Concerns/RefusesDeferredGateway.php:130` | Paid plan checkout refused before any charge: this gateway is deferred from Phase 02. |
| `app/Http/Controllers/Concerns/RefusesDeferredGateway.php:180` | Paid plan activation refused: this gateway is deferred from Phase 02 and its payment evidence cannot be verified. |
| `app/Http/Controllers/Concerns/ResolvesPendingPartialPayment.php:135` | Pending partial payment lookup failed; the payment is refused. |
| `app/Http/Controllers/Concerns/RestoresCancelledOrderStock.php:92` | Order cancellation skipped a line with an unusable product id or quantity |
| `app/Http/Controllers/Concerns/RestoresCancelledOrderStock.php:151` | Order cancellation skipped a line whose product is not in the order |
| `app/Http/Controllers/Concerns/RestoresCancelledOrderStock.php:208` | Order cancellation skipped a line the stock record says never took stock out |
| `app/Http/Controllers/Concerns/RestoresCancelledOrderStock.php:252` | Order cancellation skipped a line whose variant does not belong to its product |
| `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:851` | customer login: refused a social row still holding its module-assigned password |
| `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:1113` | Newsletter subscription failed during customer registration |
| `app/Http/Controllers/DeliverySyriaStatusController.php:95` | Delivery Syria status webhook: invalid credentials. |
| `app/Http/Controllers/DeliverySyriaStatusController.php:179` | Zid store not found for order status sync. |
| `app/Http/Controllers/ESewaPaymentController.php:196` | Plan payment return rejected: claimed amount does not match the plan price. |
| `app/Http/Controllers/EasebuzzController.php:405` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/KhaltiPaymnetController.php:256` | Khalti plan purchase NOT activated: the capture was not confirmed for the server price |
| `app/Http/Controllers/KhaltiPaymnetController.php:466` | Khalti verification refused: no server-computed payable in the stored state. |
| `app/Http/Controllers/KhaltiPaymnetController.php:511` | Khalti order refused before any stock moved: the gateway rejected the capture |
| `app/Http/Controllers/NMIPayController.php:436` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/OrderController.php:442` | Manual order total did not match its components; the computed total was stored |
| `app/Http/Controllers/OrderController.php:1411` | order receipt could not be rendered |
| `app/Http/Controllers/OrderController.php:1573` | Return approval restored no stock: order carries no readable product_json |
| `app/Http/Controllers/OrderController.php:1619` | Return approval skipped a line whose stock was already credited |
| `app/Http/Controllers/OrderController.php:1639` | Return approval skipped a line with an unusable product id or quantity |
| `app/Http/Controllers/OrderController.php:1660` | Return approval skipped a line whose product is not in the order |
| `app/Http/Controllers/OrderController.php:1694` | Return approval skipped a line whose variant does not belong to its product |
| `app/Http/Controllers/OrderController.php:1809` | Order replacement refused. |
| `app/Http/Controllers/OrderController.php:2300` | Customer cancellation endpoint refused a status it does not serve |
| `app/Http/Controllers/OrderController.php:2518` | Order assignment refused: the delivery boy is not in a store this caller owns |
| `app/Http/Controllers/OrderRefundController.php:180` | Refund transition refused: unrecognised current state |
| `app/Http/Controllers/OrderRefundController.php:202` | Refund transition refused: illegal transition |
| `app/Http/Controllers/OrderRefundController.php:416` | Refund cancellation refused: the record does not match the order |
| `app/Http/Controllers/OrderRefundController.php:749` | Refund restock skipped a line with an unusable quantity |
| `app/Http/Controllers/OrderRefundController.php:765` | Refund restock clamped to the units the order lines lost |
| `app/Http/Controllers/OrderRefundController.php:1177` | Refund settled but the order totals were left unchanged: product_json is unparseable |
| `app/Http/Controllers/OzowController.php:217` | Ozow plan return refused: no TransactionId to key on. |
| `app/Http/Controllers/PaiementProPaymentController.php:357` | PaiementPro return recorded PENDING: this gateway is not provider-verified |
| `app/Http/Controllers/PaiementProPaymentController.php:488` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/PayHerePaymnetController.php:64` | PayHere plan checkout refused: the integration is retired (owner decision). |
| `app/Http/Controllers/PayHerePaymnetController.php:76` | PayHere plan checkout refused: the PayHere SDK is not installed. |
| `app/Http/Controllers/PayUPaymentController.php:311` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/PaymentController.php:554` | Paymera replay refused: the session presenting the reference did not place the order |
| `app/Http/Controllers/PaymentController.php:697` | Paymera callback refused: this payment is already being written. |
| `app/Http/Controllers/PaymentController.php:787` | Paymera callback could not identify the order it created; the pending record names none |
| `app/Http/Controllers/PaymentController.php:888` | Paymera pending record vanished before the order was created |
| `app/Http/Controllers/PaymentController.php:958` | Paymera callback signature NOT verified and not enforced |
| `app/Http/Controllers/PaymentController.php:1458` | Paymera trigger replay refused the order number: the session presenting the reference did not place the order |
| `app/Http/Controllers/PaymentController.php:1590` | Paymera trigger refused: the caller does not own the store the pending payment belongs to |
| `app/Http/Controllers/PaymentController.php:1669` | Paymera trigger refused: this payment is already being written. |
| `app/Http/Controllers/PaymentController.php:1751` | Paymera trigger could not identify the order it created; the pending record names none |
| `app/Http/Controllers/PaymentController.php:2005` | checkout: refused, basket names a product from another store |
| `app/Http/Controllers/PaymentController.php:2045` | checkout: refused, basket holds a non-positive quantity |
| `app/Http/Controllers/PaymentController.php:3067` | Checkout refused: this payment method cannot settle an outstanding balance. |
| `app/Http/Controllers/PaymentController.php:3084` | Checkout refused: no recorded balance matches this pending payment. |
| `app/Http/Controllers/PaymentController.php:3102` | Checkout refused: the posted pending-payment amount disagrees with the record. |
| `app/Http/Controllers/PaymentController.php:3510` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Http/Controllers/PaymentController.php:4784` | Telegram order message not sent: the store has no bot token or chat id |
| `app/Http/Controllers/PaymentController.php:4800` | Telegram refused an order message |
| `app/Http/Controllers/PaymentController.php:4806` | Telegram order message could not be sent |
| `app/Http/Controllers/PaymentController.php:4874` | Whatsapp order writer: posted slug does not match the route slug; the route wins. |
| `app/Http/Controllers/PaymentController.php:4998` | Order intake refused: this basket is already being written. |
| `app/Http/Controllers/PaymentController.php:2511` | Checkout refused: the request named a customer the intake did not resolve |
| `app/Http/Controllers/PaystackPaymentController.php:290` | Paystack plan activation refused: the transaction is not a matching success. |
| `app/Http/Controllers/PaystackPaymentController.php:377` | Paystack plan activation refused: this reference is already recorded against a different buyer or plan. |
| `app/Http/Controllers/PaystackPaymentController.php:444` | Paystack plan activation refused: the captured payment does not match the plan being activated. |
| `app/Http/Controllers/PaytmPaymentController.php:332` | Paytm plan return refused: no merchant key is configured, so the checksum proves nothing. |
| `app/Http/Controllers/PosController.php:405` | POS sale refused as a duplicate submission |
| `app/Http/Controllers/PosController.php:664` | POS sale failed after stock may have moved; the claim was released so the till can retry |
| `app/Http/Controllers/PosController.php:487` | POS sale skipped a line whose product is not this store |
| `app/Http/Controllers/StoreFinanceController.php:79` | Manual wallet change refused as a duplicate submission |
| `app/Http/Controllers/StoreFinanceController.php:365` | Financial action refused: operator has selected no merchant store |
| `app/Support/Tenancy/OperatorStoreSelection.php:94` | Report refused: operator has selected no merchant store |
| `app/Http/Controllers/StoreFinanceController.php:2461` | Manual wallet change refused as a duplicate submission |
| `app/Http/Controllers/StoreFinanceController.php:2662` | Manual wallet change refused as a duplicate submission |
| `app/Http/Controllers/SavedViewController.php:117` | Saved view apply refused: not the owner or no such view |
| `app/Http/Controllers/SavedViewController.php:159` | Saved view delete refused: not the owner or no such view |
| `app/Http/Controllers/SenangPayController.php:140` | SenangPay plan return refused: the return hash could not be verified. |
| `app/Http/Controllers/SenangPayController.php:150` | SenangPay plan return refused: return hash mismatch. |
| `app/Http/Controllers/SofortController.php:106` | Sofort return rejected: plaintext plan parameter disagrees with the signed token. |
| `app/Http/Controllers/SofortController.php:233` | Plan payment return rejected: claimed amount does not match the plan price. |
| `app/Http/Controllers/ZidOAuthController.php:31` | DS exchange failed |
| `app/Http/Middleware/ActiveTheme.php:35` | Storefront visit log failed |
| `app/Http/Middleware/AuctionBidIdentityGuard.php:410` | P1-SEC-61 auction bid refused: products.slug collision across stores. |
| `app/Http/Middleware/AuctionBidIdentityGuard.php:424` | P1-SEC-61 auction bid refused: bid target does not belong to this storefront. |
| `app/Http/Middleware/FlashedMessageSafety.php:74` | an exception object was flashed to a session and was replaced before it could be rendered |
| `app/Http/Middleware/LotteryDrawOwnershipGuard.php:260` | lottery draw refused |
| `app/Http/Middleware/ModuleOrderMutationGuard.php:378` | module order mutation refused |
| `app/Http/Middleware/ModuleResourceOwnershipGuard.php:559` | module resource mutation refused |
| `app/Models/Order.php:1159` | Order return refused: the order carries no readable product_json |
| `app/Models/Order.php:1170` | Order return refused: the order lines are not in a shape this workflow can return |
| `app/Models/Utility.php:2318` | New Order webhook skipped: no store could be resolved for the order |
| `app/Models/Utility.php:2328` | New Order webhook skipped: the resolved store does not exist |
| `app/Providers/PackageServiceProvider.php:80` | addon loader: the dynamic add-on pass failed, so some modules are not loaded |
| `app/Providers/PackageServiceProvider.php:144` | addon loader: a module failed to load and its features are absent |
| `app/Services/CodCollectionService.php:161` | COD collection refused: the event is not verifiable |
| `app/Services/CodCollectionService.php:176` | COD collection refused: the amount collected does not settle the order |
| `app/Services/CodCollectionService.php:187` | COD collection cannot be recorded: cod_collection_events has no table |
| `app/Services/DeliveryBoyNotificationService.php:65` | No active Delivery Firebase devices found for delivery boy. |
| `app/Services/Marketing/MarketingSendGate.php:102` | Marketing consent store could not be read |
| `app/Services/Marketing/EnforceMarketingConsentOnMail.php:95` | Marketing consent listener failed |
| `app/Services/Marketing/EnforceMarketingConsentOnMail.php:155` | Marketing email held by consent enforcement |
| `app/Services/Marketing/EnforceMarketingConsentOnMail.php:157` | Marketing email would be held by consent enforcement (report mode; it was sent) |
| `app/Services/Marketing/EnforceMarketingConsentOnMail.php:205` | Email sender is not classified for marketing consent |
| `app/Services/Marketing/MarketingSendGate.php:122` | Marketing message held by consent enforcement |
| `app/Services/Marketing/MarketingSendGate.php:134` | Marketing message would be held by consent enforcement (report mode; it was sent) |
| `app/Services/OrderTaxResolver.php:88` | Checkout: non-numeric client tax_price discarded |
| `app/Services/OrderTaxResolver.php:101` | Checkout: client-supplied tax_price discarded (server value used) |
| `app/Services/OrderTotalReconciler.php:173` | Order total reconciliation mismatch (warn-only) |
| `app/Services/PaymentFailure.php:22` | Payment could not be started |
| `app/Services/PaymentService.php:1876` | Benefit charge returned no redirect url |
| `app/Services/PaymentService.php:2536` | Pending partial payment refused: the balance named does not belong to this customer. |
| `app/Services/PaymentService.php:2639` | cod/bank_transfer order writer refused: basket names a product from another store |
| `app/Services/PaymentService.php:2658` | cod/bank_transfer order writer refused: basket holds an unusable quantity |
| `app/Services/PaymentService.php:2751` | Checkout: client-supplied shipping_final_price discarded (server value used) |
| `app/Services/PaymentService.php:4017` | PayHere storefront checkout refused: the integration is retired (owner decision). |
| `app/Services/PaymentVerificationService.php:340` | Order recorded as PENDING: no gateway verification for this payment |
| `app/Services/PaymentVerificationService.php:365` | Order recorded as PENDING: the verification belongs to a different checkout |
| `app/Services/PaymentVerificationService.php:378` | Order recorded as PENDING: the verification record is for another payment type |
| `app/Services/PaymentVerificationService.php:802` | COD_SETTLEMENT=order is NO LONGER HONOURED and is ignored |
| `app/Services/PaymentVerificationService.php:810` | Unrecognised COD settlement policy; settle-on-delivery is in force regardless |
| `app/Services/ProductStockService.php:111` | Stock decrement rejected: insufficient stock |
| `app/Services/ProductStockService.php:221` | Variant stock decrement rejected: insufficient stock |
| `app/Services/ProductStockService.php:452` | Could not flush the model cache after a stock change |
| `app/Services/RefundIntegrityService.php:147` | Refund recorded as approved but NOT settled: no gateway refund was made |
| `app/Services/RefundIntegrityService.php:234` | Refunds awaiting settlement could not be listed. |
| `app/Services/ShopifyAdminApi.php:137` | a Shopify Admin API address was refused before the request was made |
| `app/Services/ShopifyAdminApi.php:173` | a Shopify Admin API call answered with a redirect, which was not followed |
| `app/Services/SocialIdentity/JwksProvider.php:74` | social-identity: provider key fetch failed |
| `app/Services/SocialIdentity/SocialIdentityVerifier.php:239` | social-identity: facebook debug_token call failed |
| `app/Services/StockMovement.php:102` | stock movement not recorded: no store |
| `app/Services/StockMovement.php:127` | stock movement not recorded |
| `app/Services/StockMovement.php:214` | stock movement not readable |
| `app/Services/StockMovement.php:257` | stock movement not attributed |
| `app/Services/StoreNotificationService.php:311` | No active Firebase devices found for store. |
| `app/Services/StoreNotificationService.php:493` | Metric threshold alert could not be recorded |
| `app/Services/StoredUpload.php:90` | a stored upload path points outside the uploads directory and was not touched |
| `app/Services/StoredUpload.php:99` | a stored upload path names a platform default and was not touched |
| `app/Services/StoredUpload.php:117` | a stored upload path names another tenant |
| `app/Support/StoreBoundFilesystem.php:170` | a delete outside the current store was refused |
| `app/Support/Security/ObjectFreeEncrypter.php:59` | a decrypted payload was not plain data and was refused |
| `app/Support/Modules/ModuleAvailability.php:99` | ModuleAvailability could not read the enable flag; treating the module as available. |
| `app/Services/WebuzoDomainService.php:170` | New Webuzo domain added but old domain could not be deleted |
| `app/Http/Controllers/OtpIntegrationController.php:536` | OTP test refused: the provider address is not a permitted outbound host. |
| `app/Http/Controllers/OtpIntegrationController.php:632` | OTP test refused: the provider address is not a permitted outbound host. |
| `app/Http/Controllers/OtpIntegrationController.php:685` | OTP test failed with an exception. |
| `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:361` | Customer OTP send did not succeed. |
| `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:404` | Customer OTP send threw. |
| `app/Http/Controllers/PaymentCallbackController.php:243` | Paytm plan callback refused at the intent. |
| `app/Http/Controllers/PaymentCallbackController.php:260` | Paytm plan callback refused at the intent. |
| `app/Http/Controllers/PaymentCallbackController.php:274` | Paytm plan callback refused at the intent. |
| `app/Http/Middleware/DisabledModuleRouteGuard.php:220` | Disabled-module payment return refused: no initiation record is installed and the session holds none of the vendored payment state. |
| `app/Http/Middleware/DisabledModuleRouteGuard.php:228` | Disabled-module payment return passed through on the vendored session state: the initiation record is not installed. |
| `app/Http/Middleware/DisabledModuleRouteGuard.php:297` | Disabled-module payment return was allowed and then refused downstream; the initiation was put back. |
| `app/Http/Middleware/DisabledModuleRouteGuard.php:322` | Disabled-module payment return refused. |
| `app/Services/ModulePaymentLedger.php:164` | Module payment initiation not recorded. |
| `app/Services/ModulePaymentLedger.php:187` | Module payment initiation could not be marked abandoned. |
| `app/Services/ModulePaymentLedger.php:237` | Module payment initiation could not be completed. |
| `app/Services/ModulePaymentLedger.php:276` | Module payment initiation could not be reopened after a refused request. |
| `app/Services/ModulePaymentLedger.php:371` | Module payment refusal not stored: the hourly bound for this module is reached. |
| `app/Services/ModulePaymentLedger.php:392` | Module payment refusal not recorded. |
| `app/Services/PaytmTransactionVerifier.php:242` | Paytm transaction status answered for a different order than the one asked about. |
| `app/Services/PaytmTransactionVerifier.php:248` | Paytm transaction status answered for a different merchant than the one asked about. |
| `app/Services/PaytmTransactionVerifier.php:311` | Paytm reported a successful transaction that also mentions a refund; not verifying it. |

<!-- counts: error=95 info=22 debug=1 -->

---

### The eighteen rows added 2026-11-25 (#204), with their destination and reader

**One `critical`** — `app/Http/Controllers/PaymentCallbackController.php:280`, *Paytm plan callback:
the payment is verified but could not be settled. Nothing was activated and this needs a human.*

**Seventeen `warning`** — three more refusal lines in the same controller, four in
`DisabledModuleRouteGuard`, six in `ModulePaymentLedger`, and two in `PaytmTransactionVerifier`.

**Where they land:** the application log, via `Log::warning` / `Log::critical`.

**WHAT NONE OF THEM CONTAINS, and it is the reason to read this section rather than skim it:** no
payment reference, no card data, no provider credential and no driver message. The ledger's catch
blocks log the exception CLASS and not `getMessage()`, because a `QueryException` renders the SQL
with its bindings — and one of those bindings is the 128-bit reference that authenticates a payer's
return, so the message would have put a live credential in the log. Independent review found that;
the context keys are the module, the route PATTERN and a boolean.

**Who reads them, and what they do:**

| line | what it means | the action |
|---|---|---|
| `PaymentCallbackController:280` (`critical`) | a Paytm plan payment is **verified by the provider** and could not be settled — a purchaser or plan that no longer exists, a transaction already recorded as somebody's order, or a settlement that raised. Nothing was activated and the intent was left open | the platform operator, and it is **never expected**. `payments:reconcile` lists the same purchase with its reason; the money is real and the entitlement is not |
| `PaymentCallbackController:107`, `:159`, `:173`, `:221`, `:238`, `:252` | the callback refused: no reference, a provider that did not confirm, or an intent that does not match. Routine for an unopened gate and for probes | nobody, in volume. Read when a buyer reports a Paytm purchase that did not activate — the reason says which of the six it was |
| `DisabledModuleRouteGuard:322` | a payment return to a **switched-off** application was refused for want of an initiation. **May be a payer whose money was taken and whose session expired** | the operator, through `payments:reconcile`, which lists these with their repeat counts. Caller-driven volume, so the row is what to read, not the log |
| `DisabledModuleRouteGuard:297` | a return this boundary allowed was then refused further down, so the initiation was put back | the operator. Expected for Campaigns while that module is off (its writer is behind its own entitlement gate) and worth investigating for the other two |
| `DisabledModuleRouteGuard:220` | a return was refused because **neither** this host's initiation record nor the vendored session state was present | the operator. On an installation where `module_payment_initiations` has not been migrated this is the only control, so a burst means somebody is probing a disabled module's return URL |
| `DisabledModuleRouteGuard:228` | a return was allowed on the vendored session state alone, because the initiation record is not installed | the operator, **once**: it says the boundary is running in its weaker mode and the migration is outstanding |
| `ModulePaymentLedger:164`, `:187`, `:237`, `:276`, `:392` | the record could not be written. Every one of these **fails open on purpose** — a failure to record must never become a failure to pay | the operator. Each means the boundary has lost part of its evidence while the payment path keeps working; the action is to make the table readable, not to change any module's flag. Never expected |
| `ModulePaymentLedger:371` | the hourly bound on stored refusals for one module is reached, so further distinct refusals that hour are logged and not stored | the operator. It means anonymous traffic is hitting a disabled module's return URL hard enough to matter, and that a genuine refused payer in that window may be in the log rather than in the list |
| `PaytmTransactionVerifier:242`, `:248` | the provider's status response was **about a different order or a different merchant** than the one asked about | the operator, and it is never expected behind a validated certificate: it needs Paytm itself to answer the wrong question. Nothing was activated |
| `PaytmTransactionVerifier:311` | a successful transaction whose response **also mentions a refund**. Nothing was activated, and the intent is left open | the operator. This is how a payment the operator refunded in the Paytm dashboard stops being settled by a later reconciliation run — so it is EXPECTED for a refunded sale, and worth reading because it names the purchase a person has to decide about. It also fires if Paytm ever sends a refund-shaped field this server cannot interpret, which is deliberate: no contract for reading one is established here |

### The row added 2026-11-25 (the settled-order payment echo fix / GAP-257), with its destination and reader

`app/Http/Controllers/Api/DashboardController.php:1044` —
*Payment status changed on a settled order.*

**Why it exists at all, and why it is a log rather than a refusal.** `updateStatus` now lets an
echoed `delivered_status` through, because the shipped app sends the order's own value on every
payment-only change and two guards were reading that echo as a transition — so a merchant could not
record a payment on a cancelled or returned order at all. The request that is now permitted can
also move money: `payment_status` reaches `OrderFinancialService::syncFromOrder()` through
`OrderObserver`, and a `Paid` there runs `markFinancialAsPaid()`.

P4-F124's guard already covers the common case — it returns early when the financial row is already
`cancelled` or `refunded` — but **not every case**: a RETURNED order's row is moved to `refunded`
only by the refund door, so a returned-but-unrefunded order's `Paid` credits the full
`customer_total`. A refusal here would re-break the regression the fix closes, and would leave the
web twin open regardless: `OrderController::order_payment_status` writes `payment_status` with no
`delivered_status` predicate of any kind, behind the same `Manage Order` ability. **So the rule
question is registered as `GAP-257` for an owner decision, and this line is what makes the interval
reconstructible.**

**Destination.** The default channel, as every other row in this inventory — so whatever
`LOG_CHANNEL` resolves to on the host, which §2 of this document measures rather than assumes.

**Reader: the merchant's own operator, after the fact, not an alert.** It carries the actor, the
store, the order both ways round and both payment values, which is exactly the set needed to decide
whether a credit was legitimate. It is **expected to fire occasionally** — a COD bag handed over on
a returned order and a bank transfer clearing after a cancellation are ordinary — so it is
deliberately `warning` and not `critical`: it is the audit trail for a permitted action, not a
report of a fault. If it fires on an order whose financial row is non-terminal, that is the
GAP-257 shape and the wallet is worth checking against `payments:reconcile`.

### The row added 2026-11-24 (P06-2B review F9), with its destination and reader

`app/Support/Modules/ModuleAvailability.php:99` —
*ModuleAvailability could not read the enable flag; treating the module as available.*

**Why it exists at all.** `ModuleAvailability::isDisabled()` is the predicate behind
`DisabledModuleRouteGuard`: it answers whether an application has been switched off, and the guard
refuses that application's authenticated routes when it says yes. It is deliberately **fail-open** —
a database that cannot be read must not make every module's routes refuse — and until this row it
swallowed its `Throwable` with **no log at all**.

Independent review named the shape: its sibling at `PackageServiceProvider` logs `critical` for the
same class of failure, and this project's own P1-PLT-04 lesson was that a broken install *did not
fail, it DISAPPEARED*. An availability control that silently stops controlling is exactly that, so
it now says so while keeping the open direction.

**Where it lands:** the application log, via `Log::warning`, with `module` and `exception` so the
reader knows which application stopped being gated and why.

**Who reads it, and what they do.** The platform operator. This line means route-level enforcement
of the disabled-application boundary is **not happening** for the named module — the module's
authenticated routes are being served whatever its `add_on_managers.is_enable` says. The action is
to check that `add_on_managers` is readable (the message carries the database error), not to change
the module's flag. **It is never expected in normal operation**, so one occurrence is worth
investigating; it cannot be caused by a module simply having no row, which is a separate, silent and
correct "not disabled".

### The row added 2026-11-21 (ANA-009), with its destination and reader

**`StoreNotificationService::metricThresholdBreached()` — "Metric threshold alert could not be
recorded", `warning`, one line.** Fires when a merchant's own metric floor was breached, the breach
was computed correctly, and the NOTICE about it could not be written. The `try/catch` around it is
deliberate and matches its siblings in the same service: the alert runs inside an hourly command
that sweeps every store with a configured floor, so an exception on one store must not stop the
other stores' alerts. Swallowing it silently would be the defect; this line is the swallow's record.

**Where it lands:** the application log, via `Log::warning`, with `store_id` and `metric` so one
store's failing alert can be told from a platform-wide one.

**Who reads it, and what to do when it fires:** whoever maintains the analytics alerts. A SINGLE
occurrence is already worth reading, because the consequence is silence: the merchant asked to be
told when a figure fell below their number, the figure did fall below it, and they were not told.
Nothing on any screen says so — the only other trace is the absence of a row in
`store_notifications`. A BURST across many `store_id` values is a platform fault (the notifications
table, the store-owner lookup) rather than one merchant's; a burst on ONE `store_id` points at that
store's row (an owner `created_by` that no longer resolves to a user is the known shape, and
`getStoreOwnerId()` returning null is handled separately and writes nothing).

**Why `warning` and not `critical`:** no money moved, no figure is wrong and nothing is
inconsistent. What is lost is a notification, and the figure it was about is still correct on the
merchant's own screens. It is `warning` rather than `error` for the same reason as its siblings in
this service: the tick continued and every other store's alert was still written.

**What this line does NOT cover, because the feature does not do it:** delivery. ANA-009 writes a
notification and sends no e-mail and no push (`push_sent` is written `false`, and
`MetricThresholdAlertTest::test_the_alert_is_not_pushed_and_that_is_the_contract` pins that). So
there is no send-failure line here to look for, and the absence of one is not evidence that sending
worked.

### The row added 2026-11-21 (GAP-223(b)), with its destination and reader

**`OperatorStoreSelection::logRefusal()` — "Report refused: operator has selected no merchant
store", `warning`, one line.** Fires when a platform operator opens a report surface without having
selected a merchant store. Before GAP-223(b) that principal was silently served STORE 1 -- an
ordinary merchant's tenant, not a platform scope -- because `getCurrentStore()` ends in a hard-coded
literal 1 for `type = 'super admin'`. The report now scopes to `OperatorStoreSelection::NO_STORE`,
which selects no rows, and this line is what distinguishes that deliberate refusal from a tenant
that genuinely has no data.

**Where it lands:** the application log, via `Log::warning` — the same channel and the same message
text as its finance sibling at `StoreFinanceController:365`, deliberately, so a reader grepping one
finds both halves of the same rule.

**Who reads it, and what to do when it fires:** nobody needs to act on a single occurrence — it is
the expected outcome of an operator browsing before choosing a store, and the remedy is in their
hands (select a store, or use the platform-wide analytics surface, which is separately authorized).
It is worth reading in two situations. A BURST from one `user_id` means an operator is repeatedly
hitting empty reports and has not understood that a selection is required, which is a product
problem rather than a security one: the screens narrow silently and do not yet carry an on-screen
notice saying why they are empty. That notice is recorded as outstanding UI work on the `GAP-223`
row, and this log is the only present signal that it is needed. And its ABSENCE matters: if these
stop appearing entirely while operators still use reports without choosing a store, the guard has
been bypassed or removed, and `OperatorUnchosenStoreReportsTest` is what should have caught that.

**Why `warning` and not `critical`:** nothing is lost, nothing is inconsistent, and no money moved.
A refusal that works is not an incident.

---

### The three rows added 2026-10-28 (D-13 / D-22), with their destination and reader

The ratchet's resync tool refuses to write when a line is **added** rather than moved, because a new
row needs a destination and a reader that only a person can state. These are those statements.

**`PaymentController` — "the capture is unreconciled and the record is left retryable", `critical`,
two lines (the browser callback and the gateway trigger).** F186. Fires when Paymera's payment
verification succeeded and the shared order writer then returned without writing a row — an empty
basket, a stock failure, a quantity or foreign-product refusal, a missing product row, `User not
found`. None of those throws, so the F96 catch does not run and this is the ONLY record that money
reached the gateway and produced nothing. **What to do when it fires:** the capture is real and
owed an order. `paymeraOrderEstablished()` has left `$pending['processed']` false, so a retry
within the pending record's two-hour life — once the 60-minute `paymera_claim_<reference>` lapses —
will write the order if the cause was transient. If it is not transient, the payment needs manual
reconciliation against the gateway; it is NOT refunded or voided by anything in this codebase. No
line numbers are cited here on purpose: this file's table is machine-resynced and prose citations
rot.

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

### The two rows added 2026-10-29 for F192, `critical`

**`PaymentController.php` — the pre-transaction stock give-back, once in each order writer.** They
are `critical` for the same reason the transaction's own give-back is: inventory moved and no order
accounts for it. What distinguishes them from that one is **which window** the failure happened in,
and the message says so — *"before the transaction opened"* against *"was rolled back"*. Keep them
distinguishable: a run of the pre-transaction line points at a throw between the stock loop and
`beginTransaction`, which is a different code region with different causes.

**Reader:** whoever reconciles inventory. `restored_lines` is the count given back and `error`
carries the throw's message. On a gateway-return path this line also means the buyer has **paid**,
so a single occurrence is worth a look rather than a trend.

**What they do NOT mean:** that stock was lost. They fire when it was given *back*. The silent case
is the one to fear, and it is now covered in both windows of both writers.

**Destination:** the same channel as every other row here (section 2), with the same caveat.

---

### The seven rows added 2026-11-01 (F241 and F263), with their destination and reader

The resync tool refuses to write when a line is **added** rather than moved, because a new row needs
a destination and a reader that only a person can state. These are those statements. **No line
numbers are cited in this prose on purpose:** the table above is machine-resynced and prose citations
rot — that rule is this document's own, learned from P1-ORD-15.

**`PaymentCallbackController` — the F241 Paytm plan callback. Five lines, and the route is GATED
OFF.** `config('payments.paytm_callback_enabled')` opens only on an unambiguous yes, and it ships
shut because no LIVE Paytm transaction has been verified from this container — there are no Paytm
credentials here and no network path to `securegw.paytm.in`. So none of these five can fire in a
deployment that has not deliberately opened the gate. They are inventoried now rather than when it
opens, because the lines exist in `app/` and a line with no stated destination is exactly what this
document exists to prevent.

- **"no usable order reference", `warning`.** Read by whoever opened the gate, during the first
  sandbox run. A burst means Paytm is posting a field this handler does not read — it accepts
  `ORDERID` and `orderid` only — so it is a misconfiguration rather than an attack.
- **"the provider did not confirm the payment", `warning`.** The one to watch. It covers an
  unreachable status endpoint, a failed checksum and a malformed response alike, so a steady stream
  is an outage while a single line beside a complaining merchant is a captured payment that could
  not be confirmed. **It does not depend on anybody reading the log:** the refusal is also recorded
  against the intent, so `PlanPurchaseIntent::unreconciled()` surfaces it for a human.
- **"refused at the intent", `warning`.** `state` is what distinguishes a benign replay — a provider
  retrying a delivery it already made, answered 200 on purpose so it stops retrying — from an amount
  or currency mismatch, which is a misconfiguration and is kept OPEN by `recordRecoverableRefusal()`
  rather than closed, because closing an intent over a display-label mismatch would destroy a
  captured payment.
- **"the purchaser no longer exists", `critical`.** **A person, the same day.** Money has moved, the
  intent is spent, and no plan was granted. It cannot be retried, and that is the deliberate trade:
  the alternative leaves the intent open while a plan may or may not exist, which is the ambiguity
  `unreconciled()` exists to prevent. Resolve by granting the plan by hand or refunding.
- **"assignPlan refused", `critical`.** **A person, the same day**, for the same reason. `reason`
  carries `assignPlan()`'s own message, which is where to start.

**`ShippingController` and `TaxMethodController` — the F263 platform-operator write refusals,
`warning`, one line each.** Twins of the `ShippingMethodController` row already in the table, and
they exist for the same reason: the refusal is the owner's F157 decision — *the operator reads and
exports, it does not rewrite a merchant's commercial settings* — and without a line an operator who
tried would see only "Permission denied." and reasonably report it as a bug. Read by whoever supports
the operator. Repeated lines from one operator mean the **decision** needs revisiting, not the code.
The tax one is worth distinguishing from the shipping one because a tax rate is a money input: an
operator trying to correct a merchant's rate is a support request this line turns into a visible
event.

**None of the seven carries a credential, a token, an email address or a card field.** The Paytm rows
log the merchant order reference, the provider's own status and reason strings, and internal ids; the
two F263 rows log the acting user's id, their `current_store` and the permission name.

### The two rows added 2026-11-02 (F283), with their destination and reader

**Both are tenant refusals on a shipping calculation, and the pair is deliberate: one guards the
QUOTE and one guards the PAYABLE.** They are the same sentence on purpose, distinguished only by
which calculation refused, because the thing an operator needs to know first is which of the two
fired.

- **`Api\ApiController::productOfCartLineStore()` — "shipping quote refused a cart line naming
  another store's product", `warning`.** Read by whoever investigates a shopper complaining that a
  quote looks wrong, and by security if it ever appears in volume. It fires when a cart row in one
  store names a product in another — a row every cart writer refuses today
  (`addtocart`'s hard store match, `product_cart`'s provable-mismatch refusal, and
  `ModuleCartWriteGuard` for the two module routes), so **a single line means a row that predates
  those guards or a writer that escaped them, and a burst means a new writer has been added without
  one.** It logs the cart's store, the product's store and the product id: no price, no customer
  field, no credential. The quote still answers — the line simply contributes nothing — so nothing
  else surfaces this.
- **`Cart::productOfStoreForShipping()` — "flat-rate shipping refused a cart line naming another
  store's product", `warning`.** The same condition on the path that **charges money**: this
  method's result becomes `$order_array['delivery_charge']` and from there
  `orders.delivery_price` and `orders.final_price`. **Read with more urgency than its twin for
  exactly that reason** — a line here means a foreign row reached an order's shipping arithmetic,
  so the orders written around that time are worth checking even though the refusal means the
  figure itself is sound. Same fields, same absence of anything sensitive.

**Neither is reachable by an anonymous caller**: both sit behind a slug-resolved store and, on the
API path, `resolveStoreCustomer()`.

### The three rows added 2026-11-15 (Phase 03 M4), with their destination and reader

**All three are theme-editor refusals, and they exist because M4 narrowed who may change a live
storefront.** The owner's decision of 2026-11-15 restricts live theme writes, publishing and
rollback to the verified tenant owner, a same-tenant holder of `Manage Themes`, or the platform
operator naming a store explicitly. A refusal is therefore the normal consequence of a merchant who
has delegated editing without granting the permission — and also what an attempt to act on another
tenant's store looks like. The three are deliberately distinguishable by which layer refused.

- **`ThemeEditorAccess::handle()` — "Theme editor access refused.", `warning`.** The middleware on
  all eight theme routes, so this is the **outermost** refusal and the one that fires most often.
  Read by support when a merchant says "I cannot open the theme editor": the usual answer is that
  they or their staff member holds no `Manage Themes` and does not own the store. It logs the route
  name, the HTTP method, the user id and the gate's reason — no store settings, no payload, no
  credential. **A burst from one user id is a misconfigured role; a burst from many is a seeding or
  migration problem with that permission**, which matters because `Manage Themes` is granted by
  `Utility::addNewData()` on login rather than by a migration.

- **`ThemeCustomizeController::editorStoreId()` — "Theme editor read refused.", `warning`.** The
  same question asked again inside the handler, as defence in depth. **It should be rare**, because
  the middleware refuses first: a line here means a request reached the controller that the
  middleware admitted, so it is worth reading as a possible gap between the two rather than as an
  ordinary permission failure. Logs the action, the user id and the reason.

- **`ThemeVersionController::refuseWithAudit()` — "Theme publication refused.", `warning`,
  alongside an `audit_logs` row.** The one that **matters most to read**, because publishing and
  rollback change what shoppers see. It fires for a stale optimistic-concurrency expectation — two
  people with the editor open — as well as for an authorization refusal, so a single line is
  usually two merchants colliding and **a repeated line for one store is worth asking about**.
  Logs the action, the store id, the theme and the reason; the paired audit row carries the actor,
  the version id and the same reason.

**The store id in all three comes from `ThemeEditorGate`, never `getCurrentStore()`** — that
resolver returns the literal `1` for an operator whose `current_store` is empty, so logging through
it would attribute an operator's refusal to whichever store happens to be 1. The audit row is
written directly for the same reason rather than through `AuditLog::record()`, which does use it.

**A fourth was added 2026-11-15 with M4-7**, and its rarity is the point.
`ThemeDraftAccess::handle()` — **"Theme draft access refused.", `warning`.** It guards the WIDER
draft boundary (tenant membership) rather than the narrow publish one, so it refuses only a
principal who is not a member of the tenant at all. **A merchant or staff member should never
produce this line**: the ones who legitimately cannot publish are admitted here and refused later by
`theme.editor`. So unlike its editor-access sibling, a line here is not the ordinary
"staff lacks `Manage Themes`" case — it means someone reached a draft endpoint for a store they do
not belong to, and is worth reading as such. Same fields: route, user id, gate reason.

**None is reachable anonymously**: every theme route sits behind `auth` and the `theme.editor`
middleware, and the middleware redirects an unauthenticated caller to sign-in before any of this
runs.

### The row added 2026-11-18 (P1-SEC-72), with its destination and reader

**`upload_theme_image()` -- "an image upload was refused", `warning`.** The helper behind seven upload call sites (a storefront
customer's avatar, the admin API's three payment images, the web payment settings, two vendored sign-in settings handlers) used
to store whatever it was handed under the extension the client named; it now stores images only. **This is the line it writes
when it refuses for TYPE, NAME or SIZE.** A refusal for the plan's storage and a failed write write none (the caller's own
answer says so), and neither does an upload PHP itself dropped, in the avatar handler: its own `'image' => 'required'` refuses
that first. It goes where every row in this table goes (section 2).

- **What it holds:** `sent` (`file`, or the PHP type of whatever came instead of one), `client_extension` (a word from a fixed
  list -- `php`, `html`, `png`, ... -- or `none`, or `other`; **never the client's own characters**: an "extension" is whatever
  follows the last dot of a name a stranger typed, and for `scan.Jane Roe` that is a person's name), `sniffed_type` (what the
  bytes are; null for an upload PHP dropped), `kilobytes` (the same), `customer_upload` (true for the storefront avatar, false
  for the panel's callers) and `store`. **Not the file's name**, and never its content. `ThemeImageUploadTypeTest` pins the keys
  and the vocabulary.
- **How to read it:** a `client_extension` of `php`, `phtml`, `phar`, `html`, `js`, `htaccess` or `svg` with
  `customer_upload: true` is somebody trying the door P1-SEC-72 closed, and a run of them from one store is somebody working
  at it. An image type with an image extension and a large `kilobytes` is a shopper with a picture over the ceiling **where PHP's
  `upload_max_filesize` is above 2M**; where it is 2M (its default, and this machine's) PHP refuses a larger file first, at the
  handler, and this line is not written. From the panel (`customer_upload: false`) the ordinary cause is a merchant who picked a
  PDF for a gateway logo.
- **How often:** the storefront path is behind the API group's throttle (120 requests a minute for one client), which is the
  ceiling on this line too.
- **WHAT IT CANNOT TELL ANYONE, and this is the part to read twice:** it records attempts made AFTER the fix is deployed.
  Before the fix the same request SUCCEEDED and this line did not exist, so an empty log of this kind is not evidence that
  nothing was ever stored. **But the API log did record the avatar route** (`API_log`, in the section 2 table: daily, 15
  days): `APILog` writes the URL, the scrubbed input, the status and the scrubbed response, and the avatar handler's response IS
  the stored path, so each upload left a line naming it, extension included. It is the one read-only record of whether the door
  was used, and it expires. For the three admin payment routes it says that a file was sent, not where it went; for the web
  payment settings, nothing. Whether anything was stored is answered by looking at `uploads/` and at `storage/logs/api-*.log`
  on the server, which is the owner's to do (P01-SECURITY-WORK-QUEUE, P1-SEC-72 f).

### The row added 2026-11-19 (P1-SEC-73), with its destination and reader

**`PlatformUploadPolicy::refusal()` -- "a platform upload was refused", `warning`.** The policy behind the platform's four
uploader functions (`Utility::upload_file`, `keyWiseUpload_file`, `jsonUpload_file`, and `multi_upload_file` in helper.php: 128
calls, 72 first-party and 56 in vendored modules -- `php tests/Support/Security/bin/census-platform-uploader-callers.php`) writes this
line when it refuses a file for ANY reason. It goes where every row in this table goes (section 2).

- **What it holds:** `reason` (`not_a_file`, `no_types`, `name`, `inner_extension`, `rule`, `extension`, `client_extension`), `sent`
  (`file`, or the PHP type of whatever came instead of one), `client_extension` (a word from the fixed list in
  `ThemeImageUpload::extensionWord()`, `none` or `other`; null when no file came -- never the client's own characters) and
  `shopper` (true when the request was NOT a panel request: a route that demands no panel login, or a customer's or a driver's
  token -- a visitor holding a `web` login on a storefront route is still a shopper). **Not the file's name**, and never its
  content. `PlatformUploaderTypeTest` pins the keys, the vocabulary, and the reason each hostile name is refused for.
- **How to read it:** `reason: name` or `inner_extension`, with `shopper: true` and an extension of `php`, `html`, `js` or
  `htaccess`, is somebody trying a door. `reason: rule` with an image extension is a file whose bytes are not what its name says,
  or one over the size, or a type the list does not carry. **`reason: extension` is a file whose type the list accepts but whose
  STORED name does not end in one of the list's extensions** -- a caller that builds a name which does not end in its extension, or a
  fixed name over a client upload of another type; with `shopper: false` and an ordinary extension it is a legitimate upload
  refused, and the caller needs the shape fixed (as the guest checkout's receipt did).
- **How often:** the API paths are behind the API group's throttle; the storefront paths (tickets, a guest checkout) have none, so
  a run of these from one visitor is an unthrottled door being tried.
- **WHAT IT CANNOT TELL ANYONE:** it records attempts made after the policy is deployed. Before it the same files were stored and
  nothing was logged, so an empty log of this kind is not evidence that nothing was ever stored; what is on disk is the owner's to
  look at (P01-SECURITY-WORK-QUEUE, P1-SEC-73 f).

### The row added 2026-11-19 (P1-SEC-70), with its destination and reader

**`StoreBoundFilesystem::refused()` -- "a delete outside the current store was refused", `warning`.** During a POST to one of the two vendored
sign-in handlers (`SignInWithFacebookController@facebookSettings`, `SignInWithLinkedInController@LinkedinSettings`) the `File` facade is a
`StoreBoundFilesystem` (`SignInImageDeleteGuard`, on the `web` group). The vendored handler's `File::delete(base_path($row->value))` is carried out
only if the file, **as the operating system resolves it**, is in a folder the signed-in user's CURRENT store (or one its owner also runs) may delete
from AND the row the handler chose is that store's; otherwise it is refused and this line is written. It goes where every row in this table goes
(section 2).

- **What it holds:** `store`, the signed-in user's current store (an integer; `0` when no store resolves), and `reason`: **`row`** (the row the
  handler chose is not this store's -- another tenant's, or one with no store), **`path`** (the file is in an upload folder this store may not delete
  from), **`outside`** (the file is neither in an upload folder nor in a framework working area: `.env`, `config/...`, a vendored asset), **`no store`**,
  or **`directory`** (a `deleteDirectory()` / `cleanDirectory()` call). **Not the path** -- the path is a settings row's value, which another tenant may
  have written -- and not the other store's id.
- **How to read it:** one line means a handler was about to delete a file that this store may not. **`row` is common and harmless**: the tenant that
  first saved the form owns the row the handler finds, so every LATER tenant's save names that tenant's file -- expect one `row` line per save by
  every tenant but the first, for as long as the modules stay as they are. **`path` and `outside` are somebody trying a door**: a merchant wrote a
  `*_sign_in_image` row naming somebody else's file, or a file that is not an upload at all (and, on a case-insensitive collation, probably under an
  accent-spelled name that the write-side rule never saw), and what tells them apart is the row -- its `name` (compare `HEX(name)`), `store_id` and
  `value` in `settings` -- not the log.
- **WHAT IT CANNOT TELL ANYONE:** attempts made before it was deployed; **an allowed delete writes nothing, so the absence of a line proves nothing
  about what was deleted**; and that the same defect leaves a tenant's *own* previous image on disk when an older row of that name is somebody
  else's (a leak of disk, not logged).

### The row added 2026-11-19 (P1-SEC-77), with its destination and reader

**`ObjectFreeEncrypter::decrypt()` -- "a decrypted payload was not plain data and was refused", `warning`.** The container's encrypter
(`AppServiceProvider::register()` extends the framework's) decrypts and verifies exactly as the framework does and then looks at the decrypted
bytes BEFORE PHP does: they must be, exactly, the serialization of plain data (null, a boolean, an integer, a float, a string, or an array of
those). A payload that **decrypted correctly -- valid MAC, under this application's key -- and is anything else** (an object, an enum, a
custom-serialised object, a reference, trailing bytes, a nest deeper than sixteen levels) is refused with the `DecryptException` a tampered
payload gets, and this line is written. It goes where every row in this table goes (section 2).

- **What it holds:** nothing. **No class name, no payload, no route** -- the class name is the attacker's text, and the encrypter does not know
  the request. The line's timestamp is what ties it to an entry in the web server's access log.
- **How to read it:** the application never encrypts anything but an id or an array of scalars -- a census of every `encrypt(` call in `app/`,
  `resources/` and the modules finds an id (`$plan->id`, `$order->id`, `$user->id`, `$ticket->ticket_id`) or an array of scalars (a gateway's
  return payload). So one line is one of two things: **somebody who holds the key (it is in the public history: P1-SEC-40) forged a payload,
  which is the attack this exists to stop**; or **a flow somebody added encrypts something that is not plain data and now fails**, which is how
  that regression would announce itself instead of failing in silence. A payment return URL or an order link in the access log at that second
  says which.
- **WHAT IT CANNOT TELL ANYONE:** attempts made before it was deployed; which route or which of the 107 call sites was reached (the line is
  written inside the encrypter); and anything about a forged payload that **is** plain data -- an encrypted id is still a forged id, and the
  ownership checks (P1-SEC-68 and the guards) are what judge that. Nothing is logged for a ciphertext whose MAC is wrong: the framework throws
  before this runs.

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

### The five rows added or changed 2026-11-02 (P1-SEC-55), with their destination and reader

`resync-observability-inventory.py` re-pointed three rows that only moved and **refused** the five
below, which is the division working as intended: the tool can follow code, only a person can say
who reads a line. These are those statements.

**`OtpIntegrationController` — "OTP test refused: the provider address is not a permitted outbound
host.", `warning`, TWO lines (the SMS branch and the WhatsApp branch).** A merchant pressed *test*
and `SafeRemoteFetch::check` refused the destination, so **nothing was sent and no credential left
the server.** Context: `store_id`, `base_url`, `came_from_request`, and `channel` on the WhatsApp
line. **Reader: the platform operator, when a merchant reports that testing their OTP provider does
not work.** It is the only record of *why* the refusal happened, because the caller is deliberately
told nothing more than "that address is not allowed" — a precise reason would make the endpoint an
internal-network oracle, which is the defect P1-SEC-55 closed.

**The message text changed** from "the base URL is not a permitted outbound host" because the base
URL is no longer what is judged: `OtpTransport` passes the complete url, path included, so what is
judged is what is requested. One row became two because the WhatsApp branch had no guard at all.
`came_from_request` distinguishes a merchant testing an unsaved address from a stored one already
in the integration row — the second is the more serious reading and the field is how an operator
tells them apart.

**`OtpIntegrationController` — "OTP test failed with an exception.", `warning`, one line.** Replaces
returning `$e->getMessage()` to the caller. The exception text is the thing that turned this
endpoint into a port scanner (`cURL error 7: ... port 6379 ... Connection refused`), so it is logged
with `store_id` and handed to nobody. **Reader: the platform operator.** `OtpTransport` does not
throw for a network failure — it answers `FAILED` with no detail — so this path is now reached only
by a genuine programming fault, which is exactly what belongs in a log.

**`CustomerLoginController` — "Customer OTP send did not succeed.", `warning`, one line.** The
customer-facing half, and the one that mattered most: the route carries **no auth**
(`web, ActiveTheme, ThemeLanguage, ThrottleRequests:auth`), and it used to flash the provider's own
body to the anonymous caller. Measured: a remote banner surfaced as
`session('error') = 'INTERNAL-SERVICE-BANNER: redis 7.0.11 on 127.0.0.1'`. The caller now gets one
fixed string and the detail comes here: `store_id`, `customer_id`, `channel`, the transport
`outcome`, and the provider's `status` and `message`. **Reader: the platform operator, for a
merchant whose customers cannot receive a reset code.**

**`CustomerLoginController` — "Customer OTP send threw.", `warning`, one line.** The other half of
the same oracle: a cURL failure names the host and the port it could not reach, and it was flashed
to the same anonymous caller. Context: `store_slug` and the exception message. **Reader: the
platform operator.**

**None of the five carries the `api_secret`, a token, an email address, a phone number or a card
field.** The two `CustomerLoginController` rows log a `customer_id` — an internal integer, not a
contact detail — and `provider_message`, which is the provider's own text about its own API and is
not read back to any customer. The `base_url` on the refusal rows is a merchant-configured endpoint,
which is the subject of the finding and the reason the row exists.

**Section 2 applies to all five.** They land in `storage/logs/` under the `stack`/`daily` channel
like every other row in this document, and nothing forwards them. That is a deployment decision,
not a code one.

---

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
