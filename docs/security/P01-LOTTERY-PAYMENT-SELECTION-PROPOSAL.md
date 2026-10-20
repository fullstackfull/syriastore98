# P01 — Lottery payment-method selection: verification and the smallest compatible correction

**Issue:** #70 item 5 · **Task:** P1-INV-22 · **Status:** verified by execution; correction
**proposed, not applied** — it needs an owner decision (§6).

Owner decision of this round: *"For #70, verify the payment-method selection mismatch using
executable tests and propose the smallest compatible correction."* This is the proposal. The
verification is `tests/Feature/Security/LotteryPaymentMethodSelectionTest.php` — 17 tests, 235
assertions, executed via `php artisan test` on SQLite `:memory:`.

> **This document replaces an earlier version that was wrong.** That version built its census from
> `App\Models\Setting::paymentList()`, whose `name_string` is a translated label, and concluded that
> under Arabic **no** gateway is reachable. The lottery form does not use that builder. The Arabic
> finding is retracted, the counts are corrected from 8/27 to 9/26, and the proposed middleware
> predicate — which would have *created* an Arabic outage and broken a gateway that works today —
> is corrected in §5. §7 records what went wrong, because the same mistake is available to the next
> reader.

---

## 1. What #70 filed, and what is true

| Filed | Measured |
|---|---|
| the view posts `ucfirst($payment_data->name_string)` | correct — `lottery-form.blade.php:290` |
| the service switches on mixed-case literals | correct — 35 `case` labels, exactly 9 capitalised |
| `'paystack'`/`'yookassa'`/`'midtrans'`/`'whatsapp'` never match | correct, and it is **26 gateways**, not 4 |
| `LotteryPrizesController:575` tests `== 'Midtrans'` while the switch is `case 'midtrans'` | correct, and it is the one case a fix cannot reconcile (§5) |
| — | the handler never checks the gateway is enabled for the store (§4.1) |
| — | the form silently truncates its own catalogue at CinetPay (§4.2) |
| — | two gateways a naive case-folding fix would break (§5) |

## 2. The mechanism

```
LotteryPrizesController.php:489-491
    $api = new ApiController();                       // App\Http\Controllers\Api\ApiController
    $payment_list_data = $api->payment_list($request, $store->slug);

Api\ApiController::payment_list                       // ZERO of its 52 name_string assignments
    $Setting_array[18]['name_string'] = 'payfast';    // call __() -- asserted
    $Setting_array[18]['name']        = 'payfast';

lottery-form.blade.php:290
    value="{{ ucfirst($payment_data->name_string) }}" // -> 'Payfast'

LotteryPaymentService.php:83
    case 'payfast':                                   // never matches
```

`name_string` here **is** the gateway slug, lower-case for 26 of the 35 the service implements. The
view posts `ucfirst()` of it; `switch` is case-sensitive. **The nine that work are exactly the nine
whose `case` literal is already capitalised.**

**Measured:** 52 catalogue entries, 35 implemented, **9 reachable** (`stripe`, `Razorpay`, `paypal`,
`flutterwave`, `paytm`, `Sspay`, `Paytabs`, `Xendit`, `khalti`), **26 unreachable**, 17 with no
switch arm.

**All 26 differ from their `case` label only in the first character's case** — asserted
individually, with `assertSame(ucfirst($wants), $posted)` for every one. A single `strcasecmp`
resolves all 26. There are no spelling mismatches on this surface, which makes the correction
smaller than the first version of this document argued.

**The posted values are locale-invariant.** Measured by rendering the real form in `en` and `ar` and
harvesting the `value="…"` attributes: byte-identical
(`Stripe Coingate Paytabs Payfast Telegram Phonepe Cinetpay`). `resources/lang/ar.json` does
translate the brand names — `"Stripe": "الشريط"`, `"COD": "سبب الوفاة"` — but those reach only
`Setting::paymentList`, which serves `HomeController::checkout`, not this form. That is a real
customer-facing text defect on the ordinary checkout and it is tracked separately; it is **not** this
finding.

