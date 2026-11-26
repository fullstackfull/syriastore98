# Store Syria — canonical project state

**This is the continuity document.** If a session ends, is transferred, or starts from nothing, this
is the file to read first. It links the six phases, says what is done, what is not, and what the next
executable action is. Everything in it is either a measured fact with its source, or an owner
decision quoted as such.

**Last updated:** 2026-11-25 · **Active branch:** `feature/phase-06-saas-super-admin` (PR #141) ·
**Active execution scope:** the ENTIRE remaining project across all six phases and Phase 2.5 — the
branch name does not narrow it.

> The previous header read *"Active phase: 05 · Active branch `feature/phase-05-marketing-analytics`"*.
> That was stale from 2026-11-22. The Phase 05 work it described is preserved below and in
> `docs/development/phase-5/`; nothing has been removed.

> ### NEXT EXECUTABLE ACTION (read this first — account transfer of 2026-11-26)
>
> **HEAD of `feature/phase-06-saas-super-admin` = `5be3602b`, pushed and CI-green.** CI #676 and
> #677 both returned **success on all seven jobs**, merge-base regression gate included. Nothing of
> this session's verified work is unpushed.
>
> **WHY THIS TRANSFER HAPPENED: the account hit its weekly API limit (resets 1 Dec, 21:00 UTC).**
> Two agents were killed mid-task by HTTP 429. Their work is preserved as below. Do not restart
> them; resume by message once the limit resets, or take the work over directly.
>
> #### 1. What landed this session (all CI-green on the development branch)
>
> `GAP-100` closed (its row had recorded its own completion and the status was never moved) ·
> `GAP-125` VERIFIED COMPLETE (row stale; defect already fixed by `bc0d48cc`) · `GAP-135`
> shopper-driven order transitions guarded · `GAP-143` shipping/tax clause exercised on a stored
> order and `CURRENCY: null` fixed · `GAP-156` a fifth money surface · `GAP-164` the AR/EN x
> light/dark acceptance gate · `GAP-179` slices 1 and 2 plus LynomiaChat · `GAP-276` the Phase 02
> ledger's `F114` shipping-ownership defect (nine writers, not the one the ledger named) ·
> `GAP-277` roleless staff could cancel and return any order in their tenant · `GAP-060`'s three
> deferred surfaces · Flutter wave 3 on `StoreSyria_app98` (`5b3c6bb`, golden surfaces 5 to 16).
>
> **Raised and NOT fixed:** `GAP-278` (a neighbour tenant's currency symbol reaches this store's
> checkout) and `GAP-279` (a NULL-`store_id` role counts for every store; within-tenant only —
> **measure before fixing**, and the remedy belongs at the writer, not the reader).
>
> #### 2. UNFINISHED WORK, preserved separately — `claude/gap-140-refund-policy-wip`
>
> **Branch `claude/gap-140-refund-policy-wip`, commit `9d57021d`. UNREVIEWED. DO NOT MERGE.** Off
> the development branch on purpose: the agent hit the limit before any safety review. 958 lines in
> 4 files (`app/Services/RefundPolicy.php` new, `OrderRefundController` +157/-7,
> `AccountProfileController` +43/-2, `RefundPolicyEnforcementTest` new).
>
> **Its exact state, in the agent's own last words:** *"The census asked the wrong question — it
> grepped for a literal that now lives behind a constant. Let me fix it to key on the decision."* So
> that census is **known-broken and mid-rewrite**; its green or red means nothing yet. `9d57021d`'s
> message carries the full six-item remainder. The substance is real: `refund_order.blade.php`
> renders the attachment input only when the store's `attachment` setting is on, and **no writer
> consulted the setting** — files were accepted and stored for a merchant who had it switched off.
>
> #### 3. EXACT NEXT EXECUTABLE ACTIONS, in order
>
> 1. **Finish `GAP-140`** on its WIP branch: fix the census to key on the decision and comment-strip
>    it; re-run fail-first and revert-and-confirm; measure which of `manage_stock`, `attachment` and
>    `shiping_deduct` any writer consults (only `attachment` looks addressed); then `P4-F087` actor
>    attribution and the operator's lockout from the refund list. **State the behaviour change per
>    setting** — enforcing one that currently does nothing changes behaviour for every merchant who
>    had it off. If one would move a refund AMOUNT rather than a permission or stock movement, stop
>    and ask the owner. Get a `tenant-authz-reviewer` pass.
> 2. **`GAP-279`** — measure first (can a staff row reach `getCurrentStore() === null` while holding
>    `Create Role`?), then fix at the writer.
> 3. **`GAP-278`** — named remedy is a `configuredSymbolFor()` on `OrderCurrencyStamp` mirroring its
>    existing `configuredCodeFor()`; leave `symbolFor()` untouched so order stamps do not move.
> 4. **`GAP-060`'s settings remainder: 26 declared items**, countable now because the census exists.
>    21 single selects + 5 switch groups; four sit behind decisions already in flight.
> 5. `GAP-179` leftovers: the **22 `D-25`** findings need the **owner's scope decision** and must not
>    be taken by a Phase 06 slice; 2 await the Phase 2.5 white-dialog decision.
>
> #### 4. ACTIVE AGENTS AT TRANSFER — both dead by rate limit, work saved
>
> `GAP-140` refund policy (on the WIP branch) and `GAP-179` Super Admin UX (its last slice **landed**
> in `5be3602b`; nothing lost). No agent running, no monitor armed.
>
> #### 5. PENDING OWNER DECISIONS — engineering cannot close these
>
> - **The double-applied tax band.** Measured through the shipped COD checkout: the band is applied
>   twice, so a 2x100.00 basket with 25.00 carriage is charged **267.00 where 245.00 is correct** —
>   22.00 of tax the merchant never configured, collected by the COD driver. Pinned by a test that
>   fails with the *corrected* total, so a fix cannot pass unnoticed. Work-queue S21.4.
> - **`D-25`** — the general theme change reaching every merchant screen and all 70 modules.
> - **`GAP-006`/`GAP-024`** — unifying the totals derivation seam and promoting warn-only
>   reconciliation to refusal; `GAP-024` depends on `GAP-006`. Not engineering's to take.
> - **`GAP-119`, `GAP-134`, `GAP-141`, `GAP-145`, `GAP-153`, `GAP-177`, `GAP-180`** — blocked by their
>   own text on owner decisions or unrecoverable content.
> - **`GAP-030`** — the P01-SEC-13 migration is prepared and proven on real MySQL (31 checks, 0
>   failures); **applying it is the owner's.**
> - Five legal documents and contact details in both languages; 20 Flutter baselines awaiting human
>   review; decisions I12-I19.
> - **`plans/index` distinguishes an included plan feature from an excluded one BY COLOUR ALONE**
>   (WCAG 1.4.1) — the fix is a different glyph, a visual decision.
>
> #### 6. CONFIDENTIAL — MUST NOT BE PUBLISHED, AND CANNOT TRANSFER THROUGH GITHUB
>
> - **`GAP-260` and security incident #120 stay private.** The owner's standing instruction is that
>   the finding, reproduction details, sensitive tests and remediation are **not** published to the
>   public repository without explicit authorisation. Nothing of either was pushed this session and
>   nothing may be.
> - **The exposed `webhook.site` bin still needs rotating** — owner action, and the bin identifier is
>   not to be restated in any public artefact.
> - **The production database dump remains in `main`'s tree** (P1-SEC-40 / #120); PR #122, which
>   would remove it, is deliberately unmerged. No UI work remediates it.
> - **What physically cannot cross:** this container's dev fixture (`database/p2dev.sqlite`, two
>   stores, operator role granted by hand), a dev server on 127.0.0.1:8899, the scratchpad scripts,
>   the 20 un-reviewed Flutter baselines (gitignored at `.gitignore:97`, uploaded by CI rather than
>   committed), and the eight wave-1 baseline candidates in the Flutter repo which are
>   **pre-framing-fix and must be re-exported before review** — approving them as they stand would
>   freeze a defect that is already repaired.
>
> #### 7. THE PLAN AND THE DIRECTIVE ARE PRESERVED, UNREDUCED
>
> The owner-approved **six-phase Master Product Plan** is in the repository at
> `docs/development/master-plan/` (base, +amendments, `DECISION-ID-REGISTER.md` with all 181 decision
> ids and the 12 `EX-*` exclusions), with traceability in
> `MASTER-PLAN-{SOURCE-VERIFICATION,REQUIREMENT-TRACEABILITY,GAP-REGISTER}.md`. **Three texts remain
> missing (1 GOV, 2 ARCH) and must not be invented.** Phase 02's scope is
> `PHASE-02-MASTER-EXECUTION-PROMPT.md`, verbatim and not to be edited.
>
> **The accelerated parallel-execution directive stands:** run the maximum safe number of concurrent
> workstreams, keep both repositories active, assign the next task the moment one finishes, do **not**
> give overlapping shared files to independent agents, implement first and run the expensive suites at
> integration milestones rather than after every task, accumulate coherent commits rather than pushing
> each one, and never weaken a gate or manufacture a green result. **Preserve every recorded owner
> disposition exactly; a recorded question or recommendation is not an approval, and an engineering
> report deferring something is not an owner deferral.**
>
> **Current figures (gate-enforced): 370 known requirements, 181 verified complete, 189 not.** The
> register holds **334** rows. Remaining: NOT IMPLEMENTED 10, PARTIALLY IMPLEMENTED 101.
>
> > **THE ROW COUNT WAS STALE AT 279 AND NOTHING ASSERTED IT — corrected 2026-11-26, and now
> > gate-enforced.** The register's own header carries the figure and `GapRegisterConsistencyTest`
> > asserts that against its rows in both directions; **this was a third copy with no consumer**,
> > which is the same shape as the `NOT IMPLEMENTED 12 / PARTIALLY IMPLEMENTED 99` pair corrected
> > immediately above. A derived figure nobody asserts goes stale; the remedy is not to re-derive
> > it more carefully but to make something read it. `MasterPlanTraceabilityCountsTest` now does.
>
> > **RECONCILED 2026-11-26.** This sentence read `NOT IMPLEMENTED 12, PARTIALLY IMPLEMENTED 99`
> > while the matrix's summary table held **10** and **101**, and the account transfer handed the
> > stale pair over as the project's headline. Re-measured from the matrix's own 350 requirement
> > sections rather than from either summary: `VERIFIED COMPLETE 181 · PARTIALLY IMPLEMENTED 101 ·
> > NOT IMPLEMENTED 10 · IMPLEMENTED - VERIFICATION INCOMPLETE 18 · OWNER-APPROVED DEFERRED 17 ·
> > OWNER DECISION REQUIRED 12 · EXTERNALLY BLOCKED 9 · ACCEPTED OUT OF SCOPE 2`, which sums to
> > exactly 350 and matches that table row for row. So the matrix was right, this file was stale,
> > and **the project-wide 370/181/189 figures did not move.** Two rows had been re-traced NOT →
> > PARTIAL without this copy following.
> >
> > **The cause is the one this repository keeps rediscovering: a derived figure with no consumer
> > asserting it.** `MasterPlanTraceabilityCountsTest` already pinned the matrix to its own rows and
> > `CLAUDE.md` to the matrix — and this document, which CLAUDE.md names as the FIRST file a new or
> > transferred session opens, was not in the set. It now is:
> > `test_the_handoff_document_quotes_the_matrix_figures` asserts both the project-wide sentence and
> > the per-status remainder against the matrix, in both directions, so a status change that forgets
> > this file fails CI instead of being handed to the next account.
>
> #### 8. TWO PRACTICAL WARNINGS THAT COST REAL TIME HERE
>
> `ci.yml` sets `concurrency: ci-${{ github.ref }}` with **`cancel-in-progress: true`**, so pushing
> to this branch **cancels an in-flight run**. And a fixture rebuild writes ~150 untracked JPEGs under
> `uploads/2/` and `uploads/uploads/2/` which the store-1-pinned `.gitignore` rules do not catch —
> **delete them, never add a blanket `/uploads/2/` rule**, because store 2 is a real store with 272
> tracked files. `fc46bb9f` records this.

