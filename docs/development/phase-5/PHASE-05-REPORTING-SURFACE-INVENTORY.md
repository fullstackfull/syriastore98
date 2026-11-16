# Phase 05 — reporting-surface inventory

**READ-ONLY DISCOVERY. Measured 2026-11-16 against `feature/phase-04-commerce` @ `50436028`.**

**Phase 05 engineering has NOT started and must not start until the financial-policy decisions in
`docs/finance/P4-FINANCIAL-POLICY-DECISION-PACKAGE.md` are resolved.** This document is the
inventory those decisions need, and nothing more. No analytics were built, no figure was changed, no
ledger meaning was assumed.

---

## 1. The headline, measured

**`app/Http/Controllers/ReportController.php` is 3660 lines and contains ZERO `payment_status`
predicates.**

```
$ wc -l   app/Http/Controllers/ReportController.php   ->  3660
$ grep -c 'payment_status'  ...                       ->     0
$ grep -c 'delivered_status' ...                      ->     4
```

Every revenue figure the merchant reporting suite produces — gross sale, net sale, coupon total,
shipping total, average sale, per-product / per-category / per-brand sales, and the four export
endpoints — is computed over **every order in the window, whatever its payment state**: unpaid,
cancelled, refunded, COD never collected.

The only payment-adjacent filter in the whole reporting surface is the dashboard's `$total_revenues`
(`HomeController`), and it filters on `delivered_status != cancel` — **fulfilment, not payment**.

**This is why Phase 05 cannot start.** The reports read exactly the columns whose meaning is
unresolved, and today they read them with no predicate at all. Choosing a predicate *is* the
financial-policy decision; building analytics first would bake in whichever meaning happened to be
convenient.

---

## 2. Scope and method

Searched read-only at `50436028`. Schema was read first — from the create migrations, not from the
register — so the column names are the real ones. Worth recording: **`orders` money columns are
`FLOAT`, not `decimal`**, and `payment_status` is a nullable string with no enum.

Money columns tracked: `orders.{payment_status, final_price, product_price, coupon_price, tax_price,
delivery_price, return_price}`, `store_wallets.*`, `store_wallet_transactions.*`,
`store_order_financials.*`, `order_refunds.*`, `delivery_earnings.*`, `user_coupons.amount`.

**70 surfaces present a money figure to a human or a client. 53 of them depend on at least one of
the six unresolved questions.**

| | |
|---|---|
| surfaces inventoried | **70** |
| depend on an unresolved ledger question | **53** |
| independent of them | 17 |

---

## 3. What each question blocks

| question | what it decides for reporting |
|---|---|
| **F124** cancellation semantics | whether a cancelled order counts in revenue, and whether its `payment_status` can be trusted as a filter at all |
| **F126** COD asserted `Paid` | whether a `Paid` COD order is revenue or a merchant's assertion |
| **F127** refunds move no money record | whether a refunded order is still counted — today it is |
| **F128** wallet/order divergence | which of the two records a finance report should read |
| **F032** lost credits | whether wallet totals are complete |
| **F046** unverified captures | whether a `Paid` storefront order was ever actually captured |

---

## 4. The inventory