### What the customer sees

Driven end-to-end through the registered route:

| posted | HTTP | session `error` |
|---|---|---|
| `telegram`, `whatsapp` (switch labels) | 200 | — (a rendered payment view) |
| `Telegram`, `Payfast`, `Phonepe`, `Coingate` | 302 | `Incorrect currency of payment.` |

`process()` returns `response()->json(['error' => 'Invalid payment method'], 422)`;
`lottery_form_data` reaches its `else` arm and calls `filter_var($response, FILTER_VALIDATE_URL)`.
My first reading predicted an uncaught `\Error` there — wrong: `JsonResponse` inherits `__toString`
from `Symfony\Component\HttpFoundation\Response`, so filter_var stringifies the HTTP message and
returns `false`. The handler throws, its own `catch (\Exception $e)` swallows it, and the customer
gets a message about currency. No ticket row is written — this is a revenue and functionality
outage, not a forged write.

**A caveat the suite states explicitly:** a *reachable* gateway without credentials also ends in the
currency message, because its processor throws into the same catch. So the route proves the
user-visible outcome; the reachable/unreachable split is proven by driving `process()` directly.
Both are in the suite.

## 3. The strongest single assertion

`telegram` → a rendered view. `Telegram` → the 422 default arm. Same gateway, same request, one
character of case, both executed offline (`processTelegram` is a `Session::put` and a view, so it
needs no network and no credentials).

## 4. Two things found while building the control

### 4.1 The handler never checks the gateway is enabled for the store

`lottery_form_data` validates `payment_methods` as `required` and nothing else. The view's
`@if ($payment_data->status == 'on')` is the only consultation of the per-store enable row, and a
POST does not go through the view.

Measured: a store with zero `settings` rows reports `telegram` status `'off'`, and the same store
accepts `payment_methods=telegram` with HTTP 200 and dispatches to `processTelegram()`. This is the
caller-supplied-identifier pattern `CLAUDE.md` names, applied to a gateway choice. It is a **forged
POST**, not a customer-selectable path, since the form posts `Telegram`.

It is **independent of** the case mismatch, and fixing the mismatch alone would **widen** it: 26 more
processors would become reachable by direct POST regardless of what the merchant switched on.

### 4.2 The form truncates its own catalogue at CinetPay

`LotteryPrizesController.php:495-506` drops `cod` and `bank_transfer`, and on reaching `CinetPay`
sets `$stopFiltering = true` and breaks. CinetPay is catalogue index 37, so **SenangPay,
CyberSource, Ozow, Easebuzz, NMI, PayU, Sofort, eSewa, MyFatoorah, Paynow, DPO, Braintree,
PowerTranz and SSLCommerz are never offered**, however the merchant configures them.

Measured: a store with `cinetpay`, `payu`, `sslcommerz` and `braintree` all enabled renders exactly
one radio — `Cinetpay`.

(This replaces a claim in the first version that COD "is offered by default and cannot complete".
COD is never offered on the lottery form at all. The switch has no COD arm and list B at `:546`
names it, so the omission is still visible in the code — but it is a dead branch, not a live
outage.)

## 5. The smallest compatible correction

Both offending files are under `packages/workdo/` and may not be edited. The correction is
host-side, and it is one middleware.

### `NormalizeLotteryPaymentSelection` — on `lottery-form.data.store`

Registered through `appendToGroup('web', …)` and keyed on `$route->getActionName()` for
`LotteryPrizesController@lottery_form_data`, the vehicle the four shipped lottery guards already use.