> ### HISTORICAL — the NEXT EXECUTABLE ACTION of the 2026-11-25 account transfer
>
> **MODE: EXECUTE THE VERIFIED BACKLOG. DO NOT START ANOTHER SURVEY.** The owner directive of
> 2026-11-22 remains in force and was restated at the transfer. Long multi-agent investigations are
> reserved for the final integration candidate. Independent specialist review of each individual
> security or financial slice remains **mandatory**.
>
> **THE SCOPE IS THE WHOLE PROJECT, NOT PHASE 06.** The continuation branch is named for Phase 06
> because that is where the newest slices land. The authorised scope is every remaining item across
> Phase 01, Phase 02, Phase 2.5, Phase 03, Phase 04, Phase 05, Phase 06 and the project-wide
> integration, security and acceptance obligations. **Engineering closure of a phase is not proof
> that its original requirements were delivered** — see §4.1 of `CLAUDE.md` and the four states that
> must never be collapsed into one label.
>
> #### Repositories and the stacked chain
>
> | | |
> |---|---|
> | Laravel | `fullstackfull/syriastore98` |
> | Flutter (shipped admin app) | `fullstackfull/StoreSyria_app98` |
>
> `main` → Phase 03 [#138](https://github.com/fullstackfull/syriastore98/pull/138) → Phase 04
> [#139](https://github.com/fullstackfull/syriastore98/pull/139) → Phase 05
> [#140](https://github.com/fullstackfull/syriastore98/pull/140) → Phase 06
> [#141](https://github.com/fullstackfull/syriastore98/pull/141). **Each PR targets the one before
> it.** Do not merge, rebase, force-push, rewrite history or retarget any of them without owner
> authorisation.
>
> #### Published state at the transfer — measured, not inferred
>
> | | |
> |---|---|
> | branch | `feature/phase-06-saas-super-admin` |
> | local HEAD | `8811b5b2` |
> | remote HEAD | `8811b5b2` — **in sync**, working tree clean, no uncommitted work |
> | last CI-verified head | **`87063414`** — ci.yml **#642** ([run 45540961886](https://github.com/fullstackfull/syriastore98/actions/runs/45540961886)), **conclusion `success`**, concluded 2026-11-25 04:39:03Z |
> | `8811b5b2` | **PUBLISHED** 2026-11-25 as a fast-forward `87063414..8811b5b2`. Its own required run had not concluded when this was written — **read the run for the head you are on.** |
>
> Run #642 was read job by job, not from the run conclusion: both REQUIRED Tests jobs green (04:09:06Z
> and 04:10:49Z), the merge-base regression gate green including *"Run the Feature suite at HEAD"*,
> *"Record the official known-outcome gate's verdict"* and *"Compare failing test identities"*, plus
> static checks, the secret scan, `composer audit` and the PayHere report.
>
> **Local gate on `8811b5b2`** (the evidence for the commit whose CI had not yet concluded):
> Feature **11,746 passed, 1 declared failure, 1 declared skip**, and
> `assert-known-test-outcomes.php` reports the observed set matches the manifest **exactly**. Unit
> **6,212 passed + 1 declared skip**; Contract **45 passed**.
>
> **The security attestation's human checklist is RED and must stay that way.** On the current head
> the attestation workflow reports *"Security-sensitive diff: human approval"* **success** and
> *"Security-sensitive diff: human checklist"* **failure**. That gate requires a person to work
> `.github/SECURITY-DIFF-REVIEW.md` and leave a marker that records **who** gave it. An agent typing
> that marker would defeat the control, not satisfy it. It is **not** a required CI job and blocks
> nothing in development. It is already explained once on PR #141
> ([comment 7131474457](https://github.com/fullstackfull/syriastore98/pull/141#issuecomment-7131474457));
> **do not comment again and do not forge or bypass it.**
>
> #### TASK #204 — P06-2B, THE DISABLED-APPLICATION PAYMENT BOUNDARY. **DONE 2026-11-25.**
>
> | | |
> |---|---|
> | commit | **`9acca689`**, published as a fast-forward `512fa880..9acca689` |
> | its CI run | ci.yml **#645** ([run 45604074657](https://github.com/fullstackfull/syriastore98/actions/runs/45604074657)) — **READ THE RUN**; it was in flight when this line was written |
> | the head before it | `512fa880`, ci.yml **#644** ([run 45575129709](https://github.com/fullstackfull/syriastore98/actions/runs/45575129709)), **conclusion `success`**, all seven jobs read job by job: both REQUIRED Tests jobs with *"The Feature outcome set must match the record"* green, the merge-base regression gate including *"Compare failing test identities"*, static checks including *"packages/workdo/ is unmodified"*, the secret scan, `composer audit` and the PayHere report |
> | local gate on `9acca689` | Unit **6,218 passed + 1 declared skip**; Contract **45 passed**; Feature **11,818 passed, 1 declared failure, 1 declared skip**, and `assert-known-test-outcomes.php` reports the observed set matches the manifest **exactly**. MariaDB: **15 + 16 checks, 0 failed** |
>
> Both halves were built, with their mandatory independent reviews, and the records below were
> brought into agreement with the code. **The two owner decisions that were open are still open and
> were not inferred.**
>
> | | |
> |---|---|
> | the prerequisite (P5-FIN-4 / P04-FIN-04 / review finding F13) | **BUILT.** `php artisan payments:reconcile` over `App\Services\Payments\Reconciliation\` — authoritative provider verification, the intent as the durable proof of initiation, exact amount and currency comparison, one settlement per payment in ONE transaction, discrepancies written on the intent and in `audit_logs`. Report-only unless `--apply`. Design: `docs/finance/P204-PAYMENT-RECONCILIATION.md` |
> | the boundary | **WIDENED, on what a route DOES to a payment** (`App\Support\Modules\ModulePaymentFlows`) rather than on the manifest's `payment-callback` label — the label does not follow the code in either direction, measured in `PHASE-06-DISABLED-APPLICATION-BOUNDARY.md` §4.2. A disabled module cannot START a payment; a payment already initiated is honoured ONCE for the session that started it; a return that cannot show one is refused and written down |
> | the record the modules do not keep | `module_payment_initiations` + `App\Services\ModulePaymentLedger`. **Development-and-test-only, like its two predecessors**, so the payment half of the boundary is INERT in production until the owner promotes it. Starting a payment through a disabled application is refused either way |
> | **still the owner's, and untouched** | `storefront` — the **76** of its 100 keys that are not a payment — and `oauth` (**16**). Pinned by `test_a_non_payment_anonymous_route_of_a_disabled_module_still_passes_pending_the_owners_decision` |
>
> **THE TWO MANDATORY INDEPENDENT REVIEWS FOUND ELEVEN LIVE ITEMS BETWEEN THEM, ALL FIXED**, and
> that is the part worth carrying forward rather than the clean figures. Neither found an
> authorization hole in the new code; both found that it did less than its own docblocks claimed.
>
> | | |
> |---|---|
> | the financial/data-integrity review | three HIGH, five MEDIUM. **A refunded capture still settled** (`STATUS` has four values and none says "refunded", and the buyers this reaches are exactly the ones charged and shown the 419). **A days-old payment was applied as if it had just happened** — a three-day-old intent DOWNGRADED a buyer who had since bought a dearer plan and switched off two of their three stores. **A verified capture that failed to settle was written nowhere** and the run went green once it aged out. Plus: the model cache flushed inside the transaction, so a reader cached the pre-settlement row; `ALREADY_SETTLED` was unreachable on MariaDB and a lost race was audited as a mismatching AMOUNT; a provider retry logged CRITICAL for a working payment; "written once" was keyed on reason text; and exit 0 when nothing was examined. Full account: `docs/finance/P204-PAYMENT-RECONCILIATION.md` §5.2 |
> | the `tenant-authz-reviewer` pass | **route middleware runs AFTER group middleware**, so a disabled Campaigns return was marked `completed` and then refused by the module's own gate — and its PowerTranz relay CAPTURES before reaching a writer it cannot reach. Plus: a payment could be completed in a different store; with no record installed one anonymous request still wrote a donation; and anonymous traffic could suppress and misattribute refusal records. Full account: `PHASE-06-DISABLED-APPLICATION-BOUNDARY.md` §4.5 |
>
> **AND BOTH REVIEWS SHOWED MY TESTS COULD NOT HAVE CAUGHT ANY OF IT.** Every boundary case drove a
> synthetic route and called the guard directly, so middleware ordering was invisible; every request
> used a slug that resolved to no store, so every store-shaped assertion was vacuous; and the
> canonical-reference case passed with its control deleted. Fixed, and the reasons are in the test
> docblocks rather than only here.
>
> **THREE DEFECTS IN ALREADY-COMMITTED CODE WERE FOUND BY BUILDING IT**, all fixed, all with a test
> that failed against the unchanged code first:
>
> 1. **The F241 provider callback could never have verified a payment.** It read the Paytm merchant
>    key from config, and the only writer of that key runs `if (\Auth::check())` — while that route
>    is outside the session group by design. So every real callback met an empty key and recorded a
>    **terminal** refusal against a captured payment. Its nineteen tests could not see it: they set
>    the config directly, a state production cannot reach.
> 2. **The same callback activated plans and wrote no `plan_orders` row**, leaving every successful
>    activation as a false alarm in `unreconciled()`'s captured-payment-with-nothing-to-show clause.
> 3. **`PaytmTransactionVerifier` did not check the answer was about the order it asked about.**
>
> **AND THE TWO MySQL HARNESSES EACH CHANGED THE CODE.**
> `verify-module-payment-boundary-on-mysql.php` (16 checks): under `utf8mb4_general_ci` the
> completion claim matched a reference differing only in letter case — the P1-INV-18 shape,
> invisible to SQLite. `verify-plan-payment-reconciliation-on-mysql.php` (15 checks, driving the
> REAL settlement against a database built by the project's own migrations): it pins the
> `ALREADY_SETTLED` fix that no SQLite test can fail, **and it caught a defect in one of the review
> fixes on its first run** — the entitlement check counted the intent's own order, so a retry read
> as a discrepancy. Both registered in `run-mysql-verifications.sh`.
>
> Records resynced in the same change, with the half that moved named in each: `P04-FIN-04`
> NOT IMPLEMENTED → **PARTIALLY IMPLEMENTED** (the surface exists; the order↔wallet axis does not),
> `GAP-124` likewise, and the two summary tables that count them. **No row was promoted to VERIFIED
> COMPLETE and the project-wide 170 verified is unchanged** — a payment surface that has never seen
> a live provider handshake is not a verified requirement.
>
> #### WHAT IS NOT DONE, and is the next executable work
>
> **The entire remaining project-wide backlog, in the owner's priority order** (security and tenant
> isolation · financial and data correctness · missing approved functionality · cross-phase
> dependencies · UX, accessibility and bilingual parity). The pointers below are canonical; the
> figures in them are recorded checkpoints and not a guarantee that every row is current.
>
> Carried forward from #204 itself, none of it blocking:
>
> | | |
> |---|---|
> | **F7** | `PackageServiceProvider` force-*loads* `LandingPage` and `LynomiaChat` irrespective of any row, and three other first-party mechanisms keep saying LandingPage is on. A row that exists and says `0` would make its 275 routes refuse while they disagree. Recoverable (the module screen is a host route, pinned by a test), so an inconsistency rather than a lockout |
> | **F6** | LynomiaChat schedules six of its own reconcilers, which keep running for a switched-off module. For a bundled first-party integration that is probably right; it is a decision and it is unstated. **The owner's** |
> | the wallet axis of `P04-FIN-04` | needs `report-settlement-entitlement.php` run against a restored copy of production, which is owner/operator work |
> | ~~**no acknowledgement for PLAN intents**~~ | **CLOSED 2026-11-25**, see the block below |
> | **the browser return still closes an intent terminally on an unobtainable answer** | `PaytmPaymentController:471`. The callback and the reconciliation now keep it open; three doors, two policies. Changing the oldest one changes a live purchase path |
> | **`payment_type` spellings** | the return stores `__('paytm')`, translated per locale; the replay sweep covers the language files' current and historical spellings but not `trans('paytm')`. Pre-existing, measured on MariaDB by the review |
> | promoting the three dev-only payment migrations | `plan_purchase_intents`, `order_gateway_references`, `module_payment_initiations`. **The owner's** |
>
> #### AFTER #204 — two slices, both reviewed, both in the owner's priority bands 1 and 2
>
> **GAP-077 / GAP-223(b) — the abandoned-cart screen's four other legs served STORE 1.** Band 1.
> `getCurrentStore()` ends in a hard-coded literal `1` for an operator with no `current_store`, and
> four legs guard on `empty($storeId)`, which `1` is not. Measured at HEAD before any change:
> `/abandon-carts-show` **HTTP 200 with store 1's product in the body**; `/abandon-carts-destroy`
> took store 1's cart rows **1 → 0**; `/abandon-carts-emailsend` **accepted the cart id**.
> `ACCOUNT-HANDOFF.md` open item 84 worried that fixing one leg would make the two disagree — it had
> already happened **in reverse**, GAP-218 having fixed the LIST leg only. All four now refuse
> through `OperatorStoreSelection::unchosen()`, **refusal only**: every `isAbleTo()` check is
> untouched, because `MarketingScreenAccess` (what the list leg uses) *admits* an operator holding no
> permission and its own docblock says it must not gate a write. Whether the operator should be
> **admitted** to these legs is the GAP-218 audience question and stays **the owner's**.
>
> The tenant-authz review of that change found a fifth defect: `abandon_carts_destroy` was the only
> leg with no store guard **at all**, so a storeless merchant or a staff member with an unset
> `current_store` reached `$store->id` on a null — HTTP 500 where a refusal belongs. It also named
> the trap in the obvious fix: `$store?->id` compiles to `store_id IS NULL`, and `carts.store_id` is
> nullable, so that one character would **delete every orphaned null-store cart row in the table,
> across every tenant**. A test now drives the principal that makes it reachable (an operator whose
> selected store no longer exists, for whom `StoreScope` adds no predicate at all) and fails on it.
>
> **The plan-intent acknowledgement — the #204 financial review's finding 9.** Band 2.
> `payments:reconcile` added `unreconciled()`'s whole count to its exit status with no terminal
> state, so one definite provider NO made every later run exit 1 for ever — and that command is the
> only reader of "captured, nothing delivered", so the next genuinely new loss would have arrived
> behind a weeks-old red status. `--acknowledge-intent` / `--reopen-intent`, three nullable columns,
> and the guarantee enforced where it cannot be bypassed: **both conditional consumption UPDATEs now
> carry `acknowledged_at IS NULL`**, which is the one statement every door funnels through.
>
> **The first version of that was wrong in a way worth recording**: it guarded
> `PlanPurchaseSettlement` and claimed in four docblocks that this was "the door the browser return
> comes through". No plan browser return reaches that class — `PaytmPaymentController:489`,
> `CashfreeController:291` and `MolliePaymentController:269` consume the intent **directly**. Between
> them the two reviews found **eleven live items**, all fixed; the full table is
> [`../finance/P204-PAYMENT-RECONCILIATION.md`](../finance/P204-PAYMENT-RECONCILIATION.md) §7.4. The
> sharpest was the mirror image: a settlement committing while the acknowledgement waited on the row
> lock left the payment **granted and recorded as "will never be settled"**, and because
> `plan_order_id` was then set the row matched no `unreconciled()` clause, so the contradiction was
> permanently invisible.
>
> **GAP-131 / P4-F002 — `cart-check-guest` handed any token holder another tenant's stock.** Band 1,
> and the first requirement promoted to VERIFIED COMPLETE since the transfer (170 → 171; Phase 04
> 15 → 16). Reproduced at HEAD before anything was changed: `POST /api/{storeA-slug}/cart-check-guest`
> carrying a store-B product id answered **HTTP 200 with that product's stock** — 4242 plain, 777
> per-variant — **identically for a customer, a merchant and a driver token** of store A, because
> `custom.auth` logs a token's owner into the guard matching its tokenable class and this handler has
> no customer binding at all. `$store = getStore($slug)` was read at the top and used for nothing but
> the "Store not found." early return, and **nothing underneath contained it**: `Product` and
> `ProductVariant` carry `StoreScope` but neither is in `scoped_models`, so no predicate is added for
> any principal.
>
> Both lookups now carry `->where('store_id', $store->id)`. **Fixing only the plain one would have
> looked like a fix** — the variant branch overwrites `$product` before the stock is read — and a
> missing request key was a 500, now read defensively. Nine cases, three mutations each killing
> exactly one. **The family is wider than the row**: `product-detail-guest` is
> reported to disclose the same figure, the siblings are being measured for registration as their own
> rows, and the register says so in terms so that closing this route cannot read as closing the
> family.
>
> **GAP-142 / P4-F106 — `GET api/admin/vieworder` had NO permission check, and it is the route the
> shipped Flutter app opens an order with.** Band 1, and the first of that row's nine families.
> `resolveOwnedStore()` settles tenancy; it settles nothing about **who inside the store** may read
> an order, and nothing else did either — so any authenticated member of the store read the whole
> order, its `order_billing_details` and every internal `order_notes` row while holding no order
> permission of any kind. Now gated on `callerIsStoreOwner() || MerchantPermissions::allowsAny(['Show
> Order','Manage Order'])`, which is the shape `DeleteOrder` already shipped, refusing with an
> explicit **403** — never 401, because the shipped app force-clears its session on one, and never
> `$this->error()`, which answers HTTP 200 by default.
>
> Three controls, because this family has already produced the opposite regression: the platform
> operator still reads it (`isAbleTo()` alone refuses them; `MerchantPermissions` answers WILDCARD),
> the store's **own owner** still reads it (a merchant does not implicitly hold the order
> permissions, and the known-outcome gate once caught a gate here refusing the owner), and staff
> holding either name still read it. **One of my own assertions was wrong and the test caught it:**
> the cross-tenant control asserted a non-200 status and failed against *working* code, because
> `ApiResponser::error()` answers **HTTP 200** with `{status: 0}` — on this API a refusal IS a 200,
> which is also why the new gate had to be an explicit `response()->json(..., 403)`.
>
> **EIGHT rows of GAP-142 remain**, named in the register and the matrix: F108 (driver assignment,
> which writes commission rows), F109, F110, F111, F112 (whole-Order dumps), F113, F065, F075.
> F112 and F108 are next by PII and money exposure.
>
> **Still owed, recorded not fixed:** `acknowledged_by` is always null (console has no session — the
> column is for the operator screen, whose gate must be operator-first); the real-engine lock race
> is pinned by a forced interleave in the suite, not by two MySQL connections; and
> `OperatorStoreSelection::logRefusal()` still writes *"Report refused"* on a cart screen — cosmetic,
> left because the handler name is in the log context and changing the message means hand-editing a
> 273-row observability inventory the resync script will not touch.
>
> #### ACCELERATED PARALLEL EXECUTION — owner directive of 2026-11-25
>
> The owner amended the **methodology**, not the scope or the standards. What changed: stop running
> the full Unit/Contract/Feature/merge-base set after every small change (focused tests during
> implementation, broader suites per integration batch, the complete set on the final candidate);
> fewer, larger pushes rather than a green CI run per intermediate commit; up to eight concurrent
> workstreams with isolated workspaces and non-overlapping file ownership; shorter progress
> narratives. What did **not** change: product requirements, security controls, financial integrity,
> approved business rules, mandatory independent specialist review of security and financial slices,
> failing-first discipline, and the standing production restrictions.
>
> **Worktree isolation is not usable in this container and the equivalent is recorded here so nobody
> re-tries it:** `vendor/` is gitignored, so a fresh `git worktree` has no dependencies and cannot
> run `php artisan test` — and verification matters more than the isolation mechanism. The equivalent
> in use is **strictly disjoint single-file ownership in the shared tree**, with implementation
> agents forbidden from running any git command (the coordinator owns the index and commits) and each
> running only its own focused tests. 4 CPUs, so 4 concurrent implementation agents.
>
> **Genuinely unresolved owner decisions are consolidated in one place:**
> [`OWNER-DECISION-PACKAGE.md`](OWNER-DECISION-PACKAGE.md) — each with the exact choice, a
> recommendation, the consequence of each option and the work it blocks. Nothing in it stops
> independent work, and anything engineering can decide for itself is deliberately absent.
>
> #### BATCH 2 — THE ADMIN-API AUTHORIZATION SWEEP. Committed 2026-11-25, awaiting its CI run.
>
> Four concurrent implementation agents on disjoint files, plus the coordinator. **Eight of the nine
> ungated admin-API permission families under `GAP-142` are closed**, and reviewing those fixes found
> **two live defects worse than several of them**, both fixed in the same batch.
>
> | what | state |
> |---|---|
> | `GAP-142` F106 order detail · F108 driver assignment (a money write) · F109 five driver-credential routes · F110 order notes · F112 whole-order dumps · F113 delete half · F065 coupon writes · F075 six tax/shipping writers | **GATED**, each operator-first through `MerchantPermissions::allowsAny()` with a store-owner limb, refusing with an explicit **403** — never 401 (the shipped app force-clears its session on one) and never `$this->error()` (HTTP 200). F109 carries **14/14 mutants caught with per-mutant exclusivity measured** |
> | `GAP-142` F113 read half · F111 `cost_price` | **ALSO GATED** — the family is complete. Four handlers that all had the tenancy gate and no permission limb: the whole customer book with **total spend** and postal addresses, one customer's orders with a **full delivery address**, the same book behind a search term, and `products.cost_price`. Three mutants each caught by exactly one case. **`P04-P7-01` is NOT promoted, and the reason CHANGED once the gate passed.** The consolidated verification has now run and passed on this tree (Feature 12,040 with exactly the declared failure and skip; the known-outcome gate confirming the set in both directions) — so the §7 reason is spent. The remaining reason is the requirement's own wording: *"EVERY order-mutating and money- or PII-bearing admin API route"*. Nine named families closed is not a measurement of the population, and **my attempt to measure it failed**: a token census over the 124 admin-API handlers reported 84 ungated, three of three spot-checks were false positives (including both wallet money writers, which gate on `canFinance(...)`), and the figure moved when I widened the token list — the controllers define 22+ distinct refusal-shaped helper names. **No figure from it is recorded.** The population needs a behavioural census: drive each route with an unpermissioned staff member and observe. Third time in this batch that my own tooling, not the code, was the defect |
| `GAP-246` | **RAISED AND WITHDRAWN THE SAME DAY — IT WAS FALSE.** I reported that the core product permission family is seeded nowhere in this repository, and the independent review refuted it: `"Manage Products"` is seeded at `Utility.php:2941` and granted at `:3271`/`:3377-3378`, in DOUBLE quotes, which my single-quote-only extraction pattern could not see. The "measured" framing made a tokeniser bug read as evidence. Three existing artefacts already said so, including the ratchet I cited as my authority. No code was wrong — the gate is correct and the name IS grantable, so staff can be delegated it — but the register row, two docblocks and a commit message all carried the false claim and were corrected. A new premise test now runs the real seeder and reads the `permissions` table, so it cannot recur silently |
| `GAP-242` / P4-F115 | **FIXED.** `DELETE api/admin/deletereview/{id}` carried **no predicate of any kind** — any bearer token deleted any store's review: a shopper's, a driver's, a competitor's. Found by a reviewer following the MODEL rather than the diff. The fix derives the store from the review because **the shipped app sends no `store_id`** |
| `GAP-243` / P3-F116 | **FIXED.** `CartController::get_shipping_method:640` held the same `DeliveryAddress::find($request->…)` statement repaired on the API side as GAP-235, byte-for-byte, on the web storefront. Found by the review of that fix **sweeping for the statement elsewhere** |
| `GAP-244`, `GAP-245`, `GAP-247`, `GAP-248` | **RAISED, NOT FIXED, and that is the right call for each** — a storefront payload disclosing `products.cost_price` and a `users.email` at the owning store's own slug (a payload contract, so the owner's); the registered `cart-list` read route that 500s on a stale basket line (inherits GAP-241's commerce decision, and a second pre-flight would make the read and the write disagree); a latent `product_detail` 500 for a variant product with an empty `product_attribute` (no suite fixture reaches it, so the fixture is the work); and a forced driver password reset that leaves `remember_token` standing (pre-existing, orthogonal to the permission axis, and three sibling writers should be checked before one is fixed) |
| One review finding declined, with the reviewer's own reasoning | Agent A's F7 asked that `product_detail`'s dependent lookups key on `$product['id']` rather than the raw request value. **Not taken.** The enclosure was verified mechanically — brace-counted and indentation-checked in both twins — the tests catch the enclosure being broken, and the reviewer itself marked it *"Not required"*. A cosmetic edit inside a slice already under review buys nothing and costs a re-verification |
>
> **THE REVIEWS ARE WHAT MADE THIS BATCH WORTH ANYTHING, and two of their findings were about my own
> tests rather than the code.** One control case **could not fail in two independent ways at once**:
> its only needle was a street address the handler never reads, *and* it supplied no basket, so the
> handler returned before reaching the branch the case was named after. A second case posted an
> empty payload that the fix's own first limb refuses, so nothing store-related was reached while its
> comment claimed a keying distinction was under test. Both are rewritten and now **mutation-measured
> in both directions** — including one mutant that is **NOT** caught, stated in the docblock, because
> bounding what a test is worth is the point.
>
> **Four `assertSame(200, …)` "controls" on handlers where 200 is also the refusal code** were
> strengthened to pair every admitted 200 with a row read. They would have passed if somebody
> narrowed a gate *and* converted its refusal to `error()`.
>
> **A reconciliation hazard specific to concurrent agents, recorded because it produced a wrong
> record before it was caught:** the first draft of the `GAP-142` row credited five closures and
> missed three, because no single agent report describes the tree. It was corrected by grepping every
> named handler for a gate limb. Two line numbers in the original row had drifted ~500 lines, so a
> name grep found what a line lookup would have missed. **A suite run taken while another agent is
> mid-write is also not evidence:** one agent reported five undeclared state-changing GET routes; that
> census is reflection-based and passes on the settled tree.
>
> **Three decisions went to the owner rather than being answered** ([`OWNER-DECISION-PACKAGE.md`
> §G](OWNER-DECISION-PACKAGE.md)): the API now admits the platform operator to coupon/tax/shipping
> writes while the merchant web refuses them on all nine (and F157 is an existing owner decision
> about exactly that) — two test names carry a `_PENDING_AN_OWNER_DECISION` suffix until it is
> answered, because their old names read as an approval; what a storefront product payload may
> contain; and whether the three review handlers need a permission family at all.
>
> #### THE MANDATORY INDEPENDENT REVIEW OF BATCH 2, AND WHAT IT CHANGED
>
> It found **no cross-tenant hole, no privilege escalation and no guard-class trap** in any of the
> five slices — and then found four things that mattered more than a hole would have, two of them
> about my own work rather than the code.
>
> | finding | what it changed |
> |---|---|
> | **`Manage Products` IS seeded — my "measured" claim was false** | The worst error of the batch, and the cause is reusable: my extraction script matched **single quotes only**, and `Utility.php` writes `"Manage Products"`, `"Create Product"` and `"Edit Products"` in DOUBLE quotes while the customer names beside them are single-quoted. The search therefore returned exactly the subset that confirmed the wrong conclusion, and calling it "measured" made a tokeniser bug read as evidence. **Three artefacts in this repository already said so** — `ProductController:3452-3454` in prose, `AdminApiPermissionExposureTest:566-577` by running the seeder, and `DashboardPermissionNamesTest:162`, the very ratchet I cited as my authority, in its own failure message. GAP-246 withdrawn; the register keeps the row as the record of the failure mode. No code was wrong: the gate was right either way, but its ARGUMENT was weaker than the truth — staff really can be granted the name |
> | **Two existing tests asserted the exact opposite of the new gate** | `ApiStoreOwnershipPredicateTest:249` and `:278` build an unpermissioned staff member and assert they are **not** refused. Both failed — undeclared, so a hard known-outcome-gate block. My regression ran `Security` + `Tenancy`; these live in `Platform`. **Third time this session** that "re-run the suites that NAME the file" would have caught something. Fixed by granting the names in those two fixtures, with a comment in each saying why that is not a weakening — the permission axis is pinned in the opposite direction, without a grant, in the new file |
> | **`Show Customer` on the two list routes handed over money the web withholds** | My parity argument cited one line for "the web admits either on a customer READ". True of the DETAIL screen (`show():335` → `:339-341`), **false of the LIST** (`index():312` → `:314`, `Manage Customer` only) — and the citation pointed at neither (`:52` is `store()`, `:525` is `customerTimeline()`). The web keeps `Payments Collected` / `Net Retained` on the list behind `Manage Customer` while the `Show Customer` detail screen shows no money at all, and these payloads carry `total_spend`, `aov`, the `ledger` block and the postal address. **Narrowed `customerlist` and `searchCustomerByName` to `['Manage Customer']`**; `customer/orders` keeps the pair, because its analogue really is `show()` |
> | **My premise test measured the harness, not the seeders** | It asserted `allowsAny($staff, ['Manage Customer'])` while its NAME claimed it had established which names are grantable. `grantPermission()` is a `firstOrCreate` — it creates the row, so the assertion holds for any string. It now runs `Utility::addNewData()` and reads the `permissions` table for all three names, which is the test that would have caught the false claim above. The missing control it also named — staff GRANTED `Manage Products` — is added, and it is the one case that falsifies the claim I made |
>
> Plus six citation corrections (three handler line numbers in my own docblocks had drifted), one
> comment that **contradicted its own next line** (it claimed to avoid `actingAs($c, 'customers')`
> and then called it — the measurement stands, the comment did not), and one `404` that is really a
> 200 because `ApiResponser::error()` takes the status as its *third* argument. `GAP-249` records a
> pre-existing platform-wide customer-existence oracle the new gate neither worsens nor closes.
>
> **Two findings were declined with reasons rather than applied:** agent A's F7 hardening (the
> reviewer itself marked it "not required" and the enclosure is verified mechanically), and moving
> the permission limb above the validators to close `GAP-249` (tenancy must answer first, and three
> shipped suites assert that).
>
> #### Project-wide backlog and decision registers — the canonical pointers
>
> | what | where |
> |---|---|
> | the plan itself (immutable — do not edit, shorten or recreate) | [`master-plan/README.md`](master-plan/README.md), [`MASTER-PRODUCT-PLAN-v1.0-BASE.md`](master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md), [`…-WITH-AMENDMENTS.md`](master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md), [`DECISION-ID-REGISTER.md`](master-plan/DECISION-ID-REGISTER.md) (181 ids, 12 `EX-*` exclusions) |
> | can the plan even be checked | [`MASTER-PLAN-SOURCE-VERIFICATION.md`](MASTER-PLAN-SOURCE-VERIFICATION.md) — **NOT FULLY VERIFIED**; 3 texts (1 GOV, 2 ARCH) still missing and **must not be invented** |
> | requirement-by-requirement | [`MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`](MASTER-PLAN-REQUIREMENT-TRACEABILITY.md) — **350 recovered, 172 VERIFIED COMPLETE, 178 not**; project-wide **370 known, 172 verified, 198 not**. *(This line read 170/180 and 170/200 until 2026-11-25; the document itself had said 172 since the GAP-114 and GAP-131 promotions. A pointer table that restates a figure goes stale silently — prefer the document.)* |
> | the gaps | [`MASTER-PLAN-GAP-REGISTER.md`](MASTER-PLAN-GAP-REGISTER.md) — **GAP-001…GAP-249** (249 rows). *(This pointer said GAP-232, then GAP-245, then GAP-249 in one day, and the middle value was stale within the hour because four more rows were added after it. The register's own header carries the authoritative count — prefer it, and expect this line to lag.)* |
> | security queue | [`../security/P01-SECURITY-WORK-QUEUE.md`](../security/P01-SECURITY-WORK-QUEUE.md) |
> | Phase 05 remaining + its `D-N` register | [`phase-5/`](phase-5/) |
> | Phase 06 scope, the `D-P6-01…D-P6-51` package, owner answers | [`phase-6/`](phase-6/) |
> | Phase 01 release gates | [`phase-1/PHASE-01-CLOSURE-EXECUTION.md`](phase-1/PHASE-01-CLOSURE-EXECUTION.md) |
> | Phase 02 coverage | [`phase-2/PHASE-02-COVERAGE-AUDIT-2026-10-25.md`](phase-2/PHASE-02-COVERAGE-AUDIT-2026-10-25.md) |
>
> **These are recorded checkpoints, not a guarantee every row is current.** Classify each unverified
> requirement as actionable engineering, verification, an owner decision, an approved deferral or an
> external blocker — never all as implementation work.
>
> #### Priority order for everything that follows #204
>
> 1. Security and tenant isolation · 2. Financial and data correctness · 3. Missing approved
> functionality · 4. Cross-phase dependencies · 5. UX, accessibility and bilingual parity.
>
> #### Owner-blocked — do not execute, do not re-ask
>
> | item | state |
> |---|---|
> | **#139 P1-SEC-81** (CRITICAL) | engineering COMPLETE on held branch `p1-sec-81-hold` @ `5c313b0d`, **on no remote**. Publication held by owner decision 2026-11-20; production remediation is owner/operator. **Keep confidential.** |
> | **PAY-SEC** (task #141) | confidential pre-existing payment findings. No public exploit detail, no production probing. |
> | **#120** P1-SEC-40 | the database-dump exposure incident. Deferred ≠ remediated. |
> | **#137** | final project-wide integration and security review. |
> | #129 P1-SEC-74 · #132 P1-SEC-76 batch 2 · #190 GAP-162 correction · #112 D6 stage 2 (GAP-214) · #67 P4-1 | each needs an owner decision or holds vendored/owner-only residue |
>
> #### Standing restrictions (full text in §6)
>
> **This repository is PUBLIC.** No credentials, production data, confidential vulnerability detail
> or privately held remediation patches. No production deployment, credential rotation, live-host
> change, repository-visibility change, unauthorised merge, or fabricated approval. Do not edit
> vendored `packages/workdo/` (except first-party `LynomiaChat`) — guard from first-party code. Green
> CI is a checkpoint, not release readiness.
>
> #### What could not be transferred
>
> Nothing was lost. The held P1-SEC-81 branch stays local and unpublished **by owner decision**, not
> by accident.
>
> *(That paragraph used to end "Task #204's code is not started — only its measurement, dispositions
> and order of work are recorded above." It was true at the transfer; #204 was executed on
> 2026-11-25 and the block above records it.)*

---

> ### HISTORICAL — the previous NEXT EXECUTABLE ACTION (owner directive of 2026-11-22)
>
> Superseded by the block above on 2026-11-25 and **kept verbatim as the record of what was executed
> in Phase 05**. Its head and CI references describe that moment, not now.
>
> #### (original heading) NEXT EXECUTABLE ACTION — owner directive of 2026-11-22
>
> **MODE: EXECUTE THE VERIFIED BACKLOG. DO NOT START ANOTHER SURVEY.** The owner's 2026-11-22
> directive is explicit: the nine-row investigation is accepted as a basis for correcting the
> records, and the priority from here is finishing the code, not producing another measurement
> report. Long multi-agent investigations are reserved for the final integration candidate.
>
> **LAST CI-VERIFIED HEAD: `7086b15a`** — **ci.yml #620 (run 45304308180), SUCCESS**
> (2026-11-24 02:04:49Z), all seven jobs, read job by job rather than from the run's conclusion:
> both REQUIRED Tests jobs (the clean install and the PayHere-excluded one, each with its
> *"The Feature outcome set must match the record"* step green), the merge-base regression gate
> including its *"Compare failing test identities"* step, static checks including
> *"Guard - packages/workdo/ is unmodified"*, the secret scan, `composer audit` and the PayHere
> report. Six commits were verified by that one run.
>
> `25339527` **#619, SUCCESS** (2026-11-24 00:47Z) verified the head before it, and `b4687e0e`
> **#618, SUCCESS** (2026-11-23 21:11Z) the one before that. Earlier heads each have their own run
> (`d60fed3a` #589, `62427391` #584, `306d6a93` #583, `704f1eec` #582).
>
> `6c41ebd6` #617 is NOT a verification of anything: it was **cancelled** by the push that
> superseded it. A cancelled run is not a green one, and this line names the last run that actually
> concluded successfully — which is the whole reason the line exists.
>
> **Commits after `b4687e0e` are verified by the LOCAL gate only until their own required run
> concludes** — read the run for the head you are on rather than trusting this line, which is
> written before the push it describes.
>
> #### Executed since `62427391` (all 2026-11-22, in the owner's priority order)
>
> | what | where it is recorded |
> |---|---|
> | Four Marketing read screens refused the platform operator | `GAP-218`, closed |
> | Period filters on the five report screens that genuinely had none; a crash in the downloadable report found by its own fixture | `ANA-002` / `GAP-221` |
> | The gap register's summary had drifted from its rows; it now has a consistency gate like the matrix's | `GapRegisterConsistencyTest` |
> | The Phase 05 analytics routes were outside every authorization census | `P05-18` / `GAP-166`, `AnalyticsRouteAuthorizationCensusTest` |
> | Conversion and campaign arrivals had no written definition | `PHASE-05-METRIC-DEFINITIONS.md` §13, §14 |
> | **The operator dashboard:** *Top Selling Store* ranked across currencies and printed in the wrong one; *Most Popular Store* loaded the whole visit log into PHP; the page answered 500, or signed the operator out mid-render | `P05-14`, `P05-19` / `GAP-162`, `GAP-167` |
> | `P05-14` re-traced DOWN from VERIFIED COMPLETE: the operator's view mirrors 3 of the merchant's 12 figure groups | matrix note dated 2026-11-22 |
> | Storefront-API orders recorded the currency SYMBOL as their currency CODE | `P5-R14`, `OrderCurrencyCodeAgreesAcrossWritersTest`; a read-only count for the rows already written, `report-order-currency-codes.php` |
> | The analytics screen's own Download button ignored the period on screen | `P05-15`; five tests that follow the link |
> | Three of the analytics screen's eight store predicates were held by no test | `MerchantAnalyticsTenantIsolationSweepTest`, one mutation per predicate |
> | **Eight rows a measurement called *already done* were put to refutation; seven fell** | matrix note dated 2026-11-22; `P05-15` re-traced DOWN from VERIFIED COMPLETE |
> | Ten owner questions met while building or reviewing, recorded and not decided | `D-N31`…`D-N43` |
>
> #### Next, in order
>
> **The refutation changed the order.** It did not only correct rows; its reviewers DROVE the
> vendored modules and the financial doors, and what they found outranks the items that were next.
>
> 1. **SECURITY — the vendored Campaigns and Marketing modules.** Two items are already on the
>    security queue as read-but-not-executed (`P1-SEC-69`, `P1-SEC-76`); a reviewer has now driven
>    them with the modules loaded and found tenancy and authorization defects, some not yet on the
>    queue. **They are being fixed before they are described** — an unpatched finding is not
>    written up in a repository. Exposure exists only where a module is enabled, and **whether one
>    is enabled in production cannot be read from here** — the owner or operator can answer that
>    with one query against `add_on_managers`, and should. Vendored code is not edited: guard from
>    first-party code, failing-first with the module loaded through the production loader.
> 2. **SECURITY — three leads from the dashboard review**, read and not executed: the role-grant
>    ceiling for a merchant who changed their own type; the reserved role-name gate under an
>    accent-insensitive collation; a LandingPage writer with no check beyond sign-in.
> 3. **FINANCIAL — a currency change through the admin API does not convert the wallet**, so the
>    next paid order writes its ledger row in one currency and its wallet movement in another.
>    Reported from a reviewer's probe; reproduce it first. The shipped Flutter app calls that door.
> 4. **`P5-R15`** — `/analytics`: the two money tiles do not follow the period control; an Arabic
>    merchant meets untranslated text; the Sales Report's files carry none of their screen's
>    sentences, and no test compares a file with its screen in the direction that matters.
> 5. **`P5-R4`…`R7`, financial correctness** — payment-state badges (`GAP-149`), missing caveats
>    (`GAP-152`), a collected-payments customer figure beside Total Spend (`GAP-156`), Payments
>    received on the demand-only reports (`GAP-153`).
> 6. **`P5-R13`** — the **eight** cross-tenant figures `/platform/analytics` lacks. Was nine;
>    `f87bb160` ("1 of 9") shipped *Repeat purchase*. Measured 2026-11-24: merchant **12** figure
>    groups, operator **5**, four of them counterparts plus the operator's own store count.
>    **AND THE EIGHT ARE NOT ALL BUILDABLE, which corrects what this queue and `GAP-162` both
>    said.** A mapping pass (four readers, four adversarial verifiers, all SOUND_WITH_CORRECTIONS)
>    measured **4 / 3 / 1**: buildable now — estimated gross profit, visits, devices, orders by
>    channel; **owner decision first** — conversion (`D-N32`/`GAP-155`: the page-view basis has
>    never been accepted and withdrawing the tile is a registered option), referring pages and
>    campaign traffic (`D-N41`/`D-N42`: what a row cap means across tenants, and aggregated
>    versus attributed, decide the SQL); **blocked by a data gate** — coupon redemptions. Two facts for the build:
>    the empty-scope guard is the one omission that would ship red, and the PLATFORM EXPORT has
>    no breakdown loop — `FigureCsv::breakdownRows()` already exists and the merchant file already
>    uses it, so what is missing is a loop in `PlatformAnalyticsController::export()`, not new
>    machinery. (Corrected: this line first said `FigureCsv::rows()` was breakdown-blind and the
>    seam had to be built.) **TWO OF THE FOUR ARE NOW BUILT (2026-11-24):** `devices`
>    (`3e9f9339`, with the breakdown export loop the other breakdowns need) and **estimated gross
>    profit** -- `StoreRevenueMargin(true)`, the same metric and settled-only flag `/finance`
>    uses, one row per currency, `total()` never called -- and **page views**, a count and so not
>    blocked by `D-N32`, carrying the attribution-start and never-recorded-store coverage
>    statements -- and **orders by channel**, which needs no row cap because `order_source` is
>    writer-authored from a closed vocabulary rather than visitor-authored. So ALL FOUR buildable
>    counterparts are built and **four** remain, every one of them BLOCKED: `conversion` on
>    `D-N32`, `channels` on `D-N41`, `campaigns` on `D-N42`, `redemptions` on its data gate.
>    Nothing further on this row is engineering's.
>    **The financial review of the profit slice refuted its own central claim** -- the
>    `UNKNOWN_CURRENCY` row really does add different currencies, and for a margin that can reverse
>    the sign -- so both money tables now disclose that row; the merchant tile's dropped tax clause
>    is restored; `FigureCsv::currencyRows()` no longer leaks bidi isolates into the download's
>    disclosure column (it did for `payments` too); and the currency rows are explicitly sorted.
> 7. **`P5-R8`…`R12`** — four-combination evidence, the per-slice eight-point record, the Phase 05
>    final report, the loyalty metric as a MySQL harness script; register the ANA-005 harness.
>    **The STRING half of `R8` is now a test rather than a habit** (`P05-16`): a census of the 33
>    sources behind the two analytics screens found **7 of 183** `__()` keys declared in NEITHER
>    language file, so they rendered in English on the Arabic page. Six are declared; the seventh
>    was REMOVED, because reading it in order to translate it showed it told the operator the
>    opposite of what the code does -- guest orders are excluded from the cross-tenant Buyers
>    headcount and the sentence said they "are counted here at all". A dead near-duplicate key in
>    both files went with it. `AnalyticsMessagesAreDeclaredTest` holds the rule, with the
>    metric-class scope derived by glob.
>    **AND `R8`'s RENDERING HALF IS NOW DONE TOO** (2026-11-24): both analytics screens captured in
>    all four AR/EN x light/dark combinations, each shot carrying its own HTTP status, rendered
>    `dir` and computed `body` background, 8 distinct contents by the capture-integrity checker --
>    `docs/development/phase-5/evidence/p5-r8-four-combinations-2026-11-24/`. The audit over the
>    same four reports **zero** contrast failures, zero overflow, zero broken images and zero
>    nameless controls on either screen. The only findings are three SHARED-CHROME tap targets
>    under the floor (sidebar search, rail toggle, breadcrumb), left to the owner because they are
>    in the layout Phase 2.5's accepted surfaces all render.
>    **THE FIRST CAPTURE RUN WAS WRONG AND ITS OWN PREMISE LINE CAUGHT IT:** the operator's four
>    images came out byte-identical in light/dark pairs. `getSuperAdminAllSetting()` reads
>    `created_by = <operator> AND store_id IS NULL`, and the state tool wrote
>    `(store_id = 1, created_by = <first admin>)` -- a row the operator's layout cannot see. NOT a
>    product defect: an operator-owned row was inserted by hand first and both screens went dark,
>    so the tool now derives the row's owner from the principal. The dev fixture was also 12
>    migrations behind, which is why these screens had never been captured at all.
>    **Three matrix rows were stale in the pessimistic direction and are re-measured** -- `P05-05`
>    (its `wc -l` 3660 is 4489, its `grep -c payment_status` 0 is 2 AND BOTH ARE IN COMMENTS, and
>    five report surfaces have been re-pointed since), `P05-16` and `P05-24` (`phase-5/` holds 17
>    files and an `evidence/` directory, not three). `P05-05` and `P05-16` move NOT IMPLEMENTED ->
>    PARTIALLY IMPLEMENTED; the project-wide 350 / 170 verified is unchanged.
> 8. Then Phase 06 on its own branch and Draft PR, without merging the existing phase PRs.
>
> **THE RULE THAT GOVERNS EVERY PUSH:** a push CANCELS a running required run (`ci.yml` sets
> `cancel-in-progress: true`), so nothing is pushed while one is in flight. Broken once, on #574.
> Held correctly on 2026-11-22 when a stop hook asked for a push while #583 was mid-flight; #583
> then concluded SUCCESS and the push followed. **Commit freely, push only when the required run on
> the previous head has concluded.**
>
> ---
>
> #### What the 2026-11-22 re-measurement changed, and why the records could not be trusted
>
> **EIGHT OF NINE Phase 05 rows were wrong about the code.** Only `ANA-003` was accurate. With
> `ANA-004` the day before, **nine of ten rows measured in 48 hours were stale**, and the cause is
> one repeatable mistake, now logged as **`GAP-229`**: a census searches for an identifier the
> feature does not use, and its zero result is written into the matrix as an absence.
>
> | row | what the row said | what the code does |
> |---|---|---|
> | `ANA-004` | conversion analysis "absent entirely" | `conversion()` ships on five merchant surfaces |
> | `MKT-001` | no marketing screen links to analytics | all five do, via `marketing/_analytics_link.blade.php`; the grep searched `theme_analytic`, a different route |
> | `ANA-002` | report screens have "no period filter at all" | six have one via `components/ss/report-filter.blade.php:69`, **and `ReportFilterBarTest` already passes on those six** |
> | `MKT-002` | five disclosure literals absent | true, and misleading: all five surfaces carry a sixth mechanism (`x-ss.marketing-filters`) — phone-only, so the PARTIAL verdict survives |
> | `MKT-010` | greentic omits the conversion hook | it calls it at `:20`; fixed and closed as `GAP-220` on 2026-11-21 and **the matrix was never updated** |
>
> **The rule this establishes, now in force:** an absence claim must be established by BEHAVIOUR —
> find the surface a merchant would use and read what it renders, read the layer that feeds it, and
> only then search by name, for several plausible names — and must state which searches were run.
> A zero grep result is evidence about a string, never about a capability.
>
> **All nine rows were rewritten on 2026-11-22 and each correction was re-verified by hand against
> the code, not taken from the measurement alone.** No row was promoted to VERIFIED COMPLETE on the
> strength of an automated measurement; every one stayed `PARTIAL`, which is why
> `MasterPlanTraceabilityCountsTest` still passes unchanged.
>
> **`MKT-001`'s verified links are preserved and must not be rebuilt.** Five screens, one shared
> partial, gated on `ReportAccess::permits()` — the route's own predicate — deliberately NOT on
> `@permission`, which would hide the link from the operator and from a tenant owner whose role
> predates the permission name.
>
> ---
>
> #### The backlog this produced
>
> **33 items buildable with no owner decision** (being executed now, in the owner's priority order:
> security and tenant isolation, then financial and data correctness, then missing functionality,
> then cross-phase dependencies, then UX/accessibility/bilingual parity).
>
> **18 items needing a decision**, consolidated into `docs/development/phase-5/PHASE-05-OWNER-DECISION-REGISTER.md`
> as **D-N13…D-N30**, each with the exact decision, options and consequences, an engineering
> recommendation, what is safe to build first, and what stays blocked. **Six are already answered by
> an existing decision and are not re-asked; twelve are genuinely open.** None of the 33 depends on
> any of the 18.
>
> **ONE NEW CORRECTNESS DEFECT, found by the re-measurement and logged as `GAP-228`:**
> `ProductController::update()` validates `cost_price` as `required|numeric|min:0|lte:price` at
> `:1869` and **never assigns it** — the only write in the file is `:837`, inside `store()`. A
> merchant whose product has no cost can never add one: the form demands the value, accepts it, and
> discards it in silence. Every "at least N paid orders have no product cost on record" disclosure
> is therefore permanent by construction. Not a policy question.
>
> ---
>
> #### Standing constraints, unchanged
>
> - **`P1-SEC-81` remains a CRITICAL confidential production-remediation item.** The held fix is on
>   no remote. Do not publish details, merge, deploy or fabricate human security approval. The
>   owner/operator action remains outstanding and must stay visible.
> - **The `Security-sensitive diff: human checklist` CI gate is red by design on every head** and is
>   the owner's to clear; its companion `human approval` job passes. Writing the marker would
>   fabricate human security approval, which §9 forbids. Documented on PR #140 in three places.
> - **Never invent financial, marketing, legal or consent policy.** Where a figure needs a rule that
>   does not exist, disclose the omission and record the decision — the pattern `ANA-009`,
>   `MKT-007` and `MetricThreshold` already use.
> - **Do not rebuild existing WorkDo engines**, and do not edit `packages/workdo/` as a shortcut.
>
> ---
>
> #### Resource rules added 2026-11-22, after a near-loss
>
> - **Check whether equivalent work is already running before launching an investigation.** A
>   nine-row measurement was duplicated because silence was read as failure; the original was alive
>   and finished after 4.3 hours.
> - **Never delete the only copy of a workflow's evidence.** The disk filled and truncated the
>   completed run's 1 MB result file to **0 bytes**; the findings survived only because its journal
>   had been kept deliberately during cleanup.
> - **Keep headroom for test artefacts and logs.** The Feature suite writes a JUnit and a TeamCity
>   log on every gated run.
---


## Original-plan traceability (permanent, added 2026-11-16)

Three documents govern whether the delivered product matches the **owner-approved** plan, as
distinct from whether the engineering passed:

| document | what it answers |
|---|---|
| [`MASTER-PLAN-SOURCE-VERIFICATION.md`](MASTER-PLAN-SOURCE-VERIFICATION.md) | **Can the original plan even be checked?** Answer: **NOT FULLY VERIFIED** — the Decision Register v1.0 was never committed to this repository. |
| [`MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`](MASTER-PLAN-REQUIREMENT-TRACEABILITY.md) | **350 requirements recovered. 172 VERIFIED COMPLETE. 178 are not.** Project-wide, including the recovered-and-untracked: **370 known, 172 verified, 198 not.** *(Corrected 2026-11-25. This is the SECOND copy of the same figure in this file; the first, in the canonical-pointers table above, was corrected earlier the same day and this one was missed — which is the argument against restating a figure at all.)* |
| [`MASTER-PLAN-GAP-REGISTER.md`](MASTER-PLAN-GAP-REGISTER.md) | **GAP-001…GAP-249** (249 rows). 181 open gaps out of the 350, plus 18 recovered 2026-11-16, 2 more on 2026-11-24, and **48 raised by the work of 2026-11-25** (`GAP-200`…`GAP-249`, one of which — `GAP-246` — was withdrawn the same day as a false finding and is kept as the record of its cause). Of the original 181: 56 category A (must complete before release), 16 B, 73 C, 11 D, 25 E; the 20 recovered are deliberately `COVERAGE NOT ASSESSED`, and the 48 later rows carry no status term at all. |

> **Corrected 2026-11-25, TWICE, and the second time is the lesson.** This table read *"169
> VERIFIED COMPLETE / 181 are not"* and *"181 open gaps"*, which disagreed with the documents it
> cites. Re-read from those files, it became 170/180 and GAP-232. Both figures were stale AGAIN
> within the same day — 172/178 after two promotions, and GAP-249 after 17 more rows — and the
> second staleness was found only by a deliberate sweep comparing every restated figure in this file
> against the document that owns it. **Four copies of two numbers existed in this one file.** The
> figures above were re-read from those two files. No requirement status
> was changed; only this summary was brought back into agreement with its sources.

**CI success does not establish product-scope completeness.** See `CLAUDE.md` §4.1 for the rule and
the four states that must never be merged into one label.

## 1. Where the code is, exactly

| | repository | `main` | active branch |
|---|---|---|---|
| Laravel (canonical backend) | `fullstackfull/syriastore98` | **`4e878b8d`** — the Phase 2.5 merge (PR #134) | `feature/phase-05-marketing-analytics` *(corrected 2026-11-17; was `feature/phase-03-storefront`. The Phase 03 and Phase 04 branches still exist and are both ancestors of this one.)* |
| Flutter (merchant/staff app) | `fullstackfull/StoreSyria_app98` | **`cbf6283f`** — the Phase 2.5 merge (PR #9) | none; stays on `main` unless a Phase 03 contract need arises |

Both SHAs verified against the owner's directive. Both Phase 2.5 heads are ancestors of their
`main`. Post-merge CI: Flutter **success**; Laravel success per the owner's confirmation.

**Flutter is a merchant and staff app. It is not a customer storefront and not a commerce backend.**

---

## 2. The six-phase Master Product Plan

The owner-approved roadmap. It is the governing source of truth; nothing below replaces it.

| phase | scope | state |
|---|---|---|
| **01 Foundation** | architecture, WorkDo integration, security foundations, schema, tenancy, permissions, API contracts, design foundations, CI gates | engineering complete. **Release gates still outstanding** — not waived by any later phase |
| **02 Merchant Experience** | merchant + employee experience on Laravel web and the Flutter app: products, inventory, orders, customers, settings, employees, roles, reporting | **closed for development**, merged |
| **02.5 Premium Merchant Experience** | premium UI/UX across merchant web and Flutter, with verified evidence | **COMPLETE, owner-visually-accepted, merged to both mains** |
| **03 Storefront, Official Site, Docs** | customer storefront across 3 themes, section-based theme editor, official bilingual website, bilingual documentation centre | **ENGINEERING COMPLETE (M0–M7), 2026-11-15.** Head `f4315390` on `feature/phase-03-storefront`; PR #138 open, **Draft, NOT merged**. Nine CI checks green including both required Tests jobs and the merge-base regression gate; the tenth, `Security-sensitive diff: human checklist`, is **red by design** and is the owner's to clear. See `phase-3/PHASE-03-FINAL-REPORT.md` |
| **04 Commerce** | remaining commerce workflows on the existing WorkDo engine | **ACTIVE — authorised 2026-11-15.** Branch `feature/phase-04-commerce`, based on the Phase 03 engineering head `f4315390` and therefore **dependent on PR #138**; it carries Phase 03's commits and must not be merged ahead of it. No duplicate commerce engine, ever. Measured at the outset: **488 of 1,476 registered routes** are commerce-shaped, and the surface already carries **59 `tests/Feature/Commerce` files** plus a `tests/Feature/Payments` directory, gateway replay and idempotency guards, and order-total reconcilers — so this phase is discovery-and-repair, not greenfield. P4-0 closed with a register of **94 defects confirmed by independent adversarial review** out of 117 claimed (17 refuted, 6 already tracked) -- `phase-4/PHASE-04-FINDINGS-REGISTER.md`. Four money or catalogue defects are fixed and falsified so far: a flat coupon applied once per cart LINE (a 120.00 basket wrote a COD order of **0.00**), the cart readers' missing spend gate, twelve order writers pricing shipping from **the first merchant in the shared database**, and `ordersave`'s shipping-on arm omitting the product subtotal. Two more are recorded as owner decisions rather than fixed, because each changes what a merchant's live coupons do or what a money column means. **P4-4 closed stock integrity across every cancel and return door; P4-3b (2026-11-16) closed order-write integrity**: the two storefront API writers now write the order and its four child rows in one transaction (a caller could previously make a `coupon_limit = 1` coupon **pay out twice**), `OrderController::store()` refuses another store's customer, and the web checkout gained the duplicate-submission claim the API already had (a repeated POST previously wrote a second order and took the stock twice). See `phase-4/` |
| **05 Marketing & Analytics** | campaigns, growth, merchant analytics, reporting | not started |
| **06 SaaS & Super Admin** | SaaS administration, plans, subscriptions, tenant governance, premium Super Admin UX | not started. **Final project audit (#137) happens after this**, before any production release |

---

## 3. Phase 2.5 — the authoritative matrix

**225 REDESIGNED AND VERIFIED · 24 PREMIUM VERIFIED · 28 OUT OF SCOPE · 8 DUPLICATE · 10 BLOCKED ·
0 PENDING · 295 total.**

Generated by `docs/development/phase-2.5/tools/build-coverage-matrix.py`, which has a `--check` mode
that fails on drift. **Do not hand-edit it.**

All 24 Premium surfaces were visually accepted by the owner after thirteen corrections
(`phase-2.5/PHASE-2.5-OWNER-VISUAL-CORRECTIONS.md`). The ten BLOCKED rows are **disposed, not
outstanding** — 4 accepted on MySQL measurement, 1 on existing evidence, 2 deferred on external
sandbox credentials, 3 scheduled into Phases 04/06
(`phase-2.5/PHASE-2.5-BLOCKED-DISPOSITIONS.md`). They remain `BLOCKED` in the matrix because none
has a four-combination visual capture, which is the only thing that status has ever meant.

**Do not restart Phase 2.5 and do not rebuild the merchant UI.** Reopen a merchant surface only for
a demonstrated regression or a genuine Phase 03 dependency.

---

## 4. Phase 03 — active work

Plan: `phase-3/PHASE-03-EXECUTION-PREP.md` (reconciled against merged `main`).
Progress: `phase-3/PHASE-03-M0-STOREFRONT-BASELINE.md`.

### Milestones

| | milestone | state |
|---|---|---|
| **M0** | storefront harness, fixtures, theme/locale capture, route and tenant verification | **complete** — 7 of 8 gaps closed; gap 1 (no dark mode) is M2 by design |
| **M1** | measured storefront baseline across all three themes, defect inventory | **complete for rendering; performance and accessibility NOT measured** — see below |
| **M2** | premium storefront across `stylique`, `techzonix`, `greentic` + dark mode | **complete (A–E)** — per-store appearance preference, three themes tokenised, **160-load cross-store matrix with 0 axe WCAG A/AA violations**, Core Web Vitals baselined. Not merged, not released. See `phase-3/PHASE-03-M2-PREMIUM-STOREFRONTS.md` |
| **M3** | theme editor data model: draft/publish/version, safe migrations, tenant ownership | **complete (A–E)** — atomic publish/rollback/preview with conflict detection, theme-isolated section ordering, and six two-process concurrency scenarios on MariaDB. **C passed a full gate** (`6bd3e4d2`: Unit 1793/1 skipped, Contract 44, Feature 8628 passed with the known-outcome set matching the manifest exactly -- 2 declared failures, 1 declared skip, nothing undeclared, nothing added to the manifest) — inventory + derived allowlist (`2abc369b`), four additive migrations with engine-enforced integrity including a **composite** key that makes cross-tenant publication impossible, rollback proved on MySQL, **21/21** constraint checks under production's `sql_mode`, then **one controlled write path** (gate, type-aware validator, canonical payload hash, post-commit cache flush) with the merchant form and the Flutter endpoint routed through it and their HTTP contracts unchanged. Independently reviewed at B and C. **E closed the integration question the earlier milestones left open:** the one-controlled-write-path claim measured across `app/` and `packages/` (two writers, one reader, one dead import, and a vendored `store_theme_customize` that is a different table nothing else reads), and M3-C's post-commit cache flush **re-measured under its real caller**, which nests the service inside its own transaction and so turns that transaction into a savepoint. The nesting is safe and now pinned. See `phase-3/PHASE-03-M3-THEME-VERSIONING.md` §9–§12 |
| **M4** | theme editor experience: sections, preview, publish, rollback, permissions | **engineering complete (M4-0…M4-9)**. M4-0: the four GET theme routes had NO test of any kind (every existing theme test was a POST); 9 now drive them, asserting content, with the empty-gallery-at-200 trap measured and closed. M4-1: the read path moved off `getCurrentStore()` — the last of the eight methods using it — and `{theme}`/`{slug}` are contained before any path is built, so a bad identifier is a refusal rather than the 500 a promoted `file_get_contents()` warning used to produce. M4-2 (**owner decision 2026-11-15**): one authorization boundary on all **eight** theme routes, both verbs, closing the measured inversion in which zero-permission staff could not open the editor yet could drive every write by direct POST — and `theme.file.get`, which had no authorization at all. Live writes now require the verified tenant owner, a same-tenant holder of `Manage Themes`, or the operator naming a store explicitly. See `phase-3/PHASE-03-M4-THEME-EDITOR-PLAN.md`. M4-3: the editor's two reads -- `ThemeConfigurationService::liveSettings()`, a key-complete read **behind the gate** (the only key-complete read before it was the global helper `getThemeSetting()`, which takes no user and authorizes nothing), and `App\Support\Theme\ThemeFieldTree`, the page -> section -> field metadata a grouped editor needs and the catalogue deliberately discards. The tree's name set is pinned to `editableFields()` in both directions, so a field cannot become editable-and-invisible or visible-and-unsaveable. Measured while building it: `homepage.json` is a **second, incompatible schema** contributing **0** fields to all three themes, `pages.json` is an **incomplete index** omitting `homepage` and `terms-condition`, and each theme has **exactly 2** duplicate field names of two kinds -- cross-file (`blog_status`/`article_status`) and within-file (`track_cancel_title`) -- which `editableFields()` collapses silently and the tree now surfaces. M4-4: **the first version a store ever owns**, captured lazily. M3 built publish, rollback, preview and history and **no store could use any of them** -- the versions migration backfills nothing, so every store owned zero versions and rollback had no target. A backfill migration would be a persistent production write the owner reserves, so the baseline is taken per store and per theme on first editor open or draft save: no migration, no bulk write, and stores that never touch their theme never gain a row. It holds the **stored overrides only**, not the ~500-key merge -- key-completeness would make a later rollback materialise every theme default as an explicit row, permanently detaching the merchant from their theme's defaults -- and it is captured **without validation**, because it records what IS rather than a change anyone proposed (the legacy corpus contains markup the allowlist would refuse). Proved end to end: rolling back to the baseline restores exactly what was live. M4-5 (**seam only**): a draft preview needs a seam, and the three obvious ones are each closed by something measured here -- the view composer RECOMPUTES `$themeSettings` (`ViewServiceProvider:23`, `:65`) so controller data is silently overwritten; a `{theme}` route parameter switches on a latent branch (`:50-55`) that assigns the **unvalidated** route string to `theme_id` and resolves the store through the very `getCurrentStore()` store-1 fallback M4-1/M4-2 removed; and a `themes` path segment is read as a theme name by `ActiveTheme:71` whenever the store or its `theme_id` is falsy, which includes the pre-launch state a merchant previews from. So the seam is a **request-scoped** `ThemePreviewContext` that `getThemeSetting()` consults, keyed on the `(store, theme)` pair, idempotent (the composer fires twice per page), bound `scoped` not `singleton` so a draft cannot survive into a shopper's request. **M4-5 complete.** The preview route renders the draft through the REAL storefront handler (`HomeController::storeSlug()`, widened from private) rather than a second renderer that would drift from three themes. Three measured surprises on the way: `landing_page()` re-derives the store from `url()->full()`, so it 404s from any non-storefront path; the view composer `abort(404)`s when `request()->route('storeSlug')` is null, one layer deeper than the settings seam reaches, so the route must carry `{storeSlug}` — bound to the gate-resolved store, never determining it; and `Auth::user()` returns a **Customer** under `actingAs($c,'customers')`, so the middleware pins `Auth::guard('web')`. The context is disarmed explicitly after the response rather than trusting `forgetScopedInstances()`, because a draft never reaching a shopper should hold by construction, not by how the app is served. M4-6: publish, rollback and history **over HTTP** -- `ThemePublishingService` had existed since M3-D with **no production caller**, so the whole engine was reachable by nothing; these are the first requests that arrive at it, under the owner's rule. The controller owns four things the service deliberately does not: the **refusal audit** (a refused publish left no trace at all -- and it writes the row directly rather than through `AuditLog::record()`, which sets `store_id` from `getCurrentStore()`, the very store-1 fallback M4 removed); the **retry decision** (the service converts a collision into a refusal and does not retry, because a retry must re-read the live version first -- so the conflict is reported, never silently republished over the other writer); surfacing **`keysNotInSnapshot`**, since publish and rollback delete nothing and a rollback predating a setting leaves it live; and translating the refusal, whose service strings name the installation. M4-9 (**correctness fixes only; the reorder UI is deferred**): `order.blade.php:89` loaded jQuery UI from **the vendor's public demo site** (`demo.workdo.io/vcardgo-saas/...`) -- a third party serving executable JavaScript into an authenticated merchant's admin page -- while the identical library ships locally and the sibling view in the same directory already loads it correctly. Vendored, and pinned by a ratchet scoped to the theme-editor views. `updateOrder()` took `page_slug` and `orders` from hidden inputs **unexamined**; both are now validated against the theme. The subtle part: the themes' shipped `orders` strings **do not match** their real section keys (stylique/home declares 9 against 10 sections -- 3 phantom tokens, 4 real sections missing; greentic declares no orderable page at all), so validating tokens against the sections would make the product refuse its own default ordering. The accepted set is the **union**. M4-7: **the version panel**, which is where the M3 engine becomes visible -- draft state, save-as-draft, preview link, publish with the expected-live-version carried in the form (so the optimistic check is real rather than inert from the UI), publication history and per-version restore. It renders **no control the merchant cannot use**: with no draft there is nothing to publish, so it shows an empty state rather than a disabled button. The editor captures the **baseline on open**, so a store whose shop has been live for months does not read "nothing published yet". A **third boundary** was needed and is deliberate: `theme.draft` (membership) for saving and previewing drafts, beside `theme.editor` (management) for live writes and publishing -- inside the management group the draft route was unreachable for exactly the staff it exists for, measured. **The reorder UI stays deferred** until the storefront consumes saved ordering. M4-8: **151 Arabic strings added and the editor asserted in Arabic** -- 17 editor UI strings and 134 taxonomy strings (81 field labels, 47 section titles, 11 page titles, re-measured rather than taken from the survey). Coverage is now **100%** of the editor UI and of all three themes' taxonomy, pinned by a test that walks the real theme JSON so a new field tomorrow fails CI by name. **M4 CLOSED.** The reorder UI stays deferred until the storefront consumes saved ordering, recorded with its dependencies. |
| **M5** | official Store Syria website on the WorkDo LandingPage foundation | **engineering complete**. Seven public pages under `/platform` — overview, features, pricing, getting started, FAQ, contact, legal — with a shared responsive bilingual header and footer. **The foundation is kept, not replaced:** `/` still renders the LandingPage module and nothing here adds a second builder. Measured reason these are first-party routes rather than module content: `composer.json` carries **no PSR-4 entry for `Workdo\`**, so `class_exists('Workdo\LandingPage\Entities\LandingPageSetting')` is **false** until `loadAddons()` runs — a view naming one of those classes is a fatal error in every test run and wherever the module is off; and the module's other public surface, `landing-pages/{slug}`, stores its HTML in a database row, so shipping the company site that way would need a **production seeder** (not authorised) and would put the marketing copy outside git. **Route precedence is load order and is now proved, not assumed:** `routes/customer.php` is required from `routes/web.php:1691` and declares `{storeSlug?}/faq`, `{storeSlug?}/contact-us` and the rest; moving the identical platform group to the end of the file makes `/platform/faq` resolve to **`HomeController`** — falsified, 3 tests fail. The mirror risk is closed too: `User::slugs()`, the single place store slugs are minted (`StoreController` :134, :283, :563), now treats `platform` exactly like a taken slug and hands the merchant `platform-1`, so no store can be permanently shadowed by these pages. **Prices are read, never written:** no view contains a price; `plans` is sorted in PHP because `price` is a string column; an installation with no plans says so rather than showing an illustrative figure. **`-1` is the unlimited sentinel, not `0`** — measured at `User.php:339`, `DashboardController.php:2966` and `Utility.php:868`, where a `0` permits **zero**; the first draft had it backwards and would have printed "Unlimited" beside a plan that sells nothing. `''` and `'off'` are kept apart, because *not configured* and *not included* are different answers to a buyer. **Capability copy has anchors:** 34 claims in `App\Support\Platform\PlatformCapabilities`, each module-backed claim rendered only while `packages/workdo/<Module>` is on disk, with the rendered page tied to the filtered list in both directions. Theme names are read from `themes/`. **Two pages deliberately say nothing:** contact (the application reads `contactus_url` and no other contact setting, so the page links that where set and otherwise states the details are being confirmed) and legal (owner restrictions reserve legal policy, so five documents get a stable address, a description and a plain "not published yet"). Both are **owner content items**, not missing engineering. 42 tests, every control falsified; 214 Arabic strings added to both JSON files with the `ar.json` surplus unchanged at 31. See `phase-3/PHASE-03-M5-OFFICIAL-WEBSITE.md`. |
| **M6** | bilingual documentation centre with working search | **engineering complete**. `/platform/docs` — 5 categories, 16 articles, **both languages, 42 Markdown files in `resources/docs/`** — with search, breadcrumbs, a table of contents, related articles, an in-category pager and a script-free mobile nav. Explicitly NOT the vendored `/api-docs` (`packages/workdo/Api/src/routes/web.php:18`, behind the merchant auth stack, documents the API not the product). **The corpus is files, not rows**, for the same measured reason as M5: a database-backed centre only has content after a seeder runs on PRODUCTION, which Phase 03 may not do, and prose in a row cannot be diffed or asserted — `the_two_languages_carry_the_same_articles` is only possible because they are files. **Search is PHP over a cached index, and a `LIKE` query would be WORSE**: MySQL's default collation folds Latin case and accents and does nothing about Arabic orthography, which is where the misses are. Eleven folding rules, each its own named test — alef variants, alef maqsura, ta marbuta, hamza carriers, tashkeel, tatweel, Arabic-Indic digits — so a merchant typing `مسوده` finds `مسوّدة`; without them that search returns NOTHING, silently. The opposite direction is asserted too, because a normaliser returning a constant would satisfy all eleven. Ranking is title 8 / summary 4 / body 1 **per term, not per occurrence**. No stemming, no synonyms, no fuzzy matching — and the results page says so rather than implying it. **Markdown renders with `html_input => strip` and `allow_unsafe_links => false`** (the article body is the only unescaped output on the platform site), and heading ids are post-processed so the SAME `slug()` builds the id and the contents link and they cannot drift; Arabic headings get Arabic ids. The TOC is read from the Markdown, so a heading in a code fence is not an entry. **The locale is the request's, with no `/en/` or `/ar/` path segment** — that would give every article two addresses disagreeing with the header's language switch — and an untranslated article is served in English **with a visible notice**, marked `lang="en"` and `dir="ltr"`, never as a 404 and never silently. That notice is driven against a REAL gap (the test writes an English-only article and removes it in `finally`); an earlier version asked for locale `fr`, which resolves to the English corpus, so the notice was never reachable. 51 tests, every control falsified — emptying the normalisation map alone reddens 12. See `phase-3/PHASE-03-M6-DOCUMENTATION-CENTRE.md`. |
| **M7** | Phase 03 integration, evidence, acceptance package, closure | **engineering complete**. Three browser harnesses over the public surfaces — 11 pages × {en, ar} × {light, dark} = **44 loads**: **44/44 premises satisfied, 0 off-origin requests, 0 console errors, 22/22 light-dark pairs whose background genuinely differs, 0 axe WCAG A/AA nodes, 0 best-practice nodes, 3/3 click-through journeys, 64 internal links and 32 in-page anchors with none broken, worst body contrast 13.73:1, worst lab LCP 224 ms, worst lab CLS 0.0234**. A row counts as evidence only when the page is not the error page, the content area holds real text, `dir` agrees with the locale, the surface's own content marker is present and nothing was fetched off-origin — four of those five ways of lying answer HTTP 200. The light/dark pair is compared AS A PAIR, because a run rendering the same background twice would otherwise report success. **Three real defects found by rendering rather than reading, all fixed:** (a) the public site read `cust_darklayout`, the MERCHANT ADMIN's preference, so a visitor on a dark OS got a light marketing page — now `data-ss-theme="auto"`, the design system's own name for following `prefers-color-scheme`, with the operator setting kept as an explicit override; (b) `color-contrast` × 15 — `--ss-brand-accent` on `--ss-brand-subtle` measures **3.64:1** in light, below AA for 12px/600 text, so all nine text uses moved to `--ss-brand` (5.85:1 light, 7.18:1 dark); (c) `landmark-complementary-is-top-level` × 12 — the docs sidebar was an `<aside>` inside `<main>` and is now a plain `<div>`, since the real landmark is the `<nav aria-label>` already inside it. All three were invisible in the source. **Two harness defects recorded because each looked like a product defect:** a raw `LANGUAGE` cookie is discarded by `EncryptCookies` (22 Arabic loads came back LTR English — the harness now drives `/change-languages/{lang}`, which is also the real journey), and a selector must be `:visible`-scoped where the navigation is rendered twice from shared markup. **Honest about what it does not say:** zero axe violations is not an accessibility pass (axe automates ~a third of WCAG; keyboard order, focus management and label quality still need a person), the vitals are LAB numbers from `artisan serve` on localhost with no TLS, CDN, HTTP/2 or opcache and no INP at all, and the 650 KB page weight is dominated by the 365 KB design system that `artisan serve` does not compress. **Gates at Phase 03 close: Unit 1845 passed / 1 skipped (15,903 assertions), Contract 45 passed, Feature 8,828 passed / 2 declared failures / 1 declared skip (45,015 assertions) with the known-outcome gate matching the manifest EXACTLY and nothing added to it, and every CI guard green including `guard-no-workdo-changes` — `packages/workdo/` is untouched.** A ratchet fired on the way and that is the system working: `ResidualTenancyExposureTest`'s line-absolute raw-query census moved when M5/M6 added lines above `getRelatedSlugs()`; it was re-read rather than renumbered — same method, same `DB::table()` call, no predicate added or removed. See `phase-3/PHASE-03-FINAL-REPORT.md`, `phase-3/PHASE-03-M7-INTEGRATION.md` and `phase-3/evidence/m7-platform-2026-11-15/`. |

### M1 — the three-theme baseline

`phase-3/PHASE-03-M1-THREE-THEME-BASELINE.md`. Evidence:
`phase-3/evidence/m1-storefront-baseline-2026-11-12/`.

**M0 measured ONE theme three times.** `themes/` ships `stylique`, `techzonix` and `greentic` — 13,
15 and 16 blade files, each with its own `main_file`, layout, section markup and ~520-key settings
file — and every M0 fixture store carried `theme_id = 'stylique'`. Five fixture stores now cover all
three plus the empty-`theme_id` fallback.

| | measured |
|---|---|
| rows | **210** (5 stores × 2 locales × 3 viewports × 7 routes) |
| premise-fail | **0** |
| images / broken | 2,496 / **0** |
| horizontal overflow | **0** |
| `dir="rtl"` on Arabic rows | **105 of 105**, all three themes |
| cross-store gate | **210 captures, 210 distinct contents, 0 collisions** |
| route health | **80 of 80** store × route combinations answer 200 |
| console 404s | 210 — one reference, `/public/js/flipdown.js` (see below) |

**NOT measured and NOT claimed:** performance, accessibility, product-detail and checkout-flow
captures, and dark mode (there is none to capture). Those are separate instrumentation and belong
with the theme work that will change the pages anyway.

A theme that fails to resolve falls back silently, so a capture labelled `techzonix` that rendered
`stylique` would pass every M0 premise check and be mislabelled evidence. The harness now asserts
the rendered theme against the store's declared one, and
`tests/Unit/Ui/StorefrontThemeCoverageTest.php` fails if a **fourth theme** is added that the
harness and fixture do not know about.

### The eight M0 harness gaps

Measured 2026-11-11, re-verified against `main` 2026-11-12.

| # | gap | state |
|---|---|---|
| 1 | storefront has **no dark mode at all** (0 files reference `cust_darklayout`/`data-ss-theme`/`style-dark`) | **closed by M2** — `data-ss-theme` on `<html>` before first paint, three states, per-store key, 160 loads verified |
| 2 | the Phase 2.5 state control does not drive the storefront | **closed** — harness drives `change.languagestore` |
| 3 | premise checks are merchant-admin shaped | **closed** — storefront-shaped checks |
| 4 | no harness can sign in as a customer | **closed** |
| 5 | fixture cannot render a credible storefront (`theme_customizes` has 0 rows) | **closed** — 20 rows; the two tenants' `/home` now differ by 2,722 lines |
| 6 | almost no imagery (2 of 32 products) | **closed** — `storefront_image()` + committed placeholders; 710 broken → 0 |
| 7 | store 2 is empty | **closed** — 3 categories, 2 brands, 8 products, one deliberately out of stock |
| 8 | storefront RTL unverified | **closed — and it works** |

**All eight closed.** Gap 1 was M2 work by design and M2 closed it.

### Phase 03 tooling

- `phase-3/tools/seed-storefront-fixture.php` — repeatable customer-auth fixture. Guarded by
  `DevFixtureDatabaseGuard`; refuses to run outside a dev fixture.
- `phase-3/tools/storefront-matrix.cjs` — customer-authenticated storefront capture across locales
  and viewports, with storefront-shaped premise checks and cross-tenant negatives.
- `phase-2.5/tools/serve-dev.sh` — the dev server. Creates **dev-only, gitignored**
  `public/themes` and `public/uploads` symlinks; production needs neither because its document root
  is the repo root. It deliberately does **not** bridge `storage/`, because `public/storage` is a
  real tracked-regular-file defect and hiding it in development would hide it everywhere.
- `phase-3/tools/verify-appearance.cjs` — the appearance-preference contract: 40 browser checks
  covering storage failure, keyboard operation, the accessible name in both languages, and `auto`
  re-theming on a live device change without a reload.
- `phase-3/tools/verify-a11y-matrix.cjs` — the M2-E cross-store matrix. 160 loads over 5 stores x 2
  locales x **4 appearance states** x 4 routes. `auto` is driven twice, under an OS that says light
  and one that says dark, because **`auto` is the default state** and sweeping only `light`/`dark`
  tests the two states fewest shoppers are in. Each load **reads the rendered appearance and text
  direction back** and exits non-zero if either is not what was asked for — a sweep of a state that
  never applied is not evidence.
- `phase-3/tools/verify-performance-a11y.cjs` — Core Web Vitals and an axe pass over 80 loads.
  **Its axe results were wrong until M2-E:** it clicked the appearance toggle before running axe, so
  every violation was attributed to the state before the click. It read as 59 contrast nodes where a
  direct sweep found none. axe now runs first.
- `phase-3/tools/verify-light-mode-unchanged.cjs` — did a theme's light mode move? Swaps in **both**
  the committed theme stylesheet and the committed shared layer; reverting only half attributes the
  layer's changes to the theme. **It only knew two themes until M2-E**, so passing `greentic`
  injected stylique's stylesheet and reported coral-to-green "regressions"; now one baseline per
  theme, and an unknown theme exits non-zero.
- `phase-3/tools/verify-contrast.cjs`, `verify-no-cascade-regression.cjs` — composited contrast
  (it skips elements whose backdrop is an image or gradient, where a ratio against a colour says
  nothing) and the cascade-safety comparison.
- `phase-3/tools/check-cross-store-captures.py` — **new.** Fails if any capture is byte-identical
  to another *store's* capture. The Phase 2.5 distinctness check cannot answer this: it groups by
  directory first, which in Phase 2.5 means "a different wave" (benign) and in Phase 03 means "a
  different store" (the thing to catch). It found 28 such collisions on the first Phase 03 evidence
  set, all of which every other check passed.

**`phase-2.5/tools/check-distinct-surface-captures.py` now takes an optional evidence root.** It
had hardcoded the Phase 2.5 path and silently ignored any argument, so running it over Phase 03
evidence printed a clean Phase 2.5 result. CI calls it with no arguments, so that gate is
unchanged. `detect-refusal-toast-captures.py` was deliberately **not** repointed — it resolves
surface names through the Phase 2.5 matrix builder; the storefront harness's own premise checks are
the equivalent.

### Open Phase 03 findings

| finding | state |
|---|---|
| a product with no image renders `<img src="">` | **FIXED** — `storefront_image()` at 22 merchant-owned sites; 0 broken, 0 empty across both tenants |
| an unknown store slug answers 200 and serves another tenant's shop | **FIXED, and the finding was mis-stated.** It never served another tenant: `storeSlug()` was `redirect()->back()`, so the harness was sent back where it came from and photographed it. 10 routes answered 500 or a 302 bounce; all now 404 |
| empty `stores.theme_id` → base layout, no `dir`, `themes//…` asset path | **FIXED, and worse than recorded** — `/home` could not resolve `main_file` and the other 11 page routes answered **500**, not a degraded layout. Three places now read `config('theme.active')` |
| `theme_customizes` is **not** in `config/tenancy.php` `scoped_models` | **open** — pinned for the storefront READ (`ThemeCustomizeIsStoreScopedTest`); every editor/preview/publish path still needs its own predicate (M3/M4) |
| `theme_customizes` has no `status`/`is_draft`/`version`/`published_at`; `landing_page_settings.name` is UNIQUE | **open** — M3 must change the data model before M4's UI |
| `process-order`, `support-ticket`, `blogs/filter/view` answer **500 for a store that exists** | **open** — pre-existing, found by the 45-route sweep. `process-order` is a payment route and was deliberately not touched |
| **the fixture seeded `products.product_type = 'simple'`, and `/product-list` was empty for 4 of 5 stores** | **FOUND AND FIXED at M2-E.** `product_type` is `NULL` for an ordinary product; a non-null value names a *module* product (`auction`, `digital`) and ~a dozen queries select `where('product_type', null)`. `HomeController:979` therefore computed a price-range maximum of `0`, the slider rendered `max="0"`, and the page's AJAX call asked for products priced between 0 and 0. Four stores answered 200 with a correct-looking page and no products, so **every Phase 03 measurement of that route since M0 was thinner than its numbers suggested**. Seeder fixed, 24 rows repaired; re-running the matrix on populated pages immediately found two more real defects |
| **a Flutter social-links save does not reach the storefront** | **NEW at M3-A, untracked before it** (searched `docs/` and `tests/` first; no Contract test covers `social_media` or `footer_repeater`). `Api/DashboardController:9032` writes with raw `DB::table()->updateOrInsert()`, which does not invalidate the `Cachable` Eloquent read the storefront uses. Measured on MariaDB with `CACHE_STORE=database`: the app reads the new links back (its own read is raw) while the storefront footer keeps rendering the old ones. **Fixed as part of M3-C** by routing that endpoint through the same write service, with the HTTP contract unchanged |
| **theme-settings writes are unauthorized while theme-settings READS are locked** | **CLOSED at M4-2** (owner decision 2026-11-15). Tracked as `docs/discovery/03-FEATURE-MATRIX.md:223` (*Theme settings write authorization*, HIGH/P0); M3-A re-measured it as **inverted** — the four reads gated on `isAbleTo('Manage Themes')` while the three writes carried **no check at all**, so zero-permission staff could not open the editor and could drive every write by direct POST. One `theme.editor` boundary now covers all **eight** routes, both verbs. **TWO CLAIMS IN THE ORIGINAL ROW WERE RETRACTED AND ARE CORRECTED HERE**, because this file restated them after the retraction: `Manage Themes` is **not** held by "no role and no user" — that was the TEST FIXTURE; in production `Utility::addNewData()` grants it to the shared global `admin` role on login. And store 1 does **not** "belong to a merchant" — that is unverified, and false on a clean seed, where `PermissionTableSeeder` gives it to the operator. The refusal never depended on the answer. `Edit Theme` genuinely is not a permission row |
| **the same live theme write was enforced by a weaker rule on the Flutter admin API** | **FOUND BY INDEPENDENT REVIEW AT M4-2, FIXED.** M4-2 put every *web* theme route behind `theme.editor`, but `ThemeConfigurationService::writeSettings()` still gated on `allows()` (tenant membership) and its **second** production caller — `POST api/admin/updateStoreOwnerInfo`, `Api\DashboardController:9054` — carries no such middleware. So zero-permission staff went on rewriting `footer_repeater` on the live storefront through the shipped app. Fixed **at the service**, because it is the one controlled write path and a fix at the route is what let this survive. Contract unchanged (200, `status: 1`, `social_media` absent from `updated_fields`, no new 401). Pinned by `StoreOwnerInfoThemeWriteContractTest` |
| **the vendored `ThemeCustomizer` module has no authorization at all** | **open — ledger item, out of bounds.** `packages/workdo/ThemeCustomizer/src/routes/web.php:18-19` is `['web','auth','PlanModuleCheck:ThemeCustomizer']`: no `verified`, no permission, no ownership. It writes `StoreThemeCustomizer` keyed on `getCurrentStore()`, so any authenticated tenant member repaints the live storefront and an operator with an empty `current_store` writes **store 1** — the exact trap M4 closed on the first-party routes. Different table (`store_theme_customizers`), so M3's "only writers of `theme_customizes`" still holds. Vendored, which CLAUDE.md puts out of bounds. Only live when the module is installed and enabled |
| **"naming a store explicitly" is sticky state, not per-request intent** | **open — recorded, benign today.** `storeIdForSelfService()` reads `users.current_store`, and its refusal fires only when that column is **empty**. A seeded operator already has it set to store 1 (`PermissionTableSeeder:40-50`), so they reach store 1 through a different door than the fallback M4 removed. Benign on a clean seed, where store 1 is the operator's own; **unverified on production**. If M4-6 adds publication attribution, log the resolved store id on success as well — `ThemeEditorAccess` logs only refusals today |
| **the `xss` middleware corrupts lowercase `alert` and `javascript` in merchant copy** | **ALREADY TRACKED** as `docs/discovery/11-RELEASE-READINESS.md` **S-09** (*"provides no XSS protection and silently corrupts passwords"*). M3-A adds the theme-settings consequence, measured through the real middleware: `Smart alerts for shoppers` is stored as `Smart s for shoppers`, while `Alert & Co <b>Bold</b>` passes through untouched — the sweep is case-sensitive, so it damages ordinary copy and stops no XSS. Only password fields are exempt. **M3 does not rely on it**; the type-aware allowlist in `App\Support\Theme\ThemeFieldCatalog` is the real control |
| **the themes' own shipped defaults are not all valid** | NEW at M3-A. `terms_condition_policy_repeater` is invalid JSON in all three themes, `team_slider` ships a PHP array where a JSON string is declared, and `privacy_policy_create_date` ships a human-readable string against a declared `date` type. A strictly typed version store would reject the themes' own defaults, so M3-C validates what a merchant WRITES and must not fail a publish over a malformed default it never touched |
| an unknown-slug 404 renders the framework's page, not a branded storefront 404 | **STILL OPEN after M2** — M2 was appearance and contrast work and did not add a branded error page. M2-E measured the framework page and it has its own contrast failure (`#a0aec0` on `#f7fafc`, **2.15:1**), so a branded 404 is both a brand gap and an accessibility one. Deferred to M3/M4 with the theme editor, which is what would own a themed error page |
| `POST api/admin/updateStoreOwnerInfo` validated the raw `store_slug` and stored `Str::slug()` of it, so two stores could hold one slug | **FIXED** — normalised before the uniqueness rule. Cross-tenant storefront mis-resolution (every resolver is `where('slug',…)->first()`, lowest id wins), not a tenancy or data-access defect. `stores.slug` still has **no unique index** and duplicates may already exist in production — adding one needs an audit first |
| **every Arabic store name produces the slug `-`** | **OPEN, and ALREADY TRACKED — not a new finding.** Pinned as audit **sections 3.1 and 3.2** in `tests/Feature/Platform/StoreSlugIdentityTest.php`, which asserts them as defects on purpose and is gated by the **owner directive of 2026-10-22** (URL format and migration strategy to be documented first). Phase 03 adds one fact the audit does not record: `Str::slug('متجر دمشق')` is `mtgr-dmshk`, so the platform already ships a working transliteration on its API path. See §10.2 and the **correction in §11** — I broke the 3.2 pin with an out-of-scope fix and reverted it. | `User::slugs()` tests `empty()` *before* stripping non-ASCII, so the intended `store-<uniqid>` fallback can never fire. `متجر دمشق` → `-`, `صابون حلب` → `-`, `متجر Damascus` → `-damascus`. Shared by `Product`/`Tag`/`ProductBrand`/`ProductLabel`, and reached from the **admin app's** own store-creation path (`Api\DashboardController:7369`). Two lines to repair, but it decides what an Arabic merchant's public URL should be — transliteration or opaque id — so it is escalated, not chosen. Also bears on **P1-SEC-61** (`products.slug` not unique). Full trace: `phase-3/PHASE-03-M0-STOREFRONT-BASELINE.md` §7.5 |
| `HomeController::wishlist()` does an unscoped `Wishlist::…->get()` | **open, not a leak** — verified: the view never renders `$wishlists` (AJAX-filled) and no theme overrides it. A wasted full-table read |
| the storefront links to category routes that do not exist (`/{slug}/textiles`, `/{slug}/soaps`, …) | **open** — `<a href>` links, so they do not appear in the console count |
| `asset('public/…')` — **76 references across 18 files** emit `/public/<path>`, which resolves only if the document root is the repository root | **open, ALREADY TRACKED as P2-A-01 / F16.** Phase 02 fixed the admin layout and deliberately deferred the sweep. Two measurements **disagree** on the live doc root: P2-A-01 measured `/public/…` → 404 against the running application, while P1-SEC-40's `/.git/HEAD` → 200 implies the root IS the repo root. Settling it needs a live-host request the owner's restrictions forbid. **Either way, closing P1-SEC-40 by moving the root to `public/` would break all 76 at that moment** — pinned by `tests/Unit/Security/PublicAssetPrefixCouplingTest.php` |
| `public/js/theme-custom.js` and `public/images/pos_image1.png` are absent | **open** — ordinary broken assets, both **outside** the storefront (merchant layout and marketplace). Recorded, not fixed |

---

## 5. Security — open, deferred, and not fixed by deferral

**The owner has deferred the comprehensive security review to the end of Phase 06 (Issue #137).
That is a deferral, not a remediation.** None of the below is fixed.

| id | finding | state |
|---|---|---|
| **P1-SEC-40** / #120 | live test host serves `/.git/` and historical credentials | **OWNER-DEFERRED — UNRESOLVED.** Apache deny rules exist but are deliberately dormant in `docs/security/p1-sec-40-apache-deny.conf`; both `.htaccess` files are directive-identical to `main` so a merge cannot arm them |
| **P1-SEC-61** | `bidingForm` unscoped + `products.slug` not unique | message **closed**; root cause **open** (needs a vendored patch or a `UNIQUE (store_id, slug)` migration) |
| **P1-SEC-62** | operator diagnostic readable via the publicly served log | **open, accepted, dependent** — closed by remediating P1-SEC-40 |
| `public/storage` | a **tracked regular file** holding the vendor's build path, not a symlink, so no `/storage/…` URL resolves from a clone | **open** — worked around for Phase 03 by committing both placeholders under `public/images/`; the defect itself is untouched and still affects every deployment |
| user deletion / financial retention | limits recorded in the work queue | open |
| Phase 01 release gates | outstanding | open |

Full queue: `docs/security/P01-SECURITY-WORK-QUEUE.md`.

---

## 6. Standing owner restrictions

Unchanged and in force:

**No** secret rotation · **No** live-host access change · **No** production deployment ·
**No** history rewrite or force-push · **No** deleting live data · **No** repository visibility change ·
**No** falsified evidence · **No** forged security acknowledgements · **No** representing deferred
exposure as remediated · **No** claim of production release readiness.

The Apache deny rules were deliberately moved to a documentation-only file. **Do not reactivate them
through a Phase 03 merge or deploy.**

---

## 7. Testing and evidence standards

| suite | expectation |
|---|---|
| Unit | green. ~1,640 passing; **1 skip is pre-existing** (the production-dump fixture is absent) |
| Contract | green — the Flutter API contract |
| Feature | **not green and not meant to be**: 2 declared failures + 1 declared skip in `.github/known-test-outcomes.json`. The gate asserts that set **in both directions** |

**Last measured on `feature/phase-03-storefront`, 2026-11-12:** Unit **1,640 passed** / 12,228
assertions / 1 pre-existing skip · Contract **38 passed** / 149 assertions · Feature **8,582
passed** / 43,964 assertions / 2 declared failures / 1 declared skip · known-outcome gate **OK —
observed set matches the manifest exactly, nothing undeclared.**

**Evidence rules, carried forward from Phase 2.5 and not relaxed:** a capture must show the route it
claims; no redirect, refusal toast, blank fragment, reused file or wrong storefront counts. The two
capture-integrity gates run over Phase 03 evidence as well. Ratchets are never widened to get green;
when one fails, the code changes.

**A 200 is not evidence.** Three of the four ways a storefront capture lies answer 200.

**Adding a COMMENT above a logged line breaks the observability gate.**
`docs/security/P01-OBSERVABILITY-INVENTORY.md` records each security log line by
`file:line`, and `SecurityLogInventoryTest` asserts the two agree. M1's explanatory comments in
`CustomerLoginController` shifted two lines (`770`→`778`, `995`→`1007`) and the known-outcome gate
reported an undeclared failure. The fix is mechanical — update the inventory's line numbers — and
the gate's own message says so. **Never add the failure to `known-test-outcomes.json` to get
green**; that file records failures the owner accepted with a named root cause.

**Before raising a defect, grep `tests/` for it.** This codebase deliberately PINS some known
defects as passing tests — `tests/Feature/Platform/StoreSlugIdentityTest.php` asserts the Arabic
slug collapse and the 101st-collision null *as defects*, so that an audit's measurements cannot go
stale silently. A pin reads exactly like an unnoticed bug until you find the file that owns it, and
"fixing" one breaks it. Phase 03 M0 did precisely that and had to revert.

**Independent review is required before committing a change to authorization, tenancy, a route's
middleware, an order, a payment, or a handler that reads an id from request input** — the
`tenant-authz-reviewer` subagent. On the M0 fail-closed change it found no authorization defect and
three real misses, one of them a positive control too weak to detect the single regression that
change could cause. That pattern holds: the review's value has been in the *evidence*, not only the
code.

---

## 8. Phase 03 milestone record (historical)

> **This section's heading formerly read "Next executable action".** Retitled 2026-11-25 because it
> records the Phase 03 M0–M3 milestones and is not a statement of what to do next; the canonical
> NEXT EXECUTABLE ACTION is the block at the top of this file. No content below was changed.

**M0, M1 and M2 are complete.** M2's five packages:

| | what | evidence |
|---|---|---|
| M2-A | appearance foundation: per-store browser preference, 3 states, pre-paint | 40 browser checks, 0 failures |
| M2-B | stylique, and the finding that a tokenised stylesheet is **not** sufficient | 84 captures, 4 combinations |
| M2-C | techzonix, re-hued dark variant, the shared rule that broke two other themes | 84 captures |
| M2-D | greentic, where **neither ink passed** on the hover and the colour had to move | option A taken, B recorded |
| M2-E | integrated cross-store verification, contrast, accessibility, Core Web Vitals | **160 loads, 0 axe WCAG A/AA violations**; 80 loads, 0 CWV rows outside "good" |

### M3 — A, B, C, D and E complete; engineering closed, not released

**M3-D added atomic publishing** (`ThemePublishingService`), **theme-isolated section ordering**
(`theme_customize_orders.theme_id`, additive and nullable, nothing backfilled), and a **two-process
MariaDB concurrency harness** covering the six races the directive named. 20 checks, 0 failed, over
eight consecutive runs.

**It found two production defects in the services themselves**, both in code written earlier in M3:

1. **A draft save blocked behind an open publish and died after 50 seconds.** `saveDraft()`
   allocated `version_no` under `lockForUpdate()` over the `theme_versions` range — the same rows a
   publish holds until commit. `1205 Lock wait timeout exceeded`; in production, a 500 on *Save
   draft*. Allocation is now optimistic, with the unique index as the arbiter it always was.
2. **A deadlock victim got a 500 instead of a refusal.** Two concurrent first-publishes gap-lock the
   same unique index range, their insert-intention locks conflict, and InnoDB kills one — *before*
   anyone reaches the duplicate key. And the first fix changed nothing, because
   **`Illuminate\Database\DeadlockException` extends `PDOException`, not `QueryException`** —
   measured. Any handler that catches `QueryException` to deal with concurrency has the same hole.

**AND THE FIRST PULL REQUEST FOUND TWO MORE THINGS, which is why it was opened before the work was
finished.** `ci.yml` fires only on `pull_request` and pushes to `main`/`master`, so **no CI run had
ever covered any Phase 03 commit**. Draft PR #138's first run refused: 44 fixture tiles committed
under the repository-root `uploads/` tree (P1-OWN-10 / owner decision 11 — `.htaccess` serves a file
that exists there directly, with no application and no authorisation), and the storefront fixture's
dev password tripping the secret scanner. Both are **M0/M1 conditions, not M3-D's**. Both fixed at
the value: the tiles moved outside anything served and are staged by the seeder, and the password
was renamed into the scanner's own self-labelling vocabulary. **No rule narrowed, no path excluded,
no workflow weakened.**

**`Security-sensitive diff: human checklist` is red and must stay red.** It requires a person to work
`.github/SECURITY-DIFF-REVIEW.md` and put the marker on its own line in the PR description. That is
an owner action; adding it from automation would be a falsified security approval. The attestation
workflow listens for `edited`, so it re-runs on a description change with no commit needed.

The M3 design, its evidence and the five findings that corrected it are in
`phase-3/PHASE-03-M3-THEME-VERSIONING.md`. The shape: **`theme_customizes` keeps its exact schema and
becomes the published projection**, three additive tables carry versions, the live pointer and an
append-only publication log, and drafts live only in `theme_versions` — which no storefront path
reads, so a draft cannot leak to the storefront. Measured: 111 routes carry `storeSlug` and **75 of them carry no auth middleware**, all 75 running `ActiveTheme` + `ThemeLanguage`.

**M3-C closed the two unauthorised writers** (§9 of that document). `ThemeConfigurationService` is now
the only writer to `theme_customizes` in `app/` — verified by grep across `app/` and `packages/`; the
one other writer in the repository is `docs/development/phase-3/tools/seed-storefront-fixture.php`, a
development fixture tool that writes directly by design. It resolves the store through `ThemeEditorGate` (operator
first, then explicit ownership, then permissions — never `getCurrentStore()`, which hands an operator
the literal store 1), validates each value against the theme's own declared type, upserts named keys
only, and flushes the model cache after commit. The Flutter `updateStoreOwnerInfo` social-links write
went through it with its status codes, response shape and `updated_fields` list unchanged, which is
what fixes the measured defect where a merchant's saved links never reached the storefront.

**M3-C's independent review found a real tenant hole in the gate itself**, and §10 of that document
records it: the gate treated a resolved root of `1` as a tenant, and the shipped installer creates
the OPERATOR'S own store with `created_by = 1`, so a merchant who demotes their own `type` through
the product reached it. The test meant to catch that could not fail — it asserted against a store no
root-of-1 predicate could ever match. Fixed by reusing `UserPolicy::tenantRootIsAMerchant()` (now
public) at both sites. **Unverified against production**: `SELECT id FROM stores WHERE created_by =
1` would settle whether that seeded store still exists there. The same review found two MORE
unguarded writers in the same controller — `makeActiveTheme()` and `updateOrder()`, both with no
check of any kind and both reachable by a direct POST from any authenticated user — now closed
through the same gate.

**And the sharpest find was a production 500 that no test could see.** `theme_customize_orders` has
no `theme_id` column, yet both the section-order read and write filtered on it — M3-C's first fix
carried the inherited predicate forward. Measured on MariaDB: `ERROR 1054 Unknown column 'theme_id'
in 'WHERE'`. **SQLite does not error** — it treats the unresolvable identifier as a string literal,
matches nothing, and the handler carries on — so a Feature test driving the route passes with the
defect in place, and one did. Both predicates removed; section order is consequently keyed on
(store, page) and **shared across themes**, and adding the column is a migration against live data,
so it is recorded as an owner decision rather than taken. Guarded by
`tests/Unit/Theme/ThemeCustomizeOrderColumnsExistTest`, which compares the SOURCE to the MIGRATION,
because a database assertion inherits the same engine blindness.

**A fourth false green, and this one generalises beyond the theme editor.**
`App\Http\Middleware\XSS::handle():56-78` redirects ANY `super admin` to the installer's updater
before the controller runs, whenever it finds one unrecorded migration — and in a test run it always
does, because it walks `Module::allModules()` with a RELATIVE path that resolves from the project
root while no WorkDo module migration ever runs. Measured: the operator's POST answered 302 to
`/update` with the row untouched, so an operator assertion written against the completely unguarded
controller PASSED. **Any test asserting a platform operator is refused on a `web` + `xss` route may
be measuring that redirect rather than the control it names.**

**Two things M3-C established that are worth carrying forward.** First, **CI has never run on
`feature/phase-03-storefront`** — `ci.yml` triggers only on `pull_request` and pushes to
`main`/`master`, so the Unit/Contract/Feature/known-outcome gate has covered no Phase 03 commit at
all; the suites are run locally per package instead, and opening a PR is an owner decision. Second,
the model cache's flush is `Cache::flush()`, and on the **database** store that is a `DELETE FROM
cache` which **joins the open transaction** — so on today's production configuration the
in-transaction flush already behaves like a post-commit one, and the post-commit control only becomes
load-bearing if `MODEL_CACHE_STORE` is ever set. Both directions are measured by
`verify-theme-cache-flush-after-commit-on-mysql.php`.

**M3-E closed the integration question**, which is the one the earlier milestones could not ask of
themselves.

*The "one controlled write path" claim is about the whole repository, so it was measured across
`app/` and `packages/`:* two writers (the two services), **one** reader (`helper.php:3576`, inside
`getThemeSetting()`), a dead `ThemeCustomize` import in `ViewServiceProvider` that is not a path,
and a vendored `store_theme_customize` that turns out to be a **different table** nothing outside
`packages/workdo/ThemeCustomizer` reads. Publish is an UPSERT that **reports** keys absent from a
snapshot and never deletes them — which is what makes "retain Flutter-written social links and
`footer_repeater`" true by construction.

*And M3-C's post-commit cache flush was re-measured under its real caller.*
`Api\DashboardController` wraps `writeSettings()` in its own transaction (`:8922` → `:9054` →
`:9067`), so the service's `DB::transaction()` is a **savepoint** and M3-C's isolated proof did not
cover the shape production actually runs. Measured: a nested savepoint release does **not** fire
`afterCommit`; the outermost commit does, at level `0`; a rollback **never** does. The nesting is
safe, and `ThemeWriteUnderCallerTransactionTest` now fails if anyone replaces `DB::afterCommit()`
with a direct `flushCache()` or adds a stray `DB::commit()`.

*That test's first draft failed, and the probe was wrong, not the service* — Eloquent returned the
rolled-back value while the raw table was already `NULL`, because `ThemeCustomize` is `Cachable`.
The fourth time this phase a theme test read the cache and thought it was reading the table. A
consequence for **D-28a**: both opt-in model-cache stores would keep that phantom value after a
rollback, and no flush repairs it, so `F194-MODEL-CACHE-ISOLATION-RUNBOOK.md` §10 now carries it
with an added post-activation check.

*Recorded, not fixed, and corrected after measuring:*
`ProductCompare/.../pages/compare.blade.php:9` indexes `$themeSettings['compare_banner_status']`
unguarded, and that key is declared in no `theme_json` and written by no handler. I first wrote this
up as a reachable latent 500; **it is not**. A repo-wide grep for renderers finds four compare views
in use and `pages.compare` is not among them — the similar-looking one is `pages.product_compare`,
a different file. So it is **dead code carrying a trap**, a hazard only for whoever first wires that
view up, which could be M4. Vendored, so out of bounds here either way.

The things M2 established that M3 must not break:

- **`theme_customizes` is NOT in `config/tenancy.php`'s `scoped_models`,** so it adds no predicate
  for any principal. M2 pinned this with 6 tenancy assertions; a theme editor writing that table is
  the first surface where a missing predicate becomes a cross-tenant write.
- **A theme's brand colour lives in two places** — `--primary-color` in `main-style.css` and
  Tailwind's `primary` in the theme's JS or layout. The editor has to write both, or move the themes
  to techzonix's pattern of reading the token through `var(--…)`. Asserted by
  `StorefrontAccessibleNamesTest::test_no_theme_declares_a_brand_colour_tailwind_can_override`.
- **Four copies of `front_end/layouts/app.blade.php`** exist (3 themes + the `resources/views`
  fallback) and the theme's own wins. Every layout-level change in M2 had to be made four times.
- **~959 declared theme fields** across the three themes' `default_data/settings.php` are what M4's
  visual editor has to surface.

Do not begin Phases 04–06 early.

### Still owed from M2, recorded rather than fixed

| | |
|---|---|
| 4 axe **best-practice** rules | `region` (119), `heading-order` (17), `landmark-unique` (12), `landmark-one-main` (6). Pre-existing and measured; none carries a WCAG tag. Needs landmark/heading restructuring across three vendored themes — its own slice |
| `front_end/payment/nmi-payment.blade.php` disables zoom | a real WCAG 1.4.4 failure, deliberately left: payment flows are outside an incidental accessibility sweep. Pinned in `ViewportAllowsZoomTest::OUT_OF_PHASE` |
| the `Logout` key is wrong in Arabic | `إغلاق` = "Close". Used in one non-storefront view and two vendor stubs. Other locales are worse (`it.json` gives `Log Out` as `Iscriviti`, "Sign up"). A 16-locale translation-quality pass is its own work |
| `p3fixt-no-theme` has **no catalogue** | created in M0 only to prove the empty-`theme_id` layout fallback, which it still does. It contributes 32 of the M2-E matrix's 160 loads, and on `/home` and `/product-list` those are empty-grid pages. **Next fixture task:** give it a catalogue so the matrix is strong on all five stores |
| no **human** accessibility review | axe automates roughly a third of WCAG. Keyboard order, focus management and whether a label *describes* its control still need a person |
| Core Web Vitals are **lab** numbers | `php artisan serve` on localhost: no network, TLS, CDN, HTTP/2 or opcache, SQLite not MySQL. A regression baseline for M3, not a user-experience claim. INP is a field metric and is not measured |

### The four Phase 03 fixtures a later session will need

| | |
|---|---|
| dev server | `docs/development/phase-2.5/tools/serve-dev.sh` → `127.0.0.1:8899`. **Never export its env vars**; it destroys the fixture |
| fixture | `docs/development/phase-3/tools/seed-storefront-fixture.php`, idempotent, `--remove`, `--report` |
| capture | `docs/development/phase-3/tools/storefront-matrix.cjs --store <slug>` |
| stores | `damascus-textiles` (id 1), `aleppo-soap` (id 2), `p3fixt-no-theme` (id 4, `theme_id = ''`) |

The storefront password is `storefront-fixture-placeholder` and is not a secret. The value self-labels because `scan-secrets.sh`'s `generic-assigned-secret` rule flags `PASSWORD = '<12+ chars>'`, and its recorded norm says a flagged fixture is fixed by changing the VALUE, never by narrowing the rule. Every fixture row carries
a `p3fixt-` prefix or the `P3FIXT` tag.