# 15 — Deferred owner decisions

A register of decisions the owner has **explicitly deferred or reserved**, kept separate from the
task ledger on purpose: a deferred decision is not an incomplete task, and recording it as an
unticked acceptance criterion invites someone to "finish" it.

Each entry says what was measured, what the decision costs either way, and what must be true before
it can be taken. Nothing here is a to-do. Do not act on any of it without a new owner decision.

`07-OWNER-APPROVAL-GATES.md` holds the Phase 1A gates (1–18) and is a dated artifact with its own
scoped evidence basis; it is not appended to.

---

## D-01 — `SESSION_SAME_SITE` stays at its current value

**Deferred by the owner, 2026-10-20.** Verbatim: *"Do not switch globally to Lax yet. Keep the
current value until the gateway POST callback and session-dependency matrix has been tested and a
compatible payment-state strategy approved."*

**Recorded against P1-SEC-01 / issue #15, acceptance criterion 3, which is NOT marked completed.**

### Current state

`config/session.php:202` — `'same_site' => env('SESSION_SAME_SITE', null)`. Unchanged. `null` omits
the attribute, so browsers apply their own Lax-by-default, which is not identical across browsers or
across the redirect shapes a payment gateway uses.

What **did** change in P1-SEC-01: the stock Laravel comment above that line claimed *"By default, we
will set this value to `lax`"*, which is false of this application. The comment is corrected and the
value is pinned by `tests/Feature/Security/SessionHygieneTest.php` so a future change has to be
deliberate. `SESSION_SAME_SITE` in the environment still overrides it.

### What `lax` would cost, measured

A top-level cross-site POST does not carry a `Lax` cookie. Nine CSRF-exempt routes are POSTed to by
third-party gateways and then read their state out of the session. Driven with no session cookie:

| route | handler | at `lax` |
|---|---|---|
| `iyzipay/callback/plan/{id}/{amount}/{coupan_code?}` | `IyziPayController@iyzipayCallback:125` | 302 → `/login` |
| `aamarpay/success/{data}` | `AamarpayController@aamarpaysuccess:195` | 302 → `/login` |
| `plan-get-phonepe-status` | `PhonePePaymentController@planGetPhonePeStatus:104` | 302 → `/login` |
| `plan-easebuzz-payment-notify` | `EasebuzzController@notify_url:150` | 302 → `/login` |
| `plan-get-Powertranz-status/{plan_id}` | `PowertranzPaymentController@…:197` | 302 → `/login` |
| `plan/sslcommerz/status/{plan_id}` | `SSLCommerzPaymentController@…:172` | 302 → `/login` |
| `store/paymera/trigger/{orderId}` | `PaymentController@handlePaymeraTrigger:877` | 302 (reads `Cache`; breaks only on the `auth` middleware) |
| `{storeSlug?}/get-payment-easebuzz` | `EasebuzzController@getProductStatus:226` | **the checkout refusal, and no order** |
| `campaigns-card-payment/` | `Campaigns…@paymentSuccess:128` | null deref on `Auth::user()->id` |

Six of those **take money and then activate a plan** from `Auth::user()`; at `lax` the merchant lands
on the login page and the plan is never activated. The eighth is the worst:
P01-COMMERCE-SEC-01 deliberately made the session the **sole** source of checkout state, so
`storedCheckoutState()` returns null, `gatewayReturnRefusal()` fires, and a **paid Easebuzz order
silently fails to exist**.

### What must be true before this can be taken

1. **A gateway POST-callback and session-dependency matrix**, per gateway: does the callback arrive
   as a top-level cross-site POST, a same-site POST, or a GET redirect; and does its handler read
   the session, `Cache`, or only its own request payload.
2. **An approved payment-state strategy** for the callbacks that genuinely need cross-site state —
   the candidates are a signed, single-use state parameter carried in the callback URL, or a
   server-side state record keyed by the gateway's own reference rather than by the session. Both are
   design work, not configuration.
3. Only then is `lax` (or a per-route `none` with `secure`) a configuration change rather than a
   payment outage.

**Related and also deferred:** `secure`, `http_only` and `partitioned` travel with this decision and
were read but not changed. Full write-up: `docs/security/P01-SESSION-HYGIENE.md` §C.

---

## D-02 — the lottery payment-method rewrite is not enabled

**Reserved by the owner, 2026-10-20.** Verbatim: *"Document actual available payment methods and the
smallest safe compatibility fix. Do not activate previously unreachable payment flows without
approval."*

Nine of the thirty-five implemented gateways are reachable; twenty-six are not, each differing from
its `case` label only in the first character's case. A normalising middleware would make **at most
22** of them live — and two, `phonepe` and `coingate`, would be left broken or made worse (coingate
becomes an HTTP 500 where today it is a 302).

**What may be shipped without a further decision:** the same middleware in **refuse-only** form —
the missing enabled-gateway allowlist, no rewrite. It activates nothing and closes a live gap where
`lottery_form_data` accepts a gateway the store has switched off.

**What is held:** enabling the rewrite, and `RestoreMidtransSelection`, which must land with #68's
payment-state design because the comparison it restores is the only thing stopping a failed Midtrans
payment writing a ticket.

Full write-up: `docs/security/P01-LOTTERY-PAYMENT-METHODS-AVAILABLE.md`. Issue #70 item 1.

---

## D-03 — `auction:order` stays unscheduled

**Settled by the owner, 2026-10-20.** Verbatim: *"Keep `auction:order` unscheduled. Do not activate
it without a separate end-to-end security and financial acceptance review."*

Registered as an Artisan command and scheduled nowhere, which is what keeps P1-INV-19's order limb
latent. Activating it needs its own review, not this task's. Recorded in the ledger under
`P1-INV-19` and here because it is a standing constraint rather than a finished item. Issue #65.

---

## D-04 — the #69 upstream report is drafted and not sent

**Reserved by the owner, 2026-10-20.** Verbatim: *"Prepare an upstream WorkDo report for the tenant
email-template issue. Do not change vendor code or submit externally without approval."*

Drafted at `docs/upstream/WORKDO-REPORT-email-template-tenancy.md`. No vendor file is modified. The
report is an outward-facing disclosure naming file paths and line numbers in a commercial vendor's
code; sending it is the owner's decision. It carries no credentials, customer data or store names.

Issue #69, task `P1-SEC-07`.

---

## D-05 — payment verification posture, and the `'Paid'` state

Carried forward, unchanged, and recorded here so it is not mistaken for new: no gateway return proves
a payment moved, enforcing it would stop merchant wallets being credited for 51 live payment methods,
and #68's question — *do not trust a recorded `Paid` state without verified settlement evidence* —
is still open. `PaymentVerificationService` logs every unverified write; the posture is unchanged by
design. Issues #67, #68, and §7 of PR #3.

---

## Standing constraints, restated because they bound everything above

No merge into `main`. No deployment. No persistent migrations. No destructive data operations. No
edits under `packages/workdo/`. PR #3 remains Draft.
