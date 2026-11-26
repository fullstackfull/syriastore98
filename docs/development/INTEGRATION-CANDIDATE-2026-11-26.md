# Integration candidate — 2026-11-26

**What this document is.** The merge-readiness package for one integrated tree. It is not a
completion claim for any phase, and it is not a statement that this is ready for production. Those
are different questions with different evidence, and §4.1 of `CLAUDE.md` requires them kept apart.

**The candidate.** `int/checkpoint` at **`056d31125`**, mirrored at `backup/int-checkpoint`.
**138 commits** and **325 files** ahead of `feature/phase-06-saas-super-admin`
(+47,671 / −2,291). Fifteen Laravel branches and three Flutter tips are merged.
**Nothing has been pushed to a development branch, nothing merged to `main`, and no pull request
has been touched.** The stacked chain `main → #138 → #139 → #140 → #141` is as it was.

---

## 1. Gate evidence

Measured on `056d31125`, on a clean tree, with **nothing else running in that worktree** — a
condition that had to be re-established, see §4.

| gate | result |
|---|---|
| `php -l`, every tracked PHP file | clean |
| **Unit** (blocking) | **6,842 passed**, 1 skipped, 0 failed · 61,481 assertions · 132s |
| **Contract** (blocking, the Flutter API contract) | **58 passed** · 242 assertions |
| Cross-repo API contract | **UNRESOLVED 0** — every endpoint the shipped app calls resolves |
| **Feature** | **13,620 passed**, 1 skipped, **0 failed** · 89,285 assertions · 1,945s |
| **Known-outcome set** (blocking) | **OK — matches the manifest exactly.** 13,621 cases, 0 failures against 0 declared, 1 skip against 1 declared, nothing undeclared |
| composer audit | 37 advisories ≤ ceiling 38 |
| MySQL/MariaDB roster | **71 passed, 0 failed**, 1 skipped — *"every MySQL verification script on disk is accounted for"* |
| `flutter analyze` | **248 issues, 0 errors, 0 warnings** — exactly the `INFO_CEILING: '248'` ratchet |
| `flutter test` | **2,189 passed**, all tests |

**What the known-outcome gate means, in its own words:** *"This is NOT a statement that the suite
is green. It is a statement that the suite is failing and skipping exactly the tests the project
has recorded."* The declared failure set is empty and the one declared skip is the accepted one.

---

## 2. What integrating found that no per-branch run could

This is the argument for the checkpoint, and each item is a defect or a blind spot that every
individual branch's own suite reported clean.

**A live SQL injection fix existed on exactly one branch of fifteen.** GAP-338's
`whereRaw('ROUND(average_rating) = ?', [(float) $request->rating])` was on `ws/p06-ws1-apigates`
and nowhere else — including **not** on `feature/phase-06-saas-super-admin`, the head of PR #141,
which still carried the concatenated form. The gap register already recorded GAP-338 as CLOSED,
which was true of a branch and not of the shipping tree. Nothing would have caught it: the test
proving the fix lives on the same branch as the fix, so dropping one drops both and the tree stays
green. `assert_security_fixes_survived_the_merge()` now greps for each fix's presence **and** its
vulnerable form's absence after every merge.

**The checkpoint script's branch list had silently gone stale.** `ws13` and `ws14` were created
after it was written and were absent from it. A checkpoint that omits a branch does not fail — it
reports a clean merge of everything it happened to know about. It now refuses unless every branch
ahead of the base is named or explicitly skipped with a reason.

**A fix on one branch and its declaration on another.** `ThemeViewsDoNotShadowAdminViewsTest`
declared that the stylique theme shadows an admin layout; `ec7fc5d12` had moved that layout out of
the panel's namespace. The fixing branch had no declaration to contradict and the declaring branch
had no fix, so they met for the first time in the merged tree.

