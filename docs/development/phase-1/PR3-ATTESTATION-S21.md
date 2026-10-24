# §21 — the attestation-gap review: `bfb921d2..1bff9dc7`

**Owner direction, 2026-10-24:** *"For PR #3, perform a focused attestation-gap review of the
previously uncovered commits. Reuse existing verified evidence and do not rerun all 77 commits
individually."*

This section closes the gap PR #3's own §20 left open — *"any commit pushed after `bfb921d2` is
outside this attestation until a section covers it"* — and it closes it on the terms the owner set:
by **reusing the evidence that already exists**, not by re-running or re-reviewing each commit.

It lives in the repository rather than in the pull-request description, for the reason recorded in
that description's own header: on 2026-10-21 a rewrite of it was **cut off mid-text by a safety
classifier and replaced the whole description with a ~6,000-character prefix**, and the write was
not retried. The description now points here. The body as it stood before that pointer was added is
archived byte-identically alongside this file.

---

## 1. The range, and a correction to the figure

| | |
|---|---|
| last attested head (§20) | `bfb921d2` |
| head attested here | `1bff9dc7` |
| **commits in range** | **79** |

**The register's §7 said 77, and that was correct when written** — at head `2efbc1dd`. Two commits
landed after it (`22731010`, the record of the gap itself, and `1bff9dc7`, the sentinel fix), so the
figure is 79 here. Stated rather than silently updated, because a number that moves between
documents is how a reader loses confidence in both.

**Census keyed on the files each commit touches**, not on its subject prefix:

| | commits |
|---|---|
| touch `app/` `config/` `routes/` `resources/` `database/` | **46** |
| touch only `tests/` or `.github/` | 12 |
| documentation only | 21 |
| **total** | **79** |

**That keying is itself a correction.** My first pass keyed on the Conventional-Commits prefix and
counted `9dfcd1c8` (`fix(ci): …`) as production code; it changes **one line of one markdown file**.
That is census-failure mode 2 from this project's own skill — *keyed on spelling rather than on the
thing* — committed today, by me, while writing an attestation about rigour. Recorded for the same
reason the other four census failures are.

Cumulative diff: **203 files, +38,101 / −3,282** across the range; **464 files, +138,270 / −3,921**
against `main` at `1eebc721`, which is what the PR actually proposes.

---

## 2. What is attested CUMULATIVELY, and why this is the load-bearing part

These are properties of the **tree at `1bff9dc7`**, not of individual commits, so they cover the net
effect of all 79 at once. This is what makes a focused review possible without re-running anything:
a per-commit pass could not establish any of them, and they are the claims a reviewer actually needs.

**CI, read back from the API today — not inferred, and not taken from the webhook.** Run
`40102841581`, head `1bff9dc7`: **all 9 check runs `success`.**

| check | job | finished |
|---|---|---|
| **Tests (REQUIRED — real suites, clean install from `composer.lock`)** | 120898345065 | 14:40:39Z |
| **Tests (REQUIRED — real suites, PayHere excluded and NOT fixed)** | 120898345001 | 14:40:53Z |
| **Merge-base regression gate (new failures only)** | 120898344928 | 14:49:12Z |
| Static checks · Secret scan · PayHere report · checklist · 2 push reports | — | all `success` |

Two of those carry most of the weight:

- **The merge-base regression gate is a cumulative statement over the whole range.** It runs the
  suite at the merge base and at head and asserts the branch **introduces no new failure**. Passing
  at `1bff9dc7` is therefore a single verified claim about all 79 commits' net effect on test
  outcomes — the one thing 79 individual re-runs would have been trying to establish.
- **Both REQUIRED Tests jobs install the complete locked dependency set**, which this development
  container cannot (`yoomoney/yookassa-sdk-php` pulls from `git.yoomoney.ru`, which the network
  policy denies). So CI, not any local run, is the authority on the locked set, and it is green.

**Locally on this tree:** Feature **5722 passed, 2 declared failures, 17 declared skips, 0 errors**
(1026s), with `assert-known-test-outcomes.php` reporting the outcome set matches the manifest
**exactly, in both directions** — an undeclared failure *and* an unexpectedly-passing declared
failure both fail that gate. **Nothing was added to `.github/known-test-outcomes.json`** at any
point in this range.

