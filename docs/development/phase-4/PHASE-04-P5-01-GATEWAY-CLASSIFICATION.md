# P4-5 / `P04-P5-01` — the gateway classification table

*Produced 2026-11-25 by reading and executing this repository's own code. Every count below was
measured by a script over comment-stripped source, never copied from another document; where an
existing document disagrees, the disagreement is recorded in §8 rather than quietly reconciled.
Ratcheted in both directions by `tests/Feature/Commerce/GatewayClassificationInventoryTest.php`
(§7), so a new payment type with no row here, and a row here for a payment type that no longer
exists, each fail CI.*

**The requirement, verbatim** (`docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md` §6 P4-5, as
`MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` quotes it):

> the ~30 gateway controllers as a table: which verify server-side, which check amount, which have
> replay guards, which are covered by existing `Gateway*` tests … every gateway classified; none
> marked verified without evidence; gateways needing external credentials marked EXTERNALLY BLOCKED

`GAP-136`'s next action adds the sentence that decides the shape of this document: *"marking
credential-dependent gateways EXTERNALLY BLOCKED rather than untested."* **"Untested" reads as
negligence. "Externally blocked" is the honest status for a gateway whose verification needs a
provider credential nobody in this project has.** Getting that split right per gateway is the work,
and §4 states exactly which rows earn which term and why.

---

## 1. The measured count — the row's "~45" replaced

| what | count | where it was measured |
|---|---|---|
| distinct payment-type identifiers (case-folded) | **57** | union of the four first-party registries below, plus the two stored literals |
| of those, **gateway** identifiers | **50** | every identifier that names an external payment provider |
| distinct external **providers** behind those 50 | **48** | 50 minus the two providers this codebase spells twice (§2.1) |
| identifiers that are **not** a gateway | **7** | `cod`, `bank_transfer`, `whatsapp`, `telegram`, `other_payment`, `pos`, `lottery product` |

So the register's "~45 payment types" should read **57 payment-type identifiers, 50 of them
gateways, naming 48 distinct providers**. The 48 agrees with
`docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §1 ("48 external payment providers are
integrated"), which arrived at it from the other direction (52 accepted types minus four
non-providers) — two independent derivations of the same number.

### 1.1 Where the 57 come from, and the three-way agreement that confirms it

Four first-party registries, each read with PHP's own tokenizer so that **no comment can satisfy or
break the census** (`CLAUDE.md` §3; this codebase has been bitten at least five times):

| registry | count | file | what it governs |
|---|---|---|---|
| `PaymentSettingController::getPaymentGateways()` | **52** `id`s | `app/Http/Controllers/PaymentSettingController.php:630` | the admin settings screen; the `is_<x>_enabled` row and the credential fields; what the two manual-order writers store |
| `Setting::paymentList($slug)` | **53** `name`s | `app/Models/Setting.php:84` | the storefront checkout's offered list; what the checkout posts |
| `PaymentController::getValidPaymentTypes()` | **52** | `app/Http/Controllers/PaymentController.php:2584` | which posted `payment_type` values the checkout accepts at all |
| `PaymentService::process()` | **52** `case` arms | `app/Services/PaymentService.php:379` | the dispatch; an arm per provider plus the `default` refusal |

Case-folded, their union is **55**. The two values live code stores and no registry offers —
`'POS'` (`app/Http/Controllers/PosController.php:330`) and `'Lottery Product'` (vendored,
`packages/workdo/LotteryPrizes/.../LotteryResultController.php:135`) — bring it to **57**.

**The independent confirmation.** `App\Payments\PaymentTypeLabel::known()` is built from a
*different* source (hand-mirrored label maps written for F150) and answers **57**. Set-differenced
against the union above, **both directions are empty**. Two separately-maintained enumerations of
this vocabulary agree exactly, which is the strongest evidence available that the population is
complete. The ratchet in §7 asserts all three against each other.

---

## 2. How to read the table

### 2.1 Two providers are spelled twice, deliberately

`dpo` / `dpopay` and `senagepay` / `senangpay` are **one provider each**, under two spellings that
different registries use: `Setting::paymentList()` says `DPO` and `SenagePay`,
`getPaymentGateways()` says `dpopay` and `Senangpay`. Both spellings reach
`orders.payment_type` — `PaymentService` branches on `case 'DPO':` and stores
`$requests_data['payment_type']` verbatim — so both get a row. `PaymentTypeLabel`'s own docblock
records that dropping either would turn a stored row into a dash, and that which one is the typo is
an open question for the owner. **They are rows, not providers:** 50 gateway rows, 48 providers.

### 2.2 The return class — where the order is actually written

Taken from `P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §1's taxonomy and **re-measured here**, because
the class decides what a verification would have to be attached to:

