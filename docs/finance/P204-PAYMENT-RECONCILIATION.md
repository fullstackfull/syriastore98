# #204 — reconciling a previously initiated payment

**Status: BUILT. No business row is written unless a person passes `--apply`.** Written after the
patch, because this repository is public.

> *The heading used to read "writes nothing unless a person tells it to". The independent financial
> review measured that as false in one narrow way, and it is corrected rather than softened: under
> production's `CACHE_STORE=database` a report-only run writes four `cache` rows, because reading
> the platform's credentials goes through `Cache::remember('super_admin_details')` and a `Cachable`
> model. No intent, order, entitlement, coupon or audit row — and not "nothing".*

Owner instruction, verbatim:

> *"Keep a safe, authenticated and idempotent reconciliation mechanism for previously initiated
> payments; do not use it to permit new transactions through disabled applications."*

This is the capability three separate records named as missing: **P04-FIN-04 / P4-F128** (*"the
wallet can be moved without the order, in both directions, with no reconciliation anywhere"*),
**P5-FIN-4** on the Phase 05 plan, and Phase 06 review finding **F13**. It is also the prerequisite
for the disabled-application boundary — `PHASE-06-DISABLED-APPLICATION-BOUNDARY.md` §4.3 — because
widening a refusal over payment routes before a reconciliation existed would have lost captured
payments.

---

## 1. What was missing, measured

Every piece of a reconciliation existed and **nothing joined them.**

| | |
|---|---|
| `plan_purchase_intents` | records what a buyer was about to purchase, written by the server before they left for the provider |
| `PaytmTransactionVerifier` | can ask Paytm, server to server, what became of one reference |
| `PlanPurchaseIntent::unreconciled()` | the query the F229 design says *"makes this design worth having"* and *"points a human at"* — **and it had NO READER.** Zero matches in `app/`, `routes/` and `resources/views`. It pointed at nobody |
| `app/Console/Commands/` | twelve commands, none about payments. Named in full in `PHASE-06-REVIEW-FOLLOWUP-2B-2C.md` F13 |

**And the one purchase path with full verification could not complete.** `plan-paytm-payment` is
POST-only inside the session group, so Paytm's cross-site return is answered **419 before `auth`
runs** (`F241-PAYMENT-RETURN-ARCHITECTURE.md` §1). On an installation with the intent table, a buyer
who pays for a plan through Paytm is charged, lands on an error page, and is left with an intent
that is `pending`, carries no `provider_txn_id` and no `failure_reason` — **the one shape
`unreconciled()` cannot match.** A captured payment, byte-identical to an abandoned checkout.

---

## 2. What was built

`php artisan payments:reconcile` → `App\Console\Commands\ReconcileInitiatedPayments` over
`App\Services\Payments\Reconciliation\`.

| class | what it is |
|---|---|
| `CaptureVerifier` + `CaptureAnswer` | the contract for *"what does this provider say about this reference"*, in `PaytmTransactionVerifier`'s own three-state vocabulary: `VERIFIED`, `REFUSED`, `UNVERIFIABLE` |
| `CaptureVerifierRegistry` | **which providers can be asked at all.** Paytm, and nothing else |
| `PaytmCaptureVerifier` + `PaytmPlatformCredentials` | the adapter, plus the platform credentials a session-less caller needs |
| `PlanPurchaseIntent::compare()` | **extracted, verbatim, from `verifyAndConsume()`** so the dry run asks the same question the settlement answers. One rule, two callers |
| `PlanPurchaseSettlement` | the single writer: consume, order, entitlement, attachment, coupon, audit — in **one transaction** |
| `PlanPaymentReconciler` | the decision, and the discrepancy record |
| `ReconciliationOutcome` | nine named outcomes and not a bool |

### 2.1 Each requirement, and the thing that meets it

| requirement | how |
|---|---|
| **durable proof of prior initiation** | it acts only on a `plan_purchase_intents` row, which the SERVER wrote before the buyer left. It never creates an intent and never starts a payment, so it cannot be used to transact through anything. No row, no reconciliation |
| **authoritative provider verification** | `CaptureVerifierRegistry`. A provider with no verifier is `NO_VERIFIER` and nothing settles |
| **transaction ownership** | the question asked of the provider is the intent's own server-minted reference; the purchaser, plan, amount and currency are the intent's. **Nothing a caller supplies chooses any of them** |
| **amount, currency and state** | `PlanPurchaseIntent::compare()` — exact scaled-integer comparison, never a float and never `bccomp` (bcmath is not installed here) — and only a `pending` intent is eligible |
| **replay and duplicate settlement** | the intent's conditional `UPDATE … WHERE status = 'pending'`; `plan_orders.order_id` UNIQUE **on the intent reference** rather than on `time()`; and a refusal when the provider's transaction id is already somebody's plan order |
| **concurrency safety** | the same conditional UPDATE, inside the settlement's transaction, **raced on MariaDB through this service** — `verify-plan-payment-reconciliation-on-mysql.php`, 15 checks. *(This row used to cite a script that races the intent's bare UPDATE and knows nothing about the settlement; the review named that as a false claim, and the new script is what makes it true.)* |
| **no duplicate financial or inventory side effects** | a plan purchase is paid to the platform: no wallet, no `store_order_financials`, no stock, no `orders` row. Asserted as a count over six tables before and after a settlement |
| **auditable discrepancies** | written onto the intent in the server's own words so `unreconciled()` surfaces it, **and** into `audit_logs`, in one transaction. **Once**, keyed on a canonical `reconciliation[<code>]:` marker in the reason — the first version compared the whole sentence, which the review showed is not once at all |
| **no invented settlement rules** | a matching capture becomes exactly what the paid browser return already makes it. Nothing is refunded, reversed, credited or guessed |

### 2.2 The settlement is ONE transaction, and that is the difference from the browser return

`PaytmPaymentController::getPaymentStatus()` consumes the intent, then writes the order, then
assigns the plan, as three separate commits. That is where requirement 5's *"captured payment,
intent spent, nothing to show"* state comes from — `unreconciled()`'s third clause exists for it.

A reconciliation that could leave that state behind would be manufacturing the thing it was built
to repair. So a failure at **any** step rolls the whole settlement back, the consumption included,
and the intent is `pending` again for the next run. Rolling back the consumption does not reopen a
spent payment: nothing was granted, because the grant rolled back with it.

### 2.3 Nine outcomes, because a bool would have to lie about one of them

`GatewayReference::claim()` is this repository's own record of what a boolean costs on a money path:
it returned `true` on four paths that did not mean the same thing.

| outcome | written by an applying run | needs a person | ask again |
|---|---|---|---|
| `SETTLED` | plan order, entitlement, attachment, audit row | no | no |
| `WOULD_SETTLE` | nothing — it was a dry run | no | — |
| `ALREADY_SETTLED` | nothing | no | no |
| `NOT_ELIGIBLE` | nothing; no provider was asked | no | no |
| `NOT_CAPTURED` | nothing | no | **yes** |
| `UNVERIFIABLE` | nothing | no | **yes** |
| `NO_VERIFIER` | nothing | unknowable from here | no |
| `DISCREPANCY` | the reason, on the intent and in `audit_logs` | **yes** | no |
| `SETTLEMENT_FAILED` | nothing — it was rolled back | **yes** | **yes** |

`ALREADY_SETTLED` and `NOT_ELIGIBLE` both mean *somebody else got there first* — the first inside
one race, the second before the run began. **`ALREADY_SETTLED` was unreachable on MariaDB until the
review found why** (§5.2 row 5), which is worth knowing when reading this table: a row in a state
diagram is not a state the code can reach.

`NOT_CAPTURED` is retryable **by design**: a provider's "not successful" includes "not successful
*yet*" — Paytm reports `PENDING` while a bank has not confirmed — so nothing terminal is written on
it. An AMOUNT discrepancy closes the intent, exactly as the browser return closes it; every other
discrepancy keeps it open with its reason, because a currency label, a deleted plan or a missing
purchaser are things a person may put right, and closing the intent would make a recoverable
payment unrecoverable.

`NO_VERIFIER` deliberately does **not** count as "needs a person", and that is a judgement worth
stating: whether money moved is genuinely unknown, but the providers in that position (Cashfree,
Mollie) have a working browser return, so their `pending` intents are overwhelmingly abandoned
checkouts. A report that flagged every one as an emergency is a report nobody reads by the second
week. They are counted and listed in their own words.

### 2.4 It writes no business row unless told to

`--apply` is the whole difference. Without it the run asks each provider and prints what an applying
run **would** do — no intent, no order, no entitlement, no coupon, no audit row — and the dry run
calls the settlement's own preflight, so *"would settle"* cannot be said about a purchase the
settlement would refuse.

**It is not literally write-free, and the stronger claim was in this document.** Under production's
`CACHE_STORE=database` a report-only run writes `cache` rows, because reading the platform's Paytm
credentials goes through `Cache::remember('super_admin_details')` and a `Cachable` model. Measured
by the independent financial review.

The reason it ships that way is the reason `config/payments.php` ships the Paytm callback closed:
**no live Paytm transaction has ever been verified from this project.** There are no Paytm
credentials here and no network path to `securegw.paytm.in`, so the one thing that cannot be
synthesised — a real captured payment coming back `TXN_SUCCESS` with a valid checksum over the
wire — has not been observed. The orchestration and the controls are real and tested; the live
handshake is not.

**It is not scheduled, on purpose.** An unattended job that grants entitlements on a provider's
answer is a decision about how the platform is operated, and `routes/console.php` already records
that nothing in this repository can show whether the scheduler even runs. Scheduling it is the
owner's call.

---

## 3. Two defects found while building it, both in code that was already committed

### 3.1 The F241 provider callback could never have verified a payment

`PaymentCallbackController::paytmPlan` called `PaytmTransactionVerifier` directly, and that verifier
reads `config('services.paytm-wallet.merchant_key')`. **The only writer of that key is
`PaytmPaymentController::paymentConfig()`, behind `if (\Auth::check())`** — and this route is outside
the session group *on purpose*, which is the whole of F241's design. So a real callback always met
an empty key, was answered `UNVERIFIABLE: no merchant key is configured`, and
`recordRefusal()` wrote a **terminal** refusal against a payment the provider had captured. Every
one, from the day the gate was opened.

**Its nineteen tests could not see it**, because they set
`config(['services.paytm-wallet.merchant_key' => ...])` directly in `setUp()` — a state production
cannot reach without a session. The new case stores the key the way an operator does, as the
platform's own setting, and clears the preset.

### 3.2 An activation left no record of the sale

The same callback consumed the intent and assigned the plan and **wrote no `plan_orders` row**. Two
consequences: the platform's own revenue table did not contain the payment, and the intent was left
`consumed` with a null `plan_order_id` — which is precisely what `unreconciled()`'s third clause
reports as *"payment captured, nothing to show"*. **Every successful callback would have put a false
alarm in the one list a person reads.**

Both are closed by routing that controller through the same `PaytmCaptureVerifier` and
`PlanPurchaseSettlement` the reconciliation uses — one writer, not two.

### 3.3 And a third, found by the MySQL harness rather than by reading

`PaytmTransactionVerifier` did not check that the status response was **about the transaction it
asked about.** `getTxnStatus` is keyed on (MID, ORDERID), so an authentic, correctly-checksummed
`TXN_SUCCESS` naming a *different* order was returned as `VERIFIED` for this one. Behind a validated
certificate that needs Paytm itself to answer the wrong question, so it is defence in depth rather
than a live hole — but it is the only place *transaction ownership* can be checked at all, and a
door that settles with nobody watching should not take it on trust. Present-and-different now
refuses; absent is judged exactly as before, because no sandbox evidence in this project establishes
whether Paytm echoes those fields.

---

## 3.4 One more, found by the independent review of this change

**`PaymentCallbackController` closed a purchase permanently on an answer it could not obtain.**

On any non-verified provider state the callback called `recordRefusal()`, which is TERMINAL — the
retry dies on `where('status', PENDING)`. It ran with **no purchaser scope** and before any
ownership check, on a reference taken from the request. So whoever could name a pending reference
closed that purchase for good, and **an unreachable status endpoint was enough to do it by
accident**.

`PlanPaymentReconciler` answers the identical provider state by writing nothing, precisely because
an answer that could not be obtained says nothing about the money. Two doors disagreeing about the
same answer is how a captured payment gets destroyed by the more careless one. The callback now
records a RECOVERABLE refusal, which `unreconciled()`'s fourth clause surfaces and
`payments:reconcile` can finish.

Low reachability — the gate ships closed and the reference is 128 bits — and fixed anyway, because
the asymmetry was the kind this project has already paid for twice.

## 4. What it cannot reconcile, and why — said here because the name promises more

| | |
|---|---|
| **a provider with no authoritative verifier** | Cashfree and Mollie have a plan binding and no amount contract; Benefit and Flutterwave have neither. D-31 requirement 8 blocks a provider whose contract cannot be established, and a reconciliation that guessed one would be the guess requirement 8 forbids, made unattended. Listed as `NO_VERIFIER`, never settled |
| **a storefront ORDER payment** | `orders` carries no gateway reference and `order_gateway_references` has no production writer, so there is no durable proof such a payment was initiated. `F216-CAPTURE-RECOVERY-DESIGN.md` §6 names the two owner decisions in front of that |
| **a payment taken through a MODULE** | Donation, LotteryPrizes and Campaigns verify nothing with a provider (one gateway of thirty-five excepted) and keep no gateway reference, so there is nothing to ask. What the host *does* record — `module_payment_initiations` — is listed by this command: every return to a switched-off application that was refused for want of an initiation, so a payer whose money was taken is not turned away unseen. `--acknowledge` is how a person says they have dealt with one; it changes no payment, only the list |
| **the wallet-versus-order axis** | still the read-only reporter `report-settlement-entitlement.php`, and still never run against real data. This change does not close P04-FIN-04's wallet half; it closes the *plan* half and builds the surface that half will need |

---

## 5. Evidence

```
tests/Feature/Payments/PlanPaymentReconciliationTest       44 passed   (12 added by the review's findings)
tests/Feature/Security/PaytmPlanCallbackTest               22 passed   (3 new, all failing first)
tests/Feature/Security/PaytmTransactionVerifierTest        23 passed   (2 new, both failing first)
Unit suite                                              6,218 passed, 1 declared skip
Contract suite                                             45 passed
verify-plan-payment-reconciliation-on-mysql.php            15 checks, 0 failed (MariaDB 10.11)
verify-module-payment-boundary-on-mysql.php                16 checks, 0 failed (MariaDB 10.11)
```

**Failing first, then passing, for each new control** — and for §3.1 and §3.2 the new cases were
driven against the *unchanged* controller and failed there, which is what makes them evidence about
the defect rather than decoration on the fix.

Every refusal case carries its control in the same test: the identical fixture with one value
changed, settling. "Nothing was written" is also what a broken fixture produces — and it was: the
first version of the reconciliation tests re-registered `Http::fake()` per call, which does not
replace the first stub, so four *controls* were being answered by the first fake. The fixture was
broken and the code was right, which is the direction that wastes the most time.

### 5.2 What the independent financial review found, and what it changed

It drove probes on SQLite and on MariaDB and tried to produce a duplicate. **It could not**: no
sequence of runs, callbacks, retries or a real two-connection race produced two plan orders, two
entitlement grants or two coupon rows for one intent. What it found was wrong entitlements and wrong
records *around* that core — three HIGH, five MEDIUM, all fixed:

| # | what was wrong | what it is now |
|---|---|---|
| 1 | **a refunded capture still settled.** `STATUS` has four values and none says "refunded", so a sale the operator had refunded in the dashboard kept reporting `TXN_SUCCESS` — and the buyers this reaches are exactly the ones charged and shown the 419 error | any refund-shaped field the verifier cannot read as zero makes the answer UNVERIFIABLE. **No field contract is invented**: it is name-based, fail-closed, and §3.3's rule about guessing holds |
| 2 | **a days-old payment was applied as if it had just happened.** `assignPlan()` SETS the plan and recomputes the expiry from now, so a three-day-old intent for a cheaper plan DOWNGRADED a buyer who had since bought a dearer one and switched off two of three stores; a purchase already granted by hand was granted again; two payments for one period both settled | `ENTITLEMENT_MOVED_ON`: refused and recorded when the purchaser holds another plan or has a newer successful plan order. The report now prints what they hold now. Whether such a payment should ever auto-apply is the owner's |
| 3 | **a verified capture that failed to settle was written nowhere**, so it was absent from `unreconciled()` and once past `--days` the run went green with the money unsettled | recorded recoverably with an audit row, under the same idempotence rule; an unexamined pending intent now makes the run exit 1 |
| 4 | **the model cache was flushed inside the transaction**, so a concurrent reader cached the pre-settlement row and it outlived the commit — measured with three processes on `CACHE_STORE=database`: a fresh process read `plan_id` NULL while the row said otherwise | `DB::afterCommit()` over all four models the settlement writes, the pattern this repository already proved for themes |
| 5 | **`ALREADY_SETTLED` was unreachable on MariaDB.** Under REPEATABLE READ the loser of a race read its own snapshot, the intent still looked pending, and a lost race was reported *and audited* as a mismatching AMOUNT | `findForUpdate()`, a locking re-read. Pinned by `verify-plan-payment-reconciliation-on-mysql.php`, because on SQLite `lockForUpdate()` compiles to nothing and no suite test can tell the two apart |
| 6 | **a provider retry of a settled payment** answered `accepted` and logged CRITICAL "needs a human" | the callback short-circuits a non-pending intent, as the reconciler already did |
| 7 | **"written once" did not hold.** Idempotence compared `failure_reason` text, so the callback's differently-prefixed sentence produced a fresh audit row per run — and if the audit insert ever failed, it was never written again | one transaction (which fixes the second half outright: reason and audit row commit together or neither does), with the key a canonical `reconciliation[<code>]:` marker. **The draft that keyed it on an audit-row READ was caught by `AuditAttributionTest::test_no_audit_reader_exists_without_an_authorization_gate`** — a ratchet requiring any first-party audit reader to carry an authorization check. The read was console-only and disclosed nothing, and the honest answer to that gate is not to need it rather than to add the word its regex looks for |
| 8 | **exit 0 when nothing was examined.** A broken listing query printed "No pending intent falls inside the window" and exited clean; so did `--provider=Paytn` | the listing failure surfaces as exit 2, an unrecorded provider is refused, and `would_settle` no longer exits 0 |

Four smaller ones with it: the settlement built the entitlement from the object it was handed rather
than the row it consumed (latent, both callers pass fresh rows); the replay lookup used the full
transaction id while the column stores 100 characters; the coupon was resolved by code through a
cache, so renaming it dropped the usage; and the preflight refused the order the intent itself had
written.

**And the new MariaDB script caught a defect in one of these fixes on its first run** — the
entitlement check counted this intent's own order, so a retry read as a discrepancy instead of
"already settled". That is the script earning its place before it was even registered.

### 5.1 What the suite cannot establish

- **No live provider handshake.** See §2.4.
- **SQLite cannot race.** The conditional UPDATE is exercised sequentially from one stale snapshot
  in the suite; two real connections contend in `verify-module-payment-boundary-on-mysql.php`.
- **`plan_orders.price` is a FLOAT column.** The settlement writes the intent's `DECIMAL(16,4)`
  value into it, exactly as the browser return does. The comparison that decides whether to settle
  never touches a float; what is *stored* afterwards inherits the column's precision, which is a
  pre-existing property of the schema and not changed here.

---

### 5.3 Carried forward from the review, not closed

Recorded rather than fixed, each with the reason:

| | |
|---|---|
| **the "needs a person" list only grows** | there is no way to acknowledge a PLAN intent (`--acknowledge` is module-only), so once one definite provider NO is on the list every later run exits 1 and the status stops carrying information. A `TXN_FAILURE` recorded by the callback is indistinguishable from money waiting to be settled. The fix is an acknowledgement for plan intents, and it is a surface rather than a one-line change |
| **three doors, two policies, on an unobtainable answer** | the browser return still closes an intent TERMINALLY on `UNVERIFIABLE` (`PaytmPaymentController:471`), while the callback and the reconciliation now keep it open. The return is the oldest of the three and changing it changes a live purchase path, so it is named rather than touched |
| **`payment_type` spellings** | the browser return stores `__('paytm')` — lower case, and translated per locale — while this settlement stores the canonical `Paytm`. `GuardsPlanActivation`'s replay sweep covers the language files' CURRENT and HISTORICAL spellings but not `trans('paytm')`, so a locale whose `paytm` key differs leaves a gap in the replay lookup. Pre-existing, measured by the review on MariaDB, and a change to that trait's constant rather than to this slice |
| **the plan order is dated the day of settlement** | up to `--days` after the money moved, and the provider's own date is not kept. A reporting question, not a correctness one |
| **every pending intent is asked about on every run** | sequentially, with 10 s and 20 s timeouts and no back-off. Fine at this scale and worth knowing before it is scheduled |
| **a 150-character purchaser name is silently cut to 100** under production's non-strict MySQL. Under a strict connection that insert would raise and the intent would fail on every run — which §5.2 row 3 now records rather than hides |
| **one lock-upgrade shape was observed and no deadlock occurred** | two settlements for one purchaser can take an S lock on `users` through the `plan_orders` foreign key; the review measured the wait once in nine arrangements and never a deadlock. `DeadlockException` extends `PDOException`, not `QueryException` (this project measured that during Phase 03), so a handler catching the latter would not see one |

## 6. Owner decisions this does NOT take

- **Whether to promote `module_payment_initiations` to production.** Until it is, the payment half
  of the disabled-application boundary is inert there, by construction and by design.
- **Whether to open `PAYTM_CALLBACK_ENABLED`.** Still closed; still needs one real sandbox payment
  driven by somebody who holds Paytm credentials.
- **Whether to schedule `payments:reconcile`.** See §2.4.
- **Whether a merchant may complete an order on their own attestation**, and the durable gateway
  reference on `orders` that would make a storefront-order reconciliation possible —
  `F216-CAPTURE-RECOVERY-DESIGN.md` §6, unchanged.
- **The two remaining boundary categories**, `storefront` (the 76 keys that are not a payment) and
  `oauth` (16).
