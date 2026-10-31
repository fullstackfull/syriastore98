# F229 — the permanent fail-closed fix, scoped against D-31

**Status: NOT STARTED. Scoped here, with the preconditions measured, so the next session starts
from evidence rather than from the owner's prose.**

D-31 (2026-10-31) rejected log-only as the final solution and instructed a permanent, proven,
fail-closed fix, within development/test-only permissions. This file is the engineering reading of
those ten requirements — **what exists, what has to be built, and which requirement cannot be met
for which provider and why.** It invents no design the owner has not asked for.

---

## 1. What already exists, measured

| mechanism | where | state |
|---|---|---|
| server-owned price | `GuardsPlanActivation::serverOwnedPlanPrice()` | **exists**, used by the repaired handlers |
| replay refusal on a provider reference | `GuardsPlanActivation::refusePlanActivationReplay()` + `existingPlanOrderForProviderReference()` + `planProviderTypeSpellings()` | **exists**, generalised from Paystack in F228 |
| fail-closed provider map | `config/payments.php` `plan_activation.*`, `assertPlanActivationEnabled()` | **exists**, 26 providers, disabled by default |
| credential presence refusal | `GuardsPlanActivation::assertProviderCredentialsPresent()` | **exists** (added by F234) |
| the Paystack reference implementation | `PaystackPaymentController:425-467` | **exists** — status, reference, **amount and currency** all matched, mismatch REFUSES |

## 2. What does not exist and has to be built

**Requirement 1 — the server-owned payment intent. This is the whole of the new build.**

Measured: `grep -rln "plan_purchase_intent\|PlanPurchaseIntent" app/ database/` returns **nothing**.
(`PaymentIntent` does appear three times — `Api/ApiController.php:7719`, `:7841`, `:7906` — but
those are Stripe's own API object on a **storefront order** path, a different surface and a
different defect.)

So there is no persisted record binding *purchaser + plan + expected payable amount + currency +
discount + provider transaction reference*. Requirement 4's "binding to the original server-owned
purchase intent" therefore **cannot be satisfied by anything that exists today**, and requirement 1
is the prerequisite for 4.

It is a **new table plus a service**, and under D-15 condition 9 / D-31's scope the migration is
**development and test only** — the same constraint `stock_movements` carries, with the same
consequence: in production the mechanism is absent and must fail *open* in the sense of changing
nothing, never fail *closed* in a way that refuses real purchases on an installation where the
table was never created. That interaction is the single most important design question and it is
**not yet answered**.

**Requirement 5's recovery clause.** No pending-review state exists anywhere:
`grep -rn "pending.review\|pendingReview\|PENDING_REVIEW\|needs_review" app/` returns **zero**. And
`RefundIntegrityService::hasGatewayRefundDriver()` is **still `return false;`** (`:119-121`), so
D-15 condition 6 refuses *every* collected payment, not a subset. Requirement 9 forbids new refund
or settlement behaviour, so "safe recovery when payment succeeds but entitlement assignment fails"
means **a durable, reviewable record** — the plan-side twin of F186's captured-payment-without-order
— and explicitly **not** an automatic refund.

## 3. Requirement 3 is the gate on everything, and the evidence is partial

> *"Build provider-specific amount normalization based on verified API contracts and real sandbox
> evidence. Never guess minor/major unit conversions or use imprecise floating-point comparisons."*

**What is measured (send side), without any sandbox.** All five providers send the amount
server-side and **none multiplies it** — each derives `$plan->price` minus a percentage discount and
sends it unmultiplied:

| provider | outbound amount | multiplier |
|---|---|---|
| Benefit | `BenefitPaymentController:96` `"amount" => $get_amount` | none (`:32`, `:40`) |
| Cashfree | `CashfreeController:140` `'order_amount' => $get_amount` | none (`:70`, `:77`) |
| Paytm | `PaytmPaymentController:142` `'amount' => $price` | none (`:53`, `:62`) |
| Mollie | `MolliePaymentController:135` `"amount" => [ … ]` | none (`:55`, `:63`) |
| Flutterwave | the inline SDK this server renders, `plans/stripe.blade.php:3364` `amount: res.total_price` | none |

Every `/100` in those files is percentage-discount arithmetic, not unit conversion. And the same
blade proves the conventions genuinely **differ per provider**: `:3228` Paystack `* 100` and
`:3275-3280` Razorpay `* 100` are minor units, while `:3364` Flutterwave is major.

**What is NOT measured (report side), and this is the blocker.** Knowing what we *send* does not
establish what each provider *reports* on verify. D-17's Paystack case had the same structure and
the review accepted send-side corroboration — but there send was `* 100` and the verify reports
minor, i.e. consistent. Here send is major, and whether each verify reports major is **unmeasured
for all five**.