**The vendor boundary holds across the entire range, measured:** `git diff --name-only
bfb921d2..1bff9dc7 -- packages/workdo/` returns nothing outside the first-party `LynomiaChat`
exemption, and `guard-no-workdo-changes.sh` (which compares against `main`, so its verdict is
cumulative) exits 0 at head.

**Not deployed, not merged, nothing live touched.** `main` was not modified, no migration was run
against any server, no live data was altered. PR #3 remains a **Draft titled ⛔ DO NOT MERGE**.

---

## 3. What the per-commit evidence is, reused rather than re-run

Each commit in this range carries its own evidence in its own message. Measured across all 79:

| the message records | commits |
|---|---|
| explicit test counts | **69 / 79** |
| mutants or removal checks | **59 / 79** |
| CI guards or the known-outcome gate | **43 / 79** |
| an independent review and what it found | **15 / 79** |

The 15 with a recorded independent review are the security- and payment-sensitive ones, which is
where this project spent its review budget deliberately. Several record the review finding a defect
**in the fix itself** — `c53cb55b`, `2897ec0c`, `8d18782e`, `47519edc`, `09191cda`, `2efbc1dd` —
and that pattern is the strongest evidence in this range that the reviews were real rather than
ceremonial.

**The one gap, named.** Exactly **one** production-code commit records no test, mutant or gate
evidence in its own message:

> `98c53b8a` — *fix(P1-INV-05): the two read-modify-write restocks go through the atomic primitive*
> (`app/Models/Order.php`, plus a MySQL verification script)

Its evidence exists; it is in the **immediately following** commit, `c25b2745`, which records
`tests/Feature/Security` + `tests/Feature/Commerce` at **4094 passed / 11 skipped, exit 0** and
states that the row **stays BACKLOG** because its acceptance evidence was incomplete. So the commit
is covered, by an adjacent record rather than its own — worth naming precisely because a reader
checking `98c53b8a` alone would find nothing and would be right to ask.

---

## 4. What this section does NOT attest — the boundary, stated plainly

**This is not a per-commit adversarial re-reading of 79 commits, and it does not claim to be.**
§17 set this project's precedent: it attested 3 of its 28 commits and named the 25 it did not.
The same honesty applies here, and the owner's direction asked for exactly this scope.

- **8 commits were authored in this session** — `2f68495d`, `09191cda`, `0afac2cf`, `d082214a`,
  `0d8a48b1`, `673daff4`, `47719ce1`, `2efbc1dd`, plus `22731010` and `1bff9dc7` — each with its
  own gates, mutants and, where the change touched payments or authorization, an independent
  review that **found real defects in the fix** and whose findings are recorded in the commit.
- **The other commits were not re-examined here.** Their evidence is what their messages and the
  ledger record. Writing a section that claimed to have examined them would be the one thing this
  convention forbids.

**What a reviewer should take from that:** the cumulative gates in §2 are verified and cover the
whole range; the per-commit claims in §3 are *recorded* and were not re-verified here. Those are
different grades of evidence and are kept separate on purpose.

**Also not claimed:** that the suite is green. It is not, and is not meant to be — 2 failures and
17 skips are accepted by name in `.github/known-test-outcomes.json`, whose own README forbids adding
an entry to obtain green. The known-outcome gate asserts that set, not success.

---

## 5. The acceptance limitations this attestation is subject to

The owner accepted the engineering completion status **subject to the recorded acceptance
limitations**. They are not restated here — they live in the authoritative documents, and
duplicating them is how they drift:

| limitation | where it is recorded |
|---|---|
| per-task PARTIAL rows and their exact unmet criteria | `12-PHASE-01-TASK-LEDGER.md` |
| the 13 environment checks, each with its prepared command | `PHASE-01-CLOSURE-EXECUTION.md` §B, §9 |
| the 5 D15-gated migrations and their two ordering constraints | `PHASE-01-CLOSURE-EXECUTION.md` §C; `_gate_f_pending/README.md` |
| the two vendor reports, prepared and **not sent** | `docs/upstream/`, `PHASE-01-CLOSURE-EXECUTION.md` §D |
| what Phase 02 must not assume | `PHASE-01-CLOSURE-EXECUTION.md` §4, §6, §8 |

