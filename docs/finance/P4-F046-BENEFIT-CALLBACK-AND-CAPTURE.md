# P4-F046 — the Benefit callback destination, and capture verification

**Owner decision:** *remove webhook.site, verify capture server-side, narrow the catch, mark
EXTERNALLY BLOCKED if provider verification unavailable.*

Four clauses. **Three are done.** The fourth is written up here with exactly what blocks it.

---

## 1. The public bin is gone from first-party code

`post.url` in a Tap charge body is the provider's **server-to-server notification destination**.
Both first-party Benefit charges pointed it at `https://webhook.site/fd8b0712-…` — a **public bin
whose URL was committed in this repository and is in git history**.

| site | rail | what the bin would have received |
|---|---|---|
| `PaymentService::processBenefit()` | **storefront**, a real shopper buying goods | amount, currency, the first product's name, the customer's first name, surname and email, the store slug, and the **charge id this platform's return paths treat as proof of payment** |
| `BenefitPaymentController` | **SaaS plan**, a merchant buying a subscription | amount, currency, the plan name, the merchant's own name and email — and, because the sibling `redirect.url` carries them, the complete **replay tuple** `plan_id` + `amount` + `coupon`, in the same payload as the charge id |

Both now send `"post" => ["url" => null]`.

**That shape is not invented.** `processTap`, **in the same file**, for the **same provider** and the
**same endpoint**, already ships `'post' => ['url' => null]`. So the bin is removed without choosing
a new destination — and choosing one is the separate open owner decision **P1-SEC-55**, which this
change deliberately does not pre-empt.

**Nothing is lost by sending `null`.** No route in this repository receives a Tap server-to-server
callback; measured, the only credential-authenticated provider-callback receiver is the delivery
one. The shopper's own return is `redirect.url`, which is unchanged.

### 1.1 Three vendored copies remain, and must not be edited

`packages/workdo/{Campaigns,Donation,LotteryPrizes}` each carry the same bin. CLAUDE.md forbids
editing vendored code, so they are **reported, not fixed**:

- None is route-registered in this container — `add_on_managers` holds **zero rows**, so
  `loadDynamicAddons()` registers nothing and a 1476-route dump contains no Campaigns, Donation or
  LotteryPrizes route at all.
- Production enablement needs an `add_on_managers` row **and** the merchant's plan `modules` list.
  Neither is readable from here, so **whether these are live in production is NOT DETERMINED** —
  and per `ARCH-009`, their absence here must not be read as absence there.
- They dispatch on `$request->payment_type` alone (note: capital-B `'Benefit'`, unlike first-party's
  lowercase), with no enable-key gate.

**First-party code being clean is not the platform being clean.** The ratchet says so in both
directions.

### 1.2 The ratchet now asserts zero, which is stronger than what it replaced

`GatewayCallbackDestinationTest::DECLARED_FIRST_PARTY_BINS` was `['BenefitPaymentController' => 1,
'PaymentService' => 1]`. It is now `[]`. The constant is kept rather than the test deleted: the
assertion reads "no first-party file names a bin", so **any** hit fails, where before a third file
could only be caught as a new key. Retiring the guard at the moment it finally has nothing to permit
would be the wrong move.

It strips `T_COMMENT`/`T_DOC_COMMENT` before counting, which this change needed — the removals left
comments naming the host, and a raw `str_contains` would have reported its own documentation as a
defect. That trap is recorded twice already in this project.

**Falsified:** restoring the bin in `processBenefit` turns the ratchet red.

---

## 2. The catch is narrowed

`processBenefit` answered **every** failure with `'Currency Not Supported.Contact To Your Site
Admin'` from a single `catch (\Throwable)`. A wrong secret key, an unreachable provider, a malformed
body and a genuine currency rejection all produced that one sentence, and the real reason was
recorded **nowhere**. A merchant chasing "currency not supported" could not learn their credential
was wrong.