| class | what happens on the way back | measured | how it was measured |
|---|---|---|---|
| **A** | the provider's own controller takes the return, judges the payment its own way, then `redirect()->route('store.payment.status', …)` into the generic writer | **11 rows / 9 controllers** | comment-stripped scan of `app/Http/Controllers/*.php` for `store.payment.status` |
| **B** | `PaymentService` handed the provider `route('store.payment.status', …)` directly, so the browser or the gateway lands on the generic writer with no gateway-owned code in between | **32 rows** | the remainder |
| **C** | the gateway returns to a handler that reads the stored checkout state and writes the order itself | **7 rows** | the files containing `storedCheckoutState(` — `AuthorizeNet`, `CinetPay`, `Easebuzz`, `Khalti`, `NMI`, `PaiementPro`, `PaymentController` |
| **D** | no gateway: `cod`, `bank_transfer`, `whatsapp`, `telegram` | **4 rows** | — |
| **—** | not a storefront checkout choice at all | **3 rows** | `other_payment` (`Models/Setting.php:124` hard-codes `status = 'off'` and `getValidPaymentTypes()` refuses it), `pos`, `lottery product` |

11 + 32 + 7 + 4 + 3 = **57**.

### 2.3 The one control that decides almost every row

There is **no per-gateway verification** on the storefront order arm. There is one central control:

```
app/Services/PaymentVerificationService.php:339
    if ($verification === null) { … return self::STATUS_PENDING; }
```

A gateway order is recorded `Paid` only if some code path called `recordVerification()` for that
payment type in the same session, bound to the same checkout nonce. **Comment-stripped, `app/`
contains exactly five `recordVerification()` call sites, naming four payment types:**

| site | type |
|---|---|
| `app/Http/Controllers/PaymentController.php:733` | `paymera` |
| `app/Http/Controllers/PaymentController.php:1689` | `paymera` |
| `app/Http/Controllers/NMIPayController.php:348` | `nmi` |
| `app/Http/Controllers/KhaltiPaymnetController.php:1055` | `khalti` |
| `app/Http/Controllers/AuthorizeNetPaymnetController.php:1206` | `authorizenet` |

Everything else resolves to `Pending`. That is a deliberate owner decision (Phase 01 final execution
directive, quoted at `PaymentVerificationService.php:50-60`), **not** a verification. The order is
still written, so a charged customer has a record — which is the other half of the same decision.

### 2.4 The classification terms

| term | means | members |
|---|---|---|
| **VERIFIED** | a capture-verification path exists for this type **and** a test drives that path to a `Paid` order | 3 |
| **GUARDED, UNVERIFIED** | a verification path exists, no test reaches it, and nothing external prevents one | **0** |
| **EXTERNALLY BLOCKED** | a verification path exists but cannot be driven without a provider credential or sandbox | 1 |
| **RETIRED** | `App\Payments\RetiredGateways` already answers for it; the provider cannot be offered | 2 |
| **NO VERIFICATION PATH** | no `recordVerification()` path names this type, so nothing establishes that money moved | **44** |
| **NO GATEWAY** | not a gateway at all: COD, an offline arrangement, a relay, or a stored literal | 7 |

**`GUARDED, UNVERIFIED` has zero members, and that is a measurement, not an oversight.** All four
types with a verification path are accounted for: three are driven by tests, and the fourth
(`authorizenet`) cannot be driven as the code stands. The term is kept in the table's vocabulary and
asserted by the ratchet precisely so that the *next* gateway to gain a verification path without a
test lands in it visibly instead of being filed as verified.

