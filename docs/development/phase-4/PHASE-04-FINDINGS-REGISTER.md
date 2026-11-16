# Phase 04 — commerce findings register

Generated from the P4-0 commerce inventory: 12 domains inventoried in parallel, then **every**
claimed defect handed to an independent reviewer instructed to **refute** it. 129 agents,
5,734 tool calls. The reviewers are the reason this register is usable: they **refuted 17**
claims and found **6** already tracked, so the queue below is 94 findings rather than 117.

**This register is a claim about the code, not a record of work done.** A row is evidence that
somebody read the code and somebody else tried and failed to talk them out of it. It is *not*
a measured runtime observation unless the row says so, and no row is closed until a test fails
against the defect and passes against the fix.

| | count |
|---|---|
| claims inventoried | 117 |
| **CONFIRMED** | **94** |
| — high | 18 |
| — medium | 42 |
| — low / info | 34 |
| REFUTED by review | 17 |
| already tracked elsewhere | 6 |

Per-domain detail, including each reviewer's corrected statement of the defect and the
failing test it asks for, is in `findings/<domain>.md`.

## High severity — confirmed (18)

| id | domain | finding |
|---|---|---|
| **P4-F001** | cart | A `flat` coupon is subtracted once per cart LINE, so the discount scales with the number of lines |
| **P4-F014** | checkout | PaymentService::getProductStatus prices storefront COD / bank-transfer orders from an arbitrary foreign merchant's plan |
| **P4-F023** | order-write | ordersave excludes the product subtotal from orders.final_price on the shipping-on branch — but the live arm is :9625, and the plan it branches on is the platform admin's, not the store's |
| **P4-F026** | order-write | place_order and ordersave write the Order row and its four child tables with no transaction — confirmed, with a deterministic caller-controlled trigger the claimant did not find |
| **P4-F032** | order-write | An order in a wallet-less store writes no financial record and loses the wallet credit permanently — confirmed, and the premise is stronger than claimed |
| **P4-F034** | order-lifecycle | Merchant-side cancellation never credits stock back; only the storefront customer door restocks |
| **P4-F037** | order-lifecycle | CONFIRMED: `api/admin/status-change` has no `Manage Order` check, and its cancel branch is money-bearing |
| **P4-F044** | payments-core | Gateway cancel returns land on the generic storefront order writer: an order row and a stock decrement for a payment that never happened |
| **P4-F046** | payments-core | Storefront Benefit/Tap charge sets its server-to-server callback to a hardcoded public webhook.site bin, and the storefront return performs no capture verification for 'benefit' at all |
| **P4-F056** | inventory-stock | Delivery Syria webhook: return/cancel limbs restore no stock, and the return limb permanently shuts every remaining automated restock door |
| **P4-F062** | pricing-discounts | CONFIRMED (reachability corrected): the cart's per-row coupon arithmetic has no minimum_spend/maximum_spend gate, and that figure becomes orders.final_price for cod and bank_transfer — reac… |
| **P4-F063** | pricing-discounts | Flat coupon is subtracted once per cart ROW; apply_coupon's common tail reports that N-fold figure as final_price while `amount` stays the single subtraction |
| **P4-F070** | tax-shipping | Jurisdiction-restricted tax methods are charged to non-matching shoppers when price_type is not 'inclusive' — CONFIRMED, with a narrower mechanism than claimed and prior art the claimant mi… |
| **P4-F082** | refunds-returns | Returning a second distinct line re-subtracts the first line's value from the already-reduced order total, driving final_price negative |
| **P4-F096** | customer-identity | Guest-checkout validation failure returns a RedirectResponse that processOrder dereferences as a Customer |
| **P4-F104** | flutter-contract | App's Return transition writes return_status=2 without restocking, leaving no UI-reachable way to credit the stock back |
| **P4-F105** | flutter-contract | CONFIRMED: /api/admin/orders/update-status writes delivered_status with no transition guard, and its Return arm stamps return_status=2 without restocking, which silently disarms the web's r… |
| **P4-F107** | flutter-contract | DELETE /api/admin/deleteorder hard-deletes an order and its tax/coupon/billing rows with no permission check, reachable by any permissionless staff token |

## Medium severity — confirmed (42)