| file | surface | reads | ledger-dependent |
|---|---|---|---|
| `app/Http/Controllers/ReportController.php` | Merchant sales report — line chart and the fiv | orders.final_price, orders.delivery_price, orders.tax_price, orders.coupon_price. Net sale is computed inline  | **yes** — F124 cancellation semantics, F126 COD-paid-at-creation, F127 |
| `app/Http/Controllers/ReportController.php` | Merchant sales report — chart data endpoint (s | orders.final_price, delivery_price, tax_price, coupon_price at :1013-1028 and again at :1085-1115 and :1173-11 | **yes** — F124, F126, F127 |
| `app/Http/Controllers/ReportController.php` | Merchant sales report — line-chart export (JSO | Serves Session 'order_line_chart_report': NetSaleTotal, GrossSaleTotal, AverageNetSales, AverageGrossSales, Sh | **yes** — F124, F126, F127 — inherits the unfiltered totals verbatim a |
| `app/Http/Controllers/ReportController.php` | Merchant sales report — bar-chart export (JSON | Session 'order_bar_chart_report': NetSaleTotal, GrossSaleTotal, ShippingTotal, CouponTotal from the same order | **yes** — F124, F126, F127 |
| `app/Http/Controllers/ReportController.php` | Merchant sales-by-product report | Per-line final_price out of orders.product_json (totalNetSales/Totalsale accumulators), with no order-level pa | **yes** — F124, F127 — a cancelled or refunded order's lines still cou |
| `app/Http/Controllers/ReportController.php` | Merchant sales-by-product export (JSON) | Session 'product_order_report': NetSaleTotal, PurchasedItemTotal. Route GET product-order-export (web.php:1177 | **yes** — F124, F127 |
| `app/Http/Controllers/ReportController.php` | Merchant sales-by-category report | Per-line final_price from orders.product_json attributed to each line's category. No payment predicate. Route  | **yes** — F124, F127 |
| `app/Http/Controllers/ReportController.php` | Merchant sales-by-category export (JSON) | Session-cached category revenue series. Route GET category-order-export (web.php:1180). | **yes** — F124, F127 |
| `app/Http/Controllers/ReportController.php` | Merchant sales-by-brand report | Per-line final_price from orders.product_json attributed to each line's brand. No payment predicate. Route GET | **yes** — F124, F127 |
| `app/Http/Controllers/ReportController.php` | Merchant sales-by-brand export (JSON) | Session-cached brand revenue series. Route GET brand-order-export (web.php:1185). | **yes** — F124, F127 |
| `app/Http/Controllers/ReportController.php` | Merchant Top-5 report (top products, top categ | orders.product_id and orders.payment_type across every order the store has ever taken (labelled 'All time' at  | **yes** — F126 — a COD order asserted Paid at creation is indistinguis |
| `resources/views/reports/_sales_stats.blade.php` | Merchant sales-stats tile row (the five figure | $TotalgrossSale, $TotalNetSale, $TotalShippingCharge, $TotalCouponAmount prefixed with the store currency symb | **yes** — F124, F126, F127 — the tile carries no exclusion statement,  |
| `app/Http/Controllers/HomeController.php` | Merchant dashboard — Order value tile | SUM(orders.final_price) WHERE store_id = current AND delivered_status != 2. Filters on FULFILMENT only; paymen | **yes** — F126, F127 — the label is honest about cancellations and ret |
| `resources/views/dashboard.blade.php` | Merchant dashboard — most recent order amount | $latest->final_price through currency_format_with_sym for the current store. | no |
| `resources/views/dashboard/overview.blade.php` | Merchant dashboard — recent-orders strip | $ssOrder->final_price per row via currency_format_with_sym. | no |
| `app/Http/Controllers/HomeController.php` | Merchant dashboard — Top Category / Top Brand  | Delegates to TopSellersByRevenue::compute() over orders.product_json per-line revenue, windowed [from, to). Ro | **yes** — F124, F127 — the underlying query has no payment or cancella |
| `app/Http/Controllers/HomeController.php` | Merchant dashboard — best-selling brand chart | orders.final_price, delivery_price, tax_price, coupon_price across four date branches (:1649-1663, :1713-1727, | **yes** — F124, F126, F127 |
| `app/Http/Controllers/HomeController.php` | Platform operator dashboard — top five stores  | Order::select('store_id', SUM(final_price)) GROUP BY store_id ORDER BY total_amount DESC LIMIT 5 — across EVER | **yes** — F124, F126, F127 — platform-level GMV built from the same un |
| `app/Support/TopSellersByRevenue.php` | Shared revenue engine behind both dashboard sa | DB::table('orders')->where('store_id', …) with an optional half-open created_at window and NOTHING ELSE (:157- | **yes** — F124, F127 — this is the single place to add a payment predi |
| `app/Http/Controllers/StoreFinanceController.php` | Merchant finance dashboard — wallet balance ti | store_wallets.available_balance, pending_balance, withdrawal_pending_balance, total_income, total_withdrawn fo | **yes** — F126, F127, F128, F032 — this balance is the sum of OrderObs |
| `app/Http/Controllers/StoreFinanceController.php` | Merchant finance dashboard — wallet transactio | store_wallet_transactions.amount, balance_before, balance_after, direction, type, status, description, currenc | **yes** — F127 — there is no refund or reversal transaction TYPE in th |
| `app/Http/Controllers/StoreFinanceController.php` | Merchant finance dashboard — per-order P&L tab | DB::table('store_order_financials') paginated 15/page: product_amount, discount_amount, tax_amount, shipping_a | **yes** — F032 (orders whose financial row was never written are simpl |
| `resources/views/finance/index.blade.php` | Merchant finance dashboard — payment-status dr | store_order_financials.payment_status, presented as a two-option select labelled 'Not Received' / 'Received' ( | **yes** — F128 — this IS the divergence door. It is a routine-looking  |
| `app/Http/Controllers/StoreFinanceController.php` | Merchant finance dashboard — inventory valuati | products.cost_price, product_stock, sale_price/price and product_variants.stock, variation_price — computes in | no |
| `app/Http/Controllers/StoreFinanceController.php` | Merchant finance — withdrawals list | store_withdrawals.amount with the wallet relation eager-loaded (:1431-1434). Rendered at resources/views/finan | **yes** — F032, F126 — a withdrawal is drawn against available_balance |
| `app/Http/Controllers/StoreFinanceController.php` | Merchant finance — unreconciled gateway captur | order_gateway_references.amount, gateway, capture_state for rows with order_id IS NULL and capture_state != re | **yes** — F046 — this is the one surface that exists specifically to s |
| `app/DataTables/OrderDataTable.php` | Merchant order list — amount column and paymen | orders.final_price formatted per order currency (:139-140), with a payment_status filter applied at :711 (?ss_ | **yes** — F124 — the filter's 'Unpaid' bucket mixes never-charged orde |
| `app/Http/Controllers/OrderController.php` | Merchant order list — needs-attention counters | COUNT of orders WHERE payment_status = 'Unpaid' for the current store (:753), presented as an actionable 'unpa | **yes** — F124 — every cancelled order lands in this count, so the mer |
| `app/Http/Controllers/OrderController.php` | Merchant order detail page | Order::order_detail() array: final_price, tax_price, coupon_price, delivery_price, return_price, payment_statu | **yes** — F124, F127 — the page shows payment_status as an editable tw |
| `app/Http/Controllers/OrderController.php` | Merchant order quick-preview panel | orders.final_price (:250 of the partial) and payment_status (:42), presented as three separate readings — fulf | **yes** — F124 — the payment reading is the raw column, so it inherits |
| `app/Http/Controllers/OrderController.php` | Merchant order receipt (printable) | per-line orignal_price and final_price (:227, :232) and the order grand total (:247) via order_currency_format | no |
| `app/Http/Controllers/OrderController.php` | Merchant shipping label | per-line final_price at resources/views/order/shippinglabel.blade.php:298. | no |
| `app/Http/Controllers/OrderController.php` | Merchant order grid view (card layout) | orders.final_price per card at resources/views/order/grid.blade.php:215. | no |
| `app/Http/Controllers/OrderController.php` | Merchant order kanban view | orders.final_price and orders.payment_status per card (resources/views/order/partials/kanban-card.blade.php:41 | **yes** — F124, F126 — the green 'Paid' badge is the most compressed a |
| `app/Http/Controllers/OrderController.php` | Merchant order show (encrypted-id route) | Order::order_detail() per-line final_price at resources/views/order/order_show.blade.php:209, and orders.payme | **yes** — F124 |
| `app/Exports/OrderExport.php` | Merchant order export to Excel (Order.xlsx) | orders.product_price, coupon_price, delivery_price, tax_price, final_price, return_price AND payment_status, a | **yes** — F124, F126, F127 — a merchant sums the Final Price column in |
| `app/Http/Controllers/OrderController.php` | Merchant order details AJAX endpoint | Order::order_detail() money array returned to the order screen's JS. | **yes** — F124 |
| `app/DataTables/OrderRefundDataTable.php` | Merchant refund-request list | order_refunds.refund_status only — the columns are order_id, created_at and refund_status (:98-102). NO money  | **yes** — F127 — the refund queue a merchant works from shows no amoun |
| `app/Http/Controllers/OrderRefundController.php` | Merchant refund detail screen | order_refunds.product_refund_price (resources/views/order/refund_details.blade.php:152-153) and the per-item r | **yes** — F127 — this is the only screen in the product showing a refu |
| `app/DataTables/UserCouponDataTable.php` | Merchant coupon usage list (user_coupons) | user_coupons.amount as an 'Amount' column, with coupon_name and product_order_id. Reached from CouponControlle | no |
| `resources/views/customer/view.blade.php` | Merchant customer detail — order history amoun | orders.final_price per order row via currency_format_with_sym. | no |
| `app/Services/StoreNotificationService.php` | Merchant notification bell — order_created / o | orders.final_price and orders.payment_status written into the notification's data payload (:47-49 and :119-121 | **yes** — F124, F126 — an 'order_paid' push is generated from the paym |
| `app/Http/Controllers/Api/DashboardController.php` | Flutter admin API — dashboard recent orders | orders.final_price for the four most recent orders, withheld entirely (not zeroed) from a principal lacking Ma | no |
| `app/Http/Controllers/Api/DashboardController.php` | Flutter admin API — order list | orders.product_price, delivery_price, final_price, coupon_price, tax_price formatted to 2dp per row (:1571-157 | **yes** — F124 — the app can filter by payment_status, so the dual mea |
| `app/Http/Controllers/Api/DashboardController.php` | Flutter admin API — order detail | Order::order_detail() with every money field normalised at :2756 — product_price, delivery_price, final_price, | **yes** — F124, F127 — return_price is served with no indication of wh |
| `app/Http/Controllers/Api/DashboardController.php` | Flutter admin API — top five orders by value | orders.final_price, payment_status, delivered_status for the five largest orders, ORDER BY final_price DESC (: | **yes** — F124, F126 — ranks orders by stated value regardless of whet |
| `app/Http/Controllers/Api/DashboardController.php` | Flutter admin API — customer list with lifetim | SUM(orders.final_price) per customer scoped to the store (:6417-6420), divided by order count for AOV, returne | **yes** — F124, F126, F127 — 'total spend' counts cancelled, unpaid an |
| `app/Http/Controllers/Api/DashboardController.php` | Flutter admin API — one customer's orders with | SUM(orders.final_price) and order count for one customer (:8661-8667), plus the full Order::order_detail() mon | **yes** — F124, F126, F127 |
| `app/Http/Controllers/Api/DashboardController.php` | Flutter admin API — notification feed | Serves store_notifications rows whose data payload carries final_price, payment_type and payment_status for or | **yes** — F124, F126 |
| `app/Http/Controllers/Api/DashboardController.php` | Driver API — home counters and earnings | SUM(delivery_earnings.commission_amount) total and today (:5669-5681), plus the last five assigned orders' fin | **yes** — F124, F046 — the driver sees the order's payment_status to d |
| `app/Http/Controllers/Api/DashboardController.php` | Driver API — earnings transaction list | delivery_earnings.commission_amount, commission_percentage, status per row, joined to the order for final_pric | **yes** — F124 — each row prints the order's payment_status beside the |
| `app/Http/Controllers/Api/DashboardController.php` | Driver API — assigned order list | orders.final_price (aliased 'amount') and orders.payment_status across three status branches (:5459, :5465, :5 | **yes** — F124, F126 — this is the screen a courier uses to decide how |
| `app/Http/Controllers/Api/DashboardController.php` | Driver API — order detail | Order::order_detail() full money array for one assigned order (:5530). | **yes** — F124, F126 |
| `app/Http/Controllers/DeliveryBoyNotificationController.php` | Driver web — notification list with order amou | Eager-loads order:id,product_order_id,final_price,delivered_status onto each delivery-boy notification. | no |
| `app/Http/Controllers/AccountProfileController.php` | Storefront web — customer order list | Order with the refund relation for the signed-in customer and store (:560), rendered at resources/views/front_ | no |
| `app/Http/Controllers/AccountProfileController.php` | Storefront web — customer order list filter (A | Same rows filtered by delivered_status (:587-600), same final_price rendering. Route GET {storeSlug}/order-fil | no |
| `app/Http/Controllers/AccountProfileController.php` | Storefront web — customer order detail | Order::order_detail() rendered at resources/views/front_end/sections/order_view.blade.php: per-line final_pric | no |
| `app/Http/Controllers/AccountProfileController.php` | Storefront web — shopper refund request screen | orders.tax_price, coupon_price, final_price and order_refunds.product_refund_price (resources/views/front_end/ | **yes** — F127 — the shopper is shown a refund figure that no subseque |
| `resources/views/front_end/pages/account.blade.php` | Storefront web — account page order summary | orders.final_price per row via currency_format_with_sym for the store. | no |
| `resources/views/front_end/pages/order-detail.blade.php` | Storefront web — shopper order detail (themed  | orders.tax_price (:216), coupon_price (:222), final_price (:243) and per-line final_price (:90), all via order | no |
| `app/Http/Controllers/Api/ApiController.php` | Storefront API — customer order list | CAST(orders.final_price AS DOUBLE) aliased 'amount', plus reward_points, scoped to the authenticated customer  | no |
| `app/Http/Controllers/Api/ApiController.php` | Storefront API — customer return-order list | orders.final_price aliased 'amount' for rows matching delivered_status = 3 OR return_price > 0 (:7817). orders | **yes** — F127 — return_price > 0 is the proxy for 'this order was ret |
| `app/Http/Controllers/Api/ApiController.php` | Storefront API — customer order detail | Order::order_detail() full money document, after an ownership check via resolveViewableOrder(). Route POST api | **yes** — F124 — the document includes payment_status at Order.php:82  |
| `app/Models/Order.php` | SHARED READER behind roughly ten of the surfac | Order::order_detail() builds the canonical order money document: per-line orignal_price, total_orignal_price a | **yes** — F124, F127 — whatever payment_status is decided to mean, thi |
| `packages/workdo/AutomaticOrderPrinting/src/app/Http/Controllers/Automa` | VENDORED — automatic order printing: invoice / | Order::order_detail() per-line final_price, rendered as $item->final_price in all ten templates (template1:283 | no |
| `packages/workdo/LotteryPrizes/src/DataTables/LotteryOrderData.php` | VENDORED — lottery order list, detail, receipt | orders.final_price as a currency-formatted column (:60) and orders.payment_status as a declared column (:140); | **yes** — F126 — a vendored module writes payment_status 'Paid' at cre |
| `packages/workdo/AuctionProduct/src/app/Models/AuctionProductOrder.php` | VENDORED — auction order list and detail | Its own order_detail twin returning return_price (:55), payment_status (:62), final_price (:179) and tax_price | **yes** — F124, F127 — a second, independent implementation of order m |
| `packages/workdo/PartialPayments/src/DataTables/PartialPaymentData.php` | VENDORED — partial payments: deposit history a | order_partial_payments.payment_amount and its OWN payment_status vocabulary — Cancelled, Completed, Processing | **yes** — F124, F126 — a partially paid order carries settlement infor |
| `packages/workdo/LynomiaChat/src/app/Services/CommerceReader.php` | FIRST-PARTY MODULE — Lynomia commerce API orde | orders.payment_status mapped through its own 17-key vocabulary to one of paid / unpaid / pending / partially_p | **yes** — F124, F126, F127 — this is the only consumer that already di |
| `packages/workdo/LynomiaChat/src/app/Http/Controllers/InboxApiControlle` | FIRST-PARTY MODULE — Lynomia merchant inbox or | The order payload's payment_status is surfaced on the inbox order card (resources/views/merchant/inbox.blade.p | **yes** — F124 — an outbound event fires on every payment_status chang |
---

## 5. What this inventory does not cover

- **No production access.** Nothing here states how many rows carry which value. The queries that
  would settle that are in §9 of the decision package.
- **Vendored `packages/workdo/` surfaces are listed but not assessed for correctness**, because they
  must not be edited.
- **No judgement is offered on whether any figure is currently *wrong*** — only on whether its
  *meaning* depends on an unresolved question. A figure can be computed correctly and still mean
  something nobody has agreed.
- **This is not the Phase 05 plan.** It is the dependency map the plan will need.

---

## 6. The first executable Phase 05 action, once the decisions land

Re-read this inventory against the approved policy, mark each of the 53 dependent surfaces with the
predicate the policy implies, and only then design the reporting work. The inventory is deliberately
a table rather than prose so that pass can be mechanical.