**A sixth term was added, and this is the disclosure.** `GAP-136` names five. `NO VERIFICATION PATH`
is literally true of `cod` — nothing verifies a capture — but it reads as "a gateway whose capture
goes unchecked", which `cod` is not. Forcing seven non-gateway rows into that term would make the
most important number in this document (44) wrong by seven and would describe cash in a courier's
hand as an unverified gateway capture. `NO GATEWAY` keeps the five gateway terms exact.

---

## 3. THE TABLE

Column notes, so nothing here is read as more than it is:

- **what decides it** — the `file:line` that produces this row's classification. For the four types
  with a verification path it is the `recordVerification()` call; for the retired two it is the
  list; for every other gateway it is the central `$verification === null` branch, because that is
  genuinely where the decision is made.
- **amount reconciled** — whether the captured amount is compared against the order total, i.e.
  membership of `PaymentVerificationService::ENFORCED_PAYMENT_TYPES`
  (`app/Services/PaymentVerificationService.php:65`).
- **replay claim** — the `file:line` of the claim that stops one payment producing two orders for
  *this* row's writer. Class A and B share the generic writer's
  `claimGatewayReturn($request, 'storefront-return', …)`; class C each have their own; `cod` and
  `bank_transfer` are claimed on the way *out* of the checkout by `processOrder`'s basket-fingerprint
  `Cache::add` (P4-F018's fix), not by a gateway-return claim, because there is no gateway return.
- **own check on the order return** — whether the gateway's own code asks the provider anything on
  the storefront return. Several do. **None of them records the answer**, so the order is `Pending`
  either way; "answer discarded" is the precise and uncomfortable statement.
- **test** — the test that drives the classification. For a `VERIFIED` row it is a test that reaches
  a `Paid` order; for the rest it is the test that pins the `Pending` outcome.
- **verification blocker** — what a stronger status would require. `first-party` means **no
  credential is needed**: the provider is already being asked and the answer is thrown away, so the
  work is ours. Otherwise the provider's own verify/status endpoint plus the settings keys named,
  whose exact list per provider is `P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §4.

| # | payment type | kind | class | classification | what decides it (`file:line`) | amount reconciled | replay claim (`file:line`) | own check on the order return | test | verification blocker |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `aamarpay` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `aamarpay` keys |
| 2 | `authorizenet` | gateway | C | EXTERNALLY BLOCKED | `AuthorizeNetPaymnetController.php:1206` | no | `AuthorizeNetPaymnetController.php:280` | — | AuthorizeNetCredentialFailureTest (refusal only) | sandbox authorizenet_login_id + authorizenet_transaction_key (E2) |
| 3 | `bank_transfer` | offline | D | NO GATEWAY | `PaymentVerificationService.php:303` | no | `PaymentController.php:2169` | — | PaymentStatusDerivationTest:165 | — |
| 4 | `benefit` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `benefit` keys |
| 5 | `braintree` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | BraintreeController.php:197 provider call, answer discarded | UnverifiedGatewayStaysPendingTest | first-party: provider answer discarded |
| 6 | `cashfree` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `cashfree` keys |
| 7 | `cinetpay` | gateway | C | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `CinetPayController.php:455` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `cinetpay` keys |
| 8 | `cod` | cash on delivery | D | NO GATEWAY | `PaymentVerificationService.php:265` | no | `PaymentController.php:2169` | — | CodStateMachineTest | — |
| 9 | `coingate` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `coingate` keys |
| 10 | `cybersource` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | CyberSourceController.php:296 provider call, answer discarded | UnverifiedGatewayStaysPendingTest | first-party: provider answer discarded |
| 11 | `dpo` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | DPOPayController.php:521 request field only | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `dpopay` keys |
| 12 | `dpopay` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | DPOPayController.php:521 request field only | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `dpopay` keys |
| 13 | `easebuzz` | gateway | C | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `EasebuzzController.php:316` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `easebuzz` keys |
| 14 | `esewa` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `esewa` keys |
| 15 | `fedpay` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `fedpay` keys |
| 16 | `flutterwave` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `flutterwave` keys |
| 17 | `iyzipay` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `iyzipay` keys |
| 18 | `khalti` | gateway | C | VERIFIED | `KhaltiPaymnetController.php:1055` | yes | `KhaltiPaymnetController.php:332` | — | KhaltiAmountBindingTest:207 + GatewayStockBeforeVerificationTest:371 | test khalti_public_key/khalti_secret_key (E3; provider half only) |
| 19 | `lottery product` | off-gateway literal | — | NO GATEWAY | `PaymentVerificationService.php:303` | no | `—` | — | PaymentStatusDerivationTest:260 | — |
| 20 | `mercado` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `mercado` keys |
| 21 | `midtrans` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `midtrans` keys |
| 22 | `mollie` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `mollie` keys |
| 23 | `myfatoorah` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | MyFatoorahController.php:115 provider call, answer discarded | UnverifiedGatewayStaysPendingTest | first-party: provider answer discarded |
| 24 | `nepalste` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `nepalste` keys |
| 25 | `nmi` | gateway | C | VERIFIED | `NMIPayController.php:348` | yes | `NMIPayController.php:322` | — | UnguardedOrderWriterTrustBoundaryTest:404 | live nmi_api_private_key (provider half only) |
| 26 | `other_payment` | not selectable | — | NO GATEWAY | `Models/Setting.php:124` | no | `—` | — | RetiredProviderSurfaceTest:243 | — |
| 27 | `ozow` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | OzowController.php:302 request field only | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `ozow` keys |
| 28 | `paddle` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `paddle` keys |
| 29 | `paiementpro` | gateway | C | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaiementProPaymentController.php:399` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `paiementpro` keys |
| 30 | `payfast` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `payfast` keys |
| 31 | `payhere` | gateway | B | RETIRED | `RetiredGateways.php:63` | no | `PaymentController.php:3701` | — | RetiredProviderSurfaceTest | — |
| 32 | `paymentwall` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `paymentwall` keys |
| 33 | `paymera` | gateway | C | VERIFIED | `PaymentController.php:733, :1689` | yes | `PaymentController.php:696` | — | PaymentStatusDerivationTest + PaymeraCallbackVerificationTest + PaymeraStatusPollTest | GATE G(4): live signature field, algorithm, secret, captured-amount field |
| 34 | `paynow` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | PaynowController.php:228 provider call, answer discarded | UnverifiedGatewayStaysPendingTest | first-party: provider answer discarded |
| 35 | `paypal` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `paypal` keys |
| 36 | `paystack` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `paystack` keys |
| 37 | `paytabs` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `paytabs` keys |
| 38 | `paytm` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `paytm` keys |
| 39 | `paytr` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `paytr` keys |
| 40 | `payu` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | PayUPaymentController.php:204 request field only | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `payu` keys |
| 41 | `phonepe` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | PaymentController.php:3469 provider call, answer discarded | UnverifiedGatewayStaysPendingTest | first-party: provider answer discarded |
| 42 | `pos` | off-gateway literal | — | NO GATEWAY | `PaymentVerificationService.php:303` | no | `—` | — | PaymentStatusDerivationTest:260 | — |
| 43 | `powertranz` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | PowertranzPaymentController.php:413 provider call, answer discarded | UnverifiedGatewayStaysPendingTest | first-party: provider answer discarded |
| 44 | `razorpay` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `razorpay` keys |
| 45 | `senagepay` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | SenangPayController.php:122 request field only | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `Senangpay` keys |
| 46 | `senangpay` | gateway | A | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | SenangPayController.php:122 request field only | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `Senangpay` keys |
| 47 | `skrill` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `skrill` keys |
| 48 | `sofort` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `sofort` keys |
| 49 | `sslcommerz` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `sslcommerz` keys |
| 50 | `sspay` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `sspay` keys |
| 51 | `stripe` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `stripe` keys |
| 52 | `tap` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | PaymentController.php:3501 provider call, answer discarded | UnverifiedGatewayStaysPendingTest | first-party: provider answer discarded |
| 53 | `telegram` | relay | D | NO GATEWAY | `PaymentVerificationService.php:339` | no | `PaymentController.php:5268` | — | GatewayClassificationInventoryTest | — |
| 54 | `toyyibpay` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | PaymentController.php:3487 request field only | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `toyyibpay` keys |
| 55 | `whatsapp` | relay | D | NO GATEWAY | `PaymentVerificationService.php:303` | no | `PaymentController.php:5268` | — | PaymentStatusDerivationTest:165 | — |
| 56 | `xendit` | gateway | B | NO VERIFICATION PATH | `PaymentVerificationService.php:339` | no | `PaymentController.php:3701` | — | UnverifiedGatewayStaysPendingTest | provider verify endpoint + `Xendit` keys |
| 57 | `yookassa` | gateway | B | RETIRED | `RetiredGateways.php:63` | no | `PaymentController.php:3701` | — | RetiredProviderSurfaceTest | — |

---

## 4. The tally, and the four gateways that have a verification path at all

| classification | rows |
|---|---|
| NO VERIFICATION PATH | **44** |
| NO GATEWAY | 7 |
| VERIFIED | 3 |
| RETIRED | 2 |
| EXTERNALLY BLOCKED | 1 |
| GUARDED, UNVERIFIED | 0 |
| **total** | **57** |

Of the **50 gateway** rows: 44 + 3 + 2 + 1 = 50.

### 4.1 `paymera` — VERIFIED, with a live-configuration blocker that must not be read as verified

The only first-party gateway, and the only one with a real callback, a server-to-server status poll,
inbound signature verification and amount enforcement. All three legs are driven:

- the capture comparison — `PaymeraCallbackVerificationTest` (10 cases, including a mismatched
  amount, an unreadable amount failing closed, and the configured amount field being authoritative);
- the signature scheme — the same file, verified and rejected against a *test* secret;
- the status poll — `PaymeraStatusPollTest` (9 cases over `Http::fake()`, including an unconfigured
  credential making **no call at all** and returning a sentinel no call site can mistake for a
  capture);
- the `Paid` derivation — `PaymentStatusDerivationTest::test_a_matching_verification_yields_paid`,
  with `test_the_amount_comparison_is_exact` proving the comparison is not float-sloppy.

**What is NOT established, and the distinction is the whole point of this document.** Paymera's real
signature field name, its algorithm, the delivery of its secret, and the response field carrying the
captured amount exist only in Paymera's own integration document. `config/services.php`
(`require_callback_signature`, default **true**) therefore makes `verifyPaymeraSignature()` return
UNVERIFIABLE and **every live Paymera callback is rejected today**. So: the *mechanism* is verified;
the *production configuration* is externally blocked on GATE G(4) / GATE 20. No test drives a
Paymera order to `Paid` through the real route, and this document does not claim one does.

### 4.2 `nmi` — VERIFIED, and the strongest row in the table

`NMIPayController::getNMIProductStatus` does not receive a return; it **makes the charge**, with the
amount taken from `serverPayableAmount()` (the stored state's server-computed total), and records
NMI's own `response == 1`. `nmi` is amount-enforced, so the figure sent and the figure written are
compared. Driven end to end over the real route
(`ANY {slug}/get-payment-NMI`) with `NMIService` substituted in the container:
`UnguardedOrderWriterTrustBoundaryTest.php:404` asserts `payment_status === 'Paid'` on a legitimate
charge, and asserts the gateway was actually reached first so the control cannot pass vacuously.

### 4.3 `khalti` — VERIFIED, and the one row where enrolling the type was a correction

`KhaltiPaymnetController` resolves `App\Khalti\Khalti` **from the container**
(`app(Khalti::class)`), which is the only reason the path is drivable at all —
`P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §5 still records it as having "no seam and no
`Http::fake()`", which that document's §6 item 3 asked for and which has since landed. Driven to
`Paid` by `KhaltiAmountBindingTest.php:207` and
`GatewayStockBeforeVerificationTest.php:371`, and refused on a mismatch by the same files.
`khalti` is amount-enforced because the verified figure and the written figure are computed
separately: the verify call is bound to the stored state's `cartlist_final_price`, while
`$order->final_price` is recomputed from a **fresh cart read** at return time
(`PaymentVerificationService.php:65`'s own comment records the basket-swap this closes).

### 4.4 `authorizenet` — EXTERNALLY BLOCKED, and precisely why

The verification path exists and is the second-strongest in the codebase: the handler *builds* the
`authCaptureTransaction`, sets its amount from `serverPayableAmount()`, and gates on Authorize.Net's
own approved/declined distinction (`getResultCode() == "Ok"` **and** a non-null
`$tresponse->getMessages()`; a decline populates `getErrors()` and leaves `getMessages()` null).

It cannot be driven. `AuthorizeNetPaymnetController.php:495` constructs
`new AnetController\CreateTransactionController($requestNet)` **inline**, and `:498` / `:502` execute
it against `ANetEnvironment::PRODUCTION` or `SANDBOX`. There is no injected service and no `Http`
facade call, so no test can answer for the SDK. Driving it needs a reachable Authorize.Net sandbox
and the store's `authorizenet_login_id` + `authorizenet_transaction_key` — item **E2** of
`P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §7.1. The only test on this handler,
`AuthorizeNetCredentialFailureTest`, drives the **refusal** (a store with no credentials is refused
rather than crashing) and writes no order.

**Stated plainly, because it is the difference between EXTERNALLY BLOCKED and GUARDED, UNVERIFIED:**
the blocker is the *current code shape* plus a credential, not nature. Extracting the SDK seam the
way `NMIService::chargeSale()` already is — `P01-ORD-13` §6 item 2, explicitly behaviour-preserving —
would move this row to `GUARDED, UNVERIFIED` and then, with a test, to `VERIFIED`, with no provider
involved. That is first-party work in `app/`, out of scope for this artefact.

### 4.5 The 44 — what "NO VERIFICATION PATH" does and does not mean here

It means: **nothing on the path establishes that money moved**, so the central control writes
`Pending` and no merchant wallet is credited (`OrderObserver::created()` credits only an order
already `Paid`). It does **not** mean a forged return mints a paid order — it cannot, and that is the
one thing standing between these 44 and that outcome.

Within the 44 there is a split worth acting on, and it is in the **own check** column:

- **Seven rows already ask the provider and throw the answer away** — `braintree`, `cybersource`,
  `myfatoorah`, `paynow`, `powertranz` (class A), and `phonepe`, `tap` (the generic writer's own
  provider-specific blocks), measured as live outbound calls in comment-stripped source. The answer
  gates an early return and never reaches `recordVerification()`. **No credential is needed to
  improve these**; the work is first-party.
- **`cybersource` is the one of those seven that needs a correction rather than a wiring**, and the
  correction is not this artefact's to make. P4-F048's reviewer measured that the storefront arm has
  **never worked**: the honest initiator supplies no `store_id`, so `CyberSourceController.php:303`
  raises an uncaught `ErrorException` *above* the `try`, and the three credential names the handler
  reads are written nowhere in the repository (the product persists `cybersource_pay_*`), so the call
  it would make cannot authenticate. P4-F048 and P4-F049 are both recorded **REFUTED as distinct
  Phase 04 packages** -- not refuted as facts -- because
  `docs/development/phase-2/E14-GATEWAY-MATRIX-2026-10-31.md` already holds them. This row is
  `NO VERIFICATION PATH` on the same basis as the other 43: nothing establishes that money moved.
- **Seven rows judge a request field only** — `dpo`/`dpopay`, `ozow`, `payu`,
  `senagepay`/`senangpay` (class A, six rows for four providers) and `toyyibpay`, which branches on
  `$request->status_id` on the generic writer. This confers nothing: they redirect to, or are, the
  generic writer, which demands the caller's own server-stored checkout state, takes a replay claim,
  and writes `Pending`.
- **The remaining 30 rows** have no gateway-owned code on the return at all (7 + 7 + 30 = 44). For
  these, obtaining a sandbox credential **does not by itself make the return verifiable**: there is nowhere
  to put the answer. A verification would be new first-party code *and* a credential.

---

## 5. What a stronger status would cost, per blocker

Grouped from the table's last column; the external items keep `P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md`
§7.1's identifiers so the two documents cannot drift into different vocabularies.

| blocker | rows | who can clear it |
|---|---|---|
| GATE G(4) — Paymera's live signature field, algorithm, secret delivery and captured-amount field | 1 (`paymera`) | **owner**, one message to one provider. Not a sandbox account |
| **E2** — Authorize.Net sandbox login id + transaction key | 1 (`authorizenet`) | owner; or **first-party**, by extracting the SDK seam |
| **E3** — Khalti test keys and a test verify host | 1 (`khalti`) — provider half only; the writer is already driven | owner |
| live `nmi_api_private_key` | 1 (`nmi`) — provider half only; the writer is already driven | owner |
| **first-party: the provider answer is discarded** | 7 | **nobody external.** This is engineering work in `app/` |
| provider verify/status endpoint + that gateway's settings keys | 37 | owner (credential) **and** first-party (the integration has nowhere to put an answer) |
| — (retired, or not a gateway) | 9 | n/a |

**Nothing in the 57 is "untested" in the sense of neglected.** Every row has a test that pins its
actual outcome; what 45 of them lack is a *provider-side capture verification*, and for 38 of those
the first obstacle is a credential this project does not hold.

---

## 6. What this table does not claim

- **No journey was driven against a real gateway.** Every assertion behind this table is a
  stored-state drive, a substituted service, an `Http::fake()`, or a source-structural measurement.
  That is `GAP-137`'s open half and this document does not close it.
- **Whether any provider offers a sandbox, and on what terms, is NOT VERIFIED** — no provider
  documentation was read and nothing in this container can reach one. The blocker column says what
  *this application* would need, not what the provider offers.
- **The plan-purchase arm is a different question with different controls** and is not in this
  table. Phase 06 owns it; `config/payments.php` holds its classification (26 fail-closed providers,
  18 recorded provider-verified, one platform-approved) and five test files already ratchet it.
  A provider that is `NO VERIFICATION PATH` here may be `provider_verified` there, because the two
  arms run different code.
- **IT IS NOT THE ONLY GATEWAY MATRIX, AND THE TWO COVER DIFFERENT ARMS.**
  `docs/development/phase-2/E14-GATEWAY-MATRIX-2026-10-31.md` holds one row per **plan-purchase**
  return for nineteen gateways; its own header states that none of the nineteen writes a customer
  order and that fifteen of its rows are an agent's reading of source that was never independently
  re-verified. This table is the **storefront order** arm, covers all 57 payment types, and every
  cell in it is re-derived by the ratchet. Neither supersedes the other: a reader asking "may this
  provider's evidence activate a paid plan" wants E14 and `config/payments.php`; a reader asking
  "may this provider's evidence mark a customer order Paid" wants this one. `P04-P5-01`'s gap text
  said "no census document exists under docs/ for Phase 04", which was true of the order arm and
  was never true of the plan arm.
- **`packages/workdo/` was not re-censused.** A provider that exists only in a module and never
  reaches `PaymentService::process()` would appear in none of the four registries, and no module
  loads in a test run without an `add_on_managers` row.

---

## 7. The ratchet

`tests/Feature/Commerce/GatewayClassificationInventoryTest.php`. It re-derives every fact from the
code at run time and compares it with this document **in both directions**, so the table cannot rot
silently. What it asserts:

1. **The row set equals the code's payment-type set, both ways.** A new payment type in any of the
   four registries with no row here fails; a row here for a type no registry knows fails. The code
   side is re-derived three ways — the four registries, and `PaymentTypeLabel::known()` — and all
   three must agree with each other too, so a drift between the registries and the label map is also
   a failure.
2. **Every classification term is one of the six**, and the counts in §4 match the rows.
3. **`RETIRED` is exactly `RetiredGateways::PROVIDERS`**, both ways.
4. **`amount reconciled = yes` is exactly `ENFORCED_PAYMENT_TYPES`**, both ways.
5. **A verification path exists for exactly the rows that claim one.** The `recordVerification()`
   call sites are re-censused from comment-stripped source; `VERIFIED`, `GUARDED, UNVERIFIED` and
   `EXTERNALLY BLOCKED` require one, and `NO VERIFICATION PATH` requires there to be none.
6. **Each cited `file:line` still holds what the row says it holds** — a `recordVerification(` naming
   that type, a `claimGatewayReturn(` with that label, or the central branch — read comment-stripped
   and scoped to that line, never to the file.
7. **The offline and COD carve-outs match the constants**, both ways.
8. **Every test named in the table exists on disk.**
9. **The derivation is executed, not read**: the service is called for a representative of each
   term and the resulting status is asserted, so a row cannot describe a behaviour the code no
   longer has.
10. **Every settings id a blocker names is a real one** in `getPaymentGateways()`, because the ids
    are not the folded payment type (`dpo` is stored under `dpopay`, `senagepay` under `Senangpay`)
    and a row naming a non-existent one sends a reader to a settings block that is not there.
11. **The non-selectable rows really are not selectable** -- `other_payment`, `pos` and
    `lottery product` appear in neither `getValidPaymentTypes()` nor the dispatch switch.

**Scoped and comment-stripped throughout.** Several of these payment files carry long docblocks
naming *other* gateways — `PaymentVerificationService`'s own comments name `nmi`, `khalti`, `pos`
and `cod`; `EnforcedPaymentTypesIntegrityTest`'s docblock names `NMIPayController.php:605` — so
every census uses `Tests\Support\StripsSourceComments::sourceWithoutComments()` and every
`file:line` assertion reads **that one line**, not the file.

---

## 8. Defects and documentation errors this census found, and did not fix

Each belongs on its own row; none is fixed here, because `GAP-136` is an artefact plus its ratchet
and `app/` was not touched.

1. **`telegram` orders silently became `Pending` when the default inverted, and nothing records it.**
   Measured by executing the derivation: `whatsapp` → `Paid`, `telegram` → **`Pending`**. Both are
   relays with no payment API, both are written by the *same* writer
   (`PaymentController::whatsapp`, route `POST {slug}/telegram`), and
   `OFFLINE_PAYMENT_TYPES` lists `whatsapp` but not `telegram`. Before the no-unverified-`Paid`
   inversion every unenforced type resolved `Paid`, so the asymmetry was invisible; the constant's
   own docblock records that `pos` and `lottery product` were added *for exactly this reason* and
   `telegram` was missed. A merchant taking orders over Telegram now has every order sit unpaid. It
   is a business decision (it governs when a wallet is credited), so it is reported, not changed.
2. **`findings/payments-gateways.md` mis-attributes the fifth verification path to PaiementPro.** It
   says "only five code paths produce such a record (Paymera, AuthorizeNet, Khalti, NMI,
   PaiementPro)". There are five paths, but they name **four** types: Paymera has two
   (`PaymentController.php:733` and `:1689`). `PaiementProPaymentController` records **nothing** —
   its own 70-line comment at `:280-357` explains at length that it deliberately does not, and the
   handler logs *"PaiementPro return recorded PENDING: this gateway is not provider-verified"*. The
   derived claim "43 of 48 providers write Pending orders" is therefore one short: it is **44 of
   50 rows / 44 of 48 providers**.
3. **The same document says "only `bank_transfer`/`whatsapp`/`telegram` are Paid on arrangement".**
   `telegram` is not; `pos` and `lottery product` are. The constant has four members and the
   sentence names three, one of them wrong.
4. **`P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` has drifted and now mis-points a reader.** Its §1
   cites `getValidPaymentTypes` at `PaymentController.php:1563-1574` (now `:2584`),
   `getPaymentGateways` at `:183-447` (now `:630`), the generic writer at `:2138` (now `:3274`), and
   `ENFORCED_PAYMENT_TYPES` at `PaymentVerificationService.php:60-62` with "exactly one entry,
   `'paymera'`" (now `:65`, three entries). Its §5 still says Khalti has "no seam and no
   `Http::fake()`", which its own §6 item 3 asked for and which has since landed. The *reasoning* is
   intact; the anchors are not, and it is the document a procurement request would be built from.
5. **`cybersource` makes the outbound call and discards the answer** (`CyberSourceController.php:296`
   handler, discarded at `:365`). Already recorded in the Phase 04 findings register; restated here
   because the table's own column makes it visible, and because it is one of seven rows where the
   improvement needs **no** credential.

---

## 9. Provenance

- Enumeration, call-site censuses and class assignment: scripts over comment-stripped source
  (PHP's tokenizer), run 2026-11-25 in the development container (PHP 8.3).
- The derivation outcomes in §8 item 1 and §2.3 were obtained by **booting the application and
  calling `resolveOrderPaymentStatus()`**, not by reading it.
- Return-class taxonomy: `docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §1, re-measured
  here (§2.2); credential requirements: the same document's §4 and §7.1.
- Existing per-gateway controls: `docs/development/phase-4/findings/payments-gateways.md` and
  `payments-core.md`, with the three corrections in §8.
- `app/` was not modified. No `git` command was run.
