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

## D-06 — COD settlement timing. **DECIDED 2026-10-22. APPROVED.**

Was `P1-PAY-04` (#26), and was the one decision actually blocking acceptance: GATE F could not be
released without it, and Phase 01 could not be accepted without GATE F.

**The decision, as given:**

- COD orders start **Unpaid**.
- `Paid` only after actual cash collection is **verified**.
- Courier collection and merchant settlement are tracked **separately**.
- **No merchant wallet credit at order creation.**
- Collection and settlement workflows configurable per store, *but* uncollected money must never be
  reportable as paid.
- Proceed with the GATE F development prerequisites and the required MySQL verification.

**What this unblocks:** `P1-MIG-01` (GATE F, the six money-bearing tables) and with it `P1-MIG-03`.
Sequence unchanged: MIG-02 → MIG-03 → MIG-01 → MIG-04, each with a rehearsed rollback.

**Note for whoever implements it:** this decision and recorded D-05 now interact. D-05 says no gateway
return proves a payment moved and that enforcing it would stop wallet credits for 51 live payment
methods. This decision says COD must not credit a wallet at creation. They agree in direction —
neither permits an unverified `Paid` — so the COD path should be built on the same
`PaymentVerificationService` seam rather than a parallel one.

---

## D-07 — numeric subdomains. **DECIDED 2026-10-22. APPROVED.**

**This is the entry the register did not have.** Before this date there was no owner-approved domain
architecture anywhere in the repository: this register held nothing on domains, the only recommendation
on file (`docs/discovery/10-OPEN-QUESTIONS-AND-DECISIONS.md` row **D-08**) was *unratified* and
recommended the **opposite** ("drop custom domains; merchants get `storesyria.com/{slug}` only"), and
#111 held open questions. That gap is recorded in
`docs/development/phase-1/P01-DOMAIN-IDENTITY-AUDIT.md` §0 and is now closed by this entry.

**The decision, as given:**

- Every merchant gets a **unique, permanent numeric subdomain**, independent of the editable slug.
- **Custom domains stay supported**, subject to **DNS ownership verification**.
- **Platform-owned hosts and system labels are reserved.**
- **Session cookies isolated by host.**
- Existing merchant URLs and integrations **preserved** during migration.
- **Wildcard DNS and SSL verified before production activation.**

**This supersedes discovery recommendation D-08(a).** Recorded explicitly so the project stops holding
two opposed positions, which is what the audit asked for.

**It also settles `P1-SEC-32`,** whose four sub-questions map onto it: (a) the platform host is reserved
— engineering had already determined this is not a business call; (b) reserved labels — the conventional
list ships; (c) **proof of control is required** (DNS verification), which was the open owner question;
(d) release of a squatted host still needs an operator path, and is the one piece this decision does not
name — see the blocker list.

**Cookie isolation by host is the answer the audit recommended** (per-host scope, not `.storesyria.com`),
so no merchant is logged out at cutover.

**Engineering consequences already measured and now in scope** (audit §3, with executable evidence in
`tests/Feature/Platform/StoreSlugIdentityTest.php` and
`tests/Support/Security/bin/verify-store-slug-identity-on-mysql.php`):

- `stores.slug` has **no unique index**, and uniqueness is advisory only.
- An Arabic store name collapses: `متجر الشام` → `-`, `سوق دمشق` → `-`, `مـتجر!!!` → empty. Every
  Arabic-named store contends for `-`, then `--1`, `--2`; the 101st returns `null` and store creation
  **500s**.
- The collision class is **collation-wide**: `getStore('Damascus')` resolves a stored `damascus`.
- The identifier must **not** be the primary key — `getCurrentStore()` hardcodes `1` for the operator,
  so PK 1 is already an authorization sentinel.

---

## D-08 — the platform's OpenAI key. **DECIDED 2026-10-22. NOT AUTHORIZED for merchant use.**

Was `P1-SEC-22(e)` (#96).

**The decision, as given:** merchants must not consume the platform's OpenAI API key; enforce without
breaking platform-operator functionality; design separate merchant credentials, entitlements or billing
for future use.

**Implemented**: `AITemplateController::resolveApiKeyForActor()` now reaches the platform key for the
**operator only**. A merchant, or their staff, with no key of their own is refused and gets the
handler's existing "no key configured" message. Judged with the shared operator helper (P1-SEC-11) so
it agrees with the engine on case and trailing space. Driven for a merchant **and** a staff member,
because `creatorId()` resolves staff to their merchant; removal check bites on both.

**Still open and now a product item, not a defect:** separate merchant credentials / entitlements /
billing. Not a Phase 01 blocker.

---

## D-09 — data retention. **DECIDED 2026-10-22.**

Default eligible-data retention **24 months**, subject to applicable legal retention and preservation
obligations.

**Resolves** `P1-ORD-03` (#86-adjacent: `user_delete` hard-deletes orders while soft-deleting the
account). Orders are financial records; the 24-month default plus the legal-obligation carve-out means
deletion must be **selective**, not a hard delete of order rows. **Also bounds `P1-OWN-08`**: the
`shetabit_visits` scrub has a retention rule to cite.

---

## D-10 — production key rotation. **DECIDED 2026-10-22. DO NOT ROTATE NOW.**

"Do not rotate existing production keys at this time. Record the outstanding exposure accurately."

**So `P1-OWN-02` (#43, `APP_KEY`) and `P1-OWN-09` (#88, gateway secrets) are deferred by decision, not
blocked.** Recorded accurately, as instructed:

- **`APP_KEY` is in git history and unrotated.** `Crypt::encrypt()` is therefore an **encoding, not a
  control** — any encrypted id in a URL is forgeable. `P1-ORD-09`'s order-details link rests on it.
- **Every store's payment-gateway secrets were readable by any internet visitor** (`P1-OWN-09`). The
  endpoint is closed; what was already disclosed cannot be undisclosed. **Treat as exposed.**
- **Platform keys** (reCAPTCHA, OpenAI) are owner-only to rotate and need no merchant coordination —
  the cheapest item on that list whenever the decision changes.
- **Ordering constraint that still holds when it does:** inventory encrypted-at-rest columns **before**
  rotating `APP_KEY`, or every encrypted column is corrupted. Inventory → re-encrypt → rotate.

---

## D-11 — production migrations and release. **APPROVED IN PRINCIPLE 2026-10-22, conditional.**

Contingent on **verified backups**, **restoration rehearsal**, **passing required tests** and **safe
migration sequencing**. And: *"do not make destructive production changes without an independently
verified recovery path."*

**This does not lift the session constraints below** — it authorises the release path, not ad-hoc
production work from a development session. Nothing in this repository has run a persistent migration,
and nothing will without the four conditions met and verified.

---

## FINAL OWNER-APPROVED EXECUTION DECISIONS — 2026-10-22 (D1 … D18)

The owner's final Phase 01 directive. **These are settled: do not re-ask any of them.** They are
recorded here, in the authoritative register, rather than only in the directive, because several
**supersede standing project rules** and later work has to be able to find that out.

### D9 supersedes a `CLAUDE.md` prohibition, and that is the most consequential line in the set

`CLAUDE.md` says **"Never edit `packages/workdo/`"** — a blanket prohibition, enforced by a local
PreToolUse hook and by `.github/scripts/guard-no-workdo-changes.sh` in CI, "which is the authority".

**D9 now permits a controlled, project-specific vendor patch** where a safe host-side solution cannot
close a verified issue, subject to: host-side protections preferred first; the smallest targeted
change; independent security and compatibility review; a reproducible patch and reapplication
mechanism across upgrades; the exact original version and patch checksum tracked; affected module
routes, permissions, tenant isolation and payments tested; never a silent untracked edit; and no
deployment without the required verification.

**What that means in practice, stated so nobody mistakes the scope:** the CI guard still exists and
still fails on an unexplained vendored change, which is correct — the authorization is for
*independently reviewed, narrowly scoped, reproducible* patches, not for editing vendored files. Any
such patch needs the patch-and-reapply mechanism built **first**, because an unreproducible edit is
exactly what the prohibition existed to prevent and D9 explicitly keeps that prohibition for
untracked edits. **No vendor patch has been made under this authorization.**

### The rest, and which previously-blocked question each closes

| | Decision | Closes |
|---|---|---|
| **D1** | **COD**: creation stays Pending/Unpaid; collector may be merchant, courier or delivery company, and the **actual collector and verified collection event are recorded**; five things kept distinct — order status, payment status, cash collection, collector liability, merchant settlement; Paid only on trustworthy evidence; no wallet credit for creation *or* for "Delivered"; per-store workflows; **idempotent** financial operations | `P1-PAY-04` (extends D-06 — note it now explicitly rejects *marked-Delivered* as sufficient, which the shipped machine currently uses) |
| **D2** | **Gateways**: a return URL, redirect or client flag proves nothing; require provider-side verification of ownership, reference, amount, currency and status; replay/duplicate protection; an unverifiable gateway stays Pending and writes **no paid ledger entry**; compatibility rollout; surface gateways that must be disabled | `P1-PAY-03`, and it hardens recorded **D-05** |
| **D3** | **Refunds**: a request is **intent only**; never overwrite original totals; track approval, execution and adjustment separately; adjust only on reliable execution; return stock only on defined conditions; no duplicate refunds or restocks | `P1-ORD-11`, `P1-ORD-14` |
| **D4** | **Domains**: a globally unique **permanent numeric public subdomain** (e.g. `1001.storesyria.com`), independent of slug/name/custom domain, **not assumed equal to the PK**; custom domains retained behind **DNS ownership verification**; apex and infrastructure labels reserved; **forced release/transfer is operator-only after verifying ownership, with previous holder, new holder, operator, reason and time in the audit log**; DNS change alone never transfers a claim; existing links preserved by gradual redirect; legacy routes kept compatible; **host-scoped cookies**; wildcard DNS, certificate coverage and hosting verified before production | **`P1-SEC-32` entirely, including (d)** — the last open owner decision in the phase |
| **D5** | **Retention**: 24 months default for eligible operational records; financial, tax, dispute, audit and legally required records follow their own obligations; legal holds; document per-table disposition; **no blind global DELETE** | `P1-ORD-03` (with D14) |
| **D6** | **Historical sensitive data**: inventory, preserve evidence under restricted access, rehearse on a restored backup, clean request/header fields, invalidate sessions, force password resets for affected accounts, review old logs and backups, verify inaccessibility — population from **evidence, not assumption**; and **retention must not preserve exposed plaintext credentials** | `P1-OWN-08` (direction; execution needs the restored backup) |
| **D7** | **No production key rotation now.** Residual risk accepted and to be **documented without claiming remediation**; keep fixing disclosure in code; never print secret values | `P1-OWN-02`, `P1-OWN-09` |
| **D8** | **Tokens**: narrow abilities to real client roles; preserve installed-app compatibility; expiring tokens plus a secure refresh via staged rollout; **no sudden mass device invalidation**; avoid blanket `['*']` | `P1-OWN-04`, and bears on `P1-ORD-09` |
| **D10** | **JS/CSS**: **preserve** the existing delegation — any holder of `Manage Store Setting` may edit permitted store JS/CSS and analytics; **do not narrow it to the owner or invent a new mandatory permission**; but enforce tenant ownership and authentication, keep CSRF, prevent crossing into another merchant's or the operator's origin, no access to server-side secrets, and **document the delegation risk** | `P1-OWN-12` — and note this is the **opposite** of the narrowing this project had recommended |
| **D11** | **Uploads in git**: leave them in the tree **and in history**; no deletion, no history rewrite, no force-push; prevent **new** user uploads being committed; document residual exposure; identify files needing separate legal/security handling; **do not claim preservation removes confidentiality risk** | `P1-OWN-10` |
| **D12** | **Legacy/dormant modules**: retain stored records; keep unsafe routes disabled until corrected; **`auction:order` still needs separate authorization**; retired Chatwoot data **not** destructively dropped | `P1-INV-20/21/22`, and it re-affirms recorded **D-03** |
| **D13** | **Category deletion**: **no cascade-delete of products**; refuse deletion while products remain; offer safe reassignment first; verify real MySQL FK behaviour and change constraints by approved migration | `P1-INV-12` |
| **D14** | **Account deletion**: retain order, invoice and financial records; remove or anonymise unnecessary personal data; respect retention, legal obligations and open disputes | `P1-ORD-03` |
| **D15** | **GATE F / production migrations approved**, execution conditioned on ten objective prerequisites (target environment, real dump from a restored backup, NULL `store_id` inventory, real constraints, dump-wins comparison, verified recoverable backup, rehearsal, COD/payment prerequisites, data-preservation and ordering, rollback, mandatory tests). **Do not promote guessed schemas from `_gate_f_pending`**; if the real schema or tested recovery is unavailable, **leave production unchanged and record the exact environment blocker** | `P1-MIG-01…04` (policy; the prerequisites remain) |
| **D16** | **Merge to `main` approved as a goal**, in sequence, and **separate from deployment**: finish implementation, complete acceptance, confirm CI and MySQL evidence, review risk, clean mergeable PR, merge only when branch/release gates permit, verify main, **do not auto-deploy**, prepare a separate deployment runbook. **If gates do not pass, do not merge** | the merge question |
| **D17** | **BackupRestore kept disabled/isolated** until server exposure is checked; no public access to backups, logs or sensitive storage paths; diagnostics preserved without exposing secrets | `P1-OWN-01`, `P1-OWN-03`, and it answers `P1-PLT-04`'s log-and-continue question |
| **D18** | **Platform OpenAI key is operator-only**; merchants and ordinary staff must not consume it; future merchant AI needs merchant credentials/entitlements/billing; **do not break operator access** | `P1-SEC-22(e)` — already implemented in `e326f0b3`, and this confirms it |

### Two places where a final decision contradicts what this project had concluded

Recorded because silently absorbing them would hide a real change of direction.

1. **D10 vs `P1-OWN-12`.** This project recommended requiring a *second, higher* permission for the
   script-bearing setting names, on the grounds that `storejs`/`storecss` is script injection into a
   payment page. **The owner has decided the opposite**: the existing `Manage Store Setting`
   delegation stands. The current implementation already matches that — `SettingsRestrictedWriteTest`
   records that the script-bearing names deliberately "stay open to `Manage Store Setting` holders and
   are the owner's decision (P1-OWN-12)" — so **no narrowing is to be introduced**, and the risk is
   documented instead, as D10 requires.
2. **D1 vs the shipped COD machine.** `e54e4bff` settles a COD order to Paid when the merchant **marks
   it Delivered**. D1 says explicitly: *"Do not credit a merchant wallet merely because the order was
   created or marked Delivered"*, and requires the **actual collector and a verified collection
   event** to be recorded. So marked-Delivered is **no longer sufficient**, and the current trigger is
   a partial implementation rather than a complete one. This is recorded as outstanding work, not as
   done.

---

## Standing constraints, restated because they bound everything above

No merge into `main`. No deployment. No persistent migrations. No destructive data operations. No
edits under `packages/workdo/`. PR #3 remains Draft.