The four most load-bearing, because they are easy to read past. **The first of them is now known
to be false, and is left visible rather than quietly deleted:** this section claimed *the financial
subsystem is dead, not merely untested — its tables do not exist, the service throws, an Observer
swallows it*. The production dump restored on 2026-10-24 shows all 15 tables present with live data
and the service running. What survives of the claim is that **no migration creates them**, so the
schema is unmanaged rather than absent (`docs/GATE-F-VERIFIED-SCHEMA.md`). The other three stand:
**a green suite is not a correct control**
(twice this phase a guard passed its own tests while an independent read found a live bypass);
**the per-table tenancy ratchet is silent for 28 of 190 raw query sites**; and **`$casts` is not the
encrypted-column inventory**.

---

## 6. Attestation

The checklist in `.github/SECURITY-DIFF-REVIEW.md` is worked for `bfb921d2..1bff9dc7` **in the
focused form the owner directed**: cumulatively verified at the head for the gate, vendor-boundary,
dependency-set and outcome-set properties (§2), and reused per commit from the evidence already
recorded in each message (§3), with the single gap named (§3) and the boundary of what was *not*
re-examined stated (§4).

**Any commit pushed after `1bff9dc7` is outside this attestation until a section covers it.**

SECURITY-DIFF-REVIEW: completed (focused, per owner direction of 2026-10-24)

---

# §22 — `1bff9dc7..` the GATE F verification head

§21 ends by saying *"any commit pushed after `1bff9dc7` is outside this attestation until a section
covers it."* This covers it. The range is small and its character is what makes a focused
attestation sufficient.

## 1. The range, keyed on files touched

**19 files, and not one of them is production code.**

| area | files |
|---|---|
| `app/` `config/` `routes/` `resources/` or a **discoverable** migration | **0** |
| parked drafts under `database/migrations/_gate_f_pending/` — which Laravel's non-recursive glob does not find, and which carry a `.php.draft` extension | 13 |
| documentation, including `CLAUDE.md` | 5 |
| a read-only verification script under `tests/Support/Security/bin/` | 1 |

So the branch's runtime behaviour is **unchanged** across this range. That is the load-bearing fact,
and it is measurable rather than argued: `guard-gate-f-containment.sh` scanned 236 discoverable
migration files across both trees and reports **0 of the 6 money tables created by any discoverable
migration, with all 6 still holding a parked, non-runnable draft.**

## 2. Gates run, with their output

All run locally on this tree at this head.

| gate | result |
|---|---|
| `guard-gate-f-containment.sh` | **exit 0** — containment intact, 9 drafts found, 0 discoverable creators |
| `guard-no-workdo-changes.sh origin/main` | **exit 0** — no change under `packages/workdo/` outside `LynomiaChat` |
| `scan-secrets.sh origin/main` | **exit 0** — no credential-shaped literal added across 457 changed files |
| `guard-no-env-files.sh` | **exit 0** — no live `.env` in checkout or index |
| `guard-test-isolation.sh` | **exit 0** — `phpunit.xml` still pins the suite to sqlite `:memory:` |
| `guard-no-new-root-uploads.sh origin/main` | **exit 0** |
| focused suites on their designed engine | **155 passed, 7 skipped, 0 failures** (`CodStateMachineTest`, `CodCashCollectionTest`, `FinancialSurfaceCoverageTest`, `KhaltiAmountBindingTest`, `UnverifiedGatewayStaysPendingTest`, `GatewayReturnTrustBoundaryTest`, `PendingPaymentGatewayCensusTest`) |

**`guard-test-isolation.sh` passing matters here specifically.** This work needed MySQL evidence, and
the obvious route was to point the suite at MySQL. `tests/bootstrap.php` **refuses** that, because
`RefreshDatabase` runs `migrate:fresh` and would drop every table in the target database. **The guard
was not weakened, bypassed or edited.** A separate override config was written outside the repository,
the bootstrap refused it exactly as designed, the config was deleted, and the MySQL evidence was
taken the way this project already does it — through `tests/Support/Security/bin/` scripts and direct
read-only queries against a restored copy.

