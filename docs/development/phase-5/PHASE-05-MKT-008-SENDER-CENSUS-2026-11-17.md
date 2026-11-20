# GAP-200 / MKT-008 — the message-sender census, 2026-11-17

**91 agents, 0 errors.** Six partitions enumerated every send site in `app/` and all ~70
vendored modules; each classified site was then challenged by an adversarial pass; five
dedicated analyses answered whether a safe first-party interception seam exists.

The decisions this feeds are in
[`PHASE-05-OWNER-DECISION-PACKAGE.md`](PHASE-05-OWNER-DECISION-PACKAGE.md) §3. This file is the
evidence behind them.

## Scope, stated honestly

| send sites found | **214** |
|---|---|
| classified in depth | **40** (a cap; the run would otherwise have been several times the size) |
| listed but NOT classified | **174** — enumerated in §3 below, not analysed |
| classifications the adversary refuted | **10 of 40** |

**The classified 40 were taken in census order, not chosen for severity.** The unclassified
remainder is therefore not "the safe ones", and no category, consent or severity claim is made
about any of them.

## 1. The classified forty

Categories are the measuring pass's; where the adversarial pass refuted one, the refutation is
in §2 and **the refutation is what should be believed**.

| id | category | consent | severity | nearest first-party seam |
|---|---|---|---|---|
| `apicontroller-place-order-mail` | MIXED | NONE | MEDIUM | Illuminate\Mail\Events\MessageSending, with a listener registered in app/Providers/EventServiceProvider.php $l |
| `apicontroller-place-order-whatsapp` | MIXED | NONE | MEDIUM | `Utility::SendMsgs()` — app/Models/Utility.php:2648, specifically the existing conditional at :2664, immediate |
| `authorizenet-sends` | MIXED | NONE | HIGH | TWO first-party seams exist; the mail one is MEASURED. (1) MEASURED — Laravel's MessageSending. I booted this  |
| `paymentcontroller-getproductstatus-mail` | MIXED | NONE | MEDIUM | BEST SEAM — App\Models\Utility::sendEmailTemplate(), app/Models/Utility.php:2515. First-party (app/), one file |
| `paymentcontroller-getproductstatus-whatsapp` | MIXED | NONE | HIGH | app/Models/Utility.php:2648 — `Utility::SendMsgs($template, $mobile_no, $msg)`, the method body itself, immedi |
| `paymentcontroller-whatsapp-method-mail` | MIXED | NONE | HIGH | A first-party refusal seam EXISTS, and there are two, neither requiring any edit under packages/workdo/. NEARE |
| `paymentcontroller-whatsapp-method-whatsapp` | MIXED | NONE | HIGH | App\Models\Utility::SendMsgs() - app/Models/Utility.php:2648 - with the refusal placed immediately before the  |
| `paymentservice-stock-status-whatsapp` | MIXED | NONE | MEDIUM | `App\Models\Utility::SendMsgs()` — app/Models/Utility.php:2648 — is the nearest seam, and it is first-party (a |
| `safewebhook-send` | MIXED | NONE | LOW | `App\Models\Utility::WebhookCall()` — app/Models/Utility.php:2207 — for the webhook half, and `App\Services\Ot |
| `utility-ios-push` | MIXED | NONE | LOW | app/Models/Utility.php:3638 — the method body itself, at the existing early-return guard on 3640-3642. This is |
| `utility-sendemailtemplate-order` | MIXED | NONE | MEDIUM | `Illuminate\Mail\Events\MessageSending`, via a listener registered in the first-party app/Providers/EventServi |
| `utility-sendmsgs-whatsapp` | MIXED | NONE | HIGH | app/Models/Utility.php:2648 — the body of Utility::SendMsgs() itself, entered before the cURL at :2681-2690. T |
| `apicontroller-ordersave-stockalert-twilio` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | app/Models/Utility.php:3460 — Utility::send_twilio_msg($to, $msg, $settings). This is a verified single choke  |
| `apicontroller-place-order-stockalert-twilio` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | `App\Models\Utility::send_twilio_msg($to, $msg, $settings)` — app/Models/Utility.php:3460-3477. This is the so |
| `deliveryboy-notification-order-assigned` | OPERATIONAL_INTERNAL | NONE | LOW | app/Services/DeliveryBoyNotificationService.php:55-70 — specifically the DeliveryBoyDevice query at :57-62 ins |
| `firebase-sendtotoken-delivery` | OPERATIONAL_INTERNAL | NONE | LOW | The send boundary is itself first-party — no vendored file appears anywhere in the chain (app/Http/Controllers |
| `firebase-sendtotoken-store` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | The nearest seam needs no indirection at all, because NOTHING on this path is vendored: both files live under  |
| `paymentcontroller-getproductstatus-stockalert-twilio` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | App\Models\Utility::send_twilio_msg() — app/models/Utility.php:3460 (declaration), with the refusal placed bef |
| `paymentcontroller-telegram-order-message` | OPERATIONAL_INTERNAL | NONE | LOW | app/Http/Controllers/PaymentController.php:4618 — the private method `sendTelegramOrderMessage()` itself, with |
| `paymentcontroller-whatsapp-method-stockalert-twilio` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | App\Models\Utility::send_twilio_msg() — app/Models/Utility.php:3460 (guard before the Twilio client is built a |
| `paymentservice-order-created-mail-owner` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | NEAREST FIRST-PARTY SEAM: app/Models/Utility.php:2515 — `Utility::sendEmailTemplate()` itself. app/Models/Util |
| `paymentservice-stockalert-twilio` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | app/Models/Utility.php:3460 — `Utility::send_twilio_msg($to, $msg, $settings)`. It is the sole, exclusive funn |
| `store-notification-capture-without-order` | OPERATIONAL_INTERNAL | NONE | LOW | NOTHING ON THIS PATH IS VENDORED, so no packages/workdo/ file need be touched for any of the options below — t |
| `store-notification-order-created` | OPERATIONAL_INTERNAL | NONE | LOW | A first-party container decorator on `App\Services\StoreNotificationService`, registered in `app/Providers/App |
| `store-notification-order-paid` | OPERATIONAL_INTERNAL | NONE | LOW | The whole sender is first-party, so no container trickery is needed: the nearest complete seam is a guard at t |
| `store-notification-push` | OPERATIONAL_INTERNAL | NONE | LOW | The service-container binding, in app/Providers/AppServiceProvider::register() -- specifically a decorator bou |
| `utility-paymentwebhook` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | `Utility::paymentWebhook()` — app/Models/Utility.php:2257. This is the nearest seam and the correct one. It is |
| `utility-send-twilio-msg` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | app/Models/Utility.php:3460 - inside `Utility::send_twilio_msg()`, immediately before `new Client($account_sid |
| `utility-stock-alert-twilio-helpers` | OPERATIONAL_INTERNAL | SERVER_SIDE | LOW | app/Models/Utility.php:3460 — `Utility::send_twilio_msg($to, $msg, $settings)`. First-party (app/Models/, not  |
| `utility-webhookcall` | OPERATIONAL_INTERNAL | NONE | MEDIUM | `App\Models\Utility::webhook()` at app/Models/Utility.php:2190 (return false to suppress), with `App\Models\Ut |
| `abandoned-message-dispatch` | PROMOTIONAL | NONE | MEDIUM | app/Services/AbandonedMessageDispatch.php — the class IS the seam, and it is first-party (app/Services/, not p |
| `apicontroller-ordersave-mail` | PROMOTIONAL | NONE | MEDIUM | app/Models/Utility.php:2515 — `App\Models\Utility::sendEmailTemplate($emailTemplate, $mailTo, $obj, $owner, $s |
| `apicontroller-ordersave-whatsapp` | PROMOTIONAL | NONE | MEDIUM | `App\Models\Utility::SendMsgs()` — app/Models/Utility.php:2648. This is the single first-party chokepoint for  |
| `paymentservice-stock-status-mail` | PROMOTIONAL | NONE | HIGH | app/Models/Utility.php:2515 — App\Models\Utility::sendEmailTemplate($emailTemplate, $mailTo, $obj, $owner, $st |
| `utility-sendemailtemplate-noorder` | PROMOTIONAL | NONE | MEDIUM | A first-party `Illuminate\Mail\Events\MessageSending` listener registered in app/Providers/EventServiceProvide |
| `utility-sendfcm` | PROMOTIONAL | NONE | LOW | app/Models/Utility.php:3576-3580 — the head of the first-party static Utility::sendFCM(), beside its existing  |
| `otptransport-post` | TRANSACTIONAL | NONE | LOW | `App\Services\OtpTransport::post()` — app/Services/OtpTransport.php:114-124 — is the nearest and only real sea |
| `paymentcontroller-password-reset-on-checkout-register` | TRANSACTIONAL | NONE | LOW | TODAY: none, and the distinction matters. Nothing is ever handed to the Mailer, so no mail-layer seam fires fo |
| `paymentservice-order-created-mail-customer` | TRANSACTIONAL | NONE | MEDIUM | PRIMARY (nearest, narrowest, first-party): `Utility::sendEmailTemplate()` at app/Models/Utility.php:2515 — a g |
| `paymentservice-order-created-whatsapp` | TRANSACTIONAL | NONE | LOW | App\Models\Utility::SendMsgs(), app/Models/Utility.php:2648 — a refusal placed at the top of that method, befo |

## 2. What the adversarial pass refuted

Ten of forty. These corrections are load-bearing: the first pass **over-reported promotional
sending**, and one interception claim was simply false.

### `utility-sendemailtemplate-noorder` — claimed PROMOTIONAL / NONE / MEDIUM

REFUTED on basis and scope; the interception column survives intact. == (c) INTERCEPTION — HOLDS. Verified end to end; not the weak link. == Chain confirmed by reading every hop: PendingMail::send -> `$this->mailer->send($mailable)` (vendor/laravel/framework/src/Illuminate/Mail/PendingMail.php:122-125); Mailer::send -> `if ($view instanceof MailableContract) return $this->sendMailable($view)` (Mailer.php:298-302); sendMailable -> `$mailable instanceof ShouldQueue ? queue : ->send($this)` (Mailer.php:350-355) and CommonEmailTemplate imports ShouldQueue at app/Mail/CommonEmailTemplate.php:8 and does NOT implement it (:11-12), so the sync arm — the claim is right; Mailable::send -> `$mailer->send($this->buildView(), $this->buildViewData(), fn)` (Mailable.php:198-217) -> non-Mailable arm -> Mailer.php:331 `if ($this->shouldSendMessage(...))` -> Mailer.php:599-607 `if (! $this->events) return true; return $this->events->until(new MessageSending(...)) !== false;`. `$this->events` is always injected: MailManager::build passes `$this->app['events']` (MailManager.php:142-148) and resolve() routes through build() (MailManager.php:112-133). No custom mailer/transport exists — grep for `Mail::extend`/`bind('mailer'`/`singleton('mailer'`/TransportInterface across app/, bootstrap/, config/ and package providers returns only bootstrap/cache/services.php:270 (stock MailServiceProvider). `Messa

### `utility-sendfcm` — claimed PROMOTIONAL / NONE / LOW

REFUTED on (a) and (b); (c) is mechanically correct but MOOT, plus one wrong line number. === (a) CATEGORY IS WRONG — this is not PROMOTIONAL === The template body is `'now ' . $product->name . ' is available in stock'` (app/Http/Controllers/ProductController.php:2969; packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionProductController.php:1473). It is a BACK-IN-STOCK alert: no offer, no discount, no price, no campaign content. It is sent only to holders of a `notify_users` row for that exact product (ProductController.php:2963) — a recipient-initiated, per-product subscription. The repo names it this itself: tests/Feature/Tenancy/EnforcedModelIsolationTest.php:191 => `'back-in-stock notifications'`, and app/Http/Controllers/Api/ApiController.php:8591-8592 => "the shopper journey it looks like ('notify me when this is back in stock')". By the brief's own rule ("a reminder that carries an offer is promotional"), this carries none. One-shot semantics confirm it: the subscription row is DELETED immediately after sending (ProductController.php:2971; AuctionProductController.php:1475), so it fires exactly once per opt-in and cannot recur — the delete IS the unsubscribe. === (b) consent=NONE IS WRONG IN BOTH DIRECTIONS === DIRECTION 1 — a real consent check exists and was missed. Three stacked server-side pre-send gates, none caller-supplied, none post-send, none Blade-o

### `utility-ios-push` — claimed MIXED / NONE / LOW

REFUTED on ground (a): the category is wrong. `Utility::ios_send_push_notification` is DEAD CODE with ZERO callers, not a live MIXED sender. **(a) CATEGORY — WRONG. This is the refutation.** The task directs "Read the actual template body." There is no template body. `$message` is a caller-supplied parameter passed through verbatim (`app/Models/Utility.php:3650` `$body = $message;`, re-used at :3659-3661). The only hardcoded content is the titles — `$title = "Fashion App";` (:3648) and `'alert' => array('title' => 'Seek Into Bible App', ...)` (:3661). Two unrelated third-party app names in one method is positive evidence of copy-pasted vendor sample code, not a Store Syria sender. And there is nothing to classify, because nothing calls it. `grep -rn "ios_send_push_notification"` over `app/ packages/ routes/ config/ resources/ database/ tests/ bootstrap/ public/ docs/` returns exactly one line — the definition at `app/Models/Utility.php:3638`. A separate bounded grep over `vendor/` returns zero. No dynamic dispatch either: `Utility::$` and `call_user_func` both return zero hits in `app/`, `packages/`, `routes/`. Total occurrences in the repository: one, and it is the signature. `MIXED` asserts the sender currently carries both transactional and promotional traffic. It carries none. For an MKT-008 consent-and-unsubscribe inventory this is not a quibble: it books a remediation obl

### `safewebhook-send` — claimed MIXED / NONE / LOW

REFUTED on vector (a) only — the CATEGORY is wrong. The consent claim is literally accurate but misleading in combination, and the interception claim SURVIVES everything I threw at it. === (a) CATEGORY=MIXED IS UNSUPPORTED — no promotional content can reach this sender === The entry asserts MIXED and cites no payload, no template and no recipient for that call. I read both halves: WEBHOOK HALF — there is no message at all, and the recipient is not a consumer. Every one of the 8 sites builds the payload as a bare `json_encode($model)` and POSTs it to the URL the MERCHANT entered in their own `webhooks` row: app/Models/Utility.php:2288 (`json_encode($order)`), app/Http/Controllers/ProductController.php:951 and :1253 (`json_encode($Product)` / `$product`), app/Http/Controllers/UserController.php:193 (`json_encode($user)`), app/Http/Controllers/OrderController.php:2089 (`json_encode($order)`), app/Http/Controllers/PosController.php:604 (`json_encode($sales)`), packages/workdo/Marketing/src/app/Http/Controllers/AppointmentController.php:137, packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionOrdersController.php:232. No subject, no body copy, no template, no offer — and the destination is a machine endpoint belonging to the merchant, not a customer inbox or phone. The modules that can fire are a fixed operational set: app/Models/Webhook.php:23-28 offers exactly `New User

### `utility-paymentwebhook` — claimed OPERATIONAL_INTERNAL / SERVER_SIDE / LOW

REFUTED on ground (c) only. category=OPERATIONAL_INTERNAL, consent=SERVER_SIDE and severity=LOW all survive; the interception claim does not. === (c) THE INTERCEPTION CLAIM IS FALSE — THIS SENDER HAS TWO LIVE ENTRY POINTS, NOT ONE === Claimed: "`Utility::paymentWebhook()` — app/Models/Utility.php:2257 ... It is the sole entry point for this sender ... a refusal predicate added there stops exactly this sender and nothing else." `app/Http/Controllers/PosController.php:600-611` fires the IDENTICAL `New Order` webhook inline and never calls `paymentWebhook()`: :601 `$module = 'New Order';` :602 `$webhook = Utility::webhook($module, $store->id);` :604 `$parameter = json_encode($sales);` :607 `$status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);` This is `PosController::store()` (declared app/Http/Controllers/PosController.php:293), routed live at routes/web.php:1417 (`POST pos/data/store`, name `pos.data.store`) and again by routes/web.php:1408 (`Route::resource('pos', PosController::class)`). It is first-party, not vendored. Exhaustive: `grep -rn "'New Order'" app/ packages/` returns exactly three hits — app/Models/Utility.php:2259 (inside `paymentWebhook`), app/Models/Webhook.php:26 (the module enum), and app/Http/Controllers/PosController.php:601 (the bypass). No `packages/workdo/` module fires it. So a refusal predicate inside `paymentWebhook()` leave

### `paymentservice-stock-status-whatsapp` — claimed MIXED / NONE / MEDIUM

REFUTED on category and severity. The interception analysis is sound for this sender but carries two false generalisations. == (a) CATEGORY = MIXED IS WRONG. This is the material refutation. == There is no stored template body to read. `whatsapp_messages` (database/migrations/2024_01_31_113056_create_whatsapp_messages_table.php:14-22) has exactly: id, name, from, user_id, is_active, store_id, created_by — NO body/content column. `Utility::SendMsgs()` uses the row only for the on/off toggle (Utility.php:2661, 2664) and ships `$msg` verbatim (Utility.php:2676-2678). So the actual body is the hardcoded literal at PaymentService.php:2866 and :2924, identical at both sites: "Dear,{firstname} .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :{product->name}. " No offer, no coupon, no discount, no link, no second product, no call to action. By the check's own test ("a reminder that carries an offer is promotional") this fails the promotional test outright. Contrast the senders the measurement doc does call VIOLATION — packages/workdo/CouponEmail/src/app/Listeners/CreateCustomerLis.php:20-37 — which carry a coupon. Trigger and recipient are both transactional: - Recipient is `$request['billing_info']['billing_user_telephone']` (PaymentService.php:2864, :2922) — the number the buyer typed into THIS checkout request seconds ea

### `paymentcontroller-whatsapp-method-mail` — claimed MIXED / NONE / HIGH

REFUTED on vector (c) only — the interception claim. The classification triple (category=MIXED, consent=NONE, severity=HIGH) survives both other attacks and is CONFIRMED. (a) CATEGORY — NOT refuted; confirmed from the actual template bodies in app/Models/Utility.php::defaultEmail(): - 'Order Created' (Utility.php:630, en body :636): "Thank you for Shopping / We received your purchase request" — order confirmation, no offer. Transactional. - 'Order Created For Owner' (Utility.php:674, en body :680): "This is Confirmation Order {order_id} place on {order_date}" — merchant-facing. Transactional. - 'Stock Status' (Utility.php:696, en body :702; Arabic twin :699): "We are excited to inform you that the product you have been waiting for is now back in stock. Do not miss this opportunity to get your hands on it!" — explicit purchase inducement. Promotional under the task's own rule. MIXED is right. (b) CONSENT — NOT refuted in either direction. Three gates exist and all three genuinely refuse (not caller-supplied, not post-send, not Blade-only), but none is recipient consent: - $Product->stock_order_status == 'notify_customer' (PaymentController.php:5199, 5249) — merchant product setting. - UserEmailTemplate ... $is_actives->is_active == 1 (Utility.php:2523-2526), keyed user_id = $owner->id — merchant template toggle. - $whatstemplate->is_active == 1 (Utility.php:2664), keyed store_id

### `paymentcontroller-whatsapp-method-whatsapp` — claimed MIXED / NONE / HIGH

REFUTED on three grounds. The interception MECHANICS survive; the category, the caller census, and the proposed narrower seam do not. === (a) CATEGORY IS WRONG — both cited legs are TRANSACTIONAL, not MIXED === Order Created, app/Http/Controllers/PaymentController.php:5766 — "Hello, Welcome to $store->name .Hi,your order id is $order->product_order_id, Thank you for Shopping We received your purchase request, we'll be in touch shortly!." Pure order acknowledgement. Undisputed. Stock Status, PaymentController.php:5211 and :5269 — "Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name." Read against the three tests the task sets: - NO offer, NO discount, NO link, NO call to action. - Trigger is `$Product->stock_order_status == 'notify_customer'` (:5189, :5247). The product editor labels that radio "Allow, But notify customer" — resources/views/product/create.blade.php:269-272, edit.blade.php:311-313. It is the BACKORDER setting, not a marketing flag. - Recipient is `$request['billing_info']['billing_user_telephone']` (:5209, :5267) — the buyer's own checkout input, in the same request. No list, no audience selection, no campaign. The promotional language the classification is presumably reacting to is real, but it is in a DIFFERENT SENDER. app/Models/Utility.php:696, 'Stock Status' en: ".

### `paymentcontroller-whatsapp-method-stockalert-twilio` — claimed OPERATIONAL_INTERNAL / SERVER_SIDE / LOW

PARTIAL REFUTATION. The three classification fields all survive; the interception record contains one false, load-bearing scope claim that would make the MKT-008 remediation plan wishful in exactly the way attack (c) warns about. CONFIRMED — category=OPERATIONAL_INTERNAL. Read all four helper bodies verbatim: app/Models/Utility.php:3479-3492 (low_stock_threshold), 3494-3508 (out_of_stock), 3510-3524 (variant_out_of_stock), 3700-3712 (variant_low_stock_threshold). Every body is a bare stock alert -- "Low Stock Alert: The stock of {name} is below the specified threshold. Current Stock: N, Low Stock Threshold: M" / "Out of Stock Alert: ...". No offer, discount, link, call to action or promotional framing. All four send to $settings['twilio_notification_number'] (Utility.php:3488, 3504, 3520, 3708), a merchant-configured store setting written at app/Http/Controllers/SettingController.php:1671 with validation 'required|numeric' and scoped to getCurrentStore(). No customer identifier reaches this transport. CONFIRMED — consent=SERVER_SIDE, and the check can genuinely refuse. $settings originates at app/Http/Controllers/PaymentController.php:5063 as Setting::where('store_id', $store->id)->pluck('value','name')->toArray(), inside whatsapp(Request $request, $slug) (PaymentController.php:4695) with $store = getStore($slug) resolved from the route slug (route-wins precedence documented in

### `apicontroller-place-order-whatsapp` — claimed MIXED / NONE / MEDIUM

REFUTED on (a); (b) is true-but-not-load-bearing; (c)'s seam reasoning is correct but its call-site inventory is wrong by more than half and omits two vendored callers. (a) CATEGORY=MIXED IS NOT SUPPORTED — this is the primary refutation. I read both bodies in full. They are hardcoded in PHP at the call site; the template row is consulted ONLY for `is_active` (Utility.php:2661, 2664), and `whatsapp_messages` has NO body/content column — `name, from, user_id, is_active, store_id, created_by` (database/migrations/2024_01_31_113056_create_whatsapp_messages_table.php; matching WhatsappMessage.php:13-21). So no merchant can turn either into promotional content and the content is fixed and fully known. - Order Created, ApiController.php:4327: "Hello, Welcome to $store->name .Hi,your order id is $order->product_order_id, Thank you for Shopping We received your purchase request, we'll be in touch shortly!." Recipient `$OrderBillingDetail->telephone` (:4328). Unambiguously transactional. - Stock Status, ApiController.php:3857 and :3913 (identical strings): "Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name." Recipient `$request['billing_info']['billing_user_telephone']` (:3855, :3911) — the phone supplied on the order being placed in THIS request. Trigger at :3836 (and the no-variant leg at 

## 3. The 174 enumerated but unclassified

Listed so nothing is lost. No claim is made about any of them.

- `app/Http/Controllers/PayUPaymentController.php:538, 549, 565, 576, 631, 642 (twilio); 596, 663 (Stock Status mail); 607, 674 (Stock Status whatsapp); 993, 994 (Order Created / For Owner mail); 1048 (Order Created whatsapp)`
- `app/Http/Controllers/NMIPayController.php:843, 854, 879, 890, 945, 956 (twilio); 910, 977 (Stock Status mail); 921, 988 (Stock Status whatsapp); 1374, 1375 (Order Created / For Owner mail); 1494 (Order Created whatsapp)`
- `app/Http/Controllers/KhaltiPaymnetController.php:739, 750, 766, 777, 832, 843 (twilio); 797, 864 (Stock Status mail); 808, 875 (Stock Status whatsapp); 1269, 1270 (Order Created / For Owner mail); 1325 (Order Created whatsapp)`
- `app/Http/Controllers/EasebuzzController.php:704, 715, 731, 742, 797, 808 (twilio); 762, 829 (Stock Status mail); 773, 840 (Stock Status whatsapp); 1218, 1219 (Order Created / For Owner mail); 1273 (Order Created whatsapp)`
- `app/Http/Controllers/PaiementProPaymentController.php:787, 798, 814, 825, 880, 891 (twilio); 845, 912 (Stock Status mail); 856, 923 (Stock Status whatsapp); 1250, 1251 (Order Created / For Owner mail); 1305 (Order Created whatsapp)`
- `app/Http/Controllers/CinetPayController.php:814, 822, 833, 841, 886, 894 (twilio); 859, 913 (Stock Status mail); 867, 921 (Stock Status whatsapp); 1234, 1235 (Order Created / For Owner mail); 1290 (Order Created whatsapp)`
- `app/Http/Controllers/OrderController.php:2032`
- `app/Http/Controllers/OrderController.php:2059`
- `app/Http/Controllers/OrderController.php:2066, 2091`
- `app/Http/Controllers/CartController.php:1291`
- `app/Http/Controllers/CartController.php:1404, 1437`
- `app/Http/Controllers/WishlistController.php:205`
- `app/Http/Controllers/WishlistController.php:557, 591`
- `app/Http/Controllers/AccountProfileController.php:1142-1146`
- `app/Http/Controllers/Api/AuthController.php:518`
- `app/Http/Controllers/Api/DashboardController.php:4742`
- `app/Http/Controllers/SettingController.php:712`
- `app/Http/Controllers/SettingController.php:1496 (decl), 1544-1573`
- `app/Models/Customer.php:279-284, 283`
- `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:1053 (decl), 1102-1105`
- `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:126 (decl), 273 (sms), 292 (whatsapp)`
- `app/Http/Controllers/OtpIntegrationController.php:312 (decl), 533 (sms), 599 (whatsapp)`
- `app/Http/Controllers/Auth/PasswordResetLinkController.php:37 (decl), 83-87`
- `app/Http/Controllers/Auth/EmailVerificationNotificationController.php:16 (decl), 36`
- `app/Http/Controllers/Auth/RegisteredUserController.php:46 (decl), 167`
- `app/Http/Controllers/StoreController.php:92 (decl), 412`
- `app/Http/Controllers/ProductController.php:611 (decl of store), 1714 (decl of update), 2960-2973`
- `app/Http/Controllers/ProductController.php:949, 953 and 1251, 1255 (both inside store(), decl 611)`
- `app/Http/Controllers/UserController.php:74 (decl), 187, 196`
- `app/Http/Controllers/PosController.php:293 (decl), 602, 607`
- `app/Services/StoreNotificationService.php:22-70 (orderCreated); shared push 299-367; transport app/Services/FirebaseNotificationService.php:12-72 (HTTP POST at :57-63)`
- `app/Services/StoreNotificationService.php:75-140 (orderPaid); shared push 299-367; transport app/Services/FirebaseNotificationService.php:57-63`
- `app/Services/StoreNotificationService.php:185-297 (captureWithoutOrder); shared push 299-367`
- `app/Http/Controllers/Api/ApiController.php:244-336 (saveStoreFcmToken); route routes/api.php:201-204`
- `app/Services/DeliveryBoyNotificationService.php:19-53 (orderAssigned); push 55-113; transport app/Services/DeliveryFirebaseNotificationService.php:12-72 (HTTP POST at :57-63)`
- `app/Http/Controllers/DeliveryBoyNotificationController.php:17-49 (registerDevice); route routes/api.php:382`
- `app/Http/Controllers/ProductController.php:2959-2976 (inside ProductController::update(), declared at :1714); transport app/Models/Utility.php:3576-3636`
- `app/Models/Utility.php:3638-3696 (ios_send_push_notification)`
- `app/Models/Utility.php:transport SendMsgs 2648-2699 (curl to graph.facebook.com at :2668-2694); 11 call sites listed in trigger`
- `app/Models/Utility.php:transport SendMsgs 2648-2699; 24 call sites listed in trigger`
- `app/Http/Controllers/OrderController.php:2054-2062 (send at :2059), inside order_status_change`
- `app/Http/Controllers/CartController.php:1341-1475 (abandon_carts_messsend); hand-over at :1438-1445 via app/Services/AbandonedMessageDispatch.php:105-108`
- `app/Http/Controllers/WishlistController.php:514-616 (abandonWishlistMsgSend); hand-over at :587-594`
- `app/Http/Controllers/SettingController.php:1496-1585 (testSendwhatsappmassage); curl at :1563-1574`
- `app/Http/Controllers/PaymentController.php:4618-4648 (sendTelegramOrderMessage); called at :4588-4589 from getWhatsappUrl (:4364)`
- `app/Http/Controllers/PaymentController.php:4599 (inside getWhatsappUrl, :4364-4603)`
- `app/Models/Utility.php:transport send_twilio_msg 3460-3477; message builders low_stock_threshold 3479-3492 and variant_low_stock_threshold 3698-3712`
- `app/Models/Utility.php:transport send_twilio_msg 3460-3477; message builders out_of_stock 3494-3508 and variant_out_of_stock 3510-3524`
- `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:126-375 (sendPasswordOtp); SMS branch 191-274, send at :273`
- `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:282-321 (WhatsApp branch of sendPasswordOtp), send at :292-321`
- `app/Http/Controllers/OtpIntegrationController.php:~340-585 (test); SMS send at :533`
- `app/Http/Controllers/OtpIntegrationController.php:~586-660 (WhatsApp branch of test); send at :599-629`
- `app/Models/Utility.php:webhook() 2190-2205, WebhookCall() 2207-2224, paymentWebhook() 2257-2296; transport app/Services/SafeWebhook.php:128-130`
- `app/Services/PaymentService.php:3562-3719 (private webhook()); HTTP POST at :3702-3719; invoked at :3464-3490`
- `app/Package/Payment.php:charge() 73-124; receipt fields at :88-89 and :112-113; defaults at app/Package/Reference.php:63-65`
- `packages/workdo/CouponEmail/src/app/Listeners/CreateCustomerLis.php:34 (transport at app/Models/CouponEmail.php:41-45)`
- `packages/workdo/CouponEmail/src/app/Listeners/CreateOrderLis.php:39 (transport at app/Models/CouponEmail.php:41-45)`
- `packages/workdo/CouponEmail/src/app/Listeners/CreateOrderLis.php:53 (transport at app/Models/CouponEmail.php:41-45)`
- `packages/workdo/CouponEmail/src/app/Listeners/AuthorizeNetPaymentLis.php:36 (transport at app/Models/CouponEmail.php:41-45)`
- `packages/workdo/CouponEmail/src/app/Listeners/AuthorizeNetPaymentLis.php:50 (transport at app/Models/CouponEmail.php:41-45)`
- `packages/workdo/CouponEmail/src/app/Listeners/PaiementProPaymentLis.php:37 (transport at app/Models/CouponEmail.php:41-45)`
- `packages/workdo/CouponEmail/src/app/Listeners/PaiementProPaymentLis.php:50 (transport at app/Models/CouponEmail.php:41-45)`
- `packages/workdo/ReviewReminder/src/app/Http/Controllers/ReviewReminderController.php:265-269 (Mail::to([$mailTo])->send(new CommonEmailTemplate(...)))`
- `packages/workdo/ReviewReminder/src/app/Http/Controllers/ReviewReminderController.php:259-263 (Mail::to([$store['email']])->send(new CommonEmailTemplate(...)))`
- `packages/workdo/Marketing/src/app/Http/Controllers/AppointmentController.php:136-139 (Utility::WebhookCall at :138)`
- `packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionOrdersController.php:207`
- `packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionOrdersController.php:218`
- `packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionOrdersController.php:228-234`
- `packages/workdo/AuctionProduct/src/app/Console/Auction.php:125`
- `packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionProductController.php:1463-1479`
- `packages/workdo/AuctionProduct/src/resources/views/auctionorder/view.blade.php:164, 224`
- `packages/workdo/CouponEmail/src/app/Listeners/CreateCustomerLis.php:34`
- `packages/workdo/CouponEmail/src/app/Listeners/CreateOrderLis.php:31-40`
- `packages/workdo/CouponEmail/src/app/Listeners/CreateOrderLis.php:48-53`
- `packages/workdo/CouponEmail/src/app/Listeners/AuthorizeNetPaymentLis.php:29-37`
- `packages/workdo/CouponEmail/src/app/Listeners/AuthorizeNetPaymentLis.php:45-50`
- `packages/workdo/CouponEmail/src/app/Listeners/PaiementProPaymentLis.php:30-37`
- `packages/workdo/CouponEmail/src/app/Listeners/PaiementProPaymentLis.php:45-50`
- `packages/workdo/EMailBox/src/app/Http/Controllers/EmailBoxController.php:269-281`
- `packages/workdo/EMailBox/src/app/Http/Controllers/EmailBoxController.php:794-807`
- `packages/workdo/Helpdesk/src/app/Http/Controllers/HelpdeskController.php:186`
- `packages/workdo/Helpdesk/src/app/Http/Controllers/HelpdeskConversionController.php:82`
- `packages/workdo/Helpdesk/src/app/Http/Controllers/HelpdeskConversionController.php:157`
- `packages/workdo/Helpdesk/src/app/Http/Controllers/HelpdeskConversionController.php:159`
- `packages/workdo/Helpdesk/src/app/Http/Controllers/HelpdeskConversionController.php:162`
- `packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryOrderController.php:142`
- `packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryOrderController.php:153`
- `packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryResultController.php:110`
- `packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryResultController.php:170`
- `packages/workdo/LotteryPrizes/src/app/Console/Lottery.php:80`
- `packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryPrizesController.php:664-684`
- `packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryPrizesController.php:686`
- `packages/workdo/LotteryPrizes/src/resources/views/admin/lottery-order/view.blade.php:149, 209`
- `packages/workdo/LotteryPrizes/src/app/Jobs/SendLotteryWinEmail.php:42`
- `packages/workdo/LotteryPrizes/src/app/Jobs/SendLotteryFailEmail.php:42`
- `packages/workdo/LotteryPrizes/src/app/Jobs/SendOrderCreatedEmail.php:38`
- `packages/workdo/Donation/src/app/Http/Controllers/CustomerDonationController.php:211-231`
- `packages/workdo/Donation/src/app/Http/Controllers/CustomerDonationController.php:233`
- `packages/workdo/Campaigns/src/resources/views/superAdmin/share_campaigns.blade.php:4-9, 17-23`
- `packages/workdo/LynomiaChat/src/app/Services/PlatformNotifier.php:212`
- `packages/workdo/LynomiaChat/src/app/Services/MerchantContactVerification.php:226`
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/InboxApiController.php:89`
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/CampaignApiController.php:121`
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/CampaignApiController.php:146`
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/AutomationApiController.php:168`
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/AutomationApiController.php:716`
- `packages/workdo/Duo2FA/src/app/Http/Controllers/Duo2FAController.php:104-105, 112-113`
- `packages/workdo/SMS/src/app/Listeners/OrderPaymentStatusLis.php:26, 29, 44, 46`
- `packages/workdo/SMS/src/app/Listeners/AuthorizeNetPaymentLis.php:26, 28, 37, 39`
- `packages/workdo/SMS/src/app/Listeners/PaiementProPaymentLis.php:26, 28, 37, 39`
- `packages/workdo/SMS/src/app/Listeners/GetProductStatusLis.php:26, 28, 37, 39`
- `packages/workdo/PreOrder/src/app/Models/PreOrderHistory.php:84 (call), 92 (dispatcher), 147-151 (Mail::to)`
- `packages/workdo/PreOrder/src/app/Models/PreOrderHistory.php:85 (call), 137-145 (recipient substitution + Mail::to)`
- `packages/workdo/PreOrder/src/app/Http/Controllers/PreOrderController.php:281 (method), 283-284, 324, 348`
- `packages/workdo/PreOrder/src/app/Console/PreOrder.php:17 (signature), 38, 91, 114`
- `packages/workdo/PartialPayments/src/app/Console/Payment.php:18 (signature), 39-44, 67, 80; dispatcher at packages/workdo/PartialPayments/src/app/Http/Controllers/PartialPaymentsController.php:304, 361-371`
- `packages/workdo/PartialPayments/src/app/Http/Controllers/PartialPaymentsController.php:702 (method), 710-713`
- `packages/workdo/PreOrder/src/app/Models/PreOrder.php:25 (method), 33-36`
- `packages/workdo/ReviewReminder/src/app/Listeners/SendOrderReviewMailListner.php:37, 39, 42; dispatcher at packages/workdo/ReviewReminder/src/app/Http/Controllers/ReviewReminderController.php:211, 255-269`
- `packages/workdo/PDFVouchers/src/app/Http/Controllers/MailSettingController.php:102 (method), 121, 124, 156`
- `packages/workdo/SpinToWinReward/src/app/Http/Controllers/SpinToWinRewardController.php:255 (method), 258, 313-315, 322; dispatcher at 412, 465-479`
- `packages/workdo/SpinToWinReward/src/app/Http/Controllers/SpinToWinRewardController.php:544 (method), 553, 567-576, 631-633, 640; dispatcher at 412, 465-479`
- `packages/workdo/ProductEnquiry/src/app/Http/Controllers/ProductEnquiryController.php:88 (method), 89, 112-113, 120; dispatcher at 273, 326-340`
- `packages/workdo/ProductCatelog/src/app/Http/Controllers/ProductCatelogController.php:119 (method), 121, 130; dispatcher at 294, 336-352`
- `resources/views/email/common_email_template.blade.php:1-27`
- `resources/views/email/sendOTP.blade.php:1-136`
- `resources/views/email/otp_verify.blade.php:1-134`
- `resources/views/email/productMail.blade.php:1-355`
- `resources/views/email/test_mail.blade.php:1-219`
- `resources/views/vendor/notifications/email.blade.php:1-40`
- `packages/workdo/EMailBox/src/resources/views/mail/email_template.blade.php:1-9`
- `packages/workdo/PDFVouchers/src/resources/views/mail_setting/coupen_mail.blade.php:1-19`
- `resources/views/setting/test_mail.blade.php:8`
- `resources/views/auth/verify-email.blade.php:61`
- `routes/api.php:71-74`
- `routes/api.php:86`
- `routes/api.php:140-141`
- `routes/api.php:145`
- `routes/api.php:165`
- `routes/api.php:208-211`
- `routes/api.php:357`
- `routes/api.php:374`
- `routes/auth.php:18`
- `routes/auth.php:30-31`
- `routes/auth.php:48-50`
- `routes/customer.php:111`
- `routes/customer.php:113`
- `routes/customer.php:128-131`
- `routes/customer.php:174`
- `routes/customer.php:182`
- `routes/customer.php:183`
- `routes/customer.php:184-185`
- `routes/customer.php:186`
- `routes/customer.php:190`
- `routes/customer.php:191`
- `routes/customer.php:195`
- `routes/customer.php:197`
- `routes/customer.php:200`
- `routes/customer.php:355`
- `routes/customer.php:375`
- `routes/web.php:347-350`
- `routes/web.php:911`
- `routes/web.php:1040`
- `routes/web.php:1192, 1210`
- `routes/web.php:1193`
- `routes/web.php:1200`
- `routes/web.php:1206`
- `routes/web.php:1208`
- `routes/web.php:1602`
- `app/Models/Utility.php:606-801 (609-616 names, 619 firstOrCreate, 789 lang create)`
- `app/Models/Utility.php:804-824 (817 is_active = 0)`
- `packages/workdo/Helpdesk/src/database/seeders/EmailTemplateDatabaseSeeder.php:21-24, 161-185`
- `app/Http/Middleware/LotteryMailTemplatePrerequisite.php:160-186 (178 insert)`
- `packages/workdo:see trigger for per-file line numbers`

## 4. The five mechanism analyses

### Mechanism 1

# GAP-200 / MKT-008 — MECHANISM QUESTION 1: Laravel mail-event interception Verified against the vendored framework in this repo: **Laravel 11.57.1** (`/home/user/syriastore98/vendor/laravel/framework/src/Illuminate/Foundation/Application.php:48`). ## Answer in one line `MessageSending` fires on **every email path used in this repository**, and a listener returning literal `false` **aborts the send** before the transport is touched. The required seam exists, is first-party, needs no `packages/workdo/` edit and no migration — **for email only**. Three other outbound channels (SMS, WhatsApp, FCM push) do **not** fire it, and a plan that treats this hook as covering "sending" rather than "email sending" would be exactly the quiet-assumption failure you warned about. --- ## 1. Does it fire for every mail path? — YES for email, by construction There is exactly one funnel: `Illuminate\Mail\Mailer::send()` at `vendor/laravel/framework/src/Illuminate/Mail/Mailer.php:298`. The decision point is `Mailer.php:331-341`: ```php if ($this->shouldSendMessage($symfonyMessage, $data)) { // Mailer.php:331 $symfonySentMessage = $this->sendSymfonyMessage($symfonyMessage); if ($symfonySentMessage) { $sentMessage = new SentMessage($symfonySentMessage); $this->dispatchSentEvent($sentMessage, $data); // Mailer.php:337 return $sentMessage; } } ``` `Mailer.php:598-608`: ```php protected function shouldSendMessage($message, $data = []) { if (! $this->events) { return true; } return $this->events->until(new MessageSending($message, $data)) !== false; } ``` Every entry point converges there: | Path | Route to `Mailer::send()` | Evidence | |---|---|---| | `Mail::to($x)->send($mailable)` | facade → `MailManager::__call` → `PendingMail::send()` → `Mailer::send($mailable)` → `sendMailable()` → `Mailable::send()` → `Mailer::send($view,$data,$cb)` | `Facades/Mail.php:87-90`; `MailManager.php` `__call`; `PendingMail.php:122-125`; `Mailer.php:350-355`; `Mailable.php:198-218` | | `Mail::send($mailable)` (one arg) | `MailManager::__call` → `mailer()->send()` → `sendMailable()` → same | `MailManager.php` `__call` / `mailer()`; `Mailer.php:300-302` | | `Mail::raw / html / plain` | all delegate to `send()` | `Mailer.php:210, 222, 235` | | `Mail::sendNow` | `Mailer.php:365-371` | same funnel | | **Queued** (`Mail::queue` / `later` / `ShouldQueue` mailable) | `Mailable::queue()` → `SendQueuedMailable` job → `handle()` → `$this->mailable->send($factory)` → `Mailer::send()` | `Mailable.php:225`; `SendQueuedMailable.php:81-83` | | Notifications (`$user->notify(...)` with a `MailMessage`) | `MailChanne

### Mechanism 2

GAP-200 / MKT-008 — MECHANISM QUESTION 2: THE NOTIFICATION LAYER VERDICT (one line): `NotificationSending` is cancellable but is the WRONG seam — it fires for **2 senders in the entire repo**, both transactional. The right seam is `Illuminate\Mail\Events\MessageSending`, which I **measured** firing and cancelling for every mail call shape in this codebase, including vendored-module Mailables. **Boundary enforcement for EMAIL is achievable with no `packages/workdo/` edit and no migration. Durable per-recipient suppression STORAGE is not achievable without one of the two.** Non-email channels (WhatsApp, SMS, FCM) do not pass this seam at all. ═══════════════════════════════════════════════════════════════ 1. `NotificationSending` — it cancels, but it covers almost nothing ═══════════════════════════════════════════════════════════════ Cancellation mechanics (vendored framework, Laravel **11.57.1**, `vendor/laravel/framework/src/Illuminate/Foundation/Application.php:48`): - `vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:160-170` — `shouldSendNotification()` is `$this->events->until(new NotificationSending(...)) !== false`. A listener returning `false` suppresses the send. It also honours a `shouldSend()` method on the notification (`:161-164`). - Event payload: `vendor/.../Notifications/Events/NotificationSending.php:17-30` — `$notifiable`, `$notification`, `$channel`. No cancel is possible before that point. - Queued notifications still pass it: `NotificationSender::queueNotification()` (`:176-237`) dispatches `SendQueuedNotifications`, whose `handle()` (`vendor/.../Notifications/SendQueuedNotifications.php:118-121`) calls `sendNow()`, which re-enters `shouldSendNotification()`. **MEASURED** (booted app, `APP_ENV=testing`): a listener returning `false` on `NotificationSending` suppressed `App\Notifications\ResetPasswordNotification`; `MessageSending` never fired and the array transport held 0 messages. WHO ACTUALLY USES IT — the census is brutally small: - `use Notifiable` exists on exactly **two** models: `app/Models/User.php:8,25` and `app/Models/Customer.php:8,21`. **Zero** occurrences in `packages/workdo/`. - `->notify(` / `->notifyNow(` / `Notification::send` / `::sendNow` / `::route` across `app/`: **2 sites**, both password reset — - `app/Models/Customer.php:283` → `ResetPasswordNotification` - `app/Http/Controllers/Auth/PasswordResetLinkController.php:87` → `CustomResetPasswordNotification` - Across `packages/workdo/`: **0 sites** (verified by grep over all 70 modules). - The only `Notification` class in a WorkDo modu

### Mechanism 3

# GAP-200 / MKT-008 — MECHANISM QUESTION 3: mail transport / container binding **VERDICT: YES — achievable, with zero `packages/workdo/` edits and zero migrations. But NOT by the seam the question names.** A first-party *custom transport* or a *decorated MailManager* both work mechanically and both are the wrong choice here: in this codebase the transport driver name is tenant-supplied runtime data, so a transport-keyed interception has a silent-miss mode. The correct seam is the `MessageSending` event, which is checked inside `Illuminate\Mail\Mailer::send()` on the single code path that reaches the transport at all. Below: the chokepoint proof, the sender census, the exact binding, and why the other two seams fail. --- ## 1. There is exactly ONE place email leaves this application `vendor/laravel/framework/src/Illuminate/Mail/Mailer.php:585` ```php return $this->transport->send($message, Envelope::create($message)); ``` This is the **only** `transport->send` in the whole of `Illuminate\Mail` (grep over the entire directory returned one hit). It is reached only from `Mailer::sendSymfonyMessage()` (`Mailer.php:582`), which is called from exactly one place: `Mailer.php:331-332` ```php if ($this->shouldSendMessage($symfonyMessage, $data)) { $symfonySentMessage = $this->sendSymfonyMessage($symfonyMessage); ``` `Mailer.php:598-607` ```php protected function shouldSendMessage($message, $data = []) { if (! $this->events) { return true; } return $this->events->until(new MessageSending($message, $data)) !== false; } ``` Everything else funnels into `Mailer::send()`: - `Mailer::html()` `:210`, `raw()` `:222`, `plain()` `:235` — each `return $this->send(...)`. - `Mailer::sendNow()` `:365` — delegates to `send()` or `$mailable->send($this)`. - `Mailable::send($mailer)` (`vendor/laravel/framework/src/Illuminate/Mail/Mailable.php`) — `return $mailer->send($this->buildView(), $this->buildViewData(), fn($message) => ...)`. - `Illuminate\Notifications\Channels\MailChannel::send()` `:64` / `:67` — `$message->send($this->mailer)` / `$this->mailer->mailer(...)->send(...)`. - Facade: `Illuminate\Support\Facades\Mail::getFacadeAccessor()` `:87-90` returns `'mail.manager'`; `MailManager::__call()` forwards every unknown method to `$this->mailer()->$method(...)`. So `Mail::to()->send()` and `Mail::send()` both land on the same `Mailer`. **`$this->events` is never null in this app.** `MailManager::build()` `:142-147` always constructs `new Mailer($name, $views, $transport, $this->app['events'])`. So the `return true` escape at `Mailer.php:599` is unreachable here. --- ## 2. Ever

### Mechanism 4

# GAP-200 / MKT-008 — MECHANISM QUESTION 4: the LynomiaChat consent model as a template Read-only investigation. Every claim below is file:line from `/home/user/syriastore98` at HEAD (`.git/HEAD` → `feature/phase-05-marketing-analytics`). Where I could not verify something I say so. ## VERDICT UP FRONT **As a design template: excellent, and it should be copied.** It already models the four things a defensible consent record needs — purpose, channel, state, and evidence of capture — and it gets the two hard parts right (append-only decisions separated from projected effective state; negatives union, positives do not). **As *the* general consent record, under the stated constraints: NO, and it is not close.** Three independent blockers, any one of which is fatal: 1. **It lives entirely under `packages/workdo/`**, which the stated constraint forbids editing (see §5.1 — this conflicts with both CLAUDE.md and the CI guard, and the parent should resolve that conflict rather than assume it). 2. **Its tables are created only by module migrations** (`packages/workdo/LynomiaChat/src/database/migrations/2026_10_20_000100…`, `2026_10_21_000200…`). Whether they exist in production **cannot be verified from this repository**. If they do not, using them requires a production migration — forbidden. 3. **It is keyed on a Lynomia subject id, not on a deliverable address**, and it holds **no email or phone column at all** — so it cannot answer the only question the e-mail sending boundary can ask. **The sending boundary itself is a different matter, and there the answer is YES** — a real first-party interception point exists, is unoccupied, and covers both named e-mail senders without touching `packages/workdo/`. Details in §5.2. That seam is genuine; the *record it would consult* is what does not exist. --- ## 1. WHAT IT ALREADY MODELS **Two tables, deliberately split** (`.../database/migrations/2026_10_20_000100_create_lynomia_consent_tables.php:38-94`): | | `lynomia_consent_decisions` | `lynomia_consent_states` | |---|---|---| | answers | what the customer told **this store** | what **Lynomia** holds, as last reported | | authored by | this side (`CheckoutConsentCapture`) | inbound Lynomia events / the hourly pull | | shape | append-only, one row per decision | one row per scope **per origin**, upserted | | key | `unique(store_id, decision_ref)`; scope index `(store_id, external_subject_id, purpose, channel, id)` :69-72 | `unique(store_id, external_subject_id, purpose, channel, origin)` (migration 2 :68-71) | Modelled fields worth reusing verbatim: `purpose` / `channel`

### Mechanism 5

# GAP-200 / MKT-008 — MECHANISM QUESTION 5: the schema, and whether the constraint is satisfiable **Read-only. No file was written, created or deleted; no git write ran.** --- ## 0. What I measured, and what I did not **Measured directly:** the three tables' columns and indexes from the two migration-derived dev databases (`/home/user/syriastore98/database/database.sqlite`, `/home/user/syriastore98/database/p2dev.sqlite`, via `PRAGMA table_info` / `PRAGMA index_list`), every migration that touches them, the models, every mail/notification send site in `app/` and `packages/workdo/`, and the framework source in `vendor/` that decides whether a message is actually handed to a transport. **NOT measured, and I cannot measure it from here — this is load-bearing:** - **The production column set of `customers`, `newsletters` and `users` has never been measured.** `docs/GATE-F-VERIFIED-SCHEMA.md` §2 covers 15 tables and 3 columns and **none of them is one of these three**. The dev SQLite files are built from `database/migrations/` and therefore prove what *this codebase creates*, not what production *has* — the exact distinction `docs/GATE-F-VERIFIED-SCHEMA.md` §1 records as the reason three confident claims in this repo turned out false. - There is already a **known production-vs-code disagreement in this very family**: `newsletters.user_id`, an undeclared column, is the single remaining declared skip in `.github/known-test-outcomes.json` (`expected_skips`), and `docs/GATE-F-VERIFIED-SCHEMA.md:41` says its type "was never measured, so it is not in §2 and declaring it would be the guess GATE F exists to forbid." - Whether `lynomia_consent_decisions` / `lynomia_consent_states` exist **in production** is unknowable from here (module migration, module ships disabled). - Whether module-added `customers` columns exist in production (`total_club_point`, `affiliate_code`, `used_affiliate_code`, `commission_amount`, `social_id`, `social_type` — all named in `app/Models/Customer.php:30-58` but absent from **both** dev DBs). - I did not read `app/Services/FirebaseNotificationService.php` or the other push services in detail; the push channel is marked NOT MEASURED below. --- ## 1. `customers` — 30 columns measured `id, billable_id, billable_type, trial_ends_at, created_at, updated_at, first_name, last_name, email, profile_image, type, email_verified_at, password, mobile, regiester_date, last_active, status, date_of_birth, firebase_token, device_type, register_type, google_id, apple_id, facebook_id, password_otp, password_otp_datetime, created_by, store_id, remember_token, 


---

## 5. The three non-email send-boundary chokepoints — classified on evidence (2026-11-20, for D8-2)

D8-2a wired the **email** seam at `MessageSending`. The other three outbound channels do not fire that
event (§4, mechanism 1/2); each has exactly one first-party chokepoint, and D8-2 asks of each: is there a
**marketing** sender here that a consent gate must cover, without holding a transactional message? Measured
at HEAD against primary source. (Raw `grep SendMsgs(` counts are inflated by docblock references to
`Utility::SendMsgs()`; the figures below are **actual call sites**, read.)

| Channel | Chokepoint | Marketing sender? | Classification (evidence) |
|---|---|---|---|
| **SMS** | `Utility::send_twilio_msg()` (`app/Models/Utility.php:3464`) | **No** | All 4 callers are internal `Utility` methods — `low_stock_threshold` (:3492), `out_of_stock` (:3508), `variant_out_of_stock` (:3524), `variant_low_stock_threshold` (:3712) — and every one sends to `$settings['twilio_notification_number']`, the **merchant's own** number, about their own stock. Operational self-alert to the store, not a message to a customer. No marketing-consent surface. |
| **Push** | `Utility::sendFCM()` (`app/Models/Utility.php:3580`) | **No (first-party)** | 2 callers. `ProductController:2970` sends "now {product} is available in stock" only to `NotifyUser` rows — users who explicitly asked to be told when this product returns — then deletes the row: a user-requested, one-shot transactional alert, not a campaign. The second caller is in vendored `AuctionProduct` (auction notice; not first-party, not editable). `Utility::ios_send_push_notification` is dead code (§2). |
| **WhatsApp** | `Utility::SendMsgs()` (`app/Models/Utility.php:2652`) | **Yes — one** | The first-party **marketing** path is the abandoned-basket nudge: `CartController::abandon_carts_messsend()` and `WishlistController::abandonWishlistMsgSend()` → `App\Services\AbandonedMessageDispatch::handOver()` → `SendMsgs()`. "Nudge the shopper" re-engagement = marketing. Every other first-party `SendMsgs()` caller (PaymentController, the gateway controllers, ApiController, PaymentService, the SMS-module order/payment listeners) is an order-created or payment/stock-status acknowledgement to the buyer of the order in hand — transactional, several already classified so in §2. |

**What D8-2 is, therefore.** The only first-party marketing sender among the three non-email channels is the
**abandoned cart / abandoned wishlist WhatsApp nudge**, through the single first-party service
`AbandonedMessageDispatch`. D8-2 gates that one path through the existing `MarketingSendGate`
(`Channel::Whatsapp`, `MessageClass::Marketing`, the shopper's mobile), mirroring the email seam: inert while
`off`; in `report` the nudge still goes and the gate records what enforcement would do; `enforce` is read as
`report` for a phone channel until `phone_identity_canonical` (the gate already enforces that downgrade). SMS
and push need no gate — their first-party senders are operational or user-requested, not marketing. Any
**bulk campaign** sender in the vendored Marketing module reaches WhatsApp only through this same
`Utility::SendMsgs()` chokepoint; covering it under `enforce` is a chokepoint-seam question reserved to the
owner (it cannot be done by editing `packages/workdo/`), and is out of first-party D8-2 scope.