| arm | shopper sees | logged |
|---|---|---|
| `ConnectException` | "the provider could not be reached" | store, message |
| `ClientException` (4xx) | **the historical currency message** — a currency the account cannot take really is a 4xx | store, status, **Tap's own body** |
| `ServerException` (5xx) | "the provider reported an error" | store, status |
| `\Throwable` (kept **last**) | the historical message | store, exception class, message |

**`\Throwable` is kept deliberately.** Narrowing a catch that currently swallows everything must not
turn a previously-handled failure into a 500 **on a payment path**. The final arm preserves the old
behaviour for anything the three named arms miss, while still logging it.

### 2.1 The unwrap moved inside the try, and that was the second half of the bug

`$res->transaction->url` sat **below** the catch. Tap can return **200 with an error body**, which
left `$res->transaction` null and raised an uncaught `\Error` in PHP 8 — a white page from a
response the server had successfully received. The unwrap is now inside the try, with an explicit
shape check that logs the provider's error payload and returns a real message.

---

## 3. Capture verification — the clause that is NOT done, and precisely why

**The finding is verified, with its stated consequence corrected.**

`PaymentController::getProductStatus` has exactly three gateway-finality branches — PhonePe,
toyyibpay and Tap — and **no `benefit` branch**. A case-insensitive search for "benefit" across the
whole 5811-line controller returns **one** hit: an allowlist entry. Nothing anywhere calls
`recordVerification('benefit', …)`.

**The consequence is NOT a falsely-Paid order**, as the finding said. `benefit` is not COD and not in
`OFFLINE_PAYMENT_TYPES`, so with no verification record `resolveOrderPaymentStatus()` returns
**Pending**, and the observer credits no wallet for a Pending order.

**The real exposure, stated accurately:** an unpaid shopper can mint a **Pending order with its stock
decrement, its emptied cart and a consumed 48-hour gateway-return claim** by abandoning the Tap page
and then loading the return URL — which is `Route::any` with **no authentication**, reading state
from their own session.

### 3.1 What is written and what is blocked

| | |
|---|---|
| **Writable here** | the `benefit` branch itself, copying the Tap branch's shape (same provider, same endpoint, same `App\Package\Payment` helper), then `recordVerification` in the Khalti/NMI shape |
| **EXTERNALLY BLOCKED** | **exercising** it. No Tap credential exists in this container — measured three ways: no `tap`/`benefit` in `.env`, no entry in `config/services.php`, and `benefit_secret_key` is a per-store `settings` row in a database with **0 rows** |
| **NOT the blocker** | the network. `api.tap.company` is reachable: a request returns **HTTP/2 401 with Tap's own body** `{"errors":[{"code":"2107","description":"Authorization Required"}]}`. Stated explicitly so it is never reported as a network restriction |
| **No test seam** | `processBenefit` builds `new Client()` inline and `App\Package\Payment::getCharge` uses raw `curl_init` — neither is the `Http` facade, so `Http::fake()` intercepts nothing. Any test would be structural unless a seam is introduced |
| **Owner decisions still open** | where the callback should point (**P1-SEC-55**), and whether the storefront return should gain a benefit capture check at all |

**So the honest status of this clause is: design complete and verified, implementation deferred, the
verification step EXTERNALLY BLOCKED for want of a provider credential the owner holds.** It is not
claimed as done, and the ratchet
`test_recorded_the_storefront_return_still_has_no_benefit_verification_branch` continues to assert
the absence rather than hide it.

---

## 4. Status against the owner's four clauses

| clause | status |
|---|---|
| remove webhook.site | **DONE** for both first-party sites; three vendored copies reported and not editable |
| narrow the catch | **DONE**, four arms, `\Throwable` kept last |
| *(found while doing it)* the unwrap outside the try | **DONE** |
| verify capture server-side | **NOT DONE — EXTERNALLY BLOCKED.** No Tap credential in this container; the host is reachable, so the network is not the blocker. Two owner decisions also open |