## 3. What this range establishes, and what it retracts

It **retracts three claims** this repository asserted and the restored production dump refuted — most
importantly "the financial subsystem is dead", which appears in §21 §5 above as one of the four
load-bearing limitations. That claim is corrected in place rather than deleted, here and in
`PHASE-01-CLOSURE-EXECUTION.md`, `HANDOFF-CURRENT.md` §12d, `CLAUDE.md` and
`_gate_f_pending/README.md`. The authoritative account is `docs/GATE-F-VERIFIED-SCHEMA.md`.

**Nothing was deployed, merged or migrated.** `main` was not modified and was fetched but never
merged. No migration was run against any server. No live data was altered. The three throwaway
databases used for the verification were dropped and the dump destroyed. PR #3 remains a **Draft
titled ⛔ DO NOT MERGE**.

**Any commit pushed after this head is outside this attestation until a section covers it.**

SECURITY-DIFF-REVIEW: completed (focused — this range contains no production code)

---

# §23 — `4a6aaf95..52e90923`, the coupon fix and the closeout

§22 ends by saying *"any commit pushed after this head is outside this attestation until a section
covers it."* **This range contains production code, so unlike §22 it is not a focused attestation.**
`.github/SECURITY-DIFF-REVIEW.md`'s eight questions are worked below, in order.

This section's own commit is documentation-only and sits on top of `52e90923`.

## 0. The range

**21 files, 3,380 insertions, 195 deletions. Three are production code:**

| area | files |
|---|---|
| `app/` | **3** — `Http/Controllers/Api/ApiController.php` (+384/−…), `Models/Cart.php` (22), `Models/Coupon.php` (+72) |
| `tests/` | 9 — 5 new, 4 modified |
| documentation, the ledger, the ledger tool | 9 |
| `routes/**` · `**/Middleware/**` · `**/Policies/**` · `**/config/**` · `**/Providers/**` · `database/migrations/**` · `.github/**` · `packages/workdo/**` | **0** |

So the `sensitive-diff-review` job's own path list does not flag this range. **It is attested anyway**,
because the diff changes **what a shopper is charged** and runs on a handler with no principal check —
which is the character the checklist exists for, whatever the globs say.

## 1. What does this diff REMOVE or NARROW?

Ordered by what a reader would miss. Three removals **narrow** the surface and one **widens** it,
and the widening is the one that broke an existing control (§3).

**NARROWED — the posted money keys are no longer read for any money purpose in `apply_coupon`.**
`$request->final_sub_total` and `$request->sub_total` previously supplied the discount base, both
percentage bases, the shipping-cost comparison and five `original_price` display values. All now read
`couponSpendBaseline()`, a server recomputation from `cart_list` / `cart_list_cookie`. **This removed a
live unauthorized discount of unbounded size** — measured, 100,000.00 off a 200.00 basket for a 10%
coupon with `final_sub_total=1000000` posted.

**NARROWED — the per-user cap stops trusting a posted e-mail.** `coupon_limit_user` compared the
signed-in customer's *account* e-mail against prior uses resolved through `order_billing_details.email`,
which is written straight from `billing_info`. `Coupon::usesByCustomer()` now takes the **higher** of
the server-derived `user_coupons.user_id` count and the legacy e-mail count. Strictly stricter.

**NARROWED — the payable is floored.** All four `final_price` branches in `place_order` subtract
`coupon_price` under `max(0, …)`, so a discount larger than the basket cannot produce a negative
payable.

**WIDENED — coupons apply at all, and two gates stopped refusing.** The `coupon_type ==
'fixed product discount'` branch's `else` used to return a minimum-spend message for **every other
type**, so `flat` and `percentage` — the latter being the column DEFAULT — were refused outright; it
now refuses only a genuinely unsupported type. And the raw `where('coupon_limit','>',$uses)` gate read
`0 > 0` for the schema default, so a blank limit meant *never usable*; `Coupon::applyUsageLimit()`
reads 0/NULL as *no limit*, which is this codebase's own idiom for the sibling `coupon_limit_user`.

**Deliberately dropped, stated rather than preserved:** the blanket refusal of `flat` and `percentage`,
and the reading of posted totals. Both were the defect.

