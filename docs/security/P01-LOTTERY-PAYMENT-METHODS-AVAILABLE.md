# P01 — Which lottery payment methods actually work, and the smallest safe fix

**Issue:** #70 item 1 · **Task:** `P1-INV-22` · **Status:** documentation and a **refuse-only**
proposal. **Nothing is activated.**

Owner decision this answers: *"Document actual available payment methods and the smallest safe
compatibility fix. Do not activate previously unreachable payment flows without approval."*

Evidence for every figure: `tests/Feature/Security/LotteryPaymentMethodSelectionTest.php` — 19
tests, 76 assertions, each recomputed from the shipped sources on every run so an upstream rename
moves the numbers and the pinned counts go red.

---

## 1. What a customer can actually pay with, today

**Nine of the thirty-five gateways the lottery payment service implements are reachable.** The other
twenty-six fall through to `default:` and the customer is told *"Incorrect currency of payment."*

### Reachable — these nine work

| posted value | gateway |
|---|---|
| `Stripe` | Stripe |
| `Razorpay` | Razorpay |
| `Paypal` | PayPal |
| `Flutterwave` | Flutterwave |
| `Paytm` | Paytm |
| `Sspay` | sspay |
| `Paytabs` | PayTabs |
| `Xendit` | Xendit |
| `Khalti` | Khalti |

They work for one reason only: these are exactly the nine whose `case` literal in
`LotteryPaymentService::process()` is already capitalised, and the form posts `ucfirst()` of the
gateway slug. It is coincidence, not design.

### Unreachable — these twenty-six are offered and cannot complete

`paystack`, `mercado`, `skrill`, `paymentwall`, `mollie`, `coingate`, `toyyibpay`, `iyzipay`,
`payfast`, `benefit`, `cashfree`, `aamarpay`, `telegram`, `whatsapp`, `paytr`, `yookassa`,
`midtrans`, `nepalste`, `payhere`, `authorizenet`, `tap`, `phonepe`, `paddle`, `paiementpro`,
`fedpay`, `cinetpay`.

Each differs from its `case` label **only in the first character's case** — `'payfast'` is posted as
`'Payfast'`, and so on for all twenty-six. A single `strcasecmp` resolves every one.

### Never offered at all, for reasons unrelated to the casing

- **`payhere` and `yookassa`** are in `RetiredGateways::PROVIDERS`, so their status is forced `off`
  and the radio is never rendered. They are in the twenty-six above but are unreachable for a prior
  and stronger reason, and must be excluded from any "would go live" count.
- **`cod` and `bank_transfer`** are dropped by the form's own filter
  (`LotteryPrizesController.php:502`). The switch implements neither, and the response-dispatch list
  at `:546` names both — so their absence from the switch looks like an omission, but it is a dead
  branch, not a live outage.
- **Everything after CinetPay in the catalogue.** The same filter sets `$stopFiltering = true` on
  reaching CinetPay (catalogue index 37), so **SenangPay, CyberSource, Ozow, Easebuzz, NMI, PayU,
  Sofort, eSewa, MyFatoorah, Paynow, DPO, Braintree, PowerTranz and SSLCommerz are never offered**,
  however the merchant configures them. Measured: a store with `cinetpay`, `payu`, `sslcommerz` and
  `braintree` all enabled renders exactly one radio.

### So the honest figure for "how many would go live under a casing fix" is **at most 22**

26 unreachable, minus `payhere` and `yookassa` (never offered), minus `phonepe` and `coingate`
(§3 — a casing fix alone leaves them broken), and only for gateways a merchant has actually
enabled.

## 2. Why — three lines of shipped code

```
packages/workdo/LotteryPrizes/src/resources/views/theme/lottery-form.blade.php:290
    value="{{ ucfirst($payment_data->name_string) }}"

app/Http/Controllers/Api/ApiController.php  (payment_list — the builder the form uses)
    $Setting_array[18]['name_string'] = 'payfast';     // a BARE LITERAL; zero of its 52
    $Setting_array[18]['name']        = 'payfast';     // name_string assignments call __()

packages/workdo/LotteryPrizes/src/app/Services/LotteryPaymentService.php:83
    case 'payfast':
```

`name_string` here **is** the gateway slug, lower-case for 26 of the 35. The view posts `ucfirst()`
of it and `switch` is case-sensitive.

**The posted values are locale-invariant.** Measured by rendering the real form in Arabic and
English: the radio values are byte-identical. (`resources/lang/ar.json` does translate the brand
names, but those reach `Setting::paymentList`, which serves `HomeController::checkout` — not this
form. That is a separate, real, customer-facing text defect: `"COD"` renders as `"سبب الوفاة"`,
"cause of death". Its own item.)

## 3. Two gateways a casing fix alone would not fix — and one it would make worse

- **`phonepe`.** Response-dispatch list B at `LotteryPrizesController.php:546` names `'Phonepe'`
  while the switch label is `'phonepe'`. Normalise the selection and the gateway dispatches while
  that list stops matching, so PhonePe falls into the `else` arm and **still** ends in the currency
  message.