```
1. resolve the store from the route's {storeSlug}                       (never from request input)
2. build the catalogue THE FORM USES -- Api\ApiController::payment_list($request, $store->slug)
   -- and apply the form's own filter (LotteryPrizesController:495-506), so the middleware never
   accepts a gateway the form did not offer
3. find the single entry where  ucfirst($entry->name_string) === $request->payment_methods
                          AND  $entry->status === 'on'                 (NO __() -- see below)
4. no match -> refuse with the module's own 422 shape; do not fall through
5. match    -> $request->merge(['payment_methods' => <the switch's exact case label for the slug>])
```

**Step 3 has no `__()` and must not acquire one.** The first version of this document specified
`ucfirst(__($entry['name_string']))` against `Setting::paymentList`. That is wrong twice: it compares
a translated label to an untranslated posted value, so under any non-English locale **nothing would
match and every lottery payment would be refused**; and in English it computes `'Paytab'` while the
request carries `'Paytabs'`, **breaking a gateway that works today**. The suite pins `Paytabs` as
reachable for exactly this reason.

Properties:

- **No vendor file changes.** The guard sits in front of the handler; the switch is untouched.
- **The request/response contract is preserved.** The customer posts what the shipped view emits.
- **Locale-invariant**, because the catalogue it reads is.
- **Strictly tightening on the selection.** Step 3's `status === 'on'` limb plus step 2's filter is
  the allowlist §4.1 is missing. A direct POST of a raw switch label (`telegram`) stops matching and
  is refused — a behaviour change, and a correct one.
- **Step 1 is a coherent allowlist, not a tenant predicate, and the distinction matters.** The route
  carries no principal (`routes/web.php:32-35`: `middleware(['web'])` only), so no session identity
  is being overridden by request input. The slug is the resource locator, the handler already does
  `getStore($slug)` and scopes `LotterySetting` by `$store->id`, and a caller naming store B is
  checked against — and pays — store B. This is *not* the `$request->store_id` anti-pattern.
- **No ambiguity risk**: `ucfirst($entry->name_string)` is distinct across all 52 entries
  (`Payfast`, `Paypal`, `Paytm`, `Paytabs`, `Paytr`, `Paynow`, `Payu`, `Payhere` all differ). The
  middleware should still refuse on a multi-match rather than pick one.
- **Repairs PayTR's amount conversion for free.** `StoreLotteryPayment` reads the session copy, which
  the processors write from the already-normalised `$request`, so its `== 'paytr'` branch (the
  kurus→lira division) becomes live.

### Two gateways the normalisation would break, both pinned by the suite

- **`phonepe`.** Response-dispatch list B at `:546` names `'Phonepe'`, while the switch label is
  `'phonepe'`. Normalising makes the gateway dispatch and makes that list stop matching, so PhonePe
  falls into the else arm and **still** ends in the currency message. (It is a 302 today, not a 500,
  because `JsonResponse::getTargetUrl()` raises `BadMethodCallException`, which *is* an `Exception`
  and is caught. Measured.)
- **`coingate`.** List B names `'coingate'`, so normalising routes a **successful** `processCoingate`
  — which returns `(object) $response` at `LotteryPaymentService:629` — into
  `new RedirectResponse($response->getTargetUrl())`. `stdClass::getTargetUrl()` is an **`\Error`**,
  which `catch (\Exception)` does **not** catch: **HTTP 500** where today it is a 302. The dedicated
  `elseif ($paymentMethod == 'coingate')` at `:549` is already dead code, because `:546` matches
  first. Measured.

Both must be excluded from the normalisation map until the vendor fixes the lists.

### `RestoreMidtransSelection` — the one irreconcilable case

`StoreLotteryPayment` compares the session copy to `'Midtrans'`; the switch wants `'midtrans'`. One
value cannot satisfy both, and that comparison guards the gateway's `status_code` check — the only
thing stopping a failed Midtrans payment from writing a ticket. Smallest fix: a second middleware on
the named route `store.ticket.payment.midtrans` (`routes/web.php:44`, which is where
`LotteryPaymentService:1359` sends the return) that re-casts the session copy to `'Midtrans'` before
the controller reads it. `'Khalti'` needs nothing.