## 2. Did any file in this diff already exist and do something else?

All three production files are **modified, not overwritten**. `Coupon.php` gains two static helpers and
changes nothing existing. `Cart.php` changes two call sites. `ApiController.php` changes
`apply_coupon` and `place_order` in place and adds three private helpers above them — which is what
moved the seven observability-inventory rows in `95754130`.

**No path was overwritten.** No previous behaviour was lost except the two dropped deliberately above.

## 3. What existing control assumed the surface you widened was narrow?

**This is the question that earned the review, and the answer is: two controls, both written against
"no coupon ever applies", and one of them broke in my own fix.**

**(a) `Cart::getCouponTotalAmount`'s own `coupon_limit` gate — BROKEN BY THE FIX, then fixed.** The raw
gate exists in **three** places; I changed the two in `ApiController` and missed `Cart.php:1070`.
Before, both coupon arithmetics refused a `coupon_limit = 0` coupon and so **agreed on a refusal**;
after, `apply_coupon` accepted while the cart still refused — and because the free-shipping arm for a
store with no shipping method reports the **cart's** figure, the response became *"Coupon code applied
successfully"* with a discount of **0.00**. The shopper is told it worked and charged full price, which
is criterion 4's own shape through a door the `elseif` does not cover. Reproduced as a failing test
before fixing, routed through the shared helper, and a **tokenizer ratchet** now fails on any raw gate
outside it across all of `app/`. My census was keyed on the two sites I had already read — census-
failure mode 1 from this project's own skill, committed while fixing a census-shaped defect.

**(b) The per-user cap, which a previous package had cleared.** That package recorded *"Not
coupon-limit evasion"* at the `UserCoupon` writer. True **only while every coupon was refused
upstream.** And since `coupon_limit = 0` now correctly means *no total cap*, this cap became the only
backstop — `0` plus `coupon_limit_user = 1` would have been unlimited reuse by one shopper, which is
the configuration a merchant gets by leaving the total limit blank. Closed by (a)'s sibling,
`usesByCustomer()`.

**Named and NOT closed, in both directions:** 11 further `PerUsesCouponCount` sites are not rewired,
and 12 other writers persist `coupon_price` with no established subtraction. A regex flagged 4 of those
as not subtracting and two checks showed **both were false positives** — `PosController` subtracts via
a differently-named local, `CinetPayController` takes an already-discounted figure from `apply_coupon`'s
response — so no source scan can answer it. That is `P1-ORD-02` criterion 1 and it stays open.

## 4. Is any audit, logging or compliance emission removed, skipped, or made conditional?

**No.** One emission is **added** — `Log::warning('Coupon refused: the basket could not be priced')` —
and seven existing lines moved by the new helpers. `docs/security/P01-OBSERVABILITY-INVENTORY.md` is
updated in both directions and `SecurityLogInventoryTest` (5 passed) asserts the document and `app/`
agree each way, so an added line with no stated destination fails the gate. Checking the figure also
found the document's own heading count four short and unasserted; corrected in `6d0524e7`.

## 5. Does any failure become silent?

Four answers, because the honest one is not a flat "no".

**A money gate that cannot be evaluated is NOT treated as satisfied.** `couponSpendBaseline()` returns
`null` rather than throwing, and the caller **refuses the coupon** on `null`, with the warning above.
Loud, not silent.

**`warnIfInconsistent()` is warn-only by design, and that is a recorded decision rather than an
oversight.** A stored total that does not reconcile is **logged**, and the order still saves. The four
branches do not share a base — two read `$final_price`, two read `$data['shipping_total_price']`, which
already bundles delivery — so a rejecting assertion would refuse legitimate orders on the shipping-on
paths, which this container cannot drive. Promoting it to `assertConsistent()` turns a logged mismatch
into a **refused order**: a release decision, stated as one.

**The reconciler's log is therefore not quiet today, and that is expected.** A 200.00 basket at 10% tax
stores `product_price 242, tax_price 0, final_price 220`. The **charge is correct**; the components
cannot produce it. Pre-existing, measured, pinned as a known-bad test, not fixed.