**Six census ratchets whose figures only disagreed once merged** — raw-query entry points
(229 → 232 across two branches' additions), the per-table map, the analytics tenancy census
(three figures, all moved by one removed read), the order-document count, the operator
`btn-primary` declaration, and a merchant order-action census that the route's own branch had no
reason to read.

**The MySQL roster refused the checkpoint** over `verify-money-column-precision-on-mysql`, which
arrived with one branch while nothing invoked it — and which `MetricCaveat`'s docblock cites by
name for the 18 checks behind its FLOAT_PRECISION refutation. *"A script nobody runs is not
evidence."*

**Seven stale document figures across four documents**, every one named by a gate rather than
found by reading.

---

## 3. Requirement reconciliation (§4.1)

**Four states, kept separate.** Engineering complete · original-requirement coverage · human
acceptance · production readiness. This document speaks to the first two only.

- Requirement traceability matrix: **374 known requirements, 182 VERIFIED COMPLETE, 192 not.**
- Gap register: **344 rows.**
- **Four requirements that had been tracked nowhere in the matrix now have sections** —
  `INV-006` (pre-order), `PRD-002` (attributes and templates by business type), `PRD-005` (bundles)
  and `ORD-008` (picking, packing, barcodes, labels, invoices). All four are PARTIALLY
  IMPLEMENTED, and **three of the four register rows they came from were wrong**, two of them
  claiming absence where the code existed.
- `GAP-339` closed; `GAP-274` partially closed with its remaining half named rather than deferred;
  `GAP-342`, `GAP-343` and `GAP-344` raised.

**No phase is claimed complete.** No phase after 02 is authorised to merge or release, and this
document does not ask for that.

---

## 4. A compromised run, discarded rather than reported

The first attempt at these gates was thrown away. The session ran `php artisan test` in
`/home/user/wt/int` repeatedly while the gates' own Feature suite was running **in that same
worktree** — the `Storage::fake()` collision `CLAUDE.md` §3 records, and the one this session had
warned three workstreams about an hour earlier.

It is recorded here because the quiet direction is what makes it unsalvageable rather than merely
noisy: a colliding run's *"nothing was left on disk"* assertion passes **because the other process
deleted the file the uploader correctly stored**, so a compromised run can come back green. The
figures in §1 are from a run with a clean tree and no concurrent process. CLAUDE.md §3 now says
the restriction is two `php artisan test` **processes** in one worktree — not two suites — and
that a backgrounded gate is one of them.

---

## 5. Open, and the owner's to decide

Nothing in this list was decided by engineering. `docs/development/OWNER-DECISION-PACKAGE.md`
carries **J14–J26**; the ones that block work:

| | |
|---|---|
| **J17** | a flash sale can make a product free |
| **J18** | the discount-stacking rule — blocks three repairs |
| **J22** | 39 ungated landing-page editor routes, safe today only because they crash |
| **J24** | the FLOAT_PRECISION caveat's wording: merchant-facing on eleven surfaces, and its Arabic is content |
| **J25** | a competitor reads your unit cost from your public product slug |
| **J26** | `orders.return_status_message` — written, read, permanently null, and no migration creates its column |

**Outside the J series**, each named in its matrix row with the reason it was not invented:
whether enabling a pre-order should imply permitting a backorder; the business-type taxonomy for
product templates (the plan names «نوع النشاط» once and zero business types anywhere); flexible
bundles and `PRICE-003`; and the picked/packed fulfilment stage, which changes a column the
shipped Flutter app reads.

---

## 6. Owed by a human, not by engineering

- **The Flutter visual baseline review.** 80 images across 20 surfaces × light/dark × LTR/RTL were
  re-exported from this candidate. **Nothing is approved and nothing is committed** — they are
  gitignored, so accepting one takes a deliberate `git add -f`. `flutter test` went 2,109 → 2,189
  with them present, which is the determinism proof. A pre-screen found the known MaterialIcons
  defect fixed; **contrast was deliberately not judged** from a PNG.
- **Incident #120 / P1-SEC-40.** Owner-deferred, live, and not closable from this repository.
- **The Phase 01 closure gates** and the **#137 post-Phase-06 audit**.

---

## 7. What is not claimed

Green gates are evidence, not acceptance. This candidate has not been through CI, has not been
reviewed by a person, and is not asserted to be production-ready. Engineering completion and
production release readiness remain separate, and the second is not claimed here at all.
