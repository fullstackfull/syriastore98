# Phase 01 — execution report against the owner's final unblock instruction

**2026-10-23 · branches `claude/modest-tesla-r74de7` and `claude/store-syria-p01a-ci-foundation`,
kept identical · PR #3 remains Draft · `main` and production unchanged**

This answers the five questions the owner asked for, in their order. Where something was not
verified, it says so; "NOT VERIFIED" appears here deliberately and is not an omission.

---

## A. What was unblocked and completed

### A.1 The seven outstanding items

| # | item | owner's decision | outcome |
|---|---|---|---|
| 1 | **P1-SEC-14 / LaraBug (#89)** | disable outbound reporting until sensitive-data protection is verified; preserve local logging | **COMPLETE** — `larabug.outbound_enabled` defaults to **false**, and `mayReportOutbound()` requires both a configured account and that switch, because "no key" was never an off switch: the vendor client POSTs with an empty bearer token regardless. Local logging is preserved by construction (the refusal is in the handler, not a channel removal). 78 tests; 5 mutants, 5 bite. |
| 2 | **`pending_payment`** | the server alone determines the payable; reject mismatches safely | **COMPLETE** — see §A.2. |
| 3 | **P1-INV-24 (#117)** | prefer `ON DELETE SET NULL` where the schema supports it; validate MySQL constraints; preserve data | **COMPLETE** — see §A.3. |
| 4 | **P1-PAY-04 (#26)** | COD policy approved, do not reopen; complete after GATE F | **CORRECTLY NOT STARTED** — GATE F is an environment prerequisite (§B). Not reopened. |
| 5 | **P1-INV-15 (#60)** | restock only on acceptance; no duplicate credits or refunds; separate inventory from refund settlement | **COMPLETE** — see §A.4. |
| 6 | **P1-ORD-01 (#20)** | defer the redesign, defer no confirmed integrity defect; document the deferral and its safety evidence | **DOCUMENTED** — `docs/security/P01-ORD-01-COUPON-POLICY-DEFERRAL.md`. The confirmed defect in that family was **not** deferred: the unauthorized-discount path was fixed in `13bec7d3`. |
| 7 | **P1-ORD-13 (#82)** | gateway test infrastructure, not another business decision | **SPECIFIED** — `docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md`, 52 payment types, 11 order-writing return paths, 12 fail-closed controls named with file:line, and the exact externally-obtained items listed in §B.3. |

### A.2 Decision 2 — the payable of a pending balance

**Measured on the real route** (`POST {slug}/process-order`, signed in as a real customer of the
store, PartialPayments active through the store owner's plan), against an `order_partial_payments`
row recording an outstanding balance of **40.00**:

> posted `payment_amount = 1.00` → **stored, and therefore charged, 1.00**

`cartlist_final_price` is not a label on the money. Every gateway arm in `PaymentService` reads
that one key for the amount it hands the provider, and `serverPayableAmount()` reads it to decide
what AuthorizeNet captures.

**Two further defects in the same limb**, both found while fixing the first:

- `partial_payment_id` went unchecked into `PartialPayments::ManagePartialPayment()` — a bare
  `find()` with no `store_id` and no `customer_id` predicate, on a model in no `scoped_models`
  entry. One shopper could mark **any tenant's** balance settled.
- All eight sites gated on `isset($x) || $x == 'pending_payment'` where the intent is `&&`, so
  posting any `partial_payment_type` on an ordinary checkout diverted the gateway return into the
  settle branch: charged, **no order row**.

**A third, found by the independent read-only review of the fix.** Eight handlers read the stored
checkout state and write an order; seven carry the settle branch and `KhaltiPaymnetController`
carries no `partial_payment` reference at all. A khalti pending-balance checkout was therefore
priced at the balance *correctly*, charged it, and then written as a **full-price order with the
balance still owing**. Refused at initiation, not at the return — by the return the card is already
charged.

### A.3 Decision 3 — reviews survive a category deletion

The defect is triggered by **the D13 refusal's own advice**: the merchant is refused, told to move
the products, does so, the D13 count falls to zero, the delete is permitted, and the reviews
cascade away below the application.

Driven end to end on **MySQL 8.0.46** against the module's schema column for column:

| | before the migration | after |
|---|---|---|
| reviews surviving the sequence | **2 → 0** | **4 → 4**, pointers nulled, no row deleted |

*"Where the actual schema supports it"* — it did not. `category_id` is `NOT NULL DEFAULT 0` and
MySQL refuses the constraint outright (**errno 1830**, measured), so the migration makes the column
nullable first. And the `DEFAULT 0` is itself a latent violation: no `categories` row has id 0, so
an insert omitting the column is refused by the live constraint — measured when the first draft of
the verification script died on exactly that.

### A.4 Decision 5 — one return, one stock credit

Double credit **measured at 50 → 59 → 68** across the two-step merchant workflow. Two endpoints
could credit the same line and neither knew about the other. The mechanism is a per-**line** marker
inside `product_json` that both write and both consult — per line because a four-line order can
have one line marked returned and then the whole request approved, and in `product_json` because
both endpoints already write it transactionally, so it needs no column and therefore no migration
against live data.

Also closed in the same package: POS-shaped and NULL `product_json` now refuse instead of 500ing;
`tax_price` is no longer written as 0.00 on every return; the restock and both saves are in one
transaction; both return AJAX callers gained an error handler.

### A.5 The 28 BLOCKED rows — re-classified, not executed

An independent read of all 28 rows against the owner's standing decisions:

| verdict | count | meaning |
|---|---|---|
| EXECUTABLE-NOW | **14** | an owner decision now covers it; code can be written and tested in this container |
| DOC-ONLY | **3** | the remaining work is a record, no code |
| ENV-PREREQ | **11** | blocked on something outside this container |
| **STILL-BLOCKED** | **0** | no row is waiting on a decision any more |
| ALREADY-DONE | 0 | though two rows are **stale in part** — see §E.3 |

**None of the 14 has been executed in this session**, and this report does not claim otherwise.
They are unblocked, specified and ordered by (risk desc, effort asc) in the sweep, with the file,
the control and the proving test named for each. That ordering is the next session's work list.

---

## B. What remains blocked, and by what

### B.1 One prerequisite unblocks seven of the eleven

**A verified, restorable production backup plus a real `SHOW CREATE TABLE` dump taken from the
restored copy.** It closes the gate on P1-MIG-01, P1-MIG-02 and P1-MIG-04, and feeds P1-OWN-07,
P1-OWN-08, P1-OWN-11 and P1-PLT-02's count. This is D15's own condition.

### B.2 The remaining four

| row | blocked on |
|---|---|
| P1-OWN-01 (#42) BackupRestore | production shell: does an `add_on_managers` row exist at all, what sits in the backup directories, does `.htaccess` deny them |
| P1-OWN-03 (#44) compromised API log | production filesystem and webserver: `curl -I .../storage/logs/api.log` — a 200 turns this from a survey into an ongoing exposure |
| P1-OWN-06 (#47) branch protection | a GitHub repository setting; no workflow can assert it and this session's GitHub surface cannot write it |
| P1-OWN-09 (#88) gateway-secret exposure | production access logs; criteria 1 and 4 are doc-and-doable, 2 and 3 are not |

### B.3 Vendor and provider dependencies

| | |
|---|---|
| **Paymera callback contract** | signature field, algorithm, secret, captured-amount field and amount unit. One message to one provider, and it blocks the **only currently enforced** payment type. |
| **Sandbox credentials** | Authorize.Net, Khalti, and (lower priority) CinetPay / Easebuzz / PaiementPro |
| **A public callback hostname** | reachable by each sandbox; `PaymentService` hard-codes `https://storesyria.com` |
| **An owner decision** | `paytr`'s `test_mode` is hard-coded to `0` — there is no test path |
| **D9's patch mechanism** | reviewed vendor patches are permitted, but no `patches/` directory and no `cweagans/composer-patches` exist yet. This gates the vendored half of P1-SEC-07, P1-INV-20 item 3 and P1-INV-22 items 2–4. |

---

## C. What was actually tested

Evidence, by what produced it. Nothing below is inferred from a green run elsewhere.

| | |
|---|---|
| **Feature / Unit / Contract** | the full gate, run on the delivered tree — result recorded in §C.1 |
| **New behaviour tests** | `PendingPaymentAmountSourceTest` 20 · `PendingPaymentGatewayCensusTest` 5 · `OrderReturnWorkflowTest` 10 |
| **Regression on touched areas** | Commerce 564 passed / 7 skipped · Security Order+Return 562 passed / 1 skipped · inventories and migration hygiene 32 passed |
| **Removal checks** | **24 mutants across the three packages, 23 bite.** The one survivor is invisible on SQLite and is closed by a MySQL script instead. |
| **Real MySQL 8.0.46** | `verify-pending-partial-payment-lookup-on-mysql.php` 27/27 · `verify-product-review-category-set-null-on-mysql.php` all assertions |
| **CI guard** | `guard-no-new-root-uploads.sh` proven to bite on a probe commit, then the probe removed |

**Why two findings needed a real database.** `order_partial_payments.id` is BIGINT, so MySQL
coerces `'5abc'` to row 5 where SQLite matches nothing; and `customer_id` is declared `string()`
while the module inserts an integer, so with Laravel binding `PDO::PARAM_INT` MySQL converted the
column and `->where('customer_id', 7)` matched `'7'`, `'7abc'`, `' 7'` **and** `'07'` where SQLite
matched only `'7'`. A SQL-side ownership predicate is unsound on the engine that matters and well
behaved on the engine the suite runs — the worst combination available.

### C.1 Gate result on the delivered tree

Run locally on the tree this report is committed with:

```
guard-no-workdo-changes.sh        packages/workdo/ unmodified          exit 0
guard-no-new-root-uploads.sh      no file added under uploads/         exit 0
Unit                              351 passed (1837 assertions)
Contract                           26 passed (83 assertions)
Feature                          5632 testcases
                                    2 failures   (manifest declares 2)
                                   17 skips      (manifest declares 17)
                                    0 errors
known-outcome gate               OK -- the observed outcome set matches the manifest
                                 exactly, in both directions, nothing undeclared
```

**And this is not a claim that the suite is green.** It is a claim that it fails and skips exactly
what the project has recorded, for the reasons it has recorded. Nothing was added to
`.github/known-test-outcomes.json` to obtain that result.

**CI, independently, on the pushed head `f881506e`** (run 40041336789): all six jobs success,
including `composer audit must not grow`, the `packages/workdo/` guard, both required test jobs and
the merge-base regression gate.

### C.2 What was NOT tested

- **No gateway provider was contacted.** Every payment assertion is against the application's own
  state, never against a provider's answer.
- **No browser was driven.** The RTL/LTR and contrast checks remain a documented manual run.
- **No DNS or SSL was verified.** Wildcard coverage for `*.storesyria.com` is unverified.
- **No production database was read.** Every MySQL result above is from a throwaway database this
  container creates and drops.

---

## D. Production actions: performed versus not performed

### D.1 Performed

**None.** No production action of any kind was taken from this session.

### D.2 Not performed, and why

| action | status |
|---|---|
| Rotate `APP_KEY` or any config secret | **NOT PERFORMED** — D7, explicit |
| Delete or rewrite historical user uploads in git | **NOT PERFORMED** — D11, explicit |
| Run any migration against any live database | **NOT PERFORMED** — the #117 migration is **written, not run**; applying it is gated on D15 |
| Modify live data | **NOT PERFORMED** |
| Merge to `main` | **NOT PERFORMED** — PR #3 stays Draft |
| Deploy | **NOT PERFORMED** |
| Weaken a CI guard to obtain green | **NOT PERFORMED** — the audit ceiling was **lowered** 58 → 55, not raised; nothing was added to `known-test-outcomes.json` |

### D.3 One CI red, diagnosed rather than suppressed

Run 40033599786 failed on `composer audit must not grow`, 58 → 59, against a `composer.lock`
**byte-identical to `main`** (`git diff origin/main...HEAD -- composer.lock` is empty). No
dependency entered the lock; the advisory database grew. `symfony/yaml` was pinned at v7.2.0 while
every other symfony component sat at v7.4.x and carried **four** of the 59 advisories, all fixed by
7.4.18. One package moves in the lock, no major crossed, `laravel/framework` untouched, and it is
`packages-dev` so it does not ship under `--no-dev`. Measured after: **55**. The ceiling was lowered
to match, which tightens the ratchet below where it stood before the advisory appeared.

---

## E. What remains unresolved, and whether it blocks Phase 01 acceptance

### E.1 Does not block acceptance

- The **14 EXECUTABLE-NOW** rows. They are unblocked and specified; executing them is work, not a
  decision, and Phase 01's acceptance criteria do not require all 28 BLOCKED rows closed.
- **P1-ORD-02 criteria 1 and 3** — one seam for every order writer, and the reconciler's
  exact-decimal assertion. Gated on P1-ORD-01, which the owner has deferred.
- **P1-PAY-04 (#26)** — correctly waiting on GATE F.

### E.2 Blocks acceptance until the owner or the environment supplies something

| | |
|---|---|
| **GATE F** (P1-MIG-01, -04) | 15 tables that live code reads and writes have no migration, 6 money-bearing. Needs the restored-backup dump. |
| **P1-OWN-08 (#86)** | `shetabit_visits` holding plaintext passwords and cookies. The **code** hole is closed; the **rows** remain. This is the one live data exposure rather than a latent defect. |
| **Release gates** | production MySQL verification, DNS/SSL, gateway sandboxes, browser checks — all recorded **DEFERRED / NOT VERIFIED**, never PASSED. |

### E.3 Two rows are stale and should not be paid for twice

- **P1-PLT-02 criterion 1** — all five plan-payment handlers already write `PlanOrder::create(...)`
  instead of a blank host order, with a test covering it. Only the live NULL-store **count** is
  left.
- **P1-MIG-02 criteria 1–2** — already verified on real MySQL 8.0.46, 31 checks / 0 failures. Only
  *application to a persistent database* remains.

### E.4 Escalated: one active exposure, outside the row that should have caught it

**407 tracked user uploads at the repository root are served directly by the webserver**, because
the `.htaccess` rewrite into `public/` only fires when the file does **not** exist at the root. Full
analysis, the single read-only check that confirms it, and the prepared containment are in
`docs/security/P01-OWN-10-ROOT-UPLOADS-EXPOSURE.md`.

The growth half is **remediated** (CI guard, proven to bite). The serving half is **held**, because
the one-line `.htaccess` change can 404 live product images and which way it falls is a fact about
the production filesystem this container cannot see.

### E.5 Escalated: a disabled gateway success check

`PaiementProPaymentController::getProductStatus` has its only gateway check commented out:

```php
// if ($request->responsecode == 0) {
```

so the handler writes an order for **any** return value. `git blame` puts this at the boundary
commit — it was inherited, not introduced by this project.

**Deliberately not "fixed" by uncommenting**, and the reason is the direction of the failure: if
PaiementPro's real return does not carry `responsecode`, restoring the condition stops every
legitimate order from being written while the customer is still charged. That is worse than what is
there now, on a running shop. The correct fix is the P1-PAY-03 design — an unverifiable gateway
resolves to **Pending** rather than Paid — which the sweep lists as EXECUTABLE-NOW and which needs
no provider credentials for its refuse limb.

### E.6 Three further findings recorded while specifying the gateway work

| | |
|---|---|
| `cinet_pay_secret_key` | a **required** settings field, read back, and used by **no** CinetPay call — which is exactly the value an HMAC check on that return would need |
| `paymentwall_private_key` | read with a **trailing space** in the `isset()` and **two** trailing spaces in the value, so `secret_key` is permanently `''`. Not corrected here: correcting it would **activate** a gateway code path that has never run. |
| `paiementpro_mode` | collected by the settings screen and read by nothing |

A single census test would catch this whole class: every `required_fields` entry in
`getPaymentGateways()` must be read somewhere in `app/`.

---

## F. Bottom line

Three owner decisions were executed with measured before-and-after evidence, one was documented as
deferred with its safety evidence, one was specified as infrastructure, one was correctly left
waiting on GATE F, and one was completed earlier in the day. **No owner question remains open on any
of the 28 BLOCKED rows.** What is left is engineering (14 rows), records (3), and things only the
owner's environment can supply (11) — of which seven collapse into a single verified backup.

Phase 01 acceptance is **not** blocked by an unanswered decision. It is blocked by GATE F, by the
`shetabit_visits` data, and by the release gates — all of which are environment, and all of which
are recorded as DEFERRED / NOT VERIFIED rather than passed.
