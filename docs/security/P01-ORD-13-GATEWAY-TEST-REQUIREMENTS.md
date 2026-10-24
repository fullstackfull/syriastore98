# P1-ORD-13 criterion 4: gateway test infrastructure, what must be procured, and what need not wait

*Written 2026-10-23 from reading the code. **Nothing in this document was executed by this session:**
no test was run, no artisan command was run, no provider was contacted and no web page was read. Every
claim about this repository carries a `file:line` and can be checked without running anything. Every
measurement quoted is attributed to the run that produced it. Every claim about a provider's own
product is marked **NOT VERIFIED** with the check that would settle it.*

Issue [#82](https://github.com/fullstackfull/syriastore98/issues/82), acceptance criterion 4: **"Every
gateway return handler is driven end to end (the vendored-style handlers are in the host app, so they
are in scope)."** Criteria 1 to 3 are closed and executed
(`docs/development/phase-1/12-PHASE-01-TASK-LEDGER.md`, the `P1-ORD-13` row). Criterion 4 is open.

**Owner decision, 2026-10-23:**

> *"This requires gateway test infrastructure, not another business decision. Preserve fail-closed
> payment behavior, prepare the exact provider testing requirements, and complete all independently
> testable work."*

This document is the procurement and test-infrastructure specification that decision asks for. It is
not a proposal: it names the exact settings keys, the exact routes, the exact seams, and the exact
work that does not have to wait.

---

## 1. Scope: what "a gateway return handler" is in this codebase

52 payment types are accepted. The three counts agree, which is the premise everything below rests on:

| set | count | source |
|---|---|---|
| accepted `payment_type` values | **52** | `app/Http/Controllers/PaymentController.php:1563-1574` (`getValidPaymentTypes`) |
| arms in the dispatch switch | **52** | `app/Services/PaymentService.php:323-440` (`process()`) |
| gateways in the settings screen | **52** | `app/Http/Controllers/PaymentSettingController.php:183-447` (`getPaymentGateways`) |

Two of the 52 (`cod`, `bank_transfer`) are not providers; two more (`whatsapp`, `telegram`) are relay
methods with no payment API. **So 48 external payment providers are integrated.**

They do **not** each have a return handler. They fall into four classes, and the class decides what
test infrastructure each one needs.

| class | what happens on the way back | count | needs a provider to drive? |
|---|---|---|---|
| **A. Converge on the generic writer** | the provider's own controller takes the return, checks its own status, then `redirect()->route('store.payment.status', $slug)` | **9** | the provider's half, yes; the **writer**, no |
| **B. Return straight to the generic writer** | `PaymentService` gave the provider `route('store.payment.status', ...)` as its return / callback / webhook URL, so the browser or the gateway lands directly on `PaymentController::getProductStatus` | **32** | the provider's half, yes; the **writer**, no |
| **C. Own order writer** | the gateway returns to a handler that writes the order itself | **7** | **per handler**, see §4 |
| **D. No gateway** | `cod`, `bank_transfer` (written inline by `PaymentService::getProductStatus`, `app/Services/PaymentService.php:379,381`), `whatsapp`, `telegram` | 4 | **no** |

The class-B return URLs are built at `app/Services/PaymentService.php:662, 669, 755, 848-849,
985-986, 1111, 1152, 1209, 1215, 1222, 1284-1285, 1354-1355, 1438-1439, 1495, 1576-1578, 1662, 1735,
1796-1804, 1951-1952, 2013, 2070-2072, 2138, 3554-3556, 3710-3711, 3851, 3890, 3956-3957, 4078-4079,
4575, 4582, 4627, 5000`. The class-A redirects are at `MyFatoorahController.php:193`,
`OzowController.php:310`, `PaynowController.php:269`, `DPOPayController.php:530`,
`BraintreeController.php:220`, `PowertranzPaymentController.php:423`, `SenangPayController.php:222`,
`CyberSourceController.php:325` and `PayUPaymentController.php:201`. SSLCommerz is class B, not A, although
its controller exists: `routes/customer.php:388` declares no SSLCommerz storefront route at all (only a
comment), and the storefront `success_url` points straight at `store.payment.status`
(`PaymentService.php:4999-5003`).

**The consequence for criterion 4 is the single most useful fact in this document.** 41 of the 48
providers (classes A and B) share **one** order writer, `PaymentController::getProductStatus`
(`app/Http/Controllers/PaymentController.php:2138`, route `ANY {storeSlug?}/get-payment-status`,
`routes/customer.php:186`). Driving *that* handler end to end needs no provider at all, and it is
already driven in the suite. What a provider sandbox buys for those 41 is not the writer's behaviour.
It is the answer to a narrower question: *does the provider actually come back to this URL, with the
parameters the handler's pre-writer blocks read?*

**Seven handlers in `app/` read the stored checkout state and write an order**, derived from the
source, not from a list: `grep -rl 'storedCheckoutState(' app/` returns
`AuthorizeNetPaymnetController`, `CinetPayController`, `EasebuzzController`, `KhaltiPaymnetController`,
`NMIPayController`, `PaiementProPaymentController` and `PaymentController`, plus the trait that defines
the mechanism. `PendingPaymentGatewayCensusTest` re-derives the same set at run time and fails if an
eighth appears. `PaymentController::whatsapp` is a further order writer that deliberately keeps its
`$request->all()` fallback, because two checkout views post to it without passing through
`processOrder()` (recorded at `tests/Feature/Commerce/UnguardedOrderWriterTrustBoundaryTest.php:30-36`).

---

## 2. What "preserve fail-closed payment behaviour" currently means, with file:line

`app/Http/Controllers/Concerns/GuardsGatewayReturn.php` is the shared trait the storefront gateway
returns use. Read in full, it holds trust controls over the checkout state, a replay
claim, a server-derived payable, two basket pre-flights and five distinct refusals. Not all of them are fail-closed controls,
and the distinction matters, because a test harness that makes the wrong one convenient will quietly
take a control out of the money path.

### 2.1 The controls that must not be weakened

| # | control | file:line | what it refuses | how a test harness weakens it by accident |
|---|---|---|---|---|
| **F1** | `storedCheckoutState()` | `GuardsGatewayReturn.php:122-151` | a return with no server-stored checkout state. Returns `null`, **never** the request. Replaces the `$request->all()` fallback that let a caller who omitted the session cookie supply the cart, the prices, the shipping, the coupon, the store and the customer | a fixture that "helpfully" re-reads the request when the session is empty, or a harness flag that lets a driver POST a state inline instead of through a session |
| **F2** | `adoptStoredCheckoutState()` | `GuardsGatewayReturn.php:203-210` | the posted `customer_id` and `method_id` surviving a `Request::merge()` that cannot overwrite a key the state omits. Measured: a guest return posting `customer_id=<victim>` wrote an order owned by the victim on cinetpay and easebuzz; a store-B shipping method at cost `-500` wrote `-500.00SYP` into a store-A order's `delivery_price` | replacing it with a bare `$request->merge($state)` because "it merges anyway". That exact substitution left the defect live on the generic return after it was closed on the other five (`P01-SECURITY-WORK-QUEUE.md` S32 item 3) |
| **F3** | `claimGatewayReturn()` | `GuardsGatewayReturn.php:277-308`, keys at `:343-371`, reference at `:408-426`, TTL `172800` at `:102` | a second order from one checkout or one payment. `Cache::add()` is insert-if-absent, decided by the engine rather than by PHP, so exactly one of two **overlapping** returns can take a key. Two keys: the checkout nonce stamped once at `PaymentService.php:314` (`stampCheckoutNonce`, called from `process()` at `:324`), and the gateway's own transaction reference, which only khalti (`payload.token`) and cinetpay (`cpm_trans_id`) supply | a harness that runs on `CACHE_STORE=array` and then concludes the claim works under concurrency. It does not follow: `array` is per-process. The engine-level proof is `tests/Support/Security/bin/verify-gateway-return-claim-on-mysql.php` |
| **F4** | `serverPayableAmount()` | `GuardsGatewayReturn.php:523-536` | a capture amount chosen by the browser. Replaced AuthorizeNet's `$amount = (int) json_decode($input['data'])['get_amount']`. Returns `null` (so the caller refuses) when the state carries no total, and refuses a non-positive one | a sandbox fixture that pins a convenient round amount into the request instead of into the stored state |
| **F5** | `storedCheckoutCartList()` | `GuardsGatewayReturn.php:571-582` | the basket being read back from the hidden `response_data` field the server printed into the payment form (`resources/views/front_end/payment/nmi-payment.blade.php:98`) | any harness that posts a cart list to a return route "so the handler has something to iterate" |
| **F6** | `basketHasUnusableQuantity()` | `GuardsGatewayReturn.php:610-663`, ceiling `MAX_SANE_LINE_QUANTITY = 1000000` at `:110` | a cart line whose quantity is not a positive integer, or is above the sanity ceiling. Measured: qty `-5` **adds** stock (20 -> 25); qty `2147483647` took a 50-unit product to `product_stock -2147483597` and still wrote the order | raising or removing the ceiling to make a load fixture pass. The constant's own comment says raise it, never remove it |
| **F7** | `basketHasForeignProduct()` | `GuardsGatewayReturn.php:707-782` | a basket naming another store's product, **or** a variant that does not belong to the named product. Uses `withoutGlobalScopes()` because `Product` and `ProductVariant` are in no `config/tenancy.php` `scoped_models` entry and so add no predicate for anybody | a multi-tenant fixture that seeds one shared product catalogue |
| **F8** | `gatewayReturnPaymentStatus()` -> `PaymentVerificationService::resolveOrderPaymentStatus()` | `GuardsGatewayReturn.php:481-489`, service at `app/Services/PaymentVerificationService.php:178`; `ENFORCED_PAYMENT_TYPES` at `:60-62` | nothing today, **on purpose**, and that is the point: it replaced the literal `'Paid'` in six handlers so that every unverified Paid write is now logged and enforcement is a one-line change in one service. `ENFORCED_PAYMENT_TYPES` holds exactly one entry, `'paymera'` | adding a provider to `ENFORCED_PAYMENT_TYPES` to make a sandbox test green. That is a business change (merchant wallets stop being credited), not a test fixture. `EnforcedPaymentTypesIntegrityTest` exists for this |
| **F9** | Paymera status poll fails closed | `PaymentController.php:464-513`, sentinel `PAYMERA_STATUS_UNAUTHENTICATED` at `:373`, credential at `:425-443` | with no credential configured it **makes no call at all** and returns the sentinel, so a missing credential can never be mistaken for a capture. An unreachable host, a non-2xx reply and a non-array body all return the same sentinel | `Http::fake()` with a permissive `'*' => Http::response([...])` default, which answers the poll as though it authenticated |
| **F10** | Paymera callback signature required by default | `config/services.php:264` (`require_callback_signature`, default **true**), enforced at `PaymentController.php:839-851`, scheme at `PaymentVerificationService.php:471` | **every Paymera callback**, today. The signature field name, algorithm and secret exist only in Paymera's integration document (GATE G(4) / GATE 20, `docs/development/phase-1/07-OWNER-APPROVAL-GATES.md:118,640`), so `verifyPaymeraSignature()` returns UNVERIFIABLE and the callback is rejected | setting `PAYMERA_REQUIRE_CALLBACK_SIGNATURE=false` in a test `.env` and letting it reach a deployed environment. It is a documented escape hatch for the owner, not a test convenience |
| **F11** | Paymera captured amount checked | `PaymentController.php:861-876`, `PaymentVerificationService::verifyPaymeraCapture()` at `:337` | a callback whose captured amount cannot be parsed or does not match. Unparseable or absent is UNVERIFIABLE, which fails closed | supplying `services.paymera.callback_amount_field` from a guess rather than from Paymera's document |
| **F12** | `NMIService::chargeSale()` transport failure is not a sale | `app/Services/NMIService.php:64-85` | a `TransferException` returns `[]`, so the caller's `response == 1` is false: no order, no stock movement. The previous inline call let the exception escape as a 500 mid-charge | a double that returns a success array on every call, including the ones that model an outage |

### 2.2 The parts of the trait that are *not* fail-closed controls

Recording these so a reviewer does not treat them as load-bearing, and so nobody "preserves" the wrong
thing:

- `claimGatewayReturn()` **fails OPEN when there is nothing to claim** (`:281-289`): a checkout
  initiated before the nonce shipped, on a gateway with no transaction reference, has no key, and the
  return proceeds on the session control alone. Deliberate and logged, so the window is measurable.
- `releaseGatewayReturnClaim()` (`:327-332`) **gives a claim back**. It is correct only where the
  stored state is being restored and no money moved (NMI's declined card). Its own docblock says it is
  not a general undo.
- The five refusal helpers (`gatewayReturnRefusal` `:867`, `gatewayReturnReplayRefusal` `:439`,
  `gatewayReturnQuantityRefusal` `:844`, `gatewayReturnForeignProductRefusal` `:791`,
  `gatewayReturnConfigurationRefusal` `:827`) carry **five distinct flash strings on purpose**. Two
  failures sharing one string already broke a test whose job was to prove the state guard does not
  over-refuse (`:812-826`). A harness must not normalise them.
- None of these refusals is a 401. `gatewayReturnRefusal()`'s docblock states why: the shipped Flutter
  app forces a logout on any 401.

### 2.3 The honest limit, restated

`GuardsGatewayReturn` **does not decide whether a payment succeeded**, and says so at `:64-70`. No
method in it looks at a status code, a signature or a gateway response. Any future gateway test
infrastructure that makes a handler *look* verified without a provider having verified anything is the
failure mode the project brief names as "simulated validation mistaken for real gateway verification",
and `GatewayReturnTrustBoundaryTest.php:37-41` already records that its own tests do not prove it.

---

## 3. What can already be driven end to end with no provider

This extends the measured table at `tests/Feature/Commerce/PartialPaymentDepositSourceTest.php:173-197`
to the whole set. **Every row marked *measured* is attributed to the file that recorded that
measurement. This session ran nothing**, so a row's "yes" means "that file records it as driven", and
the "what blocks it" column is code reading.

| writer | route | drivable to `new Order()` today | evidence | what blocks it |
|---|---|---|---|---|
| `PaymentController::getProductStatus` (41 providers) | `ANY {slug}/get-payment-status` (`routes/customer.php:186`) | **yes** | measured: `GatewayReturnTrustBoundaryTest::shippingWriterRoutes()` `:164-170` drives it to the `delivery_price` write; `test_posted_money_cannot_change_the_order_total` `:739` | nothing. Needs `countries`/`states`/`cities` rows, because the handler inserts a `cities` row from `billing_info` and a missing FK parent 500s **before** the order write |
| `PaiementProPaymentController::getProductStatus` | `ANY {slug}/get-payment-paiementpro` (`:195`) | **yes** | measured: the positive control `test_a_legitimate_guest_return_still_writes_its_order` `:666`, and `PartialPaymentDepositSourceTest` (302, one order) | nothing. Note `if ($request->responsecode == 0)` is **commented out** at `PaiementProPaymentController.php:227`, so the handler writes for any return value |
| `CinetPayController::getProductStatus` | `ANY {slug}/get-payment-cinetPay` (`:197`) | **yes** | measured: `PartialPaymentDepositSourceTest` (302, one order) with `responsecode=0, status=success` | nothing. The only gate is `$request->responsecode == 0` (`CinetPayController.php:370`) |
| `EasebuzzController::getProductStatus` | `ANY {slug}/get-payment-easebuzz` (`:200`) | **yes** | measured: `PartialPaymentDepositSourceTest` (302, one order) with `status=success` | nothing. Reads its own session key `easebuzz_request_data` (`EasebuzzController.php:248`) |
| `PaymentService::getProductStatus` (`cod`, `bank_transfer`) | via `{slug}/process-order` | **yes** | measured: `CodStateMachineTest`, `CodCashCollectionTest` | nothing |
| `PaymentController::whatsapp` (`whatsapp`, `telegram`) | `POST {slug}/whatsapp`, `{slug}/telegram` (`:184-185`) | **yes** | measured: `UnguardedOrderWriterTrustBoundaryTest`, `WhatsappUrlStockTest` | nothing. Telegram's outbound send is `Http::` (`PaymentController.php:3480`) and so is fakeable |
| `NMIPayController::getNMIProductStatus` | `ANY {slug}/get-payment-NMI` (`:360`) | **yes, through an existing seam** | measured: `UnguardedOrderWriterTrustBoundaryTest.php:273-298`, `StockManagementSettingTest.php:275-293`, `GatewayReturnBasketClearTest.php:120` all bind a double for `NMIService` | nothing for the **writer**. `NMIService::chargeSale()` (`app/Services/NMIService.php:64`) is the seam; the real endpoint is `https://secure.networkmerchants.com/api/transact.php` (`:18`). The seam proves the writer and proves nothing about NMI |
| `PaymentController::handlePaymeraCallback` / `handlePaymeraTrigger` | `GET /store/paymera/callback/{orderId}` (`routes/web.php:374`), `POST /store/paymera/trigger/{orderId}` (`:514`) | **partly** | measured: `PaymeraCallbackIdempotencyTest`, `PaymeraCallbackVerificationTest`, `PaymeraStatusPollTest` (which uses `Http::fake()` at `:58, 79, 110, 128, 147, 254`) | the **refusal** paths are fully drivable. The **success** path is not, because `paymera` is the one entry in `ENFORCED_PAYMENT_TYPES` and `require_callback_signature` defaults true with no scheme configured. Driving success needs GATE G(4), not a sandbox account |
| `KhaltiPaymnetController::getOrderPaymentStatus` | `POST {slug}/order-khalti` (`:190`) | **no** | measured: "khalti stops at `$khalti->verifyPayment()`" (`GatewayReturnTrustBoundaryTest.php:659-664`) | `app/Khalti/Khalti.php:16-42` posts to `https://khalti.com/api/v2/payment/verify/` with **raw `curl_init()`**, and the controller does `new Khalti()` inline. No container seam, no `Http::fake()`, no sandbox host in the code |
| `AuthorizeNetPaymnetController::getOrderPaymentStatus` | `ANY {slug}/authorizenet-status` (`:191`) | **no** | the pre-existing `Undefined variable $requestNet` crash is **fixed** (`AuthorizeNetPaymnetController.php:435-460`, `AuthorizeNetCredentialFailureTest`), which removed the reason given in `PartialPaymentDepositSourceTest` | the handler **is the charge**. `new AnetController\CreateTransactionController($requestNet)` is built inline at `:461` and executed at `:464`/`:468`. No injected service, so no seam. This is the one class-C handler that needs either a sandbox account **or** the same extraction `NMIService` got |
| `PayUPaymentController::storeGetPayUStatus` | `ANY /store-payu-payment/status` (`:363`) | **redirect only** | it writes no order; it `Crypt::decrypt($request->data)` and redirects to `store.payment.status` (`PayUPaymentController.php:192-201`) | to drive it, mint `data` with `Crypt::encrypt(['status' => 'true', 'order_id' => $orderID])` exactly as `PaymentService.php:4495-4496` does. The `DecryptException` recorded in `PartialPaymentDepositSourceTest` is a fixture gap, not a provider gap |

**So: 8 of the 11 order-writing paths are drivable to a persisted order today with no provider.** Two
(khalti, authorizenet) need a seam or a sandbox. One (paymera success) needs an owner gate, not a
sandbox.

---

## 4. Per provider: the exact credentials to obtain

Every key below is the **`settings.name` value** the application reads, taken from
`app/Http/Controllers/PaymentSettingController.php:183-447` (`getPaymentGateways()`), which is the one
declarative map of gateway to fields in the codebase. The same names are read at run time either as
`Utility::GetValueByName('<key>', $store->id)` or as `$admin_payment_setting['<key>']` from
`getAdminAllSetting()` (`app/Helper/helper.php:419`); both resolve the same `settings` rows, so the key
name is the same whichever form a handler uses.

**They are per-store rows, not env vars.** A sandbox credential has to be written to the `settings`
table for the store under test, not to `.env`. The one exception is Paymera's poll host and signature
scheme, which are `config/services.php` keys (`:215-315`).

"Sandbox selector" is what the application itself uses to choose a test environment. Where there is
none, the provider's own test keys are the only selector, and whether the provider issues them is
**NOT VERIFIED** here.

| # | `payment_type` | settings id | required settings keys | sandbox selector in this code | return class |
|---|---|---|---|---|---|
| 1 | `cod` | `cod` | none (`is_cod_enabled`, `cod_info`) | n/a | D |
| 2 | `bank_transfer` | `bank_transfer` | none (`is_bank_transfer_enabled`, `bank_transfer`) | n/a | D |
| 3 | `whatsapp` | `whatsapp` | `whatsapp_number` | n/a | D |
| 4 | `telegram` | `telegram` | `telegram_access_token`, `telegram_chat_id` | n/a | D |
| 5 | `stripe` | `stripe` | `stripe_publishable_key`, `stripe_secret_key` | none in code; provider test keys | B |
| 6 | `paymera` | `Paymera` | `PAYMERA_TERMINAL_ID`, `PAYMERA_USERNAME` | `config services.paymera.status_base_url`, default `https://egate-t.paymera.cc` (`config/services.php:276`), which is already Paymera's **test** gateway | C |
| 7 | `paystack` | `paystack` | `paystack_public_key`, `paystack_secret_key` | none in code; provider test keys | B |
| 8 | `Razorpay` | `razorpay` | `razorpay_public_key`, `razorpay_secret_key` | none in code; provider test keys | B |
| 9 | `mercado` | `mercado` | `mercado_access_token`, `mercado_mode` | `mercado_mode != 'live'` selects `sandbox_init_point` (`PaymentService.php:797`) | B |
| 10 | `skrill` | `skrill` | `skrill_email` | none in code | B |
| 11 | `paymentwall` | `paymentwall` | `paymentwall_public_key`, `paymentwall_private_key` | none in code | B |
| 12 | `paypal` | `paypal` | `paypal_client_id`, `paypal_secret_key`, `paypal_mode` | `paypal_mode != 'live'` (`PaymentService.php:939`) | B |
| 13 | `flutterwave` | `flutterwave` | `flutterwave_public_key`, `flutterwave_secret_key` | none in code; provider test keys | B |
| 14 | `paytm` | `paytm` | `paytm_merchant_id`, `paytm_merchant_key`, `paytm_industry_type`, `paytm_mode` | `paytm_mode` | B |
| 15 | `mollie` | `mollie` | `mollie_api_key`, `mollie_profile_id`, `mollie_partner_id` | none in code; provider test keys | B |
| 16 | `coingate` | `coingate` | `coingate_auth_token`, `coingate_mode` | `coingate_mode` (`PaymentService.php:1183`) | B |
| 17 | `Sspay` | `sspay` | `sspay_secret_key`, `sspay_category_code` | none in code | B |
| 18 | `toyyibpay` | `toyyibpay` | `toyyibpay_secret_key`, `toyyibpay_category_code` | none in code | B |
| 19 | `Paytabs` | `paytabs` | `paytabs_profile_id`, `paytabs_server_key`, `paytabs_region` | none in code | B |
| 20 | `iyzipay` | `iyzipay` | `iyzipay_private_key`, `iyzipay_secret_key`, `iyzipay_mode` | `iyzipay_mode == 'sandbox'` -> `https://sandbox-api.iyzipay.com` (`PaymentService.php:1482`) | B |
| 21 | `payfast` | `payfast` | `payfast_merchant_id`, `payfast_merchant_key`, `payfast_salt_passphrase`, `payfast_mode` | `payfast_mode` | B |
| 22 | `benefit` | `benefit` | `benefit_secret_key`, `benefit_private_key` | none in code | B |
| 23 | `cashfree` | `cashfree` | `cashfree_key`, `cashfree_secret_key` | none in code | B |
| 24 | `aamarpay` | `aamarpay` | `aamarpay_store_id`, `aamarpay_signature_key`, `aamarpay_description` | **hard-coded sandbox**: `https://sandbox.aamarpay.com/request.php` (`PaymentService.php:1759`, `:1842`). There is no live path in this code | B |
| 25 | `paytr` | `paytr` | `paytr_merchant_id`, `paytr_merchant_key`, `paytr_salt_key` | **hard-coded `$test_mode = 0`** (`PaymentService.php:1914`), signed into the hash at `:1919` and sent at `:1955`. There is no test path in this code | B |
| 26 | `yookassa` | `yookassa` | `yookassa_shop_id_key`, `yookassa_secret_key` | none in code | B |
| 27 | `Xendit` | `Xendit` | `Xendit_api_key`, `Xendit_token_key` | none in code; provider test keys | B |
| 28 | `midtrans` | `midtrans` | `midtrans_secret_key` | **hard-coded** `\Midtrans\Config::$isProduction = false` (`PaymentService.php:2105`). Sandbox only | B |
| 29 | `Nepalste` | `nepalste` | `nepalste_public_key`, `nepalste_secret_key`, `nepalste_mode` | `nepalste_mode != 'live'` -> `https://nepalste.com.np/sandbox/payment/initiate` (`PaymentService.php:3564,3569`) | B |
| 30 | `PayHere` | `payhere` | `payhere_merchant_id`, `payhere_merchant_secret`, `payhere_app_id`, `payhere_app_secret`, `payhere_mode` | `payhere_mode === 'sandbox'` -> `https://sandbox.payhere.lk/` (`PaymentService.php:3664-3665`) | B |
| 31 | `khalti` | `khalti` | `khalti_public_key`, `khalti_secret_key` | **none in code**. `app/Khalti/Khalti.php:23` hard-codes `https://khalti.com/api/v2/payment/verify/` | **C** |
| 32 | `AuthorizeNet` | `authorizenet` | `authorizenet_login_id`, `authorizenet_transaction_key`, `authorizenet_mode` | `authorizenet_mode == 'live'` -> `ANetEnvironment::PRODUCTION`, **anything else -> `SANDBOX`** (`AuthorizeNetPaymnetController.php:462-469`). Sandbox is the default | **C** |
| 33 | `Tap` | `tap` | `tap_secret_key` | none in code; provider test keys | B |
| 34 | `PhonePe` | `phonepe` | `phonepe_client_id`, `phonepe_client_secret`, `phonepe_client_version`, `phonepe_mode` | `phonepe_mode`, defaulting to `'sandbox'` (`PaymentService.php:3883`; the return-side status check is `PaymentController.php:2160-2163`) | B |
| 35 | `Paddle` | `paddle` | `paddle_vendor_id`, `paddle_vendor_auth_code`, `paddle_public_key`, `paddle_mode` | `cashier.sandbox` from `paddle_mode`, defaulting to `true` (`PaymentService.php:3941`) | B |
| 36 | `Paiementpro` | `paiementpro` | `paiementpro_merchant_id` (and `paiementpro_mode` is declared but **not** required) | **`paiementpro_mode` is collected by the settings screen and read nowhere else**: `grep -rn 'paiementpro_mode' app/` returns only `PaymentSettingController.php:365` | **C** |
| 37 | `FedPay` | `fedpay` | `fedpay_public_key`, `fedpay_secret_key`, `fedpay_mode` | `fedpay_mode` (`PaymentService.php:4045`) | B |
| 38 | `CinetPay` | `cinetpay` | `cinet_pay_site_id`, `cinet_pay_api_key`, `cinet_pay_secret_key` | none in code. **`cinet_pay_secret_key` is required by the settings screen and read back at `app/Models/Utility.php:1829`, and no CinetPay call uses it**: `PaymentService.php:4098-4130` and `CinetPayController.php:91-100, 264-267` send only the site id and api key | **C** |
| 39 | `SenagePay` | `Senangpay` | `senang_pay_merchant_id`, `senang_pay_secret_key`, `Senangpay_mode` | `Senangpay_mode` | A |
| 40 | `CyberSource` | `cybersource` | `cybersource_pay_merchant_id`, `cybersource_pay_secret_key`, `cybersource_pay_api_key` | none in code | A |
| 41 | `Ozow` | `ozow` | `ozow_pay_Site_key`, `ozow_pay_private_key`, `ozow_pay_api_key`, `ozow_mode` | `ozow_mode == 'sandbox'` -> `IsTest=true` (`PaymentService.php:4315`) | A |
| 42 | `MyFatoorah` | `myfatoorah` | `myfatoorah_pay_country_iso`, `myfatoorah_pay_api_key`, `myfatoorah_mode` | `myfatoorah_mode == 'sandbox'` -> `isTest` (`PaymentService.php:4744`) | A |
| 43 | `easebuzz` | `easebuzz` | `easebuzz_merchant_key`, `easebuzz_salt_key`, `easebuzz_enviroment_name` | `easebuzz_enviroment_name`, passed to the SDK constructor (`PaymentService.php:4402, 4427`). Note the spelling: `enviroment`, one `n` | **C** |
| 44 | `NMI` | `nmi` | `nmi_api_private_key` | none in code. Endpoint hard-coded at `app/Services/NMIService.php:18` | **C** |
| 45 | `payu` | `payu` | `payu_merchant_key`, `payu_salt_key`, `payu_mode` | `payu_mode` (`PaymentService.php:4476`) | A |
| 46 | `Paynow` | `paynow` | `paynow_pay_integration_id`, `paynow_pay_integration_key`, `paynow_pay_merchant_email`, `paynow_mode` | `paynow_mode == 'sandbox'` (`PaymentService.php:4676, 4690`) | A |
| 47 | `sofort` | `sofort` | `sofort_publishable_key`, `sofort_secret_key` | none in code | B |
| 48 | `esewa` | `esewa` | `esewa_merchant_key`, `esewa_mode` | `esewa_mode` (`PaymentService.php:4613`) | B |
| 49 | `DPO` | `dpopay` | `dpo_pay_Company_Token`, `dpo_pay_Service_Type` | none in code | A |
| 50 | `Braintree` | `braintree` | **`required_fields` is EMPTY** (`PaymentSettingController.php:436`); the fields are `braintree_pay_merchant_id`, `braintree_pay_public_key`, `braintree_pay_private_key`, `braintree_mode` | `braintree_mode`, defaulting to `'sandbox'` (`PaymentService.php:4858`) | A |
| 51 | `PowerTranz` | `powertranz` | **`required_fields` is EMPTY** (`:441`); the fields are `powertranz_pay_merchant_id`, `powertranz_pay_processing_password`, `powertranz_pay_production_url`, `powertranz_mode` | `powertranz_mode` | A |
| 52 | `SSLCommerz` | `sslcommerz` | **`required_fields` is EMPTY** (`:446`); the fields are `sslcommerz_pay_store_id`, `sslcommerz_pay_secret_key`, `sslcommerz_mode` | `sslcommerz_mode == 'sandbox'` -> `https://sandbox.sslcommerz.com/gwprocess/v4/api.php` (`PaymentService.php:5029`) | B |

### 4.1 Does the provider offer a sandbox, and what is the signup prerequisite?

**NOT VERIFIED for all 48 providers, and deliberately not guessed.** Nothing in this container can
reach a provider, and no provider documentation was read. What the code *does* establish is the row
above: whether **this application** has a code path that selects a test environment. That is a
different question from whether the provider issues test credentials, and the two must not be
conflated in a procurement request.

**What would establish it:** for each provider the owner intends to test, one read of that provider's
own developer documentation for (a) whether test/sandbox credentials exist, (b) whether a live business
account, a company registration or a country of incorporation is a prerequisite for obtaining them, and
(c) whether the sandbox will accept a callback URL on a public hostname. Record the answer against the
row above.

**Three rows where the code already narrows the question:**

- **aamarpay (#24), paytr (#25), midtrans (#28)** are hard-wired. aamarpay and midtrans can only be
  driven in sandbox from this code; paytr can only be driven live. For paytr, an end-to-end test in a
  sandbox would require a code change, and that change is a live-money path.
- **AuthorizeNet (#32)** already defaults to `SANDBOX` and only switches to `PRODUCTION` on the literal
  `authorizenet_mode == 'live'`. So a sandbox login id and transaction key are the only missing piece
  for that handler, apart from the seam question in §5.
- **Paymera (#6)** is already pointed at a host named `egate-t` ("t" for test, read from the hostname
  and recorded as such in `config/services.php:269-271`). Paymera's blocker is not a sandbox account.
  It is GATE G(4): the callback signature field name, algorithm and secret, plus the response field
  carrying the captured amount, plus the expected amount unit. All four are already owner gates
  (GATE 20 / GATE 14, `docs/development/phase-1/07-OWNER-APPROVAL-GATES.md:112,118,640-669`).

---

## 5. What each return path needs in order to be driven

This answers, per routed return handler, the question "what has to exist before a test can post to
this route and reach `new Order()`". It is read from each handler's own entry block. The generic
writer and PayU's redirect are included because criterion 4 names every return handler, not only the
class-C ones.

| handler | route and HTTP shape | what the return itself must carry | what else must exist |
|---|---|---|---|
| `PaymentController::getProductStatus` (`:2138`) | `ANY {slug}/get-payment-status`, `routes/customer.php:186`, middleware `[web, ActiveTheme, themelanguage]` only | nothing provider-specific for most of the 41. The three provider-specific pre-writer blocks are PhonePe (`:2159-2183`, needs `requests_data['order_id']` and an outbound `checkStatus`), toyyibpay (`:2184-2193`, branches on `status_id`) and Tap (`:2195-2208`, needs `tap_id` and an outbound `getCharge`) | a session holding `request_data`. **No public callback URL is needed to drive the writer**; one *is* needed to prove the provider arrives here |
| `PaiementProPaymentController::getProductStatus` (`:225`) | `ANY {slug}/get-payment-paiementpro`, `:195` | nothing. `if ($request->responsecode == 0)` is commented out at `:227`, so no field is checked | a session holding `request_data` |
| `CinetPayController::getProductStatus` (`:368`) | `ANY {slug}/get-payment-cinetPay`, `:197` | `responsecode=0`. `cpm_trans_id` is read only for the replay claim (`GuardsGatewayReturn.php:415-416`) | a session holding `request_data`. The initiation at `PaymentService.php:4122-4123` appends `?_token=` + the CSRF token to both the notify and return URLs |
| `EasebuzzController::getProductStatus` (`:229`) | `ANY {slug}/get-payment-easebuzz`, `:200`. **CSRF-exempt** by the `'*get-payment-easebuzz*'` pattern at `bootstrap/app.php:358` | `status=success` | a session holding **`easebuzz_request_data`**, not `request_data`. The initiation still appends `&request_data=` + a urlencoded JSON of the whole request to the callback URL (`PaymentService.php:4421`); that parameter is no longer read, but it is still on the wire |
| `KhaltiPaymnetController::getOrderPaymentStatus` (`:210`) | `POST {slug}/order-khalti`, `:190`. The only POST-only one | `payload[token]` and `payload[amount]`. The order write is nested inside `verifyPayment(...)['status_code'] == '200'` (`:670-672`, order row from `:675`) | a session holding `request_data`, **and a live Khalti verify call**. `app/Khalti/Khalti.php:26` is raw `curl_init()` and the controller constructs `new Khalti()` inline at `:670`, so there is **no seam and no `Http::fake()`** |
| `AuthorizeNetPaymnetController::getOrderPaymentStatus` (`:228`) | `ANY {slug}/authorizenet-status`, `:191` | **card number, expiry (`YYYY-MM`) and CVV**, because this is the charge, not a return | a session holding `request_data`, the store's `authorizenet_login_id` and `authorizenet_transaction_key`, **and a reachable Authorize.Net sandbox**. The SDK controller is constructed inline at `:461`, so there is no seam |
| `NMIPayController::getNMIProductStatus` (`:222`) | `ANY {slug}/get-payment-NMI`, `:360` | `nmi_card_number`, `nmi_expiration_date` (`YYYY-MM`), `nmi_cvv_code`, `nmi_cardholder_name` | a session holding `request_data` and the store's `nmi_api_private_key`. **A double bound for `NMIService` removes the provider entirely for the writer** |
| `PaymentController::handlePaymeraCallback` (`:522`) | `GET /store/paymera/callback/{orderId}`, `routes/web.php:374`, **anonymous** | the `paymera_<uniqid>` reference in the path, plus whatever Paymera posts. The reference is the only identifier | a `paymera_pending_<reference>` cache entry, a status poll answer (fakeable via `Http::fake()`), **a callback signature scheme (GATE G(4))** and **a captured-amount field name (GATE G(4))** |
| `PaymentController::handlePaymeraTrigger` (`:976`) | `POST /store/paymera/trigger/{orderId}`, `routes/web.php:514`, **anonymous and CSRF-exempt** | as above. Treated as a machine caller: `orderWrittenBy($write, false)` (`:953`) restores the session so the caller does not inherit the buyer's binding | as above |
| `PayUPaymentController::storeGetPayUStatus` (`:190`) | `ANY /store-payu-payment/status`, `:363` | `data` = `Crypt::encrypt(['status' => 'true'|'false', 'order_id' => $orderID])`, minted exactly as `PaymentService.php:4495-4496` does, plus a session entry under the literal key `"'" . $orderID . "'"` carrying `slug` | nothing else. This handler writes no order |

**Signatures and webhook secrets, summarised.** Three different things get called "verification" here
and they must be kept apart in a procurement request.

- **An inbound signature or HMAC on the return itself.** Exactly **one** storefront path has one, and
  it is Paymera's (`PaymentController.php:839-851`). It is unconfigured, so it rejects.
- **An outbound confirmation call from the handler.** Khalti makes one
  (`KhaltiPaymnetController.php:671`, nested order write) and NMI makes the charge itself and records
  the answer (`NMIPayController.php:347`). On the generic writer, three of the 41 do it: PhonePe,
  toyyibpay and Tap (`PaymentController.php:2159-2208`). Class-A controllers each make their own
  before redirecting.
- **Nothing at all.** cinetpay, easebuzz and paiementpro.
  `P01-SECURITY-WORK-QUEUE.md` S32 records all three as "gateway verification: **no**", and
  `GuardsGatewayReturn.php:398-404` records that those three plus the generic return supply no
  transaction reference either.

So for the third group, obtaining a sandbox account does not by itself make the return verifiable:
the integration has nowhere to put the answer. `cinet_pay_secret_key` is already being collected from
merchants and is exactly the kind of value such a check would need (§4 row 38). Adding one is a code
change and a larger one than criterion 4.

---

## 6. Independently testable work that needs no provider

Short and concrete. None of these items requires a credential, a sandbox, a public hostname or an
outbound connection.

1. **PayU's `data` fixture.** Mint `Crypt::encrypt(['status' => 'true', 'order_id' => $id])` the way
   `PaymentService.php:4495` does and seed the `"'<orderID>'"` session entry. That turns the recorded
   `DecryptException` into a driven redirect and closes `storeGetPayUStatus` end to end.
2. **AuthorizeNet's SDK seam.** Extract `new AnetController\CreateTransactionController(...)` +
   `executeWithApiResponse(...)` (`AuthorizeNetPaymnetController.php:461-469`) into an injected
   service, exactly as `NMIService::chargeSale()` already is. Behaviour-preserving (same SDK, same
   environment constants, same parse), and it makes the whole order writer underneath reachable. The
   `authorizenet_mode == 'live'` branch must keep choosing `PRODUCTION`, and the seam must not
   introduce a default that makes an unconfigured store charge live.
3. **Khalti's verify seam.** Same shape: `app/Khalti/Khalti.php` is constructed inline with `new
   Khalti()` (`KhaltiPaymnetController.php:670`). Either inject it or route the call through an
   `Http::` client so `Http::fake()` reaches it. This is the single change that moves khalti from
   "cannot be driven" to "drivable". Khalti is also the one producer the P1-ORD-13 census found with no
   order binding (ledger note N6), so it is the handler on this path most recently changed.
4. **A paiementpro `responsecode` regression fence.** `PaiementProPaymentController.php:227` has its
   only gateway check commented out. Whatever the right behaviour is, a test should pin the current one
   so that re-enabling it is a visible decision rather than a silent one.
5. **A cinetpay dead-key finding, recorded as a test.** `cinet_pay_secret_key` is a required settings
   field that no CinetPay call reads. A census test over `app/` asserting that every
   `required_fields` entry in `getPaymentGateways()` is read somewhere would catch this class, and
   would have caught two more of the same class. `PaymentWallPaymentController.php:40` reads the secret
   as `$payment_setting['paymentwall_private_key ']` (trailing space) with the value taken from
   `'paymentwall_private_key  '` (two trailing spaces), so `$this->secret_key` is always `''`. And
   `paiementpro_mode` is offered by the settings screen (`PaymentSettingController.php:365`) and read
   by nothing: `grep -rn 'paiementpro_mode' app/` returns that one line.
6. **A per-handler `Route::any` and CSRF inventory, asserted.** Five of the six storefront
   gateway-controller routes are `Route::any`, so they answer GET; only `order-khalti` is POST-only,
   and easebuzz is CSRF-exempt even on POST
   (`bootstrap/app.php:358`). An inventory test that fails when a new gateway route is declared without
   an explicit method and CSRF decision costs nothing and needs no provider.
7. **A `PayUPaymentController::getProductStatus` disposition.** It carries the unfixed
   `$requests_data = $request->all()` fallback at `:214-218` and is **dead code**: no route declares it
   (`grep` over `routes/` returns `storeGetPayUStatus` and `pay` only) and no caller invokes it. S32
   already records this. Either delete it or guard it; a routed copy of that shape is the exact defect
   `storedCheckoutState()` exists to close.
8. **The generic writer's provider-specific blocks.** PhonePe, toyyibpay and Tap
   (`PaymentController.php:2159-2208`) each return early with the state intact. The toyyibpay limb is
   pure request-field branching and needs nothing; PhonePe and Tap make outbound calls that are
   reachable through their libraries. Each pending-return-then-complete sequence should be pinned,
   because the claim placement at `:2247` was already got wrong once for exactly this reason
   (`GuardsGatewayReturn.php:246-266`).
9. **Engine-level proof of the replay claim.** `CACHE_STORE=array` in `phpunit.xml` cannot prove
   insert-if-absent under concurrency. `tests/Support/Security/bin/verify-gateway-return-claim-on-mysql.php`
   already exists; keep it in the MySQL runner and cite it rather than the SQLite suite whenever the
   claim is discussed.
10. **The fail-closed inventory as a test.** The twelve controls in §2.1 should be asserted as a set,
    the way `EnforcedPaymentTypesIntegrityTest` already asserts `ENFORCED_PAYMENT_TYPES`, so that a
    future gateway-test harness that removes one fails loudly. In particular:
    `require_callback_signature` defaulting to `true`, the `MAX_SANE_LINE_QUANTITY` value, and the five
    distinct refusal strings.

**Two harness facts any future gateway test infrastructure must be built around**, both recorded from
real debugging sessions rather than theory:

- **`Illuminate\Routing\Route::getController()` caches its controller instance for the whole test.** A
  later `$this->app->instance(NMIService::class, $other)` rebinds the container and changes nothing the
  handler sees. Two different gateway answers in one test must be queued on one double
  (`tests/Feature/Commerce/UnguardedOrderWriterTrustBoundaryTest.php:250-298`).
- **The generic writer inserts a `cities` row from `billing_info`.** Without `countries`/`states`/`cities`
  parents the handler raises a foreign-key violation and 500s **before** the order write, so a defect
  sits behind a crash and a test passes for the wrong reason
  (`P01-SECURITY-WORK-QUEUE.md` S32 item 3).

---

## 7. What the owner must obtain externally, and what proceeds now

### 7.1 Must be obtained externally

| # | item | from | blocks | note |
|---|---|---|---|---|
| **E1** | Paymera callback **signature field name, algorithm, and secret delivery**; the response field carrying the **captured amount**; the **expected amount unit** | Paymera, via the owner | the only Paymera success path, and therefore the only *enforced* payment type in the platform | already GATE 20 / GATE G(4) and GATE 14 (`07-OWNER-APPROVAL-GATES.md:112,118,640-669`). **One message to one provider.** Not a sandbox account |
| **E2** | Authorize.Net **sandbox** `authorizenet_login_id` and `authorizenet_transaction_key` | Authorize.Net developer sandbox. **NOT VERIFIED** that a sandbox exists without a live merchant account | the real-provider half of handler #32 | the application already defaults to `ANetEnvironment::SANDBOX`. Item 2 in §6 removes the dependency for the **writer** |
| **E3** | Khalti test `khalti_public_key` / `khalti_secret_key`, and a test verify host | Khalti. **NOT VERIFIED** that a sandbox exists, or that a Nepali business registration is a prerequisite | the real-provider half of handler #31 | the code hard-codes the live verify URL; item 3 in §6 removes the dependency for the **writer** |
| **E4** | CinetPay, Easebuzz, PaiementPro test credentials (keys in rows #38, #43, #36) | each provider. **NOT VERIFIED** for all three | nothing in the suite today: all three are already drivable to `new Order()` | obtaining them does **not** make these returns verified. None of the three has a signature check to feed |
| **E5** | **A public callback hostname** reachable by each provider's sandbox | hosting / infrastructure, not a provider | every "does the provider actually come back here" test, for all classes | `PaymentService.php:463-464` hard-codes `https://storesyria.com` as the Paymera callback base, so a staging host also needs that value made configurable |
| **E6** | A decision on `paytr` | owner | an end-to-end paytr test | `test_mode` is hard-coded to `0` (`PaymentService.php:1914`). There is no sandbox path to drive. Adding one is a change to a live-money hash |
| **E7** | Per-provider sandbox availability answers for every provider not named in E1 to E4 or E6 | provider documentation | the procurement plan's sequencing | one documentation read per provider, recorded against the table in §4. **NOT VERIFIED here by design** |

### 7.2 Proceeds now, with no provider

Items 1 to 10 of §6. In priority order by what each unblocks:

1. §6 item 3 (**Khalti seam**) and §6 item 2 (**AuthorizeNet seam**). These two alone move the
   drivable-writer count from 8 of 11 to **10 of 11**, leaving only the Paymera success path, which is
   an owner gate rather than a provider.
2. §6 item 1 (**PayU fixture**). Closes the last recorded fixture gap.
3. §6 item 10 (**fail-closed inventory test**). This is what makes "preserve fail-closed payment
   behavior" enforceable rather than aspirational once a sandbox harness starts landing.
4. §6 items 4, 5, 6, 7. Findings this analysis surfaced, each small and each independent.
5. §6 items 8, 9. Coverage of the generic writer's remaining branches and the engine-level claim
   proof.

---

## 8. What is NOT VERIFIED

Stated so none of it is mistaken for established:

- **Whether any provider offers a sandbox, and on what terms.** Not one provider's documentation was
  read. The §4 column is "what this application does", not "what the provider offers".
- **Whether `PAYMERA_USERNAME` is the right credential for Paymera's status API**, as opposed to only
  for the create call. `PaymentController.php:413-418` already records this as unverified.
- **Which host is correct for Paymera in production.** `egate-t.paymera.cc` is read as a test host from
  its own name; `config/services.php:269-276` records that the correct production host is Paymera's to
  say.
- **That any of the §3 "yes" rows still behaves as measured on the current tree.** Those measurements
  come from `PartialPaymentDepositSourceTest` and `GatewayReturnTrustBoundaryTest` as those files
  record them. This session ran nothing. Re-running `php artisan test --testsuite=Feature` against the
  current tree would confirm them.
- **That the 52/52/52 agreement in §1 means no payment type is missing.** It means the three in-repo
  lists agree with each other. A provider that exists in `packages/workdo/` and never reaches
  `PaymentService::process()` would not appear in any of them, and no module was loaded to check
  (`CLAUDE.md`: no WorkDo module loads in any test run without an `add_on_managers` row).
- **The production topology for the callback URLs.** `PaymentService.php:463-464` hard-codes
  `https://storesyria.com`; whether a staging deployment is reachable from each provider's sandbox is
  an infrastructure fact this repository does not contain.