- **`coingate`.** List B names `'coingate'`, so normalising routes a **successful**
  `processCoingate` — which returns `(object) $response` at `LotteryPaymentService.php:629` — into
  `new RedirectResponse($response->getTargetUrl())`. `stdClass::getTargetUrl()` is an **`\Error`**,
  which `catch (\Exception)` does not catch: **HTTP 500** where today it is a 302. A casing fix makes
  this one worse, not better.

Both measured. Both must be excluded from any normalisation map until the vendor fixes the lists.

## 4. Two vendored 500s on this screen, unrelated to the casing

- **Ticking "Refund Ticket Option" is a 500 for every merchant**, on an otherwise legitimate save.
  The checkbox is renamed into the repeater row by `public/js/repeater.js`, so a ticked box posts the
  scalar `'on'`, and `store():115` calls `implode(',', …)` on it — a PHP 8 `TypeError`. Measured:
  ticked → 500 and no row; unticked → 302 and a row. Pinned by
  `test_pins_a_vendored_defect_ticking_refund_ticket_is_a_500`.
- **The lottery clear-all is a 500.** `[]` has no urlencoded representation and the jQuery-repeater
  form sends **no** `lottery_repeater_basic` key when every row is deleted; `store():95` then does
  `foreach (null)`. Our `LotterySettingProductOwnershipGuard` turns that into a clean refusal, so
  nothing regressed — but the feature has never worked. The fix is `?? []` at `:93`, which is
  upstream's, and deliberately not smuggled into our guard because it would newly enable a
  destructive bulk delete that has never been reachable.

## 5. The smallest safe compatibility fix — and it activates nothing

### Ship this now: `NormalizeLotteryPaymentSelection`, **refuse-only**

One host middleware on `lottery-form.data.store`, keyed on the route's action name, with **the
rewrite disabled**:

```
1. resolve the store from the route's {storeSlug}             (never from request input)
2. build the catalogue the FORM uses -- Api\ApiController::payment_list($request, $store->slug) --
   and apply the form's own filter (LotteryPrizesController:495-506)
3. find the single entry where  ucfirst($entry->name_string) === $request->payment_methods
                          AND  $entry->status === 'on'        (NO __() -- see below)
4. no match -> refuse with the module's own 422 shape
5. match    -> PASS THROUGH UNCHANGED. Do not rewrite payment_methods.
```

**What it closes.** `lottery_form_data` validates `payment_methods` as `required` and nothing else.
The view's `@if ($payment_data->status == 'on')` is the only consultation of the per-store enable
row, and a POST does not go through the view. Measured: a store with **zero** `settings` rows accepts
`payment_methods=telegram` with HTTP 200 and dispatches to `processTelegram()`. Step 3's `status`
limb is the allowlist that is missing.

**What it does not do.** It changes **nothing** about which gateways work. The nine that work keep
working; the twenty-six that fail keep failing, with a clearer refusal. **No previously unreachable
payment flow is activated.**

**One behaviour change, stated plainly:** a direct POST of a raw switch label (`telegram`) is
accepted today and would be refused. That is correct — it is the forged-POST path above — and it is
still a contract change on a payment route, so it is called out rather than buried.

**Step 3 must not acquire a `__()`.** An earlier draft of this proposal specified
`ucfirst(__($entry['name_string']))` against `Setting::paymentList`. That is wrong twice: it compares
a translated label to an untranslated posted value, so **nothing would match on a non-English
storefront and every lottery payment would be refused**; and in English it computes `'Paytab'` while
the request carries `'Paytabs'`, **breaking a gateway that works today**.

### Hold for your approval: enabling the rewrite

Turning on step 5's rewrite is what makes up to 22 gateways live. Per your decision it is not done.
When you want it, the sequencing is: enable it for **one** gateway at a time from the merchant's
enabled set, excluding `phonepe` and `coingate`, and land `RestoreMidtransSelection` alongside
Midtrans — `StoreLotteryPayment` compares the session copy to `'Midtrans'` while the switch wants
`'midtrans'`, and that comparison guards the gateway's `status_code` check, which is the only thing
stopping a failed Midtrans payment writing a ticket. That interacts with #68's held question about
trusting a recorded `Paid` state, so it should land with that design rather than ahead of it.

### Not fixable from the host at all

- **PayFast's client-side flow.** `lottery-form.blade.php:397` and `:428` compare the selection to
  `'payfast'` in **JavaScript**, so the form is never retargeted at the PayFast host. A server
  middleware cannot reach a browser comparison.
- The CinetPay truncation, COD/bank-transfer support, `phonepe`'s list entry, `processCoingate`'s
  return type, and the two 500s in §4. All vendored; all belong in an upstream report.

## 6. What this document does not claim

It does not claim any of the twenty-six gateways would **work** once reachable. Reachability is the
only thing measured: a reachable gateway without credentials still fails, and nine of the twenty-six
have never executed in this installation as far as the repository's history shows. That is precisely
why activation is a decision rather than a fix.