**One residual IS silent and is declared rather than closed:** if one of the 12 unverified
`coupon_price` writers does not subtract, that shopper is overcharged with a discount printed beside
it, and nothing logs. It is the same gap as §3's last paragraph, and it is why `P1-ORD-01` is `PARTIAL`
rather than `DONE`.

## 6. Is method-resolution order involved?

**N/A.** PHP, and nothing in the range touches a trait, an interface, a service provider or a
middleware group. `Coupon::applyUsageLimit()` and `Coupon::usesByCustomer()` are static helpers called
explicitly at each site; neither overrides anything.

## 7. Was the change verified by removing it?

**Yes, at four levels, and one survival is reported rather than hidden.**

| removal set | result |
|---|---|
| the coupon fix | **7 mutants, 6 bite.** M1 limit 0 no longer unlimited · M2 the `>uses` limb dropped · M3 the type branch refuses again (5 fail) · M4 `couponSpendRefusal` always passes (3 fail) · M5 the percentage base reverts to the posted subtotal · M7 the two messages swapped |
| **M6 SURVIVED on the first attempt** | The tool mutated only the **first** of four `final_price` assignments — the guest arm — and that arm is **unreachable, not untested**: `place_order` 401s through `resolveStoreCustomer()` ~450 lines earlier. Probed with a sentinel `99999`: all 17 tests still passed, which is the evidence for *unreachable*. Mutating all four together fails 2 tests. Reported, not claimed as a bite. |
| the review fixes | the `Cart` gate reverted → 2 fail · the per-user count reverted → 1 fails · a **comment-only** mention of the raw gate does **not** satisfy the ratchet |
| `P1-INV-05` AC3 (#31) | **4 mutants, all bite.** M1 restore gives back `$quantity + 1` → tests 1 and 3 · M2 `restoreVariantStock` becomes a no-op → test 2 · M3 `linesAreReturnable` stops requiring qty → test 4 on the 500 · M4 = M3 plus tolerant `qty ?? 0` reads → test 4 on `final_price`. **M3 is itself a correction**: I recorded the unguarded shape as a silent half-return; it is an HTTP **500**, because PHP raises on the undefined `qty` key before `(int)` can yield 0 — which is why M4 exists at all. |
| the reconciler census | removing `place_order`'s call fails the covered-writers assertion; **adding a comment-only mention of `warnIfInconsistent` to an uncovered writer does not count as coverage** — the control that distinguishes this census from the shell `grep` it replaced, which reported 14 writers against the tokenizer's 16 |
| GATE F reconciliation | a **clean synthetic store reports 0 items and exit 0** (the negative control, without which "it found three" could be hard-coded), and a tokenizer test asserts the class contains **no INSERT, UPDATE or DELETE** |

**One test of mine could not fail for its stated reason, and the review found it.** The free-shipping
percentage test claimed to cover the second copy of that arithmetic; reverting that copy's base fails
**nothing**, because the copy sits behind `if ($shipping_Methods != null)` and `$shipping_Methods` comes
from `Session`, which a `postJson` leaves empty. Renamed to what it measures, with the uncovered lines
named.

## 8. Does the merge-base regression gate report zero new failures?

**Read back from the API, not inferred — see the CI line in `PHASE-01-CLOSURE-EXECUTION.md` →
`CLOSEOUT` §6.** Locally on this tree: Unit **351 passed** (1,837 assertions), Contract **26 passed**
(83 assertions), and the Commerce + Security blast radius **4,478 passed / 11 skipped / 0 undeclared
failures**. All six CI guards invoked the way `ci.yml` invokes them, **exit 0** each.

## 9. Not claimed

That the other 12 `coupon_price` writers subtract — no claim in either direction. That the 11 further
`PerUsesCouponCount` sites enforce the cap. That the taxed-order component defect is fixed. That the
charged-amount change is accepted: **it changes what shoppers pay**, and that was always
`P1-ORD-01`'s stated condition on the owner.

**Nothing was deployed, merged or migrated.** `main` was not modified. No migration was run against any
server. No live data was altered. PR #3 remains a **Draft titled ⛔ DO NOT MERGE**.

**Any commit pushed after this head is outside this attestation until a section covers it.**

SECURITY-DIFF-REVIEW: completed