Requirement 3 asks for the **verified response contract**, which the send side is not. So the
measurement above **narrows** the work; it does not discharge it.

**Requirement 8 is therefore the operative rule, not an escape hatch:** a provider whose verified
response contract cannot be established is marked **BLOCKED** and its paid activations fail closed.

## 4. Per-provider readiness, and the honest answer for each

| provider | verify call reachable in-repo? | report-side contract | requirement 8 verdict today |
|---|---|---|---|
| **Flutterwave** | yes, legacy rave v2 at `FlutterwaveController:161` | **UNKNOWN — this is F231.** Three spellings in play; the `data.chargeResponseCode` this repo reads comes from the inline SDK's `response.tx`, **not** the verify envelope | **BLOCKED** |
| **Benefit** | `BenefitPaymentController` — 1 outbound site, raw Guzzle | gate is `$data->gateway->response->code == '00'`, a property of the tap charge; amount field unverified | **BLOCKED pending sandbox** |
| **Cashfree** | 3 outbound sites, raw Guzzle | gate is `$info->payment_status == "SUCCESS"`; amount field unverified | **BLOCKED pending sandbox** |
| **Mollie** | 2 sites, Mollie SDK | SDK-typed; `amount->value` is a decimal string in major units *by the SDK's documented contract* — the closest to establishable without a sandbox, but still to be confirmed against a captured response | **BLOCKED pending capture** |
| **Paytm** | 0 `Http::`/Guzzle sites — verification is **local and cryptographic** (`PaytmWalletProvider::response()` AES-verifies `CHECKSUMHASH`) | `TXNAMOUNT` is inside the checksummed body, so it is **provider-attested without a network call** | **best candidate to go first** |

**Paytm is the one that can be done now**, and that is a finding rather than a preference: its
amount arrives inside a body the server cryptographically verifies against its own merchant key, so
the amount is attested without a sandbox. The plan, however, is **not** in that body —
`ReceivePaymentProvider::beginTransaction()` drops the `plan` key — which is exactly why
requirement 1's intent record is needed to bind them.

**Note also F232:** with no merchant key configured, Paytm verifies the checksum against the empty
string, which a caller knows equally well. That is already fixed (fail-closed refusal), and the
intent work must not regress it.

## 5. Requirement 3's floating-point clause, concretely

`plans.price` is read as a float in every one of these handlers, and `serverOwnedPlanPrice()`
returns `float`. A `==` or `!=` on two floats is exactly what the requirement forbids. The
comparison must be done on **integer minor units** or via a **scaled string compare** (`bccomp`
with an explicit scale), decided per provider from its verified contract — never by casting both
sides to float and testing equality.

## 6. Proposed order of work, with the reason for the order

1. **Requirement 1 — the intent record.** Nothing else can be built on sand; requirement 4's
   binding has no subject until this exists. Answer the production-absence question first.
2. **Paytm end to end**, as the one provider whose amount is attested without a sandbox. It proves
   the intent + binding + comparison design against a real, cryptographically verified body.
3. **Requirement 5's recovery record** — the captured-payment-without-entitlement twin. No refund
   behaviour (requirement 9), no automatic cancellation.
4. **The remaining four**, each only when its verified response contract exists; **BLOCKED** with
   its reason until then (requirement 8), with the compatibility impact of fail-closed handling
   documented as requirement 8 demands.
5. **Independent security review and the full gates** before F229 may be called complete
   (requirement 10).

## 7. What must not happen

- **No log-only as the remediation.** D-31 demotes it to an auxiliary development/sandbox
  measurement and forbids presenting or deploying it as the fix.
- **No guessed unit conversion**, and no float equality.
- **No new refund or settlement behaviour**, and no automatic refund or cancellation of a captured
  payment (requirement 9, and D-15 condition 3 which separates reversing a merchant's balance from
  a real refund to a customer).
- **No production migration, deployment, environment change or merge to `main`.** D-31 grants
  development/test-only authorisation and restates the prohibitions.
- **No claim that a provider is covered because its send side is known.** Requirement 6 says
  "covered"; requirement 3 says what that means.

## 8. One cheap thing the owner could supply that unblocks two findings at once

**One captured, redacted Flutterwave verify response body.** Field *names* are what is needed, not
values. It settles F231's gate, Flutterwave's arm of requirement 3, whether `data.txid` can key a
replay guard (today `:208` falls back to `$pay_id`, the caller's own path parameter, which cannot),
and whether Paystack's tolerate-empty-currency rule transfers. One capture, four answers.