### What this does not fix

**PayFast's client-side flow.** `lottery-form.blade.php:397` and `:428` compare the selection to
`'payfast'` in **JavaScript**, so the form is never retargeted at the PayFast host and
`get_payfast_status()` is never called. A server middleware cannot reach a browser comparison.

**The truncation at CinetPay (§4.2), COD and bank transfer.** Vendor-side; nothing a guard can
invent.

**`payhere` and `yookassa`** are in `RetiredGateways::PROVIDERS`, so their status is forced `'off'`
and the view never renders a radio for them. They are unreachable for a prior and stronger reason
and must be excluded from any "would go live" count.

## 6. Why this is not applied in this commit

1. **It switches payment gateways from dead to live on a trading storefront.** The honest figure is
   **at most 22**: 26 unreachable, minus `payhere`/`yookassa` (never offered), minus `phonepe` and
   `coingate` (still broken, and coingate becomes a 500), and only for gateways a merchant has
   enabled. Every one of those processors is vendored code that, as far as this repository's history
   shows, has never executed in production. Enabling them changes what the business can charge
   through — the class of change owner decision 7 reserves.
2. **Step 4 makes a previously-accepted request fail.** A direct POST of a raw switch label is
   accepted today (§4.1) and would be refused. Correct, and still a contract change on a payment
   path.
3. **The Midtrans guard re-enables a payment-status check that is currently skipped**, which
   interacts with #68's held question: *"do not trust a recorded Paid state without verified
   settlement evidence."* It should land with that design, not ahead of it.

**Recommended sequencing**

| | change | gate |
|---|---|---|
| a | `NormalizeLotteryPaymentSelection` shipped **refuse-only**: step 1–4 with no rewrite — the §4.1 allowlist alone | tightening only; no gateway changes state |
| b | enable the rewrite in (a), excluding `phonepe` and `coingate`, one gateway at a time from the merchant's enabled set | owner decision per gateway |
| c | `RestoreMidtransSelection` | land with #68's payment-state design |
| d | upstream WorkDo report: the `ucfirst` in the view, list B's `'Phonepe'`, `processCoingate`'s return type, the CinetPay truncation, the missing COD/bank-transfer arms, and PayFast's JavaScript comparison | owner approval to send |
| e | the Arabic brand-name keys in `resources/lang/*.json` — a real defect on `HomeController::checkout`, with **no effect on this flow** | separate issue |

(a) is the part worth doing soonest: it closes a live authorization-shaped gap and changes nothing
about which gateways work.

## 7. What went wrong in the first version, and the guard added against it

I read `LotteryPrizesController:489-491` — the two lines that instantiate `Api\ApiController` and
call `payment_list` — and then built the census from `Setting::paymentList` anyway, because that
builder is in host code, has the same `$Setting_array[...]['name_string']` shape, and *looked* like
the source. Every downstream number followed from it, and the Arabic translations in `ar.json` made
the false conclusion feel like a discovery rather than an error. A premise test that checked the
regex parse against `Setting::paymentList` passed, which confirmed the wrong thing.

The suite now contains `test_premise_the_harvest_from_the_rendered_form_is_locale_invariant`: it GETs
the real form in both locales and takes the radio values out of the rendered HTML. Nothing is
reconstructed. That test alone would have caught this on the first run, and a census built from a
builder rather than from the page is the general shape of the mistake.

---

### Evidence

```
php artisan test tests/Feature/Security/LotteryPaymentMethodSelectionTest.php
  17 passed (235 assertions)
```

Characterization suite: every assertion pins today's behaviour, and a correct fix is expected to
turn some of it red. Four premise tests guard the ways it could pass while proving nothing — the
module really loading, the form's catalogue really being ApiController's and really not translating,
the harvest from the rendered page, and the Blade attribute still posting `ucfirst(name_string)`.