| id | domain | finding |
|---|---|---|
| P4-F002 | cart | `api/{slug}/cart-check-guest` discloses any tenant's product and per-variant stock to any Sanctum token holder |
| P4-F004 | cart | Guest basket is written under session()->getId() but read from the `cart` cookie; the first add after the session id changes orphans the existing rows |
| P4-F015 | checkout | Web checkout dispatches any of 52 payment types with no per-store enablement check — CONFIRMED, severity corrected to medium |
| P4-F016 | checkout | Guest checkout billing-validation failure dereferences a RedirectResponse and 500s instead of refusing |
| P4-F018 | checkout | COD/bank-transfer web order writer (PaymentService::getProductStatus) has no double-submit control and is outside the order-writer census's root |
| P4-F022 | checkout | Checkout's city-list hold gates on $('#state'), an element the checkout page never renders |
| P4-F024 | order-write | ApiController::ordersave resolves the shipping-governing Plan twice from different tenants in one request; the charge side reads the first type-admin row, not … |
| P4-F025 | order-write | OrderController::store writes orders.customer_id with no store predicate — the API twin's guard was never ported to the web form |
| P4-F027 | order-write | Web COD / bank-transfer checkout (PaymentService::getProductStatus) has no duplicate-submission claim, and the replay census's completeness assertion does not … |
| P4-F029 | order-write | CONFIRMED (with two corrections): OrderFinancialService reads `qty`, the three merchant-side order writers key `quantity`, so product_cost is 0 and gross_profi… |
| P4-F031 | order-write | CONFIRMED: OrderController::store writes an order, a stock decrement and (when Paid) all three money tables into fallback store 1 for an operator who chose no … |
| P4-F033 | order-lifecycle | Order::order_status_change() has no from-state rules; the order board is the one merchant surface that offers a forward move on a Cancelled or Returned card |
| P4-F035 | order-lifecycle | Storefront order-detail page ships the cancel handler without the cancel button, so the only restocking cancellation path is unreachable for non-auction orders |
| P4-F036 | order-lifecycle | A shopper can set their own order to delivered through the storefront API, unlocking product_return's stock credit and the refund request |
| P4-F040 | order-lifecycle | Merchant order detail offers "Cancel Order" at four statuses the writer always refuses, with an untranslated message asserting the order was delivered |
| P4-F042 | order-lifecycle | Delivery Syria webhook: no transition rules, and its Return arm writes return_status = 2 without restocking, which closes both of the merchant's restock doors |
| P4-F043 | order-lifecycle | `Api\ApiController::ordersave` writes `delivered_status` from request input — an order can be born Delivered, bypassing the fulfilment gate on refunds and retu… |
| P4-F050 | payments-gateways | CyberSource storefront checkout: the initiator's blob omits the store_id the return handler dereferences above its try — real, measured, but already ledger row… |
| P4-F055 | inventory-stock | Merchant-side order cancellation restores no stock; only the storefront customer door does |
| P4-F059 | inventory-stock | CONFIRMED: OrderController::store refuses every untracked product, and on a default store (stock_management = 'off') that is every simple product |
| P4-F061 | pricing-discounts | coupons.status is written by every coupon editor and read by no gate — deactivating a store coupon does not stop it discounting |
| P4-F064 | pricing-discounts | Guest coupon re-check in PaymentService::getProductStatus crashes the cod/bank_transfer web checkout |
| P4-F065 | pricing-discounts | Flutter merchant API coupon writes (AddCoupon/UpdateCoupon/DeleteCoupon) enforce tenancy but no permission — confirmed, with two corrections to the claim |
| P4-F069 | pricing-discounts | ProductPricing and WholesaleProduct rewrite the cart line price in both readers with zero test coverage — CONFIRMED, with two evidence corrections |
| P4-F074 | tax-shipping | Tax banding diverges between the catalogue engine and the cart/checkout engine, and `priority` sorts lexicographically |
| P4-F075 | tax-shipping | Six Flutter admin-API tax/shipping write handlers carry a tenant control and no permission check — CONFIRMED, and it is the "larger slice" the Phase 02 decisio… |
| P4-F076 | tax-shipping | Saving a shipping zone with every shipping method deselected is a 500 (ShippingZoneController::update has no validation) |
| P4-F083 | refunds-returns | Refund restock does not consult Order::LINE_STOCK_RESTORED, so a line credited by product_return is credited again by RefundStock |
| P4-F087 | refunds-returns | Refund approve / reject / restock / payout record no actor; four state-changing mutators attribute nothing |
| P4-F094 | customer-identity | Storefront checkout's "Create an account?" checkbox submits an empty value, so the registration branch at PaymentController:2026 is unreachable from the shippe… |
| P4-F098 | customer-identity | saveAddress silently discards the storefront address form's delivery-instructions field (and address_type with it) |
| P4-F101 | customer-identity | Unscoped `social_id` customer lookup in the social sign-in modules — CONFIRMED, and it is three of eight, not two of seven |
| P4-F106 | flutter-contract | GET /api/admin/vieworder serves the full order document — money and billing PII — with a tenancy predicate only and no permission gate; its sibling api/admin/o… |
| P4-F108 | flutter-contract | POST /api/admin/assignOrders writes driver assignment and commission rows with no permission check — CONFIRMED |
| P4-F109 | flutter-contract | DeliveryBoyApiController enforces ownership but no permission, while its web twin requires five permissions — same defect shape already closed for AddCustomer … |
| P4-F110 | flutter-contract | CONFIRMED: the admin-API order-note create/delete pair has no permission gate, while its web twin requires 'Create Order Note' / 'Delete Order Note' |
| P4-F111 | flutter-contract | searchProductsByName and SearchProduct serve products.cost_price with no Manage Products gate |
| P4-F112 | flutter-contract | CONFIRMED (broader than claimed): POST /api/admin/searchdata?type=order dumps whole Order rows with no permission gate — the same money OrderList gates on ['Ma… |
| P4-F113 | flutter-contract | CONFIRMED: customerlist, customer/orders, searchCustomerByName and destroyCustomer carry no permission check — and an existing test pins the gap at 200 |
| P4-F114 | flutter-contract | POST /api/admin/update-customer answers 401 to merchant staff, forcing a logout in the shipped app |
| P4-F115 | flutter-contract | API cancellation leaves a prepaid order's wallet credit in place; the web cancel reverses it |
| P4-F116 | flutter-contract | Manual-order product picker reads the stale stock_status column (client-side only; the searchProductsByName half of the claim is wrong) |

## Low / informational — confirmed (34)

| id | domain | finding |
|---|---|---|
| P4-F003 | cart | Web cart writer product_cartlist never binds the signed-in customer to the slug store — real and uncovered, but only on that one writer |
| P4-F005 | cart | `quantity_type=remove` on the guest web cart reports success and removes nothing (real, but low — no shipped client emits it and the real removal route works) |
| P4-F006 | cart | Unknown variant_id crashes both quantity-change handlers before the cart-row guard; the 500-vs-200 split is a platform-wide product_variants existence oracle |
| P4-F007 | cart | CartController::product_cartlist dereferences a null store for an unknown slug — confirmed, but it is one of six such handlers in the file, not an outlier |
| P4-F008 | cart | `abandon_carts_destroy` dereferences a null store where its own sibling fails closed explicitly |
| P4-F009 | cart | `cart_remove` returns `{status:1}` without deleting when `getStore()` resolves nothing — real and uncovered, but the "absent slug" half is wrong and the only s… |
| P4-F011 | cart | Login cart merge creates a duplicate line when the customer already holds the same product |
| P4-F013 | cart | get_tax_data really does resolve a caller-supplied product id with no store predicate — but the claimed sale_price disclosure is structurally blocked, and the … |
| P4-F019 | checkout | Checkout endpoints dereference a null store for an unknown slug (confirmed; severity low, not medium) |
| P4-F020 | checkout | processBillingInfo dereferences eight unguarded billing keys before any validator runs — CONFIRMED, with corrections |
| P4-F021 | checkout | get_shipping_method reads any customer's delivery address by id and replaces the request's whole billing_info, which then lands in the order |
| P4-F028 | order-write | ordersave discards the per-customer order reference it computes, but the blast radius and the is_guest half of the claim are overstated |
| P4-F038 | order-lifecycle | `api/admin/status-change` reports a refused status change as a success envelope |
| P4-F039 | order-lifecycle | `api/admin/status-change` labels the status it wrote one rung too far up the ladder (4→'pickedup', 5→'shipped', 6→'delivered') — real, uncovered, but no client… |
| P4-F047 | payments-core | PhonePe's capture check on the generic storefront return sources its gateway credentials from the route slug's store, while the order is written to the checkou… |
| P4-F052 | payments-gateways | PhonePe's and Tap's provider confirmations are never recorded, so the generic storefront writer writes Pending — real, but it is the accepted default for ~45 g… |
| P4-F053 | payments-gateways | CinetPay notify_url is unrouted-in-practice vendor sample that cannot record a capture — CONFIRMED on substance, but severity is low: the plan arm is currency-… |
| P4-F054 | payments-gateways | PaymentService::paymentConfig() is dead code with an uninitialised variable and a dangling PayPal setting name |
| P4-F058 | inventory-stock | Cancellation restock writes LINE_STOCK_RESTORED but never reads it; order_return_request has no delivered_status gate, so return-then-cancel double-credits whe… |
| P4-F060 | inventory-stock | ProductController::update's choice_no variant upsert creates ProductVariant rows with no store_id — real and uncovered, but two of the claim's supporting citat… |
| P4-F073 | tax-shipping | Shipping-quote and apply_coupon read an arbitrary customer's DeliveryAddress by caller-supplied id — real and uncovered, but it is a tax-band inference channel… |
| P4-F077 | tax-shipping | The 'Delivery Syria' shipping branch weighs a cart line with an unscoped Product::find() while the flat-rate branch two lines below refuses the same line |
| P4-F078 | tax-shipping | Storefront API delivery_list resolves the shipping zone with no country/state predicate |
| P4-F079 | tax-shipping | CONFIRMED: Api\ApiController::tax_guest() never passes a tax group to Tax::TaxCount(), so POST /api/{slug}/tax-guest returns zero tax for every store, product … |
| P4-F080 | tax-shipping | taxSettings persists an unvalidated caller-supplied tax_id — real and uncovered, but zero impact; the same five lines hide a worse correctness bug |
| P4-F081 | tax-shipping | Debug logging left on the shipping-quote path (CartController:621) — real, uncovered, under-scoped |
| P4-F084 | refunds-returns | Accept → RefundStock → Cancel double-counts the returned units: the stock credit is never reversed and the order's allowance is never consumed |
| P4-F086 | refunds-returns | The three OrderRefundSetting toggles are never read server-side: RefundStock, RefundAmonut and order_refund_request each ignore the policy their Blade gate enf… |
| P4-F089 | refunds-returns | OrderRefundController::index() lacks the operator limb its five mutator siblings carry, locking the platform operator out of GET /refund-request |
| P4-F093 | refunds-returns | A shopper-initiated refund request reaches the merchant with no notification — but the claim's cited contrast is wrong and the merchant-side half is overstated |
| P4-F097 | customer-identity | The storefront address book validates `address_type` as required and then drops it in mass assignment — plus the same bug on `instructions` |
| P4-F099 | customer-identity | Two live handlers resolve a `DeliveryAddress` by caller-supplied id with no ownership or tenant predicate |
| P4-F102 | customer-identity | CONFIRMED (with corrections): `AccountProfileController::password_change`'s else arm dereferences the `web` guard on a `customers`-gated storefront route |
| P4-F117 | flutter-contract | Three api/admin commerce handlers concatenate $e->getMessage() into the JSON response body — confirmed, uncovered, and 20 sites not 3 |

## Refuted by review (17) — do not re-raise these

Each of these was claimed by a domain inventory agent and **killed** by an independent
reviewer. The reviewer's reasoning is in the per-domain file. They are listed here so the
next reader does not spend the same effort twice.

| id | domain | claim, and why it fell |
|---|---|---|
| P4-F010 | cart | `cartClear`'s signed-in arm has no store predicate — but it is neither unique nor an independent defect |
| P4-F012 | cart | `carts.price` is written by five disagreeing paths, is never read, and is an INTEGER column |
| P4-F030 | order-write | Manual-order return: the refusal is documented and pinned by a named test, and the approval path credits no money |
| P4-F041 | order-lifecycle | Return approval without a delivery/request precondition is the asserted, tested behaviour — not an uncovered defect |
| P4-F045 | payments-core | Tap isset() gate on the storefront return: code reading accurate, but it gates nothing — the skipped path is the owner-decided, test-pinned unverified-gateway baseline |
| P4-F048 | payments-gateways | CyberSource storefront return never inspects the gateway's answer |
| P4-F049 | payments-gateways | CyberSource storefront arm: the caller-chosen store_id selects no credentials at all, and the request cannot authenticate — the claimed credential-selection and charge mechanism does not exist |
| P4-F057 | inventory-stock | Return approval credits stock without consulting StockMovement::movedOutFor() |
| P4-F066 | pricing-discounts | AddCoupon's missing percentage cap is a validator-consistency gap, not a free-basket defect: max:100 already permits 100, and a 100% coupon zeroing every line is pinned as intended behaviour |
| P4-F067 | pricing-discounts | SetNumber() double-application in apply_coupon's tail is real, but its output reaches no reader: the tax line the claim names is written through an id that exists nowhere in the repository |
| P4-F072 | tax-shipping | CartController::get_tax_data() unscoped Product::find() leaking another tenant's sale_price |
| P4-F088 | refunds-returns | Order::LINE_STOCK_CARRIED is written by the replacement service and read by no stock path |
| P4-F090 | refunds-returns | canManageRefunds()'s byte-exact operator comparison is a pinned owner decision, and the proposed fix would broaden operator permissions on five money-moving mutators |
| P4-F092 | refunds-returns | The shopper's return list prints the raw English refund status in both locales |
| P4-F095 | customer-identity | REFUTED — the facade at PaymentController:2840 is unimported, so no broker is ever reached: the line throws a class-not-found Error that the surrounding catch swallows |
| P4-F100 | customer-identity | Per-store SMTP override via legacy `mail.driver` keys IS honoured by Laravel 11 — the claim inverts the actual failure mode |
| P4-F103 | customer-identity | `add_newsletter` guard mismatch — REFUTED: the handler is dead code whose insert always fails, and the one live limb is already recorded |

## Already tracked elsewhere (6)

| id | domain | finding |
|---|---|---|
| P4-F017 | checkout | storefront place.order points at a missing OrderController method |
| P4-F051 | payments-gateways | CyberSource storefront credential-key mismatch is real but is already tracked as ledger row F91, and the severity is wrong |
| P4-F068 | pricing-discounts | Coupon code uniqueness is global across all tenants on the web and absent entirely on the Flutter API |
| P4-F071 | tax-shipping | place_order's `tax_info` read is real but already pinned by a green blocking test — and the claim's money impact is backwards: the tax IS charged, and implementing the claimed fix would overcharge it… |
| P4-F085 | refunds-returns | order_refunds.product_refund_id VARCHAR(255) overflow — real mechanism, but a recorded owner-BLOCKED finding (P1-ORD-11) with the money/stock paths already guarded, and the claimed trigger arithmetic… |
| P4-F091 | refunds-returns | Route::resource('refund-request') registers five verbs the controller does not implement |


---

## Found during Phase 04 engineering, after the inventory

The inventory above is what 12 reading agents and their reviewers found. These were found by
*building the fixtures*, which is a different instrument: a test has to make the code do the thing,
and that is where a claim about the code meets the code's actual behaviour.

| id | domain | severity | finding |
|---|---|---|---|
| **P4-F118** | pricing-discounts | HIGH | `fixed product discount` gives the discount to the products **not** named in `applied_product`, and an empty `exclude_categories` refuses every line |
| **P4-F119** | storefront-catalogue | HIGH | **Editing** a variant product empties its storefront variant selectors, so the product silently stops selling — FIXED in this branch |
| **P4-F120** | pricing-discounts | MEDIUM | `orders.coupon_price` records the coupon's headline amount, not the discount actually given, when the coupon is worth more than the basket |
| **P4-F121** | order-write | HIGH | `orders.product_price` / `final_price` truncated to whole currency units on `order-save` — up to 0.99 lost per order — FIXED in this branch |
| **P4-F122** | tax-shipping | MEDIUM | the cart sidebar quotes a shipping row from a plan no order writer will honour |
| **P4-F123** | customer-identity | MEDIUM | `Utility::CustomerAuthCheck()` memoised its answer in process statics keyed on nothing — it decides which cart reader prices a basket — FIXED in this branch |
| **P4-F124** | order-lifecycle | MEDIUM | an app-side cancel writes no `payment_status`, so `OrderObserver` never reverses the order's wallet credit — **UNRESOLVED**, design in `docs/finance/P4-F124-LEDGER-SEMANTICS.md` |
| **P4-F125** | order-lifecycle | HIGH | three more cancel doors restored no stock; two of them reverse the wallet and lose the goods — the inverse of P4-F124 — FIXED in this branch |
| **P4-F126** | order-write | MEDIUM | two manual-order writers accept `payment_type=cod` with `payment_status=Paid`, bypassing the verification seam and crediting the wallet at creation — the condition D-06 forbids — RECORDED |
| **P4-F127** | refunds-returns | MEDIUM | a refund never touches `orders.payment_status`, `store_order_financials` or the wallet: a fully refunded order stays `Paid` and keeps its credit — RECORDED |
| **P4-F128** | order-lifecycle | MEDIUM | `StoreFinanceController::updateFinancialPaymentStatus()` moves the wallet without touching the order, so the two can diverge permanently with no reconciliation — RECORDED |
| **P4-F129** | documentation | MEDIUM | `OrderFinancialService` and the backfill assessment claim twenty order-write sites write `'succeeded'` to `orders`; every one is `PlanOrder`. One read-only production query settles it — RECORDED |

### P4-F118 — the `fixed product discount` arm does not do what the merchant form says

Measured while writing `MultiLineCouponArithmeticTest::test_a_fixed_product_discount_stays_per_line`,
whose fixture had to be built around it. The live arm is `Cart.php:1115-1123`:

```php
if (! in_array($productId, $exclude_product) && ! in_array($productId, $coupon_applied)) {
```

Three separate things are wrong, and only the first is obvious from reading:

1. **The membership test is inverted.** A product named in `applied_product` is the one product
   that does *not* get the discount. Measured: a coupon naming product A, on a basket of A, B and C,
   discounted **B and C**.
2. **The other four arms are unreachable.** Each needs some `count(explode(',', $column)) == 0`,
   and `count(explode(',', ''))` is **1**, never 0. Only the first arm can ever fire, so the
   `applied_categories`-only and `applied_product`-only configurations the merchant form offers do
   not exist at runtime.
3. **An empty `exclude_categories` refuses everything.** The arm's last limb is
   `! in_array($categoryId, $exclude_categories)`; with an empty column that is
   `in_array(null, [''])`, which PHP 8 evaluates as **true**. A product with no category, or a
   coupon with no exclusions, silently discounts nothing. This is why the first version of the
   fixture measured 0.00 and looked like a test bug.

**Not fixed, deliberately.** It changes which of a merchant's products their existing coupons apply
to — live catalogue behaviour, not an internal arithmetic — so it is an owner decision, and
bundling it with the flat-coupon arithmetic would make both impossible to review. Current behaviour
is pinned by the test above so that whichever way the decision goes, the change is visible.

### P4-F119 — editing a variant product makes it unsellable

Found while building the fixture for P4-1's "variant product renders no variant control" question.
That question had two answers, and separating them is the point:

1. **The dev fixture was wrong** — it stored `for_variation_1: "on"` where the merchant form posts
   the checkbox *value* `1` (`product/edit.blade.php:669`). Every test in the suite posts `1`. So
   the missing selector in the fixture was a **fixture gap**, now corrected.
2. **Underneath it was a real defect.** With the flag corrected the selectors appeared — and were
   **empty**.

`products.product_attribute` holds a `values` array per attribute, and **the two merchant forms
write different things into it**:

| form | what the browser submits | evidence |
|---|---|---|
| create | option **IDs** | `product/create.blade.php:861` — `<option … value="' + key + '">` |
| edit | **TERMS** | `product/edit.blade.php:689` — `<option @if(…) selected @endif>{{ $f }}</option>`, **no `value`** |

`ProductController` stores whichever arrives. The storefront partial resolved them with
`ProductAttributeOption::where('id', $p)` — an id lookup — so terms resolved to nothing.

**Measured on the running dev server**, flushing the `Cachable` product cache between reads:

```
values = ["1","2","3","4"]     Size -> [Select an option, S, M, L, M/L]
values = ["S","M","L","M/L"]   Size -> [Select an option]
```

The first probe said *ids don't work either* — because it read a **stale cached row**. That is the
trap CLAUDE.md names, and it nearly cost a wrong root cause.

**An empty selector is an unsellable product, not a cosmetic gap.** `front-theme.js:1237` serialises
`.variant_form` on change and writes the returned `variant_id` onto the add-to-cart button; with
nothing to select, `Cart::AddToCart` refuses at `app/Models/Cart.php:158-160` with *"Please Select a
variant in a product."* Nothing in the merchant UI reports it.

**Fixed on the read side** — `App\Support\Commerce\VariantOptionTerms` resolves either shape, with
the explicit `store_id` predicate the lookup never had (`ProductAttributeOption` is **not** in
`config/tenancy.php`'s `scoped_models`, so a bare term lookup would have offered another tenant's
options). Six tests in `tests/Feature/Storefront/VariantOptionsRenderTest.php`; reverting the
partial reddens **5 of 6**, and the one that stays green is the create-shape guard.

**The write-side divergence is the root cause and is NOT fixed here.** Normalising it means
converting terms to ids at save time and rewriting what is already stored — a data change on live
catalogues, for a defect whose symptom is a read. Recorded as the follow-up.

### P4-F120 — `orders.coupon_price` records the coupon's headline amount, not the discount given

Found by independent review of the P4-2 fix (and **not** fixed by it). `apply_coupon` reports
`amount` and `coupon_discount_amount` as the raw `$coupon->discount_amount`
(`ApiController.php:4917`, `:4928`), while the cart — correctly, after P4-2 — reports
`min(basket, discount_amount)`. They agree except when the coupon is worth more than the basket:

> A 40.00 basket with a flat 50.00 coupon writes `orders.coupon_price = 50.00` against a discount
> that was actually 40.00, beside `final_price = 0.00`. The row does not reconcile.

Pre-existing, unchanged by P4-2, and bounded: the **charge** is right (`final_price` is the cart's
figure), so no money moves wrongly. What is wrong is the financial record, and anything built on it
— merchant reporting, coupon analytics, `user_coupons.amount`. Fixing it changes a money column's
meaning on existing rows, so it is its own slice.

### Two review findings on the P4-2 fix itself, both fixed in the same branch

Recorded because they were found by **adversarial review of a fix**, which is where three of the
last four packages' defects came from, and because one of them was a money defect introduced by the
first version of the fix:

1. **The first version's allocation depended on cart row order.** It consumed the flat allowance
   first-come. Tax is charged per line, at that line's own rate, on the price *after* its share of
   the coupon — and the two readers do not even iterate the same way (`cart_list` orders by id
   DESC, `cart_list_cookie` has no `orderBy`). **Measured** on two 100.00 lines, one taxed at 20%,
   with a flat 100.00 coupon: `total_sub_price` came out **144.00 or 124.00** depending on which row
   was newer — a 20.00 swing a shopper controls by removing and re-adding a product. Now allocated
   **pro rata**, which is order-free and sums exactly, and pinned by
   `test_the_flat_discount_does_not_depend_on_cart_row_order` (which fails against first-come).
2. **A spend-refused coupon was still echoed as applied.** The `coupon_info` block re-applies expiry
   and the two usage limits but not the spend gate, so the response carried the coupon's real id,
   name and headline discount beside an amount of 0.00. `coupon_info.coupon_final_amount` is read as
   money by `PaymentService.php:2460` where no top-level `coupon_code` was posted. Now gated on the
   allocator's own verdict.

3. **The pro-rata fix for (1) then broke the bound it was replacing** — found by a SECOND round of
   review of the same class, and the most instructive item in this package. Pro rata divides by the
   whole basket subtotal while a non-positive line contributes nothing to the numerator, so
   `Σ shares = D × (positive mass) / (total mass)`, and one negative line makes the denominator the
   smaller of the two. **Measured** on the basket this codebase already records at
   `ApiController.php:3555-3565` — one 1000.00 product plus a 50.00 product at `qty = -5`, subtotal
   750.00 — with a flat 700.00 coupon: **discount 933.33, `total_sub_price` −183.33**. The
   first-come version it replaced could not do that; its running allowance was a hard cap, and
   removing it was the regression. Negative `carts.qty` is reachable today through two vendored
   module writers (`GuardsGatewayReturn.php:621-627`, S29), so the **cart read was live**; it does
   not reach `orders.final_price` only because three order writers refuse a non-positive line
   quantity first. Fixed by keeping a residual budget beside the ratio — it never binds for a
   well-formed basket, so order-independence is preserved — and pinned by
   `test_the_discount_never_exceeds_the_basket_even_with_a_negative_line`, which asserts the
   universal invariant (`discount ≤ basket`) rather than one arithmetic.

   **A fourth defect, in the P4-3 seam, found by the full suite and misdiagnosed once.**
   `PreTransactionStockGiveBackTest::test_the_plan_throw_happens_before_any_stock_is_taken` failed in
   the Feature suite and **passed in isolation**. My first explanation was that the run had read
   files mid-edit; that was wrong, and it is kept here rather than edited out because the wrong
   diagnosis is the instructive part — it reproduced on a clean tree.

   The cause was a `private static array $owners` memo in `StorePlan::ownerOf()`, keyed on
   `store_id`. **`RefreshDatabase` restarts ids at 1 for every test while a static survives the
   whole PHPUnit process**, so test N memoised store 1's owner and test N+1 received that stale
   `User` for its own, different store 1. That test's `breakThePlanLookup()` nulls `plan_id`,
   flushes the `User` cache and forgets `admin_details` — and a static memo answers none of those,
   so the plan still resolved and nothing threw.

   The memo is **removed**, not reset-hooked: `User::find()` on a primary key against a `Cachable`
   model is cheap enough that it bought nothing worth a process-global. The irony is the record:
   this is CLAUDE.md's own process-global-pollution class, walked into while fixing a defect caused
   by a *global cache key*. **Signature worth remembering: fails in the suite, passes alone.**

   **And both line-number ratchets fired on these comments**, twice for one of them:
   `SecurityLogInventoryTest` keys its inventory on `file:line` and has a mechanical resync tool;
   `PaymeraPostCommitClaimTest` asserts four cited anchors in a docblock and had to be re-measured
   by hand, then again, because the first correction moved them further. That docblock's own warning
   reads *"Measure after editing the comment, not before"*, and it has now been learned a fourth
   time — which is an argument for keying an anchor on a stable token rather than a coordinate, not
   for more discipline. Noted for whoever owns that test; out of scope here.

   **The lesson, recorded because it is the general one:** a fix for a review finding is itself
   unreviewed code. Two rounds found two defects in two successive versions of the same twenty
   lines, and neither was found by a test — the order-dependence because no test varied row order,
   this one because no test built a malformed line.

The review also **refuted** its own sharpest claim, which is worth recording as such: it suspected
the hoisted coupon lookup had changed `$store->id` to `$store_id` and widened three gateway paths.
The diff says the pre-change code read `$store_id` too — no path moved. What *was* wrong was the
inherited comment's **justification** for that variable, which is now corrected in place rather than
carried forward.

### P4-F121 — `orders.product_price` and `orders.final_price` were truncated to whole currency units — FIXED

Found by a third round of independent review, of the very expression P4-3 rewrote. Pre-existing, not
caused by that rewrite.

`SetNumber()` has a **string branch** that keeps only the first run of digits
(`app/Helper/helper.php:292-295`: `preg_match('/\d+/', $number, $matches)`), and `ordersave` applied
it to values that were *already* `SetNumber()` strings. **Executed:**

```
SetNumber(99.99)            = 99.99
SetNumber("99.99")          = 99.00      <- the cents are gone
SetNumber(SetNumber(99.99)) = 99.00
```

`$cartlist_final_price` is a string on both paths (`:8981` from `$cartlist['final_price']`, published
through `SetNumber()` at `:1037`; `:9135` from `$apply_coupon['final_price']`, likewise at `:4922`),
and so is `$delivery_price` (`:9163`, from `get_shipping_method`).

**Measured through `POST api/{slug}/order-save` on a 99.99 basket:** `orders.product_price` **99.00**
and `orders.final_price` **99.00**. Up to 0.99 lost per order, shopper-favourable, and
`product_price` is the merchant's ledger. No coupon needed.

Fixed with a `(float)` cast at the three assignments, in `ordersave` and in `confirm_order`'s copy of
the same block — **not** in `SetNumber()`, which has on the order of 200 callers and whose string
branch some may rely on. Pinned by
`OrderTotalUsesTheStoresOwnPlanTest::test_a_fractional_basket_is_not_truncated_to_whole_units`.

**This is the one change in the package that moves money UP for a shopper who did nothing wrong.**
COD totals on fractional baskets will tick up by up to 0.99 to the basket's true value. It needs no
pre-approval — restoring cents is correctness, and CLAUDE.md escalates *material* changes to prices —
but it is the kind of delta an owner should meet in a report rather than in a reconciliation, so it is
stated here and in the phase report rather than left to be noticed.

**The response shape did not change, and that was checked rather than assumed.** The cast is *inside*
`SetNumber()`, not in place of it, so the function still returns a string and the JSON value stays
`"99.99"` rather than becoming `99.99`. A strongly-typed Flutter parse is unaffected. Had the cast
replaced the call, that would have been a shape change on keys the shipped app reads. Related bounded
knowable: `(float)` stops at a thousands separator (`(float) "1,234.56"` is 1.0), which is safe at all
six sites because every input is `SetNumber()` output and `SetNumber` strips commas on the way out
(`helper.php:297`) — worth knowing only if a future caller feeds one of these a
`currency_format_with_sym()` value. The old string branch mangled commas too, so the cast is strictly
better in every case.

**Why no test saw it:** every order-writer fixture in the suite prices in whole units, where the
truncation is a no-op. That is the **third** variant of one shape in this package — the
order-dependence was invisible because no test varied row order, the negative-line overshoot because
no test built a malformed line, and this because no test priced in cents.

### P4-F122 — the cart sidebar quotes shipping from a plan no writer will honour

`CartController::cart_list_sidebar:39-40` resolves the store owner's plan with **no `type == 'admin'`
test**, while all thirteen order writers apply one. So for a store whose owner's `type` is not
`admin`, the shopper's sidebar shows a shipping row and every writer then writes no delivery charge —
the same quoted-versus-charged divergence class as P4-F014, through a different door. A merchant can
reach it without any data oddity, since they can change their own `users.type` through the product.

**Not fixed.** The right direction is to narrow the sidebar to match the writers, not to widen the
writers — widening would start adding a delivery charge to live orders on stores that have none
today. Either way it moves money on live stores, so it is an owner decision. The site is annotated in
place, and its unguarded `$admin->plan_id` read **is** fixed, because a property read on null silently
turned shipping off rather than saying anything.

Also recorded from the same pass, and **not** changed: `type == 'admin'` at all thirteen writers
excludes `'super admin'`, while `User::creatorId()` treats both as tenant roots, so a store created by
the platform operator resolves no plan anywhere. It is consistent today; changing it would move money
on live stores and must not ride along in a seam refactor.

### P4-F123 — `Utility::CustomerAuthCheck()` memoised its answer in process statics keyed on nothing — FIXED

Found by independent review **sweeping for the shape of the defect this phase had just introduced and
removed** in `StorePlan`. That sweep is the reason this row exists, and it is the strongest argument
in the package for reviewing a fix rather than only the thing it fixed.

`app/Models/Utility.php:1119-1120` declared `private static $store_id` and `private static $customer`,
filled them on the first call — the store id resolved from `$slug`, and a **count** of customers in
that store with the authenticated customer's email — and returned them on every later call in the
process, **whatever slug it was given and whoever was signed in**. Not keyed on the slug, the
customer, or anything.

**What it decides:** this function is the switch that chooses which cart reader prices a basket.
`ApiController::couponSpendBaseline:118` and `apply_coupon:4619` both branch on it between
`Cart::cart_list_cookie()` and `cart_list()`. A stale answer prices the signed-in `carts` rows where
the guest cookie basket was intended, or the reverse — directly underneath the coupon arithmetic this
phase spent three review rounds on.

**Production was hardening, not a hole, and that was measured:** all 21 call sites are controllers,
middleware or views — no job, listener, command or observer — so under PHP-FPM the statics died with
the request, and within one request the slug is the route's store and the authenticated customer does
not change.

**In the test suite it was live.** Measured: `CustomerAuthCheck()` returned **true for a customer of
another store**, because the previous test in the same PHPUnit process had filled the memo while
`RefreshDatabase` restarted the id space. Pinned by
`tests/Feature/Security/CustomerAuthCheckIsNotMemoisedTest`, whose two methods must stay in order in
one file — split them, reorder them or run either alone and the defect is invisible, which is the same
property that hid the `StorePlan` memo outside the full suite. Restoring the statics reddens the
middle test.

#### The rule worth keeping, which settles the next three cases without re-deriving them

> **A process-static memo of SCHEMA is safe. A process-static memo of ROWS, IDS or COUNTS is not,**
> because `RefreshDatabase` restarts the id space inside the static's lifetime.

It clears `BulkDeleteTenantGuard::$storeColumnMemo` (which caches *which tables carry a store column*
— schema, and schema does not change inside a test process) and catches both `StorePlan`'s removed
memo and this one. Where a request-scoped memo genuinely is wanted, `app/Scopes/StoreScope.php`
already solves it: it pairs each memo with the container instance it was built under and discards it
when that changes (`forgetSlugMemoIfStale()`, `forgetOwnedStoreMemoIfStale()`). Nobody needs to design
that again.

Also swept and **left alone**: `Module_Alias_Name()` (`app/Helper/helper.php:1568-1580`) has the same
shape, but its guard only memoises a non-empty result and it feeds a module's *display alias* — not
`module_is_active()` — so it can render a name wrongly across tests and cannot make an inactive module
look active. `CouponBasketAllocator` was checked and is immune: every field is instance state, one
allocator per cart read, and `spendIsOutOfRange()` is a pure static holding nothing.

---

## P4-4 — stock integrity across every cancel and return door (CLOSED)

Four confirmed high findings — **P4-F034, P4-F056, P4-F104, P4-F105** — sharing one mechanism: *a
status is written and the goods are never accounted for.* All four are fixed, with **119 tests across
seven files green** and the fix falsified (reverting all three doors reddens **21 of 40**).

### What was measured, before the fix

| door | measured |
|---|---|
| merchant web `order-status-change` | a 3-unit line's `product_stock` stayed **4** where 7 was due; variant lines and multi-line orders the same; no `LINE_STOCK_RESTORED` marker |
| Flutter `api/admin/orders/update-status`, cancel | stayed **4** |
| Delivery Syria webhook, return limb | stayed **4** where 6 was due — HTTP 200 `{success:true}`, status moved, nothing credited |
| then the merchant's **genuine** web approval | still **4**, answering *"Return request approve successfully"* |
| API cancel from **five** non-pending states (1, 3, 4, 5, 6) | all landed; the web refuses every one |
| API Pending → Return | landed; `return_status` became 2 |

### Corrections to the findings, measured

- **"Eight doors" is not reproducible as eight handlers.** There are **seven** handlers writing
  `delivered_status = 2` and **eight routes** (one is registered twice). **Six handlers are defects**;
  the seventh is `OrderReplacement::cancelAndReplace()`, which deliberately does not restore. The
  original count double-counted: "eight doors … and none of them restocks" included the path the same
  finding calls the deliberate ninth.
- Two candidate doors are **not** doors: `DeliverySyriaStatusController` writes `zid_orders` and never
  touches `delivered_status`; `AuctionProductOrder` is a separate table with its own stock model.
- `OrderController::updateStatus` is not a cancel door despite the name — it only ever writes
  `delivered_status = 1`.
- The webhook does **not** shut "every automated restock door": `OrderRefundController::RefundStock()`
  stays open, gated on the refund's own status and referencing neither `delivered_status` nor
  `return_status`.
- The cancel gap is **five** states, not one.

### The fix, and what moving the loop required

The restore loop lived inline in `OrderController::status_cancel()` and that was the only door that ran
it. It now lives in `App\Http\Controllers\Concerns\RestoresCancelledOrderStock`, called by the merchant
web door, the Flutter door and the webhook's cancel limb.

**With one caller the loop needed no idempotency of its own** — a replayed storefront cancel is refused
by `Order::order_status_change()` before the loop is reached. With six callers that is false, and
`movedOutFor()` cannot substitute: `stock_movements` is development-and-test-only, so on every live
installation each line reads `UNKNOWN` and the loop restores unconditionally. So the loop now consults
both line markers itself, **and** every caller guards on the order's pre-write state. The marker bounds
the goods; the caller's guard bounds the transition.

That gives **`LINE_STOCK_CARRIED` its first production reader.** Until this change its only reference in
the whole repository was an assertion in `OrderReplacementTest`, so cancelling a *replacement* order
through any restoring door would have credited goods that never left the shelf — an inflation nobody had
pinned, found by adversarial review of the tests rather than of the code.

The loop stays in the **callers** and deliberately not in `Order::order_status_change()`: that is what
`cancelAndReplace()` calls, and the cancel-plus-reissue pair must net −N.

### Both return arms write `return_status = 1`, not 2

The Flutter app and the webhook both wrote **2 — approved —** and restocked nothing. That is the exact
durable idempotency marker `OrderController::order_return_request` reads before crediting, so an
app-side or courier-side "Return" **disarmed the merchant's subsequent genuine approval**. And there was
no UI-reachable recovery, because both web controls gate on `return_status == 1`: the Approve button was
never offered and the order simply rendered as "Return" with the goods quietly missing.

Both now write `1`. **A courier or an app tap reporting a return is a request, not an approval** — the
goods are on their way back, not checked in. `1` says that, restores the Approve control, and routes the
credit through the one existing restore door rather than adding a fourth beside `status_cancel`,
`order_return_request` and `RefundStock`.

### A fifth defect, in the fix, caught by the tests it was written against

My first version of the webhook guard read `$oldStatus` **inside** the `DB::transaction` closure without
capturing it in the `use (...)` list. PHP warns, Laravel raises `ErrorException`, the outer catch logs it
and answers **500**, and the transaction rolls the whole write back — so every courier-reported
cancellation that would have changed the status returned 500 and wrote *nothing*, not even `cancel_date`.
**Wider than the defect it replaced**, on a webhook a courier retries. One missing `$oldStatus` in a
`use` list. Found because the test's assertions were left unweakened; fixed before anything was pushed.

That is the fifth time in this phase a fix has needed its own review, each for a different reason: a
first-come allocation, a lost bound, a whole-unit truncation, a process-static memo, and now an
uncaptured closure variable.

### P4-F124 — an app-side cancel never reverses the wallet credit (RECORDED, not fixed)

The web cancel writes `payment_status = 'Unpaid'` (`Order.php:712`) and `OrderObserver::updated()` fires
only when `payment_status` changes (`OrderObserver.php:32-34`). The Flutter cancel writes no
`payment_status` at all, so the observer never runs and the order's wallet record is never walked back.

**Not fixed.** Writing `'Unpaid'` over `'Paid'` is a payment-semantics change on a shipped endpoint,
which CLAUDE.md §2 and the Phase 04 directive both put in the escalate-to-owner set — and more than one
answer is defensible, since the web's unconditional `'Unpaid'` may itself be wrong for uncollected COD.
`ApiOrderStatusTransitionTest::test_the_api_cancel_leaves_the_payment_status_alone_p4_f124` pins the
current behaviour in **both** directions, and keeps a sibling-order oracle proving the web really does
write `'Unpaid'`, so the gap cannot quietly survive as a note about behaviour nobody has.


---

## P4-F125 to P4-F129 — found by the P4-F124 ledger inventory

An agent was commissioned to measure how money state is recorded, so that P4-F124 could be *designed*
rather than guessed. It found one defect in the goods and four in the money, none of which the money
question itself would have surfaced.

### P4-F125 — three more cancel doors, and two are P4-F124's mirror image — FIXED

P4-4 wired the shared restore into three doors. Three more cancel an order and were not among them:
`Api\ApiController::order_status_change`, `Api\DashboardController::statusChange` and
`Api\DashboardController::orderCancel`.

**The first two are the exact inverse of P4-F124.** They route through `Order::order_status_change()`,
so they *do* write `payment_status = 'Unpaid'` and `OrderObserver` *does* reverse the merchant's wallet
credit — and then they lose the goods. P4-F124 restores the stock and loses the money; these restored
the money and lost the goods. Same family, opposite halves, and **neither was visible from the other**.

Measured through the real routes: `product_stock` stayed at 4 where 7 was due, on all three, with the
cancel landing in every case. Fixed in `4f804baf`; each call site carries its own replay guard.

### P4-F126 — a merchant may assert `Paid` on a COD order at creation — RECORDED

`OrderController::store:385` and `Api\DashboardController::createOrderViaApi:6739` accept `payment_type`
as a free string and `payment_status` as `Paid|Unpaid`, and neither calls `resolveOrderPaymentStatus()`.
`payment_type = 'cod'` with `payment_status = 'Paid'` is accepted, and `OrderObserver::created()` credits
the wallet at creation — **the literal condition owner decision D-06 forbids**. The code's defence is
that a merchant is asserting an out-of-band fact, which is coherent for a counter sale and is not
coherent for a COD parcel that has not left. Owner decision; see the design document's question 6.

### P4-F127 — a refund moves no money record — RECORDED

`OrderRefundController` contains no reference to `payment_status`, `StoreWallet` or
`OrderFinancialService`. A fully refunded order keeps `payment_status = 'Paid'`, keeps its
`store_order_financials` row at `paid`, and keeps the merchant's wallet credit. `order_refunds.refund_status`
is the only record that money came back. Design document question 4.

### P4-F128 — the wallet can be moved without the order — RECORDED

`StoreFinanceController::updateFinancialPaymentStatus():376-452` calls `markFinancialAsPaid()` /
`markFinancialAsUnpaid()` directly on a `store_order_financials.id` and never reads or writes
`orders.payment_status`. The order and its financial record can diverge permanently, in both directions,
with no reconciliation anywhere.

### P4-F129 — the repository's own vocabulary documentation is wrong — RECORDED

`OrderFinancialService.php:651-659` states `'succeeded'` is written to orders by *"twenty order-write
sites"*, and `docs/finance/PAYMENT-STATUS-BACKFILL-ASSESSMENT.md` builds an impact assessment and
production SQL on that premise. Every one of those assignment sites was classified as `$order = new
PlanOrder()` — the SaaS plan rail, not `orders`. If that is right, the assessment's query over `orders`
returns zero rows. **One read-only production query settles it**: `SELECT payment_status, COUNT(*) FROM
orders GROUP BY 1`. The same document also still says the financial tables do not exist in the test
schema; they were created on 2026-11-02 and are measured present.

---

## P4-3b — order-write integrity (2026-11-16)

### P4-F026 — the storefront order and its four child rows were not one transaction — FIXED

`Api\ApiController::place_order` and `::ordersave` wrote the Order, OrderBillingDetail,
OrderCouponDetail, UserCoupon and OrderTaxDetail with five separate `save()` calls and **no boundary
of any kind**. Measured on `d227b7c0`: `DB::beginTransaction|DB::commit|DB::rollBack|DB::transaction`
matched nothing anywhere in either method.

**The reachable half-write is a money defect.** The write order is OrderCouponDetail and *then*
UserCoupon, and `user_coupons` is the only table either coupon cap counts — `Coupon::usesByCustomer()`,
`PerUsesCouponCount()`, `coupon_uses()` and `UsesCouponCount()` are all counts on it. A throw between
those two saves leaves "the discount is recorded, the redemption is not".

**And the throw is caller-reachable.** `Coupon::find($coupon_data['coupon_id'])` takes the id from the
request and `$UserCoupon->coupon_id = $Coupon->id;` dereferences it unchecked. Posting a real coupon
*code* with a nonexistent `coupon_id` makes the server grant the discount, write the order and the
coupon-detail row, and die on null — measured in `StorefrontOrderWriteAtomicityTest`, where a coupon
limited to one use **paid out twice**.

Fixed: both writers wrap the order, its stock attribution and all four child rows in one transaction.
The intake claim's answer and `Utility::paymentWebhook()` moved **below** the commit.

### P4-F130 — an unconditional `rollBack()` would unwind a caller's transaction — FIXED IN THE SAME CHANGE

Found while building a post-commit probe, not by a failing test. `Connection::rollBack()` with no
argument computes `$toLevel = $this->transactions - 1`, so inside somebody else's transaction a bare
`DB::rollBack()` after our own commit succeeded rolls back **their** work. The catch now captures
`DB::transactionLevel()` before opening the boundary and rolls back only above it.

### P4-F131 — `$order->exists` is not an answer after a rollback — FIXED

The D-13 stock-compensation gate asked `$order->exists`. `Model::performInsert()` sets it and nothing
ever unsets it, so the moment these writers gained a boundary the gate began answering *"an order
claims this stock"* for rows that had just been rolled away — skipping the give-back on every failure
inside the boundary. All seven guards now call `orderRowSurvived()`, which reads the `orders` table on
the query builder and fails closed toward **not** restoring when the probe itself cannot answer.
Pinned by a new `ApiOrderWriterStockCompensationTest` case that fails on both writers under the old
spelling and passes under the new one.

### P4-F132 — `OrderController::store()` billed any store's customer — FIXED

`products.*.product_id` carried an `exists:products,id` rule, which is global, and `customer_id` carried
no ownership predicate at all. The merchant screen's own GET would never offer another store's customer;
the POST never checked. The predicate now uses `resolvedCurrentStoreId()` — **not** `getCurrentStore()`,
whose null return compiles to `where('store_id', IS NULL)` and would have *accepted* every customer row
with a null `store_id`. Both fail-open cases are pinned by their own tests.

### P4-F133 — the web checkout had no duplicate-submission control — FIXED

A second POST to `{slug}/process-order` naming the same cart rows wrote a second order, took the stock
a second time, cleared the cart again and flashed no error — measured at `d227b7c0`. For `cod` that is a
second amount for a driver to collect. The F276 intake claim moved into
`Http\Controllers\Concerns\ClaimsCheckoutIntake` and is now used by both the API and the web door. The
claim is released unless the checkout consumed its own cart rows, which is per-claim evidence rather
than an order count (a count is wrong under concurrency in the direction that refuses an innocent
shopper).

### P4-F134 — `ordersave` may record no coupon redemption at all — RECORDED, NOT FIXED

`ordersave`'s coupon child-row block reads `$order_array['coupon_info']` and is gated on
`isset($coupon_data['coupon_id'])`, but an earlier hardening rebuilt `coupon_info` from the server's
verification with the keys `code`, `discount`, `discount_string`, `price`, `discount_amount` — **no
`coupon_id`**. If that reading is right, orders placed through `api/{slug}/order-save` write neither
`order_coupon_details` nor `user_coupons`, so coupon caps never see them. Not yet measured end to end;
it needs a driven test before anything is changed, because the fix direction (start recording
redemptions that are not being recorded today) changes what shoppers are allowed to spend.

### P4-F135 — a refused coupon still burns the shopper's allowance — RECORDED, NOT FIXED

`place_order`'s coupon child-row block is gated on `!empty($request->coupon_info)` rather than on
whether `apply_coupon()` accepted the coupon, so a coupon the server **refused** still writes both
`order_coupon_details` and `user_coupons`. The discount is correctly not applied; the redemption is
recorded anyway. Same block takes `coupon_name`, `coupon_code`, `coupon_discount_type`,
`coupon_discount_number` and `user_coupons.amount` from request input rather than from the resolved
coupon row. Queued for P4-1 with the identity fix, because both want the same change: derive the coupon
from the server's own validated resolution, never from the caller's `coupon_id`.

---

## P4-6 — refunds and returns (2026-11-16)

### P4-F082 — returning a second line subtracted the first line again — FIXED

`Order::product_return()` sums the `final_price` of every line whose `return` flag is set — a
cumulative figure, correctly, because `orders.return_price` records everything ever returned. It
then wrote `$final_price = ($Order->final_price - $return_price)`, and `$Order->final_price` is the
total **as the previous return already reduced it**.

Measured on two lines of 90.00 and 40.00 with 17.50 of tax, total 147.50:

| | computed | correct |
|---|---|---|
| first return (90.00) | 57.50 | 57.50 |
| second return (40.00) | **−72.50** | 17.50 |

`orders.final_price` is the amount **owed** — on a `cod` order it is the cash a driver collects — so
a negative value is an instruction to hand money over at the door, printed on the merchant's own
order screen. The error is exactly the previously-returned line's value, every time, and compounds
with each further line.

**The method's own docblock already named this** (`app/Models/Order.php`: "recomputes the total from
the CURRENT final_price rather than from an immutable base, so the money half compounds") and then
fixed only the same-line replay, whose guard keys on one line's return flag. Nine existing tests
drive this workflow and **every one returns a single line**, which is how a defect written down in
the source survived all of them.

Fixed by reconstructing the base as `final_price + return_price`, which is the original total by
construction and needs no new column and therefore **no migration**. On a first return
`return_price` is 0, so the behaviour is byte-identical; only second and later calls change. Pinned
by `SecondReturnTotalTest` (4 cases: a positive control, the defect, a no-negative-total property,
and a flat-coupon case that stops the fix being made by breaking the summation instead). All three
failing cases were re-reddened by reverting the one line.

**NOT repaired: orders already driven negative by this defect.** For such a row
`final_price + return_price` is not the original total, and writing a plausible number into a money
column is exactly what this workflow's tax handling refuses to do. Reconciling existing damage needs
a production read first and is an owner item — the same stance as P4-F124.
