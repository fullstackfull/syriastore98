# Master Plan — requirement traceability matrix

**Generated from a read-only recovery pass over every phase's authoritative sources.**

> **AUTHORITY WARNING - REVISED 2026-11-16.** The owner-approved *Master Product Plan / Decision
> Register v1.0 (2026-10-14)* was **recovered from preserved project conversations and is now
> committed** at `master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md`, with its three approved amendments
> in `...-WITH-AMENDMENTS.md` and all 181 decision IDs registered in
> `master-plan/DECISION-ID-REGISTER.md`.
>
> **Every row below was written BEFORE that recovery**, against the best derivation then available,
> and **no row's status was changed by the recovery.** Three things follow:
>
> 1. A row may now be **re-traced against REGISTER authority** - the requirement's own text. Until a
>    row is re-traced, its authority is what it says: DIRECT, DERIVED or INFERRED.
> 2. **One INFERRED reading is known to be wrong.** `PRD-001` was read as governing honest metric
>    presentation; the register defines it as *"full product editor"*. The rows citing it are
>    identified in `DECISION-ID-REGISTER.md` section 4.1. **No row may be marked VERIFIED COMPLETE
>    on INFERRED authority alone** - this is why.
> 3. **Eighteen recovered requirements are not in this matrix at all**, because their text was
>    unavailable when it was built. They are `GAP-182`...`GAP-199` in the gap register, category R,
>    all at `COVERAGE NOT ASSESSED`. **Ten of the eighteen fall in Phases 03 and 04, which are
>    already declared engineering-complete.**
>
> **Total alignment can now be certified against the RECOVERED plan.** It still cannot be certified
> against a pre-reorganization original, which the recovery document itself declines to claim to be.

## Totals

**350 requirements recovered. 172 VERIFIED COMPLETE. 178 are not.**

**Plus 18 recovered on 2026-11-16** and held in the gap register at `COVERAGE NOT ASSESSED`, so the
project-wide figure is **368 known requirements, 172 verified complete, 196 not.**

These are exact counts of recovered requirements. They are measured against the recovered plan, not
against a pre-reorganization original.

> **Counts corrected 2026-11-20, and now pinned by a test.** The table below had drifted from the
> body it summarises: it read `PARTIALLY IMPLEMENTED 76` / `NOT IMPLEMENTED 47` while the rows
> themselves held 79 and 44 — three rows had been re-traced from NOT to PARTIAL without the summary
> following, so the headline understated what had been looked at. Nothing checked, which is the same
> failure as a metric citing a section that does not exist. `MasterPlanTraceabilityCountsTest` now
> asserts every status is in the controlled vocabulary and that each count equals the number of rows
> carrying it, so a status change that forgets the summary fails CI instead of ageing quietly.
> `P05-12` moving to VERIFIED COMPLETE the same day is the only *substantive* change to the figures.
>
> **2026-11-21.** `P05-15` (P5-8 Export) moves PARTIALLY IMPLEMENTED -> VERIFIED COMPLETE, so
> VERIFIED COMPLETE 171 -> 172 and PARTIALLY IMPLEMENTED 78 -> 77; the 350 total is unchanged and
> Phase 05/06 goes 2 -> 3 verified. The row's own stated next action ("give a report screen a
> caveat consumer, then the six exports have something to carry") is what was built: five of the
> six `ReportController` exports now carry a disclosure column, and the sixth was MEASURED to be
> counts-only rather than skipped. Two residuals are named in the row and are not folded into it
> -- `GAP-217` (two hintless money tiles on `_sales_stats`) and `P05-05`'s unfiltered predicates.

| status | count |
|---|---|
| VERIFIED COMPLETE | **172** |
| PARTIALLY IMPLEMENTED | **77** |
| NOT IMPLEMENTED | **43** |
| IMPLEMENTED - VERIFICATION INCOMPLETE | **19** |
| OWNER-APPROVED DEFERRED | **17** |
| OWNER DECISION REQUIRED | **11** |
| EXTERNALLY BLOCKED | **9** |
| ACCEPTED OUT OF SCOPE | **2** |

| phase | recovered | verified complete | not complete |
|---|---|---|---|
| 01 Foundation — architecture, security, tenant isolation, pe | 70 | 15 | **55** |
| 02 Merchant Experience | 114 | 100 | **14** |
| 2.5 Premium Merchant Experience | 52 | 29 | **23** |
| 03 Storefront, Themes, Official Website and Documentation (M | 36 | 10 | **26** |
| 04 Commerce (P4-0 .. P4-9) | 43 | 15 | **28** |
| 05 Marketing & Analytics AND 06 SaaS & Premium Super Admin | 35 | 3 | **32** |

---


## Phase 01 Foundation — architecture, security, tenant isolation, permissions, database integrity, API compatibility, CI, design-system foundations, release prerequisites

### `P01-A1` — A. Task completion

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Every task in the ledger is `DONE`, or `BLOCKED` with a named owner decision" — shown by "`phase-01-tasks.json` + the GitHub milestone agree, with no task left `IN_PROGRESS`"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §A, row A1`
- **Owner decisions:** None found authorising the PARTIAL status or waiving A1. HANDOFF §13a records it as disclosed, not approved.
- **Expected behaviour:** Every one of the 109 ledger rows reads DONE, or BLOCKED with a named owner decision; the GitHub milestone matches; nothing is IN_PROGRESS.
- **Actual behaviour:** I ran `python3 docs/development/phase-1/tools/ledger-tool.py counts`: DONE 82 · PARTIAL 4 · BLOCKED 21 · BACKLOG 2 = 109, owner-decision 26. Six rows (4 PARTIAL + 2 BACKLOG) are neither DONE nor BLOCKED. Two of the 21 BLOCKED rows carry `owner_decision: false` (P1-PLT-02, P1-OWN-01) — they are blocked on an environment observation, not a named owner decision. PARTIAL is disclosed in HANDOFF-CURRENT.md §13a as "a seventh status value, introduced during execution and NOT owner-authorised". The GitHub-milestone half I could not check from this container.
- **Implementation:** docs/development/phase-1/phase-01-tasks.json
- **Evidence:** ledger-tool.py counts, executed in this pass; docs/development/phase-1/phase-01-tasks.json
- **Gap:** 6 rows are in statuses the criterion does not admit, one of which (PARTIAL) was never owner-authorised; 2 BLOCKED rows name no owner decision; milestone agreement unverified.
- **Responsible:** Phase 01 closure owner + repository owner
- **Next action:** Either obtain an owner decision admitting PARTIAL/BACKLOG as closing states for those 6 rows, or resolve each to DONE/BLOCKED-with-named-decision; reconcile with the GitHub milestone.

### `P01-A2` — A. Task completion

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "No task is `DONE` on a commit alone" — "each `DONE` row carries acceptance evidence: commit sha, test names, measured numbers"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §A, row A2`
- **Expected behaviour:** Every DONE row's evidence field names a commit, the tests and the measured numbers.
- **Actual behaviour:** Measured in this pass over phase-01-tasks.json: 0 of 109 rows has an empty `evidence` field, 0 DONE rows have empty evidence, and 0 rows lack an issue number. Rows I read (P1-PLT-02, P1-INV-03, P1-PAY-03) carry commit shas, test names and measured numbers. The earlier audit recorded a qualification I did not re-check: for P1-INV-11 and P1-ORD-12 the mutation counts appear only in commit messages, with no on-disk record.
- **Implementation:** docs/development/phase-1/phase-01-tasks.json
- **Evidence:** Programmatic check over docs/development/phase-1/phase-01-tasks.json in this pass (0 empty evidence fields, 0 missing issue numbers)
- **Gap:** Two rows' measured numbers may live only in commit messages (carried over from the earlier audit; not re-checked here).
- **Responsible:** Phase 01
- **Next action:** Optionally move P1-INV-11 and P1-ORD-12's mutation counts on-disk, following the pattern in docs/security/P1-INV-14-MODULE-ORDER-MUTATION-VERIFICATION.md.

### `P01-A3` — A. Task completion

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Every corrective task is linked to the work it corrects" — shown by "ledger `queue_refs` and the issue cross-links"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §A, row A3`
- **Expected behaviour:** Each corrective ledger row carries queue_refs pointing at the S-row it closes, and the issue cross-link.
- **Actual behaviour:** Measured in this pass: 11 of 109 rows carry no `queue_refs` — P1-INV-21, P1-INV-22, P1-INV-23, P1-INV-24, P1-INV-25, P1-ORD-15, P1-SEC-07, P1-SEC-33, P1-SEC-34, P1-SEC-35, P1-TST-01. The earlier Final Decision Report found 8; the number has grown to 11, so this has drifted further since it was first marked FAIL. All 109 rows carry issue numbers, so the issue half holds.
- **Implementation:** docs/development/phase-1/phase-01-tasks.json
- **Evidence:** Programmatic check over docs/development/phase-1/phase-01-tasks.json in this pass
- **Gap:** 11 rows have no queue_refs; the Final Decision Report called this "cheap to fix" and it was not fixed before closure.
- **Responsible:** Phase 01
- **Next action:** Add queue_refs to the 11 named rows, or record for each that it corrects no queue row.

### `P01-B1` — B. Security and identity

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "No storefront or admin handler derives its subject, store, price or quantity from caller input unchecked" — shown by "the security suites, each control removal-checked"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §B, row B1`
- **Owner decisions:** None closing these. The AddCustomer row cites the owner instruction "No silent payment behavior or authentication policy changes" as the reason it is not fixed — that is a constraint on how to fix it, not an approval to leave it.
- **Expected behaviour:** On every reachable handler, the subject/store/price/quantity is derived from the authenticated principal or the server's own state, never from unchecked request input.
- **Actual behaviour:** The named Phase 01 exception is closed: `AccountProfileController::edit_address_form` (:1749) now resolves through `ownedAddress()` (:1773), and 6 address sites route through that one predicate. But the criterion is universal and the work queue still carries open rows of exactly this shape: `POST api/admin/AddCustomer` has no permission check and no operator limb — state recorded as "MEASURED, PINNED, NOT FIXED — awaiting an owner decision", with a staff member holding no permissions measured getting 201 and a chosen password; and an S3 row where the order-status webhook fires the CALLER's store's URL with the ORDER's data.
- **Implementation:** app/Http/Controllers/AccountProfileController.php (ownedAddress); app/Http/Controllers/Api/DashboardController.php::addCustomer() is the unfixed site
- **Evidence:** tests/Feature/Security/ (274 files); tests/Feature/Security/AdminApiCustomerCreatePermissionTest.php pins the AddCustomer behaviour AS IT IS, not as it should be
- **Gap:** AddCustomer permission gate unfixed (owner decision pending because it would make a shipped Flutter endpoint start refusing); the order-status webhook store-selection leak unfixed.
- **Responsible:** Owner decision + engineering
- **Next action:** Obtain the owner decision on the AddCustomer gate (403, operator-first), then fix; fix the webhook sink selection to the order's store.

### `P01-B2` — B. Security and identity

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Cross-tenant reads and writes are refused on every reachable path found in Phase 01" — shown by "`tests/Feature/Tenancy` + `tests/Feature/Security`, and the S-row table in the queue"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §B, row B2`
- **Owner decisions:** The P1-INV-16 ledger row records that adding Product to scoped_models "is needed IF the vehicle chosen is..." — i.e. it is an open owner-facing choice, not an approved deferral.
- **Expected behaviour:** Every cross-tenant read or write path discovered in Phase 01 is refused, with a driven negative test per path.
- **Actual behaviour:** The three exceptions the Final Decision Report named are closed in the ledger (P1-SEC-12, P1-SEC-28, P1-INV-03 all DONE). But the structural cause is still open and CLAUDE.md states it as current: `App\Models\Product` is absent from `config/tenancy.php`'s `scoped_models` — I confirmed the list by reading lines 141-400 (Menu, SupportTicket, SupportConversion, FlashSaleCondition, ThemeCustomizeOrder, NotifyUser, OrderRefund, OrderNote, Customer, Cart, DeliveryBoy) — so `Product::find($id)` still adds no predicate for any principal. The work queue's S3 webhook row is an open operator-reachable cross-store disclosure.
- **Implementation:** config/tenancy.php (Product deliberately absent, reasons documented in the file)
- **Evidence:** tests/Feature/Tenancy/ (26 files), tests/Feature/Security/ (274 files)
- **Gap:** Product is unscoped platform-wide; the Final Decision Report's §F.2 proposed adding it to scoped_models as "one change that closes a whole class" and that was not done. One operator-reachable cross-store webhook disclosure remains.
- **Responsible:** Owner decision + engineering
- **Next action:** Decide whether Product joins scoped_models (broad behavioural change) or whether per-handler predicates remain the control, and record it; close the webhook sink row.

### `P01-B3` — B. Security and identity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Merchant, customer and driver token boundaries hold under the `custom.auth` guard-class trap" — shown by "a driven test per boundary, with a merchant and a driver token — not only a customer token"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §B, row B3`
- **Expected behaviour:** For each boundary, a test drives a merchant token and a driver token (not only a customer token) and asserts refusal.
- **Actual behaviour:** tests/Feature/Security/ResidualSafetyCorrectionsTest.php exists; the Final Decision Report records `::test_neither_a_merchant_nor_a_driver_token_can_enrol_a_user` driving a merchant and a driver token and asserting zero rows written, run green in that session's Security suite, plus AuctionBidForgeryTest acting as the deliveryboy guard directly. I confirmed the file exists; I did not re-run it in this pass.
- **Implementation:** app/Http/Middleware/CustomAuth.php; ApiController::resolveStoreCustomer()
- **Evidence:** tests/Feature/Security/ResidualSafetyCorrectionsTest.php; recorded green run in docs/development/phase-1/PHASE-01-FINAL-DECISION-REPORT.md §I row B3
- **Gap:** Proven per endpoint, not per journey step — that shortfall is tracked separately as G3.
- **Responsible:** Phase 01
- **Next action:** None for B3 itself; see G3.

### `P01-B4` — B. Security and identity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Role and permission escalation is refused, including the finance-permission denylist gap" — shown by "`P1-SEC-02`'s negative tests"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §B, row B4`
- **Expected behaviour:** Role/permission escalation (including the RoleController users.type rewrite and the six unseeded finance permission names) is refused, proven by negative tests.
- **Actual behaviour:** P1-SEC-02 is DONE in the ledger with evidence; the Final Decision Report marks B4 PASS with FinancePermission's operator-first ordering as the recorded fix. Not re-driven in this pass.
- **Implementation:** Not re-read in this pass
- **Evidence:** P1-SEC-02 ledger row evidence; the Security suite recorded green in PHASE-01-FINAL-DECISION-REPORT.md §I row B4
- **Responsible:** Phase 01
- **Next action:** None.

### `P01-B5` — B. Security and identity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Session hygiene: logout symmetry, regeneration, `same_site`, CSRF path-matching, MFA ordering" — shown by "`P1-SEC-01`'s tests in both locales"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §B, row B5`
- **Owner decisions:** D-01 — SESSION_SAME_SITE stays at its current value (docs/development/phase-1/15-DEFERRED-OWNER-DECISIONS.md §D-01).
- **Expected behaviour:** All five session-hygiene properties hold, with tests in Arabic and English.
- **Actual behaviour:** P1-SEC-01 is DONE; docs/security/P01-SESSION-HYGIENE.md exists. The Final Decision Report marks B5 PASS but states plainly that it "rests on the suite rather than on a targeted run" and that the locale-specific cases were not singled out. Owner decision D-01 keeps SESSION_SAME_SITE at its current value.
- **Implementation:** Not re-read in this pass
- **Evidence:** P1-SEC-01 ledger row; docs/security/P01-SESSION-HYGIENE.md
- **Gap:** The both-locales limb was never singled out in a targeted run — the report says so at the row.
- **Responsible:** Phase 01
- **Next action:** If locale coverage matters for acceptance, run the P1-SEC-01 tests in both locales and record the output.

### `P01-B6` — B. Security and identity

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Every remaining security row is either closed with evidence or recorded as an owner decision" — shown by "`docs/security/P01-SECURITY-WORK-QUEUE.md`, S1…S37"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §B, row B6`
- **Owner decisions:** None closing the S1/S2/S3 rows at the head of the queue.
- **Expected behaviour:** No security row sits in neither state; every one is closed with evidence or recorded as an owner decision.
- **Actual behaviour:** The P1-SEC-22 failure the Final Decision Report named is DONE in the ledger. The queue itself has since grown rows that are neither closed nor owner-decided at the time of writing: the S2 AddCustomer row's own text says CLAUDE.md sends readers to this queue and "this one was escalated to the owner in a report and a findings document without a row in the queue — so the one place a reader is told to look did not have it"; the S3 webhook row was "recorded here rather than repaired"; the S1 BackupRestore row opens on an owner action that has not been taken.
- **Evidence:** docs/security/P01-SECURITY-WORK-QUEUE.md
- **Gap:** Open rows exist that are recorded but not decided; the queue has demonstrably lagged behind escalations at least once.
- **Responsible:** Owner decision + engineering
- **Next action:** Walk the queue head-to-tail and give every open row either closure evidence or a dated owner decision.

### `P01-C1` — C. Orders, checkout and monetary integrity

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "One server-authoritative total per order writer; no posted money field reaches a persisted total" — shown by "the survey test pattern, failing when a writer bypasses the seam"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §C, row C1`
- **Owner decisions:** None approving the deferral. The ledger classifies it as "a release-scale decision", i.e. a decision not yet taken — not an approved one.
- **Expected behaviour:** Every order writer derives its total through one seam; a writer that bypasses it fails a survey test.
- **Actual behaviour:** Measured in this pass from tests/Feature/Commerce/OrderTotalReconcilerCoverageTest.php: COVERED = 3 files (PaymentService.php, PaymentController.php, Api/ApiController.php); DECLARED_UNCOVERED holds the rest of the 16 writers. The ledger row P1-ORD-02 criterion 1 reads "OPEN and NOT STARTED. This is the cutover". The 3 that do reconcile do so WARN-ONLY — promoting `warnIfInconsistent()` to `assertConsistent()` refuses orders and is recorded as a release decision. The posted-money-field limb IS closed (criterion 2, commits a2a5b9ca and 13bec7d3).
- **Implementation:** app/Services/OrderTotalReconciler.php (13,341 bytes)
- **Evidence:** tests/Feature/Commerce/OrderTotalReconcilerCoverageTest.php — asserts the covered/uncovered partition in both directions
- **Gap:** 13 of 16 writers do not reconcile; the 3 that do are warn-only; no single derivation seam exists.
- **Responsible:** Owner decision + engineering (carried into Phase 02 per HANDOFF §13c)
- **Next action:** Owner decision on unifying the derivation seam and on promoting warn-only reconciliation to refusal, then the cutover.

### `P01-C2` — C. Orders, checkout and monetary integrity

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "A coupon that is printed is a coupon that is charged" — shown by "`P1-ORD-01`'s positive tests per coupon type"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §C, row C2`
- **Owner decisions:** The owner directed "defer the redesign, defer no confirmed integrity defect; document the deferral and its safety evidence" (PHASE-01-OWNER-EXECUTION-REPORT.md §A.1 item 6). That approves deferring the REDESIGN only — the 12 unestablished writers are not a documented safety claim.
- **Expected behaviour:** On every writer that prints a coupon, the charged total is reduced by that coupon, for each coupon type.
- **Actual behaviour:** P1-ORD-01 is PARTIAL: 5 of 7 criteria closed, and only for the storefront writer `api/{slug}/place-order`. The row states criteria 1 and 4 are "NOT ESTABLISHED for the other 12 writers that persist coupon_price — the subtraction is written three different ways and no source scan can settle it", and explicitly withdraws an earlier "criterion 4 CLOSED" claim as an overstatement. An unauthorized discount of unbounded size (10% coupon yielding 100,000.00 off a 200.00 basket) was fixed on that one writer.
- **Implementation:** Storefront writer api/{slug}/place-order; app/Models/Cart.php, app/Models/Utility.php
- **Evidence:** tests/Feature/Commerce/OrderTotalCouponTest; docs/security/P01-ORD-01-COUPON-POLICY-DEFERRAL.md
- **Gap:** 12 other coupon_price writers unestablished in either direction; blocked behind C1.
- **Responsible:** Owner decision (C1) + engineering
- **Next action:** Establish, writer by writer, whether the printed coupon is the charged coupon; close C1 first.

### `P01-C3` — C. Orders, checkout and monetary integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Invalid quantities are refused at both ends — non-positive **and** above the sanity ceiling" — shown by "the three cash-on-delivery writers plus the gateway returns"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §C, row C3`
- **Expected behaviour:** Non-positive, fractional, non-numeric and above-ceiling line quantities are refused on the three COD writers and on the gateway returns.
- **Actual behaviour:** Closed, but by later-phase work, not by Phase 01. `GuardsGatewayReturn::MAX_SANE_LINE_QUANTITY = 1000000` and the shared `basketHasUnusableQuantity()` helper are now wired at 6+ sites: PaymentService.php:2538, AuthorizeNetPaymnetController.php:433 and :672, Api/ApiController.php:3655 and :9237, PaymentController.php:3549 and :5000. The in-code comment records what was open at Phase 01 close: "qty 2147483647 on a 50-unit product: products.product_stock 50 -> -2147483597, order written, product_price = final_price = 21474836470.0, status 'Paid' ... still open on the three writers that take cash on delivery. GuardsGatewayReturn::MAX_SANE_LINE_QUANTITY existed and was wired into none of them."
- **Implementation:** app/Http/Controllers/Concerns/GuardsGatewayReturn.php:110,687; 6 call sites listed above
- **Evidence:** tests/Support/Security/bin/verify-cart-quantity-guard-on-mysql.php (P01-SEC-14 E5, real MySQL); the measured exploit figures recorded at ApiController.php:3640-3652
- **Responsible:** Closed in Phase 04; Phase 01 ledger not updated
- **Next action:** None. Record in the Phase 01 ledger that C3 was closed by Phase 04 (F276 / P01-COMMERCE-SEC-01), so the Phase 01 acceptance matrix stops reading PARTIAL.

### `P01-C4` — C. Orders, checkout and monetary integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "No partial write: a refused basket leaves no order, no stock movement and no orphan decrement" — shown by "the orphan-decrement test, with fixture order stated"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §C, row C4`
- **Expected behaviour:** A refused basket leaves no order row, no stock movement and no orphan decrement.
- **Actual behaviour:** OrderWriteTransactionTest, CheckoutCartPreservationTest and CheckoutPreflightStockTest are recorded on disk and inside a green Commerce run (443 passed / 7 skipped). The Final Decision Report marks C4 PASS but states the orphan-decrement fixture was not re-driven individually, so it rests on the suite. tests/Feature/Commerce/ holds 76 files including GatewayCancelReturnWritesNoOrderTest.php and PreTransactionStockGiveBackTest.php.
- **Implementation:** Not re-read in this pass
- **Evidence:** tests/Feature/Commerce/ (76 files) incl. GatewayCancelReturnWritesNoOrderTest.php; tests/Feature/Security/PreTransactionStockGiveBackTest.php
- **Gap:** The criterion's "with fixture order stated" was not singled out.
- **Responsible:** Phase 01
- **Next action:** None.

### `P01-C5` — C. Orders, checkout and monetary integrity

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Returns and refunds restore exactly what the order took, and only for the owning store" — shown by "`P1-INV-02`, `P1-INV-05`"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §C, row C5`
- **Expected behaviour:** A return or refund restores exactly the units the order took — product, variant and per-line — and only within the owning store.
- **Actual behaviour:** The Final Decision Report marked C5 FAIL on `OrderRefundController::restorationPlan()` placing an entry on any line of the same product with no bound when no counted line fits. P1-ORD-14 (#83) and P1-INV-05 (#31) are both DONE in the final ledger; the closeout records #31 closed by `ReturnRestoresExactlyWhatWasTakenTest`, which "drives the real decrement then the real /order-return and asserts net zero for product, variant and per-line. 4 mutants, all bite." I confirmed tests/Feature/Security/ReturnRestoresExactlyWhatWasTakenTest.php exists, plus RefundStockIdempotencyTest, ReturnApprovalStockTest, SecondReturnTotalTest and RefundIntegrityTest in tests/Feature/Commerce/. I did not re-read restorationPlan() in this pass.
- **Implementation:** app/Http/Controllers/OrderRefundController.php (not re-read here)
- **Evidence:** tests/Feature/Security/ReturnRestoresExactlyWhatWasTakenTest.php; tests/Feature/Commerce/{RefundStockIdempotencyTest,ReturnApprovalStockTest,SecondReturnTotalTest,RefundIntegrityTest}.php
- **Gap:** I found no artefact that re-states the C5 criterion as met after P1-ORD-14 closed; the only full A–H assessment on disk still reads FAIL at this row.
- **Responsible:** Phase 01 closure owner
- **Next action:** Re-assess C5 against the closed P1-ORD-14/P1-INV-05 evidence and record the verdict, so the acceptance matrix is not left stale at FAIL.

### `P01-D1` — D. Payments and financial integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Payment state transitions are derived, never asserted from a browser return" — shown by "`PaymentVerificationService` is the only writer of `Paid`, and each unverified write is logged"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §D, row D1`
- **Owner decisions:** D2 (2026-10-22) — "a return URL, redirect or client flag proves nothing"; D-05 payment verification posture.
- **Expected behaviour:** No browser return can assert a payment state; PaymentVerificationService derives it and is the only writer of Paid; unverified writes are logged.
- **Actual behaviour:** app/Services/PaymentVerificationService.php exists. The ledger records the no-record rule at :294-304 sitting ABOVE the ENFORCED_PAYMENT_TYPES test at :324, so the Pending default governs all 52 payment types. PaymentStatusDerivationTest, PaymentStatusNormalizationTest and GatewayReturnTrustBoundaryTest are recorded green. Scope limit stated at the row: derived is not verified-against-the-provider — that is D4.
- **Implementation:** app/Services/PaymentVerificationService.php
- **Evidence:** tests/Feature/Commerce/GatewayReturnTrustBoundaryTest.php; PaymentStatusDerivationTest; recorded green run in PHASE-01-FINAL-DECISION-REPORT.md §I row D1
- **Responsible:** Phase 01
- **Next action:** None.

### `P01-D2` — D. Payments and financial integrity

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Replay of a gateway return cannot produce a second order or a second charge" — shown by "`P1-PAY-02`"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §D, row D2`
- **Owner decisions:** D2 requires "replay/duplicate protection" (15-DEFERRED-OWNER-DECISIONS.md, D1…D18 table).
- **Expected behaviour:** Replaying any gateway return produces no second order and no second charge, on every writer.
- **Actual behaviour:** The Final Decision Report marked D2 FAIL — "No idempotency key exists anywhere" — with PaymeraCallbackIdempotencyTest the single exception. P1-PAY-02 was then "promoted from BACKLOG and closed" in PHASE-01-CLOSURE-EXECUTION.md §7a and is DONE in the final ledger. app/Services/GatewayReference.php (333 lines) exists and tests/Support/Security/bin/verify-gateway-reference-idempotency-on-mysql.php and verify-gateway-return-claim-on-mysql.php exist. I did not read the closure evidence line by line or re-run anything.
- **Implementation:** app/Services/GatewayReference.php
- **Evidence:** tests/Support/Security/bin/verify-gateway-reference-idempotency-on-mysql.php; verify-gateway-return-claim-on-mysql.php; PaymeraCallbackIdempotencyTest
- **Gap:** I did not establish in this pass that the idempotency claim covers all 16 order writers rather than the gateway-return family; the only full A–H assessment on disk still reads FAIL here.
- **Responsible:** Phase 01 closure owner
- **Next action:** Re-assess D2 against the P1-PAY-02 closure evidence, naming the writers covered, and record the verdict.

### `P01-D3` — D. Payments and financial integrity

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** "COD settlement timing is explicit and owner-approved" — shown by "`P1-PAY-04`"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §D, row D3`
- **Owner decisions:** D-06 (APPROVED 2026-10-22) and D1 of the final set; D-15 gates creating cod_collection_events. Cited from docs/development/phase-1/15-DEFERRED-OWNER-DECISIONS.md.
- **Expected behaviour:** COD settlement timing is decided by the owner, recorded, and the shipped state machine implements it.
- **Actual behaviour:** Decided: D-06 (2026-10-22, APPROVED) settled COD timing, and D1 in the final set rejects the trigger the shipped machine uses — "a merchant marking their own order delivered" — requiring that "the actual collector and verified collection event are recorded" and "no wallet credit for creation or for 'Delivered'". P1-PAY-04 (#26) is DONE in the final ledger, and `CodStateMachineTest::test_gate_f_cannot_be_released_while_marked_delivered_is_the_settlement_trigger` goes red if GATE F is promoted before the trigger question is answered. tests/Unit/Database/CodCollectionEventsStillAbsentTest.php exists and `cod_collection_events` was deliberately NOT created.
- **Implementation:** app/Services/CodCollectionService.php; cod_collection_events deliberately absent by D-15
- **Evidence:** CodStateMachineTest (ratchet); tests/Unit/Database/CodCollectionEventsStillAbsentTest.php
- **Gap:** The real collection event still cannot be recorded — the table does not exist by decision — so COD settlement remains tied to a trigger D1 rejects, held safe only because the wallet credit path is inert.
- **Responsible:** Owner (D-15 prerequisites) + engineering
- **Next action:** When GATE F is released, create cod_collection_events and move settlement onto a recorded collection event; until then the ratchet must stay.

### `P01-D4` — D. Payments and financial integrity

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Gateway callback authenticity is enforced for every gateway the owner elects to enforce" — shown by "`P1-PAY-03`, per-gateway fixtures"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §D, row D4`
- **Owner decisions:** D2 (2026-10-22) sets the policy — an unverifiable gateway stays Pending and writes no paid ledger entry — and requires the owner to "surface gateways that must be disabled". That policy limb is met; the per-gateway enrolment is not an approved deferral.
- **Expected behaviour:** For each gateway the owner elects, the callback is verified server-side against the provider — ownership, reference, amount, currency and status.
- **Actual behaviour:** P1-PAY-03 is BLOCKED. Its own acceptance text: criterion 1 "NOT MET as literally written. Three of 52 types are provider-verified (paymera, khalti, nmi). The rest resolve to Pending". Criterion 2 (Khalti's amount binding) was recorded NOT MET and "unblocked engineering, not credentials" — and it has since been fixed: KhaltiPaymnetController.php:936 now records "the amount Khalti is asked about is the SERVER's (`cartlist_final_price` from the stored state) rather than `$payload['amount']`", with Khalti asked exactly once per return. The MET limbs: no gateway payment is recorded Paid without a verification record, and the order is always written so the merchant can reconcile.
- **Implementation:** app/Services/PaymentVerificationService.php; app/Http/Controllers/KhaltiPaymnetController.php
- **Evidence:** tests/Feature/Commerce/PaiementProReturnVerificationTest.php; PaymentStatusDerivationTest; the D-13 block at app/Http/Controllers/KhaltiPaymnetController.php:932-948
- **Gap:** ~48 of 52 payment types have no provider-side verification of any kind; they resolve to Pending, which is safe but is not the criterion. Named commercial residuals: AuthorizeNet performs its own capture and records no verification so it now writes Pending; Khalti's writer refuses on a failed curl, leaving a charged customer with no order row.
- **Responsible:** Owner + credentials (per gateway)
- **Next action:** Owner names the gateways to enforce and supplies a sandbox credential per gateway; then enrol them one at a time. Separately decide the AuthorizeNet and Khalti commercial residuals.

### `P01-D5` — D. Payments and financial integrity

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "GATE F readiness: the money tables are either released with a recovery plan, or contained with the containment proven" — shown by "`guard-gate-f-containment.sh`, and `P1-MIG-01`"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §D, row D5`
- **Owner decisions:** D-15 — "GATE F / production migrations approved, execution conditioned on ten objective prerequisites" and "Do not promote guessed schemas from _gate_f_pending" (15-DEFERRED-OWNER-DECISIONS.md).
- **Expected behaviour:** Either the money tables are released with an approved recovery plan, or they are contained and the containment is proven in CI.
- **Actual behaviour:** The containment limb was proven at Phase 01 close (guard-gate-f-containment.sh exit 0; 235 migration files scanned; 0 of 6 money tables created by any discoverable migration). The schema limb then closed on 2026-11-02: all 15 tables and 3 columns now have migrations in database/migrations/ derived from App\Support\Database\GateFProductionSchema (I confirmed the file and the migrations exist, and both ordering constraints hold from the filenames: 2026_10_26_000100 cost_price < 2026_11_02_000400 store_order_financials; 2026_11_02_000500 commission columns < 2026_11_02_000600 delivery_earnings). The drafts remain in database/migrations/_gate_f_pending/ (9 .draft files). cod_collection_events deliberately not created.
- **Implementation:** database/migrations/2026_10_26_000100…2026_11_02_000600; app/Support/Database/GateFProductionSchema.php
- **Evidence:** tests/Support/Security/bin/verify-gate-f-migrations-on-mysql.php (29 checks, 0 failed); .github/scripts/guard-gate-f-containment.sh; tests/Unit/Database/CodCollectionEventsStillAbsentTest.php
- **Gap:** "Released with a recovery plan" is not met: promotion is a no-op against production by design, so nothing live is reconciled, and D-15's ten prerequisites (restored backup, verified recoverable backup, rehearsal, rollback, NULL store_id inventory) remain outstanding.
- **Responsible:** Owner + production environment
- **Next action:** Satisfy D-15's ten prerequisites against a restored backup before any production application; keep the containment guard in CI meanwhile.

### `P01-DEFECT-PROFILE` — Declared known-bad outcomes

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Same as above. Classification "A REAL APPLICATION DEFECT": GET /profile answers 500 with ErrorException: Attempt to read property "slug" on null
- **Source:** `.github/known-test-outcomes.json → expected_failures → Tests\Feature\ProfileTest::test_profile_page_is_displayed`
- **Owner decisions:** None.
- **Expected behaviour:** The merchant profile page renders for a user with no current_store.
- **Actual behaviour:** Measured, and re-measured 2026-11-10: `route('landing_page', $store->slug)` at HomeController.php:610 and :612, reached from resources/views/partision/header.blade.php:11-12 via getStoreById(getCurrentStore()), which is null for a User with no current_store and no store they created. /profile is served by UserController@profile, so this is the real merchant profile page, not Breeze's.
- **Implementation:** app/Http/Controllers/HomeController.php:610,:612
- **Evidence:** tests/Feature/Platform/AddonMigrationStrandingTest::test_the_profile_page_failure_is_not_this_bug — reddens if it is ever fixed
- **Gap:** Needs a null-store guard at both lines.
- **Responsible:** app/ owner — unassigned
- **Next action:** Add the null-store guard and remove the manifest entry.

### `P01-DEFECT-REG` — Declared known-bad outcomes

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Implied by acceptance criterion F2's manifest discipline: a declared failure must carry a cause, an owner and a classification. This entry's own classification is "OPEN -- ACTIONABLE DEFECT": POST /register silently skips the admin-role assignment, so "the user, the store and the login are committed and the merchant ends up with no role and no error"
- **Source:** `.github/known-test-outcomes.json → expected_failures → Tests\Feature\Auth\RegistrationTest::test_new_users_can_register`
- **Owner decisions:** None. Being declared in the manifest is a CI bookkeeping state, not an owner approval.
- **Expected behaviour:** A merchant who registers is provisioned with a role, or registration fails loudly.
- **Actual behaviour:** Measured and recorded in the manifest: both addRole call sites (:179 and :310) are null-guarded by `if ($role_r)`, so when the admin role is absent the assignment is SILENTLY SKIPPED and a half-provisioned merchant survives. Latent in production (the role is seeded there), live in test. Owner recorded as "app/ -- NOT owned by the CI workstream".
- **Implementation:** app/Http/Controllers/Auth/RegisteredUserController.php:179,:310
- **Evidence:** tests/Feature/Platform/AddonMigrationStrandingTest.php proves it is not the casing bug
- **Gap:** Needs a null guard plus a decision about what to do when the role is absent — the half-provisioned row should not survive.
- **Responsible:** app/ owner — unassigned
- **Next action:** Fix the silent tolerance and delete the manifest entry (the manifest requires a resolved entry to be removed).

### `P01-E1` — E. Inventory and stock

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Product and variant ownership is checked on every reachable mutation — 'scoping the product does not scope the variant'" — shown by "the S36/S37 suites"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §E, row E1`
- **Owner decisions:** None. Final Decision Report §F.2 proposes adding Product to scoped_models as "one change that closes a whole class" and the P1-INV-16 row records that choice as needing an owner decision.
- **Expected behaviour:** Every reachable mutation of a product or variant checks ownership.
- **Actual behaviour:** Confirmed open in this pass by reading config/tenancy.php: `App\Models\Product` is NOT in scoped_models, so StoreScope::isActiveFor() short-circuits and Product adds no predicate for any principal. CLAUDE.md states the same as a current fact and cites a live cross-tenant stock drain (S19) that the customer_id sweep missed. The per-handler work is extensive and largely done (P1-INV-03, P1-INV-10 closed), but the criterion says *every reachable mutation*, which cannot be asserted while the model itself is unscoped.
- **Implementation:** Per-handler predicates in ProductController, PosController, ApiController
- **Evidence:** config/tenancy.php (read in this pass); CLAUDE.md §1 scoped-models table
- **Gap:** No structural control — the per-handler census is the only control, and a census is the failure mode this project has repeatedly hit.
- **Responsible:** Owner decision + engineering
- **Next action:** Owner decision on adding Product to scoped_models; if declined, record why and keep the per-handler census ratcheted.

### `P01-E2` — E. Inventory and stock

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "`stock_management` ON enforces, OFF does not deduct, on every writer" — shown by "`StockManagementSettingTest` (40 cases over four settings states)"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §E, row E2`
- **Expected behaviour:** With stock_management ON every writer enforces stock; with it OFF no writer deducts.
- **Actual behaviour:** tests/Feature/Commerce/StockManagementSettingTest.php exists; the Final Decision Report records it executed as 40 passed / 63 assertions — exactly the criterion's own "40 cases over four settings states".
- **Implementation:** app/Services/ProductStockService.php
- **Evidence:** tests/Feature/Commerce/StockManagementSettingTest.php — 40 passed / 63 assertions, recorded executed
- **Responsible:** Phase 01
- **Next action:** None.

### `P01-E3` — E. Inventory and stock

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Concurrent checkout of the last unit cannot oversell" — shown by "`P1-INV-05`, **on MySQL** (SQLite cannot show it)"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §E, row E3`
- **Expected behaviour:** Ten concurrent checkouts of five units apply exactly five, on real MySQL with true simultaneous transactions and row locks.
- **Actual behaviour:** The property is proven on SQLite: OrderStockConcurrencyTest::test_ten_concurrent_checkouts_cannot_oversell_five_units gives ten callers the stale read before any writes and asserts 5 applied / 5 rejected / 0 remaining, and ::test_the_decrement_is_a_single_guarded_update_statement asserts the mechanism from the query log. The criterion's own "on MySQL" limb was marked NOT VERIFIED — no MySQL in that container. A MySQL harness now exists with 49 verify-*-on-mysql.php scripts, including verify-cart-reconciliation-concurrency-on-mysql.php and verify-order-replacement-concurrency-on-mysql.php, but I found none named for the last-unit oversell case and did not run any.
- **Implementation:** app/Services/ProductStockService.php — single guarded conditional UPDATE
- **Evidence:** tests/Feature/Commerce/OrderStockConcurrencyTest.php (11 passed / 58 assertions, SQLite); tests/Support/Security/bin/run-mysql-verifications.sh + 49 MySQL scripts
- **Gap:** The criterion's explicit "on MySQL" requirement has no matching script; true simultaneous transactions and row locks remain unproven.
- **Responsible:** Phase 01 engineering (environment available — 49 sibling scripts already run there)
- **Next action:** Add a verify-last-unit-oversell-on-mysql.php to the existing harness and run it on real MySQL.

### `P01-E4` — E. Inventory and stock

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Cancellation and return restore stock exactly" — shown by "`P1-INV-05` positive test"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §E, row E4`
- **Expected behaviour:** Cancelling or returning an order restores exactly the stock it took, once.
- **Actual behaviour:** Cancellation was already proven (OrderCancelRestockTest, ReturnApprovalStockTest, RefundStockIdempotencyTest green). The refund-by-line half that made this PARTIAL is tied to C5, and P1-INV-05 (#31) is recorded "DONE and closed. AC3 closed by ReturnRestoresExactlyWhatWasTakenTest ... 4 mutants, all bite." Files confirmed present: tests/Feature/Security/ReturnRestoresExactlyWhatWasTakenTest.php, tests/Feature/Commerce/{OrderCancelRestockTest,MerchantCancelRestockTest,DeliverySyriaReturnRestockTest,RefundStockIdempotencyTest}.php.
- **Implementation:** Not re-read in this pass
- **Evidence:** tests/Feature/Security/ReturnRestoresExactlyWhatWasTakenTest.php; tests/Feature/Commerce/OrderCancelRestockTest.php and siblings
- **Gap:** Same as C5 — no refreshed A–H assessment records E4 as met after P1-INV-05 closed.
- **Responsible:** Phase 01 closure owner
- **Next action:** Re-assess E4 with C5 and record the verdict.

### `P01-F1` — F. Platform foundation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "CI gates run on both event classes, or are explicitly report-only" — shown by "the three gate-wiring tests"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §F, row F1`
- **Expected behaviour:** Each CI gate runs on both push and pull_request, or is explicitly declared report-only, asserted by tests.
- **Actual behaviour:** tests/Unit/Ci/ holds RequiredGateWiringTest.php, RegressionGateTest.php, SensitiveDiffGateTest.php plus AdvisoryGateIsolationTest, SecurityAttestationWiringTest, KnownTestOutcomesCheckerTest, SecretScannerCoverageTest and ClaudeConfigurationTest. The three named gate-wiring tests are present and were recorded inside a green Unit run (349 passed / 1827 assertions).
- **Implementation:** .github/workflows/ci.yml
- **Evidence:** tests/Unit/Ci/{RequiredGateWiringTest,RegressionGateTest,SensitiveDiffGateTest}.php — confirmed present in this pass
- **Responsible:** Phase 01
- **Next action:** None.

### `P01-F2` — F. Platform foundation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "The known-outcome gate exits 0 with the declared set unchanged, and the manifest unmodified" — shown by "`assert-known-test-outcomes.php` + `git diff` on the manifest"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §F, row F2`
- **Expected behaviour:** The gate exits 0, asserting the outcome set in both directions, with no manifest entry added to obtain green.
- **Actual behaviour:** Confirmed by reading .github/known-test-outcomes.json in this pass: expected_failures holds 2 entries, expected_skips holds 1 (down from 17 once GATE F closed), plus require_reason_log=true and minimum_testcases=1000. Both declared failures are classified in the manifest itself as "OPEN -- ACTIONABLE DEFECT" owned by app/: POST /register silently skips the admin-role assignment and provisions a merchant with no role, and GET /profile answers 500 at HomeController.php:610/:612 on a null store. The closure records exit 0 with the manifest unmodified at the delivered tree, and one earlier run that FAILED (exit 1, four undeclared failures) and was declared rather than worked around.
- **Implementation:** .github/scripts/assert-known-test-outcomes.php
- **Evidence:** .github/known-test-outcomes.json read in this pass; .github/scripts/assert-known-test-outcomes.php; tests/Unit/Ci/KnownTestOutcomesCheckerTest.php
- **Gap:** The gate is honest, but the two declared failures are real open application defects that Phase 01 shipped as accepted outcomes — they are tracked as declared, not fixed.
- **Responsible:** Phase 01 (gate) / app owner (the two defects)
- **Next action:** Treat the two declared failures as open defects with owners, not as permanent manifest furniture.

### `P01-F3` — F. Platform foundation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Unit, Feature and Contract suites all run, with the Contract suite pinning the Flutter and Lynomia contracts" — shown by "the three suite runs"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §F, row F3`
- **Expected behaviour:** All three suites run; the Contract suite pins the shipped Flutter admin and Lynomia Chat contracts.
- **Actual behaviour:** tests/Contract/ holds AddCustomerPermissionRefusalContractTest, AdminOrderPayloadShapeContractTest, LynomiaAudienceFieldsContractTest, LynomiaConsentContractTest, LynomiaDeliveryStatusContractTest, OrderStatusLocaleContractTest, StoreOwnerInfoThemeWriteContractTest and a lynomia/ fixture directory. Recorded executed: Unit 349 / Contract 26 / Feature in the gate. CLAUDE.md now cites Unit 1079 and Contract 27. Stated limit at the row: the Contract suite replays a committed artifact of Lynomia's producer output and does not reach a live Lynomia.
- **Implementation:** phpunit.xml testsuites
- **Evidence:** tests/Contract/ (7 test files + lynomia fixtures), confirmed present in this pass; recorded suite runs
- **Gap:** Lynomia is pinned against a replayed artifact, not a live producer — disclosed at the row.
- **Responsible:** Phase 01
- **Next action:** None.

### `P01-F4` — F. Platform foundation

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Schema-sensitive evidence is verified on **real MySQL**, not SQLite" — shown by "`P1-PLT-01`'s harness, with at least one SQLite-masked finding re-verified"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §F, row F4`
- **Expected behaviour:** Anything schema-sensitive is verified on real MySQL, with at least one SQLite-masked finding re-verified there.
- **Actual behaviour:** The harness is substantial and present: 49 scripts under tests/Support/Security/bin/ matching *mysql*, plus run-mysql-verifications.sh. GATE F was proved on a real server (verify-gate-f-migrations-on-mysql.php — 29 checks, 0 failed, log committed under docs/development/phase-2/evidence/phase-01-gates-2026-11-02/). The SQLite-masked finding P01-SEC-13 has verify-sec13-migration-on-mysql.php. I ran none of them in this pass, and the Final Decision Report's session had no MySQL at all.
- **Implementation:** tests/Support/Security/bin/
- **Evidence:** 49 tests/Support/Security/bin/verify-*-on-mysql.php scripts + run-mysql-verifications.sh, confirmed present; verify-gate-f-migrations-on-mysql.php log recorded as 29 checks / 0 failures
- **Gap:** No run in this pass; E3's last-unit oversell case still has no MySQL script.
- **Responsible:** Phase 01 engineering
- **Next action:** Run run-mysql-verifications.sh on the current head and commit the log; add the missing E3 script.

### `P01-F5` — F. Platform foundation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Migration readiness: every parked draft is reviewed, and nothing runnable exists that the owner has not released" — shown by "`guard-gate-f-containment.sh`, `_gate_f_pending/`, `_sec13_pending/`"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §F, row F5`
- **Owner decisions:** D-15 released GATE F conditionally.
- **Expected behaviour:** Every parked draft is reviewed and nothing runnable exists that the owner has not released.
- **Actual behaviour:** guard-gate-f-containment.sh is present in .github/scripts/ and was recorded exit 0 (235 migration files scanned, 0 of 6 money tables created by any discoverable migration). database/migrations/_gate_f_pending/ still holds 9 .draft files plus a README. Since 2026-11-02 the GATE F migrations ARE runnable in database/migrations/ — released under D-15 with structure measured rather than guessed, every down() a documented no-op, and GateFSchemaGuard::assertCompatible() throwing on any disagreement.
- **Implementation:** app/Support/Database/GateFProductionSchema.php; GateFSchemaGuard
- **Evidence:** .github/scripts/guard-gate-f-containment.sh; database/migrations/_gate_f_pending/ (9 drafts retained); verify-gate-f-migrations-on-mysql.php
- **Gap:** The 'review' half of the criterion rests on the drafts' READMEs; P1-MIG-02's customers.password_otp draft remains parked and unapplied.
- **Responsible:** Phase 01
- **Next action:** None for F5; P1-MIG-02 is tracked separately below.

### `P01-F6` — F. Platform foundation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Design System foundation intact and contrast-gated" — shown by "`check-contrast.py` in CI"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §F, row F6`
- **Expected behaviour:** The Store Syria Design System foundation is preserved and a contrast gate runs in CI.
- **Actual behaviour:** Confirmed in this pass: .github/workflows/ci.yml:325 runs `python3 docs/design-system/tools/check-contrast.py public/css/store-syria-ds.css`. The Final Decision Report records it resolving --ss-* var() chains and computing WCAG 2.1 ratios for 40 foreground/background pairs across light and dark, failing if the auto scope drifts from dark. The browser half (browser-checks.cjs) is deliberately a documented manual run, not wired — which is also P1-OWN-05(c), an open owner choice.
- **Implementation:** public/css/store-syria-ds.css; docs/design-system/
- **Evidence:** .github/workflows/ci.yml:325 (read in this pass); docs/design-system/tools/check-contrast.py
- **Gap:** Token-level contrast only — rendered-page contrast checking is not wired (tracked as P1-OWN-05(c) and G8).
- **Responsible:** Phase 01
- **Next action:** None for F6; see P01-OWN-05 and G8.

### `P01-F7` — F. Platform foundation

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Observability: the security-relevant log lines are enumerated and reach a channel an operator reads" — shown by "`P1-PLT-05`"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §F, row F7`
- **Expected behaviour:** Security-relevant log lines are enumerated in one place and demonstrably reach a channel an operator reads.
- **Actual behaviour:** The Final Decision Report marked F7 FAIL (P1-PLT-05 was BACKLOG; "nothing enumerates them in one place and nothing asserts an operator can see them"). P1-PLT-05 was then "promoted from BACKLOG and closed" (PHASE-01-CLOSURE-EXECUTION.md §7a) and is DONE. docs/security/P01-OBSERVABILITY-INVENTORY.md exists (62 KB) and is ratcheted by SecurityLogInventoryTest::test_the_observability_inventory_matches_the_source, keyed on file:line, with a regeneration script at docs/development/phase-1/tools/resync-observability-inventory.py; the ledger records 92 source lines / 92 documented and that planting a new Log::warning makes it report the undocumented line.
- **Implementation:** docs/security/P01-OBSERVABILITY-INVENTORY.md
- **Evidence:** docs/security/P01-OBSERVABILITY-INVENTORY.md; SecurityLogInventoryTest; docs/development/phase-1/tools/resync-observability-inventory.py
- **Gap:** The enumeration half is closed and ratcheted. The second half — "reach a channel an operator reads" — I found no artefact asserting; the inventory names a destination per line but nothing establishes an operator actually receives it, and P1-SEC-14 criterion 3 (the only production observability reading) is still open for production.
- **Responsible:** Owner/operations + engineering
- **Next action:** Record, per channel, who reads it and how that was verified; otherwise state the half as open.

### `P01-G1` — G. Phase-wide verification (the directive's §9)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "End-to-end **merchant** journey: create product with variants → receive order → fulfil → return" — run "as one exercise, not as a sum of task tests"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 1`
- **Owner decisions:** None deferring §G. The 2026-10-24 closing direction accepted engineering completion "subject to the recorded acceptance limitations" and said "No restarted discovery, no repeated tests" — that is not an approval of §G going unrun, and §G is not listed among the recorded limitations.
- **Expected behaviour:** One exercise drives the whole merchant journey end to end.
- **Actual behaviour:** NOT DONE. Confirmed in this pass: `find tests -iname '*journey*' -o -iname '*EndToEnd*' -o -iname '*E2E*'` returns nothing. The segments are covered by task tests; no single test drives the journey. The criterion is explicit that a sum of task tests is not the same thing.
- **Gap:** No end-to-end merchant journey exercise exists.
- **Responsible:** Phase 01 acceptance
- **Next action:** Run §G as one exercise, or obtain an explicit owner waiver naming §G.

### `P01-G10` — G. Phase-wide verification (the directive's §9)

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "CI and the security attestation on the final head"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 10`
- **Expected behaviour:** CI is green and the security attestation covers the final head.
- **Actual behaviour:** Recorded met at closure: "At `1bff9dc7` all 9 check runs are `success` (run 40102841581, read back from the API)", with the attestation boundary covered by PR3-ATTESTATION-S21.md §4, §22 and §23 (the last covering production code and working all eight SECURITY-DIFF-REVIEW questions). The attestation gap was itself found and closed as a release prerequisite that "lives nowhere else". I did not re-read the check runs from the API in this pass.
- **Implementation:** .github/SECURITY-DIFF-REVIEW.md; .github/scripts/flag-sensitive-diff.sh
- **Evidence:** docs/development/phase-1/PR3-ATTESTATION-S21.md; recorded CI run 40102841581 at 1bff9dc7
- **Gap:** The recorded green head (1bff9dc7) predates later Phase 02–05 work on the same repository; nothing re-attests the current head for Phase 01's purposes.
- **Responsible:** Phase 01 acceptance
- **Next action:** Confirm the attestation and CI state at whatever commit is offered for Phase 01 acceptance.

### `P01-G2` — G. Phase-wide verification (the directive's §9)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "End-to-end **customer** journey: browse → cart → checkout (COD and one online gateway) → order history → return request"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 2`
- **Owner decisions:** None.
- **Expected behaviour:** One exercise drives the whole customer journey, including one online gateway.
- **Actual behaviour:** NOT DONE as one exercise — no journey test exists (verified by search). COD segments are covered by CodStateMachineTest and CodCashCollectionTest. The online-gateway segment "cannot be driven in this environment — no credentials, and none should be here".
- **Gap:** No end-to-end customer journey exercise; the online-gateway leg needs a provider sandbox (same dependency as P1-ORD-13 criterion 4).
- **Responsible:** Owner/infrastructure + Phase 01 acceptance
- **Next action:** Obtain a provider sandbox credential, then run the journey as one exercise.

### `P01-G3` — G. Phase-wide verification (the directive's §9)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Cross-tenant attempt at **every** step of both journeys, with a merchant, a customer and a driver token"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 3`
- **Owner decisions:** None.
- **Expected behaviour:** At every step of both journeys, a cross-tenant attempt is made with all three token classes and refused.
- **Actual behaviour:** PARTIAL: the three-token discipline exists and is proven per endpoint (B3), not per journey step — and the journeys themselves do not exist as exercises (G1, G2).
- **Evidence:** tests/Feature/Security/ResidualSafetyCorrectionsTest.php; AuctionBidForgeryTest
- **Gap:** Per-endpoint, not per journey step.
- **Responsible:** Phase 01 acceptance
- **Next action:** Depends on G1/G2 existing first.

### `P01-G4` — G. Phase-wide verification (the directive's §9)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Checkout and payment status consistency across the nine order writers"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 4`
- **Expected behaviour:** Checkout and payment status are consistent across all order writers.
- **Actual behaviour:** PARTIAL: EnforcedPaymentTypesIntegrityTest and UnguardedOrderWriterTrustBoundaryTest survey the writers and ran green; the totals survey (C1) exists now as OrderTotalReconcilerCoverageTest but declares 13 of 16 writers uncovered. Note the criterion says "nine order writers" while the measured census found 16 files persisting an order total — the original figure was an undercount.
- **Implementation:** app/Services/OrderTotalReconciler.php
- **Evidence:** tests/Feature/Commerce/OrderTotalReconcilerCoverageTest.php; EnforcedPaymentTypesIntegrityTest; UnguardedOrderWriterTrustBoundaryTest
- **Gap:** Totals consistency is declared, not achieved, for 13 of 16 writers; payment-status consistency rests on the Pending default rather than per-writer verification.
- **Responsible:** Owner decision (C1) + engineering
- **Next action:** Close C1, then re-run this step.

### `P01-G5` — G. Phase-wide verification (the directive's §9)

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Refund and inventory restoration"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 5`
- **Expected behaviour:** Refund and inventory restoration verified as part of the phase-wide exercise.
- **Actual behaviour:** Was FAIL on C5/E4; both underlying rows (P1-ORD-14, P1-INV-05) have since closed with ReturnRestoresExactlyWhatWasTakenTest, but the phase-wide exercise itself was never run.
- **Evidence:** tests/Feature/Security/ReturnRestoresExactlyWhatWasTakenTest.php
- **Gap:** No phase-wide run; the A–H matrix still reads FAIL here.
- **Responsible:** Phase 01 acceptance
- **Next action:** Re-assess with C5/E4 and run inside the §G exercise.

### `P01-G6` — G. Phase-wide verification (the directive's §9)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Financial integrity with GATE F in whichever state the owner has approved"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 6`
- **Owner decisions:** D-15 approved GATE F conditionally; no decision resolves R1/R2/R3.
- **Expected behaviour:** Financial integrity verified against the owner-approved GATE F state.
- **Actual behaviour:** The state is now approved (D-15) and the schema side is closed and measured. But GateFReconciliation's R1/R2/R3 computed against the restored production copy found live integrity defects that remain unresolved: a wallet whose stored balance cannot be reconstructed from its own ledger (9 broken chain links, worst jump −98.28bn, three currencies summed into one column); 17 COD orders recorded paid with no collection evidence; 89 of 92 P&L rows with zero product cost. Nothing was reconciled, activated or corrected — deliberately.
- **Implementation:** GateFReconciliation (read-only; no INSERT/UPDATE/DELETE)
- **Evidence:** GateFReconciliationReportTest (10 tests, clean-store negative control, tokenizer ratchet asserting no write statement); docs/GATE-F-RECONCILIATION-ITEMS.md; docs/GATE-F-VERIFIED-SCHEMA.md
- **Gap:** Three measured financial integrity defects in live data, each needing a fact the business holds. "Do not write a corrected balance, fabricate a collection event, or back-fill a cost to make a column agree."
- **Responsible:** Owner / business
- **Next action:** Owner supplies the business facts for R1, R2 and R3; until then financial integrity cannot be asserted.

### `P01-G7` — G. Phase-wide verification (the directive's §9)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Flutter admin and Lynomia Chat contracts (`tests/Contract`)"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 7`
- **Expected behaviour:** The Flutter admin and Lynomia Chat contracts are pinned and pass.
- **Actual behaviour:** Contract suite recorded 26 passed / 83 assertions; 7 contract test files confirmed present. Stated limit: it replays a committed artifact of Lynomia's producer output and does not reach a live Lynomia.
- **Evidence:** tests/Contract/ (7 files + lynomia fixtures); recorded run 26 passed / 83 assertions
- **Gap:** Lynomia pinned against a replayed artifact.
- **Responsible:** Phase 01
- **Next action:** None.

### `P01-G8` — G. Phase-wide verification (the directive's §9)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Storefront and theme compatibility, Arabic (RTL) and English (LTR), contrast-checked"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 8`
- **Owner decisions:** The closure doc classifies this as an environment blocker, not an owner-approved deferral: "it is one session with a rendered app and staging, or an explicit decision to accept them on test evidence" — and no such explicit decision is recorded.
- **Expected behaviour:** The storefront and themes render correctly in Arabic RTL and English LTR, contrast-checked on rendered pages.
- **Actual behaviour:** NOT VERIFIED at Phase 01 close — "the token-level contrast gate is in CI; rendered-page checking is not". The closure doc also records this among "Three acceptance criteria [that] need a browser or live server" and says "No further engineering reaches DONE on these here". Substantial rendered-page storefront RTL/LTR and contrast work exists now, but it was done in Phase 03 (tests/Unit/Ui/StorefrontDarkModeLayerTest, StorefrontAccessibleNamesTest, per CLAUDE.md) — not against Phase 01's criterion.
- **Evidence:** docs/design-system/tools/check-contrast.py in CI (token level); Phase 03 storefront capture matrices (not a Phase 01 artefact)
- **Gap:** No Phase 01 rendered-page RTL/LTR contrast verification; the criterion was left to a browser/live-server session that did not happen in Phase 01.
- **Responsible:** Owner decision or a session with a rendered app
- **Next action:** Either run the rendered-page check in both directions against the Phase 01 head, or obtain the explicit owner decision to accept on test evidence.

### `P01-G9` — G. Phase-wide verification (the directive's §9)

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "MySQL-specific validation for anything schema-sensitive"
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G, step 9`
- **Expected behaviour:** Everything schema-sensitive is validated on MySQL as part of the phase-wide exercise.
- **Actual behaviour:** Same state as F4: a 49-script MySQL harness exists and GATE F was proved on a real server with 29 checks / 0 failures, but no phase-wide MySQL validation run is recorded against the final head, and E3's case has no script.
- **Evidence:** tests/Support/Security/bin/run-mysql-verifications.sh + 49 scripts
- **Gap:** No recorded phase-wide MySQL run on the final head.
- **Responsible:** Phase 01 engineering
- **Next action:** Run run-mysql-verifications.sh on the Phase 01 final head and commit the log.

### `P01-H` — H. Honest limits

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Phase 01 cannot be reported complete while any of these is unrecorded: controls that are **subsumed** rather than independently proven; controls that **cannot be proven in this environment**; findings recorded but **not verified by execution** (`P1-SEC-03`, `P1-INV-04`); owner decisions outstanding, each with what it blocks. A phase report that omits these is not an acceptance report."
- **Source:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §H`
- **Expected behaviour:** All four classes of honest limit are recorded in the phase report.
- **Actual behaviour:** All four classes are recorded. The Final Decision Report §I group H lists them explicitly, including that B5 and C4 are marked PASS on a suite run rather than a targeted drive; the closure's §3 names where each limitation lives rather than restating it. The closure is unusually candid — it preserves a corrected warning that was wrong, records a gate that FAILED and was not worked around, and records a review that found a defect in the author's own fix.
- **Evidence:** docs/development/phase-1/PHASE-01-FINAL-DECISION-REPORT.md §I group H; PHASE-01-CLOSURE-EXECUTION.md §3 and CLOSEOUT §2
- **Responsible:** Phase 01
- **Next action:** None.

### `P01-LEDGER-AUCTION` — Open ledger rows (BACKLOG)

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** P1-INV-16 (#61) and P1-ORD-06 (#62): five implementation defects — the unscoped Product restock, the missing replay guard, the silent no-op non-variant path, the auction status-change reading the host orders table with an auction id, and the PII POST to a merchant-configured URL
- **Source:** `docs/development/phase-1/phase-01-tasks.json tasks P1-INV-16, P1-ORD-06; docs/development/phase-1/16-POST-PHASE-01-BACKLOG.md §A`
- **Owner decisions:** Owner direction 2026-10-24, quoted verbatim in 16-POST-PHASE-01-BACKLOG.md: "For #61 and #62, keep dormant auction functionality safely disabled and transfer noncritical implementation work to a clearly documented future backlog. Do not delete historical data." Plus D-03 (auction:order stays unscheduled) and D12.
- **Expected behaviour:** The five defects are fixed, or safely dormant with the dormancy asserted and the work carried as a named backlog item with a precondition.
- **Actual behaviour:** Deferred under an explicit owner direction, with the dormancy MEASURED rather than assumed: AuctionOrderDormancyTest asserts over app/, packages/workdo/, routes/ and bootstrap/ that the console command is the only writer of auction orders, that it is registered but scheduled nowhere, and that no route creates an auction order — each assertion with a mutant that fails the file. The five defects are explicitly restated as not fixed: "Did NOT fix the three implementation defects in A1 or the two in A2 — stated plainly here rather than implied by their absence from a completed list."
- **Implementation:** No fix; containment only
- **Evidence:** tests/Feature/Security/AuctionOrderDormancyTest.php (3 passed, 3 mutants); docs/development/phase-1/16-POST-PHASE-01-BACKLOG.md
- **Gap:** An operator check remains and is a release prerequisite, not a completed test: `SELECT COUNT(*) FROM auction_product_orders;` — a non-zero result moves these from "deferred" to "scheduled before D-03 is revisited". The open question that decides A1 defect 1's severity (can an auction order's product_json reference a product outside the order's store?) is unanswered.
- **Responsible:** Owner (count) + post-phase engineering
- **Next action:** Run the auction_product_orders count; if non-zero, schedule the five defects before D-03 is revisited.

### `P01-LEDGER-MIG-02` — Open ledger rows

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P1-MIG-02 (#39): "The column holds the value the reset writes, on MySQL" and "Verified by the MySQL harness, not by SQLite" — customers.password_otp is INTEGER while the web reset writes a 60-char bcrypt hash
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-MIG-02; CLAUDE.md §1 Schema`
- **Owner decisions:** D-15 governs applying migrations; no decision applies this one.
- **Expected behaviour:** customers.password_otp holds the value the password reset writes, verified on real MySQL.
- **Actual behaviour:** Still open. CLAUDE.md states it as a current fact: "`customers.password_otp` is `INTEGER` but the web reset writes a 60-char bcrypt hash (P01-SEC-13, migration prepared, **not applied**)." The draft is parked as `.php.draft` so Laravel cannot load it. This is the finding that survived because SQLite's type affinity hides it — the canonical example in CLAUDE.md §3.
- **Implementation:** Draft only, not loadable
- **Evidence:** tests/Support/Security/bin/verify-sec13-migration-on-mysql.php (the pattern); the parked draft in database/migrations/_sec13_pending/
- **Gap:** The migration has not been applied anywhere; on MySQL the reset silently fails or truncates.
- **Responsible:** Owner (D-15) + engineering
- **Next action:** Apply under D-15's conditions and verify with verify-sec13-migration-on-mysql.php on real MySQL.

### `P01-LEDGER-MIG-04` — Open ledger rows

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P1-MIG-04 (#41): "Each table is either drafted with a reviewed migration or recorded as deliberately retired" and "zid_orders and newsletters.user_id unblock their two declared test skips"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-MIG-04`
- **Owner decisions:** D12 — retired Chatwoot tables retained, not destructively dropped.
- **Expected behaviour:** All nine SCHEMA-01 tables are drafted or retired, and the two declared test skips are unblocked.
- **Actual behaviour:** Largely closed by the 2026-11-02 GATE F work, with D12 retaining the retired Chatwoot tables. One residual confirmed in this pass by reading .github/known-test-outcomes.json: a single declared skip remains — CustomerAccountBoundaryTest::test_an_anonymous_visitor_is_not_gated_out_of_newsletter_signup, blocked on `newsletters.user_id`, "an undeclared COLUMN, not a table… Its type was never measured… so declaring it would be exactly the guess GATE F exists to forbid."
- **Implementation:** database/migrations/ (GATE F set)
- **Evidence:** .github/known-test-outcomes.json expected_skips (1 entry, read in this pass)
- **Gap:** newsletters.user_id is undeclared and unmeasured; one test remains skipped and newsletter signup's insert always fails.
- **Responsible:** Owner (restored backup) + engineering
- **Next action:** Measure newsletters.user_id on a restored copy, add it to docs/GATE-F-VERIFIED-SCHEMA.md, then migrate.

### `P01-LEDGER-ORD-09` — Open ledger rows

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P1-ORD-09 (#73): "the emailed link no longer opens the order from a different device with no login" (NOT DONE); "the credential is bound to ONE order and expires" (NOT DONE); consumers all route through one guard (PARTIAL); "sequencing with #43 is untouched" (NOT DONE)
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-ORD-09`
- **Owner decisions:** D8 bears on this (token design); D7/D-10 defer rotation. Neither decides the document-token lifetime, which the row names as the owner's call.
- **Expected behaviour:** The order-document credential is bound to one order, expires, and every consumer routes through one guard; the sequencing with APP_KEY rotation is settled.
- **Actual behaviour:** The exposure is closed: OrderDocumentAccessGuard refuses `{storeSlug?}/order/{id}` and `{storeSlug?}/customerpayment/{id}` to every anonymous caller, so a ciphertext forged with the leaked APP_KEY no longer renders anyone's order. The disclosed cost: a GUEST's emailed link stops working, because orders.customer_id is 0 on a guest order. No signed-URL machinery exists in this codebase (routes/auth.php:45 is the only `signed` route). ReviewReminder order-review* and AuctionProduct auction-order/{id} are NOT in TARGETS and remain open.
- **Implementation:** app/Services/... OrderDocumentAccessGuard
- **Evidence:** OrderDocumentAccessGuard::TARGETS; docs/security/P01-SEC-11-ORDER-DOCUMENT-DIRECTION.md
- **Gap:** Guest emailed links are broken (a disclosed behaviour regression); no expiring per-order token; two consumer routes outside the guard; sequencing with APP_KEY rotation unsettled.
- **Responsible:** Owner (lifetime) + engineering
- **Next action:** Owner decides the document-token lifetime; then implement a scoped expiring token and bring order-review* and auction-order/{id} into TARGETS.

### `P01-LEDGER-OWN-01` — Open ledger rows

**Status: EXTERNALLY BLOCKED**

- **Requirement (original wording / faithful reference):** P1-OWN-01 (#42): BackupRestore containment — five criteria, including "A regression test asserts the BackupRestore routes are NOT registered when the module is not enabled" and "The host-side containment steps are specified and ready to apply … specified, NOT applied to any server"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-01; docs/security/P01-SECURITY-WORK-QUEUE.md S1`
- **Owner decisions:** D17 — "BackupRestore kept disabled/isolated until server exposure is checked; no public access to backups, logs or sensitive storage paths." That is an approved posture, not a closure.
- **Expected behaviour:** The module stays disabled, the exposure is documented, containment is specified and the three read-only server observations are taken.
- **Actual behaviour:** The engineering half is recorded COMPLETE; the three read-only server observations (does an add_on_managers row for BackupRestore exist at all; what sits in storage/uploads/{Files,Database}_Backup; does .htaccess already deny those paths) have not been made. The queue's S1 row documents the full chain: document root is the repository root, artefacts written inside it at chmod 0777, the file archive is the entire application root including .env and .git, a failed dump is logged as a success, shell injection via $request->database_name, DB password on the command line, and there is no restore.
- **Implementation:** Host-side containment specified, not applied
- **Evidence:** docs/security/P1-OWN-01-BACKUPRESTORE-CONTAINMENT.md §5 (the three checks); docs/security/S1-BACKUPRESTORE-ADMIN-VERIFICATION.md; docs/security/P1-OWN-01-SERVER-VERIFICATION-RUNBOOK.md
- **Gap:** Three read-only observations outstanding; if the module IS active this is an incident, not a queue item.
- **Responsible:** Owner / operator with server access
- **Next action:** Make the three read-only observations; if a row exists in add_on_managers, treat as an incident.

### `P01-LEDGER-OWN-02` — Open ledger rows

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** P1-OWN-02 (#43): "Rotation executed per docs/security/SECRET-ROTATION-CHECKLIST.md"; "The Zid secret is provisioned in the environment BEFORE it is moved out of config"; "An APP_KEY rotation is preceded by an inventory of every encrypted-cast column"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-02`
- **Owner decisions:** D7 and D-10 (2026-10-22) — "No production key rotation now. Residual risk accepted and to be documented without claiming remediation." D-35 constraint 2 forbids rotating APP_KEY in the current workstream. Both predate the P1-SEC-40 finding.
- **Expected behaviour:** APP_KEY and the four tracked config secrets are rotated, in the stated order.
- **Actual behaviour:** Not rotated. CLAUDE.md states it as current: "`APP_KEY` is in git history and unrotated, so `Crypt::encrypt()` is an encoding, not a control. An encrypted id in a URL is forgeable." The deferral was decided when exploiting it was believed to require a repository clone; P1-SEC-40 §3.2.1 then established the key is retrievable over plain HTTP from the production host by any anonymous visitor, and says in terms "The item's severity should be re-rated on that basis."
- **Evidence:** docs/security/SECRET-ROTATION-CHECKLIST.md; docs/security/P1-SEC-40-INCIDENT.md §3.2.1
- **Gap:** The premise of the existing deferral no longer holds; no re-rating decision has been taken.
- **Responsible:** Owner
- **Next action:** Owner re-rates D7/D-10 against the anonymous-HTTP retrieval finding and decides rotation order relative to deploying the deny rules.

### `P01-LEDGER-OWN-03` — Open ledger rows

**Status: EXTERNALLY BLOCKED**

- **Requirement (original wording / faithful reference):** P1-OWN-03 (#44): "Up to 15 days of plaintext passwords, OTPs and bearer tokens are treated as compromised"; "A retention decision is recorded for API request/response bodies"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-03`
- **Owner decisions:** D-09 (data retention, 2026-10-22) and D5 set retention policy generally; neither records the decision for API request/response bodies specifically.
- **Expected behaviour:** The API log's plaintext credentials are treated as compromised and a retention decision is recorded.
- **Actual behaviour:** Open. Requires access to the running server; "Past malicious use cannot be determined from the repository." The log writer itself was redacted in Phase 01 (P1-SEC-16 / increment 5), but the already-written files are untouched.
- **Implementation:** Redaction of the writer is done; the existing files are not
- **Gap:** Existing api-*.log files and their backups not purged or confirmed clean; no recorded retention decision.
- **Responsible:** Owner / operator
- **Next action:** Purge or confirm clean the api-*.log files and backups; record the retention decision.

### `P01-LEDGER-OWN-04` — Open ledger rows

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** P1-OWN-04 (#45): "A decision on expiry and abilities, with a compatibility window for shipped binaries"; "`Api\AuthController::login` is routed, or its absence is recorded as intended"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-04; docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md`
- **Owner decisions:** D8 (2026-10-22) — "Tokens: narrow abilities to real client roles; preserve installed-app compatibility; expiring tokens plus a secure refresh via staged rollout; no sudden mass device invalidation; avoid blanket ['*']." That is a decision to DO this with a staged rollout, which also makes it a client release-window dependency.
- **Expected behaviour:** Token expiry and abilities are decided and rolled out with a compatibility window; the customer login endpoint is routed or its absence recorded as intended.
- **Actual behaviour:** Open. CLAUDE.md states the current position: "Sanctum tokens never expire (no config/sanctum.php) and every one carries ['*']. CheckAbilities is imported in routes/api.php and never used. There is no refresh path for any client, and Api\AuthController::login is unrouted — customers have no login endpoint." The constraint that makes it a release-window item: the shipped Flutter app force-logs-out on any 401.
- **Evidence:** docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md
- **Gap:** Non-expiring wildcard tokens remain in the field; no refresh path; no customer login endpoint.
- **Responsible:** Owner (release window) + engineering
- **Next action:** Plan the staged rollout D8 describes against a client release window; route or formally record Api\AuthController::login.

### `P01-LEDGER-OWN-05` — Open ledger rows

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** P1-OWN-05 (#46): three parts — S12-c anonymous order-document access; "pint: no pint.json, no authorised reformatting pass"; "browser checks: still a documented manual run, not wired with Playwright in CI"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-05`
- **Owner decisions:** None on (b) or (c).
- **Expected behaviour:** All three settled.
- **Actual behaviour:** (a) S12-c is CLOSED — link-bearer access is no longer accepted. (b) and (c) remain open owner choices with no security content: pint would touch every file, and wiring Playwright into CI is a budget and runtime decision. (c) is the same gap as G8's rendered-page contrast checking.
- **Evidence:** OrderDocumentAccessGuard (for the closed limb (a))
- **Gap:** (b) pint adoption and (c) Playwright-in-CI undecided.
- **Responsible:** Owner
- **Next action:** Owner chooses on pint and on Playwright-in-CI; (c) also unblocks rendered-page contrast checking for G8.

### `P01-LEDGER-OWN-06` — Open ledger rows

**Status: EXTERNALLY BLOCKED**

- **Requirement (original wording / faithful reference):** P1-OWN-06 (#47): "main requires a pull request"; "The required checks include both Tests jobs, the merge-base gate and the attestation"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-06`
- **Owner decisions:** D16 approves merging to main in sequence "only when branch/release gates permit", which presupposes the protection exists.
- **Expected behaviour:** Branch protection on main requires a PR and the four named required checks.
- **Actual behaviour:** Not established — "Repository owner setting. Cannot be scripted from a session." I could not verify branch protection from this container. Note that main has since received a merge (4e878b8d, 2026-11-12), so whether protection was in force at that point is unknown here.
- **Gap:** Branch protection state unknown and unverified.
- **Responsible:** Repository owner
- **Next action:** Owner sets and confirms branch protection on main with the four required checks, and records a screenshot or API read as the evidence.

### `P01-LEDGER-OWN-07` — Open ledger rows

**Status: EXTERNALLY BLOCKED**

- **Requirement (original wording / faithful reference):** P1-OWN-07 (#56): "The five queries are run READ-ONLY and their row counts recorded"; remediation per class if residue exists; exposure window from products.updated_at; "If all empty, that is recorded as the finding and this closes"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-07`
- **Owner decisions:** None required to run them; remediation would need one.
- **Expected behaviour:** The pre-fix WooCommerce transfer residue is inventoried read-only and either remediated under an approved plan or recorded as empty.
- **Actual behaviour:** Not done — needs read-only access to the production database. "The repository cannot show which rows the pre-fix WooCommerce transfer primitive moved."
- **Gap:** The five read-only queries have not been run.
- **Responsible:** Owner / operator with read access
- **Next action:** Run the five read-only queries and record the counts.

### `P01-LEDGER-OWN-08` — Open ledger rows

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** P1-OWN-08 (#86): "The owner states scrub or purge, and the scrub is run against a restored copy first and verified"; "The owner states whether customers are notified and forced to reset passwords, and the mechanism exists before the statement is made"; backups/replicas accounted for; order relative to the APP_KEY rotation stated
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-08`
- **Owner decisions:** D6 (2026-10-22) gives the DIRECTION — "inventory, preserve evidence under restricted access, rehearse on a restored backup, clean request/header fields, invalidate sessions, force password resets for affected accounts … retention must not preserve exposed plaintext credentials" — but execution needs the restored backup and the specific scrub/purge and notification statements, which have not been made.
- **Expected behaviour:** shetabit_visits rows holding plaintext customer passwords, session cookies and possibly card fields are scrubbed or purged, customers are notified or not by decision, and the sequencing with APP_KEY rotation is stated.
- **Actual behaviour:** Open. P1-SEC-13 stops NEW rows holding secrets; it cannot undo the stored rows. The row records that every customer who logged in, registered or reset a password on a themed storefront since 2024-01-26 has a plaintext password in the table — failed attempts included, which are often near-misses of the real one — and that store_id is never set on these rows, so any operation is table-wide. Seven sub-items including whether card-bearing rows start a PCI process.
- **Implementation:** P1-SEC-13 stops new rows
- **Gap:** Historical plaintext credentials remain stored; no notification decision; no PCI determination.
- **Responsible:** Owner + restored backup
- **Next action:** Owner states scrub vs purge and the notification decision; rehearse on a restored copy; sequence against APP_KEY rotation.

### `P01-LEDGER-OWN-09` — Open ledger rows

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** P1-OWN-09 (#88): "The owner states whether merchants are told to rotate, and the guidance exists before the statement is made"; "The access-log check has been done by someone with access, and its result is recorded here"; API log files accounted for; order relative to the APP_KEY rotation stated
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-09`
- **Owner decisions:** D7 defers production key rotation but says residual risk is "to be documented without claiming remediation" — it does not decide the merchant-notification question.
- **Expected behaviour:** Merchants are told (or not, by decision) to rotate their gateway keys; the access logs are checked; the API log files are purged or confirmed clean.
- **Actual behaviour:** Open. Every store's payment-gateway secrets were readable by any internet visitor (56 secret fields across Stripe, Paystack, PayPal, Razorpay and others). P1-SEC-15 stops the endpoint answering; it cannot un-disclose what it already answered. The access-log check is "the only evidence of exploitation" and is time-critical — P1-SEC-40 §6 step 4 warns to pull the logs "before rotation discards the window".
- **Implementation:** P1-SEC-15 closed the endpoint
- **Evidence:** docs/security/P1-SEC-55-OUTBOUND-SENDER-CENSUS.md (adjacent); GatewaySecretRedactor
- **Gap:** No merchant rotation guidance; no access-log check; API log files not purged.
- **Responsible:** Owner + operator
- **Next action:** Owner decides merchant rotation guidance; operator runs the read-only access-log check and records the result before any rotation.

### `P01-LEDGER-OWN-10` — Open ledger rows

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** P1-OWN-10 (#103): "The owner has decided keep / purge / ignore, and the decision is recorded"; "If /uploads/ is ignored or purged, no test or handler in the repository depends on a tracked file there" — 407 user-uploaded files committed to the repository
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-10`
- **Owner decisions:** D11 (2026-10-22), cited verbatim above from docs/development/phase-1/15-DEFERRED-OWNER-DECISIONS.md.
- **Expected behaviour:** The owner decides and the decision is recorded; residual exposure documented; new user uploads prevented from being committed.
- **Actual behaviour:** The decision IS made and recorded, but the ledger row still reads BLOCKED on it. D11: "leave them in the tree and in history; no deletion, no history rewrite, no force-push; prevent new user uploads being committed; document residual exposure; identify files needing separate legal/security handling; do not claim preservation removes confidentiality risk." The recurrence guard exists (.github/scripts/guard-no-new-root-uploads.sh) and docs/security/P01-OWN-10-ROOT-UPLOADS-EXPOSURE.md documents the exposure.
- **Implementation:** guard-no-new-root-uploads.sh in CI
- **Evidence:** .github/scripts/guard-no-new-root-uploads.sh; docs/security/P01-OWN-10-ROOT-UPLOADS-EXPOSURE.md
- **Gap:** D11's sub-item "identify files needing separate legal/security handling" — I found no artefact recording that identification.
- **Responsible:** Owner (triage) + ledger bookkeeping
- **Next action:** Close the ledger row against D11, and record the legal/security triage of the 407 files that D11 asks for.

### `P01-LEDGER-OWN-11` — Open ledger rows

**Status: EXTERNALLY BLOCKED**

- **Requirement (original wording / faithful reference):** P1-OWN-11 (#106): "The three results are recorded with the date, and a unique key is either decided or declined"; "If (2) returns rows, those stores are told and P1-SEC-29's fix is checked against that data"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-11`
- **Owner decisions:** None.
- **Expected behaviour:** Three read-only statements on the live settings table (SHOW INDEX; hosts held by more than one store; rows renamed by a case variant) are run and recorded, and a unique key decided or declined.
- **Actual behaviour:** Not done. The three statements are written out verbatim in the row; counts and store ids suffice and no values need leave the server. "Engineering will not connect to the live database."
- **Gap:** Three read-only statements outstanding; the unique-key decision depends on their output.
- **Responsible:** Owner / operator with read access
- **Next action:** Run the three statements and record the results with the date.

### `P01-LEDGER-OWN-12` — Open ledger rows

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P1-OWN-12 (#108): "The decision is recorded, and implemented if it is (b) or (c)"; "The test that pins today's behaviour is changed in the same commit, with the decision cited" — who may put script and style on every storefront page, checkout included (storejs, storecss, google_analytic, fbpixel_code)
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-OWN-12`
- **Owner decisions:** D10 (2026-10-22), quoted above.
- **Expected behaviour:** The owner decides between (a) leave as is, (b) require Manage Setting, (c) keep and sanitise; the decision is recorded and implemented.
- **Actual behaviour:** The decision was made on 2026-10-22 and the ledger row has not been updated — it still reads BLOCKED on a decision that exists. D10 chose (a) with conditions: "preserve the existing delegation — any holder of Manage Store Setting may edit permitted store JS/CSS and analytics; do not narrow it to the owner or invent a new mandatory permission; but enforce tenant ownership and authentication, keep CSRF, prevent crossing into another merchant's or the operator's origin, no access to server-side secrets, and document the delegation risk." The register notes this is "the opposite of the narrowing this project had recommended". Whether D10's four conditions are implemented was not established from code in this pass.
- **Implementation:** Not established from code in this pass
- **Evidence:** docs/security/P01-OWN-12-STOREFRONT-SCRIPT-DELEGATION.md
- **Gap:** D10's conditions (tenant ownership, authentication, CSRF, no cross-origin, documented delegation risk) are unverified; the ledger row is stale.
- **Responsible:** Engineering
- **Next action:** Verify D10's four conditions in code, pin them with tests citing D10, and close the ledger row.

### `P01-LEDGER-PLT-02` — Open ledger rows

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P1-PLT-02 (#34): "Neither writer can persist an order with a NULL store_id" and "Existing NULL-store rows are COUNTED and reported (not modified)"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-PLT-02`
- **Owner decisions:** None — the row carries owner_decision: false. It is an environment blocker, not an owner decision.
- **Expected behaviour:** MercadoPaymentController and SkrillPaymentController cannot write a NULL store_id, and a read-only script counts the existing NULL-store rows in production.
- **Actual behaviour:** The fix limb is implemented (commit 8909272f) but the row's own evidence says the reconciliation "did not re-verify the acceptance criteria". The IN_REVIEW audit returned BLOCKED: 2 of 4 criteria PROVEN, 1 UNPROVEN, 1 OWNER-GATED. The outstanding half is the count: "Run report-null-store-orders.php against production and record the count." This is also load-bearing for S6 — these writers must stop creating NULL-store rows before any tightening of `null_tenant` can be considered, and `null_tenant => 'open'` means an unresolved tenant adds no predicate at all.
- **Implementation:** app/Http/Controllers/MercadoPaymentController.php:86; app/Http/Controllers/SkrillPaymentController.php:106
- **Evidence:** commit 8909272f; tests/Support/Security/bin/report-null-store-orders.php (prepared, not run against production)
- **Gap:** The production NULL-store count has not been taken; the row's acceptance criteria were never re-verified after the fix landed.
- **Responsible:** Operator with read access + engineering
- **Next action:** Run report-null-store-orders.php read-only against production, record the count, then re-verify the row's four criteria.

### `P01-LEDGER-SEC-26` — Open ledger rows

**Status: EXTERNALLY BLOCKED**

- **Requirement (original wording / faithful reference):** P1-SEC-26 (#99): four criteria, including "A route that names no store never serves the lowest-id merchant's settings to a caller" and "The decision on an unknown slug (fail closed vs. legacy read) is made and tested"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-SEC-26`
- **Owner decisions:** None.
- **Expected behaviour:** Every no-argument getAdminAllSetting() caller is classified; platform settings read getSuperAdminAllSetting(); an unknown slug's behaviour is decided and tested.
- **Actual behaviour:** Split: criterion 3 needs one read-only owner query (`SELECT store_id, created_by, value FROM settings WHERE name = 'storage_setting'`) because defaultSetting() seeds storage_setting for the super admin only while the merchant-shaped read answers with a store's row — "Guessing it either breaks every image-existence check on a deployment where the lowest-id merchant held the row, or leaves the defect." The remaining parts are engineering. Note the unknown-slug limb has since been closed on the storefront by Phase 03 (P3-M0.5, "unknown storefront slug fails closed").
- **Implementation:** app/Helper/helper.php:1500 (check_file/delete_file/logo helpers)
- **Gap:** The storage_setting query has not been run; the remaining classification work is unfinished.
- **Responsible:** Owner / operator (query) + engineering
- **Next action:** Run the one read-only query, then classify the remaining callers.

### `P01-LEDGER-SEC-32` — Open ledger rows

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P1-SEC-32 (#111): "(c) proof of control. Approved in principle by D-07; the challenge needs a column, so it is gated on D15"; "(d) a release path for the rightful owner"
- **Source:** `docs/development/phase-1/phase-01-tasks.json, task P1-SEC-32`
- **Owner decisions:** D-07 (2026-10-22, APPROVED) for (a)(b)(c in principle); D4 of the final set answers (d) in full, quoted above. Both from 15-DEFERRED-OWNER-DECISIONS.md.
- **Expected behaviour:** A host is not taken by whoever saves it first; reserved hosts refused; DNS control proven; a release path exists for the rightful owner.
- **Actual behaviour:** (a) and (b) are decided by D-07 and implemented as one rule in app/Services/HostClaims.php, with verify-host-claims-on-mysql.php as evidence. (c) is approved in principle but needs a column to store the challenge, gated on D-15. (d) was recorded as "the single smallest outstanding owner decision in Phase 01" and then WITHDRAWN on 2026-10-24 — D4 had already answered it: "forced release/transfer is operator-only after verifying ownership, with previous holder, new holder, operator, reason and time in the audit log; DNS change alone never transfers a claim". What remains for (d) is implementation: the release path needs an audit record with five named fields, and no table provides one.
- **Implementation:** app/Services/HostClaims.php (a and b)
- **Evidence:** tests/Support/Security/bin/verify-host-claims-on-mysql.php; app/Services/HostClaims.php
- **Gap:** (c) DNS-control challenge column; (d) the five-field audit record and the operator release path.
- **Responsible:** Owner (D-15) + engineering
- **Next action:** Under D-15, add the challenge column and the host-claim audit table, then implement (c) and (d) to D4's specification.

### `P01-RP-0` — Release prerequisites (CLOSEOUT §2C)

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** "`P1-SEC-40` (#120) — A PRODUCTION DATABASE DUMP IS IN `main`'s CURRENT TREE. Read this one first; it is the only item on this list that is a live exposure right now rather than a pending check." … "blocks production deployment / real customer data: **YES** — must be reassessed first"
- **Source:** `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md CLOSEOUT §2C item 0; docs/security/P1-SEC-40-INCIDENT.md`
- **Owner decisions:** D-35 (2026-11-02) overrode this item's BLOCKING effect on development-only `main` integration AND NOTHING ELSE — explicitly "not fixed and not resolved", still blocking production deployment. PR #122 was closed, not merged, by the owner. The owner's statement that customer rows are test/demo data bears only on the personal-data impact of those rows and does not touch the APP_KEY exposure.
- **Expected behaviour:** The exposure is contained, the history purge and exposure assessment are completed, and the item is reassessed before any production deployment or real customer data.
- **Actual behaviour:** Partly contained, and the finding has grown. The file is no longer in main's current tree (removed by commit 32f562c4, "the dump was the reason for looking, not the worst thing found") but I confirmed the blob is STILL reachable: `git cat-file -e 3935dee8:storesyria_ecommercego.sql` succeeds. The incident doc's own status line reads "partially contained, two remediation steps blocked on owner authorization", and §3.2.1 records something worse: the live host serves its own `.git`, the deployed clone is a full clone of main, and the root commit contains a committed `.env` — so "the production `APP_KEY` and database credentials are retrievable over plain HTTP from https://storesyria.com/.git/ by any anonymous visitor", with an exposure window running from first deployment (root commit 2026-08-27), not the dump's 18 days. §6 steps 1–10 are PLAN ONLY, NOT EXECUTED.
- **Implementation:** Deny rules prepared and deliberately dormant; dump removed from the current tree
- **Evidence:** docs/security/P1-SEC-40-INCIDENT.md; docs/security/p1-sec-40-apache-deny.conf and p1-sec-40-nginx-deny.conf (armed but dormant); git cat-file check run in this pass
- **Gap:** The emergency step (stop the server serving .git, or move/delete it) has not been taken; APP_KEY and DB password not rotated; access logs not pulled; repository visibility not decided; history not rewritten; GitHub Support not asked; affected-account resets and notification obligations not decided.
- **Responsible:** Owner (authorisation) + server access
- **Next action:** Owner authorises, in order: (1) stop production serving .git; (2) confirm 404s; (3) rotate DB password and APP_KEY; (4) pull access logs before rotation discards the window; (5) decide repository visibility; (6-8) history rewrite and force-push; (9-10) GitHub Support purge and clean-clone verification.

### `P01-RP-1` — Release prerequisites (FINAL §2a)

**Status: EXTERNALLY BLOCKED**

- **Requirement (original wording / faithful reference):** "OUTSTANDING RELEASE PREREQUISITES — these are NOT test results. Read this table as a list of things that have not happened." 13 environment/server observations: P1-PLT-02 #34, P1-OWN-01 #42, P1-OWN-03 #44, P1-OWN-06 #47, P1-OWN-07 #56, P1-OWN-08 #86, P1-OWN-09 #88, P1-OWN-11 #106, P1-SEC-26 #99, P1-OWN-04 #45, P1-OWN-05 #46, P1-ORD-13 #82 (criterion 4), P1-SEC-14 #89 (criterion 3) — plus a fourteenth of the same kind, P1-PLT-04 #36 criterion 2
- **Source:** `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md FINAL §2a; restated at CLOSEOUT §2C item 1`
- **Owner decisions:** The owner's direction of 2026-10-24 item 4 was to "Record the 13 environment checks and 5 D15-gated migrations as outstanding release prerequisites, not completed tests" — i.e. they are recorded as open, not approved as waived.
- **Expected behaviour:** Each observation is taken on the running server or restored backup and its result recorded.
- **Actual behaviour:** None is recorded as taken against production. One has a partial result: #89 criterion 3 was run in a release-integration container on 2026-11-02 and the log committed, but the closure states plainly "THIS DOES NOT CLOSE THE CRITERION FOR PRODUCTION" because the report's first line is `APP_ENV : local` and the criterion says "run where the app runs". Where a script exists, writing it was the engineering and is done; running it is the prerequisite.
- **Implementation:** Prepared scripts: report-null-store-orders.php, report-larabug-configuration.php, report-storefront-entitlement-gap.php
- **Evidence:** docs/development/phase-2/evidence/phase-01-gates-2026-11-02/larabug-configuration-local-container.log (for #89 criterion 3, local only)
- **Gap:** 13 (+1) observations outstanding; each is named with its exact read-only command in FINAL §2a.
- **Responsible:** Owner / operator with server access
- **Next action:** Run each read-only observation where the app runs and paste the output into its issue.

### `P01-RP-2` — Release prerequisites (CLOSEOUT §2C)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "5 D15-gated migrations" — P1-MIG-01 #38, P1-MIG-02 #39, P1-MIG-03 #40, P1-MIG-04 #41, P1-SEC-32 #111 (c) and (d) — with the two ordering constraints preserved
- **Source:** `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md CLOSEOUT §2C item 2`
- **Owner decisions:** D-15 (approved, ten prerequisites); D12 (retired Chatwoot tables retained, answering P1-MIG-04's open sub-question).
- **Expected behaviour:** The parked drafts become reviewed, runnable migrations without promoting a guessed schema, preserving both ordering constraints.
- **Actual behaviour:** Struck through and marked "CLOSED 2026-11-02. GATE F is closed; the migrations exist." Confirmed in this pass: all the named migrations are in database/migrations/ and both ordering constraints are verifiable from the filenames. Proved on a real server by verify-gate-f-migrations-on-mysql.php (29 checks, 0 failed). But "Promotion is a no-op against production by design" — it aligns fresh environments and reconciles nothing live. P1-MIG-02 (customers.password_otp INTEGER vs a 60-char bcrypt hash) is NOT in that set and CLAUDE.md still records its migration as "prepared, not applied". P1-SEC-32(c) and (d) remain implementation gated on an audit record no table provides.
- **Implementation:** app/Support/Database/GateFProductionSchema.php; GateFSchemaGuard::assertCompatible()
- **Evidence:** tests/Support/Security/bin/verify-gate-f-migrations-on-mysql.php; database/migrations/2026_10_26_000100 … 2026_11_02_000600; docs/GATE-F-VERIFIED-SCHEMA.md
- **Gap:** P1-MIG-02 unapplied; P1-SEC-32(c)/(d) unimplemented; nothing applied to production; cod_collection_events deliberately absent.
- **Responsible:** Owner (D-15 prerequisites) + engineering
- **Next action:** Apply P1-MIG-02 under D-15's conditions on real MySQL; build the host-claim audit record for P1-SEC-32(c)/(d).

### `P01-RP-3` — Release prerequisites (CLOSEOUT §2C)

**Status: EXTERNALLY BLOCKED**

- **Requirement (original wording / faithful reference):** "#82 criterion 4 — one provider sandbox per gateway. Owner/infrastructure." The underlying criterion: "Every gateway return handler is driven end to end (the vendored-style handlers are in the host app, so they are in scope)"
- **Source:** `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md CLOSEOUT §2C item 3; phase-01-tasks.json P1-ORD-13 acceptance[3]`
- **Owner decisions:** The owner directed this as "gateway test infrastructure, not another business decision" (PHASE-01-OWNER-EXECUTION-REPORT.md §A.1 item 7) — i.e. it is to be built, not waived.
- **Expected behaviour:** Every gateway return handler is driven end to end against a provider sandbox.
- **Actual behaviour:** P1-ORD-13 is PARTIAL with criteria 1–3 executed (8 mutants) — the primary key is carried through gateway returns, two same-second guest orders each get their own thank-you page, and the Paymera replay branch is closed. Criterion 4 needs a provider sandbox per gateway and has not been done. The requirements are specified in docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md (52 payment types, 11 order-writing return paths, 12 fail-closed controls named with file:line).
- **Implementation:** app/Services/OrderCompleteBinding.php
- **Evidence:** docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md; criteria 1-3 evidence in the ledger row (8 mutants)
- **Gap:** No gateway return handler has been driven end to end against a real provider sandbox.
- **Responsible:** Owner / infrastructure
- **Next action:** Owner provisions one sandbox credential per gateway; then drive each return handler.

### `P01-RP-4` — Release prerequisites (CLOSEOUT §2C)

**Status: EXTERNALLY BLOCKED**

- **Requirement (original wording / faithful reference):** "#89 criterion 3 — `php tests/Support/Security/bin/report-larabug-configuration.php`, run where the app runs; its output is the record." Underlying criterion: "The owner is told whether the tracker is keyed in production, and the answer is recorded"
- **Source:** `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md CLOSEOUT §2C item 4; phase-01-tasks.json P1-SEC-14 acceptance[2]`
- **Owner decisions:** The owner directed "disable outbound reporting until sensitive-data protection is verified; preserve local logging" — done. The production reading is still owed.
- **Expected behaviour:** A reading of the error tracker's configuration taken where the application runs, recorded.
- **Actual behaviour:** Run on 2026-11-02 in the release-integration container and the output committed: larabug keys SET BUT BLANK, `larabug in this env = no`, isConfigured() = false, mayReportOutbound() = false. The closure then says in bold: "THIS DOES NOT CLOSE THE CRITERION FOR PRODUCTION, and must not be read as doing so" — the report's first line is `APP_ENV : local`. Criteria 1 and 2 (the blacklist, and a payload built with the real LaraBug class carrying a card number, a gateway secret and a session cookie) are closed with 3 mutants, plus an outbound gate that makes the answer harmless.
- **Implementation:** ScrubbedLaraBug; larabug.outbound_enabled defaults false
- **Evidence:** docs/development/phase-2/evidence/phase-01-gates-2026-11-02/larabug-configuration-local-container.log; docs/security/P01-SEC-14-ERROR-TRACKER-EXPOSURE.md
- **Gap:** DONE for a from-source install, OPEN for production — one command, output is the record.
- **Responsible:** Owner / operator
- **Next action:** Run report-larabug-configuration.php on production and paste the output into issue #89.

### `P01-RP-5` — Release prerequisites (CLOSEOUT §2C)

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** "The three GATE F reconciliation items — R1 a wallet whose stored balance cannot be reconstructed from its own ledger …; R2 17 COD orders recorded paid with no collection evidence …; R3 89 of 92 P&L rows with zero product cost … **Each needs a fact the business holds. Do not write a corrected balance, fabricate a collection event, or back-fill a cost to make a column agree.**"
- **Source:** `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md CLOSEOUT §2C item 5; docs/GATE-F-RECONCILIATION-ITEMS.md`
- **Owner decisions:** None resolves them. D-15 and the 2026-10-24 direction both say keep the reconciliation gates pending and invent nothing.
- **Expected behaviour:** Each of R1, R2, R3 is resolved with a fact the business holds, before the financial subsystem is relied on.
- **Actual behaviour:** All three measured against a restored copy by code that provably cannot write (GateFReconciliation has no INSERT/UPDATE/DELETE, asserted by a tokenizer ratchet). R1: 0 arithmetic violations but 9 broken chain links, worst jump −98.28bn, three currencies summed into one column. R2: 17 COD orders paid with no collection evidence — which cannot exist because cod_collection_events does not. R3: 89 of 92 P&L rows with zero product cost, and recomputing against today's costs would change 0 of 89, so the costs were never entered. Nothing was reconciled, activated or corrected. The dump was destroyed and every database dropped.
- **Implementation:** GateFReconciliation (read-only)
- **Evidence:** GateFReconciliationReportTest (10 tests, clean-store negative control, tokenizer ratchet); docs/GATE-F-RECONCILIATION-ITEMS.md; docs/GATE-F-VERIFIED-SCHEMA.md
- **Gap:** Three unresolved live financial integrity defects.
- **Responsible:** Owner / business
- **Next action:** Owner supplies the business facts: the true wallet balances, the COD collection record, and the historical product costs.

### `P01-RP-6` — Release prerequisites (CLOSEOUT §2C)

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** "#20's compatibility condition — the coupon fix **changes charged amounts**: a coupon that was silently refused now applies. That was always the condition on the row and it is not waived."
- **Source:** `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md CLOSEOUT §2C item 6`
- **Owner decisions:** None. The closeout says in terms that it "is not waived".
- **Expected behaviour:** The owner explicitly accepts the changed charged amounts before release.
- **Actual behaviour:** Not recorded as accepted. The Final Decision Report marks C2 BLOCKED for exactly this reason: "P1-ORD-01 (#20) changes charged amounts and needs explicit owner acceptance."
- **Implementation:** The coupon fix on api/{slug}/place-order
- **Gap:** No recorded owner acceptance of the revenue-affecting behaviour change.
- **Responsible:** Owner
- **Next action:** Obtain and record the owner's explicit acceptance before release.

### `P01-RP-7` — Release prerequisites (FINAL §4 / CLOSEOUT §2C item 7)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Mandatory merge and release gates — preserved and unmet … per the owner's direction `main` is not to be merged and production is not to be deployed while they remain unmet." Gates: both required Tests jobs, the merge-base regression gate, assert-known-test-outcomes.php, guard-no-workdo-changes.sh, guard-gate-f-containment.sh, guard-no-new-root-uploads.sh, guard-test-isolation.sh, guard-no-env-files.sh, scan-secrets.sh, and .github/SECURITY-DIFF-REVIEW.md
- **Source:** `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md FINAL §4 and CLOSEOUT §2C item 7`
- **Owner decisions:** D16 — "Merge to `main` approved as a goal, in sequence, and separate from deployment … merge only when branch/release gates permit … do not auto-deploy. If gates do not pass, do not merge." D-35 — development-only main integration unblocked for P1-SEC-40 only.
- **Expected behaviour:** All gates preserved and unweakened; no merge to main and no production deployment while release prerequisites are unmet.
- **Actual behaviour:** The gate scripts are all present in .github/scripts/ (confirmed in this pass: assert-known-test-outcomes.php, guard-composer-lock-integrity.sh, guard-gate-f-containment.sh, guard-no-env-files.sh, guard-no-new-root-uploads.sh, guard-no-workdo-changes.sh, guard-test-isolation.sh, scan-secrets.sh, regression-gate.php, flag-sensitive-diff.sh, assert-human-approval.sh). Nothing was weakened and no manifest entry was added to obtain green. **The no-merge condition has since been overtaken by events**: `main` HEAD is now 4e878b8d "Merge Phase 2.5 Premium Merchant Experience into main" (2026-11-12), so Phase 01 work has been merged into main while its release prerequisites remain unmet.
- **Implementation:** .github/workflows/ci.yml
- **Evidence:** .github/scripts/ (11 gate scripts confirmed present); git log -1 origin/main
- **Gap:** The gates are preserved, but the "main is not to be merged" half no longer holds. D-35 authorised development-only main integration for the P1-SEC-40 item specifically; I found no owner decision covering the general Phase-01 no-merge condition, and production deployment remains blocked.
- **Responsible:** Owner
- **Next action:** Record, against D16, which release gates were judged to permit the 2026-11-12 merge, and confirm production deployment remains blocked.


## Phase 02 Merchant Experience

### `P02-A-PROSE-01` — A — Design System Adoption (prose requirement)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Use CSS logical properties." (and "Preserve semantic design tokens", "Do not introduce a second competing design system", "Do not replace Laravel Blade with a new frontend framework")
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream A, prose after the bullet list`
- **Expected behaviour:** No physical left/right declaration in the design system where a logical property exists, enforced by a ratchet; Blade remains the rendering layer.
- **Actual behaviour:** tests/Unit/Ui/DesignSystemStylesheetScopeTest.php holds `test_the_stylesheet_uses_logical_properties_only`, a detector self-test (`test_the_physical_property_detector_actually_detects`) and a token-scope assertion. StoreAreaRtlPhysicalPropertiesTest adds `test_no_inline_physical_offset_anywhere_in_the_area`. The merchant admin remains Blade; no second design system was introduced.
- **Implementation:** public/css/store-syria-ds.css
- **Evidence:** tests/Unit/Ui/DesignSystemStylesheetScopeTest.php (read here); tests/Feature/Merchant/StoreAreaRtlPhysicalPropertiesTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-A1` — A — Design System Adoption

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Consistent typography and spacing"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream A`
- **Expected behaviour:** Every merchant page draws type and spacing from the Store Syria design-system tokens rather than ad-hoc values.
- **Actual behaviour:** public/css/store-syria-ds.css carries the token scale; 26 <x-ss.*> Blade components exist under resources/views/components/ss/. Type-scale ratchets exist as tests (MerchantTypeScaleTest, DashboardTypeScaleTest, StoreAreaTypeScaleTest).
- **Implementation:** public/css/store-syria-ds.css; resources/views/components/ss/*
- **Evidence:** tests/Unit/Ui/MerchantTypeScaleTest.php, DashboardTypeScaleTest.php, StoreAreaTypeScaleTest.php; docs/development/phase-2/tools/browser-checks-p02.cjs
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-A2` — A — Design System Adoption

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Consistent cards, forms, buttons, tables, badges and alerts"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream A`
- **Expected behaviour:** One component vocabulary for each of the six families, used by merchant screens.
- **Actual behaviour:** Measured in store-syria-ds.css: .ss-card 26 rules, .ss-table 37, .ss-btn 34, .ss-badge 8, .ss-alert 11, .ss-field 11. Components card/chip/stat/status-badge/panel/empty/switch exist as Blade components.
- **Implementation:** public/css/store-syria-ds.css; resources/views/components/ss/*
- **Evidence:** tests/Unit/Ui/SsButtonVariantTest.php, OrderTablesUseDesignSystemTest.php, FinanceTablesUseDesignSystemTest.php, StoreTablesInDesignSystemTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-A3` — A — Design System Adoption

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Unified page headers and breadcrumbs"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream A`
- **Expected behaviour:** One breadcrumb and one page-header component used by every merchant screen; current page not a link; correct RTL separator.
- **Actual behaviour:** resources/views/components/ss/breadcrumb.blade.php and page-header.blade.php exist and are used across merchant views (e.g. page/index, taxes/index, plans/create). BreadcrumbContractTest asserts: no screen uses the host theme's class, exactly one item marked current, the current item is never a link, every link carries the DS class, the layout renders the one component.
- **Implementation:** resources/views/components/ss/breadcrumb.blade.php; resources/views/layouts/app.blade.php
- **Evidence:** tests/Unit/Ui/BreadcrumbContractTest.php (8 methods, read here); docs/development/phase-2/evidence/p2-01-14/breadcrumb-live-24-configurations.txt
- **Gap:** No before/after screenshots for this slice — the evidence is a 24-configuration measurement log.
- **Responsible:** Phase 02 (closed)
- **Next action:** None required; screenshots would close the Mandatory Visual Evidence gap (see P02-XC-02).

### `P02-A4` — A — Design System Adoption

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Arabic RTL and English LTR"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream A`
- **Expected behaviour:** Every merchant surface renders correctly in both directions.
- **Actual behaviour:** browser-checks-p02.cjs drives both directions and asserts direction-sensitive geometry (e.g. 'first stat cell starts at the right edge under rtl'). Screenshot folders carry ar/en pairs for dashboard, products, orders, search, notifications, onboarding.
- **Implementation:** public/css/rtl-custom.css; public/css/store-syria-ds.css; layout direction switch
- **Evidence:** docs/development/phase-2/tools/browser-checks-p02.cjs; docs/development/phase-2/screenshots/* (ar/en pairs in 16 folders)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-A5` — A — Design System Adoption

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Light and dark themes"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream A`
- **Expected behaviour:** Both themes legible and AA-contrast on every merchant surface.
- **Actual behaviour:** [data-ss-theme="dark"] blocks exist in store-syria-ds.css (including the .dropzone override that closed F76). A family of dark-contrast ratchets exists: DarkPageSurfaceTest, DarkModalSurfaceContrastTest, DarkTextUtilityContrastTest, DarkEditorAndPickerSurfaceTest, DarkTimelineSurfaceContrastTest.
- **Implementation:** public/css/store-syria-ds.css dark blocks
- **Evidence:** tests/Unit/Ui/Dark*Test.php (5 classes); screenshots in light/dark pairs across 16 folders
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-A6` — A — Design System Adoption

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Responsive desktop, tablet and mobile layouts"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream A`
- **Expected behaviour:** Merchant screens usable at phone, tablet and desktop widths.
- **Actual behaviour:** browser-checks-p02.cjs declares WIDTHS = [360, 390, 414, 576, 768, 992, 1280, 1440] and runs its checks across them; PhonePageOverflowTest and ReportsFamilyPhoneLayoutTest pin phone-width behaviour.
- **Implementation:** public/css/custom.css, rtl-custom.css, store-syria-ds.css
- **Evidence:** docs/development/phase-2/tools/browser-checks-p02.cjs:25; tests/Unit/Ui/PhonePageOverflowTest.php, ReportsFamilyPhoneLayoutTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-A7` — A — Design System Adoption

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Keyboard navigation and accessibility"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream A`
- **Expected behaviour:** Every interactive merchant surface reachable and operable by keyboard, with visible focus and a trap where modal.
- **Actual behaviour:** Tracked as a single contract under P2-01-20. Focus/keyboard tests exist: CommandPaletteFocusTrapTest, OffCanvasSidebarFocusTest, FocusIndicatorRestorationTest, SettingsTabRailKeyboardTest, FileUploadKeyboardReachabilityTest, ShellSearchAndFocusTest. Live log of 4 configurations recorded.
- **Implementation:** public/js/store-syria-ds.js; resources/views/components/ss/panel.blade.php, actionbar.blade.php
- **Evidence:** docs/development/phase-2/evidence/p2-01-20/keyboard-focus-live-4-configurations.txt; tests/Unit/Ui/{CommandPaletteFocusTrap,OffCanvasSidebarFocus,FocusIndicatorRestoration,SettingsTabRailKeyboard}Test.php
- **Gap:** Evidence is a measurement log, not before/after screenshots.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-A8` — A — Design System Adoption

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Clear loading, empty, success, validation and error states"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream A`
- **Expected behaviour:** All five states exist as design-system surfaces, including the two (loading, success) the 2026-10-25 audit found untracked.
- **Actual behaviour:** .ss-skeleton and .ss-btn[aria-busy="true"] rules exist in store-syria-ds.css (lines ~898 and ~1704); empty/validation/error components exist (empty.blade.php, error-summary.blade.php). TableLoadingStateTest, TableEmptyStatePresentationTest, DataTableEmptyStateTest, ValidationFeedbackVisibilityTest, TransientFeedbackContractTest all exist.
- **Implementation:** public/css/store-syria-ds.css; public/js/store-syria-ds.js; resources/views/components/ss/{empty,error-summary,progress}.blade.php
- **Evidence:** tests/Unit/Ui/TableLoadingStateTest.php, TableEmptyStatePresentationTest.php, TransientFeedbackContractTest.php; tests/Feature/Merchant/EmptyStateTest.php, ValidationFeedbackVisibilityTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-B1` — B — Merchant Workspace and Navigation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Workspace switcher with current-store visibility"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream B`
- **Owner decisions:** getCurrentStore() semantics frozen by the master prompt; unchanged.
- **Expected behaviour:** The merchant always sees which store they are in and can switch without a state-changing GET.
- **Actual behaviour:** resources/views/partision/workspace-chip.blade.php exists and is rendered by the sidebar; User::switchableStores() supplies the list; the store-switch route was converted from GET to POST under P2-05-1/F12 and is ratcheted by StateChangingGetInventoryTest.
- **Implementation:** resources/views/partision/workspace-chip.blade.php; app/Models/User.php (switchableStores)
- **Evidence:** tests/Feature/Security/StateChangingGetInventoryTest.php; docs/development/phase-2/screenshots/p2-01-sidebar-nav/ (14 images)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-B2` — B — Merchant Workspace and Navigation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Clear navigation groups by merchant workflow"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream B`
- **Expected behaviour:** Sidebar grouped by workflow, still honouring Menu::add() entitlement and Laratrust permissions.
- **Actual behaviour:** config/merchant-navigation.php defines zones; app/Helper/helper.php menuZones() builds them; resources/views/partision/menu-zones.blade.php renders them. The menu registry was converted from an HTML string to structured data (P2-01-10) with a DOM-tree parity harness that bites twice.
- **Implementation:** config/merchant-navigation.php; app/Helper/helper.php; resources/views/partision/menu-zones.blade.php, menu-tree.blade.php
- **Evidence:** tests/Support/Security/bin/verify-menu-render-parity.php (17 checks); tests/Unit/Ui/SidebarActiveStateAndIconographyTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-B3` — B — Merchant Workspace and Navigation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Collapsible sidebar"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream B`
- **Expected behaviour:** The sidebar collapses to a rail and the choice persists.
- **Actual behaviour:** public/js/store-syria-nav.js implements the rail and stores its state in localStorage inside try/catch (reads can throw in a private window). Sidebar zones/rail markup in partision/sidebar.blade.php.
- **Implementation:** public/js/store-syria-nav.js; resources/views/partision/sidebar.blade.php
- **Evidence:** docs/development/phase-2/screenshots/p2-01-sidebar-nav/; browser-checks-p02.cjs
- **Gap:** Persistence is per browser (localStorage), not per employee — see P02-G5.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-B4` — B — Merchant Workspace and Navigation

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Improved header"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream B (one of the 31 requirements the 2026-10-25 audit found tracked by no row)`
- **Owner decisions:** D-29.7 (owner, 2026-10-30, PHASE-02-DECISION-REGISTER.md:2566): the conservative header implementation is ACCEPTED FOR PHASE 02 SCOPE, conditional on the accessibility/responsiveness/language/no-regression tests continuing to pass, with the broader visual improvement moved to Phase 2.5. The owner added: 'This approval concerns the header scope only, and does not mean final acceptance of all of Phase 02.'
- **Expected behaviour:** The merchant header is on the design system, its controls have accessible names, and the store link opens safely.
- **Actual behaviour:** Two of three acceptance clauses are closed and tested: the mobile sidebar toggle (an <a> wrapping three divs, announced as bare 'link' on every merchant screen) now carries an aria-label, pinned by HeaderAccessibleNamesTest (4 tests, written failing first); rel=noopener is asserted on target=_blank links. The third clause is NOT met in the strong sense: D-25 Step 1 added `ss-appbar` ALONGSIDE `dash-header`, which stays, because dash.js/side-dash.js select #mobile-collapse inside it and the theme carries ~210 .dash-header rules. The partial is 322 lines with 10 `ss-` occurrences.
- **Implementation:** resources/views/partision/header.blade.php; public/css/store-syria-ds.css (ss-appbar)
- **Evidence:** tests/Unit/Ui/HeaderAccessibleNamesTest.php, HeaderContrastTokensTest.php, HeaderDropdownTriggerNamesTest.php, SharedHeaderDesignSystemScopeTest.php, MerchantHeaderStaysOnScreenTest.php
- **Gap:** The header's styling has NOT moved onto design-system tokens; it is an additive scope only. The ledger cell itself says so: 'THE STATUS CELL THEREFORE STAYS PARTIAL.'
- **Responsible:** Phase 2.5 — Premium Merchant Experience (owner decision)
- **Next action:** Carry the header restyle into Phase 2.5 as an explicit inherited item; do not report WS B4 as fully delivered in Phase 02 terms.

### `P02-B5` — B — Merchant Workspace and Navigation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Quick actions"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream B (audit: MISSING on 2026-10-25)`
- **Owner decisions:** D-24a (owner, 2026-10-28) settled which create-actions belong in the palette; the row was deliberately held at PARTIAL until that decision existed rather than closed by assertion.
- **Expected behaviour:** A permission-aware quick-action surface reachable from anywhere; the host theme's own 'Quick Add' does not satisfy it.
- **Actual behaviour:** app/Services/MerchantQuickActions.php supplies four create-actions as a CONSTANT list (product, manual order, customer, coupon) rendered in the command palette's shortcuts region; the palette is included by layouts/app.blade.php so it is on every merchant screen. 31 target tests plus live checks across 6 screens x AR/EN. The ledger records that three deliberate bites did not bite at first because the page gate dominates, and the cases were reconstructed until each gate bit.
- **Implementation:** app/Services/MerchantQuickActions.php; resources/views/partision/command-palette.blade.php
- **Evidence:** tests/Feature/Security/MerchantQuickActionTargetsTest.php (31); docs/development/phase-2/tools/check-quick-actions-live.cjs; docs/development/phase-2/evidence/p2-01-18/
- **Gap:** The host theme's own 'Quick Add' control remains in partision/header.blade.php (removing it is shared-chrome work deferred with B4). Evidence is logs, not before/after screenshots.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-B6` — B — Merchant Workspace and Navigation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Accessible search/navigation controls"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream B`
- **Expected behaviour:** Search and navigation controls carry explicit accessible names and are operable by keyboard.
- **Actual behaviour:** resources/views/partision/sidebar.blade.php:123-124 gives #sideBarsearch an explicit aria-label alongside the placeholder (the file comments record a live probe showing the placeholder alone was computing a name, so the fix is explicit-name robustness rather than a missing name). The brand link carries an aria-label. ShellSearchAndFocusTest and CommandPaletteFocusTrapTest pin the palette.
- **Implementation:** resources/views/partision/sidebar.blade.php; resources/views/partision/command-palette.blade.php
- **Evidence:** tests/Unit/Ui/ShellSearchAndFocusTest.php, CommandPaletteFocusTrapTest.php, IconOnlyControlNameTest.php; docs/development/phase-2/evidence/sidebar-search-name/
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-B7` — B — Merchant Workspace and Navigation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Persistent employee-level preferences"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream B`
- **Expected behaviour:** A merchant employee's table/UI choices survive navigation and reload and are scoped to that employee.
- **Actual behaviour:** buildDataTable() in app/Helper/helper.php applies stateSave to all 68 DataTables from one shared helper (39 first-party + 29 inside vendored modules, so no packages/workdo file is edited). TablePreferencesTest and TableFilterMemoryTest exist; server-side per-employee filter memory is app/Services/TableFilterMemory.php.
- **Implementation:** app/Helper/helper.php; public/js/store-syria-ds.js; app/Services/TableFilterMemory.php
- **Evidence:** tests/Feature/Merchant/TablePreferencesTest.php, TableFilterMemoryTest.php; docs/development/phase-2/tools/check-table-prefs-live.cjs
- **Gap:** Device-local state (sort/length/column visibility) is per browser; only the filter memory is server-side per employee.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-B8` — B — Merchant Workspace and Navigation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Saved favorites and recent pages where appropriate"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream B (audit: MISSING on 2026-10-25; same gap as G4)`
- **Owner decisions:** Owner decision 2026-10-27 (ledger P2-06-7): favourites are permanent per-employee preferences restored after login and from other devices; recents are short-lived within the session, not a browsing history. D-24d approved the migration under dev/test-only conditions.
- **Expected behaviour:** A merchant can mark pages as favourites and see recently visited ones, both permission-aware; favourites persist across devices, recents are session-scoped.
- **Actual behaviour:** app/Services/FavouritePages.php and RecentPages.php exist; app/Models/MerchantFavouritePage.php plus database/migrations/2026_10_27_000100_create_merchant_favourite_pages_table.php; MerchantPageShortcutController serves them into the command palette.
- **Implementation:** app/Services/{FavouritePages,RecentPages}.php; app/Http/Controllers/MerchantPageShortcutController.php; resources/views/partision/command-palette.blade.php
- **Evidence:** tests/Feature/Merchant/FavouritePagesTest.php; tests/Unit/Ui/FavouriteToggleStateContractTest.php
- **Gap:** The favourites table is a migration approved for development and test environments only (D-24d). On production, where no Phase 02 migration has been run, favourites cannot persist.
- **Responsible:** Owner — production migration authorisation
- **Next action:** Before release, decide whether merchant_favourite_pages is migrated in production; until then the feature is inert there.

### `P02-B9` — B — Merchant Workspace and Navigation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Responsive mobile navigation"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream B`
- **Expected behaviour:** The sidebar/navigation is legible and operable below desktop width.
- **Actual behaviour:** P2-01-13 fixed legibility below 1025px in public/css/custom.css and rtl-custom.css with 10 browser checks across 5 widths x 2 languages, bite-tested (revert → exactly 8 failures). OffCanvasSidebarFocusTest pins the off-canvas focus behaviour.
- **Implementation:** public/css/custom.css; public/css/rtl-custom.css; public/js/store-syria-nav.js
- **Evidence:** tests/Unit/Ui/OffCanvasSidebarFocusTest.php; browser-checks-p02.cjs; docs/development/phase-2/screenshots/p2-01-sidebar-nav/
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-C1` — C — Merchant Dashboard

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Store overview"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream C`
- **Expected behaviour:** A store overview region on the merchant home, honest about what it knows and absent when empty.
- **Actual behaviour:** P2-02-15 added the three regions the master prompt names and the page lacked (inventory alerts, store overview, important notifications). resources/views/dashboard/overview.blade.php holds them; DashboardMissingRegionsTest pins them.
- **Implementation:** resources/views/dashboard/overview.blade.php; app/Http/Controllers/HomeController.php
- **Evidence:** tests/Feature/Merchant/DashboardMissingRegionsTest.php; docs/development/phase-2/screenshots/p2-final-dashboard/ (49 images)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-C2` — C — Merchant Dashboard

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Sales and orders summary"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream C`
- **Owner decisions:** PRD-001 (label, referenced by the ledger; the defining register document is missing — see missing_sources).
- **Expected behaviour:** Sales/orders figures shown with their period, with sales, payments, settlements and profit kept distinct.
- **Actual behaviour:** The stat grid carries a period per figure; the order-value figure is labelled as gross order value and gated on 'Manage Order' (P2-02-10); the status breakdown partitions the total (P2-04-1, bites: old behaviour shows 1,272 against a total of 1,284).
- **Implementation:** resources/views/dashboard/overview.blade.php; app/Http/Controllers/HomeController.php
- **Evidence:** tests/Feature/Merchant/DashboardFigureHonestyTest.php, DashboardOrderValueTest.php; tests/Unit/Ui/DashboardOverviewOperatorGateTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-C3` — C — Merchant Dashboard

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Recent orders"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream C`
- **Expected behaviour:** A recent-orders card showing real rows.
- **Actual behaviour:** Present in overview.blade.php. The ledger records the honest failure that produced it: the two list cards had been built, screenshotted and signed off while they had never held a row, because the fixture left orders.product_id at '0'. Fixed and pinned.
- **Implementation:** resources/views/dashboard/overview.blade.php
- **Evidence:** tests/Feature/Merchant/DashboardBestSellersTest.php (4 tests, bitten three ways); database/seed-p2dev.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-C4` — C — Merchant Dashboard

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Pending operational tasks"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream C`
- **Expected behaviour:** A 'needs attention' worklist, absent when there is nothing to do.
- **Actual behaviour:** Implemented in overview.blade.php (P2-02-2) with a browser check asserting the block is ABSENT in the empty state rather than showing a permanent zero.
- **Implementation:** resources/views/dashboard/overview.blade.php; resources/views/components/ss/attention.blade.php
- **Evidence:** docs/development/phase-2/tools/browser-checks-p02.cjs; screenshots/p2-wsc-dashboard/*attention*
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-C5` — C — Merchant Dashboard

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Inventory alerts"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream C`
- **Expected behaviour:** Low/out-of-stock alerting on the dashboard, using the product list's own rule so the two cannot disagree.
- **Actual behaviour:** HomeController reuses ProductDataTable's own predicate (P2-02-3); P2-02-15 added the low-stock region via ProductDataTable::scopeToLow*; DashboardStockCountAgreementTest asserts the dashboard and the list agree.
- **Implementation:** app/Http/Controllers/HomeController.php; app/DataTables/ProductDataTable.php; resources/views/dashboard/overview.blade.php
- **Evidence:** tests/Feature/Merchant/DashboardStockCountAgreementTest.php; DashboardMissingRegionsTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-C6` — C — Merchant Dashboard

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Shortcuts to common actions"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream C`
- **Expected behaviour:** The merchant can reach common actions from the dashboard.
- **Actual behaviour:** Status counts link out (P2-02-4) and links/chip interactivity are dropped for principals the controller would refuse (P2-02-11: ungated staff get 0 links and 6 static chips). The quick-action surface (P02-B5) is reachable from the dashboard via the command palette.
- **Implementation:** resources/views/dashboard/overview.blade.php; resources/views/components/ss/chip.blade.php
- **Evidence:** tests/Feature/Merchant/DashboardStatusChipLinkTest.php; browser-checks-p02.cjs; tests/Feature/Security/MerchantQuickActionTargetsTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-C7` — C — Merchant Dashboard

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Important store notifications"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream C`
- **Expected behaviour:** Important notifications surfaced on the dashboard without a second polling feed.
- **Actual behaviour:** overview.blade.php:323 renders a [data-ss-notification-digest] section that is hidden when the feed is empty and reuses the header bell's single per-page fetch (merchant.notifications.feed) rather than adding a second feed — the file states that reasoning inline.
- **Implementation:** resources/views/dashboard/overview.blade.php; app/Http/Controllers/MerchantNotificationController.php
- **Evidence:** resources/views/dashboard/overview.blade.php:301-337 (read here); tests/Feature/Merchant/DashboardMissingRegionsTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-C8` — C — Merchant Dashboard

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Clear date range and data state" (with "Do not invent financial metrics", "Do not display unverified profitability", "Preserve differences between sales, payments, settlements, and profit")
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream C`
- **Expected behaviour:** Every figure carries its period; no profitability figure; honest unavailable states.
- **Actual behaviour:** Browser checks assert every stat carries a period and that no 'revenue'/'profit' claim appears, with a disclaimer present (P2-02-1, P2-02-5). The dashboard chart was stopped from serving platform data to merchant staff (P2-02-12), verified by a branch-level harness bitten three ways.
- **Implementation:** resources/views/dashboard/overview.blade.php; app/Http/Controllers/HomeController.php
- **Evidence:** tests/Support/Security/bin/verify-order-chart-operator-branch.php (9 checks); tests/Feature/Merchant/DashboardFigureHonestyTest.php; browser-checks-p02.cjs
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D1` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Modern product list"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Expected behaviour:** A design-system product list, including row density the merchant can choose and that persists.
- **Actual behaviour:** buildDataTable/buildDataTableColumn in app/Helper/helper.php plus components/datatable.blade.php; row density shipped under P2-03-10 with RowDensityTest and dedicated screenshots.
- **Implementation:** app/Helper/helper.php; resources/views/components/datatable.blade.php; public/css/store-syria-ds.css
- **Evidence:** tests/Feature/Merchant/RowDensityTest.php; docs/development/phase-2/screenshots/p2-03-density/ (8); docs/development/phase-2/evidence/p2-03-1/
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D10` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Validation summary and inline errors"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Owner decisions:** D-03 (F70) approved the validation/error-surfacing work.
- **Expected behaviour:** A focusable validation summary plus inline field errors.
- **Actual behaviour:** resources/views/components/ss/error-summary.blade.php is live on both product forms; P2-01-9 drives the summary's focus move in a browser.
- **Implementation:** resources/views/components/ss/error-summary.blade.php; public/js/store-syria-ds.js
- **Evidence:** tests/Feature/Merchant/ValidationFeedbackVisibilityTest.php, ValidationRefusalNamesEveryFieldTest.php; docs/development/phase-2/screenshots/p2-wsf-validation/ (32)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D11` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Sticky save controls"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Expected behaviour:** Save reachable without scrolling back up a long form.
- **Actual behaviour:** ss-actionbar with scroll-padding-block-end in .ss-has-actionbar; the need was measured, not asserted — the only Save was #submit-all in the page header, sitting at y = -1175 at 1440px and y = -3131 at 390px when scrolled to the bottom of the create form.
- **Implementation:** resources/views/components/ss/actionbar.blade.php; public/css/store-syria-ds.css
- **Evidence:** docs/development/phase-2/tools/check-product-editor-live.cjs; browser-checks-p02.cjs (21 interactive checks)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D12` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Unsaved-change detection"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Expected behaviour:** A dirty-state guard that arms on real edits.
- **Actual behaviour:** data-ss-dirty-track plus a beforeunload guard in public/js/store-syria-ds.js, driven in a browser: armed by TYPING and — the case that matters — NOT armed by focus alone.
- **Implementation:** public/js/store-syria-ds.js
- **Evidence:** docs/development/phase-2/tools/browser-checks-p02.cjs (P2-01-9 interactive checks)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D13` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Concurrent-edit conflict detection"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Expected behaviour:** A stale edit is refused without losing the other editor's work, and no migration is required.
- **Actual behaviour:** ProductController::update compares an ss_last_seen_at token (updated_at is the token, so no schema change). ProductConcurrentEditTest covers 5 cases including a stale token being refused with A's price and name surviving; bite-tested (disabling the comparison fails exactly the stale-token case). The token is deliberately opt-in so the Flutter app and API, which post without it, keep working.
- **Implementation:** app/Http/Controllers/ProductController.php; resources/views/product/edit.blade.php
- **Evidence:** tests/Feature/Merchant/ProductConcurrentEditTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D14` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Record preview side panel"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Expected behaviour:** A quick product preview in a side panel, authorised server-side.
- **Actual behaviour:** ProductController::product_preview with its own route and panel partial; ProductPreviewAuthorizationTest covers 8 cases including cross-tenant refusal, a same-tenant staff member refused without Manage Products and admitted once granted, anonymous refused, and a non-integer id refused rather than coerced.
- **Implementation:** app/Http/Controllers/ProductController.php; resources/views/product/partials/preview.blade.php; routes/web.php
- **Evidence:** tests/Feature/Merchant/ProductPreviewAuthorizationTest.php (named in ledger; class present in tests tree)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D2` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Fast search and filters"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Expected behaviour:** Real filters on the product list (status/stock/category/variants), carried in the URL.
- **Actual behaviour:** ProductDataTable::applyFilters plus resources/views/product/partials/filters.blade.php. The ledger records that the list previously had no filters at all beyond DataTables' free-text box.
- **Implementation:** app/DataTables/ProductDataTable.php; resources/views/product/partials/filters.blade.php; ProductController::index
- **Evidence:** tests/Feature/Merchant/ProductListFilterTest.php; docs/development/phase-2/tools/check-product-filters-live.cjs
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D3` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Saved table preferences"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Expected behaviour:** The merchant's table preferences persist.
- **Actual behaviour:** Same mechanism as B7: one change in buildDataTable() reaches all 68 tables; filter memory is server-side per employee.
- **Implementation:** app/Helper/helper.php; app/Services/TableFilterMemory.php
- **Evidence:** tests/Feature/Merchant/TablePreferencesTest.php, TableFilterMemoryTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D4` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Clear product status and stock indicators"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D (audit: PARTIAL — a defect fix with no requirement row)`
- **Expected behaviour:** Status and stock distinguishable without colour, carrying their rule, agreeing with the list's own predicate.
- **Actual behaviour:** ProductDataTable gained trackedStockState(), stockFallbackThreshold() and a stock_status column (P2-03-9); ProductStockIndicatorTest and ProductGridBadgeContrastTest pin behaviour and contrast.
- **Implementation:** app/DataTables/ProductDataTable.php; app/Support/*
- **Evidence:** tests/Feature/Merchant/ProductStockIndicatorTest.php, ProductGridBadgeContrastTest.php; tests/Feature/Merchant/DashboardStockCountAgreementTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D5` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Full-page product editor"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Owner decisions:** D-01 (products.cost_price migration approved, dev/test) unblocked CREATE.
- **Expected behaviour:** Create and edit are full-page forms that really save, with no field lost.
- **Actual behaviour:** product/create.blade.php and edit.blade.php carry the sticky action bar, validation summary and dirty tracking; the sticky Save proxies a click to the existing #submit-all rather than adding a second submit path (which would bypass the concurrency token). Field inventory asserted before/after: create 45→45, edit 48→48. End-to-end UPDATE and CREATE both driven live (9/9) after the parked products.cost_price migration was approved (D-01/F58).
- **Implementation:** resources/views/product/{create,edit}.blade.php; public/js/store-syria-ds.js (wireClickProxy)
- **Evidence:** docs/development/phase-2/tools/check-product-editor-live.cjs; tests/Feature/Merchant/ProductCreateSaveReentrancyTest.php, ProductUpdateRefusalPersistsNothingTest.php; screenshots/p2-03-products/ (28)
- **Gap:** The ledger cell still reads PARTIAL; its stated remainders (contextual help, status/stock indicators, media) are each DONE or owner-deferred elsewhere, so the cell is stale in the conservative direction.
- **Responsible:** Phase 02 (closed)
- **Next action:** Correct the stale PARTIAL on ledger row P2-03-3 so it does not read as an open gap.

### `P02-D6` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Basic and advanced field organization"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D (audit: MISSING on 2026-10-25)`
- **Expected behaviour:** Everyday fields separated from advanced ones without hiding any existing field; keyboard reachable and announced.
- **Actual behaviour:** resources/views/components/ss/advanced-fields.blade.php used by product create and edit; AdvancedFieldsDisclosureTest pins the disclosure.
- **Implementation:** resources/views/components/ss/advanced-fields.blade.php; resources/views/product/{create,edit}.blade.php
- **Evidence:** tests/Feature/Merchant/AdvancedFieldsDisclosureTest.php
- **Gap:** The Flutter product editor is still one flat run (parity gap G6) — see P02-K9.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-D7` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Images and media workflow"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Owner decisions:** D-06 (owner, 2026-10-26): gallery image reorder DEFERRED to Phase 03 with full parity across storefront, web, Flutter and the WorkDo add-ons; explicitly recorded as a scope-limited deferral, with no unused sort_order column and no web-only ordering. D-26 (owner, 2026-10-29): per-variant media approved for Phase 02 (built); Bulk Edit Across Combinations assigned to Phase 04.
- **Expected behaviour:** Upload, deletion, primary image, validation errors and per-variant media work and are verified by driving them.
- **Actual behaviour:** Four of the owner's five closing conditions verified by driving (upload, deletion, primary image, validation errors); media driven 17/17 with the client now refusing a GIF before upload and reporting server refusals by name. The two items the ledger left open are now closed in code: F76 (.dropzone white in both themes) has a dark override at store-syria-ds.css:5069+ pinned by DarkEditorAndPickerSurfaceTest, and the missing `accept` attributes are pinned by UploadFieldAcceptContractTest ('every named file field advertises the server's own list').
- **Implementation:** app/Services/UploadLimits.php; app/Http/Controllers/ProductController.php; resources/views/product/{create,edit}.blade.php
- **Evidence:** tests/Unit/Ui/UploadFieldAcceptContractTest.php, DarkEditorAndPickerSurfaceTest.php (both read here); docs/development/phase-2/tools/check-gallery-upload-feedback-live.cjs; tests/Unit/Merchant/UploadLimitsTest.php
- **Gap:** Gallery image REORDER is not implemented and is not a Phase 02 deliverable; bulk edit across variant combinations is not implemented.
- **Responsible:** Phase 03 (reorder), Phase 04 (bulk edit) — by owner decision
- **Next action:** Carry D-06's reorder into the Phase 03 requirement set so it is not lost at phase boundary.

### `P02-D8` — D — Product Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Existing variants/options support"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D`
- **Owner decisions:** D-09/D-16 (owner, 2026-10-26) approved design A1. D-20 is recorded as PARTIAL for the narrowing-an-option-set case: refusal and blocking implemented, the no-loss alternative analysed and NOT built.
- **Expected behaviour:** Existing variant/option behaviour preserved and correct — no silent data loss.
- **Actual behaviour:** Design A1 was approved (D-16) and implemented, closing four real defects: F82 (a variant price edit silently discarded), F83 (rebuild hard-deleting variants and zeroing prices), F84 and F105 (an ordinary save zeroing 11 of 12 variant prices). ProductVariantPersistenceTest, ProductVariantOrdinarySaveGuardTest and VariantCombinationVocabularyTest pin it.
- **Implementation:** app/Http/Controllers/ProductController.php; resources/views/product/attribute_combinations*.blade.php
- **Evidence:** tests/Feature/Merchant/ProductVariantPersistenceTest.php, ProductVariantOrdinarySaveGuardTest.php, VariantFieldLabelAssociationTest.php; docs/development/phase-2/P2-03-4-VARIANT-PERSISTENCE-DECISION.md
- **Responsible:** Phase 02 (closed) with D-20's no-loss alternative open
- **Next action:** Track D-20's unbuilt no-loss alternative into a later phase rather than letting it close with Phase 02.

### `P02-D9` — D — Product Management Experience

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Contextual help"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream D (audit: MISSING on 2026-10-25; same gap as I2)`
- **Owner decisions:** None found.
- **Expected behaviour:** Help attached to the field it is about via aria-describedby, in both languages, on the merchant's forms.
- **Actual behaviour:** Measured here: resources/views/components/ss/field-help.blade.php is used by exactly three forms — product/create.blade.php, product/edit.blade.php and customer/create.blade.php. The ledger row P2-06-10 says so itself: 'DONE on the product editor; the other screens remain.'
- **Implementation:** resources/views/components/ss/field-help.blade.php; product/{create,edit}, customer/create
- **Evidence:** tests/Feature/Merchant/ContextualFieldHelpTest.php
- **Gap:** Every other merchant form (orders, categories, attributes, staff, settings, shipping, taxes, coupons) has no contextual field help. No owner decision defers this.
- **Responsible:** Phase 2.5 or a later merchant-experience slice
- **Next action:** Either extend field-help to the remaining merchant forms or obtain an explicit owner decision scoping it to the product and customer forms.

### `P02-E1` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Improved default order table"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E`
- **Expected behaviour:** The order list is on the design system with correct status tone and row actions.
- **Actual behaviour:** OrderDataTable plus app/Support/OrderStatusTone.php and order/action.blade.php; F19 fixed (the list no longer renders the Unix epoch as a date — Utility::dateFormat() passed strtotime(null)=false into date()).
- **Implementation:** app/DataTables/OrderDataTable.php; app/Support/OrderStatusTone.php; resources/views/order/action.blade.php
- **Evidence:** tests/Feature/Merchant/OrderStatusDateTest.php (4 cases through the real yajra AJAX leg); tests/Unit/Ui/OrderTablesUseDesignSystemTest.php, OrderListControlNamesAndContrastTest.php; docs/development/phase-2/tools/check-row-actions-live.cjs
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-E10` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Better printing and export UI where already supported"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E (audit: MISSING on 2026-10-25)`
- **Owner decisions:** D-24b (owner, 2026-10-28): 'APPROVED: KEEP WORKDO.' The absence of a new first-party invoice preview is explicitly NOT a Phase 02 shortfall; any further preview work is deferred to a later phase.
- **Expected behaviour:** Shipping label, receipt, invoice preview and exports are reachable, permission-gated and on the design system.
- **Actual behaviour:** Shipping label (routes/web.php:1601), receipt (:1697) and the order/report exports (order.export, reports.export and six siblings) exist and are gated by OrderDocumentAccessGuard. Export control presentation is pinned by tests.
- **Implementation:** routes/web.php; app/Http/Controllers/OrderController.php; app/Support/OrderDocumentAccessGuard.php
- **Evidence:** tests/Unit/Ui/ReportExportControlContractTest.php; tests/Feature/Merchant/OrderExportColumnAlignmentTest.php, ReportExportFailureFeedbackTest.php; tests/Feature/Security OrderDocumentAccess tests
- **Gap:** No first-party invoice preview. The existing preview is vendored (packages/workdo/AutomaticOrderPrinting/), which Phase 02 may not edit.
- **Responsible:** Later phase (owner decision D-24b)
- **Next action:** None in Phase 02; carry a first-party invoice preview as an explicitly deferred item.

### `P02-E11` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** RULE: "No editing order products or quantities after creation"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E, approved order rules (audit: MISSING — no row, no guard test)`
- **Owner decisions:** ORD-001 is cited by the ledger as forbidding it outright; the defining register document is missing (see missing_sources).
- **Expected behaviour:** A server-side guard, not a hidden control: an attempt to change order lines or quantities after creation is refused.
- **Actual behaviour:** OrderLineImmutabilityTest (read here) asserts the fixture really holds the lines it claims, that no order endpoint applies posted line items, that lines survive a whole sequence of legitimate changes, and that no handler mass-assigns request data into an order.
- **Implementation:** app/Http/Controllers/OrderController.php; app/Models/Order.php
- **Evidence:** tests/Feature/Security/OrderLineImmutabilityTest.php (4 test methods, read here)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-E12` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** RULE: "Only authorized delivery and internal note changes when allowed"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E, approved order rules (audit: MISSING)`
- **Expected behaviour:** Delivery assignment and order notes are writable only by a principal holding the matching permission and owning the store.
- **Actual behaviour:** OrderAssignPermissionTest (read here): a merchant without Manage Order cannot assign their own order; the same principal succeeds once granted; the platform operator may assign without holding the permission (the operator-first ordering this codebase requires). Note authorship and visibility are pinned separately.
- **Implementation:** app/Http/Controllers/OrderController.php; app/Policies/OrderNotePolicy.php
- **Evidence:** tests/Feature/Security/OrderAssignPermissionTest.php (3 methods, read here); tests/Feature/Merchant/OrderNoteAuthorTest.php, OrderNoteVisibilityTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-E13` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** RULE: "Product corrections through cancellation and linked replacement orders"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E, approved order rules (audit: MISSING; later the single BLOCKED item in the 2026-10-30 re-derivation)`
- **Owner decisions:** D-07 (minimum safe path approved in Phase 02), D-15 (owner, 2026-10-26: conditional approval with nine binding conditions, including 'do not treat the original order as having consumed stock without evidence' and migration in isolated dev/test only), D-32 (owner, 2026-10-31: identical products, quantities and prices only; different-item replacement is Phase 04 and this is not approval of new refund, settlement or stock rules).
- **Expected behaviour:** The UI offers no in-place product correction; it offers cancel-and-replace, and the replacement is linked to the original, with no duplicated payment or stock movement.
- **Actual behaviour:** app/Services/OrderReplacement::cancelAndReplace() builds the replacement from the original's own product_json (identical lines), refuses collected states by ALLOWLIST, refuses a second replacement per original, and runs in one transaction. It is reachable: routes/web.php:1611 POST order-replace/{id} → OrderController::order_replace (:1782), behind the existing OrderPolicy::updateStatus gate with no new permission invented. orders.replaced_order_id plus replacedOrder()/replacementOrder() relations exist. The stock-evidence prerequisite that previously blocked it is wired: AppliesCartStockChanges.php:190 now calls StockMovement::attributeToOrder() in the production path (this was F237 — the guard had been inert).
- **Implementation:** app/Services/OrderReplacement.php; app/Http/Controllers/OrderController.php::order_replace; database/migrations/2026_10_31_000300_add_replaced_order_id_to_orders_table.php
- **Evidence:** tests/Feature/Commerce/OrderReplacementTest.php (29 methods incl. 'the pair moves the stock once and the lines say why', 'a replayed call produces no second replacement', 'a dispatched original is refused and nothing is written'); tests/Feature/Merchant/OrderReplacementEntryPointTest.php (9); tests/Feature/Commerce/StockAttributionTest.php; tests/Support/Security/bin/verify-order-replacement-concurrency-on-mysql.php; screenshots/e13-order-replace/ (19)
- **Gap:** TWO REAL LIMITS. (1) The linking migration is approved for development and test environments only (D-15 condition 9), and OrderReplacement::isAvailable() checks Schema::hasColumn, so on production the operation is INERT. (2) D-15 requirement E — collected, partial or unverifiable payments — is handled by refusal, not by a settlement path; no refund to a customer is possible anywhere in the codebase, which is why the automatic path is refused for those cases.
- **Responsible:** Owner — production migration authorisation; Phase 04 — different-item replacement
- **Next action:** Record explicitly that E13 is implemented but production-inert until the owner authorises the replaced_order_id migration.

### `P02-E14` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** RULE: "No duplication of payments or inventory movements"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E, approved order rules (audit: MISSING)`
- **Owner decisions:** D-31 (fail-closed financial fix) and D-33 (Benefit and Flutterwave deferred out of Phase 02 scope) bound what was in scope for the payment half.
- **Expected behaviour:** No path produces a second payment row or a second stock movement for one event, cancellation and replacement included.
- **Actual behaviour:** A family of idempotency tests exists over the real writers: WebCheckoutIntakeIdempotencyTest, StorefrontOrderIntakeIdempotencyTest, PosSaleIdempotencyTest, GatewayReferenceIdempotencyTest, PaymeraCallbackIdempotencyTest, RefundStockIdempotencyTest, WalletChangeIdempotencyTest, plus GatewayReplayGuardCensusTest. The replacement pair's net stock movement is asserted as -N, not -2N. The 2026-10-31 handoff also records that the earlier F220 census was wrong in all four of its numbers (Order::create had no left anchor and matched PlanOrder::create) and was corrected.
- **Implementation:** app/Services/{OrderReplacement,ProductStockService,StockMovement,OrderFinancialService}.php; app/Http/Controllers/Concerns/{AppliesCartStockChanges,RestoresCancelledOrderStock}.php
- **Evidence:** tests/Feature/Commerce/*IdempotencyTest.php (8 classes); GatewayReplayGuardCensusTest.php; OrderReplacementTest::test_the_pair_moves_the_stock_once_and_the_lines_say_why
- **Gap:** The ledger's own wording is 'every REACHABLE customer-order writer' — reachability, not exhaustiveness, is the claim.
- **Responsible:** Phase 02 (closed) with D-33's two providers deferred
- **Next action:** None in Phase 02.

### `P02-E2` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Optional Kanban presentation"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E`
- **Expected behaviour:** An optional board view of orders that respects permissions and order rules.
- **Actual behaviour:** Route `order-kanban` → OrderController::order_kanban_view (routes/web.php:1312) renders order/kanban.blade.php with kanban-card partial and .ss-board* styles.
- **Implementation:** app/Http/Controllers/OrderController.php; resources/views/order/kanban.blade.php; public/css/store-syria-ds.css (.ss-board)
- **Evidence:** tests/Feature/Merchant/OrderKanbanTest.php, OrderKanbanMoveTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-E3` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Saved views and filters"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E (audit: PARTIAL — filters shipped, SAVED VIEWS had no row)`
- **Owner decisions:** D-24d (owner, 2026-10-28) approved four migrations with conditions, for development and test environments only.
- **Expected behaviour:** A named set of filters a merchant can save, reuse and share.
- **Actual behaviour:** app/Models/SavedView.php, database/migrations/2026_10_28_000003_create_saved_views_table.php and SavedViewController with store/apply/destroy routes (routes/web.php:1320-1322). Sharing was already satisfied because P2-04-8 made every filter combination a URL.
- **Implementation:** app/Models/SavedView.php; app/Http/Controllers/SavedViewController.php; migration 2026_10_28_000003
- **Evidence:** tests/Feature/Merchant/SavedViewInterfaceTest.php; tests/Feature/Merchant/OrderListFilterTest.php
- **Gap:** The ledger marks this 'DONE — dev/test migration only'. The saved_views table has not been migrated in production, so the feature is inert there.
- **Responsible:** Owner — production migration authorisation
- **Next action:** Include saved_views in whatever production migration authorisation precedes release.

### `P02-E4` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Quick order preview"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E`
- **Expected behaviour:** A read-only order preview panel carrying its own tenant predicate derived from the authenticated principal, not from an encrypted id.
- **Actual behaviour:** OrderController::order_preview with its own route, its own store predicate, and an OrderDocumentAccessGuard::TARGETS entry. OrderPreviewAuthorizationTest covers 9 cases (cross-tenant, per-permission, anonymous, non-integer ids) and measures the two controls separately.
- **Implementation:** app/Http/Controllers/OrderController.php; resources/views/order/partials/preview.blade.php; app/Support/OrderDocumentAccessGuard.php
- **Evidence:** tests/Feature/Merchant/OrderPreviewAuthorizationTest.php; screenshots/p2-04-orders/ (24)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-E5` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Full order detail page"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E`
- **Owner decisions:** D-25 (shared merchant chrome scope) authorised the restyle in three steps, all executed.
- **Expected behaviour:** A complete, authorised, design-system order detail page.
- **Actual behaviour:** order/view.blade.php restyled under D-25 Step 3; the earlier blocker (Order::order_detail() reaching Order::find() with no tenant predicate on a forgeable Crypt id) is covered by OrderDocumentAccessGuard with 'order-view/{id}' in TARGETS and cross-tenant data sets in the access test.
- **Implementation:** resources/views/order/view.blade.php; app/Support/OrderDocumentAccessGuard.php
- **Evidence:** tests/Feature/Merchant/OrderDetailTest.php, OrderDetailContractTest.php, OrderDetailControlNamesTest.php; tests/Unit/Ui/OrderDetailShellContractTest.php; screenshots/p2-04-detail/ (16)
- **Gap:** The guard keys on $route->uri(), so any NEW route reaching order_detail() needs its own TARGETS entry and test case — a standing trap, documented.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-E6` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Distinct order, payment, fulfilment and shipping status presentation"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E (audit: PARTIAL — the four-way split was not a row)`
- **Expected behaviour:** The four kinds of status are presented as different things, not collapsed into one badge.
- **Actual behaviour:** Read here: order/partials/filters.blade.php groups the two non-fulfilment values under an optgroup labelled 'Recorded in the same field, but not fulfilment states' (delivered_status holds nine values; 7 is Partially Paid, 8 is Pre Order); order/partials/preview.blade.php states three readings — fulfilment, payment and order type — explicitly.
- **Implementation:** resources/views/order/partials/*; app/Http/Controllers/HomeController.php status constants
- **Evidence:** resources/views/order/partials/{filters,preview}.blade.php (read here); tests/Feature/Merchant/OrderStatusLabelTest.php, PaymentTypeDisplayTest.php; tests/Unit/Ui/OrderGridStatusLabelTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-E7` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Permission-aware actions"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E (audit: MISSING on 2026-10-25)`
- **Expected behaviour:** Every order action is hidden AND refused for a principal who may not perform it — hiding alone is not the requirement.
- **Actual behaviour:** Enforced through OrderPolicy and mayUseOrderScreen; a census test asserts the hidden-and-refused pair per action.
- **Implementation:** app/Http/Controllers/OrderController.php; app/Policies/OrderPolicy.php; resources/views/order/action.blade.php
- **Evidence:** tests/Feature/Merchant/OrderActionPermissionCensusTest.php; tests/Feature/Security/AdminApiOrderWritePermissionTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-E8` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Clear manual order entry workflow where supported"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E (audit: MISSING on 2026-10-25)`
- **Owner decisions:** D-24d / D-24d-i (owner, 2026-10-28) approved orders.order_source with five recorded decisions, for dev/test only.
- **Expected behaviour:** The manual/POS order path is on the design system and its difference from a storefront order is visible to the merchant.
- **Actual behaviour:** Two manual paths already existed (POS under Route::resource('pos') gated on Manage Pos, and the order form); neither could be told apart from a storefront order. orders.order_source was added (migration 2026_10_28_000001) and is written by the order writers, with a writer census pinning that no writer forgets it.
- **Implementation:** database/migrations/2026_10_28_000001_add_order_source_to_orders_table.php; app/Http/Controllers/OrderController.php; POS path
- **Evidence:** tests/Feature/Merchant/OrderSourceAttributionTest.php; tests/Unit/Database/OrderSourceWriterCensusTest.php
- **Gap:** Dev/test migration only — on production, orders.order_source does not exist, so the distinction is not visible there. The Flutter app receives order_source but does not display it (parity gap G8).
- **Responsible:** Owner — production migration authorisation; Flutter display is a later slice
- **Next action:** Carry G8 (app does not show order_source) forward as an explicit open parity item.

### `P02-E9` — E — Order Management Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Operational \"Needs Attention\" center"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream E (audit: PARTIAL — the dashboard block does not satisfy it)`
- **Expected behaviour:** An order-level attention surface, distinct from the dashboard block, naming what needs doing and linking to it.
- **Actual behaviour:** OrderController::index/orderAttentionCounts feed an order-level attention surface; OrderAttentionCentreTest pins it.
- **Implementation:** app/Http/Controllers/OrderController.php; resources/views/order/index.blade.php
- **Evidence:** tests/Feature/Merchant/OrderAttentionCentreTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-F-PROSE-01` — F — Reusable Forms and Record Panels (prose requirement)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Preserve existing AJAX modal contracts during migration."
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F, closing sentence (audit: found by reading the prose, not the bullets)`
- **Expected behaviour:** Every data-ajax-popup caller still works after a screen is migrated; a ratchet counts them so a migration cannot quietly drop one.
- **Actual behaviour:** AjaxPopupContractRatchetTest exists as that ratchet; the ledger records the practice it encodes (P2-04-5 added the side panel BESIDE the modal rather than replacing it).
- **Implementation:** resources/views/**/action.blade.php; public/js/custom.js
- **Evidence:** tests/Unit/Ui/AjaxPopupContractRatchetTest.php; tests/Unit/Ui/ActionPartialContractTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-F1` — F — Reusable Forms and Record Panels

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Full-page editing for complex forms"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F`
- **Expected behaviour:** Complex records are edited on a full page, not in a modal.
- **Actual behaviour:** The product editor is the reference implementation (see P02-D5); order detail is a full page.
- **Implementation:** resources/views/product/{create,edit}.blade.php; resources/views/order/view.blade.php
- **Evidence:** docs/development/phase-2/tools/check-product-editor-live.cjs; tests/Unit/Ui/OrderDetailShellContractTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-F2` — F — Reusable Forms and Record Panels

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Side panels for quick previews and simple editing"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F`
- **Expected behaviour:** One side-panel surface with correct focus and scroll behaviour, used for previews.
- **Actual behaviour:** components/ss/panel.blade.php with .ss-panel styles; driven in a browser by 21 interactive checks — focus trap 8/8, Esc, scroll lock, focus return to trigger, RTL panel edge.
- **Implementation:** resources/views/components/ss/panel.blade.php; public/js/store-syria-ds.js
- **Evidence:** docs/development/phase-2/tools/browser-checks-p02.cjs (P2-01-9); tests/Unit/Ui/PanelStringContractTest.php; screenshots/p2-01-components/
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-F3` — F — Reusable Forms and Record Panels

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Dialogs for confirmations"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F (audit: MISSING on 2026-10-25)`
- **Expected behaviour:** One confirmation component, preserving the existing show_confirm behaviour.
- **Actual behaviour:** resources/views/components/ss/confirm-dialog.blade.php exists; ConfirmationDialogContractTest and ConfirmDialogButtonContrastTest pin shape and contrast.
- **Implementation:** resources/views/components/ss/confirm-dialog.blade.php; public/js/custom.js (show_confirm preserved)
- **Evidence:** tests/Unit/Ui/ConfirmationDialogContractTest.php, ConfirmDialogButtonContrastTest.php, ModalFooterButtonContrastTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-F4` — F — Reusable Forms and Record Panels

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "No confusing nested modal workflows"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F (audit: MISSING — no row, no check)`
- **Owner decisions:** D-24c (owner, 2026-10-28) states the nested-modal rule as binding.
- **Expected behaviour:** A ratchet asserting no merchant screen opens a modal from inside a modal.
- **Actual behaviour:** tests/Unit/Ui/AjaxPopupContractRatchetTest.php is the only test in the tree matching 'nested modal'; it is the ratchet over data-ajax-popup callers, and the owner stated the rule itself as D-24c ('Preserve Existing Contracts, Eliminate Nested User Experience').
- **Implementation:** resources/views/**; public/js/custom.js
- **Evidence:** tests/Unit/Ui/AjaxPopupContractRatchetTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-F5` — F — Reusable Forms and Record Panels

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Keyboard and focus management"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F`
- **Expected behaviour:** Focus managed as one contract across panel, action bar, dialogs and the palette, not per component.
- **Actual behaviour:** Same contract as P02-A7, tracked under P2-01-20; focus-return-to-trigger and trap behaviour driven live.
- **Implementation:** public/js/store-syria-ds.js
- **Evidence:** docs/development/phase-2/evidence/p2-01-20/keyboard-focus-live-4-configurations.txt; tests/Unit/Ui/FocusIndicatorRestorationTest.php, CommandPaletteFocusTrapTest.php; screenshots/f251-focus/
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-F6` — F — Reusable Forms and Record Panels

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Unsaved-change protection"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F`
- **Expected behaviour:** A shared dirty-state guard available to any merchant form.
- **Actual behaviour:** Same mechanism as P02-D12 (data-ss-dirty-track + beforeunload), implemented in the shared DS script rather than per form.
- **Implementation:** public/js/store-syria-ds.js
- **Evidence:** docs/development/phase-2/tools/browser-checks-p02.cjs
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-F7` — F — Reusable Forms and Record Panels

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Reliable field validation"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F`
- **Owner decisions:** F142's fix was taken on an owner decision (2026-10-27) with the impact measured gradually.
- **Expected behaviour:** Validation that actually runs and names every refused field.
- **Actual behaviour:** A family of 'refusal names every field' tests exists (ValidationRefusalNamesEveryFieldTest, SettingsRefusalNamesEveryFieldTest, CommerceSettingsRefusalNamesEveryFieldTest, ContentAndIntegrationRefusalNamesEveryFieldTest, StoreAndStaffRefusalNamesEveryFieldTest, ModalFormRefusalFeedbackTest). This requirement is where F142 was found — the payment screen's required-field validation had never run because processGateway() discarded validateGatewayFields()'s return.
- **Implementation:** app/Http/Controllers/**; resources/views/components/ss/error-summary.blade.php
- **Evidence:** tests/Feature/Merchant/*RefusalNamesEveryField*Test.php (5 classes); ModalFormRefusalFeedbackTest.php; tests/Unit/Ui/PaymentGatewayFieldContractTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-F8` — F — Reusable Forms and Record Panels

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Concurrent edit/version conflict handling"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F`
- **Expected behaviour:** A version-conflict mechanism usable by merchant forms, whose refusal shape does not break existing clients.
- **Actual behaviour:** As P02-D13. The refusal returns ['flag' => 'error', 'msg' => …], the shape ProductController::update already returns, so no new response shape is introduced for any client.
- **Implementation:** app/Http/Controllers/ProductController.php; resources/views/product/edit.blade.php
- **Evidence:** tests/Feature/Merchant/ProductConcurrentEditTest.php (5 cases, bite-tested)
- **Gap:** Implemented on the product editor only; it is not yet a shared contract other merchant forms adopt.
- **Responsible:** Phase 2.5 / later for breadth
- **Next action:** If other complex forms gain concurrent editing, reuse the ss_last_seen_at token rather than inventing a second mechanism.

### `P02-F9` — F — Reusable Forms and Record Panels

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Accessible error messages"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream F`
- **Expected behaviour:** Error messages reachable and announced to assistive technology.
- **Actual behaviour:** The validation summary is focusable and its focus move is driven in a browser; transient feedback is announced via ss_announce_transient()/announceLive; toast contrast and persistence pinned.
- **Implementation:** resources/views/components/ss/error-summary.blade.php; public/js/custom.js; public/js/store-syria-ds.js
- **Evidence:** tests/Unit/Ui/TransientFeedbackContractTest.php, ToastContrastTest.php, ToastErrorPersistenceContractTest.php; browser-checks-p02.cjs
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-G1` — G — Employee Preferences

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Saved table filters"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream G (audit: MISSING — P2-06-4 saves sort/length/columns, not filters)`
- **Expected behaviour:** A merchant's filter selection survives navigation and reload, per employee and per table.
- **Actual behaviour:** app/Services/TableFilterMemory.php (server-side) is wired into ProductController::index and OrderController::index with a filter-summary component. Its docblock records why the four non-migration alternatives were each ruled out by measurement — notably that `settings` cannot hold a per-employee value because SettingOwnership rewrites created_by to creatorId(), collapsing two employees onto one row.
- **Implementation:** app/Services/TableFilterMemory.php; ProductController::index; OrderController::index
- **Evidence:** tests/Feature/Merchant/TableFilterMemoryTest.php; resources/views/components/ss/filter-summary.blade.php
- **Gap:** Only the product and order lists have it.
- **Responsible:** Phase 02 (closed) for the two lists
- **Next action:** None.

### `P02-G2` — G — Employee Preferences

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Visible columns and column order where supported"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream G (audit: PARTIAL — visibility saved, ORDER was not)`
- **Owner decisions:** Owner decision 2026-10-27 (ledger P2-06-6): study and integrate a compatible open-source version in the dev environment, no licence purchase, no external CDN, keep the licence notice, prevent moving action columns into positions that break them.
- **Expected behaviour:** Both column visibility and column order persist where the table supports it, without a licence purchase or an external CDN.
- **Actual behaviour:** public/vendor/datatables/dataTables.colReorder.min.js is vendored locally with its LICENSE file kept alongside it; ColumnReorderContractTest pins the contract including keeping action columns out of positions that break them.
- **Implementation:** public/vendor/datatables/dataTables.colReorder.min.js; app/Helper/helper.php; public/js/store-syria-ds.js
- **Evidence:** tests/Unit/Ui/ColumnReorderContractTest.php; public/vendor/datatables/dataTables.colReorder.LICENSE.txt
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-G3` — G — Employee Preferences

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Page size and sorting preferences"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream G`
- **Expected behaviour:** Page length and sort order persist.
- **Actual behaviour:** stateSave applied by buildDataTable() across all 68 tables, with sanitizeTableState() guarding what is restored.
- **Implementation:** app/Helper/helper.php; public/js/store-syria-ds.js
- **Evidence:** tests/Feature/Merchant/TablePreferencesTest.php; docs/development/phase-2/tools/check-table-state.cjs
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-G4` — G — Employee Preferences

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Favorite pages"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream G (audit: MISSING; same gap as B8)`
- **Owner decisions:** Owner decision 2026-10-27; D-24d conditions on the migration.
- **Expected behaviour:** Favourites are permanent per-employee preferences restored after login and from other devices.
- **Actual behaviour:** See P02-B8 — MerchantFavouritePage model plus its own table, FavouritePages service, toggle state contract test.
- **Implementation:** app/Services/FavouritePages.php; app/Models/MerchantFavouritePage.php; migration 2026_10_27_000100
- **Evidence:** tests/Feature/Merchant/FavouritePagesTest.php; tests/Unit/Ui/FavouriteToggleStateContractTest.php
- **Gap:** Dev/test migration only — inert on production.
- **Responsible:** Owner — production migration authorisation
- **Next action:** As P02-B8.

### `P02-G5` — G — Employee Preferences

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Workspace UI preferences" (with "Preferences must be scoped correctly by user and store where appropriate.")
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream G`
- **Owner decisions:** None found scoping this to device-local.
- **Expected behaviour:** Workspace-level UI choices persist and are scoped to the employee where that matters.
- **Actual behaviour:** Measured here: the collapsed-rail choice is stored in localStorage by public/js/store-syria-nav.js (RAIL_KEY, inside try/catch). That is a per-browser slot, not a per-employee one — the project's own TableFilterMemory docblock argues exactly this limitation for a shared terminal. No server-side workspace-preference store exists.
- **Implementation:** public/js/store-syria-nav.js
- **Evidence:** public/js/store-syria-nav.js:29-37 (read here); browser-checks-p02.cjs
- **Gap:** Workspace UI preferences are device-local. Two employees sharing a terminal share the rail state; the preference does not follow an employee to another device, unlike favourites and filter memory.
- **Responsible:** Phase 2.5 or a later preferences slice
- **Next action:** Either state explicitly that workspace UI preferences are device-local by design, or move them to the per-employee store used by favourites.

### `P02-H1` — H — Unified Search and Notifications

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Unified search covering "Navigation"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream H`
- **Expected behaviour:** Navigation destinations are searchable, filtered by permission and module entitlement on the server.
- **Actual behaviour:** MerchantSearchController serves navigation results from the permission-filtered MerchantPageDirectory; the palette renders them.
- **Implementation:** app/Http/Controllers/MerchantSearchController.php; app/Services/MerchantPageDirectory.php; resources/views/partision/command-palette.blade.php
- **Evidence:** tests/Feature/Security/MerchantSearchAuthorizationTest.php (11 cases); screenshots/p2-06-search/
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-H2` — H — Unified Search and Notifications

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Unified search covering "Products"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream H`
- **Expected behaviour:** Product results filtered server-side by tenant and permission.
- **Actual behaviour:** Read here: MerchantSearchController puts an explicit where('store_id', $storeId) on each query (lines 219, 243, 316) precisely because Product and Order carry no effective global scope, and its header comments say so. Permission checks are operator-first ($isOperator \|\| $user->isAbleTo(...), line 180).
- **Implementation:** app/Http/Controllers/MerchantSearchController.php
- **Evidence:** app/Http/Controllers/MerchantSearchController.php (read here); tests/Feature/Security/MerchantSearchAuthorizationTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-H3` — H — Unified Search and Notifications

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Unified search covering "Orders"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream H`
- **Expected behaviour:** Order results filtered server-side by tenant and permission; a section the caller may not see is absent, not empty.
- **Actual behaviour:** Same controller and predicate; the authorization test asserts absence rather than emptiness for a forbidden section, plus the positive case once granted.
- **Implementation:** app/Http/Controllers/MerchantSearchController.php
- **Evidence:** tests/Feature/Security/MerchantSearchAuthorizationTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-H4` — H — Unified Search and Notifications

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Unified search covering "Customers"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream H`
- **Expected behaviour:** Customer results scoped to the tenant even though Customer already carries a global scope.
- **Actual behaviour:** The controller adds the explicit store predicate to Customer as well, not relying on the scope (the codebase's null_tenant => 'open' rule makes scope-only isolation unsafe).
- **Implementation:** app/Http/Controllers/MerchantSearchController.php
- **Evidence:** tests/Feature/Security/MerchantSearchAuthorizationTest.php; app/Http/Controllers/MerchantSearchController.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-H5` — H — Unified Search and Notifications

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Unified search covering "Supported quick actions"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream H (audit: MISSING on 2026-10-25)`
- **Owner decisions:** D-24a bounded the action set.
- **Expected behaviour:** A search result offers the actions the principal may actually perform on it.
- **Actual behaviour:** cmdActions() in store-syria-ds.js plus the per-result action bar; SearchResultActionsTest pins which actions appear for which principal, with nothing destructive in the palette.
- **Implementation:** app/Http/Controllers/MerchantSearchController.php; public/js/store-syria-ds.js; resources/views/partision/command-palette.blade.php
- **Evidence:** tests/Feature/Merchant/SearchResultActionsTest.php; tests/Feature/Security/MerchantQuickActionTargetsTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-H6` — H — Unified Search and Notifications

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Temporary action feedback" (separated from persistent notifications)
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream H (audit: MISSING on 2026-10-25)`
- **Expected behaviour:** A consistent transient feedback surface, distinct from the notification inbox, announced to assistive technology, preserving show_toastr.
- **Actual behaviour:** ss_announce_transient() is called from show_toastr in public/js/custom.js (preserving the existing API); announceLive in store-syria-ds.js; TransientFeedbackContractTest pins the separation.
- **Implementation:** public/js/custom.js; public/js/store-syria-ds.js
- **Evidence:** tests/Unit/Ui/TransientFeedbackContractTest.php, ToastErrorPersistenceContractTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-H7` — H — Unified Search and Notifications

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Persistent operational notifications"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream H`
- **Owner decisions:** None taken on the count semantics; it is an open public-response change reserved to the owner.
- **Expected behaviour:** A persistent notification inbox reusing the existing notification data without breaking the Flutter client.
- **Actual behaviour:** MerchantNotificationController plus notification/index.blade.php and the header bell read the same store_notifications rows the Flutter app already reads through GET api/admin/notification, with no schema change and no change to that handler. The ledger's headline: the rows already existed and the web could not see them.
- **Implementation:** app/Http/Controllers/MerchantNotificationController.php; resources/views/notification/index.blade.php; resources/views/partision/header.blade.php
- **Evidence:** docs/development/phase-2/tools/shoot-notifications-live.cjs; screenshots/p2-06-notifications/ (24)
- **Gap:** A recorded, unfixed asymmetry: the API's `count` is a TOTAL and the app derives its badge by diffing totals locally (NotificationController._calculateUnreadCount), never reading is_read. So clearing notifications on the web does NOT clear the app's badge. Correcting `count` to mean 'unread' would break a shipped build's arithmetic — recorded as a finding and an owner decision, not done.
- **Responsible:** Owner — API response-shape decision
- **Next action:** Carry the unread-count asymmetry forward as an explicit open item; it is a cross-client correctness gap, not merely cosmetic.

### `P02-I1` — I — Onboarding and Contextual Help

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Merchant onboarding guidance"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream I`
- **Owner decisions:** Owner continuation instruction C1.3 required diagnosing the RTL defect in the real application before enabling it — which is what happened.
- **Expected behaviour:** Lightweight onboarding that does not block experienced merchants.
- **Actual behaviour:** Verified here that the checklist is really rendered: resources/views/dashboard/overview.blade.php:216 contains <x-ss.setup-checklist :steps="$ssSetupSteps" />, i.e. the component is no longer the commented-out block the ledger's blocker column describes. The RTL double-mark anomaly that held it was settled at runtime per the handoff; 16 screenshots exist in 4 combinations plus mobile.
- **Implementation:** resources/views/components/ss/setup-checklist.blade.php; resources/views/dashboard/overview.blade.php
- **Evidence:** docs/development/phase-2/screenshots/p2-06-onboarding/ (16 images, ar/en x light/dark x desktop/mobile); resources/views/dashboard/overview.blade.php:216 (read here)
- **Gap:** Ledger row P2-06-1 still carries its old blocker text ('Not rendered on the dashboard until then'), which is now false.
- **Responsible:** Phase 02 (closed)
- **Next action:** Clear the stale blocker text on ledger row P2-06-1.

### `P02-I2` — I — Onboarding and Contextual Help

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Contextual field help"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream I (audit: MISSING on 2026-10-25; same gap as D9)`
- **Owner decisions:** None found.
- **Expected behaviour:** Help attached to the field via aria-describedby, in both languages, across the merchant's forms.
- **Actual behaviour:** Same measurement as P02-D9: three forms only (product create, product edit, customer create).
- **Implementation:** resources/views/components/ss/field-help.blade.php
- **Evidence:** tests/Feature/Merchant/ContextualFieldHelpTest.php; grep of resources/views for x-ss.field-help (3 consumers)
- **Gap:** The ledger row P2-06-10 says it: 'DONE on the product editor; the other screens remain.' No owner decision scopes it there.
- **Responsible:** Phase 2.5 or a later merchant-experience slice
- **Next action:** Decide explicitly: extend to the remaining merchant forms, or record an owner-approved scope limit.

### `P02-I3` — I — Onboarding and Contextual Help

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Helpful empty states"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream I (audit: MISSING on 2026-10-25)`
- **Expected behaviour:** Every list and panel has an empty state saying what to do next, not merely that there is nothing.
- **Actual behaviour:** dataTableEmptyState() was added to app/Helper/helper.php and wired into buildDataTable(), so the empty state reaches every table from the one shared helper; +13 language keys.
- **Implementation:** app/Helper/helper.php; resources/views/components/ss/empty.blade.php; resources/lang/{ar,en}.json
- **Evidence:** tests/Feature/Merchant/EmptyStateTest.php (7); tests/Unit/Ui/DataTableEmptyStateTest.php, TableEmptyStatePresentationTest.php, CustomerTimelineEmptyStateTest.php, EmailTemplateEmptyStateTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-I4` — I — Onboarding and Contextual Help

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Short task-oriented instructions"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream I (audit: MISSING on 2026-10-25)`
- **Owner decisions:** None found.
- **Expected behaviour:** Short instructions wherever a merchant must choose.
- **Actual behaviour:** Measured here: resources/views/components/ss/choice-group.blade.php is used only by the product screens (create, edit, attribute_combinations, attribute_combinations_data, attribute_combinations_edit). Ledger P2-06-12 says 'DONE on the product editor's two choice groups; other screens' choice groups remain.'
- **Implementation:** resources/views/components/ss/choice-group.blade.php
- **Evidence:** tests/Feature/Merchant/ChoiceGroupGuidanceTest.php; grep of resources/views for x-ss.choice-group
- **Gap:** Choice groups on orders, settings, shipping, staff and the rest carry no task-oriented instruction.
- **Responsible:** Phase 2.5 or a later slice
- **Next action:** Extend or obtain an explicit scope decision; do not let the ledger's DONE-with-a-caveat read as complete.

### `P02-I5` — I — Onboarding and Contextual Help

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Accessible UI guidance"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream I (audit: MISSING on 2026-10-25)`
- **Owner decisions:** None found.
- **Expected behaviour:** Guidance reachable by assistive technology wherever it is offered.
- **Actual behaviour:** Where guidance exists it is accessible (aria-describedby on field help, choice-group semantics, live-region announcements). Its COVERAGE is the same partial set as I2/I4.
- **Implementation:** resources/views/components/ss/{field-help,choice-group,help}.blade.php
- **Evidence:** tests/Feature/Merchant/ChoiceGroupGuidanceTest.php, ContextualFieldHelpTest.php; tests/Unit/Ui/TransientFeedbackContractTest.php
- **Gap:** Tied to I2/I4: accessible guidance exists only on the surfaces that have guidance at all.
- **Responsible:** Phase 2.5 or a later slice
- **Next action:** Resolve with I2 and I4 as one coverage decision.

### `P02-I6` — I — Onboarding and Contextual Help

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Arabic and English translations"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream I`
- **Owner decisions:** D-11 (Arabic validation and UI translations implemented under existing authority).
- **Expected behaviour:** Every new string ships in both languages at ship time, with correct Arabic sense.
- **Actual behaviour:** Arabic added alongside every new string per slice (e.g. +22 keys for the dashboard, 0 changed, 0 removed; +13 for empty states); resources/lang/ar/{validation,auth,passwords,pagination}.php added; wrong-sense Arabic corrected in 8 entries (P2-01-12). Coverage tests exist (ArabicUiCoverageTest, ArabicValidationMessagesTest, ArabicActionLabelRegisterTest).
- **Implementation:** resources/lang/{ar,en}.json; resources/lang/ar/*.php
- **Evidence:** tests/Unit/Merchant/ArabicUiCoverageTest.php, ArabicValidationMessagesTest.php; tests/Unit/Ui/ArabicActionLabelRegisterTest.php; screenshots in ar for every slice
- **Gap:** P2-01-12 carries a standing owner blocker: 'needs a native reviewer before release.' That has not been recorded as done anywhere I found.
- **Responsible:** Owner — native-speaker review before release
- **Next action:** Schedule the native Arabic review; it is a release gate that is still open.

### `P02-J1` — J — Other Merchant Screens

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Apply the established components to "Customers"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream J`
- **Expected behaviour:** The customers list and its row actions use ss-* components, every control has an accessible name, AA in four configurations, no functionality removed.
- **Actual behaviour:** customer views migrated (P2-05-2) with a family of customer tests covering grid filters, numeric operators, create form, order status chips and the timeline.
- **Implementation:** resources/views/customer/*.blade.php; app/DataTables/CustomerDataTable.php
- **Evidence:** tests/Feature/Merchant/CustomerGridFilterTest.php, CustomerListFilterApplyTest.php, CustomerNumericFilterOperatorsTest.php, CustomerCreateFormTest.php; tests/Unit/Ui/CustomerTimeline*Test.php (3)
- **Gap:** No screenshot evidence folder for P2-05-2 — the coverage audit names it as one of seven slices with logs but no before/after images.
- **Responsible:** Phase 02 (closed)
- **Next action:** Capture the four-configuration screenshots to satisfy Mandatory Visual Evidence.

### `P02-J2` — J — Other Merchant Screens

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Apply the established components to "Inventory views"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream J`
- **Expected behaviour:** Stock figures carry their rule, AA in four configurations, nothing removed.
- **Actual behaviour:** P2-05-3 migrated the stock views and found four defects on that one screen, three recorded as F106 (status column empty on two of three tabs and reading the wrong setting; row actions). Evidence folder with PNGs exists.
- **Implementation:** resources/views/stock*/; app/DataTables/*
- **Evidence:** docs/development/phase-2/evidence/p2-05-3/; tests/Feature/Merchant/StockReportPredicateTest.php, ProductStockIndicatorTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-J3` — J — Other Merchant Screens

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Apply the established components to "Categories and attributes"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream J`
- **Expected behaviour:** Category, attribute and attribute-option screens use ss-*; the attribute_option pilot stays the reference; AA in four configurations.
- **Actual behaviour:** category/*, attributes/*, attribute_option/* migrated (P2-05-4) with an evidence folder of PNGs.
- **Implementation:** resources/views/{category,attributes,attribute_option}/*.blade.php
- **Evidence:** docs/development/phase-2/evidence/p2-05-4/; tests/Feature/Merchant/CategoryAttributeRowActionsTest.php; tests/Unit/Ui/AttributeOptionOptionalIdTest.php, CatalogueFormLabelAssociationTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-J4` — J — Other Merchant Screens

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Apply the established components to "Staff and permissions"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream J`
- **Expected behaviour:** Users and roles screens use ss-*; the permission names rendered are ones that actually exist.
- **Actual behaviour:** users/* and roles/* migrated (P2-05-5) with PNG evidence. The permission-name trap this requirement names is itself pinned by DashboardPermissionNamesTest, which exists because isAbleTo('Manage Product') — singular — exists in no seeder and no live permissions table and had hidden surfaces from every principal.
- **Implementation:** resources/views/users/*.blade.php; resources/views/roles/*.blade.php; app/DataTables/UserDataTable.php
- **Evidence:** docs/development/phase-2/evidence/p2-05-5/; tests/Feature/Merchant/StaffAndRoleRowActionsTest.php; tests/Unit/Dashboard/DashboardPermissionNamesTest.php; tests/Unit/Ui/RolePermissionTableUsesDesignSystemTest.php, StaffCardAccessibleNamesTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-J5` — J — Other Merchant Screens

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Apply the established components to "Reports already supported by the backend"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream J (audit: MISSING — named in WS J and present in no row before the audit)`
- **Expected behaviour:** Report screens reachable, gated on Manage Reports / Manage Order Reports / Manage Stock Reports, on the design system, every figure carrying its period and qualification.
- **Actual behaviour:** P2-05-6 migrated resources/views/reports/ with a PNG evidence folder; period labelling and export controls are pinned by tests.
- **Implementation:** resources/views/reports/*.blade.php; app/Http/Controllers/ReportController.php
- **Evidence:** docs/development/phase-2/evidence/p2-05-6/ (PNGs in four configurations); tests/Unit/Ui/ReportPeriodLabelTest.php, ReportExportControlContractTest.php, ReportsFamilyPhoneLayoutTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-J6` — J — Other Merchant Screens

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Apply the established components to "Store settings"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream J`
- **Owner decisions:** The three findings this slice raised (F141/F142/F143) were each fixed on an owner decision of 2026-10-27, not left open.
- **Expected behaviour:** The ~20 setting panels use ss-*; no secret or credential is rendered into the page; AA in four configurations.
- **Actual behaviour:** P2-05-7 migrated the settings panels with a README and PNGs per panel in four configurations; credential exposure is pinned by SettingsCredentialExposureTest and the secret-field component.
- **Implementation:** resources/views/setting/*.blade.php; resources/views/components/ss/secret-field.blade.php
- **Evidence:** docs/development/phase-2/evidence/p2-05-7/ (README + per-panel PNGs); tests/Unit/Ui/SettingsCredentialExposureTest.php, SettingsPanelContractTest.php, SettingsPanelFreshStoreRenderTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-J7` — J — Other Merchant Screens

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Apply the established components to "Integrations already supported by the backend"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream J (audit: MISSING — named in WS J, absent from every row before the audit)`
- **Owner decisions:** F141/F142/F143 each fixed on an explicit owner decision (2026-10-27), F142 'gradually and with the impact measured'.
- **Expected behaviour:** Payment, Shopify, pixel, reCAPTCHA and ChatGPT panels are on the design system, keep their contracts, and render no credential.
- **Actual behaviour:** P2-05-8 migrated them. Three real defects were found and fixed here on owner decisions: F141 (nothing could CLEAR a stored gateway secret — a credential could be rotated but never removed, including a leaked one), F142 (the payment screen's required-field validation had never run), F143 (paiementpro_mode written empty on every save).
- **Implementation:** resources/views/setting/*.blade.php; app/Http/Controllers/SettingController.php (processGateway, payment.secret.clear)
- **Evidence:** tests/Unit/Ui/PaymentGatewayFieldContractTest.php, SettingsCredentialExposureTest.php; tests/Feature/Merchant/ContentAndIntegrationRefusalNamesEveryFieldTest.php; tests/Feature/Merchant/PaymentGatewayRequiredFieldsTest (the renamed class the ledger records)
- **Gap:** No screenshot evidence folder for P2-05-8 — one of the seven slices with logs only.
- **Responsible:** Phase 02 (closed)
- **Next action:** Capture the four-configuration screenshots for the integration panels.

### `P02-K1` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Feature parity matrix comparing "Merchant web functionality"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K`
- **Expected behaviour:** The web merchant panel's functionality is enumerated as one axis of a real parity matrix.
- **Actual behaviour:** PHASE-02-WEB-FLUTTER-PARITY.md §3 lists ~22 functional areas with a per-area verdict (parity / web-only / gap) and names why each web-only area is correct (theme builder → Phase 03, reports → Phase 05).
- **Implementation:** documentation deliverable
- **Evidence:** docs/development/phase-2/PHASE-02-WEB-FLUTTER-PARITY.md §3 (read here)
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-K10` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Modernize priority: "Quick daily operations"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: MISSING on 2026-10-25)`
- **Owner decisions:** D-23 bounds the API permission contract.
- **Expected behaviour:** The operations a merchant performs daily are reachable in few steps, within what the existing API already supports.
- **Actual behaviour:** Api\DashboardController::updateStatus plus an authorisation test on the server (7 cases) and the app's own flow; scoped to the existing API, with no new endpoint lacking authorization tests.
- **Implementation:** app/Http/Controllers/Api/DashboardController.php; ../StoreSyria_app98/lib/core/controller/order_controller/order_controller.dart
- **Evidence:** tests/Feature/Security/AdminApiOrderWritePermissionTest.php (7); ../StoreSyria_app98/test/contract/permission_gated_endpoints_test.dart
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-K11` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Modernize priority: "Search and filters"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: MISSING on 2026-10-25)`
- **Expected behaviour:** Search and filters matching the web's, permission-aware.
- **Actual behaviour:** lib/view/new_widgets/filter_tabs.dart with test/ui/order_filter_status_coverage_test.dart, matched server-side by ApiOrderFilterStatusCoverageTest so the app's filter vocabulary cannot drift from the nine delivered_status values.
- **Implementation:** ../StoreSyria_app98/lib/view/new_widgets/filter_tabs.dart; app/Http/Controllers/Api/DashboardController.php
- **Evidence:** ../StoreSyria_app98/test/ui/order_filter_status_coverage_test.dart; tests/Feature/Merchant/ApiOrderFilterStatusCoverageTest.php
- **Gap:** The app has a /searchdata endpoint the WEB panel did not use — recorded in the matrix as a gap on the web side, now closed by P02-H1..H4's own search.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-K12` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Modernize priority: "Notifications"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: PARTIAL)`
- **Owner decisions:** None on the count semantics — reserved to the owner as a public-response change.
- **Expected behaviour:** Notifications work on the app and agree with the web.
- **Actual behaviour:** The app already had FCM push and a notification screen; the web was brought onto the same store_notifications rows with no change to the API handler. Parity is recorded as 'parity on the inbox; Flutter still ahead on PUSH'.
- **Implementation:** ../StoreSyria_app98 notification controller; app/Http/Controllers/MerchantNotificationController.php
- **Evidence:** docs/development/phase-2/PHASE-02-WEB-FLUTTER-PARITY.md §3 and §7; ../StoreSyria_app98/lib/core/controller/notification_controller/notification_controller.dart
- **Gap:** The unread-count asymmetry in P02-H7: clearing on the web does not clear the app's badge, because the app diffs a TOTAL and never reads is_read.
- **Responsible:** Owner — API response-shape decision
- **Next action:** Same as P02-H7.

### `P02-K13` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Modernize priority: "Employee permissions"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: MISSING on 2026-10-25; security-relevant — the app trusted what the API returned)`
- **Owner decisions:** D-23 governs the API contract; no owner decision covers the absent staff-admin screen.
- **Expected behaviour:** The app hides and refuses what the employee may not do, as the web does.
- **Actual behaviour:** app/Services/MerchantPermissions.php plus the client-side merchant_permissions.dart; the app's security test folder holds 17 files including denial_not_empty_success_test.dart and refusal_reads_as_refusal_test.dart — i.e. a refusal must read as a refusal, not as an empty success.
- **Implementation:** app/Services/MerchantPermissions.php; ../StoreSyria_app98/lib/core/permissions/merchant_permissions.dart
- **Evidence:** ../StoreSyria_app98/test/security/ (17 files, census taken here); tests/Feature/Security/AdminApiPermissionExposureTest.php
- **Gap:** Staff/permissions ADMINISTRATION remains web-only (parity G4, 'gap, deliberate for now'). That is an engineering judgement recorded in the parity doc, not an owner decision.
- **Responsible:** Later slice
- **Next action:** Record G4's web-only staff administration as an explicitly open parity decision rather than a settled one.

### `P02-K14` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Modernize priority: "Arabic/English support"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: PARTIAL)`
- **Expected behaviour:** Full AR/EN coverage in the app with no key present in one language and missing from the other.
- **Actual behaviour:** lib/translations/{ar,en}.dart; the parity doc records 'no key present in en and missing from ar' and that P2-07-6 added 13 keys to each (statuses 7 and 8, an unrecognised-status label, nine status descriptions). test/translations/ holds coverage tests.
- **Implementation:** ../StoreSyria_app98/lib/translations/{ar,en}.dart
- **Evidence:** ../StoreSyria_app98/test/translations/ (2 files); PHASE-02-WEB-FLUTTER-PARITY.md §4
- **Gap:** Same standing caveat as P02-I6: no native Arabic reviewer has signed off before release.
- **Responsible:** Owner — native-speaker review before release
- **Next action:** Include the app's Arabic strings in the native review.

### `P02-K15` — K — Flutter Merchant Experience

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Modernize priority: "RTL/LTR"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K`
- **Owner decisions:** None — this is an environment blocker, not a decision.
- **Expected behaviour:** The app mirrors correctly in Arabic, verified — and per §6, never claimed as visually tested if only inspected statically.
- **Actual behaviour:** The P0 was real and is fixed: flutter_localizations was declared and never wired, so the ambient Directionality was LTR while Arabic was both default and fallback (G1). test/localization/rtl_mirroring_test.dart asserts PAINTED geometry is a mirror (start inset equal, physical sides swapped), asserts the OLD shape does not mirror, includes a 'did it move' case so a centred layout cannot pass by symmetry, and ratchets four files. test/rtl/directional_positioning_test.dart adds five more cases, run in CI.
- **Implementation:** ../StoreSyria_app98/lib/store_go.dart; lib/translations/translation_service.dart; 4 widget files
- **Evidence:** ../StoreSyria_app98/test/localization/rtl_mirroring_test.dart, test/rtl/ (2 files); docs/development/phase-2/evidence/flutter-ci/run-41118104452.txt (GitHub Actions run, 'flutter test' success)
- **Gap:** LAYOUT is verified; PIXELS are not. No screen of this app has ever been rendered and seen, by anyone or anything in these containers. The ledger's own status is 'DONE for LAYOUT, BLOCKED for PIXELS'.
- **Responsible:** EXTERNAL — no device, no emulator; `flutter build web --release` fails inside firebase_messaging_web 3.5.18 under Dart 3.13.7, with zero files under lib/ in the failure (blocker B4)
- **Next action:** Obtain a device, emulator or a working web build before claiming visual verification of any Flutter screen.

### `P02-K16` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Modernize priority: "Loading, empty and error states"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: MISSING on 2026-10-25)`
- **Expected behaviour:** Each screen has all three and none of them is a blank page.
- **Actual behaviour:** test/ui/screen_state_census_test.dart is a census over the app's screens; lib/view/widget/error_empty_state.dart is imported by 15 files and uses SliverFillRemaining so pull-to-refresh still works on an empty list; shimmer.dart (668 lines, theme- and RTL-aware) is imported by 20 files.
- **Implementation:** ../StoreSyria_app98/lib/view/widget/error_empty_state.dart; lib/view/new_widgets/shimmer.dart; home/statistic controllers
- **Evidence:** ../StoreSyria_app98/test/ui/screen_state_census_test.dart; lib/view/widget/error_empty_state.dart (present); PHASE-02-WEB-FLUTTER-PARITY.md §4
- **Gap:** Verified by widget tests and source census, not by looking at a running app (P02-K15).
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-K17` — K — Flutter Merchant Experience

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Modernize priority: "Secure authentication behavior" (with "the existing Flutter app may clear its session on HTTP 401; do not introduce incompatible authorization response behavior")
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: MISSING on 2026-10-25)`
- **Owner decisions:** Decision Register row 13: 'APPROVED — staged: observability → explicit abilities → 30-day expiry for NEW merchant tokens only; customers and drivers out of scope; no Sanctum global expiration; no mass invalidation.' Stages 2 and 3 are authorised and NOT built; stage 4 (idle expiry) is not in the decision and stage 5 is out of scope by it.
- **Expected behaviour:** Token handling, logout and refusal behaviour are correct and do not log the merchant out on an unrelated 401.
- **Actual behaviour:** Two real improvements landed. (1) adminlogin now honours is_active and is_enable_login — it checked neither, so an employee the merchant had disabled in the web UI could still sign in to the app with a never-expiring token; mirrored from AuthenticatedSessionController including the super-admin exemption, and the refusal keeps error()'s HTTP 200 + status:0 so no status code changed on a path every client calls. (2) The 401-vs-403 boundary is pinned from both sides: the client test asserts the 403 branch does NOT call onUnauthorized() and that 401 still does; the server test asserts no gated route answers 401. Stage 1 of the token plan shipped as a read-only `tokens:inventory` command.
- **Implementation:** app/Http/Controllers/Api/DashboardController.php (login); app/Console/Commands/TokenInventoryReport.php
- **Evidence:** tests/Feature/Security/AdminApiPermissionExposureTest.php (4 account-state tests); ../StoreSyria_app98/test/contract/permission_gated_endpoints_test.dart (4); app/Console/Commands/TokenInventoryReport.php
- **Gap:** Token lifetime and the absent refresh path are unchanged: Sanctum tokens never expire, every one carries ['*'], and there is no refresh for any client. Only stage 1 (observability) of the owner-approved three-stage plan is built. Api\AuthController::login remains unrouted, so customers still have no login endpoint.
- **Responsible:** Next slice — stages 2 and 3 are already owner-approved
- **Next action:** Build stage 2 (explicit abilities) and stage 3 (30-day expiry for new merchant tokens), choosing the figure against the production data the inventory command was written to measure.

### `P02-K2` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Feature parity matrix comparing "Existing Flutter functionality"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K`
- **Expected behaviour:** What the app actually does today, measured from its source.
- **Actual behaviour:** The matrix identifies it as a merchant/store-owner admin console with five bottom-nav tabs read from dashboard_manager_controller.dart:15-19, and states it must be benchmarked against the merchant web panel, not a storefront.
- **Implementation:** documentation deliverable
- **Evidence:** docs/development/phase-2/PHASE-02-WEB-FLUTTER-PARITY.md §1
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-K3` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Feature parity matrix comparing "Shared APIs"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K`
- **Expected behaviour:** The shared API surface enumerated from the app's own source.
- **Actual behaviour:** 91 distinct endpoint paths read from lib/utils/base_api.dart, all under Route::prefix('admin') at routes/api.php:133-387; the storefront API is called by no client in either repository.
- **Implementation:** documentation deliverable
- **Evidence:** docs/development/phase-2/PHASE-02-WEB-FLUTTER-PARITY.md §2
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-K4` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Feature parity matrix comparing "Permission behavior"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K`
- **Owner decisions:** D-23 (owner-authorised 2026-10-28) governs the admin API's permission contract.
- **Expected behaviour:** How permissions behave on each surface, compared.
- **Actual behaviour:** Covered in the matrix and implemented under P2-07-13/D-23: app/Services/MerchantPermissions.php server-side and lib/core/permissions/merchant_permissions.dart client-side, with security tests on both sides.
- **Implementation:** app/Services/MerchantPermissions.php; app/Http/Controllers/Api/DashboardController.php; ../StoreSyria_app98/lib/core/permissions/merchant_permissions.dart
- **Evidence:** ../StoreSyria_app98/test/security/merchant_permissions_test.dart, permission_gated_ui_test.dart, denial_not_empty_success_test.dart, refusal_reads_as_refusal_test.dart; tests/Feature/Security/AdminApiPermissionExposureTest.php
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-K5` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Feature parity matrix comparing "Missing mobile workflows"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K`
- **Expected behaviour:** Gaps named as gaps, with severity and state.
- **Actual behaviour:** §5 lists G1–G10 with severity and state, including the ones still open (G4 staff admin, G6 flat product editor, G8 order_source not displayed, G9 note author not displayed).
- **Implementation:** documentation deliverable
- **Evidence:** docs/development/phase-2/PHASE-02-WEB-FLUTTER-PARITY.md §5 (read here)
- **Responsible:** Phase 02 (closed)
- **Next action:** Carry open G-items into the next phase's register rather than closing them with Phase 02.

### `P02-K6` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Feature parity matrix comparing "Features intentionally retained on web"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K`
- **Expected behaviour:** Deliberate web-only features distinguished from gaps.
- **Actual behaviour:** The matrix marks theme builder / CMS / blog and reports/analytics as 'web-only, correct' with their phase assignment, and §4 lists five places where the APP is better than the web and must not be 'upgraded' away (shimmer, empty/error states, pull-to-refresh, translation parity, push).
- **Implementation:** documentation deliverable
- **Evidence:** docs/development/phase-2/PHASE-02-WEB-FLUTTER-PARITY.md §3–§4
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-K7` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Modernize priority: "Dashboard usability"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: MISSING on 2026-10-25; parity gap G3)`
- **Expected behaviour:** The app dashboard carries the same honesty the web one does: every figure its period, money gated on permission.
- **Actual behaviour:** Implemented on both halves: Laravel ApiDashboardFigurePeriodTest on the server, and test/ui/dashboard_figure_honesty_test.dart in the app.
- **Implementation:** ../StoreSyria_app98/lib/view/screen/home_screen/*; app/Http/Controllers/Api/DashboardController.php
- **Evidence:** ../StoreSyria_app98/test/ui/dashboard_figure_honesty_test.dart; tests/Feature/Merchant/ApiDashboardFigurePeriodTest.php
- **Gap:** Never seen rendering — see P02-K15.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-K8` — K — Flutter Merchant Experience

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Modernize priority: "Orders and order details"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: PARTIAL)`
- **Owner decisions:** D-04 (owner approval for order status display and translation, explicitly NOT for the status rules).
- **Expected behaviour:** The app's order list and detail match the web's honesty and usefulness.
- **Actual behaviour:** The status honesty pass landed (P2-07-6): statuses 7 and 8 no longer render 'unknown', nine Arabic-literal descriptions translated, the 'Cancel' button word replaced by the order STATE, three badge colour pairs raised above AA (one was at 1.85:1). The server half of that defect (F29 — order_status_text defaulting to Pending for statuses 7 and 8, plus a 'Comfirmed' misspelling no translation can match) was fixed on written owner approval.
- **Implementation:** ../StoreSyria_app98/lib/view/new_widgets/order_status_badge.dart; lib/view/screen/order_screen/order_screen.dart
- **Evidence:** ../StoreSyria_app98/test/widgets/order_status_label_test.dart; tests/Feature/Merchant/OrderStatusLabelTest.php; PHASE-02-WEB-FLUTTER-PARITY.md §5 G8/G9
- **Gap:** Two measured parity gaps remain open: G8 — the app RECEIVES orders.order_source but does not display which path created an order, which the web does; G9 — the app does not name who wrote each order note, which the web does.
- **Responsible:** Next merchant-experience slice / Phase 2.5
- **Next action:** Track G8 and G9 as open app-side display gaps.

### `P02-K9` — K — Flutter Merchant Experience

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Modernize priority: "Products and inventory visibility"
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §5 Workstream K (audit: MISSING on 2026-10-25)`
- **Expected behaviour:** The merchant can see product and stock state in the app, matching what the web shows.
- **Actual behaviour:** lib/core/stock/stock_state.dart plus test/ui/stock_state_parity_test.dart in the app, and ApiProductListStockParityTest on the server, close parity gap G7 (the two surfaces disagreed about 'out of stock' and the app was the wrong one: the web reads products.stock_status only when track_stock == 0).
- **Implementation:** ../StoreSyria_app98/lib/core/stock/stock_state.dart; app/Http/Controllers/Api/DashboardController.php
- **Evidence:** ../StoreSyria_app98/lib/core/stock/stock_state.dart, test/ui/stock_state_parity_test.dart; tests/Feature/Merchant/ApiProductListStockParityTest.php
- **Gap:** G6 remains: the app's add_new_product_screen.dart (1111 lines) is still one flat run while the web editor was organised into basic/advanced (P02-D6).
- **Responsible:** Later slice for G6
- **Next action:** Carry G6 forward as an open app-side organisation gap.

### `P02-XC-01` — Cross-cutting — §6 Testing and Quality Assurance

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Compare actual frontend performance against a measured baseline."
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §6`
- **Owner decisions:** None.
- **Expected behaviour:** A measured baseline AND a comparison of the phase's frontend performance against it.
- **Actual behaviour:** An artefact now exists — docs/development/phase-2/tools/measure-performance-baseline.cjs with evidence/performance-baseline/{README.md,baseline.json}, measured 2026-11-01 at commit cecc522f over six merchant screens in both directions, median of 3 after a discarded warm-up. But it is a POST-HOC single run: no pre-Phase-02 measurement exists, so nothing is compared to anything. Worse, the two committed artefacts disagree: the README's table reports dashboard LTR TTFB 263 ms / LCP 796 ms where baseline.json records 823 / 4224, and dashboard RTL CLS 0.0838 where baseline.json records 0.1182 — which breaks the README's own conclusion that the dashboard is 'still inside the 0.1 good threshold'.
- **Implementation:** docs/development/phase-2/tools/measure-performance-baseline.cjs
- **Evidence:** docs/development/phase-2/evidence/performance-baseline/README.md and baseline.json (both read and compared here)
- **Gap:** The COMPARISON half of the requirement is not met, and the baseline's own README contradicts its data file.
- **Responsible:** Phase 2.5 / whoever next touches merchant performance
- **Next action:** Re-run the harness, regenerate README.md from baseline.json rather than by hand, and state plainly that the first comparison can only happen at the next run.

### `P02-XC-02` — Cross-cutting — Mandatory Visual Evidence

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "For every significant UI change, capture before-and-after screenshots… desktop and mobile layouts, Arabic RTL and English LTR, and light and dark modes… Do not mark UI work as visually verified without actually rendering and testing it in a browser… If browser testing is unavailable, report the limitation explicitly."
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md, 'Mandatory Visual Evidence — Phase 02'`
- **Owner decisions:** None deferring this.
- **Expected behaviour:** Before/after images in four configurations plus mobile for every significant UI change, under docs/development/phase-2/screenshots/.
- **Actual behaviour:** Counted here: 16 screenshot folders holding 421 images, plus per-panel PNGs under evidence/p2-05-3..7. Seven slices with significant UI change carry only text measurement logs: P2-01-14 (breadcrumbs), P2-01-15 (loading/success), P2-01-18 (quick actions), P2-01-20 (keyboard), P2-05-2 (customers), P2-05-8 (integrations), P2-05-9 (30 row-action partials). No Flutter screen has ever been rendered.
- **Implementation:** docs/development/phase-2/tools/*.cjs harnesses
- **Evidence:** docs/development/phase-2/screenshots/* (directory census taken here); docs/development/phase-2/evidence/p2-01-14,-15,-18,-20 (logs only)
- **Gap:** Seven web slices lack before/after images; the entire Flutter workstream lacks any image.
- **Responsible:** Phase 2.5 for the web gap; EXTERNAL blocker B4 for Flutter
- **Next action:** Capture the seven missing web slices; keep the Flutter limitation reported explicitly rather than implied.

### `P02-XC-03` — Cross-cutting — §3 Mandatory Discovery of Existing Work

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Create a concise reuse inventory… Classify existing work as: Reuse unchanged / Extend / Adapt / Superseded / Not applicable… Do not rebuild components that already satisfy the approved requirements."
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §3`
- **Expected behaviour:** A reuse inventory produced before building, with branch forensics against p01b-design-foundation.
- **Actual behaviour:** docs/development/phase-2/PHASE-02-REUSE-INVENTORY.md exists (172 lines) and slice P2-00 carries five rows: branch forensics (git diff --stat recorded), a check that no Phase 02 work existed on any branch (git log --all --grep, per-branch ls-tree), the component inventory, a real Flutter SDK measurement, and a design pass written before implementation.
- **Implementation:** documentation deliverable
- **Evidence:** docs/development/phase-2/PHASE-02-REUSE-INVENTORY.md; docs/development/phase-2/design/P2-DESIGN-FOUNDATION.md; ledger rows P2-00-1..5
- **Gap:** I did not re-read the inventory's classifications in this pass; its existence and the P2-00 rows were confirmed.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-XC-04` — Cross-cutting — §10 Completion Criteria

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** "No security-sensitive data has been exposed." (and §4: "The publicly exposed production database dump incident, P1-SEC-40 / issue #120, remains an unresolved security issue… Do not… claim that Phase 02 UI work resolves the incident.")
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §4 and §10`
- **Owner decisions:** D-35 (owner, 2026-11-02): issue #120 is OWNER-DEFERRED and remains OPEN, with seven constraints including that the dump must not be deleted and must not be reintroduced; development-only main integration may proceed. D-29.7's closing note repeats that it 'must not be described as closed'.
- **Expected behaviour:** At completion, no security-sensitive data is exposed.
- **Actual behaviour:** NOT SATISFIED, and correctly reported as such throughout. The production dump is still in main's tree: the handoff records HEAD:storesyria_ecommercego.sql as d3d42e68…, 20,470,785 bytes, byte-identical to origin/main's, and PR #122 (which would remove it) is deliberately NOT merged. Phase 02 added no .sql/.dump/.pem/.key/.env file. P1-SEC-43 (a forged Zid webhook creating a real parcel at another merchant's account with an attacker-chosen COD amount) is OPEN, S1 and deployment-blocking.
- **Implementation:** n/a
- **Evidence:** docs/development/phase-2/PHASE-02-HANDOFF.md §-1 (dump hash and size, PR #122 unmerged); PHASE-02-CLOSURE-RECORD.md §7
- **Gap:** The completion criterion itself is not met. Deferral is of the REMEDIATION, not of the exposure.
- **Responsible:** Owner — security incident #120 and P1-SEC-43
- **Next action:** Keep #120 and P1-SEC-43 visible as release blockers in every later phase's register; neither is a Phase 02 deliverable and neither is resolved.

### `P02-XC-05` — Cross-cutting — §10 Completion Criteria

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Existing WorkDo functionality has not been lost."
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §10 (and §4: "Avoid modifying commercial packages/workdo/ modules")`
- **Expected behaviour:** No vendored module edited and no existing capability removed while screens were migrated.
- **Actual behaviour:** A CI guard exists (.github/scripts/guard-no-workdo-changes.sh, cited by D-24b) and the design-system work reached the 29 vendored DataTables through the shared buildDataTable() helper rather than by editing module files. Field-inventory preservation was asserted per screen (product create 45→45, edit 48→48) and the AJAX-popup ratchet counts modal callers so a migration cannot drop one.
- **Implementation:** app/Helper/helper.php; .github/scripts/guard-no-workdo-changes.sh
- **Evidence:** tests/Unit/Ui/AjaxPopupContractRatchetTest.php; tests/Unit/Ui/ActionPartialContractTest.php; ledger field-inventory counts
- **Gap:** There is no single global proof that no WorkDo capability was lost; the evidence is a set of per-screen inventories plus a no-edit guard. CLAUDE.md also records that no WorkDo module loads in a test run unless the test inserts an add_on_managers row, so the suite is structurally blind to module routes.
- **Responsible:** Phase 2.5 / release review
- **Next action:** If a stronger claim is needed before release, enable the modules in a run and census their routes rather than inferring from the no-edit guard.

### `P02-XC-06` — Cross-cutting — §11 Required Final Report

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "When Phase 02 engineering reaches its final review, provide [11 items]… Report clearly and concisely in Arabic."
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §11`
- **Owner decisions:** D-34 (owner, 2026-11-02) — engineering accepted; MERGED TO MAIN / RELEASE READY / DEPLOYED all NO at that moment.
- **Expected behaviour:** An Arabic, evidence-based completion and acceptance report delivered to the owner.
- **Actual behaviour:** PHASE-02-ACCEPTANCE-REPORT-AR.md (170 KB, 1,587 lines) exists, with PHASE-02-CLOSURE-REPORT-AR.md, PHASE-02-FINAL-CLOSURE-AR.md, PHASE-02-INTEGRATION-CLOSURE-AR.md and RELEASE-INTEGRATION-REPORT-AR.md alongside it. The owner acted on it: D-34 (2026-11-02) records PHASE 02 ENGINEERING OWNER ACCEPTED = YES with named accepted SHAs on both repositories.
- **Implementation:** documentation deliverable
- **Evidence:** docs/development/phase-2/PHASE-02-ACCEPTANCE-REPORT-AR.md (existence and size confirmed); PHASE-02-DECISION-REGISTER.md D-34 (read here)
- **Gap:** I confirmed the report's existence and the owner's recorded acceptance; I did not read its 11 sections in this pass.
- **Responsible:** Phase 02 (closed)
- **Next action:** None.

### `P02-XC-07` — Cross-cutting — §10 Completion Criteria

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "All approved Phase 02 requirements have an implementation or a clearly documented genuine external blocker." and "Engineering completion does not automatically mean production readiness."
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §10`
- **Owner decisions:** D-34 (engineering accepted), D-38 / PHASE-02-CLOSURE-RECORD.md (owner, 2026-11-03: development integration authorised and executed; Phase 02 CLOSED for development, explicitly not a release, with an owner-accepted exception on a RED human-checklist gate and no independent human review of any diff).
- **Expected behaviour:** Every A–K requirement implemented or blocked for a named external reason, with release readiness kept separate.
- **Actual behaviour:** On this audit's reading: 96 of the 104 A–K requirements are implemented with evidence I inspected; 8 are PARTIALLY IMPLEMENTED (B4, D9, G5, I2, I4, I5, K8, K15, K17 — nine, of which K15's remainder is externally blocked); none is NOT IMPLEMENTED. Separation of states is maintained explicitly: PHASE-02-CLOSURE-RECORD.md §7 keeps RELEASE READY = NO and DEPLOYED = NO with named open blockers.
- **Implementation:** n/a
- **Evidence:** PHASE-02-CLOSURE-RECORD.md §5–§8; this matrix's per-requirement rows
- **Gap:** The eight/nine partial requirements above are not all covered by an owner decision: D9/I2, I4/I5, G5 and K8's G8/G9 have no decision deferring them. Phase 02 was closed for development with those open.
- **Responsible:** Owner / Phase 2.5
- **Next action:** Carry the un-deferred partials (D9/I2, I4/I5, G5, K8-G8/G9, K13-G4, K17 stages 2–3) into the Phase 2.5 register explicitly, so closing Phase 02 does not retire them.

### `P02-XC-08` — Cross-cutting — §9 Git and §4 Execution Principles

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Never force-push. Never rewrite main… Do not deploy… Do not merge the Phase 02 branch into main without owner authorization and release review." and "Never treat green CI as sufficient evidence of production readiness."
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md §4 and §9`
- **Owner decisions:** D-36 (owner merged PR #123, main went red, follow-up required), D-37 (follow-up merged, main's own CI green), D-38 / closure record (development integration authorised with a documented exception).
- **Expected behaviour:** Branch discipline preserved; no deployment; CI never reported as proof of readiness.
- **Actual behaviour:** The record states, and checks, that no history was rewritten and nothing was force-pushed (the continuation branch is a strict superset of the older one — git log continuation..merchant-experience empty). Merges to main were owner-authorised (D-36, D-38). Every gate statement carries the assertion script's own caveat: 'This is NOT a statement that the suite is green. It is a statement that the suite is failing and skipping exactly the tests the project has recorded.' Eight branches ahead of main were INVENTORIED rather than merged.
- **Implementation:** n/a
- **Evidence:** docs/development/phase-2/PHASE-02-CLOSURE-RECORD.md §2, §5, §6 (read here); PHASE-02-HANDOFF.md §-1
- **Gap:** One honest caveat the record itself states: main is NOT a protected branch, so a RED check did not block the final merges; the restraint was a choice, and the automation's credential reports admin: true.
- **Responsible:** Owner — branch protection (P1-SEC-54 / S58, owner-deferred)
- **Next action:** None in Phase 02; branch protection remains the highest-value open control.


## Phase 2.5 Premium Merchant Experience

### `P2.5-A-01` — A. Authorisation and scope

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "I formally AUTHORIZE the START and IMPLEMENTATION of PHASE 2.5 — PREMIUM MERCHANT EXPERIENCE & VISUAL EXCELLENCE... START IMPLEMENTATION. DO NOT STOP AT PLANNING OR AN AUDIT REPORT."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-39 (owner, 2026-11-03), lines 11-30`
- **Owner decisions:** D-39 authorises start and implementation only; it does not authorise deployment or release.
- **Expected behaviour:** Real implementation lands on merchant surfaces, not another audit document.
- **Actual behaviour:** 295 surfaces inventoried and worked; 2,558 capture PNGs on disk under docs/development/phase-2.5/evidence/; 33 numbered waves plus packages A-E; code changes in public/css/store-syria-ds.css, app/Support/Branding/StoreSyriaBrand.php, app/Support/Customers/CustomerListFilter.php and ~70 Blade/DataTable files.
- **Implementation:** docs/development/phase-2.5/PHASE-2.5-TASK-LEDGER.md (275 KB ledger, one row per slice)
- **Evidence:** I ran `python3 docs/development/phase-2.5/tools/build-coverage-matrix.py --check` -> "coverage matrix is current: 295 surfaces, {'REDESIGNED AND VERIFIED': 225, 'PREMIUM VERIFIED': 24, 'OUT OF SCOPE': 28, 'DUPLICATE': 8, 'BLOCKED': 10}"
- **Responsible:** phase
- **Next action:** None for this clause.

### `P2.5-A-02` — A. Authorisation and scope

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Visual polish, usability corrections and accessible presentation only. Unbuilt functionality — Kanban behaviour, saved-view persistence — stays a Phase 02 row under its original identifier and must not be reported as Phase 2.5 work."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-39, the five-questions table, Q1; restated as the "Standing scope rule" at PHASE-2.5-TASK-LEDGER.md:15-25`
- **Owner decisions:** D-39 Q1 (owner directed this explicitly).
- **Expected behaviour:** No new merchant feature is built under a Phase 2.5 identifier; anything found that is unbuilt functionality is escalated with its Phase 02 id.
- **Actual behaviour:** The ledger carries a dedicated escalation section (§3 "Found, NOT in this slice, and not silently absorbed", line 275). Two BLOCKED matrix rows refuse to build screens on exactly this ground: "Edit customer (web)" — "Building it is new merchant functionality, which routes/web.php:1187-1189 records as unauthorised in Phase 2.5" — and "Wishlist CRUD".
- **Evidence:** PHASE-2.5-COVERAGE-MATRIX.md:303 and the Flutter gateway-editor row at :81; PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §3.5
- **Responsible:** phase
- **Next action:** None.

### `P2.5-A-03` — A. Authorisation and scope

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Phase 2.5 completion criterion is the full 295-surface inventory, not a wave number. Phase 2.5 cannot be presented for acceptance while PENDING is above zero" and "a screen must not silently disappear from the inventory".
- **Source:** `docs/development/phase-2.5/ACCOUNT-HANDOFF.md §2; the "must not silently disappear" clause is quoted from the directive at PHASE-2.5-COVERAGE-MATRIX.md:5`
- **Expected behaviour:** All 295 inventoried surfaces carry a terminal state; the matrix is generated, not hand-edited, and refuses to run if the surface count moves.
- **Actual behaviour:** PENDING is 0. The matrix is generated by tools/build-coverage-matrix.py from PHASE-2.5-SURFACE-INVENTORY.md and has a --check mode that fails on drift; I ran it and it is green at 295.
- **Implementation:** docs/development/phase-2.5/tools/build-coverage-matrix.py
- **Evidence:** build-coverage-matrix.py --check, executed by me 2026-11-16: 295 surfaces, 225/24/28/8/10/0
- **Responsible:** phase
- **Next action:** None.

### `P2.5-A-04` — A. Authorisation and scope

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Do not automatically merge Phase 2.5 PRs to `main` or claim the phase is closed without an explicit subsequent integration decision from the owner."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-39, verbatim quotation`
- **Owner decisions:** Asserted owner integration directive of 2026-11-12, cited only in PROJECT-STATE.md and phase-3/PHASE-03-EXECUTION-PREP.md; not quoted anywhere verbatim.
- **Expected behaviour:** Nothing merges to either main until the owner issues a separate integration decision, and that decision is recorded.
- **Actual behaviour:** At closure (2026-11-11) both PRs were drafts with zero reviews and nothing was merged (OFFICIAL-CLOSURE §0, §12.7). Later, PROJECT-STATE.md:22-23 records both mains AT the Phase 2.5 merge: Laravel `4e878b8d` (PR #134), Flutter `cbf6283f` (PR #9). The authorising owner integration decision is NOT in the Phase 2.5 decision register, which ends at D-42 (2026-11-04). It survives only as engineering prose in PROJECT-STATE.md:25 ("Both SHAs verified against the owner's directive") and phase-3/PHASE-03-EXECUTION-PREP.md:16.
- **Evidence:** PHASE-2.5-OFFICIAL-CLOSURE.md §0, §6, §12.6-12.7 (not merged, 0 reviews, acknowledgement absent); PROJECT-STATE.md:22-25, 40 (merged)
- **Gap:** The merge happened; the owner decision that authorised it is not recorded in the decision register and no D-43 exists. The register therefore ends on a state ("no merge to main") that is no longer true, which is the exact silent-drift failure the register exists to prevent.
- **Responsible:** owner dependency (supply/confirm the directive text), then phase
- **Next action:** Add the 2026-11-12 integration/acceptance decision to PHASE-2.5-DECISION-REGISTER.md as D-43, quoting the owner, so the merged state has a cited authority.

### `P2.5-A-05` — A. Authorisation and scope

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "The 19 blocker findings in the inventory are NOT automatically accepted as verified defects. Finish the adversarial verification. Refuted findings must not become implementation tasks."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-40, "And a condition on the inventory itself"`
- **Owner decisions:** D-40 condition, quoted.
- **Expected behaviour:** Every one of the 290 inventory findings carries an adversarial verdict before any of it becomes work.
- **Actual behaviour:** All 290 findings carry a verdict: 229 upheld, 61 refuted or stale (blocker row 19 -> 15 upheld / 4 refuted). Two runs disagreed on 10 findings; a third adjudication pass settled each by reading code — 5 upheld, 4 refuted, 1 stale. One refutation overturned a repair that had already been written, tested and bitten (flutter-dropdown-hint-white-on-white).
- **Evidence:** PHASE-2.5-VERIFICATION-RESULTS.md §2 (corrected table, 229/61); PHASE-2.5-ADJUDICATED-DISAGREEMENTS.md (all ten, case by case)
- **Responsible:** phase
- **Next action:** None.

### `P2.5-B-01` — B. Coverage matrix and surface states

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Preserve the 295-row matrix "with no artificial promotions"; finalise the blocked dispositions "without falsifying the authoritative matrix".
- **Source:** `docs/development/phase-2.5/PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §2; owner final-acceptance directive quoted at PHASE-2.5-BLOCKED-DISPOSITIONS.md:19`
- **Owner decisions:** Final-acceptance directive, 2026-11-11: "without falsifying the authoritative matrix".
- **Expected behaviour:** Counts move only when real evidence moves, never to clear a decision.
- **Actual behaviour:** Frozen at 225 REDESIGNED AND VERIFIED / 24 PREMIUM VERIFIED / 28 OUT OF SCOPE / 8 DUPLICATE / 10 BLOCKED / 0 PENDING across closure, blocked dispositions, final acceptance and the owner correction round. Group A's four rows were deliberately NOT promoted after they rendered HTTP 200 on real MySQL, because four-combination captures still do not exist.
- **Evidence:** I re-ran build-coverage-matrix.py --check on 2026-11-16: identical set. PHASE-2.5-OFFICIAL-CLOSURE.md §2, §12.3; PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md §5 (matrix unchanged after the 13 corrections)
- **Gap:** The matrix uses FIVE states; the directive named four (PREMIUM VERIFIED / REDESIGNED AND VERIFIED / BLOCKED / OUT OF SCOPE). DUPLICATE (8 rows) is an engineering addition and no owner decision is cited for it.
- **Responsible:** owner dependency
- **Next action:** Get DUPLICATE acknowledged as a legitimate fifth state, or re-state those 8 rows under one of the four.

### `P2.5-B-02` — B. Coverage matrix and surface states

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "VERIFIED means rendered. A surface earns a verified state only when the running application was driven and captured in all four combinations" — English LTR and Arabic RTL, light and dark. "Do not fabricate, duplicate or overclaim evidence."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-COVERAGE-MATRIX.md §"Where Phase 2.5 stands"; owner premium bar at ACCOUNT-HANDOFF.md §10`
- **Expected behaviour:** Each of the 249 verified rows has four real captures of the surface it names.
- **Actual behaviour:** The matrix generator's gate is file EXISTENCE, not content. The project itself documents two measured counterexamples that satisfied the gate: four blank 16x16 tiles for the inline payment-status control, and four `_attribute_option__` captures that photographed a permission-denied toast. Both were found by audit and repaired. Engineering states plainly: "the matrix proves a claimed capture EXISTS; it does not prove the capture shows the surface", and "a third gap has no detector at all: a capture that is neither a duplicate nor a refusal but simply the wrong screen."
- **Implementation:** docs/development/phase-2.5/tools/check-distinct-surface-captures.py; tools/detect-refusal-toast-captures.py (now wired into .github/workflows/ci.yml:386 with a premise check at :398)
- **Evidence:** I ran check-distinct-surface-captures.py (2,558 captures, 2,212 distinct, "no two captures claiming different surfaces share content") and detect-refusal-toast-captures.py (932 captures checked, exit 0, top offender 0.47%). PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §5.2 and §8
- **Gap:** No detector exists for a capture that is simply the wrong screen. The claim "249 surfaces verified" rests on file existence plus two partial detectors, not on per-surface content proof.
- **Responsible:** phase
- **Next action:** Either accept the residual risk explicitly, or build a per-capture assertion (route/title/landmark text read out of the page at capture time and stored beside the PNG).

### `P2.5-B-03` — B. Coverage matrix and surface states

**Status: ACCEPTED OUT OF SCOPE**

- **Requirement (original wording / faithful reference):** 28 surfaces classified OUT OF SCOPE, each with its reason recorded individually in the matrix.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-COVERAGE-MATRIX.md; PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §2`
- **Owner decisions:** Six-phase Master Product Plan / Decision Register v1.0 (2026-10-14) supports the Phase 06 assignment only.
- **Expected behaviour:** Every out-of-scope row is a measurement (operator-only surface, non-screen, route with no caller), not an opinion.
- **Actual behaviour:** Engineering corrected its own earlier claim: 25 are measurements but THREE are phase-scope assignments carrying no measurement of the surface at all — Super Admin dashboard ("Phase 06, not Phase 2.5 Merchant Experience"), Plan-order bank-transfer review modal, and Top brand/category chart fragment — resting on the inventory row title.
- **Evidence:** PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §2, final paragraph
- **Gap:** Three of the 28 are judgements, not measurements, and no owner decision is cited for those three specifically. The Super Admin dashboard assignment to Phase 06 does follow the approved six-phase plan, so it is defensible; the other two are not traceable to an owner boundary.
- **Responsible:** phase
- **Next action:** Measure the two unmeasured rows, or get them named in a scope decision.

### `P2.5-C-01` — C. Brand identity (D-41)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Audit existing Laravel and Flutter brand tokens, then establish one cohesive identity based on the real logo. Do not blindly replace colors. Preserve accessibility, semantic status colors, dark-mode contrast and existing functionality. Apply the unified branding gradually across Laravel and Flutter, with rendered visual verification. Do not redesign or replace the logo itself." Brand blue `#335DA8`, brand cyan `#0EAED1`.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-41 (owner, 2026-11-03, corrected the same day; `#097465` superseded)`
- **Owner decisions:** D-41, with the same-day correction preserved in the register rather than deleted.
- **Expected behaviour:** One brand constant set, derived from the committed artwork, consumed by the web token ramp, the admin chrome default and the Flutter theme, with contrast preserved and the logo untouched.
- **Actual behaviour:** app/Support/Branding/StoreSyriaBrand.php exists and is measured from the committed artwork (1080x1080, 30,235 chromatic pixels, hue band 189.4-218.9 deg): BLUE = '#335da8', CYAN = '#0eaed1', CHROME_DEFAULT = BLUE (replacing theme-3 #10B77F at hue 159.9 deg). Flutter mirrors it at lib/utils/store_syria_brand.dart. The class docblock states "NOT A LOGO CHANGE. Nothing here alters, regenerates or replaces the artwork."
- **Implementation:** app/Support/Branding/StoreSyriaBrand.php; public/css/store-syria-ds.css; ../StoreSyria_app98/lib/utils/store_syria_brand.dart
- **Evidence:** I ran tests/Unit/Ui/StoreSyriaBrandTokensTest.php — passed (part of a 24-test / 270-assertion run with DarkModalSurfaceContrastTest and HeaderGreetingBidiTest). PHASE-2.5-VERIFICATION-MATRIX.md row 11: every element's computed colour classified against the logo's hue band on /dashboard and /category in 4 combinations — 8 rows clean, 1 off-brand colour recorded. PHASE-2.5-BRAND-AUDIT.md
- **Gap:** One off-brand colour survives on /category: #3ec9d6 at hue 185.1 deg on the vendored DataTables reset button. Recorded, not fixed.
- **Responsible:** phase
- **Next action:** Decide whether the vendored DataTables reset button is worth an override (it is in packages/workdo territory).

### `P2.5-C-02` — C. Brand identity (D-41)

**Status: ACCEPTED OUT OF SCOPE**

- **Requirement (original wording / faithful reference):** "Do not blindly replace colors" — the 293 hardcoded colour literals in the Flutter app were left untouched.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-41, "How it is being honoured" table, row 4`
- **Owner decisions:** D-41: "Do not blindly replace colors" — cited verbatim in the register.
- **Expected behaviour:** No global find-and-replace; literals already inside the logo's hue family stay as they are.
- **Actual behaviour:** 293 literals deliberately untouched; the register records the reason as the owner's own instruction to avoid a blind global replacement, and the brand audit measured that Flutter's tokens were already on the logo's hues (cBlue 218.1 deg, cAccent 190.0 deg) while the WEB design system was the one that had drifted (171.6 deg).
- **Evidence:** PHASE-2.5-DECISION-REGISTER.md D-41 audit table; PHASE-2.5-BRAND-AUDIT.md
- **Responsible:** owner dependency
- **Next action:** None.

### `P2.5-C-03` — C. Brand identity (D-41)

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** "Preserve ... dark-mode contrast" (D-41). Open item: `--ss-brand` resolves to cyan `#2AC3E5` in dark mode, so the primary action changes hue with the theme.
- **Source:** `docs/development/phase-2.5/ACCOUNT-HANDOFF.md §3, "Unresolved — awaiting the owner", item 35`
- **Owner decisions:** none
- **Expected behaviour:** One brand hue for the primary action in both themes, or an explicit owner decision that it may shift.
- **Actual behaviour:** Still listed as an unresolved owner decision in the handoff. I found no later document closing it; the brand tokens test asserts every token resolves inside the logo's hue band, which both blue and cyan do, so the ratchet does not catch the shift.
- **Evidence:** ACCOUNT-HANDOFF.md §3 item 35
- **Gap:** No owner decision recorded; no closing document found.
- **Responsible:** owner dependency
- **Next action:** Owner: accept the theme-dependent primary hue, or pin the primary action to one brand colour in both themes.

### `P2.5-D-01` — D. Approved repairs (D-40)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "APPROVED, within the following boundaries. You may repair confirmed defects in existing merchant-facing functionality discovered during Phase 2.5, when those defects prevent normal use, visual verification, or accessibility." Named explicitly: `/product-list` returning HTTP 500.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-40 (owner, 2026-11-03)`
- **Owner decisions:** D-40, named item.
- **Expected behaviour:** /product-list renders in all four combinations.
- **Actual behaviour:** Root cause was `compact()` on `$filterCategories`, assigned nowhere in `ProductController::list()`. Repaired as P2.5-R-01; 7 tests, 3 bites each failing its own test; rendered 200 in all four combinations.
- **Implementation:** app/Http/Controllers/ProductController.php
- **Evidence:** PHASE-2.5-VERIFICATION-MATRIX.md row 7 ("before: HTTP 500 in all four", after "4/4 rendered at 200"), tools/render-matrix.cjs; PHASE-2.5-TASK-LEDGER.md §3b P2.5-R-01
- **Responsible:** phase
- **Next action:** None.

### `P2.5-D-02` — D. Approved repairs (D-40)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Repair the Kanban **Move** action — "repair only the existing authorized action and UI integration. If restoring it requires changing order state-transition semantics, stop that specific change and report the owner decision required."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-40, Kanban carve-out quoted verbatim`
- **Owner decisions:** D-40 carve-out honoured — no state-transition semantics changed.
- **Expected behaviour:** Selecting a move and clicking Move changes the card's column; no change to state-transition semantics or money handling.
- **Actual behaviour:** Three faults repaired (select named `order_status` vs handler reading `$request->delivered`; `picked_up` vs `pickedup`; `response()->json()` stranding a plain form POST on raw JSON). Measured before: 200, raw JSON, board unchanged. After: 302, back on /order-kanban, Pending 1->0, Confirmed 1->2. The ledger records that `Order::order_status_change()`, its vocabulary, its cancel guard and its money handling are untouched.
- **Implementation:** order kanban view + OrderController status-change integration
- **Evidence:** PHASE-2.5-VERIFICATION-MATRIX.md row 10, tools/probe-kanban-move.cjs; PHASE-2.5-TASK-LEDGER.md §3b P2.5-R-05 (10 tests, 3 bites)
- **Responsible:** phase
- **Next action:** None.

### `P2.5-D-03` — D. Approved repairs (D-40)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Repair the customer **Apply** filter control not functioning.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-40, named item`
- **Owner decisions:** D-40 forbids changing financial calculations to repair a filter — cited as the reason both residuals stand.
- **Expected behaviour:** Choosing a field, operator and value and clicking Apply filters the list; Reset restores it; the same operators mean the same thing on the list and the grid.
- **Actual behaviour:** Repaired in four stages. R-02 fixed the dead Apply button (measured 0 requests on click; after: rows 3->1, Reset restores 3; 18 live checks across en/ltr/light and ar/rtl/dark). R-03 fixed the 'Last active' vs 'Last Active' spelling. R-06 then found the filter returned WRONG ANSWERS in two byte-identical copies: AOV 'Equal' was an assignment `&& $AOV = $text_field_float`; 'Less Than' had a duplicated branch testing `>`; 'More Than' had no branch at all; and `!empty("0")` discarded a zero threshold so the whole filter silently did not run. R-07 found the customer GRID filter had never worked by any path. I confirmed in code that the shared `app/Support/Customers/CustomerListFilter.php` now exists with a real `'More Than' => $figure > $threshold` branch at :324, and that CustomerDataTable::query() keeps the tenant predicate on its own line before calling it.
- **Implementation:** app/Support/Customers/CustomerListFilter.php; app/DataTables/CustomerDataTable.php:139-176; app/Http/Controllers/CustomerController.php
- **Evidence:** PHASE-2.5-VERIFICATION-MATRIX.md rows 8-9; PHASE-2.5-TASK-LEDGER.md §3b P2.5-R-02/-R-03/-R-06/-R-07 (R-06: 14 failed/7 passed before the fix, 27 tests after, bite evidence in PHASE-2.5-R-06-BITE-EVIDENCE.md; R-07: 18 tests, 13 bites)
- **Gap:** Two residuals recorded, not fixed, both because D-40 reserves them: the AOV money arithmetic is copied verbatim rather than improved, and the numeric operators remain N+1 by construction (1 + 200 queries for 100 customers) because replacing them with SQL aggregates changes how the figures are computed.
- **Responsible:** phase
- **Next action:** Carry the N+1 and the AOV arithmetic forward as a named open item rather than leaving them in a ledger paragraph.

### `P2.5-D-04` — D. Approved repairs (D-40)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Repair the Flutter `iconData` null-handling / runtime crash.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-40, named item`
- **Owner decisions:** D-40, named item.
- **Expected behaviour:** A caller that takes the optional signature at its word does not crash.
- **Actual behaviour:** Six shared Flutter buttons declared `title` and `iconData` optional and then force-unwrapped them. Repaired backward-compatibly as P2.5-R-04; 9 tests reproduced it first; the 48dp tap target from D-01 asserted to survive the icon-less branch.
- **Implementation:** ../StoreSyria_app98/lib shared button widgets
- **Evidence:** PHASE-2.5-TASK-LEDGER.md §3b P2.5-R-04 and the discussion at :248
- **Responsible:** phase
- **Next action:** None.

### `P2.5-D-05` — D. Approved repairs (D-40)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Conditions on every repair: "Reproduce the defect before fixing it · confirm the existing intended behaviour · identify the root cause · preserve tenant isolation and authorization · do not change business rules, financial calculations, or order transition rules · preserve existing routes and Flutter API compatibility · prefer backward-compatible repairs · add regression tests with positive and negative controls · run the relevant existing security and contract tests · use the `tenant-authz-reviewer` · record each repair separately as a post-closure Phase 02 defect discovered during Phase 2.5."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-40, "The conditions, each of which is a gate on a repair landing"`
- **Owner decisions:** D-40 conditions.
- **Expected behaviour:** Each repair carries a reproduction, a root cause, bite-tested regression coverage and an authorization review where relevant.
- **Actual behaviour:** Every ledger repair row carries a reproduction and bite count (R-01 3 bites, R-03 2, R-05 3, R-06 bite evidence in its own file, R-07 13). The tenant-authz-reviewer was run on R-02 (which found R-03) and on the 2026-11-12 correction set (clean, with five secondary findings, four acted on and one recorded accepted). Contract suite green at 38 passed. The ledger also records a reviewer catching that a security fix had silently emptied an existing test (P1-SEC-60, 30 -> 42 assertions once repaired).
- **Evidence:** PHASE-2.5-TASK-LEDGER.md §3b, §3i, §3n; PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md §5b; PHASE-2.5-OFFICIAL-CLOSURE.md §5.1 (Contract 38 passed)
- **Gap:** "Record each repair separately as a post-closure Phase 02 defect" is honoured inside the Phase 2.5 ledger rather than by rows added to PHASE-02-TASK-LEDGER.md; I did not find Phase 02 ledger rows for R-01..R-07.
- **Responsible:** phase
- **Next action:** Confirm whether the Phase 02 ledger was meant to receive these rows; if so, add them under their Phase 02 identifiers.

### `P2.5-E-01` — E. D-42 follow-up repairs (development only)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** D-42.1 CI merge-base regression gate — "APPROVED, as a separate small PR, kept out of #130. Verify hard-link support rather than assume it; a correct file-copy fallback otherwise; never resolve PHP paths back into the HEAD checkout; prove it on real GitHub Actions. Do not hide errors, skip the baseline, weaken assertions or force a successful exit."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-42.1 (owner, 2026-11-04)`
- **Owner decisions:** D-42.1.
- **Expected behaviour:** A merge-base regression gate exists in CI and reports new failures only.
- **Actual behaviour:** `.github/workflows/ci.yml:1842` declares the job "Merge-base regression gate (new failures only)" and computes `BASE="$(git merge-base "origin/${{ github.base_ref }}" HEAD)"` at :1869. ACCOUNT-HANDOFF §1 records it green on real GitHub Actions for Laravel `7ccf5188`.
- **Implementation:** .github/workflows/ci.yml
- **Evidence:** .github/workflows/ci.yml:1842, :1869 (read by me); ACCOUNT-HANDOFF.md §1 CI table
- **Gap:** I did not verify the hard-link-support check or the file-copy fallback line by line.
- **Responsible:** phase
- **Next action:** None.

### `P2.5-E-02` — E. D-42 follow-up repairs (development only)

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** D-42.2 the ungated settings writer — "APPROVED, using the existing merchant settings policy. No new permission model, no WorkDo behaviour change. Also investigate and report `WebhookController` and `PixelFieldsController` without silently widening authorization."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-42.2`
- **Owner decisions:** D-42.2.
- **Expected behaviour:** The settings writer is gated by the existing policy; the two named controllers are investigated and reported, not quietly widened.
- **Actual behaviour:** Not established from code in this pass. I did not locate a ledger row or report that closes D-42.2 with a measurement, and the closure and acceptance reports do not name it.
- **Gap:** No evidence artefact found for D-42.2 in the Phase 2.5 documents I read. The investigation of WebhookController and PixelFieldsController is not reported anywhere I found.
- **Responsible:** phase
- **Next action:** Find or produce the D-42.2 closure record: the gate applied, and the written report on WebhookController and PixelFieldsController.

### `P2.5-E-03` — E. D-42 follow-up repairs (development only)

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** D-42.3 money display accuracy — "APPROVED for display only. Explicitly not stored amounts, calculations, wallet balances, settlement, payments or conversion rates. Faithful representation, not silent rounding."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-42.3`
- **Owner decisions:** D-42.3.
- **Expected behaviour:** Money figures render faithfully; nothing stored or computed changes.
- **Actual behaviour:** Applied: the ledger records Reports "has had only its money figures corrected (D-42.3)". Dedicated ratchets exist — tests/Unit/Ui/FinanceMoneyFigureContrastTest.php and FinanceMoneyFiguresTest.php — and tools/measure-finance-money-contrast-live.cjs and measure-money-figures-live.cjs. One related behaviour change IS named rather than folded in: 'Total Spend' cast its threshold with `(int)` so "Equal 300.50" answered "Equal 300"; a spend threshold is now read as money, with the ledger stating no stored or computed amount changes.
- **Implementation:** finance and report views
- **Evidence:** tests/Unit/Ui/FinanceMoneyFigureContrastTest.php, FinanceMoneyFiguresTest.php (present on disk); PHASE-2.5-TASK-LEDGER.md §4.11 and §3b R-06 note
- **Gap:** I did not run the money tests, and no single document states the full D-42.3 before/after set with its "display only" boundary re-verified at closure.
- **Responsible:** phase
- **Next action:** Run the two finance money test classes and record the result against D-42.3 explicitly.

### `P2.5-E-04` — E. D-42 follow-up repairs (development only)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** D-42.4 CurrencyController — "Keep the two already verified CurrencyController validation-message corrections if they are strictly presentation-only and preserve existing Super Admin permissions and behavior. Record them as a limited post-closure defect correction, not as authorization to begin Phase 06. No additional Super Admin redesign, currency policy change or feature development is authorized."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-42.4, quoted in full`
- **Owner decisions:** D-42.4, quoted verbatim; the condition is measured rather than asserted.
- **Expected behaviour:** Exactly two flash-message lines change; permissions, validators and HTTP shape untouched.
- **Actual behaviour:** Measured in the register: the whole diff is `app/Http/Controllers/CurrencyController.php \| 4 ++--` — two identical substitutions at :51 (store) and :104 (update), `$messages->first()` -> `implode(' ', $messages->all())`. Changed lines touching isAbleTo/permits/type/'super admin'/store_id/Validator::make or any rule: ZERO. Covered by tests/Feature/Merchant/CommerceSettingsRefusalNamesEveryFieldTest.php, driven and individually bitten.
- **Implementation:** app/Http/Controllers/CurrencyController.php:51, :104
- **Evidence:** PHASE-2.5-DECISION-REGISTER.md "D-42.4 in full" with the measured diff and the zero-count
- **Responsible:** phase
- **Next action:** None.

### `P2.5-E-05` — E. D-42 follow-up repairs (development only)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** D-42.5 the security checklist — "NOT a bypass. Do NOT create or simulate a human security acknowledgement." A focused security-review summary is required instead; the acknowledgement must follow genuine human review.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-42.5; restated in the final-acceptance directive item 6, OFFICIAL-CLOSURE §12.6`
- **Owner decisions:** D-42.5, quoted.
- **Expected behaviour:** The line `SECURITY-DIFF-REVIEW: completed` never appears in a PR body unless a human typed it.
- **Actual behaviour:** Measured from the GitHub API on 2026-11-11: PR #134 draft, 0 reviews of any kind, acknowledgement ABSENT. Engineering recorded that it would clear the check by editing the description and deliberately did not, noting GitHub enforces nothing (`protected: false`, empty rulesets) so nothing was stopping it. A security closure dossier was produced instead (PHASE-2.5-SECURITY-CLOSURE-REPORT.md, 41 KB).
- **Evidence:** PHASE-2.5-OFFICIAL-CLOSURE.md §6, §12.6; PHASE-2.5-SECURITY-CLOSURE-REPORT.md
- **Gap:** PR #134 is now merged (PROJECT-STATE.md:22), so a human presumably supplied the line, but nothing in the repository evidences who or when. The engineering obligation ("do not add it") is discharged; the human review it was collecting is not evidenced in-repo.
- **Responsible:** owner dependency
- **Next action:** Record who supplied the acknowledgement on #134 and when, so the gate's purpose is evidenced and not just its mechanics.

### `P2.5-E-06` — E. D-42 follow-up repairs (development only)

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** D-42.6 Flutter `commonToast` — "APPROVED as an isolated, backward-compatible repair of the shared mechanism, not 275 call sites. Reproduce on the real GetX 4.7.3 / Flutter 3.47.7 shape, with no artificial outer Overlay masking it. Correct the test that treated the failure as expected without weakening assertions. Report any device-verification limitation explicitly."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-42.6`
- **Owner decisions:** D-42.6.
- **Expected behaviour:** One shared toast mechanism repaired; ~270 call sites unchanged; a11y announcement pinned.
- **Actual behaviour:** `../StoreSyria_app98/lib/view/widget/common_snak_bar_widget.dart` carries `commonToast` at :33 with a docblock citing "271 call sites -- 269 `commonToast` plus..." and "roughly 270 `commonToast` call sites -- every save, every refusal", guarded by `test/a11y/toast_announcement_test.dart`, "which drives `commonToast` for all four" combinations. Matrix row "Shared toast (live)" is REDESIGNED AND VERIFIED with four captures under evidence/flutter-feedback-2026-11-10/.
- **Implementation:** ../StoreSyria_app98/lib/view/widget/common_snak_bar_widget.dart
- **Evidence:** ../StoreSyria_app98/lib/view/widget/common_snak_bar_widget.dart:33, :262, :476, :499 (read by me); PHASE-2.5-COVERAGE-MATRIX.md Flutter area
- **Responsible:** phase
- **Next action:** None.

### `P2.5-E-07` — E. D-42 follow-up repairs (development only)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** D-42.7 the operator's implicit store 1 — "APPROVED as a narrow financial safety guard: fail closed on the identified financial write paths when no explicit merchant selection exists. Do not globally rewrite `getCurrentStore()`. Owner's own stop condition: if an explicit choice cannot be reliably distinguished from the fallback, stop and report the blocker."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-42.7`
- **Owner decisions:** D-42.7, including the owner's own stop condition.
- **Expected behaviour:** Named financial write paths refuse when no merchant is selected; getCurrentStore() itself is untouched.
- **Actual behaviour:** Partially established. The Arabic acceptance-readiness report documents four StoreFinanceController handlers (index, requestWithdrawal, withdrawals, updateFinancialPaymentStatus) changed from 500 / raw exception / empty money list to a refusal naming the state, and records that this is hardening not disclosure because `store_id` is NOT NULL. But the handoff still lists open item 84: "The abandoned-cart screen has no operator limb, so an operator with an empty `current_store` is served store 1 by `getCurrentStore()`'s literal. The list leg has the identical property."
- **Implementation:** app/Http/Controllers/StoreFinanceController.php
- **Evidence:** PHASE-2.5-ACCEPTANCE-READINESS-AR.md §1.1(b) table of four handlers before/after; ACCOUNT-HANDOFF.md §3 open items 83, 84
- **Gap:** At least one surface (abandoned carts, both legs) still falls through to store 1 for an unselected operator. Open item 84 is unresolved and no owner decision closes it.
- **Responsible:** phase, then owner dependency
- **Next action:** Either extend the fail-closed guard to the abandoned-cart list and show legs, or report it under D-42.7's stop condition as a named blocker.

### `P2.5-F-01` — F. Design skills and the premium bar

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "`frontend-design`, `ui-ux-pro-max`, `design-system` and `ui-styling` are mandatory for this phase and each must be actively applied and documented" — including "recommendations that were rejected and why". "Do not invent skill usage."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md D-39 Q5; ACCOUNT-HANDOFF.md §10; PHASE-2.5-DESIGN-PACKAGES.md "What governs every package"`
- **Owner decisions:** D-39 Q5 and the owner's correction of 2026-11-04.
- **Expected behaviour:** Each work package records which skills were applied, what they recommended and what was rejected.
- **Actual behaviour:** The ledger records skill application per work package with explicit rejections and reasons, e.g. "`ui-ux-pro-max` (CLI run; rejected its slate+green palette as colliding with D-41's blue/cyan, Glassmorphism as `accessibility risk: conditional` by its own output, and Fira Code headings as having no Arabic coverage; kept dashboard density, subtle motion, status-colour discipline, tabular figures)" and "`ui-styling` (theming and dark-mode only — its shadcn + Tailwind component library does not transfer to Blade + vendored Bootstrap)". A tooling-policy correction (P2.5-F-02) fixed a stale claim that two of the four skills were absent, and repaired the ratchet that passed vacuously.
- **Implementation:** .claude/skills/ (all four committed in-repo)
- **Evidence:** PHASE-2.5-TASK-LEDGER.md:1483 ("The owner's correction of 2026-11-04 required, per work package: which design skills were applied"), :1496, :1508, :1542, :1584, :1598; :281 (P2.5-F-02)
- **Responsible:** phase
- **Next action:** None.

### `P2.5-F-02` — F. Design skills and the premium bar

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Premium is not a contrast ratio. A surface is not premium because it renders, answers 200 or passes WCAG. Layout, information hierarchy, iconography, spacing and responsiveness are the work... A real visual transformation — not contrast repairs, CSS tweaks or Bootstrap-class swaps. When a layout is weak, redesign its composition instead of applying another minor patch."
- **Source:** `docs/development/phase-2.5/ACCOUNT-HANDOFF.md §10 "What 'premium' means here"; PHASE-2.5-DESIGN-PACKAGES.md "What governs every package"`
- **Owner decisions:** The directive's four states permit REDESIGNED AND VERIFIED as terminal; no owner decision says 24 premium surfaces satisfy "Premium Merchant Experience" for the phase.
- **Expected behaviour:** Merchant surfaces are visually transformed, not merely repaired, and the transformation is shown.
- **Actual behaviour:** 24 of 295 surfaces (8.1%) reached PREMIUM VERIFIED; 225 (76.3%) are at REDESIGNED AND VERIFIED, which IS one of the directive's four states, so this is not off-scope by itself. But of the 24 premium rows, engineering's own second cut measures 13 CHANGED and 11 ALREADY-PREMIUM, and "no `before/` capture exists for any of the 24". The ledger also records, as of 2026-11-06, that "No work package in this section was visually verified on a device, only in headless Chromium at 1440x900 on one machine" and "No Arabic native-reader review has taken place" — the latter was then borne out by the owner's own review finding nine Arabic mistranslations including `Slug` -> `السناجب` ("the squirrels").
- **Implementation:** public/css/store-syria-ds.css and ~70 merchant views
- **Evidence:** PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md §3.1 (13/11 split, 96 of 96 captures, 0 before/); PHASE-2.5-TASK-LEDGER.md §4.11; PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md §1
- **Gap:** The owner saw only the 24 premium surfaces' 96 captures. The 225 REDESIGNED AND VERIFIED surfaces were never put to the owner for a judgement on whether they meet the premium bar, and the package says a Flutter visual acceptance pass "is a separate request". There is no measurement anywhere of how many of the 295 received a composition redesign versus a repair.
- **Responsible:** owner dependency
- **Next action:** Ask the owner explicitly whether 24 PREMIUM VERIFIED of 295 satisfies the premium objective, or whether a second premium pass is owed on named areas.

### `P2.5-F-03` — F. Design skills and the premium bar

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "No emojis as interface icons."
- **Source:** `docs/development/phase-2.5/ACCOUNT-HANDOFF.md §10, owner premium bar`
- **Owner decisions:** none for this instance
- **Expected behaviour:** No emoji is used where an interface icon belongs.
- **Actual behaviour:** One instance survives and I confirmed it in code: `resources/views/reports/order_country_Report.blade.php:135` sets `flagType: 'emoji'` for svgMap. The map element is `aria-hidden="true"` (:81), so it is sighted-only decoration, not a control icon. The worklist dismissed the finding on the grounds that "no repository directive forbids emoji", having checked `PHASE-02-MASTER-EXECUTION-PROMPT.md` — the Phase 02 prompt, not the Phase 2.5 bar, which does contain the rule.
- **Evidence:** resources/views/reports/order_country_Report.blade.php:135, :81 (read by me); PHASE-2.5-REMAINING-AREAS-WORKLIST.md:271, :400
- **Gap:** The engineering refutation cited the wrong document. The Phase 2.5 owner bar does forbid emojis as interface icons; the finding was closed against a Phase 02 file that has no such rule. The surviving use is arguably decoration rather than an interface icon, which is a judgement nobody has made explicitly. The stated alternative (`flagType: 'image'`) would add a new external CDN host to a repo that ratchets hosts in CI.
- **Responsible:** phase
- **Next action:** Re-check the finding against ACCOUNT-HANDOFF.md §10 rather than the Phase 02 prompt, and decide: local flag assets, drop the flag, or record the decorative emoji as an accepted exception.

### `P2.5-F-04` — F. Design skills and the premium bar

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Preserve every merchant function, data field, permission and business workflow. Nothing is removed or hidden without an accessible way to reach it." "Do not change payment logic, wallet balances, tenant ownership, financial calculations or commercial rules for aesthetic reasons."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-DESIGN-PACKAGES.md "What governs every package"; ACCOUNT-HANDOFF.md §3 standing prohibitions`
- **Expected behaviour:** No merchant capability is lost to a visual change, and no money behaviour moves.
- **Actual behaviour:** Measured where it was most at risk: the roles-chip translation change was proved non-functional by counting rows and hashing the name set before and after — permissions 206 -> 206, name-set md5 identical, permission_role 126 -> 126 — and `__()` wraps the print only. The independent review verified repo-wide that there is no `isAbleTo(__(`, no `hasPermission(__(`, no `in_array(__(` and that the role write path uses permission ids. The Tag-list change narrowed an unconditionally-declared column header to match the already-`@permission`-gated cells.
- **Implementation:** resources/lang/ar.json, resources/lang/en.json, role and tag views/DataTables
- **Evidence:** PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md §4 (row/hash table) and §5b (tenant-authz-reviewer findings)
- **Gap:** The preservation claim is proved for the specific changes most likely to break it, not across all 295 surfaces. There is no phase-wide inventory asserting that no merchant control was removed or hidden.
- **Responsible:** phase
- **Next action:** If a phase-wide claim is wanted, produce a control inventory (per surface: controls before, controls after) rather than inferring it from per-slice reviews.

### `P2.5-F-05` — F. Design skills and the premium bar

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Report to the owner in Arabic after each substantial package."
- **Source:** `docs/development/phase-2.5/ACCOUNT-HANDOFF.md §10`
- **Expected behaviour:** Each substantial package has an Arabic owner report.
- **Actual behaviour:** 30 Arabic documents exist in docs/development/phase-2.5/ (PHASE-2.5-*-REPORT-AR.md covering waves 1-33, packages C and F, R-06/R-07, security F-SEC-05-08, plus PHASE-2.5-ACCEPTANCE-READINESS-AR.md and PHASE-2.5-PROGRESS-REPORT-AR.md). I read the readiness report's opening and it is genuine Arabic prose with measured tables, not a translation stub.
- **Evidence:** `ls docs/development/phase-2.5/*-AR*.md \| wc -l` -> 30 (run by me); PHASE-2.5-ACCEPTANCE-READINESS-AR.md §1.1
- **Responsible:** phase
- **Next action:** None.

### `P2.5-G-01` — G. Evidence integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Three integrity gates must be green and must not be cleared "by weakening a guard, deleting valid evidence or approving a duplicate".
- **Source:** `docs/development/phase-2.5/PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §3`
- **Expected behaviour:** build-coverage-matrix.py --check, check-distinct-surface-captures.py and detect-refusal-toast-captures.py all pass on real evidence.
- **Actual behaviour:** All three are green today and I ran all three myself: matrix --check green at 295; distinct-capture scanned 2,558 captures, 2,212 distinct, "no two captures claiming different surfaces share content"; refusal-toast checked 932 captures, exit 0, highest toast-red 0.47%. Two of the three were RED when acceptance began and both were red for real false evidence (WooCommerce captures were pictures of /webhook/create; four attribute-option captures were pictures of a permission-denied toast).
- **Implementation:** docs/development/phase-2.5/tools/*.py
- **Evidence:** All three tools executed by me on 2026-11-16, outputs quoted above
- **Responsible:** phase
- **Next action:** None.

### `P2.5-G-02` — G. Evidence integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Recommendation 11 of the final acceptance report: "Make `detect-refusal-toast-captures.py` a required CI step rather than a documented manual run. It found a false VERIFIED claim that every other gate passed." — explicitly flagged "Recommended, cheap, not done without a nod".
- **Source:** `docs/development/phase-2.5/PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §8 item 11`
- **Owner decisions:** The report flagged it as needing a nod; it was implemented. No owner decision is cited, but it only tightens a gate.
- **Expected behaviour:** The detector runs in CI and cannot pass silently when Pillow is missing.
- **Actual behaviour:** Done. `.github/workflows/ci.yml:386` runs `docs/development/phase-2.5/tools/detect-refusal-toast-captures.py`, and :398 raises a PREMISE FAILED error on exit 2, explicitly refusing to treat "checked nothing" as a pass and citing S51 ("the time a silent guard was filed as a green release gate").
- **Implementation:** .github/workflows/ci.yml
- **Evidence:** .github/workflows/ci.yml:375-398 (read by me)
- **Responsible:** phase
- **Next action:** None.

### `P2.5-G-03` — G. Evidence integrity

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Evidence directories must preserve the machine-readable record of their own measurements ("0 findings" must be evidenced, not attested).
- **Source:** `docs/development/phase-2.5/PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §5, "On '0 findings' for the fragment directory, which is the weakest evidence claim in this report"`
- **Owner decisions:** none
- **Expected behaviour:** Each evidence directory's measurements.json records every surface it vouches for.
- **Actual behaviour:** For `evidence/web-closure-frag-2026-11-10/` (29 surfaces, 116 captures) the file on disk records SIX surfaces, not 29 — later filtered re-runs (`--only finance`, `--only variant-panels`) overwrote the earlier surfaces' findings, which are preserved nowhere. Engineering states: "Treat '0 findings' as evidenced for the 6 recorded surfaces and attested-but-not-preserved for the other 23. A full unfiltered re-run would fix that and has not been done."
- **Implementation:** docs/development/phase-2.5/evidence/web-closure-frag-2026-11-10/after/measurements.json
- **Evidence:** PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §5
- **Gap:** 23 of 29 surfaces in that directory have pictures but no preserved measurement record.
- **Responsible:** phase
- **Next action:** Run the fragment harness unfiltered once and commit the full measurements.json, or restate those 23 rows' evidence as captures-only.

### `P2.5-H-01` — H. Visual acceptance

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Final-acceptance directive item 4: "Present the 24 Premium surfaces for genuine owner visual approval", grouped by web and Flutter, and "identify actual visual changes and distinguish them from screens that were already premium". Item 6/§12.11 prohibition: do not sign or simulate approval on the owner's behalf.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-OFFICIAL-CLOSURE.md §12 items 4 and 6; PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md §2, §3.1`
- **Owner decisions:** Final-acceptance directive items 4 and 6.
- **Expected behaviour:** A package the owner can actually look through, with the changed/already-premium distinction made, and no approval recorded by engineering.
- **Actual behaviour:** PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md names all 24 with their 96 capture filenames; PHASE-2.5-VISUAL-ACCEPTANCE-VIEWER.html (50 KB, generated by parsing the package so it cannot drift) puts all 96 on one page. The changed/already-premium cut is given as §3.1: 13 CHANGED / 11 ALREADY-PREMIUM. There is no Flutter group because no Flutter surface is PREMIUM VERIFIED, and the package says so in terms. Every document repeats that no approval is recorded.
- **Evidence:** PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md §1-§6; PHASE-2.5-VISUAL-ACCEPTANCE-VIEWER.html present (50,518 bytes); I spot-checked three of the 96 captures on disk (wave-33/after/_category__en_ltr_light.png 103,680 B; pkg-b-modals/after/brand-create__ar_rtl_dark.png 17,389 B; pkg-a-10/after/kpi__en_ltr_dark.png 13,892 B)
- **Gap:** §3.1 disagrees with §3 on SEVEN rows (Coupon detail, Product question list, Page-header, x-ss.stat, Category create modal, Own profile, x-ss.attention) and the disagreement is "reported, not resolved". The two cuts came from different sources and neither was re-derived from the other.
- **Responsible:** phase
- **Next action:** Resolve the seven-row disagreement per row, so "was a change made" has one answer.

### `P2.5-H-02` — H. Visual acceptance

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Owner's visual review, 2026-11-12: twelve bounded corrections plus one shared footer correction across the 24 PREMIUM VERIFIED surfaces (Brand create modal, Testimonial list, Product grid, Tag list, Product label list, Flash sale list, Category list, Category create modal, Attributes list, Users/staff list, Own profile, Roles list, + footer).
- **Source:** `docs/development/phase-2.5/PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md §1 (the twelve, itemised with the owner's complaint and the fix)`
- **Owner decisions:** The owner's review of 2026-11-12: 12 surfaces accepted, 12 corrections requested, plus the footer.
- **Expected behaviour:** All thirteen corrections applied, each reproduced on a running page first, each re-captured.
- **Actual behaviour:** All thirteen done. Two of the thirteen were photographs, not defects (Tag-list Action column already rendered Edit/Delete; the footer already read "2026 Store Syria" in source, pinned by MerchantFooterBrandTest) and were established as such before any code changed. Nine Arabic mistranslations repaired, including `Slug` -> `السناجب` ("the squirrels") -> `المعرّف`, `Avatar` -> `الأفتار` -> `الصورة الرمزية`. A key collision was caught: translating the shared `Terms` key would have relabelled the storefront "Terms & Conditions" link "Values", so the attribute column got its own key. The active profile tab went 2.09:1 -> 6.42:1 in dark. 181 Arabic permission labels added, 206 permissions and 126 permission_role rows unchanged.
- **Implementation:** resources/lang/ar.json, resources/lang/en.json, public/css/custom.css, product/brand/tag/flash-sale/category DataTables and views, a committed avatar asset under public/
- **Evidence:** I ran tests/Feature/Ui/VisualAcceptanceCorrectionsTest.php -> 27 passed, 126 assertions. 56 before-captures exist at docs/development/phase-2.5/evidence/owner-review-2026-11-12/before/ (counted by me). PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md §5 (render-matrix 48 rows 0 not clean; shoot-modals FINDINGS: 0; matrix unchanged at 225/24/28/8/10/0)
- **Gap:** A deeper finding was reported and NOT fixed: `public/storage` is a tracked regular file containing a vendor build path, so no `/storage/...` URL resolves from any clone and `RegisteredUserController` writes that same path as every new user's `profile_image`.
- **Responsible:** owner dependency
- **Next action:** Decide on `public/storage` — it changes how every uploaded image in the application resolves, so it is not a visual-acceptance correction.

### `P2.5-H-03` — H. Visual acceptance

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Owner visual approval has not been given and is not implied by anything here. §4 is where you give or withhold it." The decision asked for: accept all 24 / accept with exceptions / defer pending a pixel before-after / reject named surfaces.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md §1 and §6; PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md §6`
- **Owner decisions:** Asserted (PROJECT-STATE.md:56); not quoted, not registered.
- **Expected behaviour:** A recorded owner decision accepting, qualifying or rejecting the 24.
- **Actual behaviour:** Contradictory records. The last Phase 2.5 document, PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md (2026-11-12), states in its header "No design is marked approved, here or anywhere else" and in §6 "the 12 corrected surfaces are presented for the owner's decision; the 12 the owner found acceptable remain unsigned too". PROJECT-STATE.md:56 (2026-11-16) states "All 24 Premium surfaces were visually accepted by the owner after thirteen corrections" and :40 marks the phase "COMPLETE, owner-visually-accepted, merged to both mains". No decision-register entry records the acceptance; the register ends at D-42.
- **Evidence:** PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md header and §6; PROJECT-STATE.md:40, :56; phase-3/PHASE-03-EXECUTION-PREP.md:16
- **Gap:** The acceptance that closed this requirement is asserted by two later engineering documents and contradicted by the Phase 2.5 document of the same date. Nothing quotes the owner. This is exactly the silent-closure risk the owner's rule exists to prevent — the strongest claim in the project state rests on the weakest evidence in it.
- **Responsible:** owner dependency
- **Next action:** Record the owner's visual acceptance as a dated decision-register entry quoting the owner, and amend PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md §6 so the two documents agree.

### `P2.5-H-04` — H. Visual acceptance

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Before/after evidence for the premium surfaces (the package offers: "A genuine pixel before/after is reconstructible for any of the fourteen changed surfaces by checking out the pre-change view from git and re-driving it... Name the surfaces you want it for.")
- **Source:** `docs/development/phase-2.5/PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md §4`
- **Owner decisions:** The package explicitly leaves the reconstruction to an owner request; no request is recorded.
- **Expected behaviour:** Where a change was visual, the owner can see before and after.
- **Actual behaviour:** No `before/` capture exists for any of the original 24, in any of the five backing folders — measured and stated. After the 2026-11-12 correction round, 56 before-captures exist for the 14 corrected surfaces (I counted them). The package argues that much of what changed is invisible in pixels (an added aria-label photographs identically) and that where the change was visual it was measured (2.66:1 -> 0 findings; 1.04:1 -> paired tokens).
- **Implementation:** docs/development/phase-2.5/evidence/owner-review-2026-11-12/before/
- **Evidence:** PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md §3.1 ("before/ captures: none exist for any of the 24"); `ls docs/development/phase-2.5/evidence/owner-review-2026-11-12/before/ \| wc -l` -> 56 (run by me)
- **Gap:** 13 of the 24 are recorded as CHANGED and still have no pixel before/after; only the 14 touched in the correction round do.
- **Responsible:** owner dependency
- **Next action:** Either reconstruct before/after for the 13 CHANGED surfaces, or record the owner's decision that the measurements suffice.

### `P2.5-I-01` — I. Web / Flutter parity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Phase 2.5 scope is "premium UI/UX across merchant web AND Flutter, with verified evidence" — 75 inventoried Flutter surfaces across two areas.
- **Source:** `docs/development/PROJECT-STATE.md:40; PHASE-2.5-COVERAGE-MATRIX.md per-area table; PHASE-2.5-DESIGN-PACKAGES.md Flutter packages F0-F3`
- **Owner decisions:** none recorded on Flutter visual acceptance
- **Expected behaviour:** Every Flutter surface carries a terminal state with four-combination evidence.
- **Actual behaviour:** Flutter merchant admin app: 51 surfaces — 0 premium, 47 redesigned, 2 blocked, 1 out of scope, 1 duplicate. Flutter merchant/employee admin app: 24 — 0 premium, 16 redesigned, 0 blocked, 6 out of scope, 2 duplicate. 0 PENDING. 421 Flutter capture PNGs exist under docs/development/phase-2.5/evidence/flutter-*. Gates: flutter test 2,001 passed, flutter analyze 0 errors / 0 warnings / 247 info against a ceiling of 248.
- **Implementation:** ../StoreSyria_app98/lib
- **Evidence:** PHASE-2.5-COVERAGE-MATRIX.md §"By merchant area" and the Flutter surface table; PHASE-2.5-OFFICIAL-CLOSURE.md §5.2; `find docs/development/phase-2.5/evidence -path './flutter-*' -name '*.png' \| wc -l` -> 421 (run by me)
- **Gap:** 0 of 75 Flutter surfaces reached PREMIUM VERIFIED, and the visual acceptance package states "If you want a Flutter visual acceptance pass, that is a separate request" — so no Flutter surface has been put to the owner at all.
- **Responsible:** owner dependency
- **Next action:** Ask the owner whether a Flutter visual acceptance pass is wanted; it was never requested or refused.

### `P2.5-I-02` — I. Web / Flutter parity

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "The blocker is verification infrastructure, not permission to abandon Flutter" (directive §5). Three costed routes: golden tests (no cost, no approval), Android emulator in CI (no cost), real devices (owner decision: approval + billing). "Flutter web is explicitly not device verification and must not be presented as such."
- **Source:** `docs/development/phase-2.5/ACCOUNT-HANDOFF.md §5; ../StoreSyria_app98/docs/PHASE-2.5-FLUTTER-RENDERING-EVIDENCE-BLOCKER-AR.md`
- **Owner decisions:** Route 3 needs an owner decision (approval + billing) and none is recorded.
- **Expected behaviour:** At least route 1 or 2 taken, since neither needs an owner decision.
- **Actual behaviour:** Route 1 partially taken: a golden harness exists with 8 reference images for exactly TWO widgets (customer-card, delivery-boy-card) in four combinations each, at ../StoreSyria_app98/test/golden/goldens/. Route 2 (Android emulator in CI) was not taken. Route 3 remains blocked (no Android SDK, no keystore, no emulator, no device — handoff blocker #6 still open). The verification matrix §4 states the limitation plainly and never substitutes Flutter web; `flutter build web` is recorded as failing outright in firebase_messaging_web interop.
- **Implementation:** ../StoreSyria_app98/test/golden/
- **Evidence:** ../StoreSyria_app98/test/golden/goldens/ (8 PNGs, listed by me); PHASE-2.5-VERIFICATION-MATRIX.md §4 rows "Flutter on a device" and F1/F2; PHASE-2.5-OFFICIAL-CLOSURE.md §5.2 ("no release APK was built or signature-verified — that needs the keystore, which no runner has")
- **Gap:** Goldens cover 2 widgets of 75 surfaces. The emulator-in-CI route, which needed no owner decision, was not taken. No Flutter surface has a device frame.
- **Responsible:** phase, then owner dependency for route 3
- **Next action:** Take route 2 (Android emulator in CI) — it needs no owner decision — or record explicitly that Flutter evidence stops at the widget harness.

### `P2.5-I-03` — I. Web / Flutter parity

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Parity gap: the web offers payment-gateway configuration as a merchant settings tab (setting/index.blade.php:55) while the Flutter app's live PaymentSettingScreen lists methods read-only; the app's COD / Stripe / Bank-transfer gateway editors are 100% commented out — 815 lines, 0 live.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-COVERAGE-MATRIX.md:81 (BLOCKED row, "This is a WEB/FLUTTER PARITY GAP")`
- **Owner decisions:** Owner final-acceptance directive, 2026-11-11, quoted in PHASE-2.5-BLOCKED-DISPOSITIONS.md.
- **Expected behaviour:** An owner decision on whether the app should edit payment gateways.
- **Actual behaviour:** I confirmed six files under ../StoreSyria_app98/lib/view/screen/setting_screen/payment_setting_screen/ contain commented-out code (cod_screen.dart:114, stripe_screen.dart:120, bank_transfer_screen.dart:109 all show `//` -prefixed `iconData:` lines). Disposed by the owner's final-acceptance directive as SCHEDULED to Phase 06; the row stays BLOCKED in the matrix because no capture exists.
- **Evidence:** PHASE-2.5-BLOCKED-DISPOSITIONS.md "Final dispositions" row 8; owner sentence quoted at PHASE-2.5-BLOCKED-DISPOSITIONS.md:10-12 — "scheduling inactive/new functionality in its appropriate future phase"; grep of the three Flutter screen files (run by me)
- **Gap:** Reviving them exposes financial configuration, which Phase 2.5 reserved; the Phase 06 commitment is recorded in a Phase 2.5 document, not in a Phase 06 plan.
- **Responsible:** owner dependency / Phase 06
- **Next action:** Carry this into the Phase 06 scope document so the deferral has a receiving home.

### `P2.5-I-04` — I. Web / Flutter parity

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Parity gap: "a merchant can edit a customer from their phone and not their desk" — `POST update-customer` -> `DashboardController::updateCustomerInfo` ships and the Flutter admin uses it, while `routes/web.php:1200` registers `Route::resource('customer', ...)->except(['edit','update'])`.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-COVERAGE-MATRIX.md:303 (BLOCKED row); PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §3.5`
- **Owner decisions:** Owner final-acceptance directive, 2026-11-11.
- **Expected behaviour:** An owner decision on whether the web screen should exist; building it is new merchant functionality Phase 2.5 may not add.
- **Actual behaviour:** Disposed by the owner's final-acceptance directive as SCHEDULED to Phase 04 (Commerce). Still BLOCKED in the matrix because no capture exists. Phase 04 is now active (PROJECT-STATE.md:44) and I found no Phase 04 row carrying this screen.
- **Evidence:** PHASE-2.5-BLOCKED-DISPOSITIONS.md "Final dispositions" row 9; the owner sentence at :10-12
- **Gap:** The Phase 04 execution plan was not checked for a row receiving this commitment; if it has none, the deferral has nowhere to land.
- **Responsible:** owner dependency / Phase 04
- **Next action:** Confirm docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md carries the Edit-customer web screen; add it if not.

### `P2.5-I-05` — I. Web / Flutter parity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Flutter Arabic copy: 72 untranslated merchant strings blocked on the owner, because GetX `.tr` renders the raw identifier when a key is missing, so translating without copy is worse than the defect.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-TASK-LEDGER.md §3g item 1; ../StoreSyria_app98/docs/development/phase-2.5/COPY-DECK-UNTRANSLATED-SCREENS.md`
- **Owner decisions:** Owner authorised the copy in the Phase 2.5 final directive.
- **Expected behaviour:** Owner-supplied Arabic copy, then conversion.
- **Actual behaviour:** RESOLVED 2026-11-09. The copy deck's own header: "The owner authorised the copy in the Phase 2.5 final directive and it is written. All 68 unique strings across the five screens below are translated, at 76 call sites, and the keys are in lib/translations/ar.dart and en.dart." Terminology was aligned to the Laravel `ar.json` side, and `State` was moved on BOTH surfaces from `الدولة` (country — a collision with `البلد`) to `المحافظة`, guarded by tests/Unit/Localisation/AddressTerminologyTest.php in the Laravel repo.
- **Implementation:** ../StoreSyria_app98/lib/translations/ar.dart, en.dart; tests/Unit/Localisation/AddressTerminologyTest.php
- **Evidence:** ../StoreSyria_app98/docs/development/phase-2.5/COPY-DECK-UNTRANSLATED-SCREENS.md header "RESOLVED — 2026-11-09" (read by me)
- **Gap:** A second ledger item in the same section is NOT recorded as resolved: `OrderRefundController::refundDenied()` passes bare English literals unwrapped by `__()`.
- **Responsible:** phase
- **Next action:** Close or re-raise the refundDenied() English-literal row.

### `P2.5-I-06` — I. Web / Flutter parity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Preserve Flutter API contract compatibility (standing principle 2; D-40 "preserve existing routes and Flutter API compatibility").
- **Source:** `CLAUDE.md §2 principle 2; PHASE-2.5-DECISION-REGISTER.md D-40 conditions`
- **Expected behaviour:** The cross-repo contract does not drift.
- **Actual behaviour:** Contract suite green at 38 passed / 149 assertions. Regenerating the manifest from the live Flutter checkout produced a byte-identical file — zero drift, verified in both directions — which closed NEW-CONTRACT-STALE-02 in the stabilization register, a row previously filed "BLOCKED — EXTERNAL EVIDENCE REQUIRED" because it needed both repositories present.
- **Evidence:** PHASE-2.5-OFFICIAL-CLOSURE.md §5.1 and §5.2 ("cross-repo API contract — green in both directions, zero drift")
- **Responsible:** phase
- **Next action:** None.

### `P2.5-J-01` — J. Blocked surfaces — explicitly accepted exceptions

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Owner final-acceptance directive: "Recommend accepting Group A's MySQL measurements and Group C's invoice evidence, deferring external sandbox coverage, and scheduling inactive/new functionality in its appropriate future phase." Group A = the four report bodies (reports-chart, order-reports-chart, order-reports-chart-data, top-all-reports).
- **Source:** `docs/development/phase-2.5/PHASE-2.5-BLOCKED-DISPOSITIONS.md:10-12 (owner sentence, quoted); Final dispositions rows 1-4`
- **Owner decisions:** Owner final-acceptance directive, 2026-11-11, quoted verbatim in PHASE-2.5-BLOCKED-DISPOSITIONS.md.
- **Expected behaviour:** The four report bodies are accepted on the MySQL measurement; no promotion in the matrix.
- **Actual behaviour:** MariaDB 10.11.14 installed in-container, 132 tables migrated, database/seed-p2dev.php seeded, all four routes driven over HTTP as the fixture merchant: 200 / 3,060 B; 200 / 8,689 B; 200 / 7,099 B (after a fix); 200 / 88,461 B. The third had a second defect its matrix row never mentioned — `count(): Argument #1 must be of type Countable\|array, int given` in x-ss.bar-chart, because ReportController::order_reports_chart_data() initialises `$PurchasedItemTotal = 0` and passes a scalar as a series; the component guarded only `$values2`. Fixed and pinned by tests/Unit/Ui/BarChartScalarSeriesTest.php (20 cases). All four rows deliberately stay BLOCKED: no four-combination capture exists.
- **Implementation:** resources/views/components/ss/bar-chart.blade.php
- **Evidence:** PHASE-2.5-OFFICIAL-CLOSURE.md §4 Group A table; evidence/mysql-reports-2026-11-11/_report_bodies__MEASUREMENT.json; tests/Unit/Ui/BarChartScalarSeriesTest.php present on disk (confirmed by me)
- **Gap:** The screens have never been seen in four combinations. "ACCEPTED ON MEASUREMENT" means the route executes, not that the screen is right.
- **Responsible:** owner dependency
- **Next action:** None owed in Phase 2.5. If these screens matter visually, capture them on the MySQL fixture in a later phase.

### `P2.5-J-02` — J. Blocked surfaces — explicitly accepted exceptions

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Group B — Shopify import screens (5) and WooCommerce import screens (4): "deferring external sandbox coverage".
- **Source:** `docs/development/phase-2.5/PHASE-2.5-BLOCKED-DISPOSITIONS.md Final dispositions rows 5-6; owner sentence at :10-12`
- **Owner decisions:** Owner final-acceptance directive, 2026-11-11.
- **Expected behaviour:** Deferred, not reclassified, pending owner-provided sandbox credentials.
- **Actual behaviour:** Both stay BLOCKED–DEFERRED. Engineering had recommended reclassifying them OUT OF SCOPE and deliberately did NOT: "The owner said deferring, not reclassifying... Reclassifying would have moved two rows between authoritative counts on my own initiative." Measured separately rather than lumped together: Shopify answers HTTP 500 in all four combinations (the Admin API is called before a view is returned, every failure arm is `redirect()->back()`); WooCommerce answers HTTP 200 with an empty body and no `<html>` element, and has no pixels of its own because both refusal arms are `redirect()->back()` — which is how four of its captures came to be pictures of /webhook/create.
- **Evidence:** PHASE-2.5-BLOCKED-DISPOSITIONS.md Final dispositions; PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §3.2; PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §3.1
- **Gap:** Nine merchant screens have never been rendered. Nothing is owed by Phase 2.5.
- **Responsible:** owner dependency
- **Next action:** Owner: provide a Shopify and a WooCommerce sandbox credential if these screens are to be verified.

### `P2.5-J-03` — J. Blocked surfaces — explicitly accepted exceptions

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Group C — Flutter invoice PDF viewer: "accepting ... Group C's invoice evidence".
- **Source:** `docs/development/phase-2.5/PHASE-2.5-BLOCKED-DISPOSITIONS.md Final dispositions row 7; owner sentence at :10-12`
- **Owner decisions:** Owner final-acceptance directive, 2026-11-11.
- **Expected behaviour:** Accepted on existing evidence; a device capture is optional.
- **Actual behaviour:** The viewer CHROME is captured in all four combinations (evidence/flutter-invoice-2026-11-10/, 4 PNGs counted by me); the PDF BODY is not, because PdfPreview rasterises through the printing plugin and flutter_test raises MissingPluginException for `rasterPdf` on channel net.nfet.printing. A real defect was found while investigating: the viewer opened at US Letter and is now pinned to A4 by test/screens/invoice_viewer_capture_test.dart. This is the one of the ten blocked rows that IS a capture gap, and engineering says so rather than smoothing it over.
- **Implementation:** ../StoreSyria_app98/test/screens/invoice_viewer_capture_test.dart
- **Evidence:** PHASE-2.5-COVERAGE-MATRIX.md Flutter invoice row; PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §3.3 and §3 preamble; evidence/flutter-invoice-2026-11-10/ (4 PNGs)
- **Gap:** The PDF body has never been seen.
- **Responsible:** owner dependency
- **Next action:** None owed.

### `P2.5-J-04` — J. Blocked surfaces — explicitly accepted exceptions

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Group D row 10 — Wishlist create/edit/store/update: four routes resolve and none renders a byte; `WishlistController::create()` and `::store()` have bodies that are literally `//`. Recommended: "Unregister the routes regardless; the feature question is the owner's."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-BLOCKED-DISPOSITIONS.md Final dispositions row 10; PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §3.5`
- **Owner decisions:** Owner final-acceptance directive, 2026-11-11 ("scheduling inactive/new functionality in its appropriate future phase").
- **Expected behaviour:** The dead routes are unregistered and the feature question goes to the owner.
- **Actual behaviour:** Disposed as SCHEDULED to Phase 04. The matrix row stays BLOCKED. The "unregister the routes regardless" half — which engineering recommended as unconditional — is not recorded as done anywhere I found; the final disposition says only "a product decision on whether merchant-authored wishlists exist at all".
- **Evidence:** PHASE-2.5-BLOCKED-DISPOSITIONS.md Final dispositions row 10
- **Gap:** Two write endpoints that render nothing remain registered. The recommendation to unregister them regardless of the feature decision was absorbed into the deferral rather than actioned or explicitly declined.
- **Responsible:** owner dependency / Phase 04
- **Next action:** Confirm whether the routes were unregistered; if not, raise it as its own item for Phase 04.

### `P2.5-K-01` — K. Security items the directive named

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** The four critical Composer advisories — "Do not raise AUDIT_CEILING or suppress" (standing directive; the ceiling was deliberately lowered 70->58 and "must not be raised").
- **Source:** `docs/development/phase-2.5/PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §6.1-6.2; ACCOUNT-HANDOFF.md §3; PHASE-2.5-SECURITY-CLOSURE-REPORT.md:158`
- **Owner decisions:** The lock change is owner-gated and was taken through PR #133 into the Phase 2.5 branch "not main", which is where the directive placed it.
- **Expected behaviour:** Clear the criticals by a same-major lock change, not by raising the ratchet.
- **Actual behaviour:** Done, and I verified it in the lock: laravel/framework v11.57.1, mtdowling/jmespath.php 2.9.2, phpoffice/phpspreadsheet 1.30.8 — exactly the three same-major, non-breaking bumps the report named as remediation. `.github/workflows/ci.yml:843` still reads `AUDIT_CEILING: '58'` — unchanged. The inventory went 66 (RED, failing by 8) to 52 re-measured on the integrated tree, "passes with 6 to spare".
- **Implementation:** composer.lock (PR #133, merged into the Phase 2.5 branch)
- **Evidence:** composer.lock parsed by me (three versions above); .github/workflows/ci.yml:843 read by me; PHASE-2.5-OFFICIAL-CLOSURE.md §5.1 ("52 advisories / 20 packages against AUDIT_CEILING: '58'")
- **Gap:** A CI comment refuted during this phase should still be corrected: ci.yml:616-625 justified "not reachable" partly with "the vulnerable auto-detection branch is never entered", which was measured FALSE (all eight import controllers accept `txt`, `config('excel.extension_detector.txt')` is NULL, so `evil.txt` does fall into IOFactory::identify()). The conclusion survives on the server-generated filename; the stated reason does not.
- **Responsible:** phase
- **Next action:** Correct the refuted reasoning in the ci.yml comment so nobody relies on it.

### `P2.5-K-02` — K. Security items the directive named

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Incident P1-SEC-40 (#120) — the committed production dump and the publicly served `.git` metadata and historical credentials on the test host. Owner standing instruction: no key rotation, no change to public access, no deployment, no history rewrite, no visibility change.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-OFFICIAL-CLOSURE.md §7 "OPEN — OWNER-DEFERRED — UNRESOLVED SECURITY RISK"; PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §6.3`
- **Owner decisions:** Owner standing instruction, recorded as "OWNER-DEFERRED — UNRESOLVED SECURITY RISK" — a deferral, explicitly not a remediation and not release approval.
- **Expected behaviour:** Containment of RECURRENCE only; the incident itself stays owner-deferred and is never described as remediated.
- **Actual behaviour:** Measured without printing values: ~29,790 rows, 90 bcrypt password hashes (33 users, 57 customers), 137 distinct IPv4 addresses across 6,471 occurrences, 6,442 rows of raw visitor request logs. No live gateway key, no APP_KEY in the dump. `.gitignore` now refuses dump shapes; the dump file `storesyria_ecommercego.sql` is no longer present in the working tree (I checked). The Apache deny rules were LIFTED OUT of `/.htaccess` and `public/.htaccess` into docs/security/p1-sec-40-apache-deny.conf (present on disk, read by me) — because the live document root IS the repository root and merging would otherwise have armed them on the next `git pull`, against the standing instruction. Both served files are directive-identical to origin/main; 32 tests pin it, two of which fail if either served file gains an active deny directive.
- **Implementation:** .gitignore, docs/security/p1-sec-40-apache-deny.conf, tests/Unit/Security/DumpAndVcsPathsAreDeniedTest.php
- **Evidence:** docs/security/p1-sec-40-apache-deny.conf exists; storesyria_ecommercego.sql absent from the tree (both checked by me); PHASE-2.5-OFFICIAL-CLOSURE.md §7, §12.9, §12.11; PHASE-2.5-MERGE-SAFETY.md
- **Gap:** Nothing is remediated. `/.git/HEAD` and `/.git/objects/info/packs` still answered 200 on 2026-11-11. 90 bcrypt hashes and 6,442 visitor-log rows remain exposed; credential rotation and customer notification are outstanding owner calls. P1-SEC-62 was raised as conditional on P1-SEC-40 (the operator diagnostic's log line reproduces a distinction the refusal message withholds, and laravel.log is publicly served).
- **Responsible:** owner dependency
- **Next action:** Owner: the three decisions in §8.1 of the final acceptance report (read-only probe, root .htaccess deny, history rewrite), plus credential rotation and customer notification.

### `P2.5-K-03` — K. Security items the directive named

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Five live security defects found during the closure, none of them a matrix row: DELETE /bank_transfer/{order}, DELETE /menu/delete-item/{id}, DELETE /delete-custom-link/{id}, DELETE /plan/{plan}, GET /users/{id}/edit.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §5`
- **Owner decisions:** Owner decision 8 of the final acceptance report asks whether these five are admitted as release blockers or logged separately; I found no recorded answer.
- **Expected behaviour:** All fixed with tests, and NOT folded into the matrix as surface rows.
- **Actual behaviour:** All five fixed, each measured end to end through the real routes before being fixed, each fix mirroring an existing gate rather than inventing a policy. Pinned by tests/Feature/Security/DestructiveRoutesTenantGuardTest.php (10 cases, covering both the refusal and that the legitimate owner and the operator can still do the work) and CrossTenantPolicyGridTest (+2). Every fix was reverted individually and the matching test reddened; the check caught that one fix had NO test at all (its row was only ever reached as a cascade through another route). They were deliberately not added as matrix rows.
- **Implementation:** BankTransferController, MenuItemsController, MenuController, PlanController, UserController::edit
- **Evidence:** PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §5, §4.2 (the per-fix revert table)
- **Gap:** Two cross-tenant READS remain unfixed and named: `product_price` and `getProductsVariantQuantity`, both `Product::find($request->...)` with no store predicate — Product is registered with StoreScope but is not allowlisted, so it adds no predicate for anyone.
- **Responsible:** phase, then owner dependency
- **Next action:** Close the two cross-tenant product reads, or record them as accepted; and get an answer on owner decision 8.

### `P2.5-K-04` — K. Security items the directive named

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** `P0-USER-DELETION-CASCADE.md` §8.1 — the scoped operator-authorization change: one Laratrust role `platform-operator` carrying exactly `Edit User`.
- **Source:** `docs/development/phase-2.5/PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §6.4`
- **Owner decisions:** none
- **Expected behaviour:** Either make the change or report why not, without taking the decision on the owner's behalf.
- **Actual behaviour:** Measured: the change is a no-op in production and the condition it was written for does not exist. In the production dump there is exactly one operator (user 1), role_user assigns role 1 `super admin`, and permission_role gives that role 75 permissions including Manage/Create/Edit/Delete User and Reset Password — granted by product code (`Utility::addNewData()`) that runs on every successful login. So UserPolicy::update() already admits the live operator. Engineering recommends declining §8.1 as superseded and surfaces the larger question instead: the operator's user-management grant is already live and re-applied at every login — is that intended?
- **Evidence:** PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §6.4
- **Gap:** No owner decision recorded on either question. A related one is also open and untouched: `StoreController::edit/update` rewrites a merchant's name and email gated on `type == 'super admin'` alone — no permission, no policy.
- **Responsible:** owner dependency
- **Next action:** Owner: decline §8.1 as superseded (recommended), and rule on whether the operator's automatic per-login user-management grant is intended.

### `P2.5-K-05` — K. Security items the directive named

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** The test suites must run to completion and the known-outcome set must be asserted in both directions; "Do not manufacture green results or weaken checks"; nothing added to `.github/known-test-outcomes.json`.
- **Source:** `CLAUDE.md §2 (autonomous execution); PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §4 and §4.1`
- **Expected behaviour:** Unit, Contract and the Feature known-outcome gate green, with the declared failure set unchanged.
- **Actual behaviour:** On the final integrated tree: Unit 1,639 passed / 12,222 assertions; Contract 38 passed / 149; Feature 8,439 testcases, 8,436 passed, 2 failed, 1 skipped; known-outcome gate OK — "2 declared failures, 1 declared skip, 0 errors, and nothing undeclared". `.github/known-test-outcomes.json` differs from main by two lines and that change only re-measured a stale file:line INSIDE an already-declared entry; no entry added or removed. The gate caught three real regressions introduced by repairs during the phase (twelve shifted log lines, FinanceControlLabellingTest asserting attribute order, and a +28 line shift from the §5.5 repair) and each time the fix was to correct what the gate defended.
- **Evidence:** PHASE-2.5-OFFICIAL-CLOSURE.md §5.1; PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §4, §4.1, §4.3. I independently ran four test classes (DarkModalSurfaceContrastTest, StoreSyriaBrandTokensTest, HeaderGreetingBidiTest: 24 passed / 270 assertions; VisualAcceptanceCorrectionsTest: 27 passed / 126 assertions) — all green on the current tree.
- **Gap:** The two declared Feature failures are real and named: RegistrationTest (a fixture gap exposing a latent robustness defect — a missing `admin` role would register a user with no role and no error) and ProfileTest (GET /profile answers 500 with `Attempt to read property "slug" on null` for a User with no current_store and no store they created). The one declared skip is SCHEMA-01: `newsletters.user_id` is written by AccountProfileController::add_newsletter and no migration declares it.
- **Responsible:** phase
- **Next action:** The two declared failures and the SCHEMA-01 skip are real defects carried forward; they belong in a project-wide gap register, not only in a test manifest.

### `P2.5-K-06` — K. Security items the directive named

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** Final-acceptance directive item 9: "Ensure merging does not trigger a deployment and does not activate the deferred `.htaccess` rules."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-MERGE-SAFETY.md:3 (owner question, quoted); OFFICIAL-CLOSURE §12.9`
- **Owner decisions:** Owner final-acceptance directive item 9.
- **Expected behaviour:** Prove merging is inert with respect to deployment and to public access.
- **Actual behaviour:** Proven, and it found a real defect of the branch's own making. No deployment can be triggered: three workflows in Laravel, one in Flutter; four actions between them (checkout, upload-artifact, gitleaks, setup-php), none able to ship files; the three deploy-shaped grep hits in ci.yml are prose in comments; no .cpanel.yml or auto-deploy manifest; no tracked git hooks; core.hooksPath set nowhere. The branch HAD committed the deny rules into both .htaccess files, which would have armed on the next `git pull` on the live host; they were lifted out and both served files are now directive-identical to origin/main.
- **Implementation:** docs/security/p1-sec-40-apache-deny.conf; tests/Unit/Security/DumpAndVcsPathsAreDeniedTest.php
- **Evidence:** PHASE-2.5-MERGE-SAFETY.md (including two falsification tests that FAIL when a deny directive is re-added to either served file); PHASE-2.5-OFFICIAL-CLOSURE.md §12.9
- **Gap:** One thing could not be checked: repository webhooks — that GitHub API path returns 403 to the session. Recorded as unverified rather than cleared.
- **Responsible:** owner dependency
- **Next action:** Owner: check Settings -> Webhooks once, to close the one unverified channel.

### `P2.5-K-07` — K. Release readiness

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Green CI is necessary evidence, not proof that a production release is ready." The four states must be distinguished and not claimed until their requirements hold: ENGINEERING COMPLETE, OWNER ACCEPTED, MERGED TO MAIN, PRODUCTION RELEASE READY.
- **Source:** `CLAUDE.md §2 principle 3; docs/development/phase-2.5/PHASE-2.5-OFFICIAL-CLOSURE.md §0 and §11`
- **Owner decisions:** Owner deferral of P1-SEC-40; Phase 01 release gates predate this phase.
- **Expected behaviour:** Each state is reported separately with its own verdict and its own decider.
- **Actual behaviour:** Kept apart rigorously and repeatedly. At closure: ENGINEERING COMPLETE YES; OWNER ACCEPTED NO; MERGED TO MAIN NO; PRODUCTION RELEASE READY NO. The acceptance report leads with "The two things that are true at once" and refuses release readiness on four named grounds. An earlier version of the engineering closure report collapsed two of these into one row and the correction is recorded rather than quietly edited.
- **Evidence:** PHASE-2.5-OFFICIAL-CLOSURE.md §0, §11; PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md §1, §9; PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §1
- **Gap:** PRODUCTION RELEASE READY remains NO and nothing in Phase 2.5 changes it: the security exposure is owner-deferred and unresolved, and "the Phase 01 release gates remain outstanding and were not in this directive's scope".
- **Responsible:** owner dependency
- **Next action:** Keep Phase 01's release gates and #120 visible in any release decision; neither is waived by Phase 2.5's merge.


## Phase 03 Storefront, Themes, Official Website and Documentation (M0–M7)

### `P03-A-01` — 03-A Existing storefront themes

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Inventory the three existing storefront themes, templates, routes and current configurability."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-A (line ~145)`
- **Expected behaviour:** A measured inventory of stylique/techzonix/greentic — view counts, theme selection mechanism, settings taxonomy — exists and is checkable.
- **Actual behaviour:** Verified against code: themes/ holds exactly stylique, techzonix, greentic; each has views/main_file.blade.php and front_end/layouts/app.blade.php; theme selection is stores.theme_id resolved by ActiveTheme middleware + ViewServiceProvider + a View::composer (read app/Providers/ViewServiceProvider.php:85-106). The prep inventory and PHASE-03-M1-THREE-THEME-BASELINE.md record blade counts and the ~520-key settings files, and M0/M1 fixtures cover all three themes plus the empty-theme_id fallback store.
- **Implementation:** docs/development/phase-3/fixtures/, docs/development/phase-3/tools/seed-storefront-fixture.php
- **Evidence:** docs/development/phase-3/PHASE-03-M0-STOREFRONT-BASELINE.md; PHASE-03-M1-THREE-THEME-BASELINE.md; evidence/m1-storefront-baseline-2026-11-12/ (5 store directories)
- **Responsible:** phase 03
- **Next action:** None.

### `P03-A-02` — 03-A Existing storefront themes

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Deliver a consistent, premium responsive storefront experience for each, without replacing working WorkDo controllers or commerce paths."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-A (line ~146)`
- **Expected behaviour:** All three themes are visually upgraded and tokenised, render at mobile/tablet/desktop, and no WorkDo commerce controller is replaced or duplicated.
- **Actual behaviour:** M2 tokenised all three themes and added storefront dark mode and a per-store appearance preference; evidence/m2e-all-stores-2026-11-12/ holds captures for 5 stores x 7 routes x {en,ar} x {light,dark} x 3 viewports. guard-no-workdo-changes reports packages/workdo/ untouched. I did not re-run the harness; I verified the capture files and matrix JSON exist on disk. No owner visual acceptance has occurred for any Phase 03 surface — grep for 'visual acceptance' in phase-3 docs returns only references to Phase 2.5's.
- **Implementation:** themes/{stylique,techzonix,greentic}/, public/css/storefront-appearance.css
- **Evidence:** docs/development/phase-3/evidence/m2e-all-stores-2026-11-12/*/storefront-matrix.json and PNGs; PHASE-03-M2-PREMIUM-STOREFRONTS.md §4
- **Gap:** 'Premium' was never accepted by a human: no owner visual acceptance for Phase 03, unlike Phase 2.5 where 24 surfaces were visually accepted after thirteen corrections. The browser harnesses are manual tools — grep of .github/workflows/ci.yml finds no reference to storefront-matrix.cjs, platform-site-matrix.cjs, verify-a11y-matrix.cjs or any Phase 03 .cjs tool, so none of this evidence is regenerated or gated by CI.
- **Responsible:** owner (visual acceptance) + phase 03
- **Next action:** Put the Phase 03 storefront captures in front of the owner for visual acceptance in the Phase 2.5 shape, and decide whether the harnesses become CI gates or are declared manual-by-design.

### `P03-A-03` — 03-A Existing storefront themes

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Validate product discovery, collections, product detail, cart and checkout UX against the existing engine." (The Master Plan scope line for Phase 03 likewise names "shopper experience, checkout UX".)
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-A (line ~147); CLAUDE.md §4 Phase 03 row`
- **Expected behaviour:** Each named surface — discovery, collections, PRODUCT DETAIL, cart and CHECKOUT — is driven and its UX validated in the agreed combinations.
- **Actual behaviour:** Measured from the evidence directories: the captured route set is exactly home, product_list, collections, cart, wishlist, track_order, contact_us (7 routes) in M1 and again in M2-E. Product detail and checkout appear in NO capture set. PHASE-03-M1-THREE-THEME-BASELINE.md §5.4 states it plainly: 'No product-detail or checkout-flow capture. /product/{slug} needs a per-store product slug the harness does not yet thread through; the 16-route HTTP sweep covers their status only.' The a11y/vitals matrix is narrower still — PHASE-03-M2-PREMIUM-STOREFRONTS.md:178 records 'routes \| 4 — /home, /product-list, /cart, /login'.
- **Evidence:** Route set derived from `ls docs/development/phase-3/evidence/m2e-all-stores-2026-11-12/damascus-textiles/`; PHASE-03-M1-THREE-THEME-BASELINE.md §5.4; PHASE-03-M2-PREMIUM-STOREFRONTS.md:178
- **Gap:** Product detail and checkout — the two highest-value shopper surfaces — were never captured, never contrast-checked, never axe-scanned and never UX-validated in Phase 03. An HTTP status sweep is not UX validation. No owner decision defers them.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Thread a per-store product slug through the harness, capture /product/{slug} and the checkout flow in the agreed combinations, and run axe and contrast over both; or obtain an explicit owner deferral naming them.

### `P03-A-04` — 03-A Existing storefront themes

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Design Arabic RTL and English LTR, light/dark where supported, mobile/tablet/desktop, accessibility, keyboard operation and performance."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-A (line ~148)`
- **Expected behaviour:** All seven named dimensions, including keyboard operation, are designed and checked for the storefront.
- **Actual behaviour:** AR-RTL/EN-LTR, light/dark and three viewports are captured (M2-E, 160 loads); axe reports 0 WCAG A/AA nodes; LCP/CLS measured over 80 loads. KEYBOARD OPERATION is not measured anywhere: PHASE-03-M7-INTEGRATION.md §7 states 'Keyboard order, focus management, whether a label describes its control... are not measured and not claimed', and PHASE-03-M4-THEME-EDITOR-PLAN.md:~300 measured 'neither first-party sortable has any keyboard path (keydown count 0 in both)'. INP is explicitly not measured (M2 §4.4).
- **Implementation:** themes/*, public/css/storefront-appearance.css
- **Evidence:** PHASE-03-M2-PREMIUM-STOREFRONTS.md §4.3–4.4; PHASE-03-M7-INTEGRATION.md §7
- **Gap:** Keyboard operation — an explicitly named original requirement — has no evidence of any kind on the storefront. Human accessibility review is listed as an outstanding owner item, which covers review but not the engineering requirement to DESIGN for keyboard operation.
- **Responsible:** phase 03 (unclosed) + owner (human review)
- **Next action:** Measure keyboard operability of the storefront (tab order, focus visibility, operable controls) and record it; treat the human a11y review as a separate owner item rather than the discharge of this requirement.

### `P03-ACC-01` — Acceptance criteria — evidence integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "No capture may be a redirect, a refusal toast, a reused file or a blank page claimed as a screen. The three Phase 2.5 integrity gates run unchanged over storefront evidence."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Acceptance criteria / Evidence`
- **Expected behaviour:** Integrity gates run over Phase 03 evidence and catch reused or refused captures.
- **Actual behaviour:** The gates exist and were run: check-distinct-surface-captures.py, detect-refusal-toast-captures.py and a new Phase 03 tool check-cross-store-captures.py, which PROJECT-STATE records as finding 28 collisions on the first Phase 03 evidence run (and records that the Phase 2.5 tool had hardcoded its own path and silently ignored arguments). M7's premise model is stricter still: a row counts only if the page is not the error page, the content area holds real text, dir agrees with the locale, the surface's own content marker is present and nothing was fetched off-origin — and the light/dark pair is compared AS A PAIR.
- **Implementation:** docs/development/phase-3/tools/
- **Evidence:** docs/development/phase-3/tools/check-cross-store-captures.py; PHASE-03-M2-PREMIUM-STOREFRONTS.md:314; PHASE-03-M7-INTEGRATION.md §3; PROJECT-STATE.md §Phase 03 tooling
- **Gap:** The gates ran over the evidence that exists; they cannot speak for the surface that was never captured (P03-E-03).
- **Responsible:** phase 03
- **Next action:** None.

### `P03-ACC-02` — Acceptance criteria — bilingual (OWNER-SUSPECTED GAP E)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Arabic and English both correct in logical and visual order; the inventory's measured gaps — 35 English keys with no Arabic row, 28 Arabic values identical to their key, 31 with no Arabic script — are closed or explicitly accepted with a reason."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Acceptance criteria / Bilingual (lines 266-270); the counts are from §Measured inventory line 95`
- **Expected behaviour:** Each of the three named gaps is either closed or dispositioned with a written reason.
- **Actual behaviour:** MEASURED BY ME TODAY against resources/lang/en.json and ar.json: en 4,111 keys, ar 4,107; English keys with no Arabic row = 35 (UNCHANGED); Arabic values byte-identical to their key = 29 (was 28 — one WORSE); Arabic values containing no Arabic-script character = 31 (UNCHANGED). None is closed. And none is dispositioned: grep for '35 English', '28 Arabic', 'no Arabic-script' across phase-3 docs and PROJECT-STATE returns only the two places in the plan that state the requirement. The only ratchet in place, TranslationFileIntegrityTest, bounds a DIFFERENT number — the 31 ar.json keys that en.json does not declare (the surplus), which M5 §9 correctly reports as unchanged. Phase 03 added ~380 new Arabic strings, so the file grew while the measured defects did not move.
- **Evidence:** Direct measurement of resources/lang/{en,ar}.json
- **Gap:** A named acceptance criterion with named numbers was neither met nor dispositioned, and the ratchet that exists measures something else, which makes the gap easy to mistake for closed.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Close the 35 missing Arabic rows and triage the 29 key-identical and 31 non-Arabic-script values; or write the acceptance with a reason per group and add a ratchet on those three numbers so they cannot drift again.

### `P03-ACC-03` — Acceptance criteria — correctness and isolation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "The three existing themes keep rendering for stores already configured against them, including the store whose theme_id is empty."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Acceptance criteria / Correctness and isolation`
- **Expected behaviour:** An empty theme_id store renders correctly, with a dir attribute and real stylesheets, in both languages.
- **Actual behaviour:** Closed in M0.4 and carried through every later capture set: the fixture store p3fixt-no-theme has its own capture directory in m1-storefront-baseline, storefront-baseline-2026-11-12b and m2e-all-stores, covering 7 routes x 2 locales x 3 viewports (x 2 appearances at M2-E). The original defect — no dir attribute and a themes//assets/css/rtl-main-style.css link that exists in no theme — was named in the prep inventory and the fixture exists specifically to hold it closed.
- **Implementation:** resources/views/front_end/layouts/app.blade.php; theme RTL assets
- **Evidence:** docs/development/phase-3/evidence/*/p3fixt-no-theme/ (three capture sets); PHASE-03-M0-STOREFRONT-BASELINE.md
- **Responsible:** phase 03
- **Next action:** None.

### `P03-ACC-04` — Acceptance criteria — honesty

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Known failures and skips reported as they are; no ratchet widened to force green."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Acceptance criteria / Honesty`
- **Expected behaviour:** The known-outcome manifest is not padded and ratchets are not relaxed.
- **Actual behaviour:** Honoured, and visibly so. Gates at close: Unit 1845/1 skipped, Contract 45, Feature 8,828 passed with 2 declared failures and 1 declared skip, known-outcome gate matching the manifest exactly with nothing added. When a ratchet fired during M7 (ResidualTenancyExposureTest's line-absolute raw-query census moved because M5/M6 added lines above getRelatedSlugs()), the census was re-read rather than renumbered and the move recorded. Every control in M5 and M6 was falsified by removal — 25 named falsifications, each re-run.
- **Implementation:** .github/known-test-outcomes.json (unchanged)
- **Evidence:** PHASE-03-FINAL-REPORT.md §7 and §8; PHASE-03-M5-OFFICIAL-WEBSITE.md §8 falsification table; PHASE-03-M6-DOCUMENTATION-CENTRE.md §6
- **Responsible:** phase 03
- **Next action:** None.

### `P03-ACC-05` — Acceptance criteria — honesty

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Anything blocked gets a disposition, not a silent omission."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Acceptance criteria / Honesty`
- **Expected behaviour:** Every incomplete item appears in an outstanding list with a disposition.
- **Actual behaviour:** Partly. PHASE-03-FINAL-REPORT.md §9 lists nine owner items and the deferred reorder UI, and §10 is unusually candid about what the numbers do not say. But several incomplete items appear in NO outstanding list: SEO in any form (P03-MP-01/02), product-detail and checkout captures (P03-A-03), the editor's missing four-combination capture (P03-E-03), the editor shell never moved onto the design system (P03-B-02), the reorder UI's WCAG 2.1.1 keyboard failure (P03-B-07), docs editorial ownership / version labels / release process (P03-D-04/05), the official site being unlinked from `/` (P03-C-02), content management for the official site (P03-C-05), and the three named Arabic translation gaps (P03-ACC-02). Phase 2.5 produced a BLOCKED-DISPOSITIONS document; Phase 03 produced none.
- **Evidence:** PHASE-03-FINAL-REPORT.md §9; absence of any phase-3 dispositions document (`ls docs/development/phase-3/`)
- **Gap:** At least nine incomplete items left Phase 03 with no disposition of any kind — the precise failure mode the owner's rule exists to prevent.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Produce a Phase 03 dispositions document carrying every row in this matrix that is not VERIFIED COMPLETE, each with a named disposition.

### `P03-B-01` — 03-B Controlled visual theme editor

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Provide a section-based visual editor with an explicit allowlist of configurable blocks, theme tokens and settings; no arbitrary executable HTML/JS and no bypass of existing permissions."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-B (line ~152)`
- **Expected behaviour:** Only allowlisted fields are editable; executable markup is refused; the editor cannot bypass permissions.
- **Actual behaviour:** Verified in code: app/Support/Theme/ThemeFieldCatalog.php derives the editable-field allowlist from the themes' own JSON; app/Support/Theme/ThemeSettingValidator.php refuses '<script\|iframe\|object\|embed\|applet\|form\|meta\|link\|base\|style\|svg' and the 'javascript:\|vbscript:' URL schemes with named reasons, and refuses unknown field types rather than allowing them. app/Support/Theme/ThemeFieldTree.php supplies page->section->field grouping and is pinned to editableFields() in both directions.
- **Implementation:** app/Support/Theme/ThemeFieldCatalog.php, ThemeSettingValidator.php, ThemeFieldTree.php
- **Evidence:** tests/Feature/Theme/ThemeFieldTreeTest.php, ThemeConfigurationServiceTest.php; M3-C allowlist derivation described in PHASE-03-M3-THEME-VERSIONING.md §9
- **Responsible:** phase 03
- **Next action:** None.

### `P03-B-02` — 03-B Controlled visual theme editor

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "A merchant-facing theme editor experience on the merchant web admin, bilingual AR (RTL) and EN (LTR)" with sections, preview, publish, rollback and permissions; M4-7 required "a four-combination capture (LTR/RTL x light/dark) with contrast checked".
- **Source:** `docs/development/phase-3/PHASE-03-M4-THEME-EDITOR-PLAN.md §1 (line 136-144) and §M4-7 (line 276)`
- **Expected behaviour:** The editor shell is rebuilt on the Store Syria Design System and proven to render correctly in all four combinations with contrast checked.
- **Actual behaviour:** The editor exists and is bilingual at the string level (M4-8, 151 Arabic strings, pinned by a test that walks the real theme JSON). But the shell was NOT moved onto the design system: resources/views/theme_customize/customize.blade.php still hard-codes '#f8f9fa' (line 27), '#e9ecef' (lines 33, 41) — the exact values M4-7 flagged — and carries only 3 'ss-' matches, which M4-7 measured as all breadcrumb. Only _version-panel.blade.php (10 matches) uses DS components. Admin dark mode is server-side via style-dark.css, so those hard-coded light values stay light. No four-combination capture of the editor exists anywhere in the repository.
- **Implementation:** app/Http/Controllers/ThemeCustomizeController.php, app/Http/Controllers/ThemeVersionController.php, resources/views/theme_customize/
- **Evidence:** None for rendering. grep of resources/views/theme_customize/*.blade.php for 'var(--ss\|ss-' and for the hard-coded hex values.
- **Gap:** The editor shell redesign named in M4-7 was not done, and the four-combination capture that would have revealed it was never produced. The M4 report (PHASE-03-FINAL-REPORT.md §2) describes M4 as 'the theme editor a merchant actually uses' without disclosing that the shell is still the M0-era Bootstrap markup with light-only literals.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Either move the editor shell onto the design system and capture it in four combinations with contrast checked, or record the shell as explicitly out of scope with an owner decision — and correct the M4 narrative either way.

### `P03-B-03` — 03-B Controlled visual theme editor

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Separate preview/draft from publish and preserve backwards compatibility for existing storefront configurations."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-B (line ~153)`
- **Expected behaviour:** A draft can be previewed without touching the live projection; shoppers keep seeing the published configuration; existing stores keep working.
- **Actual behaviour:** Verified: routes/web.php declares a separate 'theme.draft' middleware group for draft save/preview beside the 'theme.editor' management group; the preview renders through the real storefront handler (HomeController::storeSlug) via a request-scoped ThemePreviewContext bound 'scoped' not 'singleton'. Tests drive the real behaviours by name: test_the_preview_shows_the_draft_while_the_public_shop_still_shows_published, test_previewing_does_not_change_published_data, test_the_preview_response_is_not_cacheable, test_a_draft_does_not_survive_into_a_later_anonymous_request.
- **Implementation:** app/Support/Theme/ThemePreviewContext.php, routes/web.php:1542-1570, app/Http/Controllers/ThemeCustomizeController.php
- **Evidence:** tests/Feature/Theme/ThemeDraftPreviewRouteTest.php (10 named tests), ThemePreviewSeamTest.php
- **Responsible:** phase 03
- **Next action:** None.

### `P03-B-04` — 03-B Controlled visual theme editor

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Enforce store ownership and policy checks on every editor, preview and publish API." Acceptance criterion: "Every editor, preview, publish and rollback path carries a negative cross-tenant test, including the null/unresolved store case."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-B (line ~154) and §Acceptance criteria / Correctness and isolation`
- **Owner decisions:** OWNER DECISION, 2026-11-15, quoted in PHASE-03-M4-THEME-EDITOR-PLAN.md §OWNER DECISION: publish and rollback require Manage Themes; 'A global permission alone must NEVER authorize access to another tenant'; 'Do not infer tenant ownership from type=admin alone'; no separate Publish Theme permission; one boundary on all eight routes, both verbs.
- **Expected behaviour:** One boundary on all theme routes, both verbs; a global permission never authorizes another tenant; the operator never falls back to store 1.
- **Actual behaviour:** Verified in routes/web.php:1513-1570 — eight theme routes inside the 'theme.editor' group plus a 'theme.draft' membership group. ThemeEditorGate resolves the store through the gate, never getCurrentStore(). The matrix test drives the owner, same-tenant permission holder, operator-with-named-store, zero-permission staff, another tenant's permission holder, another tenant's merchant, a demoted merchant, the operator with no store, and the unauthenticated caller, and names test_a_global_permission_never_authorizes_another_tenants_store.
- **Implementation:** app/Support/Theme/ThemeEditorGate.php, app/Http/Middleware (theme.editor / theme.draft), routes/web.php:1513-1570
- **Evidence:** tests/Feature/Theme/ThemeEditorAuthorizationMatrixTest.php (14 named tests), ThemeEditorGateTest.php, ThemeDraftPreviewRouteTest.php cross-tenant cases, tests/Feature/Platform/NullTenantGuardCanaryTest.php
- **Gap:** Minor: `theme-customize/file-get` is inside the gated group but no test drives that URI directly (grep of tests/ for 'file-get' returns no match). The boundary is the group, so it is covered by construction rather than by a named test.
- **Responsible:** phase 03
- **Next action:** Add one direct test for theme.file.get so the guard cannot go missing if the route is later moved out of the group.

### `P03-B-05` — 03-B Controlled visual theme editor

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Version configuration safely and provide rollback for drafts/published themes without mutating product or checkout business data."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-B (line ~155)`
- **Expected behaviour:** Atomic publish with conflict detection, readable history and a working rollback that touches no commerce data.
- **Actual behaviour:** Four additive migrations with engine-enforced integrity (including a composite key making cross-tenant publication impossible), a canonical payload hash, publish/rollback/history over HTTP, lazy capture of a store's first version on first editor open or draft save (no backfill, which the owner reserves), optimistic conflict detection with the expected-live-version carried in the form, and a refusal audit. Rollback proved end to end over HTTP, including that it appends a publication event. Six two-process races proved on MariaDB via tests/Support/Security/bin.
- **Implementation:** app/Services/Theme/ThemePublishingService.php, app/Support/Theme/ThemePayload.php, database/migrations/2026_11_13_* and 2026_11_15_000100_*
- **Evidence:** tests/Feature/Theme/ThemePublishRoutesTest.php (rollback restores the baseline and appends an event, lines ~301-340), ThemePublishingServiceTest.php, ThemeBaselineVersionTest.php, ThemeWriteUnderCallerTransactionTest.php; MariaDB concurrency harness described in PHASE-03-M3-THEME-VERSIONING.md §9-§12
- **Gap:** Version snapshots cover SETTINGS ONLY — section ordering is not in a snapshot, so publish and rollback cannot restore an ordering (measured and stated at PHASE-03-M4-THEME-EDITOR-PLAN.md §M4-9 point 2). That is a real limit of 'version configuration', recorded here rather than left implicit.
- **Responsible:** phase 03
- **Next action:** Record the settings-only scope of a version as a known limit on the rollback contract, and fold ordering into the snapshot if and when P03-B-06 is closed.

### `P03-B-06` — 03-B Controlled visual theme editor (OWNER-SUSPECTED GAP A)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Section ordering is part of a "section-based visual editor": the merchant reorders a page's sections and the storefront renders them in that order. The M4 plan states the test of it directly — M4-9 point 1: "Nothing on the storefront consumes saved ordering (verified exit 1)."
- **Source:** `docs/development/phase-3/PHASE-03-M4-THEME-EDITOR-PLAN.md §M4-9 (lines ~290-305); PHASE-03-EXECUTION-PREP.md §03-B; PHASE-03-FINAL-REPORT.md §9 ("the M4 reorder UI \| deferred")`
- **Owner decisions:** None found. The only Phase 03 owner decision on record (2026-11-15) concerns the theme-route authorization model, not ordering.
- **Expected behaviour:** A saved section order changes what a shopper sees on the storefront.
- **Actual behaviour:** CONFIRMED BY CODE, NOT BY THE REPORT. The order can be saved and is validated: ThemeCustomizeController::updateOrder() (line 592) writes theme_customize_orders keyed on (store_id, theme_id, page_slug) after validating page_slug and the tokens against the theme. But NOTHING READS IT BACK FOR RENDERING. ThemeCustomizeOrder::resolveFor() has exactly ONE caller in the entire application — ThemeCustomizeController.php:559, inside changeOrder(), i.e. the editor screen itself redisplaying what was saved. grep for ThemeCustomizeOrder/resolveFor/theme_customize_orders across app/, themes/ and resources/ finds no storefront consumer; HomeController does not reference it. I read themes/stylique/views/main_file.blade.php directly: the home page is a FIXED sequence of hard-coded <section> blocks each gated only by `$themeSettings['<section>_status'] == 1` — slider, logo, ... blog, service — with no ordering variable anywhere. Three copies, one per theme. So a merchant drags sections, gets 'Order change successfully.' and the shop renders unchanged.
- **Implementation:** Partial only: app/Http/Controllers/ThemeCustomizeController.php:592-660 (write), app/Models/ThemeCustomizeOrder.php (resolveFor), resources/views/theme_customize/order.blade.php (jQuery UI sortable UI)
- **Evidence:** None — and an order value CAN be saved, which is exactly the false signal the owner warned about.
- **Gap:** The feature is non-functional end to end. PHASE-03-FINAL-REPORT.md §9 lists 'the M4 reorder UI \| deferred; waits on the storefront consuming saved ordering (Phase 04)' — an ENGINEERING deferral with no owner decision cited anywhere, so it is not OWNER-APPROVED DEFERRED. Worse, the landing place named for it does not exist: grep of docs/development/phase-4/*.md for 'reorder', 'section ordering' or 'theme_customize_orders' returns nothing relevant, and Phase 04 is closed. The deferral fell through.
- **Responsible:** owner decision required; then phase 03 or a named later phase
- **Next action:** Raise this to the owner as an open Phase 03 requirement with no owner deferral and no receiving phase; decide whether to make the three main_file.blade.php templates consume ThemeCustomizeOrder (and add ordering to the version snapshot) or to withdraw the reorder screen from the product so it stops promising an effect it does not have.

### `P03-B-07` — 03-B Controlled visual theme editor

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "...accessibility, keyboard operation..." applied to the editor's reorder control. M4-9 measured the defect: "neither first-party sortable has any keyboard path (keydown count 0 in both), a WCAG 2.1.1 and 2.5.7 failure for any reorder UI shipped as-is."
- **Source:** `docs/development/phase-3/PHASE-03-M4-THEME-EDITOR-PLAN.md §M4-9 (line ~303); PHASE-03-EXECUTION-PREP.md §03-A/03-E accessibility criteria`
- **Expected behaviour:** The reorder control is operable by keyboard, or it is not shipped.
- **Actual behaviour:** Measured now: `grep -c keydown resources/views/theme_customize/order.blade.php` returns 0. The screen ships a jQuery UI `.sortable()` with `disableSelection()` and no keyboard path, reachable at the named route theme.customize.order. M4-9 fixed the third-party jQuery UI load and the input validation but did not address the keyboard failure, and the final report does not mention it.
- **Implementation:** resources/views/theme_customize/order.blade.php:88-116
- **Gap:** A WCAG 2.1.1 (keyboard) failure ships on an authenticated merchant screen, measured by the project itself and then not carried into any outstanding list. It is absent from PHASE-03-FINAL-REPORT.md §9.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Add it to the Phase 03 outstanding list explicitly; fix with a keyboard reordering path or remove the control alongside the P03-B-06 decision.

### `P03-C-01` — 03-C Official website (OWNER-SUSPECTED GAP B)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Build on the existing WorkDo LandingPage foundation for the official Store Syria site; avoid a second landing-page engine." Directive fragment quoted in M5: "Build the official company/platform website using the existing WorkDo LandingPage foundation. Do not create a second unrelated landing-page engine..."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-C (line ~158); PHASE-03-M5-OFFICIAL-WEBSITE.md §1 (lines 10-13)`
- **Owner decisions:** None found for this deviation.
- **Expected behaviour:** The official site is built ON the LandingPage foundation — reusing its pages, settings or content model — rather than beside it.
- **Actual behaviour:** WHERE IT SATISFIES THE REQUIREMENT: nothing was replaced or duplicated as an ENGINE — routes/web.php:430-460 adds a plain prefixed group, no block system, no section builder, no second configurable-page mechanism; `/` still routes to HomeController::Landing which returns view('landing-page::layouts.landingpage'). WHERE IT DEVIATES: the seven pages are first-party Blade views (resources/views/platform/*.blade.php) served by a first-party controller; they consume no LandingPage entity, setting or view. M5 §2 gives the measured reason (no PSR-4 entry for Workdo\, so class_exists on a LandingPage entity is false until loadAddons() runs, and landing-pages/{slug} stores HTML in a row needing a production seeder). The reasoning is sound and documented, but it is a DEVIATION from the stated direction, not a fulfilment of it — M5 §2 frames it as 'What is kept from the foundation: / is untouched', which is leaving the foundation alone rather than building on it. No owner decision approving the deviation is recorded.
- **Implementation:** routes/web.php:430-460, app/Http/Controllers/PlatformSiteController.php, resources/views/platform/, public/css/store-syria-platform.css
- **Evidence:** tests/Feature/Platform/PlatformSiteRoutesTest.php (route precedence and controller identity), ReservedStoreSlugTest.php
- **Gap:** The architectural deviation was decided and documented by engineering, not approved by the owner. There is no Phase 03 decision register in which such an approval could live.
- **Responsible:** owner decision required
- **Next action:** Put the deviation to the owner explicitly — first-party code-owned pages instead of LandingPage content — with M5 §2's measurements as the rationale, and record the ruling.

### `P03-C-02` — 03-C Official website (OWNER-SUSPECTED GAP B)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Build the official company/platform website" — i.e. the site a visitor to Store Syria actually reaches.
- **Source:** `docs/development/phase-3/PHASE-03-M5-OFFICIAL-WEBSITE.md §1 (owner directive fragment); PHASE-03-EXECUTION-PREP.md §03-C`
- **Expected behaviour:** A visitor arriving at the platform's front door sees the official Store Syria site.
- **Actual behaviour:** NOT the case today, measured. `display_landing` defaults to 'on' (app/Models/Utility.php:106, database/seeders/DefaultSetting.php:28), so `/` renders the vendored WorkDo LandingPage module (HomeController::Landing, line 124-126). The new official site lives only under /platform, and NOTHING LINKS TO IT: grep of resources/views/ for 'platform.home' / 'platform.features' / 'platform.pricing' / 'platform.docs' outside resources/views/platform/ returns zero hits, and no link exists in the LandingPage module views either. The official site is an unreferenced island reachable only by typing the URL.
- **Implementation:** routes/web.php:430-460; app/Http/Controllers/HomeController.php:43,124-126
- **Gap:** Seven well-built, well-tested pages that no visitor path reaches. Neither the M5 document nor the final report records this; M5 §11 lists outstanding items and this is not among them.
- **Responsible:** owner decision required; then phase 03
- **Next action:** Decide and implement the front door — either /platform becomes the site `/` serves, or the LandingPage content links to it — and record the decision.

### `P03-C-03` — 03-C Official website

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Include the approved bilingual marketing, product explanations, merchant onboarding, pricing representation from canonical plan data, help/contact and privacy/legal links as approved."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-C (line ~159)`
- **Owner decisions:** Owner restriction reserving 'unapproved changes to legal or commercial policy' is cited in M5 §6 as the reason legal copy is a placeholder — a previously approved boundary, correctly applied.
- **Expected behaviour:** All six content areas present, bilingual, with prices read from the plans table and legal/contact content as approved by the owner.
- **Actual behaviour:** Marketing, product explanations, onboarding and pricing are delivered and tested: PlatformSiteController::publishedPlans() reads the plans table, sorts in PHP because price is a string column, filters on the inverted is_disable flag, and no price literal appears in any view; -1 is handled as the unlimited sentinel. Bilingual assertions are written as 'English must be ABSENT'. Contact and legal are deliberately empty: contact links contactus_url where set and otherwise states details are being confirmed; legal gives five documents a stable address and says none is published. Both are recorded as owner content items.
- **Implementation:** app/Http/Controllers/PlatformSiteController.php, resources/views/platform/{pricing,contact,legal}.blade.php
- **Evidence:** tests/Feature/Platform/PlatformPricingTest.php (10), PlatformSiteRoutesTest.php (11), PlatformSiteBilingualTest.php (11); falsification table in PHASE-03-M5-OFFICIAL-WEBSITE.md §8
- **Gap:** The 'as approved' half is not satisfied: published contact details and approved legal text for five documents do not exist. These are legitimately owner content items (the owner's own restrictions reserve legal and commercial policy), but the requirement is not discharged until they land.
- **Responsible:** owner content / legal
- **Next action:** Owner to supply published contact details and approved legal text; until then the pages ship as honest placeholders.

### `P03-C-04` — 03-C Official website

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Content should not promise unsupported functionality or bypass plan/tenant permissions."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-C (line ~160)`
- **Expected behaviour:** Every capability claim is anchored to something that really exists, and the public page is no wider than a signed-in merchant's view.
- **Actual behaviour:** 34 claims live in App\Support\Platform\PlatformCapabilities; a module-backed claim renders only while packages/workdo/<Module> is on disk; theme names are read from themes/. The independent review found and fixed a real widening — publishedPlans() originally ignored the inverted plans.is_disable flag, so the public pricing page advertised plans the operator had switched off, a strictly wider view than a merchant gets.
- **Implementation:** app/Support/Platform/PlatformCapabilities.php, app/Http/Controllers/PlatformSiteController.php
- **Evidence:** tests/Unit/Platform/PlatformCapabilitiesTest.php (10); PlatformSiteRoutesTest::the_features_page_renders_the_filtered_registry_and_nothing_else; the F2 finding and its two tests, PHASE-03-M5-OFFICIAL-WEBSITE.md §10
- **Gap:** One disclosed weakness, carried forward honestly by the team: the page-level 'filtered-out titles must not appear' loop currently has $filtered === 0 and so executes zero assertions; the registry-to-view link rests on the unit test.
- **Responsible:** phase 03
- **Next action:** None required now; the disclosed loop becomes evidence the day a named module is removed.

### `P03-C-05` — 03-C Official website (OWNER-SUSPECTED GAP B — content management)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Implied by "Build on the existing WorkDo LandingPage foundation": the LandingPage module is an operator-editable content builder, so the official site was to be manageable without a code change. No requirement anywhere formally excludes content management.
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-C; PHASE-03-M5-OFFICIAL-WEBSITE.md §2 (the trade-off is described but never excluded)`
- **Owner decisions:** None found.
- **Expected behaviour:** The operator can change the official site's marketing copy, pricing narrative, FAQ entries and legal text through the product.
- **Actual behaviour:** Not possible. All seven pages are Blade templates under resources/views/platform/ with literal copy wrapped in __(); the only dynamic data is the plans table and the capability registry. Changing a headline, an FAQ answer or the legal text requires a code change, review, merge and deploy. There is no admin screen for platform content — grep finds no controller or route for editing it. M5 §2 argues the trade-off (git-diffable, testable, no production seeder) but does not record it as an accepted exclusion, and no owner decision exists.
- **Implementation:** resources/views/platform/*.blade.php (literal copy)
- **Gap:** The official website has no content-management capability and the loss is undocumented as a decision. Note the knock-on: the five legal documents and the contact details, which the owner must supply, can only be published by a developer.
- **Responsible:** owner decision required
- **Next action:** Record this as a formal owner decision — accept code-owned marketing copy, or schedule a managed-content path — and name where legal text will be published from.

### `P03-D-01` — 03-D Documentation centre

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** "Build bilingual, searchable, discoverable docs with categories..."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-D (line ~163)`
- **Expected behaviour:** A bilingual documentation centre with categories, working search and navigation.
- **Actual behaviour:** Verified: resources/docs/{en,ar}/ holds 5 categories and 16 articles each (42 files with _category.md), served at /platform/docs with index, category, article and search routes. Search is PHP over a cached index with eleven Arabic folding rules (alef variants, alef maqsura, ta marbuta, hamza carriers, tashkeel, tatweel, Arabic-Indic digits), each its own test, plus an opposite-direction test that a constant-returning normaliser would fail.
- **Implementation:** app/Support/Docs/{DocumentationLibrary,DocumentationSearch,DocumentationRenderer}.php, app/Http/Controllers/PlatformDocsController.php, resources/views/platform/docs/
- **Evidence:** tests/Unit/Platform/DocumentationSearchTest.php (23), DocumentationLibraryTest.php (9), tests/Feature/Platform/PlatformDocsRoutesTest.php (12), PlatformDocsBilingualTest.php (7); M7 captures docs-index/category/article/search in 4 combinations
- **Gap:** Discoverability from outside the platform site is bounded by P03-C-02 — nothing outside resources/views/platform/ links to /platform/docs.
- **Responsible:** phase 03
- **Next action:** None for the centre itself; see P03-C-02 for reachability.

### `P03-D-02` — 03-D Documentation centre (OWNER-SUSPECTED GAP C)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "...with categories, role-based how-tos, onboarding, troubleshooting and merchant/employee workflows."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-D (line ~163)`
- **Expected behaviour:** Five named content types present: categories, role-based how-tos, onboarding, TROUBLESHOOTING, and merchant/employee workflows.
- **Actual behaviour:** Measured corpus: 01-getting-started (3 articles), 02-your-storefront (4), 03-products (4), 04-orders (2), 05-your-account (3). Onboarding is covered by 01-getting-started. Employee workflow is covered thinly by 05-your-account/01-staff-and-what-they-can-do.md. THERE IS NO TROUBLESHOOTING CATEGORY OR ARTICLE, and there are no role-separated tracks — every article is written for the merchant/owner voice. M6 §7 lists 'more articles (payments per provider, shipping zones, reports)' as a later phase but does not name troubleshooting or role-based how-tos.
- **Implementation:** resources/docs/en/, resources/docs/ar/
- **Evidence:** `find resources/docs/en -name '*.md'` — 16 articles, listed above
- **Gap:** Troubleshooting is entirely absent; role-based how-tos are absent as a structure. Neither is recorded as deferred or accepted.
- **Responsible:** phase 03 (unclosed) or owner decision
- **Next action:** Either add a troubleshooting category and role-separated how-tos, or get an owner decision narrowing the original 03-D content list and record it.

### `P03-D-03` — 03-D Documentation centre

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Preserve correct Arabic typography, RTL layout, navigation, code samples and English LTR reading direction."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-D (line ~164)`
- **Expected behaviour:** Arabic typography and RTL, navigation, correctly-rendered code samples, and English LTR all correct.
- **Actual behaviour:** RTL/LTR and navigation are proved by M7's 44 loads (dir agrees with locale in every row; an untranslated article is served in English with lang='en' dir='ltr' and a visible notice, driven against a real gap the test creates and removes). CODE SAMPLES ARE UNEXERCISED: `grep -rln '```' resources/docs/` returns no file — the corpus contains zero code blocks, so bidirectional code rendering has never been exercised in either language. Arabic typography correctness is a native-speaker judgement that has not been made; M6 §7 lists 'native Arabic review of the 16 translated articles and the 14 new UI strings' as outstanding.
- **Implementation:** app/Support/Docs/DocumentationRenderer.php, resources/views/platform/docs/article.blade.php
- **Evidence:** docs/development/phase-3/evidence/m7-platform-2026-11-15/docs-article--{en,ar}--{light,dark}.png; PlatformDocsBilingualTest.php
- **Gap:** Code-sample rendering is untested because no article contains one; native Arabic typography review is outstanding (correctly recorded as an owner item).
- **Responsible:** phase 03 (code samples) + owner (Arabic review)
- **Next action:** Add one article containing a code block (or a renderer test over a fixture) so LTR code inside an RTL page is proved; owner to commission the native Arabic review.

### `P03-D-04` — 03-D Documentation centre (OWNER-SUSPECTED GAP C)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Establish editorial ownership... and a content release process."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-D (line ~165)`
- **Owner decisions:** None found.
- **Expected behaviour:** A named owner for documentation content and a defined process by which an article is written, reviewed, approved and published.
- **Actual behaviour:** NOT ESTABLISHED. The phrase 'editorial ownership' / 'content release process' appears exactly once in the repository — in the plan line itself (PHASE-03-EXECUTION-PREP.md:160). grep across docs/development/phase-3/, resources/docs/ and app/Support/Docs/ for 'editorial', 'content release', 'publication process' returns nothing else. Front matter supports only title, order, summary and related (DocumentationLibrary.php:304-316, parser is strict and ignores unknown keys), so there is no author, owner, reviewer or status field. De facto the process is 'a developer edits a Markdown file and it ships with the next deploy'.
- **Gap:** This is the distinction the owner asked for: working documentation exists; an editorial workflow does not. Neither M6 §7 nor the final report records this as outstanding, so it was dropped silently.
- **Responsible:** owner (ownership) + phase 03 (process doc)
- **Next action:** Name a documentation owner and write down the release process (who drafts, who reviews Arabic, who approves, how a change reaches production); add the supporting front-matter fields if the process needs them.

### `P03-D-05` — 03-D Documentation centre (OWNER-SUSPECTED GAP C)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Establish... version labels..."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-D (line ~165)`
- **Expected behaviour:** Each article carries a version or last-reviewed label so a reader knows whether it still describes the shipped product.
- **Actual behaviour:** NOT IMPLEMENTED. I read the front matter of resources/docs/en/01-getting-started/*.md and the category files: the keys are title, order, summary, related only. DocumentationLibrary's parser accepts no version, date, author or status key. grep of resources/views/platform/docs/*.blade.php for 'version', 'updated', 'last reviewed' returns nothing — no label is rendered anywhere.
- **Gap:** A reader cannot tell how old an article is or which release it describes. Not recorded as outstanding anywhere.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Add a version or last-reviewed field to front matter, render it, and pin it with a test that every article carries one.

### `P03-D-06` — 03-D Documentation centre

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Establish... link checking..."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-D (line ~165)`
- **Expected behaviour:** Documentation links are checked, so a renamed article or a typo does not leave a dead link.
- **Actual behaviour:** Partly covered, and honestly scoped. DocumentationLibraryTest::every_related_id_names_a_real_article walks the whole corpus in both locales and fails on any dangling related id — and it runs in the Unit suite, which is a CI gate. platform-site-journeys.cjs crawls every internal href on the eleven public surfaces in both languages and resolves every in-page anchor (64 links / 32 anchors, 0 broken), but it is a manual tool — grep of .github/workflows/ci.yml finds no reference to it — and it covers one article per locale, not all 16. There is no checker for Markdown body links, and none is needed today: `grep -rn '](' resources/docs/` returns zero matches, so the corpus contains no body links at all.
- **Implementation:** app/Support/Docs/DocumentationLibrary.php::related()
- **Evidence:** tests/Unit/Platform/DocumentationLibraryTest.php::every_related_id_names_a_real_article; docs/development/phase-3/tools/platform-site-journeys.cjs lines 153-221; evidence/m7-platform-2026-11-15/journeys.json
- **Gap:** The only link class present in the corpus is checked in CI; the browser crawl that would catch the rest is manual and samples one article per locale. If a body link is ever added, nothing checks it.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Either run the journey crawl in CI or add a corpus-wide Markdown link assertion, so link checking is a standing control rather than a one-off run.

### `P03-D-07` — 03-D Documentation centre

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Establish... accessibility..." for the documentation centre.
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-D (line ~165)`
- **Expected behaviour:** The docs centre is accessible, verified to the project's standard.
- **Actual behaviour:** axe reports 0 WCAG A/AA and 0 best-practice violation nodes across the four docs surfaces in four combinations, and a real defect was found and fixed by rendering (landmark-complementary-is-top-level, 12 nodes — the sidebar <aside> inside <main> became a <div>). The team states the limit itself: PHASE-03-M7-INTEGRATION.md §7, 'Zero axe violations is not an accessibility pass. axe automates roughly a third of WCAG.' No human review has happened.
- **Implementation:** resources/views/platform/docs/_aside.blade.php and layout
- **Evidence:** docs/development/phase-3/evidence/m7-platform-2026-11-15/a11y.json; PHASE-03-M7-INTEGRATION.md §4C
- **Gap:** Automated-only. Keyboard order, focus management and label quality unmeasured, as the project itself states.
- **Responsible:** owner
- **Next action:** Owner-commissioned human accessibility review, which is already on the outstanding list (PHASE-03-FINAL-REPORT.md §9).

### `P03-E-01` — 03-E Shared design and quality

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Reuse the Phase 2.5 approved design-system components, existing page routes and test/screenshot harnesses."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-E (line ~169)`
- **Expected behaviour:** New and touched surfaces use the Store Syria Design System rather than new one-off styling.
- **Actual behaviour:** Satisfied for the public surfaces: public/css/store-syria-platform.css is additive over store-syria-ds.css and PlatformSiteAssetsAreLocalTest fails on any literal colour or any token the DS does not define; the storefront themes were tokenised in M2 and are pinned by StorefrontDarkModeLayerTest / StorefrontAccessibleNamesTest. NOT satisfied for the merchant theme editor: resources/views/theme_customize/customize.blade.php still hard-codes #f8f9fa, #e9ecef and keeps its own pushed <style>, with 3 'ss-' matches (M4-7 measured those as breadcrumb only).
- **Implementation:** public/css/store-syria-platform.css, public/css/storefront-appearance.css
- **Evidence:** tests/Unit/Ui/PlatformSiteAssetsAreLocalTest.php; tests/Unit/Ui/StorefrontDarkModeLayerTest
- **Gap:** The theme editor — the main new merchant surface of Phase 03 — was not brought onto the design system. See P03-B-02.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Fold the editor shell into the P03-B-02 remediation.

### `P03-E-02` — 03-E Shared design and quality (OWNER-SUSPECTED GAP E)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Set measurable targets for LCP/CLS/INP, accessibility and rendering correctness after baseline measurements; do not invent pass thresholds as existing facts." Acceptance: "Performance targets are set after M1's baseline. A target invented before a measurement is a wish."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-E (line ~170) and §Acceptance criteria / Evidence`
- **Expected behaviour:** After M1, the project writes down its own LCP, CLS and INP targets and judges later work against them.
- **Actual behaviour:** Baselines were measured: M2's verify-performance-a11y.cjs over 80 loads (LCP p75 380–660 ms, CLS p75 0.077–0.084) and M7's 44 loads (worst lab LCP 224 ms, worst CLS 0.0234). But NO PROJECT TARGETS WERE EVER SET — grep for 'target' across M1, M2, M7 and the final report returns only unrelated uses (link targets). M2 §4.4 judges against Google's generic 'good' thresholds ('0 outside the "good" thresholds'), which is a borrowed threshold, not a target set after the baseline. INP IS NOT MEASURED AT ALL and is declared unmeasurable in this setup (M2 §4.4, M7 §7). All numbers are lab figures from `artisan serve` on localhost against SQLite — stated plainly by the team each time.
- **Implementation:** docs/development/phase-3/tools/verify-performance-a11y.cjs, platform-site-journeys.cjs
- **Evidence:** PHASE-03-M2-PREMIUM-STOREFRONTS.md §4.4 table; evidence/m7-platform-2026-11-15/journeys.json vitals
- **Gap:** The target-setting step the acceptance criterion names did not happen, so there is nothing for Phase 04+ to regress against beyond 'the numbers last time'. INP has no measurement and no stated plan.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Write the Phase 03 performance targets down against the measured baselines, and state explicitly whether INP is deferred to field instrumentation (and in which phase).

### `P03-E-03` — 03-E Shared design and quality (OWNER-SUSPECTED GAP D)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Run actual browser captures for Arabic/English, RTL/LTR and relevant viewports, plus route-auth, tenant boundary and regression tests." Acceptance: "No capture may be a redirect, a refusal toast, a reused file or a blank page claimed as a screen."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-E (line ~171) and §Acceptance criteria / Evidence`
- **Owner decisions:** None — no owner decision waives the editor capture.
- **Expected behaviour:** Every Phase 03 surface, including the merchant theme editor, has real authenticated browser captures in the agreed combinations.
- **Actual behaviour:** CONFIRMED GAP. Storefront: 160-load cross-store matrix. Public platform site and docs: 44 loads, 11 surfaces x {en,ar} x {light,dark}, listed file-by-file in evidence/m7-platform-2026-11-15/ (44 PNGs — home, features, pricing, onboarding, faq, contact, legal, docs-index, docs-category, docs-article, docs-search). THE THEME EDITOR IS IN NEITHER SET. A repository-wide search for a PNG whose name contains theme/editor/customize finds only vendored WorkDo module assets. The only /theme-customize capture that exists anywhere is Phase 2.5's: evidence/web-blocked-2026-11-10/after/render-matrix.json records every row as title 'Store Syria - Dashboard', h1 'Dashboard', premiseFail 'landed on /dashboard, asked for /theme-customize (redirect — likely a permission refusal)' — four pictures of the dashboard, correctly flagged. M4's own plan required the capture (§M4-7, 'a four-combination capture (LTR/RTL x light/dark) with contrast checked') and warned against exactly this trap (§M4-7, 'The four _theme_customize__*.png ... are pictures of the dashboard ... Any capture must assert the landed path and the h1'). It was never produced.
- **Evidence:** Storefront and platform only: evidence/m2e-all-stores-2026-11-12/, evidence/m7-platform-2026-11-15/. NOTHING for the editor.
- **Gap:** The central new merchant surface of Phase 03 has zero rendered evidence, authenticated or otherwise, in any language, direction or appearance. This is why P03-B-02's light-only hard-coded colours went unnoticed.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Capture the editor (index, pages, section editor, order screen, version panel) as an authenticated merchant holding Manage Themes, in AR/EN x light/dark, asserting the landed path and the h1 so a redirect cannot pass; contrast-check the result.

### `P03-E-04` — 03-E Shared design and quality

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Ensure plan gating, storefront session behavior and existing merchant-to-store integration stay intact."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Workstreams / 03-E (line ~172)`
- **Expected behaviour:** Phase 03 does not break plan entitlements, storefront sessions or the merchant-to-store relationship.
- **Actual behaviour:** Plan gating of themes is preserved in code: ThemeCustomizeController::index() reads Plan::find($user->plan_id) and restricts the theme list to $plan->themes, falling back to the three shipped themes when empty (app/Models/Plan.php exposes 'themes' in $fillable and splits the CSV). Storefront session behaviour and merchant-to-store integration are indirectly covered by the Feature suite remaining at its declared outcome set (8,828 passed, 2 declared failures, 1 declared skip, known-outcome gate matching exactly). I found no Phase 03 test that asserts plan gating of themes specifically.
- **Implementation:** app/Http/Controllers/ThemeCustomizeController.php::index(), app/Models/Plan.php
- **Evidence:** Known-outcome gate result quoted in PHASE-03-FINAL-REPORT.md §7; no targeted plan-gating test found
- **Gap:** No direct test pins that a plan's themes list still constrains what the editor offers, even though M4 rewrote the surrounding authorization.
- **Responsible:** phase 03
- **Next action:** Add one test asserting a plan-restricted merchant is offered only their plan's themes in the editor.

### `P03-M0-01` — M0 Prerequisites gate

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "The three decisions above are recorded." The three: (1) storefront dark mode in or out; (2) does the official website reuse the theme editor's publish machinery; (3) what indexes the documentation search. The plan says they "are not engineering choices and should be taken before the first commit, not during 03-A".
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Milestones M0 and §Dependency map (lines 196-215)`
- **Owner decisions:** Dark-mode storage shape: 'as the owner approved' (M2 §opening). Nothing comparable for decisions 2 and 3.
- **Expected behaviour:** Three decisions, taken by the owner before the first commit and recorded.
- **Actual behaviour:** All three were resolved, but during execution and largely by engineering. (1) Dark mode: built in M2 across all three themes; M2 line 12 says the per-viewer, per-store storage is 'as the owner approved', which is the only owner trace on any of the three. (2) Publish-machinery reuse: answered implicitly by M5 building fixed first-party pages; no decision is recorded as such. (3) Search: answered in M6 (PHP over a cached index, with the measured argument that a LIKE query would be worse for Arabic). There is no Phase 03 decision register, so none of the three has a dated decision record.
- **Evidence:** PHASE-03-M2-PREMIUM-STOREFRONTS.md:12; PHASE-03-M5-OFFICIAL-WEBSITE.md §2; PHASE-03-M6-DOCUMENTATION-CENTRE.md §2
- **Gap:** M0's done-when required the decisions recorded; two of three have only an engineering rationale inside a milestone document. The absence of a Phase 03 decision register is the structural cause.
- **Responsible:** phase 03 (unclosed)
- **Next action:** Open a Phase 03 decision register retrospectively and record all three with their actual basis, flagging which were engineering calls the owner has not ruled on.

### `P03-M7-01` — M7 Phase 03 closeout

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Matrix, evidence, suites and an acceptance package in the Phase 2.5 shape. Release readiness assessed separately, as always."
- **Source:** `docs/development/phase-3/PHASE-03-EXECUTION-PREP.md §Milestones, M7 row (line 235)`
- **Expected behaviour:** A closeout package shaped like Phase 2.5's: a coverage matrix, an evidence index, suite results, blocked dispositions, and an owner-facing acceptance report.
- **Actual behaviour:** Evidence and suites are delivered (three harnesses, 44 loads, full gate results). The rest is not. `ls docs/development/phase-3/` holds ten documents and three directories — no coverage matrix, no dispositions document, no decision register, no acceptance report. Phase 2.5 by contrast ships PHASE-2.5-COVERAGE-MATRIX.md, PHASE-2.5-BLOCKED-DISPOSITIONS.md, PHASE-2.5-DECISION-REGISTER.md, PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md and PHASE-2.5-ACCEPTANCE-READINESS-AR.md. No owner visual acceptance has occurred for any Phase 03 surface.
- **Implementation:** docs/development/phase-3/PHASE-03-FINAL-REPORT.md
- **Evidence:** `ls docs/development/phase-3/` vs `ls docs/development/phase-2.5/`
- **Gap:** The milestone was marked engineering complete without the acceptance package its own done-when names. That is how the gaps in this matrix left the phase unrecorded.
- **Responsible:** phase 03 (unclosed) + owner (acceptance)
- **Next action:** Build the Phase 03 acceptance package in the Phase 2.5 shape — coverage matrix, dispositions, decision register, owner-facing acceptance report — before Phase 03 is presented for merge.

### `P03-MP-01` — Master Plan scope (OWNER-SUSPECTED GAP E)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Phase 03 scope per the approved six-phase Master Product Plan: "themes, visual editor, shopper experience, checkout UX, SEO, plus a separately scoped Official Website and a bilingual Documentation Center."
- **Source:** `CLAUDE.md §4, Phase 03 row (quoting the owner-approved Master Product Plan / Decision Register v1.0, 2026-10-14 — the plan itself is not in the repo)`
- **Owner decisions:** None — no owner decision removes SEO from Phase 03.
- **Expected behaviour:** SEO is delivered for Phase 03's surfaces — at minimum canonical URLs, per-page titles and descriptions, a sitemap, a meaningful robots policy and bilingual hreflang.
- **Actual behaviour:** SEO WAS DROPPED FROM THE PLAN AND NEVER TRACKED. A case-insensitive grep for 'seo\|sitemap\|robots\|canonical\|structured data\|meta description' across docs/development/phase-3/*.md returns only incidental matches on the word 'canonical' used in other senses ('canonical plan data', 'canonical payload'). It is in none of workstreams 03-A..03-E, none of M0–M7, and not in the final report. Measured in code: no sitemap route anywhere (grep of routes/ and app/Http/Controllers/); public/robots.txt is the vendored allow-all 'User-agent: * / Disallow:'; no rel=canonical, no hreflang, no application/ld+json or schema.org markup in any view under resources/views/ or themes/. The M5/M6 platform pages do carry a per-page meta description and robots index,follow, which is partial and first-party.
- **Implementation:** Partial and incidental: resources/views/platform/layout.blade.php:101-102 plus @section('meta_description') on all ten platform/docs pages.
- **Gap:** An owner-approved Phase 03 scope item was omitted by the execution plan and so was never built, never dispositioned and never reported. This is exactly 'dropped because a later execution plan omitted it'.
- **Responsible:** owner decision required; then phase 03 or a named later phase
- **Next action:** Raise SEO to the owner as an unaddressed Phase 03 scope item; scope it (canonical, hreflang for ar/en, sitemap, robots policy, product/organization structured data) and assign it to a phase.

### `P03-MP-02` — Master Plan scope (OWNER-SUSPECTED GAP E)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Consequence of the same scope item: a merchant's storefront should present that store's own SEO metadata. The product already collects it — the merchant SEO settings form writes metatitle, metakeyword and metadesc.
- **Source:** `CLAUDE.md §4 Phase 03 scope ('SEO'); resources/views/setting/seo_setting.blade.php (the merchant-facing form)`
- **Expected behaviour:** A store's configured meta title, description and image appear on its storefront pages.
- **Actual behaviour:** THEY NEVER DO. All four copies of the storefront layout — resources/views/front_end/layouts/app.blade.php:12 and the stylique, techzonix and greentic copies at the same line — call metaKeywordSetting($metakeyword ?? null, $metadesc ?? null, $metaimage ?? null, $slug). I traced the variables: no storefront controller assigns them (grep of app/ for metakeyword/metadesc/metaimage outside AppSettingController, SettingController and the helper returns only Utility defaults), and the ViewServiceProvider composer shares only languages, currentTheme, currantLang, store, themeSettings, slug, search_products, MainCategoryList, customer_name, customer, adminSetting (app/Providers/ViewServiceProvider.php:93-106). So every argument is null and app/Helper/helper.php:1180-1183 substitutes config('app.name'): every storefront page of every store emits title/description/og/twitter values of 'Store Syria'. Pre-existing vendored defect, inside Phase 03's scope, untouched and unreported by Phase 03.
- **Implementation:** app/Helper/helper.php:1177-1202 (helper); four layout call sites; no producer
- **Gap:** A merchant-configurable feature is wired to nothing. Also worth flagging while here: metaKeywordSetting interpolates its arguments into single-quoted HTML attributes with no escaping, so it would be an injection sink the moment a real value reaches it — fix the plumbing and the escaping together.
- **Responsible:** owner decision required (phase assignment); then engineering
- **Next action:** Record as a Phase 03 scope defect; when SEO is scheduled, pass per-store meta from the storefront controllers (or the view composer) and escape the helper's output.


## Phase 04 Commerce (P4-0 .. P4-9)

### `P04-CH-01` — Commerce channels

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Phase 04 scope element 'channels' ('products, multi-warehouse stock, pricing, fulfilment, COD, returns, CHANNELS').
- **Source:** `CLAUDE.md §4 (DERIVED); docs/development/phase-2/PHASE-02-SCOPE.md:35 routes 'channels' to Phase 04`
- **Owner decisions:** None.
- **Expected behaviour:** Unknown — no surviving source defines which commerce channels are supported or what 'channels' means in the approved plan.
- **Actual behaviour:** Not addressed and not defined. PHASE-04-EXECUTION-PLAN.md and PHASE-04-FINAL-REPORT.md never use the word 'channel'. Channel-shaped surfaces that exist (storefront web, Flutter admin, POS, WhatsApp order intake, Zid/Shopify import services) all predate Phase 04 and were untouched by it.
- **Gap:** Both the requirement's meaning and any work against it.
- **Responsible:** owner dependency
- **Next action:** Ask the owner what 'channels' means in the Decision Register; it cannot be recovered from this repository, and the EX-001..EX-012 exclusions that would say whether it was excluded are lost.

### `P04-COD-01` — COD collection and settlement

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Owner decision, 2026-10-24, FINAL, quoted verbatim in app/Services/CodCollectionService.php: 'Withdraw the Delivered-based COD payment/settlement trigger now ... Require a separate verified cash collection event, recording the collector (merchant, courier or delivery company), collection amount, time and supporting evidence ... Implement the safe state transitions and required tests now; any schema-dependent accounting functionality remains gated on GATE F.'
- **Source:** `app/Services/CodCollectionService.php:10-25 (owner decision verbatim); CLAUDE.md §4 Phase 04 scope element 'COD'`
- **Owner decisions:** The 2026-10-24 decision authorises the staged seam, and GATE F gating covered schema-dependent accounting. GATE F has since CLOSED without this table, so the gating condition has lapsed rather than been satisfied — it is no longer a live approved deferral.
- **Expected behaviour:** A COD order settles only on a recorded verified collection event carrying collector type, collector id, amount, collected_at and evidence.
- **Actual behaviour:** THE SEAM EXISTS AND CAN NEVER FIRE. CodCollectionService (added at the Phase 2.5 merge 4e878b8d, not in Phase 04) writes to `cod_collection_events`, and that table has NO migration: it is still 0009_draft_create_cod_collection_events_table.php.draft under database/migrations/_gate_f_pending/, and the 15 promoted GATE F migrations (2026_11_02_000100..001500) do not include it — GATE F closed on 2026-11-02 covering tables that already exist in production, and this one does not. isAvailable() is therefore false, record() refuses with a Log::critical and verifiedCollectionFor() answers null. A grep for CodCollectionService across app/, routes/ and resources/views finds no caller outside comments — there is no merchant or courier UI to record a collection.
- **Implementation:** app/Services/CodCollectionService.php (seam only); app/Services/PaymentVerificationService.php COD state machine
- **Evidence:** tests/Feature/Commerce/CodCashCollectionTest.php and CodStateMachineTest.php exist (pre-Phase 04) and pin the refusing seam
- **Gap:** No table, no write path, no UI — so no COD order can ever be settled through the approved mechanism. Phase 04 did not touch this despite COD being a named scope element.
- **Responsible:** owner dependency (schema), then Phase 04/05 engineering
- **Next action:** Owner decision: create cod_collection_events (a NEW table, outside the GATE F production-alignment set) and build the collection-recording surface, or state that COD settlement stays unavailable.

### `P04-COD-02` — COD collection and settlement

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-F126 — 'a merchant may assert Paid on a COD order at creation, which credits the wallet before the parcel leaves — the literal condition owner decision D-06 forbids'. Owner principle 3: 'COD is paid only after verified collection'.
- **Source:** `docs/development/phase-4/PHASE-04-FINAL-REPORT.md §4; docs/finance/P4-FINANCIAL-POLICY-DECISION-PACKAGE.md §3`
- **Owner decisions:** Owner decision D-06 and principle 3; the owner chose the validation option.
- **Expected behaviour:** Both manual order doors refuse cod + Paid at creation; a genuine counter sale stays expressible.
- **Actual behaviour:** IMPLEMENTED IN THE CURRENT TREE (post-Phase-04). Guards present at app/Http/Controllers/Api/DashboardController.php:6760 ('P4-F126 — AN UNCOLLECTED COD PARCEL MAY NOT BE ASSERTED AS ALREADY PAID') and app/Http/Controllers/OrderController.php:117; counter sales preserved via the existing POS payment type.
- **Implementation:** commit e03d72db
- **Evidence:** tests/Feature/Commerce/CodAssertedPaidAtCreationTest.php (10 cases); Phase 05 plan §0 records 'both guards falsified'
- **Gap:** Guards the CREATION door only; a merchant can still move a COD order to Paid later with no collection event, because no collection event can exist (P04-COD-01).
- **Responsible:** Phase 05 branch (P5-FIN-2), closing a Phase 04 finding
- **Next action:** Close the loop once collection events can be recorded.

### `P04-COD-03` — COD collection and settlement / Flutter

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-F145 — 'a merchant cannot change a COD order's status from the shipped app': the app always sends payment_status, the sheet echoes the order's current value, COD resolves to Pending under D-06, and the validator's in:Paid,Unpaid turns the echo into HTTP 422.
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md §P4-F145`
- **Owner decisions:** Fixed on the owner's instruction in the post-closure financial package.
- **Expected behaviour:** An echoed payment status is not a transition and must not block a delivery-status change.
- **Actual behaviour:** IMPLEMENTED IN THE CURRENT TREE (post-Phase-04). app/Http/Controllers/Api/DashboardController.php:350-357 defines the statuses a merchant may SET, :417 restricts the validator to format only, :546 'P4-F145, PART 1 — AN ECHO IS NOT A TRANSITION', :572 'PART 2 — A REAL TRANSITION IS STILL CONSTRAINED'.
- **Implementation:** commit 05c58799
- **Evidence:** tests/Feature/Commerce/OrderStatusEchoCompatibilityTest.php (15 cases); Phase 05 plan §0 records 'both limbs falsified'
- **Responsible:** Phase 05 branch (P5-FIN-7), closing a Phase 04 finding
- **Next action:** None.

### `P04-EV-01` — MySQL-specific verification

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Schema- and money-sensitive behaviour must be verified on real MySQL, because 'SQLite's type affinity hides real MySQL defects — that is how P01-SEC-13 survived'.
- **Source:** `CLAUDE.md §3; PHASE-04-FINAL-REPORT.md §7 'What the gates do not prove'`
- **Expected behaviour:** Phase 04's money arithmetic (coupon allocation, tax jurisdiction, refund totals, order-write transaction boundary) verified on a real MySQL/MariaDB harness.
- **Actual behaviour:** ONLY ONE NEW HARNESS WAS ADDED IN THE ENTIRE SPAN. `git log --diff-filter=A 54f607d8..HEAD -- tests/` shows exactly one new file under tests/Support/Security/bin/: verify-store-wallet-provisioning-on-mysql.php (and that came from the post-closure P4-F032 work). The report admits it: 'the money arithmetic in P4-F070 and P4-F082 is verified on SQLite decimals only.' The 40 pre-existing *-on-mysql.php harnesses cover earlier phases' concerns.
- **Evidence:** tests/Support/Security/bin/verify-store-wallet-provisioning-on-mysql.php only
- **Gap:** Coupon allocation, tax banding, refund totals and the new transaction boundary have no MySQL verification; production is MySQL with CACHE_STORE=database.
- **Responsible:** Phase 04 engineering
- **Next action:** Add MySQL harnesses for the four money paths this phase changed, following the existing verify-*-on-mysql.php pattern.

### `P04-EV-02` — Browser / integration evidence

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** 'Written from measurement, not from reading alone'; 'HTTP 200 is not evidence ... Every assertion must read the body or the database' (standing constraints).
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md header and §8`
- **Expected behaviour:** Captured, reproducible browser/integration evidence for the commerce surfaces the phase touched.
- **Actual behaviour:** The phase opened with a genuine browser measurement (plan §2.1-§2.3: a completed guest COD order, a forged encrypted order link refused with HTTP 403) but produced NO captured artefacts. There is no docs/development/phase-4/evidence/ directory, while phases 2, 2.5 and 3 each have one. All Phase 04 evidence is PHPUnit-level: 21 new test files plus 1 MySQL harness.
- **Evidence:** narrative measurements in PHASE-04-EXECUTION-PLAN.md §2; 21 test files added in the span
- **Gap:** No reproducible browser/integration evidence set; the §2.1 and §2.2 measurements cannot be re-run by a later reader.
- **Responsible:** Phase 04 engineering
- **Next action:** Capture the journeys (P04-P9-01) into an evidence directory on the pattern phases 2.5 and 3 already use.

### `P04-FIN-01` — Order / wallet reconciliation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-F124 — 'what cancelling an order should do to payment_status for prepaid, COD, wallet-credited, refunded, uncollected and duplicate-cancelled orders'. Owner principles 1 and 2: payment status reflects actual customer settlement; cancellation does not erase evidence of a captured payment.
- **Source:** `docs/development/phase-4/PHASE-04-FINAL-REPORT.md §4; docs/finance/P4-FINANCIAL-POLICY-DECISION-PACKAGE.md §2`
- **Owner decisions:** Owner chose Option A; the decision text is quoted verbatim in the service's docblock.
- **Expected behaviour:** Cancellation reverses the merchant's entitlement without rewriting orders.payment_status, idempotently, with the reason recorded in the financial ledger (owner Option A).
- **Actual behaviour:** IMPLEMENTED IN THE CURRENT TREE (post-Phase-04). app/Services/OrderEntitlementReversal.php carries the owner's Option A verbatim and never touches orders.payment_status; call sites now cover the doors the package measured — app/Models/Order.php:757, Api/DashboardController.php:700, :6097, :6115, Api/DeliverySyriaOrderController.php:242 — and OrderFinancialService:386 recognises the new states. Note the package's own correction: there are SEVEN cancel doors, not six.
- **Implementation:** app/Services/OrderEntitlementReversal.php; app/Services/OrderFinancialService.php
- **Evidence:** tests/Feature/Commerce/CancellationLedgerSemanticsTest.php (17 cases); commits 14aeb185, 8abce4c8, 6ee6de56 (the three doors that bypassed the engine)
- **Responsible:** Phase 05 branch (P5-FIN-1), closing a Phase 04 finding
- **Next action:** None. Phase 05's own plan table still shows an older 'three non-engine doors outstanding' status — that row is stale and should be resynced.

### `P04-FIN-02` — Order / wallet reconciliation

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-F032 — 'an order in a store with no store_wallets row writes no financial record and loses that credit permanently'. Owner principle 5: 'Missing wallets must never silently lose credits'.
- **Source:** `docs/development/phase-4/PHASE-04-FINAL-REPORT.md §4; docs/finance/P4-F032-WALLET-RECONCILIATION-PROCEDURE.md`
- **Owner decisions:** Owner approved the forward provisioning and separately gated historical recovery.
- **Expected behaviour:** A wallet exists wherever an order can credit one; a lost credit is never silent.
- **Actual behaviour:** FORWARD FIX IMPLEMENTED IN THE CURRENT TREE: app/Services/StoreWalletProvisioning.php is the single creator of a store_wallets row, called by the two store-creation doors and by OrderFinancialService::createForOrder(). Within Phase 04 itself only the SILENCE was fixed (a critical log naming order, store and amount, commit c6f77fb4).
- **Implementation:** app/Services/StoreWalletProvisioning.php, commit aa9535e6
- **Evidence:** tests/Feature/Commerce/StoreWalletProvisioningTest.php (10 cases); tests/Support/Security/bin/verify-store-wallet-provisioning-on-mysql.php — the ONLY new MySQL harness in this whole span (13 checks per the Phase 05 plan)
- **Gap:** Historical lost credits — see P04-FIN-03.
- **Responsible:** Phase 05 branch (P5-FIN-5), closing a Phase 04 finding
- **Next action:** None for the forward fix.

### `P04-FIN-03` — Order / wallet reconciliation

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Owner instruction, verbatim: 'Do not backfill or credit existing production orders automatically. Prepare a READ-ONLY reconciliation procedure for historical missing credits, including the required production-replica queries. Historical recovery remains separately approval-gated.'
- **Source:** `docs/finance/P4-F032-WALLET-RECONCILIATION-PROCEDURE.md (owner instruction quoted verbatim)`
- **Owner decisions:** YES — the verbatim instruction above. Cited rather than inferred.
- **Expected behaviour:** A read-only procedure exists, is never run from here, and historical recovery stays approval-gated.
- **Actual behaviour:** The procedure exists and declares its own state: 'STATUS: PROCEDURE ONLY. NOTHING HERE HAS BEEN RUN, AND NOTHING HERE WRITES.' Every row count is written as '?' because this session has no production access.
- **Implementation:** documentation only, by instruction
- **Evidence:** docs/finance/P4-F032-WALLET-RECONCILIATION-PROCEDURE.md §1-§2
- **Gap:** How many orders lost credit, and whether it is recoverable, is unanswered and unanswerable without a production replica.
- **Responsible:** owner dependency (production access)
- **Next action:** Run the queries on a replica when someone with access is authorised to.

### `P04-FIN-04` — Order / wallet reconciliation

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-F128 — 'the wallet can be moved without the order, in both directions, with no reconciliation anywhere'.
- **Source:** `docs/development/phase-4/PHASE-04-FINAL-REPORT.md §4; docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §0 ('P4-F128 reconciliation + finance-screen audit — outstanding')`
- **Owner decisions:** Owner principle 4 covers the direction; no implementation decision has closed it.
- **Expected behaviour:** Wallet movements reconcile against orders; a movement without an order is detectable.
- **Actual behaviour:** OPEN. No reconciliation surface exists: app/Console/Commands/ holds no reconciliation command, and the only reconciliation artefacts are OrderTotalReconciler (per-order totals, not wallet-vs-order) and the read-only F032 procedure. The project's own task ledger shows P5-FIN-4 'F128 separate settlement from entitlement + reconciliation' as PENDING.
- **Gap:** No order↔wallet reconciliation anywhere; this is one of the four rows the final report says Phase 05 analytics must not be built on.
- **Responsible:** Phase 05 (P5-FIN-4), on a Phase 04 finding
- **Next action:** Build the reconciliation as P5-FIN-4 before any revenue metric reads store_wallets.

### `P04-FL-01` — Flutter end-to-end compatibility

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Preserve Flutter compatibility including its API contracts (standing principle 2), and P4-F114 — 'POST /api/admin/update-customer answers 401 to merchant staff, forcing a logout in the shipped app'.
- **Source:** `CLAUDE.md §2 standing principle 2; docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md medium table (P4-F114); CLAUDE.md §1 ('the shipped Flutter app forces a logout on any 401')`
- **Expected behaviour:** No Phase 04 change breaks the shipped app, and the known 401-logout defect is removed.
- **Actual behaviour:** Compatibility was actively protected (OrderStatusEchoCompatibilityTest, the F145 echo fix, and the F124 package's refusal of a new payment_status value precisely because 'shipping the server first strands the app'). But P4-F114 IS STILL LIVE: app/Http/Controllers/Api/DashboardController.php:9728-9735 returns HTTP 401 whenever the token's user type is not in ['admin','super admin'] — i.e. for merchant staff — which force-logs-out the shipped app. The Contract suite is 7 files / 45 tests and drives payload shape, locale and Lynomia contracts; it drives no commerce journey.
- **Implementation:** app/Http/Controllers/Api/DashboardController.php (F145 and F124 arms)
- **Evidence:** tests/Contract/ (7 files, 45 tests); tests/Feature/Commerce/OrderStatusEchoCompatibilityTest.php
- **Gap:** F114's 401 force-logout; no end-to-end Flutter commerce journey is driven by any suite.
- **Responsible:** Phase 04 engineering
- **Next action:** Fix F114 with a staff-token test, and add contract coverage for the Flutter order/cancel/status journey.

### `P04-FL-02` — Flutter end-to-end compatibility

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-F104 / P4-F105 — the app's Return transition writes return_status=2 without restocking and 'silently disarms the web's restock door'.
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md high table`
- **Expected behaviour:** The app's Return and status transitions account for the goods exactly once and do not disarm the web door.
- **Actual behaviour:** The restock half was closed under P4-4, which also CORRECTED the finding ('both return arms write return_status = 1, not 2'). The transition-guard half of F105 is not closed — see P04-P4-03.
- **Implementation:** commits 227425d7, 4f804baf
- **Evidence:** tests/Feature/Commerce/ApiOrderStatusTransitionTest.php, DeliverySyriaReturnRestockTest.php
- **Gap:** No from-state transition rules on the shared writer.
- **Responsible:** Phase 04 engineering
- **Next action:** Covered by P04-P4-03.

### `P04-GOV-01` — Governance of Phase 04 closure

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** 'EVERY ORIGINAL REQUIREMENT MUST REMAIN ACCOUNTED FOR' — a phase may not close with requirements deferred, partially delivered or unevidenced without that being visible.
- **Source:** `The owner's standing rule as given to this audit; PHASE-04-FINAL-REPORT.md's own framing`
- **Expected behaviour:** The closure report accounts for every package and every confirmed finding, including the ones not done.
- **Actual behaviour:** The report is unusually honest about what it did NOT do (§4 six owner decisions, §5 security blockers, §6 'Recorded, not fixed', §7 'What the gates do not prove', and §3 on four layers of review finding defects in its own fixes). But three things are NOT accounted for anywhere in it: (1) P4-1 is still an OPEN package — the project's own task ledger carries 'P4-1: storefront commerce correctness' as PENDING — while the report reads as a closure; (2) the six end-to-end journeys A-F are never mentioned; (3) of 94 confirmed register findings, roughly 33 were closed and the remainder are neither fixed nor individually dispositioned in the report. The report's headline counts only the HIGH set ('sixteen of the eighteen HIGH confirmed findings are now closed').
- **Evidence:** PHASE-04-FINAL-REPORT.md; docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md; the project task ledger
- **Gap:** No disposition line for ~60 confirmed medium/low findings; no statement that P4-1 is open; no statement about journeys A-F.
- **Responsible:** Phase 04 engineering, then owner acceptance
- **Next action:** Give every one of the 94 confirmed rows an explicit disposition, and state P4-1's open status in the closure document.

### `P04-M0-01` — P4-0 Inventory and plan

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-0 deliverable: a commerce inventory and an executable execution plan, 'written from measurement, not from reading alone'; 12 domains inventoried, every claimed defect handed to an independent reviewer told to refute it.
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md (header, §5); docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md:1-25`
- **Expected behaviour:** A plan a later reader can execute, plus a register of claims each confirmed or refuted by adversarial review, with per-domain detail.
- **Actual behaviour:** Both artefacts exist and are substantive: the plan (17 KB) with packages P4-0..P4-9 and per-package acceptance clauses, and the register (88 KB) with 117 claims, 94 confirmed (18 high / 42 medium / 34 low), 17 refuted by name, 6 already tracked, plus 12 per-domain files (52-88 KB each) under docs/development/phase-4/findings/. The register states its own limit: 'a register row is a claim about the code, not a measurement of runtime, and not work done'.
- **Implementation:** docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md; docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md
- **Evidence:** docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md and the 12 files in docs/development/phase-4/findings/ (cart, checkout, customer-identity, flutter-contract, inventory-stock, order-lifecycle, order-write, payments-core, payments-gateways, pricing-discounts, refunds-returns, tax-shipping)
- **Responsible:** Phase 04 engineering
- **Next action:** None. Note that the inventory is a claim set, not evidence of delivery — 61 of the 94 confirmed rows are still open (see rows below).

### `P04-P1-01` — P4-1 Storefront commerce correctness

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** 'a storefront's language is its own, not the first merchant's' — every commerce AJAX message must follow the store being visited, not the first type-admin row in the shared database.
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §3 and §7 (row 1)`
- **Expected behaviour:** On an English storefront, POST /product_cart, /wish-list-count and /states-list answer in English; the fallback consults the store, never Cache::remember('admin_details').
- **Actual behaviour:** Fixed. tests/Feature/Commerce/StorefrontLocaleFollowsTheStoreTest.php exists (203 lines, 5 #[Test] cases) and the plan records falsification: 'restoring the admin fallback reddens 4 of 5'. I read the test file's structure, not its execution.
- **Implementation:** app/Http/Middleware/ThemeLanguage.php, commit 073de166
- **Evidence:** tests/Feature/Commerce/StorefrontLocaleFollowsTheStoreTest.php (5 cases); falsification recorded in PHASE-04-EXECUTION-PLAN.md §7
- **Responsible:** Phase 04 engineering
- **Next action:** None.

### `P04-P1-02` — P4-1 Storefront commerce correctness

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-1 acceptance, verbatim: 'a storefront harness driving cart and checkout in BOTH languages, asserting the message language matches the page'.
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6, P4-1 acceptance clause 2`
- **Expected behaviour:** A repeatable harness that drives add-to-cart and checkout on an Arabic (RTL) and an English (LTR) storefront and asserts message language against page language.
- **Actual behaviour:** No such harness exists. The only artefact is the 5-case locale test above, which pins the fallback, not a driven cart/checkout journey in two languages. No Phase 04 evidence directory exists at all (phases 2, 2.5 and 3 each have docs/development/phase-*/evidence/; phase-4 has only three .md files plus findings/).
- **Gap:** The bilingual cart/checkout harness, which is half of P4-1's stated acceptance.
- **Responsible:** Phase 04 engineering — P4-1 is still open (task ledger row 'P4-1: storefront commerce correctness' is PENDING while the phase was reported closed)
- **Next action:** Build the two-language cart+checkout harness, or record an explicit owner decision that the 5-case locale pin is sufficient.

### `P04-P1-03` — P4-1 Storefront commerce correctness

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** 'cart edge behaviour (quantity bounds, removal, guest→customer persistence); P4-F002 P4-F004'.
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6, P4-1 scope line`
- **Expected behaviour:** Quantity bounds enforced; removal actually removes; a guest basket survives sign-in without orphaning or duplicating lines.
- **Actual behaviour:** Not addressed. P4-F004 is still live in the code I read: the guest cart is WRITTEN under session()->getId() (app/Http/Controllers/CartController.php:401) and READ from the cart cookie (:134, :169, :188). P4-F005 (quantity_type=remove reports success and removes nothing) and P4-F011 (login merge creates a duplicate line) carry no fix or test. No cart-edge test was added in the phase.
- **Gap:** Guest basket identity, removal no-op and login-merge duplication are all untouched.
- **Responsible:** Phase 04 engineering (P4-1 open)
- **Next action:** Close P4-1 properly or re-classify these three register rows with the reason they were dropped.

### `P04-P1-04` — P4-1 Storefront commerce correctness

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** The variant-control question in §4 — only 1 of 32 store-1 products is variant_product=1 and its page renders a variant_form with no variant controls; 'whether the missing control is the theme or the data is P4-1's first question'.
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §4 (table row 4) and §7 (row 2)`
- **Expected behaviour:** A variant product renders its selectors and remains sellable after an edit.
- **Actual behaviour:** The root cause was found and fixed as P4-F119 ('editing a variant product makes it unsellable' — the edit emptied the storefront selectors). tests/Feature/Storefront/VariantOptionsRenderTest.php was added; the plan records 'reverting the partial reddens 5 of 6; the one that stays green is the create-shape guard'.
- **Implementation:** commit 23092f17 'P4-1: editing a variant product stopped it selling'; register §P4-F119
- **Evidence:** tests/Feature/Storefront/VariantOptionsRenderTest.php; falsification in PHASE-04-EXECUTION-PLAN.md §7
- **Gap:** The original fixture observation (a store with one variant product) is unchanged, so variant SELECTION is still not exercised end to end by a driven journey.
- **Responsible:** Phase 04 engineering
- **Next action:** Exercise variant selection in the P4-1 harness when it is built.

### `P04-P1-05` — Cart and GUEST checkout

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** Full cart and GUEST checkout must work end to end (the phase's premise: 'a guest can buy something today', order #020261115232155, COD, is_guest=1).
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §1 and §2.1`
- **Expected behaviour:** A guest adds to cart, enters billing, chooses COD and reaches /order-complete with an order row written.
- **Actual behaviour:** The journey works — but the evidence is a one-off narrative measurement in the plan (order reference quoted, orders table 9→10 rows) with no captured artefact, no script and no test. One real guest defect was fixed in the phase: P4-F096, an incomplete guest billing form answered HTTP 500 instead of the shopper's validation message (commit 51d8f45e), falsified ('removing the guard re-reddens it with the measured 500').
- **Implementation:** pre-existing WorkDo checkout; P4-F096 fix at commit 51d8f45e
- **Evidence:** PHASE-04-EXECUTION-PLAN.md §2.1 narrative only; P4-F096 has a test per the final report §2, no evidence artefact directory exists for phase 4
- **Gap:** No reproducible guest-checkout journey artefact: no screenshot set, no harness script, no state-asserting test for the whole journey.
- **Responsible:** Phase 04 engineering (P4-9)
- **Next action:** Capture the guest journey as a repeatable harness that asserts on the orders row, not on HTTP 200.

### `P04-P1-06` — Cart and GUEST checkout

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-F002 — 'api/{slug}/cart-check-guest discloses any tenant's product and per-variant stock to any Sanctum token holder'.
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md, medium-severity table (P4-F002)`
- **Expected behaviour:** The guest cart check resolves products within the slug's store and discloses no other tenant's stock.
- **Actual behaviour:** STILL OPEN, read in the code: app/Http/Controllers/Api/ApiController.php:6818 is `Product::find($value['product_id'])` with no store predicate, and :6821 resolves ProductVariant by product_id+id only. Product carries NO StoreScope predicate (CLAUDE.md: Product is not in config/tenancy.php scoped_models), so stock for any tenant's product id is returned to any Sanctum token holder.
- **Gap:** Cross-tenant stock disclosure on a live storefront API route.
- **Responsible:** Phase 04 engineering (P4-1 open)
- **Next action:** Scope the lookup to the resolved store and add a negative test driving a foreign product id.

### `P04-P2-01` — P4-2 Pricing arithmetic

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-2 acceptance: 'a multi-line basket fixture ... in which a flat coupon reduces the basket by its own amount exactly once, and a basket under the spend threshold is not discounted at all, asserted on orders.final_price and not on the AJAX figure alone'. (P4-F001, P4-F063, P4-F062)
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-2; §7 rows 3-6`
- **Owner decisions:** P4-F118 and P4-F120 were deliberately NOT fixed because each changes what a merchant's existing coupons do or what a money column means — see P04-P2-03.
- **Expected behaviour:** One flat discount per basket; minimum_spend/maximum_spend gates applied in the cart readers as well as apply_coupon; order-independent allocation.
- **Actual behaviour:** Fixed and falsified. tests/Feature/Commerce/MultiLineCouponArithmeticTest.php exists (14 cases). The plan records 'reverting both readers reddens 7 of 10', the spend gate 'in the same 7', and that review of the fix itself caught a first-come allocation that moved the tax base by 20.00 and then a pro-rata bound error (933.33 discount on a 750.00 basket, final report §3).
- **Implementation:** commits under P4-2; app/Models/Cart.php coupon readers
- **Evidence:** tests/Feature/Commerce/MultiLineCouponArithmeticTest.php (14 cases), tests/Feature/Commerce/RefusedCouponLinePriceTest.php; falsifications recorded in PHASE-04-EXECUTION-PLAN.md §7
- **Gap:** Arithmetic verified on SQLite decimals only — see P04-EV-01.
- **Responsible:** Phase 04 engineering
- **Next action:** None for the arithmetic; carry the MySQL gap to P04-EV-01.

### `P04-P2-02` — Coupon-redemption integrity

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Coupon redemption must be recorded truthfully: P4-F134 'ordersave may record no coupon redemption at all, so coupon caps never see those orders'; P4-F135 'a refused coupon still writes order_coupon_details and user_coupons, burning the shopper's allowance'; P4-F061 'coupons.status is written by every coupon editor and read by no gate — deactivating a store coupon does not stop it discounting'.
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md (medium table P4-F061; §P4-F134, §P4-F135); PHASE-04-FINAL-REPORT.md §6 'Recorded, not fixed'`
- **Owner decisions:** None recorded. The report's own words are 'Needs a driven test before anything changes' — that is an engineering judgement, so this is NOT OWNER-APPROVED DEFERRED.
- **Expected behaviour:** Every redemption that discounts an order is recorded; a refused coupon records nothing; a deactivated coupon does not discount.
- **Actual behaviour:** All three open. F061 verified in the code I read: app/Models/Cart.php:1146-1178 gates the coupon on expiry date, total usage limit and per-user limit only — there is no `status` predicate anywhere in that gate, and none in ApiController::apply_coupon's query (:4499). F134/F135 are explicitly 'RECORDED, NOT FIXED' in the register and the final report, with the engineering reason 'the fix direction changes what shoppers may spend' — an engineering deferral, not an owner decision.
- **Gap:** Coupon caps can be evaded (F134) and shopper allowances burned on refusals (F135); deactivated coupons still discount (F061).
- **Responsible:** Phase 04 engineering, with an owner decision on what live coupons may do
- **Next action:** Drive F134/F135 with a test before changing behaviour, as the register asks; add the status predicate for F061 behind a decision about live coupons.

### `P04-P2-03` — Pricing semantics

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** P4-F118 'the fixed product discount arm does not do what the merchant form says' and P4-F120 'orders.coupon_price records the coupon's headline amount, not the discount given'.
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md §P4-F118, §P4-F120; PHASE-04-EXECUTION-PLAN.md §7 closing paragraph`
- **Owner decisions:** No owner answer exists yet; recorded as pending in the plan §7 and register.
- **Expected behaviour:** Either the behaviour matches the merchant-facing form, or the owner decides the semantics explicitly.
- **Actual behaviour:** Recorded as owner decisions and not fixed, because 'each changes what a merchant's existing coupons do or what an existing money column means'. That is a genuine escalation of a money-semantics question and is stated as such in both documents.
- **Gap:** Two unresolved money-semantics questions that Phase 05 revenue reporting will read.
- **Responsible:** owner dependency
- **Next action:** Put F118 and F120 to the owner alongside the financial-policy package.

### `P04-P3-01` — P4-3 Order-write integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-3 acceptance: 'a store's shipping charge is decided by THAT store's plan, proved by a second tenant whose plan disagrees' (P4-F014, P4-F023, P4-F024).
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-3; §7 rows 7-8`
- **Expected behaviour:** Twelve order writers price shipping from the order's own store plan; ordersave's shipping-on arm includes the product subtotal.
- **Actual behaviour:** Fixed and falsified: 'reverting the plan read reddens the shipping-on test: a real 25.00 delivery charge is dropped'; 'with both defects restored the order total is 0.00 on a 100.00 basket'. tests/Feature/Commerce/OrderTotalUsesTheStoresOwnPlanTest.php was added in the phase.
- **Implementation:** commit 360b176a 'P4-3: the store's own plan decides its money, and the subtotal is in the total'
- **Evidence:** tests/Feature/Commerce/OrderTotalUsesTheStoresOwnPlanTest.php; PHASE-04-EXECUTION-PLAN.md §7
- **Responsible:** Phase 04 engineering
- **Next action:** None.

### `P04-P3-02` — P4-3 Order-write integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-3 acceptance: 'a forced child-table failure leaves no order row; a double submit creates one order' — plus the unscoped orders.customer_id write (P4-F025/P4-F026/P4-F018).
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-3`
- **Expected behaviour:** The Order row and its four child tables are one transaction; a repeated checkout POST writes one order and takes stock once; a store may not be billed another store's customer.
- **Actual behaviour:** Fixed, with five follow-on defects found inside the fix itself (P4-F130 rollback of a caller's transaction, P4-F131 $order->exists after rollback, P4-F136 cart ids that are a loop index on the guest branch, P4-F137 payment arms ending in exit/die stranding the claim, P4-F139/F140). Tests added: StorefrontOrderWriteAtomicityTest (6 cases), WebCheckoutIntakeIdempotencyTest. Falsifications are listed per-row in the final report §2.
- **Implementation:** commits a49a8bc4, 6a28a818; app/Http/Controllers/OrderController.php::store, app/Http/Controllers/Api/ApiController.php order writers
- **Evidence:** tests/Feature/Commerce/StorefrontOrderWriteAtomicityTest.php, tests/Feature/Commerce/WebCheckoutIntakeIdempotencyTest.php, tests/Feature/Commerce/ApiOrderWriterStockCompensationTest.php (extended); PHASE-04-FINAL-REPORT.md §2
- **Gap:** The final report's own caveat applies: 'any unreviewed fix in these files should be treated as provisional', and register-recorded P4-F138 (cart_id means a row id on one branch and a loop index on the other) is still open as a storefront contract issue.
- **Responsible:** Phase 04 engineering
- **Next action:** Resolve P4-F138's contract question before any further change to the cart remove/quantity endpoints.

### `P04-P4-01` — P4-4 Stock integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-4 acceptance: 'for each cancel and return door, a test asserting the stock delta, including the idempotency marker — one restock, never two, and never zero' (P4-F034, P4-F056, P4-F104, P4-F105), preserving OrderReplacement's deliberate non-restock.
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-4`
- **Expected behaviour:** Every merchant-side, API, driver and webhook cancel/return door accounts for the goods exactly once.
- **Actual behaviour:** Closed per the register's 'P4-4 — stock integrity across every cancel and return door (CLOSED)' section, which also records corrections to the findings (both return arms write return_status = 1, not 2) and a fifth defect found in the fix by its own tests. Tests added: MerchantCancelRestockTest (19 cases), DeliverySyriaReturnRestockTest, ApiOrderStatusTransitionTest; three more cancel doors added later by the ledger inventory (commit 4f804baf).
- **Implementation:** commits 227425d7, 4f804baf; app/Models/Order.php restock arms, app/Services/ProductStockService.php
- **Evidence:** tests/Feature/Commerce/MerchantCancelRestockTest.php (19 cases), tests/Feature/Commerce/DeliverySyriaReturnRestockTest.php, tests/Feature/Commerce/ApiOrderStatusTransitionTest.php, pre-existing OrderCancelRestockTest
- **Responsible:** Phase 04 engineering
- **Next action:** None.

### `P04-P4-02` — Multi-warehouse inventory

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Phase 04 scope, as the surviving derivation states it: '04 Commerce \| products, MULTI-WAREHOUSE STOCK, pricing, fulfilment, COD, returns, channels'.
- **Source:** `CLAUDE.md §4 (DERIVED summary of the owner-approved Master Product Plan); echoed by docs/development/phase-2/PHASE-02-SCOPE.md:35 which routes 'multi-warehouse stock, returns workflow, channels' to Phase 04`
- **Owner decisions:** None. Do not read the plan's silence as approval.
- **Expected behaviour:** Stock held and decremented per warehouse/location, with fulfilment choosing a source.
- **Actual behaviour:** NOTHING EXISTS AND NOTHING WAS ATTEMPTED. `grep -ril warehouse` over app/, database/migrations/, routes/ returns zero code hits; no WorkDo module in packages/workdo/ is a warehouse module (the only matches repo-wide are font files and a vendored tinymce plugin). PHASE-04-EXECUTION-PLAN.md never uses the word; PHASE-04-FINAL-REPORT.md never uses the word. This requirement was not deferred, refused or escalated — it was silently absent from the plan.
- **Gap:** The entire capability, and the scope decision behind it.
- **Responsible:** owner dependency (scope), then a future phase
- **Next action:** Ask the owner whether multi-warehouse stock was EX-* excluded or is still required; it cannot be settled from this repository because the Decision Register and its exclusions were never committed.

### `P04-P4-03` — Order lifecycle / fulfilment states

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-F033 'Order::order_status_change() has no from-state rules; the order board offers a forward move on a Cancelled or Returned card'; P4-F042 (webhook has no transition rules); P4-F043 'ordersave writes delivered_status from request input — an order can be born Delivered, bypassing the fulfilment gate on refunds and returns'; P4-F036 (a shopper can set their own order to delivered).
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md, medium-severity table`
- **Expected behaviour:** Order status moves obey a state machine; an order cannot be born Delivered; a shopper cannot deliver their own order.
- **Actual behaviour:** Mostly open. app/Models/Order.php::order_status_change (line 695) contains no transition rules — I grepped the file for 'transition' and found none. One narrow guard was added later, in the Flutter admin door only: the P4-F145 work added 'a second guard refuses a status change on an already-cancelled order' (app/Http/Controllers/Api/DashboardController.php:546-572). F043 and F036 carry no fix or test.
- **Implementation:** app/Http/Controllers/Api/DashboardController.php (F145 guard)
- **Evidence:** tests/Feature/Commerce/ApiOrderStatusTransitionTest.php and OrderStatusEchoCompatibilityTest.php (15 cases) cover the Flutter door only
- **Gap:** No state machine on the shared writer; order can still be born Delivered; shopper-driven delivered status unaddressed.
- **Responsible:** Phase 04 engineering
- **Next action:** Define the fulfilment state machine once and route every door through it; drive F043 and F036 with negative tests.

### `P04-P5-01` — P4-5 Payment and callback integrity census

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-5 acceptance, verbatim: 'the ~30 gateway controllers as a table: which verify server-side, which check amount, which have replay guards, which are covered by existing Gateway* tests ... every gateway classified; none marked verified without evidence; gateways needing external credentials marked EXTERNALLY BLOCKED'.
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-5`
- **Expected behaviour:** A per-gateway classification table, published, with an explicit externally-blocked column.
- **Actual behaviour:** The table was never produced. No census document exists under docs/ for Phase 04; the register's P4-5 sections cover only specific defects (F044, F096, F141, F142, F143, F144). The nearest standing artefacts are TEXT censuses in tests that predate the phase (PendingPaymentGatewayCensusTest, whose own docblock says 'This is a TEXT census. It proves a handler carries the construct, not that the construct is reached'; GatewayReplayGuardCensusTest) and the Phase 01 docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md.
- **Gap:** The gateway classification table — the one artefact that would let a reader see which of ~45 payment types are verified and which are not.
- **Responsible:** Phase 04 engineering
- **Next action:** Produce the table from the code, marking credential-dependent gateways EXTERNALLY BLOCKED rather than untested.

### `P04-P5-02` — Real payment verification and provider dependencies

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Real server-side payment verification: an order may be recorded Paid only on provider-confirmed capture, with the captured amount reconciled against the order.
- **Source:** `Owner decision preserved verbatim in app/Services/PaymentVerificationService.php:50-60 — 'never Paid. Do not create a falsely paid order, and preserve a safe reconciliation path for customers who may have been charged'; plan §6 P4-5`
- **Owner decisions:** The no-unverified-Paid default is an approved owner decision and is implemented; it is a safe default, not a substitute for verification.
- **Expected behaviour:** Every gateway either verifies server-side or the order stays Pending; amount binding where a provider reports one.
- **Actual behaviour:** PARTIAL BY DESIGN AND MEASURED: PaymentVerificationService::ENFORCED_PAYMENT_TYPES holds exactly THREE types — paymera, nmi, khalti — for which the captured AMOUNT is reconciled. OFFLINE_PAYMENT_TYPES holds bank_transfer, whatsapp, pos, lottery product. Every other gateway (the register says ~45) falls through to STATUS_PENDING: no false Paid, but no verification either. findings/payments-core.md records the same for Tap: 'recordVerification() is never called' over the whole of getProductStatus. Phase 04 added no new enforced gateway.
- **Implementation:** app/Services/PaymentVerificationService.php
- **Evidence:** app/Services/PaymentVerificationService.php:65-144; tests/Feature/Commerce/UnverifiedGatewayStaysPendingTest.php, KhaltiAmountBindingTest.php, Paymera* tests (all pre-existing)
- **Gap:** ~42 payment types have no server-side capture verification. The final report states plainly: 'No journey was driven against a real gateway. Every gateway assertion is either a stored-state drive or a source-structural one.'
- **Responsible:** Phase 04 engineering + external provider credentials
- **Next action:** Classify each gateway (P04-P5-01) and name which need provider credentials; those are EXTERNALLY BLOCKED for verification, NOT verified.

### `P04-P5-03` — P4-5 Payment and callback integrity

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-F044 — 'Gateway cancel returns land on the generic storefront order writer: an order row and a stock decrement for a payment that never happened'.
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md high table; §P4-F044`
- **Expected behaviour:** Pressing Cancel at the gateway writes no order, takes no stock, does not empty the cart and does not consume the return claim.
- **Actual behaviour:** Fixed (commit adcf3c37) and falsified ('removing the reader reddens both cancel cases'). Two further LIVE instances were then found behind the ratchet written to prevent them — P4-F141 (CoinGate) and P4-F142 (PayTR merchant_fail_url byte-identical to its success URL, Xendit failure_redirect_url); the report measures that the 'exhaustive' rewrite enumerated 10 of the file's 47 return-URL sites.
- **Implementation:** commits adcf3c37, d2579ffe, d5c4aef7
- **Evidence:** tests/Feature/Commerce/GatewayCancelReturnWritesNoOrderTest.php; PHASE-04-FINAL-REPORT.md §2 and §3
- **Gap:** The report's own warning stands: 'a ratchet that enumerates by name rather than by destination should be assumed incomplete until someone counts what it actually covers'. P4-F143 (Mollie finality) and P4-F144 (AamarPay blob-as-slug) are recorded, not fixed.
- **Responsible:** Phase 04 engineering
- **Next action:** Re-key the ratchet on destination, and close F143/F144.

### `P04-P5-04` — Payment callbacks — data egress

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-F046 — 'Storefront Benefit/Tap charge sets its server-to-server callback to a hardcoded public webhook.site bin, and the storefront return performs no capture verification for benefit at all'. Owner principle 6: 'Payment callbacks require trusted destinations and server-side verification'.
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md high table; PHASE-04-FINAL-REPORT.md §4; docs/finance/P4-FINANCIAL-POLICY-DECISION-PACKAGE.md §1 principle 6`
- **Owner decisions:** Escalated, unanswered. Listed in PHASE-04-FINAL-REPORT.md §4 as one of the six questions that are 'the owner's, not engineering's'.
- **Expected behaviour:** No first-party payment callback points at a public bin; Benefit returns verify the capture server-side.
- **Actual behaviour:** STILL OPEN IN FIRST-PARTY CODE — I read the live lines: app/Services/PaymentService.php:1817 and app/Http/Controllers/BenefitPaymentController.php:129 both still set `"post" => ["url" => "https://webhook.site/fd8b0712-..."]`. Three further copies are in vendored modules (Campaigns, Donation, LotteryPrizes) which must not be edited. Only the DEAD webhook_backup() path was removed and ratcheted by GatewayCallbackDestinationTest. No Benefit capture verification exists.
- **Implementation:** partial: dead webhook_backup() removed
- **Evidence:** tests/Feature/Commerce/GatewayCallbackDestinationTest.php covers the removed dead path only
- **Gap:** A live payment callback still points at a public bin; Benefit capture is unverified.
- **Responsible:** owner dependency for the destination; engineering for the removal and the capture check
- **Next action:** Remove the first-party egress and add Benefit capture verification (task P5-FIN-6, pending); the destination choice is the open P1-SEC-55 escalation.

### `P04-P6-01` — P4-6 Refunds, returns, cancellations

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-F082 — 'Returning a second distinct line re-subtracts the first line's value from the already-reduced order total, driving final_price negative'.
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md high table; §P4-F082`
- **Expected behaviour:** A second return subtracts only its own line; a 147.50 order owing 17.50 lands at 17.50, not −72.50.
- **Actual behaviour:** Fixed (commit 9ca8019a) and falsified: 'reverting one line reddens 3 of 4'.
- **Implementation:** commit 9ca8019a
- **Evidence:** tests/Feature/Commerce/SecondReturnTotalTest.php
- **Responsible:** Phase 04 engineering
- **Next action:** None.

### `P04-P6-02` — Full refunds

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-F127 (full-refund half) — 'a fully refunded order keeps payment_status = Paid, keeps its store_order_financials row at paid and keeps the merchant's wallet credit'. Owner principle 4: 'Refunds and wallet reversals are separate, auditable, idempotent events'.
- **Source:** `docs/development/phase-4/PHASE-04-FINAL-REPORT.md §4; docs/finance/P4-FINANCIAL-POLICY-DECISION-PACKAGE.md §1`
- **Owner decisions:** Owner Option A for F124 governs the mechanism and is quoted verbatim in app/Services/OrderEntitlementReversal.php.
- **Expected behaviour:** A COMPLETED refund reverses the merchant's entitlement, idempotently, without rewriting the customer's payment history.
- **Actual behaviour:** IMPLEMENTED IN THE CURRENT TREE (post-Phase-04, on the Phase 05 branch). app/Http/Controllers/OrderRefundController.php:1238 calls OrderEntitlementReversal::reverse($order, REASON_REFUNDED, 'refund settled') inside the refund transaction, gated on $targetStatus === STATE_REFUNDED; app/Services/OrderEntitlementReversal.php moves store_order_financials.payment_status to 'refunded' under lockForUpdate(), leaves orders.payment_status untouched and preserves paid_amount/paid_at.
- **Implementation:** app/Services/OrderEntitlementReversal.php, app/Http/Controllers/OrderRefundController.php:1238 (commits be0c6ea9, 621d60ae)
- **Evidence:** tests/Feature/Commerce/RefundEntitlementReversalTest.php (11 cases); tests/Feature/Commerce/CancellationLedgerSemanticsTest.php (17 cases) for the shared engine
- **Gap:** Reachable only for OFFLINE payment types — see P04-P6-05.
- **Responsible:** Phase 05 branch (P5-FIN-3), closing a Phase 04 finding
- **Next action:** None for this half.

### `P04-P6-03` — Partial refunds

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Partial refunds, with proportional financial handling. Owner instruction, verbatim: 'For partial refunds, implement proportional financial handling only if actual partial amounts can be safely reconciled and made idempotent. If the existing ledger cannot safely represent a partial refund, leave the financial mutation blocked and document the missing capability rather than approximating it.'
- **Source:** `docs/finance/P4-F127-PARTIAL-REFUND-CAPABILITY.md (owner instruction quoted verbatim at the head)`
- **Owner decisions:** YES — the verbatim owner instruction above authorises leaving the mutation blocked with documentation. This is the one row in this matrix where OWNER-APPROVED DEFERRED is supported by a cited owner decision rather than an engineering report.
- **Expected behaviour:** Either proportional, reconcilable, idempotent partial refunds — or a documented determination that the ledger cannot represent one, with the mutation left blocked.
- **Actual behaviour:** The determination was made and documented: five measured structural blockers (refund_amount written by assignment not accumulation; at most one order_refunds row per order with no unique constraint; per-line detail is untyped display text in a VARCHAR(255) blob; no currency on refund_amount; FLOAT money columns). The financial mutation is left blocked; OrderRefundController's reversal explicitly does not scale with a partial amount and says so in-source.
- **Implementation:** deliberately none
- **Evidence:** docs/finance/P4-F127-PARTIAL-REFUND-CAPABILITY.md §1-§3; the in-source statement at app/Http/Controllers/OrderRefundController.php (PARTIAL REFUNDS ARE NOT TOUCHED)
- **Gap:** The CAPABILITY is still missing: a merchant cannot issue a reconcilable partial refund. The deferral covers the financial mutation only — it does not make the product gap disappear.
- **Responsible:** owner dependency (whether to build the capability)
- **Next action:** If partial refunds are required product behaviour, the five blockers are a schema and workflow work package, not a patch.

### `P04-P6-04` — Refund correctness and idempotency

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-6 acceptance: 'negative tests for authorization, ownership, transitions, amount limits and duplicates'. Register rows P4-F083 (refund restock ignores Order::LINE_STOCK_RESTORED so a line is credited twice), P4-F084 (Accept → RefundStock → Cancel double-counts), P4-F086 (the three OrderRefundSetting toggles are never read server-side), P4-F087 (four state-changing mutators record no actor), P4-F089 (operator locked out of GET /refund-request).
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-6; PHASE-04-FINDINGS-REGISTER.md medium/low tables`
- **Expected behaviour:** Refund stock credit is idempotent across doors, policy toggles are enforced server-side, every mutation records an actor.
- **Actual behaviour:** Only P4-F082 was fixed under P4-6. F083, F084, F086, F087 and F089 carry no fix, no test and no owner decision in the register or the final report. The partial-refund determination independently records that the refund door 'is outside the per-line marker protocol the other three stock doors share' and that RefundAmonut 'is replayable without limit' for online payment types.
- **Gap:** Double stock credit across the refund/return/cancel doors; unenforced refund policy; no actor attribution; operator locked out of the refund list.
- **Responsible:** Phase 04 engineering
- **Next action:** Bring the refund door into the LINE_STOCK_RESTORED protocol and add the five negative tests the acceptance clause names.

### `P04-P6-05` — Refunds — money actually returned

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Full refunds must return the customer's money, not merely record a status.
- **Source:** `Derived from P4-6 and from the owner principle 'Refunds and wallet reversals are separate, auditable, idempotent events' (docs/finance/P4-FINANCIAL-POLICY-DECISION-PACKAGE.md §1 principle 4)`
- **Owner decisions:** None. The absence is documented, which is not the same as approved.
- **Expected behaviour:** An approved refund on a gateway-paid order issues a provider refund and records the provider's confirmation.
- **Actual behaviour:** NO REFUND EVER LEAVES THIS SERVER. Recorded in-source at app/Http/Controllers/OrderRefundController.php:1206-1210: 'hasGatewayRefundDriver() is a hardcoded return false for every type and the two gateway refund HTTP clients in this tree have zero callers. No refund request has ever left this server.' Consequently resolveRefundStatus() returns STATE_REFUNDED only for an OFFLINE payment type; every online gateway resolves to 'Accept' — approved, not settled — and the merchant-facing message now says so ('No gateway refund was issued, so the money has not been returned yet').
- **Implementation:** none — the capability does not exist
- **Evidence:** the honest merchant message and the in-source statement are the evidence that the gap is known, not that it is closed
- **Gap:** The entire gateway-refund execution path. Phase 02's E13 row records the same blocker ('no gateway refund driver exists, so D-15 condition 6 refuses every collected payment').
- **Responsible:** owner dependency, then engineering + provider credentials
- **Next action:** Decide whether gateway refunds are in scope; building them needs provider credentials, so VERIFICATION would be externally blocked even once built.

### `P04-P7-01` — P4-7 Authorization on the commerce API

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-7 acceptance: 'operator-first checks that fail against the defect' for P4-F037, P4-F107 'and the permission rows'.
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-7`
- **Owner decisions:** The plan flagged that F107-style gating 'may refuse a live merchant, so it is an owner decision'. In practice the known-outcome gate caught exactly that (a gate refusing the store owner) and it was corrected in code — no owner decision was taken or needed for the three gated routes.
- **Expected behaviour:** Every order-mutating and money- or PII-bearing admin API route carries a permission check with the operator tested first.
- **Actual behaviour:** THREE ROUTES WERE GATED, THE REST WERE NOT. Measured by mapping every MerchantPermissions::allowsAny() call to its method in app/Http/Controllers/Api/DashboardController.php: gates exist on DeleteOrder (:3562), callerMayChangeOrderState (:5048, serving status-change and order-cancel), createOrderViaApi (:6852), addCustomer (:9121), DeleteVariant (:2627) — 17 isAbleTo mentions in a ~10k-line file, most of them in comments. I read ViewOrder (:2796) and assignOrder (:10023) in full: both resolveOwnedStore() for tenancy and NEITHER has any permission check. The register's other permission rows — P4-F106 (vieworder), P4-F108 (assignOrders), P4-F109 (DeliveryBoyApiController), P4-F110 (order notes), P4-F111 (cost_price in product search), P4-F112 (searchdata dumps Order rows), P4-F113 (customerlist/destroyCustomer), P4-F065 (coupon writes), P4-F075 (six tax/shipping writers) — are all still open.
- **Implementation:** commits c62f06f7, and the P4-9b correction after the known-outcome gate caught a gate that refused the store's own owner
- **Evidence:** tests/Feature/Security/AdminApiOrderDeletePermissionTest.php, tests/Feature/Security/AdminApiOrderStatusPermissionTest.php (the two gated families only)
- **Gap:** At least nine confirmed permission rows remain ungated on the Flutter admin API, including order PII (F106), driver assignment with commission rows (F108) and whole-Order dumps (F112).
- **Responsible:** Phase 04 engineering
- **Next action:** Gate the remaining routes with the operator-first predicate already in MerchantPermissions::allowsAny, one family at a time with the refusal tests.

### `P04-P8-01` — P4-8 Tax

**Status: VERIFIED COMPLETE**

- **Requirement (original wording / faithful reference):** P4-F070 — 'Jurisdiction-restricted tax methods are charged to non-matching shoppers when price_type is not inclusive'.
- **Source:** `docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md §P4-8`
- **Owner decisions:** The report flags this as a behaviour change the owner should see — it REDUCES what shoppers are charged, toward the merchant's own configuration.
- **Expected behaviour:** A tax restricted to a city is charged only to shoppers in it; nothing is charged before an address exists.
- **Actual behaviour:** Fixed (commit fec6856e) and falsified ('reverting Cart.php reddens 5 of 8'). The register records the measured figures: 8.00 charged where 5.00 was owed, and 8.00 where 0.00 was owed before an address is entered; both the exclusive and inclusive spellings were removed.
- **Implementation:** app/Models/Cart.php::getProductTaxAmount, commit fec6856e
- **Evidence:** tests/Feature/Commerce/CartTaxJurisdictionTest.php (8 cases, two of them premises)
- **Gap:** Money arithmetic verified on SQLite decimals only (final report §7).
- **Responsible:** Phase 04 engineering
- **Next action:** Carry to P04-EV-01.

### `P04-P8-02` — P4-8 Shipping, tax and totals — fixture and reconciliation

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-8 acceptance, verbatim: 'seed shipping methods and tax, resolve "CURRENCY": null. Acceptance: totals reconcile against OrderTotalReconciler with shipping and tax present.'
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-8, built on the measured fixture gaps in §4`
- **Expected behaviour:** A fixture with a real shipping method and a real tax, and a reconciliation of order totals with both present.
- **Actual behaviour:** Not done. The register's whole P4-8 section is P4-F070 and nothing else. The §4 gaps are unchanged: POST /shipping-method still has nothing to return in the fixture, no tax seed was added, and 'CURRENCY': null was never resolved or classified (the plan said P4-4 'must determine which' — product defect or fixture gap — and no document answers it). OrderTotalReconciler exists and is called from PaymentVerificationService/OrderTaxResolver/PaymentService, but no Phase 04 test reconciles a total with shipping AND tax present.
- **Gap:** Shipping selection, zones and costs, and tax derivation, are still not exercised end to end; the CURRENCY question is unanswered.
- **Responsible:** Phase 04 engineering
- **Next action:** Seed the fixture and write the reconciliation test the acceptance clause names; answer the CURRENCY question as defect or fixture.

### `P04-P8-03` — Fulfilment and shipping workflows

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Phase 04 scope element 'fulfilment' and the shipping register rows: P4-F074 (tax banding diverges between engines and priority sorts lexicographically), P4-F076 (saving a shipping zone with every method deselected is a 500), P4-F077 (Delivery Syria branch weighs a line with an unscoped Product::find()), P4-F078 (delivery_list resolves the zone with no country/state predicate), P4-F021/P4-F073 (shipping quote reads any customer's DeliveryAddress by id).
- **Source:** `CLAUDE.md §4 scope summary; docs/development/phase-4/PHASE-04-FINDINGS-REGISTER.md medium/low tables`
- **Expected behaviour:** Shipping zones, methods and quotes are tenant-safe, validated, and consistent with the catalogue engine.
- **Actual behaviour:** None of these rows was fixed, tested or escalated in Phase 04. No fulfilment workflow work appears in the plan beyond the shipping-charge plan source (P04-P3-01) and the fixture gap (P04-P8-02).
- **Gap:** Six confirmed shipping/tax rows, two of them cross-customer address reads.
- **Responsible:** Phase 04 engineering
- **Next action:** Schedule the shipping family; F021/F073 are ownership defects and should go first.

### `P04-P9-01` — P4-9 End-to-end journeys

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-9: 'End-to-end journeys A–F, integrated verification, closure ... every journey asserts on state, never on HTTP 200 alone.'
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-9; the journeys themselves are defined only in the owner directive, which is absent`
- **Owner decisions:** None. The plan did not record a decision to drop them; they simply do not appear in the closure report.
- **Expected behaviour:** Six named commerce journeys driven end to end, each asserting database state.
- **Actual behaviour:** NO JOURNEY ARTEFACT EXISTS AND THE CLOSURE REPORT NEVER CLAIMS ONE. A repo-wide grep for 'Journey [A-F]' in docs/ and tests/ returns exactly one hit — the plan's own reference to 'Journey C in the directive'. PHASE-04-FINAL-REPORT.md contains the word 'journey' once, and it is a disclaimer: 'No journey was driven against a real gateway.' There is no journey test, no journey evidence directory and no journey section in the report. Journey C (concurrent-checkout oversell) is the only one with engine-level coverage, via the PRE-EXISTING tests/Support/Security/bin/verify-stock-oversell-concurrency-on-mysql.php (14 checks, reported passing on this container's MariaDB in the plan §2.3 — I did not re-run it).
- **Evidence:** only the pre-existing oversell harness for one journey
- **Gap:** Five of six journeys have no evidence and, worse, no recoverable definition. The phase closed without ever restating what A, B, D, E and F were.
- **Responsible:** owner dependency (the journey definitions), then Phase 04 engineering
- **Next action:** Ask the owner to resupply the journey list (it is the only source), then drive each with state assertions.

### `P04-P9-02` — P4-9 Integrated verification and gates

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** P4-9: 'Then full gates, CI on the Draft PR, and PHASE-04-FINAL-REPORT.md.'
- **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-9`
- **Owner decisions:** The checklist is explicitly the owner's to clear; it was not falsified or worked around, which is the correct handling.
- **Expected behaviour:** All required gates measured on one clean tree AND the required CI jobs completing on the Draft PR.
- **Actual behaviour:** Local gates were measured on one clean tree (ee3c68ca, git status empty): Unit 1845 passed / 1 skipped, Contract 45 passed, Commerce+Security+Tenancy 6648 passed / 1 skipped, Feature 8977 testcases with 2 failures and 1 skip matching the known-outcome manifest exactly, nothing added to .github/known-test-outcomes.json. BUT the report states: 'the required Tests jobs have NEVER COMPLETED' — cancelled at six successive SHAs — and 'Security-sensitive diff: human checklist is red by design and is the owner's to clear'. So CI on the Draft PR is unsatisfied.
- **Evidence:** PHASE-04-FINAL-REPORT.md §7 (each figure names the command); .github/known-test-outcomes.json unchanged
- **Gap:** Required CI Tests jobs never completed on PR #139; the human security checklist is unresolved.
- **Responsible:** Phase 04 engineering + owner (checklist)
- **Next action:** Let one CI run finish without being superseded, and put the security checklist to the owner.


## Phase 05 Marketing & Analytics AND 06 SaaS & Premium Super Admin

### `GOV-120` — Open security incident

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** P1-SEC-40 / Issue #120 — the live test host serves /.git/ and historical credentials.
- **Source:** `docs/development/PROJECT-STATE.md:213; CLAUDE.md §2 ("Coordinate security incident P1-SEC-40 (#120) independently; UI work does not remediate the database dump exposure")`
- **Owner decisions:** The deferral is the owner's and is recorded. The standing restrictions in PROJECT-STATE.md §6 include 'No representing deferred exposure as remediated'.
- **Expected behaviour:** The exposure is closed and the exposed credentials rotated.
- **Actual behaviour:** OWNER-DEFERRED and UNRESOLVED. Containment artefacts exist but are deliberately inert: docs/security/p1-sec-40-apache-deny.conf and p1-sec-40-nginx-deny.conf are documentation-only, and PROJECT-STATE records that both .htaccess files are directive-identical to main so a merge cannot arm them. PROJECT-STATE §6 also notes the coupling: closing this by moving the document root to public/ would break all 76 asset('public/…') references at that moment, pinned by tests/Unit/Security/PublicAssetPrefixCouplingTest.php. P1-SEC-62 (operator diagnostic readable via the publicly served log) is recorded as dependent on it.
- **Evidence:** docs/security/P1-SEC-40-INCIDENT.md, docs/security/p1-sec-40-apache-deny.conf, docs/security/p1-sec-40-nginx-deny.conf; tests/Unit/Security/PublicAssetPrefixCouplingTest.php
- **Gap:** The exposure is live. Standing owner restrictions forbid secret rotation, live-host access change and production deployment, so no engineering action here can close it.
- **Responsible:** Owner — host access and credential rotation
- **Next action:** Nothing is closable from the repository. Keep it named in every phase closure and in the #137 audit scope, and never describe the dormant deny rules as mitigation.

### `GOV-137` — Post-Phase-06 audit

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** "Final project audit (#137) happens after this, before any production release" (Phase 06 row), and "The owner has deferred the comprehensive security review to the end of Phase 06 (Issue #137). That is a deferral, not a remediation."
- **Source:** `docs/development/PROJECT-STATE.md:44 and :208`
- **Owner decisions:** The deferral to the end of Phase 06 is recorded as an owner decision in PROJECT-STATE.md:208 and is cited as Issue #137. I did not read the issue itself.
- **Expected behaviour:** A project-wide security and production-readiness audit runs after Phase 06 and before any production release.
- **Actual behaviour:** Scheduled, not started. Phase 06 has not started, so the audit cannot have. PROJECT-STATE.md §5 lists what it will have to pick up: P1-SEC-40, P1-SEC-61's root cause, P1-SEC-62, the public/storage tracked-file defect, user-deletion/financial-retention limits, and Phase 01's outstanding release gates.
- **Gap:** The audit itself, and the backlog it inherits, which grows with each phase. The deferral is of timing only and remediates nothing.
- **Responsible:** Owner-scheduled, after Phase 06
- **Next action:** Keep the inherited backlog visible phase by phase; the deferral must not let an item drop out of the accounting between now and then.

### `P05-01` — P5-1 Finance ledger surfacing

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Admit `cancelled` and `refunded` to the finance screens and validators that today accept only `in:paid,unpaid`, so a merchant can see and act on a reversed order... Without it a reversed row is invisible to the one screen that owns it."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2, slice P5-1`
- **Owner decisions:** None found.
- **Expected behaviour:** A merchant opening the finance dashboard sees a cancelled or refunded order in its true state, and the status control accepts those values.
- **Actual behaviour:** NOT done. **Re-measured 2026-11-17 and still true; only the line numbers had drifted.** app/Http/Controllers/StoreFinanceController.php:**420** still validates `'payment_status' => 'required\|in:paid,unpaid'`. resources/views/finance/index.blade.php:**543-544** still offers exactly two options ('Not Received' / 'Received'), and the badge still renders anything that is not the literal 'paid' as a danger badge labelled 'Not Received'. Because P4-F124 and P4-F127 now write 'cancelled' and 'refunded' into that column, the screen actively mislabels a reversed order as unpaid.
- **Gap:** The whole slice. It is now worse than when written: the F124/F127 engine changes shipped, so the new statuses exist in data and the screen cannot represent them.
- **Responsible:** Phase 05 (P5-1)
- **Next action:** Widen the validator and the view to the six documented entitlement states, with a test driving a cancelled and a refunded row through the finance screen.

### `P05-02` — P5-2 Metric layer

**Status: PARTIALLY IMPLEMENTED** *(corrected 2026-11-17 — the row below was written before P5-2
shipped and had gone false. It is corrected to what is on disk today, not to what was planned.)*

- **Requirement (original wording / faithful reference):** "One query object per metric in §1–§10 of PHASE-05-METRIC-DEFINITIONS.md, each with its tenant predicate at the call site, its currency handling, and its caveat flag. No controller computes money inline. Every metric gets the verification test its definition names."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2, slice P5-2`
- **Owner decisions:** None found.
- **Expected behaviour:** Ten named metrics exist as query objects, each tenant-scoped, currency-aware, carrying its caveat, and each with a passing verification test.
- **Actual behaviour:** **The metric layer EXISTS.** `app/Support/Metrics/` holds **20 classes**, measured 2026-11-17: the ten metrics (`GrossMerchandiseValue`, `CollectedCustomerPayments`, `UnpaidOrders`, `CancelledOrders`, `CompletedRefunds`, `NetFinancialReceipts`, `MerchantWalletEntitlement`, `StoreRevenueMargin`, `CouponRedemption`, `ShippingAndTax`) plus `CustomerRetention` and the shared machinery (`Metric`, `MetricResult`, `MetricCaveat`, `MetricScope`, `MetricWindow`, `MixedCurrencyException`, `MultiFigureMetric`, `SingleFigureMetric`, `RetentionFigures`). Verification tests exist too — `tests/Feature/Metrics/MetricLayerTest.php` and `tests/Support/Security/bin/verify-metric-layer-on-mysql.php`.
  **The half that is NOT done is "no controller computes money inline".** `StoreFinanceController::index()` still computes inventory capital, retail value and potential profit in a `foreach` over `Product` rather than through a metric object — and that inline arithmetic is exactly where P5-10 found a fallback that could never fire (see `docs/development/phase-5/PHASE-05-MKT-ANA-MEASUREMENT-2026-11-17.md` §4). `ReportController` likewise computes its own aggregates.
- **Gap:** Not the metrics — the *adoption*. No controller or view reads a metric class; outside `app/Support/Metrics/` and `tests/`, the layer has zero consumers, so no merchant has ever seen a figure it produced.
- **Responsible:** Phase 05 (P5-2 built it; P5-3/P5-4/P5-7/P5-8 are the slices that must consume it)
- **Next action:** Replace the inline money arithmetic surface by surface, starting with the two that P5-10 has already proved wrong by hand. Each replacement needs its stated delta, because a corrected figure is a changed figure.

### `P05-03` — Metric definition contract

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Owner instruction, quoted verbatim in the document: "Every metric must have an explicit definition, source, inclusion/exclusion rules and verification tests", and "Do not reuse `SUM(orders.final_price)` as settled revenue merely because existing reports do."
- **Source:** `docs/development/phase-5/PHASE-05-METRIC-DEFINITIONS.md header`
- **Owner decisions:** The requirement wording is the owner's, quoted verbatim in the document header.
- **Expected behaviour:** Each metric carries a definition, a named source table/column, explicit includes/excludes, and a verification test that actually runs.
- **Actual behaviour:** The definition half is delivered: §1–§10 each state definition, source, includes, excludes and a named verification test, plus §11's five cross-cutting rules. **The test half is NO LONGER absent — this row was written before P5-2 shipped and is corrected 2026-11-17.** `tests/Feature/Metrics/MetricLayerTest.php` exists and carries the named verification tests, and `tests/Support/Security/bin/verify-metric-layer-on-mysql.php` carries the evidence that cannot live in the SQLite suite.
- **Evidence:** docs/development/phase-5/PHASE-05-METRIC-DEFINITIONS.md (the definitions); tests/Feature/Metrics/MetricLayerTest.php; tests/Support/Security/bin/verify-metric-layer-on-mysql.php
- **Gap:** The definitions are measured against the metric classes, but **not against the surfaces merchants actually read**, because no surface reads a metric class yet (see `P05-02`). A definition verified against an unconsumed class has not yet been verified against the product.
- **Responsible:** Phase 05 (P5-2)
- **Next action:** Implement each named verification test as part of the metric it defines; the §6 test (two independent computations of net receipts must agree) is explicitly required to fail the build on disagreement.

### `P05-04` — Trustworthy analytics

**Status: PARTIALLY IMPLEMENTED** *(corrected 2026-11-17 — the row said "no figure in the product
carries a caveat today", and as of P5-10 two do.)*

- **Requirement (original wording / faithful reference):** "A metric with a known gap displays the gap. §9 is blocked, §5 excludes partials, §8 has a cost caveat, §4 has a date caveat. A number that hides its own limits is worse than no number — which is the whole point of 'trustworthy analytics'."
- **Source:** `docs/development/phase-5/PHASE-05-METRIC-DEFINITIONS.md §11 rule 5; CLAUDE.md §4 names Phase 05 scope as "campaigns, loyalty, reports and trustworthy analytics"`
- **Owner decisions:** None found.
- **Expected behaviour:** Every displayed figure that rests on an unresolved or incomplete record shows its caveat on the screen and in any export of it.
- **Actual behaviour:** **Two figures now carry a visible caveat, and they are the first in the product.** P5-10 wired disclosure into `/finance` for both places it renders profit: the `Potential Profit` tile and the per-order `Profit` column. Each names a measured count and, for the tile, the exact amount exposed — "Cost is not recorded for 1 of 2 in-stock products… overstated by up to that amount" — computed in the same pass that builds the figure, so it self-adjusts and falls silent when the data is complete. Rendered with `.ss-help` (contrast measured 8.28:1 light, 8.25:1 dark; `border-inline-start`, so it flips for RTL), translated in both languages, and pinned by `tests/Feature/Merchant/FinanceInventoryValuationTest.php`.
  **Everywhere else the row still stands.** No report screen and no export carries a caveat, and the 12-key `MetricCaveat` vocabulary built for exactly this purpose still has **zero consumers** outside `app/Support/Metrics/` and `tests/` — measured 2026-11-17. `COUPON_REDEMPTION_UNRELIABLE` is marked BLOCKING ("This figure is not authoritative", by owner instruction pending P4-F134/F135) and nothing renders it.
  **And the mechanism that was supposed to carry the caveats cannot display them.** `<x-ss.stat>` renders its `hint` inside `<span class="ss-visually-hidden">`, which `store-syria-ds.css:2238-2246` clips to 1×1 with no hover or focus reveal — verified by hand. Ten `:hint=` usages sit on that component across six files; nine are precisely Phase 05's "this is not payments received, and not profit" sentences, so that work was invisible to every sighted merchant. **FIXED by P5-11 (2026-11-17)** -- the hint now renders as a visible `<p class="ss-stat__hint">` associated with the figure by `aria-describedby`, measured at 5.98:1 light and 7.18:1 dark in all four language/theme combinations. The three sibling components all render their hint visibly, and no test pins the difference as intended.
- **Gap:** Caveat display on the report screens and in exports; a consumer for `MetricCaveat`; and the `<x-ss.stat>` defect, without which any caveat routed through that component would be invisible too.
- **Evidence:** docs/development/phase-5/PHASE-05-MKT-ANA-MEASUREMENT-2026-11-17.md §4 and §5.1; tests/Feature/Merchant/FinanceInventoryValuationTest.php
- **Responsible:** Phase 05 (P5-10 delivered the first two; P5-11 owns the `<x-ss.stat>` defect; P5-3/P5-8 own reports and exports)
- **Next action:** Fix `<x-ss.stat>` first — it is the cheapest change with the widest reach, and until it lands every caveat added through that component is written into a slot nobody can see.

### `P05-05` — P5-3 ReportController predicate replacement

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Point the existing report surfaces at the P5-2 metric layer, one surface at a time, with a before/after figure recorded for each. This changes numbers merchants currently see — every surface needs its delta stated, not just its code changed."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2, slice P5-3; §1 "REUSE THE SURFACE, REPLACE THE PREDICATES"`
- **Owner decisions:** None found. The owner instruction 'Do not reuse SUM(orders.final_price) as settled revenue merely because existing reports do' is quoted in PHASE-05-METRIC-DEFINITIONS.md and is not yet honoured in code.
- **Expected behaviour:** Each report surface reads a defined metric instead of an unfiltered SUM, and the change in the figure a merchant sees is recorded per surface.
- **Actual behaviour:** Unchanged. Measured in this pass: `wc -l app/Http/Controllers/ReportController.php` = 3660 and `grep -c payment_status` = 0 — exactly the numbers the 2026-11-16 inventory recorded. Every money figure in the merchant reporting suite is still computed over every order in the window whatever its payment state. HomeController's dashboard `$total_revenues` (:319) still filters on fulfilment only.
- **Gap:** All 53 of the 70 inventoried surfaces that depend on an unresolved ledger question, and the before/after delta record for each.
- **Responsible:** Phase 05 (P5-3), gated on P5-2
- **Next action:** After P5-2 exists, replace predicates surface by surface, recording the figure before and after.

### `P05-06` — P5-4 Merchant analytics dashboard

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Sales, orders, conversion and customer insight, bilingual (AR RTL / EN LTR), contrast-checked in light and dark, permission-gated and tenant-isolated. Built on P5-2 only."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2, slice P5-4; CLAUDE.md §4 "merchant analytics"`
- **Owner decisions:** None found.
- **Expected behaviour:** A merchant opens one analytics dashboard showing trustworthy sales, order, conversion and customer figures, in Arabic or English, correct in light and dark.
- **Actual behaviour:** No Phase 05 analytics dashboard exists. What exists is the pre-existing WorkDo merchant dashboard (HomeController) and the 29-method ReportController suite, both reading unfiltered figures. The project task ledger carries P5-B ('Phase 05 implementation — analytics, reporting, campaigns, loyalty') as pending.
- **Gap:** The entire slice.
- **Responsible:** Phase 05 (P5-4), gated on P5-2
- **Next action:** Not startable until P5-2 exists; the plan forbids building it on anything else.

### `P05-07` — P5-4 Conversion

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "conversion" is named as part of the merchant analytics dashboard (P5-4) and in the owner scope statement: "merchant analytics, platform reporting, sales and order metrics, conversion and customer insight..."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2 (P5-4) and §5`
- **Owner decisions:** None. The §3 exclusion is an engineering plan statement, NOT a cited owner decision, so it cannot retire this requirement.
- **Expected behaviour:** A merchant sees a conversion figure — visitors or sessions against orders — they can act on.
- **Actual behaviour:** No conversion metric exists. `grep -rln 'conversion_rate\|conversionRate' app/` returns nothing. `shetabit/visitor ^4.3` is installed (composer.json:59) and wrapped by app/Services/SanitisedVisitor.php, and HomeController imports the facade, so visit data may be recorded — but nothing computes a conversion rate from it. PHASE-05-METRIC-DEFINITIONS.md defines ten metrics and conversion is not among them.
- **Gap:** Conversion has no definition in the metric document and no slice of its own, while the plan's §3 says 'Invent metrics, customer events or campaign outcomes' is out of scope and 'There is no event stream in this repository and none is being created'. Whether visitor-log-based conversion satisfies the requirement, or whether the requirement is being quietly dropped, is unresolved.
- **Responsible:** Owner decision on what 'conversion' means here, then Phase 05
- **Next action:** Ask the owner whether conversion is to be computed from the shetabit/visitor log, or whether it is deferred; either way record the answer rather than letting the metric document's silence settle it.

### `P05-08` — P5-4 Customer insight

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "customer insight" as part of the merchant analytics dashboard, and "customer insights" in the Phase 05 scope statement.
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2 (P5-4) and §5`
- **Owner decisions:** None found.
- **Expected behaviour:** A merchant sees who their customers are and what they are worth, on figures they can trust.
- **Actual behaviour:** Pre-existing surfaces partly deliver this and all of them are untrustworthy by the phase's own standard. ReportController::index computes new/returning customer and guest counts per period, store-scoped. Api/DashboardController serves a customer list with lifetime spend as SUM(orders.final_price) per customer (inventory rows: ':6417-6420' and ':8661-8667'), which the inventory marks dependent on F124, F126 and F127 — 'total spend counts cancelled, unpaid and refunded orders'. No Phase 05 work.
- **Gap:** Every money-bearing customer figure counts cancelled, unpaid and refunded orders. No customer metric is defined in PHASE-05-METRIC-DEFINITIONS.md.
- **Responsible:** Phase 05 (P5-4), gated on P5-2
- **Next action:** Define customer lifetime value against §2 (collected payments) rather than §1 (GMV), then repoint both the web and Flutter surfaces.

### `P05-09` — P5-5 Campaign management

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Campaign *management* is not blocked; campaign *outcome measurement* is." Campaign management is named in the Phase 05 scope statement and in CLAUDE.md §4 ("campaigns").
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2 (P5-5) and §5; CLAUDE.md §4`
- **Owner decisions:** None found.
- **Expected behaviour:** A merchant can create and run campaigns from a supported surface.
- **Actual behaviour:** A vendored module provides it and has not been inventoried. packages/workdo/Campaigns/src/routes/web.php registers campaign CRUD, settings, cost setup, enable/disable, a payment path and campaigns-analytics/{id}, all behind PlanModuleCheck:Campaigns. packages/workdo/Marketing and packages/workdo/BoostSales also exist. No first-party campaign surface exists and no Phase 05 engineering has happened.
- **Gap:** The plan's own precondition — "campaigns ... inventory before building" (§1) — has not been performed, so it is unknown whether the vendored module is enabled, authorised, tenant-safe or usable. CLAUDE.md warns that no WorkDo module loads in any test run without an add_on_managers row, so no test covers these routes.
- **Responsible:** Phase 05 (P5-5)
- **Next action:** Inventory the Campaigns / Marketing / BoostSales modules for tenancy, authorization and plan gating before deciding whether a first-party wrapper is needed.

### `P05-10` — P5-5 Coupon and promotion analytics

**Status: OWNER DECISION REQUIRED**

- **Requirement (original wording / faithful reference):** Owner instruction, quoted verbatim: "Review P4-F134 and P4-F135 before using coupon-redemption records as authoritative data." The metric document marks coupon redemption "BLOCKED AS AN AUTHORITATIVE FIGURE, by instruction" and the plan says "Until those are resolved, coupon redemption is reportable only with its two-directional error displayed."
- **Source:** `docs/development/phase-5/PHASE-05-METRIC-DEFINITIONS.md §9; docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2 (P5-5)`
- **Owner decisions:** The owner instruction to review F134/F135 first is quoted verbatim in the metric document; no record of that review exists.
- **Expected behaviour:** Coupon and promotion performance is reported only once redemption records are known to be accurate, or else with its error stated on the screen.
- **Actual behaviour:** Not built, and the owner-mandated review has not happened. P4-F134 (ordersave may record no redemption at all — under-counts) and P4-F135 (a refused coupon still writes order_coupon_details and user_coupons — over-counts) are both recorded as open in the metric document and in PHASE-04-FINAL-REPORT.md §8. Both errors are unquantified. Pre-existing surfaces already present coupon money with no caveat: ReportController's coupon total, app/DataTables/UserCouponDataTable.php's Amount column.
- **Gap:** The owner's required review of F134/F135 has not been performed or recorded, so no coupon figure can be made authoritative, and the existing uncaveated coupon totals keep shipping.
- **Responsible:** Owner review of P4-F134 / P4-F135, then Phase 05 (P5-5)
- **Next action:** Put F134/F135 to the owner as a decision; until then do not build a coupon-redemption report, and consider caveating the existing coupon totals.

### `P05-11` — Loyalty

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "loyalty" is named in the owner-derived Phase 05 scope in two places: CLAUDE.md §4 ("campaigns, loyalty, reports and trustworthy analytics") and the plan's own scope statement ("...coupon and promotion analytics, loyalty, retention, marketing performance..."). PHASE-04-FINAL-REPORT.md §8 repeats it: "Phase 05 is Marketing & Analytics ... campaigns, loyalty, reports and trustworthy analytics."
- **Source:** `CLAUDE.md §4; docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §5; docs/development/phase-4/PHASE-04-FINAL-REPORT.md §8`
- **Owner decisions:** None. No owner decision deferring or descoping loyalty was found anywhere in the repository.
- **Expected behaviour:** A loyalty capability — points, rewards or tiers — exists for merchants and is measurable.
- **Actual behaviour:** NOT MEASURABLE, which is the half of this requirement that is still open. The metric document defines no loyalty metric and no merchant-facing loyalty surface exists. **The slice gap is closed:** loyalty was carried by none of the plan's original eight slices (P5-1…P5-8) and appeared only in the §5 prose scope statement; **`P5-9 — Loyalty` was added to the plan on 2026-11-17** rather than deferred, so the plan's slice list is now P5-1…P5-9. The underlying capability does exist and has now been measured (`PHASE-05-MKT-006-REWARD-REVERSAL.md`): the vendored `packages/workdo/RewardClubPoint` earns and redeems points, **one** listener (`AddRewardClubPointLis`) is its only writer, and `app/` holds **zero** writes to `customers.total_club_point` — all 11 first-party references are reads that spend the balance. Those eleven spend paths shared one copy-pasted expression that threw `DivisionByZeroError` during payment confirmation whenever `reward_points` was absent or 0; that is fixed in `App\Support\Money\RewardPointDiscount` with the arithmetic proved unchanged on every configured input. No loyalty *metric* or merchant-facing loyalty surface was built.
- **Gap:** The measurable loyalty capability, and — more seriously — the requirement's omission from the execution plan's slice list. This is precisely the failure mode the owner's accounting rule exists to prevent: a requirement present in the scope statement and absent from the work breakdown.
- **Recovered decision IDs now traced here:** **`MKT-005`** (loyalty points, VIP and referrals, with a transaction log) — **PARTIAL**: points and the `RewardClubPoint` transaction log exist; VIP tiers and referrals were not located and remain open, see `GAP-191`. **`MKT-006`** (reverse rewards on cancellation and refund) — **NOT MET**, measured: the module contains no cancel, refund or reversal handling at all, so a shopper keeps the points for an order that was cancelled or refunded and can spend them on the next one. Recorded as not met, **not** deferred — per CLAUDE.md §4.1 an engineering report deferring something is not an owner decision. **Update, 2026-11-18 (D6 stage 1, `GAP-203`):** the redemption thresholds and the programme switch are now enforced server-side at the quote and at the WhatsApp / Telegram writer, and a refusal reaches the event so a shopper's points are not spent for a discount they did not get. **The gateway-return handlers are not covered** (a shopper who starts a checkout as one customer and returns as another is priced from one and debited from the other — `GAP-214`, an owner decision), so `MKT-005` stays **PARTIAL** and D6 is not closed.
- **Responsible:** Phase 05
- **Next action:** Add a loyalty slice to the Phase 05 plan, or obtain an explicit owner decision to defer it; do not let the slice list stand as the definition of scope. For MKT-006 specifically, four remedy options with their consequences are in `docs/development/phase-5/PHASE-05-MKT-006-REWARD-REVERSAL.md` §3d — all of them change customer-visible loyalty balances, so none was taken. A backfill of historical balances is explicitly out of scope.

### `P05-12` — P5-6 Retention and repeat purchase

**Status: VERIFIED COMPLETE** *(2026-11-20)*

- **Requirement (original wording / faithful reference):** "Derived from `orders` + `customers`, no new events invented. Gate: P5-2 (a repeat-purchase rate computed over unsettled orders is not a retention figure)."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2, slice P5-6; §5 scope statement ("retention")`
- **Owner decisions:** **D1** (historical coverage, Option A) governs the period the surface may claim. Reconciled 2026-11-20: the §3 question of `PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md` that this slice was recorded as blocked on **is** D1, already answered and already implemented in `LedgerCoverage`. No new decision was required and none was asked for.
- **Expected behaviour:** A merchant sees repeat-purchase and retention rates computed over settled orders only.
- **Actual behaviour:** **Metric, surface and written definition all exist.** `app/Support/Metrics/CustomerRetention.php` + `RetentionFigures.php` compute the figures through the P5-2 metric layer; the settled-orders-only gate the plan demands is a constructor flag with its own test contrasting it against the unsettled reading. Customers are identified on `customer_id > 0` alone — deliberately **not** on `is_guest`, because `PaymentService` assigns a real `Customer` id on its guest branch, so an `is_guest` predicate silently dropped identified repeat buyers (found by a mutation that failed to redden). The surface is the **`Repeat purchase` tile on `/analytics`** (`MerchantAnalyticsFigures::retention()`), which measures over `LedgerCoverage::window()` rather than the screen's thirty days — a repeat purchase needs two orders, so a thirty-day window reports every store as less loyal than it is — states its period per D1 (*all time* / *from &lt;date&gt;*), renders *Not available* with the reason when the ledger can vouch for no period, and discloses by count the orders it excluded (guest orders; orders with no financial row). Three tests pin those three behaviours and **three complementary mutations were run**, each reddening only its own case. The metric is now documented as **§12 of `PHASE-05-METRIC-DEFINITIONS.md`**, and `CustomerRetention::definition()` cites it; `MetricDefinitionsAreDocumentedTest` pins every metric's citation to a section that exists and every metric section to exactly one class, in both directions (4 mutations, one of which reproduces this row's own former state).
- **Gap:** None outstanding for this slice. Cohort retention, churn and lifetime value are **not** part of it and are named as not-built in §12, since each needs either a subscription record or an assumption about what counts as churned.
- **Responsible:** Phase 05 (P5-6), gated on P5-2
- **Next action:** None. Not independently accepted — Phase 05 acceptance covers it.

### `P05-13` — Marketing performance reporting

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "marketing performance" is named in the Phase 05 scope statement: "...coupon and promotion analytics, loyalty, retention, marketing performance, bilingual premium dashboards, permissions, tenant isolation and export".
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §5`
- **Owner decisions:** None found.
- **Expected behaviour:** A merchant can see whether their marketing activity produced orders and revenue.
- **Actual behaviour:** Not built. The nearest slice is P5-5 (campaign & promotion analytics), which **is no longer gated** — F134/F135 were measured, fixed and mutation-proved on 2026-11-20, and coupon redemption is now authoritative per window (`PHASE-05-METRIC-DEFINITIONS.md` §9). No marketing-performance metric is defined and no surface exists. The vendored Campaigns module's campaigns-analytics/{id} route is the only thing by that name and has not been inventoried.
- **Gap:** The requirement has no slice of its own and no metric definition; it is only partially implied by P5-5.
- **Responsible:** Phase 05
- **Next action:** State explicitly whether marketing performance is P5-5 under another name or a separate deliverable; do not let it be absorbed silently.

### `P05-14` — P5-7 Platform / operator reporting

**Status: VERIFIED COMPLETE** *(2026-11-20)*

- **Requirement (original wording / faithful reference):** "The super-admin view of the same metrics across tenants. Operator-first authorization (`MerchantPermissions::allowsAny()`), never bare `isAbleTo()`." The scope statement names it "platform reporting".
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2, slice P5-7; §5`
- **Owner decisions:** None required. D1 governs the period a ledger-based figure may claim and is applied.
- **Expected behaviour:** The platform operator sees cross-tenant metrics, with authorization that tests operator status before any Laratrust permission check.
- **Actual behaviour:** **Built.** `/platform/analytics` (`PlatformAnalyticsController` → `PlatformAnalyticsFigures` → the P5-2 metric layer), gated by the pre-existing `PlatformOperatorOnly` middleware. **The gate is identity and NOT a permission, and that is the one decision in the slice.** For a cross-tenant screen "operator-first" collapses to operator-ONLY: `ReportPermission`'s second and third limbs admit the tenant owner and any holder of `Manage Reports`, both merchants, and a merchant creates their own roles (`RoleController::store`, reachable with the `Create Role` that the shared `admin` role carries for every self-registered merchant), so a permission name is substantially self-assignable. `config/superadminaccess.php` was considered and not used: it is a 15-name *grantability* control over plan / country / language / module / currency administration, filtered server-side on both role write paths, and widening it to cover reporting would change an unrelated surface to express what `users.type` already says. Three things the data cannot support are refused rather than faked: money is reported **per currency and never summed** (no historical FX record exists in this schema — `currency_rates` holds current rates and nothing stamps a rate onto an order); a cross-store total is dated from the **latest** `verifiedFrom` among the included stores, because a sum is only as covered as its least-covered contributor; and a store whose ledger can vouch for no period is **excluded and counted**, never counted as zero.
- **Evidence:** `tests/Feature/Platform/PlatformAnalyticsScreenTest.php` (9 tests). The differentiating case is `test_a_staff_member_holding_manage_reports_is_still_refused`, which first asserts as a premise that the same principal opens `/analytics` with HTTP 200. **Two mutations:** swapping the middleware to `ReportPermission` reddens all three merchant-refusal tests while the operator tests still pass; reverting the chrome fix reddens the two operator tests.
- **Gap:** None outstanding. The pre-existing operator-dashboard top-five-stores panel is a separate unfiltered-GMV surface, still carried by `P05-05`, and is not claimed by this row.
- **Responsible:** Phase 05 (P5-7), gated on P5-2
- **Next action:** None. Not independently accepted — Phase 05 acceptance covers it.

### `P05-15` — P5-8 Export

**Status: VERIFIED COMPLETE** *(half (a) 2026-11-20; half (b) closed 2026-11-21)*

- **Requirement (original wording / faithful reference):** "Wherever the existing export surfaces already support it. Exports carry the same caveats as the screens; an export that drops a caveat is a defect." The scope statement names "data export".
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2, slice P5-8; §5`
- **Owner decisions:** None found.
- **Expected behaviour:** Every export carries the same exclusions and caveats as the screen it came from.
- **Actual behaviour, in two halves, because the two are genuinely different problems.**
  **(a) THE HONEST SURFACES NOW EXPORT, and this row's own next action is what was built:** "treat the export as a view of the metric object so a caveat cannot be dropped at the export boundary". `App\Support\Metrics\FigureCsv` is handed the SAME array the Blade is handed — no second query, so screen and file cannot disagree about a number — and makes the period and the disclosure **columns**, so no sorting or copying in a spreadsheet can separate a figure from its limits. A null exports as the words *Not available*, never as a blank, because a blank cell reads as zero. Bidi isolates are stripped from the value column only (a spreadsheet treats them as text, breaking `=SUM()`), and `fputcsv` with a UTF-8 BOM handles quoting and Arabic. `/analytics/export` carries `ReportPermission`, the same gate as its screen; `/platform/analytics/export` carries `PlatformOperatorOnly`, the same as its screen.
  **(b) `ReportController`'s EXPORTS NOW CARRY A DISCLOSURE — five of six, and the sixth was measured, not skipped.** As of 2026-11-20 this half was *vacuous*: `MetricCaveat` had **zero** consumers outside `app/Support/Metrics/` and `tests/`, so there was nothing on any report screen for an export to carry. `ANA-007` closed that for the category and brand screens; this row's remaining work was to get the sentences into the files.
  **The enforceable version of the rule is one source, not one sentence repeated.** Measured 2026-11-21: the "Order value" definition was an *identical string literal in four blades*, and because nothing read it from one place, **two of the four carried the precision caveat beside it and two silently did not**. The duplication is what permitted the divergence. `App\Support\Reports\ReportFigureNote` now holds both definitions; the screens' `<x-ss.stat>` `:hint` and the export handlers call the same method, so the file and the screen cannot disagree because there is one string. `ReportFigureNoteIsTheOnlySourceTest` asserts each sentence appears in exactly one first-party file.
  **Which caveat, and why only that one.** Every money figure on these surfaces is accumulated in PHP as `+= (float)` (or `array_sum()` / `->sum()`) off `orders.final_price`, `delivery_price` and `coupon_price` — all FLOAT columns — which is precisely `FLOAT_PRECISION`, with no exceptions. `MONEY_FLOAT_PRECISION` was added as its **plural**, not as a new definition: `order_report` and `bar_chart_order_report` write nine columns of which **six are money**, so "This figure" cannot name which it means. No payment-status wording was invented — `payment_status` appears **zero** times in these handlers, and monetary definitions are the owner's D1/D2 decision.
  **The sixth export is correctly caveat-free, measured.** `export()` returns `monthList`, `userTotal`, `registerTotal`, `guest`, `newguestTotal` — all counts, no money — so there is no money caveat to carry; its screen twin `chart_data.blade.php` is correctly caveat-free too, and its view is `ReportCsvHeaderLocalisationTest`'s protected exemplar.
  **Screen half completed in the same change:** `order_product_chart` and **both** money tiles on `_sales_stats` gained the caveat they were missing; the two chart fragments became a pure refactor to the same string. No tile was added, so the `<x-ss.stat>` hint census stays at 23.
  Their *series* half was reconciled earlier by P5-3, which found and fixed two HIGH wrong-data bugs (`category_order_export` and `brand_order_export` each exported one series repeated) and one MEDIUM (`order_bar_report_export` was two columns narrower than its screen).
- **Evidence:** `tests/Feature/Analytics/FigureExportTest.php` (11 tests, 68 assertions) — the gate on both exports, that the file carries every tile the screen shows, that every row has its own period and disclosure, that an unavailable figure is words not a blank, that no bidi isolate reaches a value cell, that each currency is its own row and none holds the sum, and that a comma-bearing disclosure stays in one cell. **Five mutations, each caught:** drop the disclosure column; export a null as a blank; sum the currencies; weaken the platform export's gate to `ReportPermission`; drop one tile from the label list. Pre-existing: `ReportExportControlContractTest`, `ReportExportFailureFeedbackTest`, `ReportCsvHeaderLocalisationTest`, `ReportExportBuildsEverySeriesTest`.
- **Gap:** **None for this requirement.** Two residuals are tracked elsewhere and are deliberately *not* folded in here: (1) the `Shipping charged` and `Coupon value applied` tiles on `_sales_stats` carry **no hint at all** — they are money on the same measured float basis, so a caveat there would be honest, but it is a NEW on-screen disclosure rather than an export dropping an existing one, and adding two tiles' hints moves the `<x-ss.stat>` census; it belongs to `ANA-007`'s visible half and is recorded as `GAP-217`. (2) These exports still inherit the unfiltered predicates of `P05-05`, which is a question about *which orders* the figure counts, not about whether the file discloses its limits. **CORRECTED 2026-11-21:** this row previously said those surfaces are unreachable by the suite and that their evidence must be an executable script. That followed CLAUDE.md's "`ReportController` cannot run under the suite AT ALL", which is **too strong** — measured: `order_category_reports` (1893-2360) and `order_brand_reports` (2382-2903) contain **0** `MONTH(`/`YEAR(`/`selectRaw`/`DB::raw`/`whereRaw`/`havingRaw`/`orderByRaw` constructs, so those two handlers **are** suite-reachable. Whole-file counts are 37 and 10, not 36 and 9. That makes `ANA-007` closable with a REAL TEST on those two screens rather than only an executable script, and CLAUDE.md has been corrected.
- **Responsible:** Phase 05 (P5-8), gated on P5-2 and P5-3
- **Next action:** None. Closed 2026-11-21. Evidence for half (b): `tests/Feature/Reports/ReportExportsCarryTheScreensCaveatTest.php` (7 tests, 108 assertions — including the exported sentence compared against the SCREEN's rendered fragment, so the rule is measured at both ends) and `tests/Unit/Reports/ReportFigureNoteIsTheOnlySourceTest.php` (4 tests). **Seven mutations, seven caught**, one of which — a VERBATIM copy of the composition pasted back into the controller — passed all seven Feature tests and was the reason the structural guard exists; the docblock that had claimed `assertSame` covered it was corrected rather than left standing.

### `P05-16` — Bilingual interfaces

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "bilingual (AR RTL / EN LTR)" for the merchant analytics dashboard; "bilingual premium dashboards" in the scope statement. CLAUDE.md: "Any UI change must be checked in both directions, and contrast-checked."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2 (P5-4) and §5; CLAUDE.md §6`
- **Owner decisions:** None found.
- **Expected behaviour:** Every Phase 05 surface renders correctly in Arabic RTL and English LTR.
- **Actual behaviour:** No Phase 05 surface exists to be bilingual. For the pre-existing reporting surfaces, localisation work landed earlier: CSV export headers are localised and pinned by a test, and resources/lang/ar.json carries ~2,848 keys. Whether the Arabic coverage of the existing report screens is complete was not established in this pass.
- **Evidence:** tests/Unit/Reports/ReportCsvHeaderLocalisationTest.php (pre-existing, export headers only)
- **Gap:** Applies to work not yet begun. Carry it as a standing acceptance condition for every Phase 05 surface rather than a separate task.
- **Responsible:** Phase 05
- **Next action:** Make AR/EN plus light/dark a per-slice acceptance condition at P5-4, as Phase 03 M7 did for the storefront.

### `P05-17` — Responsive premium dashboards

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "responsive premium dashboards" (task framing); the plan's §5 wording is "bilingual premium dashboards", and P5-4 requires the dashboard be "contrast-checked in light and dark".
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2 (P5-4) and §5`
- **Owner decisions:** The Phase 2.5 BLOCKED rows were dispositioned in docs/development/phase-2.5/PHASE-2.5-BLOCKED-DISPOSITIONS.md. I did not find an owner approval of that disposition for these three chart-body rows specifically.
- **Expected behaviour:** The analytics surfaces meet the premium visual bar in four combinations (AR/EN x light/dark), responsively.
- **Actual behaviour:** No Phase 05 dashboard exists. Phase 2.5 did redesign the report *shells*: the reports hub, Sales Report (line), Sales Report (bar variant), Stock Reports, Sales by Product and Sales by Category are all 'REDESIGNED AND VERIFIED' with four captures each. But the report *chart bodies* are BLOCKED — reports-chart, order-reports-chart and order-reports-chart-data each returned HTTP 500 in all four combinations because ReportController builds raw SQL with MONTH()/YEAR(), which SQLite does not implement; the stated remediation is a MySQL dev fixture.
- **Evidence:** docs/development/phase-2.5/PHASE-2.5-COVERAGE-MATRIX.md rows at :233-242 (shells verified with captures under evidence/web-sweep-2026-11-10/; three chart-body rows BLOCKED)
- **Gap:** No Phase 05 dashboard; and the existing chart bodies have never been visually verified in any combination because the dev fixture cannot render them.
- **Responsible:** Phase 05, with a MySQL dev fixture dependency
- **Next action:** Stand up the MySQL dev fixture so chart bodies can be rendered and captured at all; Phase 05 will need it regardless.

### `P05-18` — Permissions

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "permission-gated" (P5-4); "Operator-first authorization (`MerchantPermissions::allowsAny()`), never bare `isAbleTo()`" (P5-7); "permissions" in the scope statement.
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2 (P5-4, P5-7) and §5`
- **Owner decisions:** None needed; this traces to P1-SEC-09 in Phase 01.
- **Expected behaviour:** Only a principal holding the right permission can open or export a report; the platform operator is admitted before any Laratrust check.
- **Actual behaviour:** For the existing reporting surface this is genuinely implemented, by earlier phase work rather than Phase 05. app/Http/Middleware/ReportPermission.php is attached in ReportController::__construct (:28-31) and maps 29 controller actions to three permissions (Manage Reports / Manage Order Reports / Manage Stock Reports), defaulting an unmapped action to Manage Reports. Its ordering is correct per CLAUDE.md: operator string check, then tenant owner (creatorId() === id), then isAbleTo(). Refusals are a redirect or 403, never a 401, so the Flutter app is not force-logged-out. ReportController itself contains zero isAbleTo() and zero authorize() calls — the gate is the middleware, not the controller.
- **Evidence:** tests/Feature/Security/ReportAuthorizationTest.php exists; I did not execute it or read its assertions in this pass, so I cannot call it VERIFIED COMPLETE.
- **Gap:** No Phase 05 surface exists to gate. The operator-first requirement for P5-7 platform reporting has nothing to apply to yet.
- **Responsible:** Phase 05 for new surfaces
- **Next action:** Run ReportAuthorizationTest and record the result; extend the ACTIONS map as Phase 05 adds handlers (the middleware's census test is designed to catch an unmapped action).

### `P05-19` — Tenant isolation

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "tenant-isolated" (P5-4); metric rule 2: "Tenant predicate at the call site. `Order` is not in `config/tenancy.php`'s `scoped_models`, so no global scope will add `store_id` for you — on any principal, operator included."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §2 (P5-4); PHASE-05-METRIC-DEFINITIONS.md §11 rule 2`
- **Owner decisions:** None found.
- **Expected behaviour:** Every analytics query carries an explicit store_id predicate; no figure relies on a global scope.
- **Actual behaviour:** The existing ReportController applies explicit predicates — 147 getCurrentStore() call sites, used as `where('store_id', getCurrentStore())` on Order and Customer queries. The rule itself is correct and matches CLAUDE.md: Order is not in scoped_models. No metric layer exists, so rule 2 has nothing to govern yet.
- **Evidence:** tests/Feature/Security/ReportCategoryBrandTenantScopeTest.php exists (not executed or read in this pass)
- **Gap:** Phase 05's own call sites do not exist. The one deliberately cross-tenant surface (operator top-five-stores) has no stated isolation design.
- **Responsible:** Phase 05 (P5-2), with the tenant-authz-reviewer subagent per CLAUDE.md §6
- **Next action:** Make the tenant predicate a constructor argument of each metric object so it cannot be omitted, and review every metric with the tenant-authz-reviewer before commit.

### `P05-20` — Scope constraint

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Owner instruction, quoted verbatim: "Do not create a second analytics or campaign engine if equivalent functionality already exists." The plan adds: "campaigns — inventory before building. Vendored code must not be edited; a first-party campaign surface may wrap it but must not fork it."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md header and §1`
- **Owner decisions:** The constraint wording is the owner's, quoted verbatim in the plan header.
- **Expected behaviour:** Phase 05 reuses ReportController's surfaces, the existing coupon tables, StoreFinanceController, StoreNotificationService and the vendored campaign modules rather than building rivals.
- **Actual behaviour:** No second engine exists, but only because no Phase 05 engineering has started. The constraint has not yet been tested against a real build decision, and the precondition it depends on — the §1 instruction to inventory the vendored campaign modules before building — has not been performed.
- **Gap:** The required inventory of packages/workdo/Campaigns, Marketing, BoostSales and RewardClubPoint.
- **Responsible:** Phase 05
- **Next action:** Perform the module inventory before P5-5 begins, so the reuse-or-wrap decision is made on measurement.

### `P05-21` — Scope constraint

**Status: IMPLEMENTED - VERIFICATION INCOMPLETE**

- **Requirement (original wording / faithful reference):** "Edit vendored `packages/workdo/`" is listed under "What this phase will NOT do". CLAUDE.md §2 repeats it: do not edit vendored packages/workdo (except first-party LynomiaChat) as a shortcut.
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §3; CLAUDE.md §2`
- **Owner decisions:** Standing owner constraint, recorded in CLAUDE.md §2.
- **Expected behaviour:** packages/workdo/ is untouched by Phase 05.
- **Actual behaviour:** Honoured so far. Phase 03 closed with the `guard-no-workdo-changes` CI guard green and packages/workdo/ untouched; no Phase 05 commit in the visible git log touches it.
- **Evidence:** A `guard-no-workdo-changes` CI guard is recorded as green at Phase 03 close in docs/development/PROJECT-STATE.md; I did not run it against the current Phase 05 head.
- **Responsible:** Phase 05
- **Next action:** Keep the guard in the Phase 05 gate set; it is the mechanism that makes this checkable rather than asserted.

### `P05-22` — Refund metrics

**Status: OWNER-APPROVED DEFERRED**

- **Requirement (original wording / faithful reference):** Owner instruction, quoted verbatim: "For partial refunds, implement proportional financial handling only if actual partial amounts can be safely reconciled and made idempotent. If the existing ledger cannot safely represent a partial refund, leave the financial mutation blocked and document the missing capability rather than approximating it." The metric consequence: "Partial refunds are excluded entirely and must be reported as a known gap."
- **Source:** `docs/finance/P4-F127-PARTIAL-REFUND-CAPABILITY.md (owner instruction quoted at the head); PHASE-05-METRIC-DEFINITIONS.md §5`
- **Owner decisions:** The owner's instruction is conditional and its condition was met and documented, which is what makes this an approved deferral rather than an engineering one. The condition does not extend to the reporting caveat.
- **Expected behaviour:** Either proportional partial-refund handling exists, or the mutation stays blocked with the missing capability documented and every refund figure stating that partials are excluded.
- **Actual behaviour:** The determination was made and documented: five measured properties each independently block proportional handling (refund_amount written by assignment not accumulation; at most one order_refunds row per order with no unique constraint; per-line detail is untyped display text in a VARCHAR(255) blob; refund_amount carries no currency; every money column is FLOAT). The mutation is left blocked, as instructed. The *metric* half — excluding partials and reporting the exclusion as a known gap on screen — is not implemented, because no refund metric exists.
- **Evidence:** docs/finance/P4-F127-PARTIAL-REFUND-CAPABILITY.md §1-§2 (the measured determination)
- **Gap:** The deferral covers only the financial mutation. The requirement that every refund figure display the partial-refund exclusion is NOT implemented and is not covered by the owner's instruction.
- **Responsible:** Phase 05 for the metric caveat; the mutation stays deferred
- **Next action:** Do not let the approved mutation deferral be read as covering the reporting gap; carry the caveat requirement under P05-04.

### `P05-23` — Verification standard

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Eight-point standard, per slice: "(1) a demonstrated failing case; (2) a positive control; (3) a negative regression; (4) falsification — revert the fix, confirm the test reddens; (5) independent review for anything touching money, authorization or tenancy; (6) real MySQL where SQLite's type affinity or writer serialisation could hide the defect; (7) the full applicable gates on the final committed tree; (8) honest documentation, including every caveat the metric carries."
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §4`
- **Owner decisions:** The plan states the standard is unchanged from the financial work "because the owner's standard does not change between phases".
- **Expected behaviour:** Every Phase 05 slice is accepted only against all eight points.
- **Actual behaviour:** No Phase 05 analytics slice has been delivered, so the standard has not been applied to any. It was applied to the gating financial work (F124, F126, F127, F032, F145 each shipped with falsified controls and, for F032, verification on real MariaDB with 13 checks).
- **Evidence:** tests/Feature/Commerce/ (CancellationLedgerSemanticsTest, CodAssertedPaidAtCreationTest and siblings) for the financial gate work only
- **Gap:** Applies to work not yet begun.
- **Responsible:** Phase 05
- **Next action:** Carry the eight points into each slice's acceptance, especially (6): the report chart bodies already fail on SQLite, so a MySQL fixture is not optional here.

### `P05-24` — Phase 05 reporting honesty

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Work completed will be reported as completed and work not started will be reported as not started — the final report states, per slice, which of the two it is, with evidence." Plus the vocabulary rule: an approved design is never described as implemented until measured, and engineering completion is never described as production readiness.
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §0 and §5`
- **Owner decisions:** None found.
- **Expected behaviour:** The Phase 05 final report states per slice whether it was completed or not started, with evidence, and never conflates engineering completion with release readiness.
- **Actual behaviour:** No Phase 05 final report exists; docs/development/phase-5/ holds three files only (execution plan, metric definitions, reporting-surface inventory). The project task ledger carries P5-Z ('Phase 05 final report + PROJECT-STATE') as pending. PROJECT-STATE.md §2 still records Phase 05 as 'not started'.
- **Gap:** The report, and with it the per-slice completed/not-started statement this matrix is currently standing in for.
- **Responsible:** Phase 05 (P5-Z)
- **Next action:** When the report is written, give each of P5-1…P5-9 (P5-9 — Loyalty was added 2026-11-17) an explicit verdict, and carry the loyalty, conversion and marketing-performance requirements that have no slice.

### `P05-MKT-ANA` — the eighteen recovered MKT and ANA decision IDs

**Status: MEASURED, NONE VERIFIED COMPLETE** *(added 2026-11-17)*

- **Requirement (original wording / faithful reference):** the ten `MKT-*` and ten `ANA-*`
  decision texts in `master-plan/DECISION-ID-REGISTER.md` §`MKT-001`…`MKT-010` and
  §`ANA-001`…`ANA-010`. `MKT-005` and `MKT-006` are traced under `P05-11` and are not repeated
  here, which leaves **eighteen**.
- **Source:** `docs/development/master-plan/DECISION-ID-REGISTER.md:122-155`
- **Owner decisions:** None. The requirement texts are the owner's, recovered verbatim; no
  owner decision has been taken on any of the eighteen.
- **Expected behaviour:** each decision text is satisfied by the shipped product.
- **Actual behaviour:** all eighteen were measured against the code on 2026-11-17 and each
  verdict was then challenged by two opposed adversarial passes. **Seventeen are PARTIALLY
  IMPLEMENTED; one (`ANA-010`, a prohibition) is machine-verified complete and is NOT counted
  as verified here.** Seventeen of the eighteen verdicts were challenged by at least one lens.
  Per-id detail, citations and the two lenses' findings are in
  `docs/development/phase-5/PHASE-05-MKT-ANA-MEASUREMENT-2026-11-17.md`.

| id | requirement | measured | the half that is missing |
|---|---|---|---|
| `MKT-001` | An independent marketing section, linked to analytics | **PARTIAL** | 'linked to analytics' (مرتبط بالتحليلات) is not implemented in the marketing-to-analytics direction. grep for route('reports / analytic / chart across resources/views/{coupon,newsletter,flash-sale,wishlist,cart} returns ZERO … |
| `MKT-002` | Simple by default, advanced when needed | **PARTIAL** | "Advanced when needed" on the marketing surfaces — ABSENT, and this is the decisive measurement. Zero of the five Marketing-menu surfaces (Coupon, Newsletter, Flash Sale, Wishlist, Abandon Cart) uses the project's own … |
| `MKT-003` | Coupons, automatic discounts and BOGO | **PARTIAL** | BOGO does not apply to variant products AT ALL. Both of the only two call sites that invoke the rule engine gate on `empty($cart_value->variant_id) && $cart_value->variant_id == 0` — app/Models/Cart.php:594 and … |
| `MKT-004` | Static and dynamic customer segments | **PARTIAL** | Neither half is a usable segment feature. DYNAMIC exists only in LynomiaChat Audiences, which ships behind an engineering kill switch — `LYNOMIA_INTEGRATION_ENABLED` defaults false and both env files set it false (verified by hand) — so **no store can reach it as shipped**. STATIC has no dedicated table; the measuring pass said it existed nowhere and **lens 1 refuted that**: vendored `ProductPricing` persists a merchant-picked customer list per rule, which is a static membership set in all but name. |
| `MKT-007` | Recover carts and incomplete payments, without duplication | **PARTIAL** | NO AUTOMATED OR SCHEDULED CART RECOVERY. Recovery is one merchant click per cart row. There is no app/Jobs directory at all, and the only scheduled commands in the whole tree are zid:process-events (routes/console.php:9) and … |
| `MKT-008` | Respect customer eligibility and consent for messages | **PARTIAL** | A customer-level consent flag: `customers` has NO consent/eligibility column at all. Read the create migration (database/migrations/2023_12_15_112855_create_customers_table.php, all 25 columns) and `Customer::$fillable` — there … |
| `MKT-009` | Lynomia integration when ready, without duplication | **PARTIAL** | NO GUARD AGAINST THE PLATFORM'S OWN COMPETING SHOPPER-MESSAGING PATH. `POST abandon-carts-messagesend` (CartController@abandon_carts_messsend, /home/user/syriastore98/app/Http/Controllers/CartController.php:1438) and `POST … |
| `MKT-010` | In-store conversion tools, linked to the themes | **PARTIAL** | greentic's product card omits the quick-view/compare hook entirely. themes/greentic/views/components/product-card.blade.php calls productSalesPage (line 5) and ProductcardButton (line 42) but NEVER … |
| `ANA-001` | A centre for sales, finance, products, customers and marketing | **PARTIAL** | FINANCE is not in the centre. The Reports group has no finance screen and no finance permission, and ReportController's 3,835 lines contain 0 occurrences of payment_status, refund, cost_price, profit or margin ('tax' only as a … |
| `ANA-002` | Comparisons, filters and saved periods | **PARTIAL** | **ALL THREE CAPABILITIES NOW EXIST ON `/analytics`, AND ON NOTHING ELSE — 2026-11-21.** (a) COMPARISONS: prior-period baseline, delta and a withheld-when-undividable percentage on the two count tiles; the money tiles are deliberately excluded on ledger-coverage grounds (`store_order_financials` holds nothing before 2026-11-02 and is not backfilled, so a baseline reaching back 31–60 days would be understated by an unknown amount and the delta would be a false claim dressed as a trend). (b) FILTERS: `App\Support\Analytics\AnalyticsPeriod` resolves seven presets plus a custom range from `ss_period`/`ss_from`/`ss_to` — an allowlist, a strict `!Y-m-d` parse (so `yesterday`, `+1 week` and `2026-02-30` are refused rather than reinterpreted), a reversed-range swap, a 731-day span cap and a future-end clamp, both clamps DISCLOSED on the screen and carried into the CSV as a row. The window is caller-supplied; the STORE is not, and `AnalyticsPeriodFilterTest` drives five presets plus a 2020→today custom range against a second store's rows in every window. (c) SAVED PERIODS: reuse the Phase 02 `SavedView` store — `(store_id, created_by)` ownership, neither column fillable, sanitised on read against `TableFilterMemory::vocabularyFor('analytics')` — so **no migration was needed**. `ss_view` is resolved through `forCurrentPrincipal()`, never `find()`; `saved_views` is not in `scoped_models`, so that is the only thing keeping one merchant's saved periods out of another's screen, and a foreign row is driven in the test. `SavedViewPolicy` gained `'analytics' => 'Manage Reports'` plus an `OWNER_ADMITTED_SCREENS` exception routed through `ReportAccess::permits()`, because `/analytics` admits the tenant owner without the permission row and otherwise the owner would read the screen and be refused its own Save button. **WHAT KEEPS THIS PARTIAL, stated precisely rather than left to a reader:** `ReportController`'s report screens have **no period filter at all** — measured, 0 of 24 `resources/views/reports/*.blade.php` contain `type="date"` or a date-range control — so a merchant can filter the analytics centre and cannot filter any of the twenty-four report screens beside it. Comparisons and saved periods are likewise absent there. Tracked as `GAP-221`. Original measurement, now historical: COMPARISONS — absent entirely; no period-over-period figure, prior-period baseline, delta, percent change or growth rate existed in either repository or any vendored module. |
| `ANA-003` | Custom reports, export and scheduling, permission-gated | **PARTIAL** | **SCHEDULING AND EXPORT NOW EXIST FOR THE ANALYTICS CENTRE — 2026-11-21. CUSTOM REPORTS DO NOT.** (a) EXPORT: `/analytics/export` ships the same figures as the screen, every row carrying its own period and disclosure (`FigureCsv`), filterable since ANA-002. (b) SCHEDULING: `analytics_report_schedules` + `ScheduledReportRunner` + `analytics:send-scheduled-reports`, registered **hourly** in `routes/console.php` beside the pre-existing `zid:process-events`. The file a schedule sends is produced by the SAME `MerchantAnalyticsFigures` and `AnalyticsExportRows` as the download — asserted **byte for byte**, so the clicked file and the scheduled file cannot drift. The due claim is a conditional write, not a read-then-send, because two `schedule:run` ticks overlap whenever a mail server is slow; the next due time is computed from NOW, so a schedule missed for a month sends one report rather than thirty. (c) PERMISSION-GATED: both write routes carry `ReportPermission` (named explicitly in `ACTIONS` rather than relying on its fail-closed fallback), and authorization is RE-CHECKED at send time against the owning user — a schedule outlives the session that made it, so a permission checked only at creation is a permission that expires. **THE RECIPIENT IS NOT A PARAMETER**, and that is the security decision: it is written from the authenticated user's own account address, not fillable, and re-read from the user row on every run, so neither request input nor a hand-edited row can redirect store figures. An e-mail field here would be an authenticated mail relay on a timer. Sending to a colleague is a separate owner decision. **AND THE DISCLOSURE IS LOAD-BEARING:** delivery depends on the server running `php artisan schedule:run`, which the project's own Phase 7 readiness notes record as UNVERIFIED for production ("a deployment without a scheduler passes §4–§10 and fails silently"). So every row shows its last run, a row that has never fired says **"Never sent yet"** in words, and the screen tells the merchant that the server's scheduler may not be running. Without that, a dead cron entry looks exactly like a working feature. **WHAT KEEPS THIS PARTIAL:** CUSTOM REPORTS. A merchant cannot define a report — choose columns, measures or groupings — anywhere; the only schedulable artefact is the fixed analytics export. And the 24 `ReportController` screens have neither export scheduling nor a period filter (`GAP-221`), bounded by the same thing: most of that file cannot run under the suite at all. Original measurement, now historical: SCHEDULING — absent entirely, the hardest-checked of the four; the only scheduled commands in the tree were `zid:process-events` and six `lynomia:*` ones, none of them a report. |
| `ANA-004` | Conversion, device and channel analysis | **PARTIAL** | CONVERSION ANALYSIS -- absent entirely. No conversion rate, no funnel, no stage counts: `conversion_rate|conversionRate|funnel|add_to_cart_rate|checkout_rate` has zero hits across app/, packages/, resources/views/, routes/ in … |
| `ANA-005` | Separate sales, collection, settlements and profitability | **PARTIAL** | **THE ORIGINAL MEASUREMENT ON THIS ROW WAS STALE AND IS CORRECTED HERE (2026-11-21).** It read "COLLECTION HAS NO FIGURE", on the evidence that `sum('paid_amount')`, `SUM(paid_amount)` and `sum('remaining_amount')` appear ZERO times across app/, resources/ and packages/. That grep is **still literally true and it misleads**, because collection is not computed from `orders.paid_amount` at all: it is `App\Support\Metrics\CollectedCustomerPayments`, which reads `store_order_financials` and is dated by `paid_at`. A census for the wrong identifier reports absence, which is the same failure mode that let a cross-tenant stock drain through a `customer_id` sweep. **ALL FOUR OF OWNER DECISION D2's FIGURES (2026-11-17, with D1 Option A) NOW REACH A MERCHANT SCREEN**, each under its approved label and each saying what it is not: *Order value, including shipping and tax* and *Order value* on the sales report (`reports/_sales_stats.blade.php`, P5-3e); *Payments received* on `/finance` and `/analytics` (P5-16) **and, since this change, on the sales report beside Order value** — the one piece the labelling proposal recorded as decided-but-unbuilt; *Available to withdraw* on `/finance` with the D2-mandated "A running balance, not revenue." caption; *Estimated gross profit* on `/finance` and `/analytics`. The sales-report figure reads through the SAME assembler as the other two ledger figures (`FinanceLedgerFigures`, extended to accept a narrowing window), so there is one definition of collection, one caveat vocabulary and one coverage rule rather than a second copy. D1 Option A is enforced centrally: a caller may narrow the verified period, never widen it; a request reaching back past the ledger is answered from where the record begins **with the tile's own period replaced by the span actually read**, and one ending before the record begins is **refused with the date records start** — never rendered as a zero. The window mirrors the handler's own `whereDate` bounds rather than `chartPeriodLabels`'s prose, because the point of the tile is to cover the same orders as the six beside it. Neither handler is suite-reachable (5 `MONTH(`/1 `YEAR(` and 12/4), so the assembler, the partial and the handlers' source are pinned by `SalesReportCollectionFigureTest`, `SalesStatsPassesItsCollectionFigureTest` and `StatHintIsVisibleTest`, and both handlers are driven end-to-end on real MariaDB by `tests/Support/Security/bin/verify-ana005-sales-collection-on-mysql.php` (22 checks). **WHAT KEEPS THIS PARTIAL, measured not inferred:** (a) **RESOLVED 2026-11-21, so this is no longer what keeps the row partial.** The SETTLEMENT figure now goes through the metric layer: `WalletEntitlementFigure` reads `MerchantWalletEntitlement` and renders *Available to withdraw* as an `<x-ss.stat>` carrying the WALLET's own currency code, with the four sibling wallet figures moved in the same change. The wallet currency is written once at provisioning and is not rewritten when `defult_currancy` changes, so the two can disagree — the tile states both and converts nothing, and a store whose wallets span more than one currency gets a refusal and a list rather than a total. `GAP-222` CLOSED; `NetFinancialReceipts` still reaches no surface. (b) 22 of the 24 `ReportController` screens still show demand figures with no collection, settlement or profit counterpart, and `/finance` carries no *Order value*, so no single screen lets a merchant compare all four. (c) The two sales exports carry the screen's caveats (P05-15) but only the chart SERIES — they never carried the summary totals, so the seventh tile adds no export gap; a per-day collection series would be new metric work and is not requested by the approved proposal. **THREE THINGS THE AUTHORIZATION REVIEW OF THIS SLICE ESTABLISHED, recorded here rather than discovered later.** (i) The figure now ALSO renders behind `Manage Order Reports` (`ReportPermission` maps both handlers to `self::ORDERS`), where before it sat only behind `View Finance Dashboard` and `Manage Reports`. So a merchant's delegated "order-reports analyst" — a role carrying `Manage Order Reports` and neither of the other two — now reads the tenant's collected cash and its ledger-gap counts. Within one tenant, and implied by the owner's own approval of this placement in the labelling proposal, so it is not a defect; it is a permission consequence worth being on the record. (ii) A PLATFORM OPERATOR WHO HAS CHOSEN NO STORE gets no tile: `getCurrentStore()` returns the literal 1 for them, so without a limb this screen would have shown store 1's collected cash while `/finance` refuses the same principal the same assembler. The six demand tiles beside it still show store 1's order value — pre-existing, `GAP-223(b)`. (iii) `GAP-223(a)`: on 33 days in every five years the card's `last-month` label names a different month from its figures, for all seven tiles, because the handler's window uses `strtotime('-1 month')` and its label uses `'first day of last month'`. Measured over 1,827 days. |
| `ANA-006` | Do NOT claim precise profitability when cost or fee data is missing | **PARTIAL** | **The two profit figures named by this measurement are now disclosed** (P5-10, same day): the `Potential Profit` tile and the per-order `Profit` column each name a measured count of rows whose cost was never recorded. What remains is **fee data entirely** — `gross_profit` is `(product_amount − discount_amount) − product_cost`, so it silently excludes gateway and processing fees, `store_order_financials` has no fee column, and `MetricCaveat` has no fee constant — plus the schema's inability to tell "cost unknown" from "cost is genuinely zero". **Update, P5-16 (2026-11-18):** the new `/finance` *Estimated gross profit* tile states what it leaves out — shipping is not included and, if the store's prices include tax, the tax is part of the sales value; "at least N paid orders have no product cost on record" (a lower bound: an order that mixes costed and uncosted lines is never counted). **Fee data remains absent** and the metric layer's own caveat (`MetricCaveat::EXCLUDES_SHIPPING_AND_TAX`) is still half untrue for inclusive-tax stores (`GAP-213(d)`). Still PARTIAL. |
| `ANA-007` | Show data quality, estimates and omissions | **PARTIAL** | ESTIMATES (التقديرات) — no merchant analytics surface labels any figure as an estimate, approximation or assumption. Greps for estimate/approximate/assumed/assumption/provisional/unreliable across every non-storefront Blade view … |
| `ANA-008` | Document metric definitions and their sources | **PARTIAL** | **The `CustomerRetention` half of this row is CLOSED (2026-11-20).** It was: an implemented metric with no section in the definitions document, whose `definition()` returned `'PHASE-05-EXECUTION-PLAN.md P5-6'` — a real file that documented the slice, not the metric. It is now **§12**, naming the definition, the two bases and which one is default, the two exclusions reported as counts, why `null` is a real answer rather than `0.0`, and what is deliberately not built (cohort, churn, LTV). `MetricDefinitionsAreDocumentedTest` now pins the citations in **both** directions, so this class of drift cannot recur silently: every `definition()` must name a section the document has, and every numbered metric section must be claimed by exactly one class. **What remains PARTIAL is the rest of the requirement**, which is about the *reporting surfaces*, not the metric layer: `ReportController`'s screens and the six export handlers carry no definition or source text at all, and the definitions document covers the eleven metric-layer figures only. |
| `ANA-009` | Alerts and proactive analytics, with clear controls | **PARTIAL** | Proactive ANALYTICS alerts of any kind. No sales-drop, trend, anomaly, forecast or threshold-on-a-metric detection exists anywhere in app/ or packages/workdo/ — a repo-wide grep for sales drop / anomal / forecast / predictive / … |
| `ANA-010` | Do NOT build a new accounting engine | **COMPLETE (machine)** | — |

- **Gap:** eighteen requirements with a measured status and no owner acceptance. **Two outrank
  the rest and are not actionable without an owner decision.** `MKT-008`: four message senders
  (`CouponEmail` ×3, `ReviewReminder`, the newsletter list, the back-in-stock push) consult no
  consent flag, `customers` carries no consent column at all, and there is no unsubscribe path
  anywhere — every sender named is in a **vendored** module and a consent column is a
  **persistent migration**, both standing owner decisions. `ANA-007`: the `MetricCaveat`
  vocabulary has zero consumers, and `<x-ss.stat>` cannot display a hint at all.
- **Evidence:** docs/development/phase-5/PHASE-05-MKT-ANA-MEASUREMENT-2026-11-17.md
- **Responsible:** Phase 05 for the `ANA-*` reporting ids; owner for `MKT-008` and for any
  change to a vendored sender.
- **Next action:** Do not let the measured statuses harden into acceptance. `ANA-010` needs a
  human reading before it counts toward the verified total; `MKT-008` needs an owner decision
  before any code moves; the rest are ordinary Phase 05 scope with a now-known starting point.

> **These eighteen do not change the requirement totals.** They were already inside the 181
> not-verified; what changed is that they now have measured statuses and citations instead of
> nothing. 169 verified complete and 181 not are both unchanged.

### `P05-GATE-01` — Phase 05 dependency gate

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "No Phase 05 metric may be built on an outstanding row above." The gate table lists P4-F145, P4-F032, P4-F124, P4-F127 (partial), P4-F126, P4-F127 (full verified refund), P4-F128 (reconciliation + finance-screen audit) and P4-F046 (webhook.site egress + Benefit capture).
- **Source:** `docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md §0`
- **Owner decisions:** The gate itself derives from the owner's six financial principles in docs/finance/P4-FINANCIAL-POLICY-DECISION-PACKAGE.md §1. That package's own header still reads 'Status: AWAITING DECISION'; I found no document recording the owner's answers to F128 or F046.
- **Expected behaviour:** Before any Phase 05 metric or report is built, the ledger semantics it reads are settled, so a figure means one agreed thing.
- **Actual behaviour:** 5 of 8 gate rows are marked DONE in the plan (F145, F032, F126, F124 engine, F127 request-is-not-completion). Still outstanding by the plan's own table: F124's three non-engine cancel doors, F127 full verified refund, F128 reconciliation + finance-screen audit, F046. Git log on feature/phase-05-marketing-analytics shows commits for F145, F032, F124, F126, F127 and none for F128 or F046. The project task ledger carries P5-FIN-4 (F128), P5-FIN-6 (F046) and P5-FIN-8 (financial implementation gates) as pending.
- **Evidence:** tests/Feature/Commerce/CancellationLedgerSemanticsTest.php, tests/Feature/Commerce/CodAssertedPaidAtCreationTest.php and siblings exist in tests/Feature/Commerce/; no F128 or F046 artefact found
- **Gap:** F128 (which record a finance report should read) and F046 (whether a 'Paid' storefront order was ever captured) are unresolved, and both are named by the surface inventory as blocking 53 of 70 money surfaces.
- **Responsible:** Phase 05 financial workstream + owner decision on F128/F046
- **Next action:** Resolve F128 and F046, or record the owner's decision on each, before any metric is built on store_order_financials or store_wallet_transactions.

### `P06-01` — Phase 06 — source

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "Phase 06 — SaaS & Super Admin" — the phase name, quoted from the owner-authored master execution prompt, which quotes the approved Master Product Plan / Decision Register v1.0 of 2026-10-14 directly.
- **Source:** `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md:92 (owner-authored, preserved verbatim, marked not to be edited)`
- **Owner decisions:** docs/development/MASTER-PLAN-SOURCE-VERIFICATION.md §3.4 already records 'Any Phase 06 source at all' as unrecoverable; this pass independently confirms it.
- **Expected behaviour:** A Phase 06 requirements source exists against which delivery can be traced.
- **Actual behaviour:** VERIFIED IN THIS PASS: there is no Phase 06 source document of any kind. `docs/development/phase-6/` does not exist on disk (ls returns 'No such file or directory'), and `git log --all --name-only` across the entire history returns exactly three PHASE-6-* paths, all under docs/development/lynomia-integration/. The title above is the only owner-authored Phase 06 wording that survives anywhere in the repository.
- **Gap:** Everything below this row is DERIVED from summaries, not from an owner requirements document. No Phase 06 requirement can be certified against an original.
- **Responsible:** Owner — supply the Master Product Plan / Decision Register v1.0
- **Next action:** Obtain the original plan, in particular exclusions EX-001–EX-012, before Phase 06 is scoped. Note one small correction: MASTER-PLAN-SOURCE-VERIFICATION.md calls docs/development/phase-6/ an 'empty directory'; it does not exist at all, and never has in git history.

### `P06-02` — Phase 06 — scope (derived)

**Status: PARTIALLY IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "SaaS subscriptions" (CLAUDE.md §4) / "SaaS administration, plans, subscriptions" (PROJECT-STATE.md §2).
- **Source:** `CLAUDE.md §4; docs/development/PROJECT-STATE.md:44 — both DERIVED summaries of the absent plan`
- **Owner decisions:** None found for Phase 06.
- **Expected behaviour:** Merchants subscribe to plans, are billed, and lose access when a subscription lapses.
- **Actual behaviour:** Substantial pre-existing infrastructure, with open defects recorded at Discovery and partly repaired since. Present today: PlanController, PlanRequestController, PlanCouponController, BankTransferController, and ~37 plan-payment gateway routes. Discovery (capability 10) recorded two P0s — plan-expiry enforcement commented out in SetLocate.php, and `order_approve/{id}` grantable by any authenticated merchant with no authorization. I re-checked the second one in this pass and it HAS been fixed: routes/web.php:715 now attaches App\Http\Middleware\PlatformOperatorOnly, the handler is idempotent against an already-Approved order, wraps the grant in a DB::transaction and writes an AuditLog row. The expiry-enforcement finding was not re-checked.
- **Gap:** No Phase 06 engineering has begun. Discovery's remaining subscription findings (expiry enforcement commented out; no recurring billing — cashier-paddle installed but no config, no subscriptions migration, no subscribe() call; plans.price is a string column) are unverified at current HEAD.
- **Responsible:** Phase 06
- **Next action:** Re-measure docs/discovery/07-SAAS-GAP-ANALYSIS.md capability 10 against current HEAD before planning; at least one of its P0s is already fixed, so the register is stale in the safe direction and must not be restated as current.

### `P06-03` — Phase 06 — scope (derived)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "support" (CLAUDE.md §4 Phase 06 scope: "SaaS subscriptions, support, controls, operations, platform admin, plus premium Super Admin UX").
- **Source:** `CLAUDE.md §4 — DERIVED summary`
- **Owner decisions:** None found.
- **Expected behaviour:** Merchants can raise support requests and the platform can work them.
- **Actual behaviour:** Not established beyond module presence. packages/workdo/Helpdesk exists as a vendored module; no first-party support surface was found and no Phase 06 work exists. Whether Helpdesk is enabled, tenant-safe or authorised is not established from code in this pass.
- **Gap:** The whole requirement, and its definition — 'support' is a single word in a derived summary with no original behind it.
- **Responsible:** Phase 06
- **Next action:** Ask the owner what 'support' covers before inventorying Helpdesk against it.

### `P06-04` — Phase 06 — scope (derived)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "controls, operations" (CLAUDE.md §4) / "tenant governance" (PROJECT-STATE.md §2).
- **Source:** `CLAUDE.md §4; docs/development/PROJECT-STATE.md:44 — DERIVED summaries`
- **Owner decisions:** None found.
- **Expected behaviour:** The platform operator has controls over tenants and day-to-day operational tooling.
- **Actual behaviour:** Not built as a phase deliverable. Scattered pieces exist from earlier phases — App\Http\Middleware\PlatformOperatorOnly, App\Support\Platform\PlatformCapabilities, App\Services\MerchantPermissions, a `can:manage-platform-addons` route group at routes/web.php:1155 — but there is no operator control surface. app/Http/Controllers/SuperAdmin/ does not exist on this branch, and resources/views/superadmin/ contains exactly one file, dashboard.blade.php.
- **Gap:** The whole requirement. Its content is unrecoverable: 'controls' and 'operations' are two words in a derived summary.
- **Responsible:** Phase 06
- **Next action:** Do not infer scope from the WorkDo super-admin surface; get the owner's definition first.

### `P06-05` — Phase 06 — scope (derived)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "platform admin" (CLAUDE.md §4) — the super-admin administration surface.
- **Source:** `CLAUDE.md §4 — DERIVED summary`
- **Owner decisions:** None found.
- **Expected behaviour:** A platform administrator can run the SaaS: tenants, plans, modules, operations.
- **Actual behaviour:** Only fragments exist. There is no app/Http/Controllers/SuperAdmin/ directory on this branch and `grep -n SuperAdmin routes/web.php` returns nothing — note that the Lynomia integration documents describe a `SuperAdmin/IntegrationController` with nine operations, but that is on a different branch and a different project. The platform operator dashboard surface is resources/views/superadmin/dashboard.blade.php plus HomeController's operator branch.
- **Gap:** The whole requirement.
- **Responsible:** Phase 06
- **Next action:** Establish which branch the SuperAdmin integration controllers live on before assuming the platform-admin surface is thinner or richer than it is.

### `P06-06` — Phase 06 — scope (derived)

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "premium Super Admin UX" (CLAUDE.md §4 and PROJECT-STATE.md §2). CLAUDE.md §4's phase table also records that Phase 02.5 was "a separately approved FUTURE polish stage over Phase 02's surfaces" and that Phase 06 carries "premium Super Admin UX" of its own.
- **Source:** `CLAUDE.md §4; docs/development/PROJECT-STATE.md:44 — DERIVED summaries`
- **Owner decisions:** None found for Phase 06 specifically.
- **Expected behaviour:** The super-admin surfaces reach the same premium visual bar that Phase 2.5 established for merchant surfaces, bilingual and contrast-checked.
- **Actual behaviour:** Not started. Phase 2.5 was explicitly merchant-scoped — 'Premium Merchant Experience ... premium UI/UX across merchant web and Flutter' — and its 295-row matrix covers merchant surfaces. No super-admin premium work exists.
- **Gap:** The whole requirement. The Phase 2.5 design system, evidence harness and capture-integrity gates are reusable foundations for it.
- **Responsible:** Phase 06
- **Next action:** Reuse the Phase 2.5 capture harness and its four-combination evidence rule rather than inventing a second standard.

### `P06-07` — Phase 06 — carried-in item

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** "COD / Stripe / Bank-transfer gateway editors (Flutter) — SCHEDULED — Phase 06 (SaaS & Super Admin). All six files are 100% commented out, 815 lines, 0 live. Reviving them exposes financial configuration, which Phase 2.5 reserves."
- **Source:** `docs/development/phase-2.5/PHASE-2.5-BLOCKED-DISPOSITIONS.md:295`
- **Owner decisions:** The disposition is an engineering scheduling decision recorded in a Phase 2.5 document. PROJECT-STATE.md §3 records owner visual acceptance of the 24 Premium surfaces; I found NO evidence the owner approved this specific deferral to Phase 06, so it must not be treated as an owner-approved boundary.
- **Expected behaviour:** A product decision in Phase 06 on whether merchants configure payment gateways from the Flutter admin app, and if so, those six files are revived and verified.
- **Actual behaviour:** Carried forward, unaddressed. The row is still BLOCKED in the Phase 2.5 matrix (which counts 10 BLOCKED rows) and the stated 'what it needs' is 'a product decision in Phase 06'.
- **Gap:** The product decision and the work behind it.
- **Responsible:** Phase 06, pending an owner product decision
- **Next action:** Put the question to the owner explicitly when Phase 06 opens; it concerns financial configuration, which the standing escalation rules reserve.

### `P06-08` — Phase 06 — numbering hazard

**Status: NOT IMPLEMENTED**

- **Requirement (original wording / faithful reference):** Verification of the task's own premise: the PHASE-6-* files under docs/development/lynomia-integration/ are a different project, not the Master Plan's Phase 06.
- **Source:** `docs/development/lynomia-integration/PHASE-6-SCOPE-PROPOSAL.md; docs/development/MASTER-PLAN-SOURCE-VERIFICATION.md §3.4`
- **Owner decisions:** The owner's master prompt: "Do not reinterpret an older technical roadmap as permission to change this approved six-phase product plan."
- **Expected behaviour:** The two numberings are kept apart so a Lynomia completion report is never read as Phase 06 delivery.
- **Actual behaviour:** CONFIRMED, and the Lynomia document says so itself: 'This is NOT the Store Syria platform roadmap's Phase 6.' Its scope is 'Integration Administration, Partner Operations & Platform Communication Operations' across two repositories, status 'CONDITIONAL GO for staging. NOT production-ready.' A THIRD numbering also exists and is worth recording: that same document identifies docs/discovery/09-DEVELOPMENT-ROADMAP.md §9's 'Phase 6' as 'Arabic & Storefront Experience', from the older ten-phase technical roadmap which the owner's master prompt forbids treating as overriding the six-phase plan.
- **Evidence:** git log --all --name-only confirms the only PHASE-6-* paths in the entire history are the three lynomia-integration files
- **Gap:** None for this verification. The hazard is live: three different things are called 'Phase 6' in this repository.
- **Responsible:** Governance
- **Next action:** State the three-way collision wherever Phase 06 is discussed.


---

## Missing source material

Recorded rather than invented. Each entry names what referenced the absent source.


### 01 Foundation — architecture, security, tenant isolation, permissions,

- The ORIGINAL Phase 01 owner directive itself. docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md §G is titled 'Phase-wide verification (the directive's §9)' and PHASE-01-CLOSURE-EXECUTION.md:293 says 'The requirement's authority is the directive of 2026-10-22' — but no directive document exists anywhere in docs/. A `find docs -iname '*directive*' -o -iname '*master*prompt*'` returns only docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md. So Phase 01 has no verbatim owner prompt on disk, unlike Phase 02; the acceptance criteria doc is the nearest first-party restatement, and §G's ten steps are a paraphrase of a §9 nobody can now read. Several closure statements ('the directive's point', 'the directive forbids two agents editing the same working file', 'Priority order is the directive's') cite a text that cannot be checked.
- A refreshed A–H acceptance assessment at the Phase 01 closure head. The ONLY full A–H matrix on disk is PHASE-01-FINAL-DECISION-REPORT.md §I, and it was written against a ledger of 105 tasks at 48 DONE / 17 READY. The final state is 109 tasks at 82 DONE / 4 PARTIAL / 21 BLOCKED / 2 BACKLOG. Criteria the matrix marks FAIL (B6, C5, D2, E1-partially, F7) were closed or advanced afterwards and the matrix was never re-run, so the only owner-facing statement of 'did Phase 01 meet its acceptance criteria' is materially stale in both directions. The 2026-10-24 CLOSEOUT re-states counts and prerequisites but does not re-assess A–H.
- Evidence that the §G phase-wide verification was ever run as one exercise. Searched: no tests matching *journey*, *EndToEnd* or *E2E* exist anywhere under tests/. G1 and G2 therefore have no implementation at all, and G3-G5 inherit that.
- A recorded owner acceptance of Phase 01. CLAUDE.md §4 says 'engineering complete (2026-10-24), NOT release-ready or accepted' and 14-PHASE-01-ACCEPTANCE-CRITERIA.md says 'Only the owner may approve closing the phase'. No owner acceptance document exists. Meanwhile main HEAD is 4e878b8d 'Merge Phase 2.5 Premium Merchant Experience into main' (2026-11-12), so Phase 01 work has been merged to main without that acceptance.
- A production-side record for any of the 13 (+1) environment observations in PHASE-01-CLOSURE-EXECUTION.md FINAL §2a. The only output committed anywhere is the LaraBug reading taken in a local container, which the closure itself says does not close the criterion.

### 02 Merchant Experience

- The Master Product Plan / Decision Register v1.0 (2026-10-14) — the master prompt §2 names it as the product authority and preserves decision IDs UX-001..UX-014, PRD-001, ORD-001/ORD-009, ARCH-001..ARCH-011, GOV/DATA decisions and exclusions EX-001..EX-012. No file in the repository contains that register: `grep -rl 'UX-014\\|EX-012' docs/` returns no v1.0 source document. The Phase 02 ledger cites those IDs in its Decisions column without a document that defines them, so every 'preserve decision ID X' obligation is traced to a label, not to text.
- docs/design-system/gallery.html and docs/design-system/ADMIN-LAYOUT-ARCHITECTURE.md are named as mandatory discovery inputs (master prompt §3). They were not opened in this pass — their existence was not verified here, so §3 discovery is reported from PHASE-02-REUSE-INVENTORY.md's own claim rather than from the sources.
- No pre-Phase-02 frontend performance measurement exists anywhere. The §6 obligation is a COMPARISON; the only artefact (evidence/performance-baseline/, measured 2026-11-01) is a post-hoc single run, so the 'before' side of the comparison has no source.
- Flutter rendering evidence: no screenshot of any Flutter screen exists in either repository. PHASE-02-WEB-FLUTTER-PARITY.md §6 states 'No screen of this app has been seen rendering, by me or by anything in this container.'

### 2.5 Premium Merchant Experience

- There is NO Phase 2.5 master execution prompt. Phase 02 has `docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md`, which CLAUDE.md calls 'the owner's master execution prompt, verbatim and not to be edited'. Phase 2.5 has no equivalent file. Every Phase 2.5 owner requirement I recovered exists only as a quotation inside an engineering-authored document (PHASE-2.5-DECISION-REGISTER.md D-39..D-42, ACCOUNT-HANDOFF.md §10, PHASE-2.5-DESIGN-PACKAGES.md, PHASE-2.5-OFFICIAL-CLOSURE.md §1 and §12, PHASE-2.5-BLOCKED-DISPOSITIONS.md:10-12).
- The owner's Phase 2.5 CLOSURE directive (c. 2026-11-10) is not stored anywhere. Only its section numbers survive, in PHASE-2.5-OFFICIAL-CLOSURE.md §1 ('§2 start from existing work; §3 preserve every repaired vulnerability; §4 integrate the Composer updates; §5 complete acceptance; §6 final gates; §7 finalise the PRs; §8 this document; §9 update the Phase 03 planning PR') and in PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md ('This answers §5 of the owner's closure directive'). The clause text for §2, §6 and §7 is nowhere quoted.
- The owner's FINAL-ACCEPTANCE directive of 2026-11-11 ('PHASE 2.5 FINAL ACCEPTANCE AND MERGE') is not stored verbatim. PHASE-2.5-OFFICIAL-CLOSURE.md §12 paraphrases its ten tasks in a table and quotes only two sentences (item 6, and the blocked-dispositions sentence). The four 'standing prohibitions' it re-stated are listed by label only.
- The owner's directive of 2026-11-12 that (a) accepted the 24 premium surfaces visually, (b) authorised merging PR #134 and PR #9, and (c) authorised Phase 03 execution is recorded NOWHERE as an owner decision. The Phase 2.5 decision register ends at D-42 (2026-11-04). The acceptance is asserted only in docs/development/PROJECT-STATE.md:40 and :56 and in docs/development/phase-3/PHASE-03-EXECUTION-PREP.md:16 — both engineering-authored, both later, and both contradicted by PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md of the same date, which states 'No design is marked approved, here or anywhere else.'
- There is NO Phase 2.5 coverage audit. Phase 02 has `PHASE-02-COVERAGE-AUDIT-2026-10-25.md`, which CLAUDE.md describes as the A-K requirement-to-row trace ('50 tracked, 23 partial, 31 missing'). Nothing in docs/development/phase-2.5/ traces the Phase 2.5 directives' clauses to matrix rows or ledger rows, so there is no in-repo instrument that would have detected a dropped Phase 2.5 requirement. This matrix had to be reconstructed from scratch.
- No `before/` captures exist for any of the original 24 PREMIUM VERIFIED surfaces, in any of the five backing evidence folders (measured and stated in PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md §3.1). Only the later owner-correction round has them: 56 captures for 14 surfaces under evidence/owner-review-2026-11-12/before/.
- No record exists of the 225 REDESIGNED AND VERIFIED surfaces, or of any of the 75 Flutter surfaces, being presented to the owner for visual judgement. The visual acceptance package covers the 24 premium web surfaces only and says a Flutter pass 'is a separate request'.
- `docs/development/phase-2.5/evidence/web-closure-frag-2026-11-10/after/measurements.json` records 6 of the 29 surfaces that directory vouches for; the other 23 surfaces' findings were overwritten by filtered re-runs and are preserved nowhere (stated in PHASE-2.5-ENGINEERING-CLOSURE-REPORT.md §5).
- No evidence artefact closes D-42.2 (the ungated settings writer, and the required investigation-and-report of `WebhookController` and `PixelFieldsController`). I searched the ledger, the closure reports and the acceptance reports and found no measurement or report for it.

### 03 Storefront, Themes, Official Website and Documentation (M0–M7)

- THE PHASE 03 MASTER DIRECTIVE / OWNER BRIEF DOES NOT EXIST IN THE REPO. PHASE-03-FINAL-REPORT.md:7 says 'This is the single report the Phase 03 master directive asks for'; PHASE-03-M5-OFFICIAL-WEBSITE.md:10-13 quotes 'The owner's directive: "Build the official company/platform website using the existing WorkDo LandingPage foundation..."'; PHASE-03-M6-DOCUMENTATION-CENTRE.md:16 cites 'The owner's brief names it as the thing this is not'. There is no PHASE-03-MASTER-EXECUTION-PROMPT.md (Phase 02 has one at docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md). The verbatim original Phase 03 requirements therefore cannot be fully recovered; this matrix is built from PHASE-03-EXECUTION-PREP.md (workstreams 03-A..03-E, milestones, acceptance criteria) plus the two directive fragments quoted inside M5/M6 and the scope line in CLAUDE.md §4.
- THE OWNER-APPROVED SIX-PHASE MASTER PRODUCT PLAN / DECISION REGISTER v1.0 (2026-10-14) IS NOT IN THE REPO. CLAUDE.md §2 and §4 cite it as the scope authority and paraphrase Phase 03 as 'themes, visual editor, shopper experience, checkout UX, SEO, plus a separately scoped Official Website and a bilingual Documentation Center'. grep for 'Master Product Plan' / 'Decision Register' matches only documents citing it, never the plan itself. Three of its named scope items (shopper experience, checkout UX, SEO) have no counterpart in PHASE-03-EXECUTION-PREP.md's workstreams.
- NO PHASE 03 COVERAGE AUDIT. Phase 02 has docs/development/phase-2/PHASE-02-COVERAGE-AUDIT-2026-10-25.md tracing each master-prompt requirement to a ledger row. Phase 03 has no equivalent, so nothing in the repo ever traced the directive requirement-by-requirement.
- NO PHASE 03 DECISION REGISTER. Phases 02 and 2.5 each have one (PHASE-02-DECISION-REGISTER.md, PHASE-2.5-DECISION-REGISTER.md). Phase 03 has none, so owner decisions during Phase 03 are recorded only inline in milestone documents (the only clearly-recorded one is the M4 authorization decision of 2026-11-15).
- NO PHASE 03 TASK LEDGER and no owner-facing acceptance report (Phase 02 has PHASE-02-TASK-LEDGER.md and PHASE-02-ACCEPTANCE-REPORT-AR.md; Phase 2.5 has PHASE-2.5-COVERAGE-MATRIX.md and PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md).

### 05 Marketing & Analytics AND 06 SaaS & Premium Super Admin

- The owner-approved Store Syria Master Product Plan / Decision Register v1.0 (2026-10-14). It has NEVER existed in this repository. It is cited as external authority by seven files; docs/development/MASTER-PLAN-SOURCE-VERIFICATION.md records the full search. Only the six-phase structure survives, quoted verbatim in docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md:85-92.
- The wording of every decision ID the owner required be preserved: UX-001–UX-014, PRD-001, ORD-001, ORD-009, ARCH-001–ARCH-011, the GOV and DATA families (size unknown), and especially the exclusions EX-001–EX-012. Named in PHASE-02-MASTER-EXECUTION-PROMPT.md:100-107 and defined nowhere. The exclusions matter most: without them nobody can distinguish 'never in scope' from 'silently dropped', which is exactly the failure this audit exists to catch.
- ANY Phase 06 source document. docs/development/phase-6/ does not exist on disk and no path matching phase-6 or PHASE-06 has ever been added in git history. Every Phase 06 requirement in this matrix is DERIVED from one sentence in CLAUDE.md §4 and one table row in docs/development/PROJECT-STATE.md:44. (Minor correction to MASTER-PLAN-SOURCE-VERIFICATION.md §1, which calls it an 'empty directory': it is absent entirely.)
- An owner-authored Phase 05 requirements document. docs/development/phase-5/ holds three engineering-authored files (execution plan, metric definitions, reporting-surface inventory). They quote six owner instructions verbatim and those are the highest-authority Phase 05 wording that survives, but the phase's requirement set itself is reconstructed from the plan's §5 prose scope statement, CLAUDE.md §4 and PROJECT-STATE.md §2.
- A recorded owner decision on P4-F128 (settlement vs entitlement, reconciliation) and P4-F046 (webhook.site egress, Benefit capture verification). docs/finance/P4-FINANCIAL-POLICY-DECISION-PACKAGE.md still reads 'Status: AWAITING DECISION' at its head, yet five of its seven items have been implemented — so the decisions for F124, F126, F127, F032 and F145 were taken somewhere this repository does not record.
- A record of the owner review of P4-F134 and P4-F135 that the owner's own instruction requires before coupon-redemption records may be used as authoritative data.
- Issue #137 and Issue #120 themselves. Both are referenced by number in PROJECT-STATE.md; I read the repository's description of them, not the issues.
- An owner approval of docs/development/phase-2.5/PHASE-2.5-BLOCKED-DISPOSITIONS.md. The dispositions schedule three BLOCKED rows into Phases 04 and 06; I found owner acceptance recorded for the 24 Premium visual surfaces but not for these dispositions.

### 04 Commerce (P4-0 .. P4-9)

- THE PHASE 04 OWNER DIRECTIVE DOES NOT EXIST IN THE REPOSITORY. PHASE-04-EXECUTION-PLAN.md cites it twice as authority ('Journey C in the directive', line 64; 'The directive requires the distinction', line 128) and PHASE-04-FINDINGS-REGISTER.md:566 cites 'the Phase 04 directive' for the escalate-to-owner set. `git log --all --diff-filter=A -- docs/development/phase-4/*` shows only three files ever added there (plan, register, final report). The directive is the ONLY source for the six end-to-end journeys and for the fixture-vs-product-defect rule.
- DEFINITIONS OF COMMERCE END-TO-END JOURNEYS A, B, D, E and F. Only 'Journey C' is identifiable (concurrent-checkout overselling, from PHASE-04-EXECUTION-PLAN.md:64). A repo-wide grep for 'Journey [A-F]' returns exactly one hit — that same line. The journey list cannot be reconstructed and must not be invented.
- Store Syria Master Product Plan / Decision Register v1.0 (2026-10-14) — never committed; recorded as such in docs/development/MASTER-PLAN-SOURCE-VERIFICATION.md. The Phase 04 scope wording ('products, multi-warehouse stock, pricing, fulfilment, COD, returns, channels') survives only as the CLAUDE.md §4 DERIVED summary.
- EXCLUSIONS EX-001 .. EX-012 from the Decision Register. Named in docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md:100-107 and defined nowhere. Without them, 'multi-warehouse stock' and 'channels' cannot be told apart from 'deliberately excluded' — exactly the distinction this audit exists to make.
- Owner-authored Phase 04 acceptance criteria. The acceptance clauses in PHASE-04-EXECUTION-PLAN.md §6 were written by engineering during the phase, not by the owner.
- The P4-5 gateway census artefact the plan's own acceptance demands ('the ~30 gateway controllers as a table'). No such table exists in docs/ or tests/ — the nearest artefact is the Phase 01 docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md.