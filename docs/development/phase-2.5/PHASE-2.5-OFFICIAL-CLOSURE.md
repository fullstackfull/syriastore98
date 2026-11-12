# Phase 2.5 — Official Closure

**Date:** 2026-11-11 · **Amended 2026-11-11** for the final-acceptance directive (§12) ·
**Laravel branch:** `claude/vibrant-bell-12tquy` ·
**Flutter branch:** `claude/vibrant-bell-12tquy` (`StoreSyria_app98`)

---

## 0. The four states, and which actually hold

The directive asks these to be distinguished and not claimed until their requirements hold. They
are, and they do not all hold. **Re-verified against GitHub and a full gate run on 2026-11-11
after the final-acceptance directive; every verdict below is unchanged.** §12 is the item-by-item
record of that directive.

| state | verdict | why |
|---|---|---|
| **ENGINEERING COMPLETE** | **YES** | Every executable item in the directive is done and evidenced. The matrix is frozen and reconciled, the security work is closed or explicitly reported, the dependency work is integrated and re-measured on the integrated tree, and both repositories' quality gates were run to completion rather than described. |
| **OWNER ACCEPTED** | **NO** | The 24 PREMIUM VERIFIED surfaces are *prepared for* visual approval, and are now **viewable in one page** — `PHASE-2.5-VISUAL-ACCEPTANCE-VIEWER.html`, all 96 captures, generated from the package so it cannot drift. No design has been accepted and none is signed on the owner's behalf. The §4 dispositions are **no longer owner decisions pending** — all ten were settled by the final-acceptance directive (§12.3) — but the visual acceptance itself is still outstanding. |
| **MERGED TO MAIN** | **NO** | Both Phase 2.5 pull requests are **drafts**, which cannot merge, and **both have zero reviews of any kind** — re-read from the GitHub API on 2026-11-11. The security acknowledgement the directive requires a human to supply is **not in #134's description**, and was not added. §6 and §12.6–12.7 state exactly what blocks each. PR #133 **is** merged — into the Phase 2.5 branch, which is where the directive placed it. |
| **PRODUCTION RELEASE READY** | **NO** | An unresolved security exposure is live on the test host by explicit owner deferral. That is a deferral, not a remediation, and release readiness cannot be claimed over it. The Phase 01 release gates are also outstanding and untouched by this work. |

---

## 1. Engineering completion statement

Phase 2.5 engineering is **complete**. Against the directive section by section:

| § | asked for | state |
|---|---|---|
| 2 | start from existing work; verify ancestry and PR status; do not recreate | done. #130/#132 are **contained in** #134, and #7/#8 in #9, so the older PRs are superseded rather than duplicated |
| 3 | preserve every repaired vulnerability; resolve #136 | done. 20-point preservation check passes; #136 fixed, reviewed and evidenced |
| 4 | integrate the Composer updates; revalidate | done. **66 → 52** re-measured on the integrated tree |
| 5 | complete acceptance; visual package; 10 blocked dispositions | done. Matrix frozen; the visual package carries a measured second cut; Group A's blocker refuted with executed evidence |
| 6 | final gates on the actual integrated tree, both repositories | done. §5 |
| 7 | review and finalise the PRs | done to the point branch protection allows. §6 |
| 8 | this document | this document |
| 9 | update the Phase 03 planning PR | done. §8 |

**Two defects were found *by doing the work* rather than by reading about it**, and both are the
kind a documentation pass would have missed:

- A **first-party Phase 2.5 component** (`x-ss.bar-chart`) fataled on a scalar series, taking a
  whole page down. SQLite's unrelated 500 had hidden it completely.
- The **#136 fix itself emptied an existing test**, which still reported 30 passing assertions while
  pinning nothing. Independent review caught it; the suite could not.

---

## 2. The 295-row matrix, reconciled

**Unchanged and authoritative: 225 / 24 / 28 / 8 / 10 / 0.**

| state | count | % |
|---|---:|---:|
| REDESIGNED AND VERIFIED | 225 | 76.3 |
| PREMIUM VERIFIED | 24 | 8.1 |
| OUT OF SCOPE | 28 | 9.5 |
| DUPLICATE | 8 | 2.7 |
| BLOCKED | 10 | 3.4 |
| PENDING | **0** | 0.0 |
| **TOTAL** | **295** | 100 |

`build-coverage-matrix.py --check` reports this set on the integrated tree. **No row was promoted,
demoted or deleted.** Group A's four BLOCKED rows now render on MySQL (§4) and were deliberately
*not* promoted: their four-combination captures do not exist, and the directive declares these
counts authoritative.

**Evidence integrity, all three gates green:**

| gate | result |
|---|---|
| `build-coverage-matrix.py --check` | 295 surfaces, the set above |
| `check-distinct-surface-captures.py` | green — no two captures claiming different surfaces share content |
| `detect-refusal-toast-captures.py` | green — **932 captures** checked, none photographs a permission refusal |

---

## 3. Premium visual acceptance — status: **PREPARED, NOT ACCEPTED**

Package: `PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md`.

| | measured |
|---|---|
| surfaces | **24** — the matrix and the package name the identical 24, checked row by row |
| capture files | **96 of 96** present, every one non-empty with a real PNG magic number |
| `before/` captures | **none exist for any of the 24**, in any of the five backing folders |

**The distinction the directive asked for** — actual visual changes versus screens that were
already premium — is **13 CHANGED / 11 ALREADY-PREMIUM**, added as §3.1 of the package.

It **disagrees with the package's original grouping on seven rows**, and that disagreement is
reported rather than resolved: §3 groups by the audit verdict recorded at discovery, §3.1 by whether
a change was actually made, and the two were derived from different sources. Three rows judged below
standard appear to have been captured without a change; three judged already-premium appear to have
been changed anyway.

**No design is accepted.** The owner's explicit decision is required and is not signed here.

---

## 4. The ten blocked surfaces — every disposition

Full reasoning: `PHASE-2.5-BLOCKED-DISPOSITIONS.md`. They are **four root causes, not ten
problems**.

### Group A — four report bodies. **Blocker REFUTED with executed evidence.**

The directive asked to prefer an isolated MySQL environment over assuming SQLite proves production
behaviour. Doing that refuted the blocker and found a defect behind it.

**Executed in this container:** MariaDB 10.11.14 installed from apt; `artisan migrate` → 132
tables; `database/seed-p2dev.php` seeded cleanly into MySQL (32 products, 7 orders, 2 stores); a dev
server pointed at it and the four routes driven over HTTP as the authenticated fixture merchant.

| surface | SQLite | MySQL |
|---|---|---|
| `GET reports-chart` | 500 | **200**, 3,060 b |
| `GET order-reports-chart` | 500 | **200**, 8,689 b |
| `GET order-reports-chart-data` | 500 | **200**, 7,099 b — *after a fix* |
| `GET top-all-reports` | 500 | **200**, 88,461 b |

**The fourth had a second defect its matrix row never mentioned.** It 500'd on MySQL too, with
`count(): Argument #1 ($value) must be of type Countable|array, int given` in
`resources/views/components/ss/bar-chart.blade.php` — `ReportController::order_reports_chart_data()`
initialises `$PurchasedItemTotal = 0` and passes the scalar as a series. The component guarded
`$values2` with `is_array()` and not `$labels` or `$values`; the three are now uniform. Pinned by
`tests/Unit/Ui/BarChartScalarSeriesTest.php` (20 cases, reverting fails them).

Evidence: `evidence/mysql-reports-2026-11-11/_report_bodies__MEASUREMENT.json`.
**Disposition: the engineering blocker is resolved. Capture or accept — owner's call.**

### Group B — Shopify (5 screens) and WooCommerce (4 screens)

Both need third-party sandbox credentials no engineering effort can supply. WooCommerce additionally
**has no pixels of its own** — both refusal arms end in `redirect()->back()`, so a screenshot of it
is meaningless by construction; its evidence is deliberately a measurement.
**Disposition: reclassify OUT OF SCOPE with the reason recorded, or provision sandboxes.**

### Group C — invoice PDF viewer

`PdfPreview` rasterises through the printing plugin; `flutter_test` raises `MissingPluginException`
for `rasterPdf`. Behaviour is pinned by tests, and a **real defect was found and fixed** while
investigating — the viewer opened at US Letter and is now pinned to A4.
**Disposition: accept the current evidence. A device capture is optional.**

### Group D — three product decisions

| surface | finding | disposition |
|---|---|---|
| Gateway editors (COD/Stripe/Bank) | **815 lines, 0 live** — all six files 100% commented out, no reason recorded | leave inactive; record why in the code. Reviving exposes financial configuration, which Priority 2 reserves |
| Edit customer (web) | routes deliberately unregistered, but a **complete backend ships and Flutter already uses it** — a merchant can edit a customer from their phone and not their desk | schedule in Phase 04; it is new web functionality, unauthorised in 2.5 |
| Wishlist CRUD | four routes resolve, **none renders a byte**; two are write endpoints | unregister the routes regardless; the feature question is the owner's |

---

## 5. Gates — run on the actual final integrated tree

### 5.1 Laravel (`syriastore98`, `claude/vibrant-bell-12tquy`)

| gate | result |
|---|---|
| **Unit** (blocking) | **1,639 passed**, 12,222 assertions |
| **Contract** (the Flutter API contract, blocking) | **38 passed**, 149 assertions |
| **Feature** | **8,439 testcases — 8,436 passed**, 2 failed, 1 skipped, 43,500 assertions |
| **known-outcome gate** | **OK** — "the observed outcome set matches the manifest exactly: 2 declared failures, 1 declared skip, 0 errors, and nothing undeclared" |
| **Feature / Security** | **5,152 passed, 1 declared skip, 0 failures**, 21,835 assertions |
| **Feature / Tenancy** | **450 passed**, 1,676 assertions |
| coverage matrix `--check` | green — 295, 225/24/28/8/10/0 |
| distinct-capture integrity | green |
| refusal-toast detector | green — 932 captures |
| **composer audit** | **52 advisories / 20 packages** against `AUDIT_CEILING: '58'` — **passes with 6 to spare** |

The gate's own closing words are worth repeating, because they are the difference between this
document and a claim of readiness:

> This is NOT a statement that the suite is green. It is a statement that the suite is failing and
> skipping exactly the tests the project has recorded, for the reasons it has recorded.

**`.github/known-test-outcomes.json` was not used to hide anything.** It differs from `main` by two
lines, and that change only re-measured a stale `file:line` *inside* an already-declared entry
(367 → 610/612). **No entry was added or removed; the declared set is identical to `main`.**

### 5.2 Flutter (`StoreSyria_app98`, `claude/vibrant-bell-12tquy`)

Run against Flutter **3.47.7** at `/opt/flutter` — an exact match for `FLUTTER_VERSION` in its
`ci.yml`, so these are the analyzer CI uses.

| gate | before | after |
|---|---|---|
| `flutter test` | 2,001 passed | **2,001 passed**, 0 failed, 0 skipped |
| `flutter analyze` — errors | 0 | **0** |
| `flutter analyze` — warnings | **13** ❌ | **0** ✅ |
| informational issues vs `INFO_CEILING: 248` | **253** ❌ | **247** ✅ |
| `flutter pub get --enforce-lockfile` | — | passes; `pubspec.lock` untouched |
| `check-signing-material.sh` | — | passes |
| `check-no-new-suppressions.sh` | — | passes, at its ceiling of 43 |
| cross-repo API contract | — | **green in both directions, zero drift** |

**Two Flutter gates were RED on this branch and both were this branch's own debt** — 13 analyzer
warnings and a breached info ratchet, all from the P2.5 capture and golden work, **none in `lib/`**.
Fixed: 8 unused imports, 2 unused pattern bindings, 3 no-op `!` operators, and six behaviour-neutral
lints to land at 247 rather than exactly on the ceiling.

**A contract claim was discharged:** regenerating the manifest from the live Flutter checkout
produced a **byte-identical** file — zero drift, verified in both directions. That closes
`NEW-CONTRACT-STALE-02` in the stabilization register, which was filed "BLOCKED — EXTERNAL EVIDENCE
REQUIRED" precisely because it needed both repositories present.

**Not verified, stated plainly:** none of this ran on a GitHub `ubuntu-24.04` runner, and no release
APK was built or signature-verified — that needs the keystore, which no runner has.

---

## 6. Merge status

### What merged

**PR #133 (Composer) — MERGED**, into `claude/vibrant-bell-12tquy`, which is where the directive
placed it ("into **the Phase 2.5 branch**, not `main`"). GitHub closed it automatically once its
head commit was contained in the base.

### What did not, and exactly why

**First, the fact that changes how the rest of this section reads: GitHub enforces nothing.**

| repo | `main` protection |
|---|---|
| `syriastore98` | `protected: false`, `enforcement_level: "off"`, `required_status_checks.contexts: []`, rulesets `[]` |
| `StoreSyria_app98` | identical |

*(The classic protection-detail endpoint returns 403 to this session's token; the branch and
rulesets endpoints are readable and unambiguous, and nothing was guessed past the 403.)*

So **no check is technically required**, and a merge would not have been blocked by GitHub. The
gates below are the project's own conventions — named `REQUIRED` in `ci.yml`, pinned by
`RequiredGateWiringTest`, and recorded in the Phase 01 acceptance criteria. They are honoured here
on their merits rather than because a server would refuse.

| PR | repo | state | checks | what blocks it |
|---|---|---|---|---|
| **#134** | `syriastore98` | **DRAFT**, `unstable` | 10 total · **1 failing** · 4 in progress · **0 reviews** | see below |
| **#9** | `StoreSyria_app98` | **DRAFT**, `clean` | 3 total · 0 failing · 0 pending · **0 reviews** | draft status and acceptance only — nothing technical |
| **#135** | `syriastore98` | **DRAFT**, `unstable` | 9 total · 1 failing (inherited) | planning only; should stay draft until Phase 03 is authorised |

### The one genuinely red check, and why I did not clear it

**`Security-sensitive diff: human checklist`** (`security-attestation.yml`, job `attestation`) is
**failing** on #134. `flag-sensitive-diff.sh` matched security-sensitive paths — which this change
certainly touches — and the PR body does not contain the acknowledgement line. The script requires
the exact whole line `SECURITY-DIFF-REVIEW: completed` (`grep -qxF`; a backticked or quoted mention
does not satisfy it), and the workflow triggers on `edited`, so **editing the PR description would
clear it without a new commit.**

**I did not, and will not.** That check exists precisely so a *human* confirms they have reviewed a
security-sensitive diff. Typing its acknowledgement line on an owner's behalf is forging human
approval, which the directive forbids in terms. It is listed in §10 as an owner action.

### Two CI findings worth escalating, neither caused by this work

1. **#133 merged before its own required gates reported.** At the moment of merge three checks were
   still in progress — both `Tests (REQUIRED …)` jobs and the merge-base regression gate. All three
   have since completed `success`, so the outcome was fine, but the merge did not wait for them. With
   no branch protection there was nothing to make it wait.
2. **A red `composer audit` step silently skips eleven later steps.** On `main`'s `ci.yml` the audit
   is step 7 of the 18-step `static` job, and a GitHub Actions step defaults to `if: success()` — so
   when it went red on #135, **eleven subsequent steps were SKIPPED**, including nine guards that run
   nowhere else. #135 fails that step only because the advisory *database* grew against `main`'s
   untouched lock. **The remedy already exists on the Phase 2.5 branch**, where the audit is split
   into its own `advisories` job; that is why #134 and #133 have 10 check runs and #135 has 9.
   Merging #134 fixes it for everything afterwards.

### Independent review of the integration

| check | result |
|---|---|
| unresolved merge conflicts | none — the Composer integration was a clean merge, 1 file, 0 conflict markers |
| database dumps or secret files restored | **none.** No `.sql`, `.dump`, `.bak`, `.sqlite` or `.env` is tracked, and no unignored dump-shaped file exists |
| unrelated changes | none — every commit maps to a directive section |
| business rules changed outside approved fixes | none. The #136 tightening leaves the amount rules untouched; the bar-chart fix is a rendering guard |
| unsafe vendor modifications | **none.** `packages/workdo/` is untouched; #136 is enforced from first-party middleware |
| invalid test evidence | one found **and fixed** — the #136 fix had emptied an existing test (§1) |
| Laravel/Flutter contract compatibility | green in both directions, zero drift |

---

## 7. Security — closed, and explicitly outstanding

### Preserved

All 20 previously repaired protections verified present on the integrated tree: cross-tenant user
update/edit/delete, `UserDeletionSafety` cascades, plan-activation replay, product and variant
tenant isolation, financial-record deletion restrictions, bank-transfer / menu / plan authorization,
null-tenant handling, and Laravel/Flutter API authorization compatibility.

### Closed in this round

| id | finding |
|---|---|
| **P1-SEC-54** | the auction bid write — **closed by #136**, and the finding as originally written was incomplete: a registered guard already derived `store_id` from the product row |
| **P1-SEC-60** | a security fix re-staged a test and silently emptied it (30 → 42 assertions once repaired) |
| — | `x-ss.bar-chart` fataled on a scalar series, 500ing a page |

### OPEN — OWNER-DEFERRED — UNRESOLVED SECURITY RISK

> **The exposed Git history and credentials on the public test host are recorded, at the owner's
> direction, as OWNER-DEFERRED — UNRESOLVED SECURITY RISK.** This is a deferral, **not** a
> remediation, and it is not production release approval.

Per the owner's standing instruction, **nothing was done to change it**: no key rotation, no change
to public access, no deployment, no history rewrite, no visibility change. **Verified on 2026-11-11
that the live host is unchanged** — `/.git/HEAD` and `/.git/objects/info/packs` still answer 200.

**One thing the owner must know, and it has been corrected.** The deny rules were committed earlier
*into* `.htaccess`, where they *would* have closed that channel — not on a deploy, which this project
has none of, but on the next `git pull` on the live host, whose `.git/HEAD` reads
`ref: refs/heads/main`. Merging this branch would therefore have armed them with nobody intending it,
which is the opposite of the standing instruction.

They were lifted out on 2026-11-11 into `docs/security/p1-sec-40-apache-deny.conf`, a file no server
reads. Both `/.htaccess` and `public/.htaccess` are now **directive-identical to `origin/main`** —
the diff is comments only — so **merging cannot change public access to anything.** The rules were
**kept rather than deleted**, because deleting a written control would be suppressing a finding,
which the same instruction also forbids; they remain reviewed and pinned by 32 tests, two of which
fail if anyone re-arms a served file. Arming them is **an owner decision, not a deployer's**, and
the conf states what to append and where.

### Still open, reported not fixed

| id | finding |
|---|---|
| **P1-SEC-61** | `bidingForm` is unscoped and `products.slug` is not unique, so a legitimate click can resolve another store's product. **Fails closed** since #136; the remaining cost is a misleading message |
| `#120` / `P1-OWN-09` | the live `APP_KEY` and database credentials — owner-deferred, above |

---

## 8. Phase 03 preparation

PR #135, branch `planning/phase-03-storefront-docs-2026-11-11`. **Planning only; no implementation
started.** §9 of this closure is deliberately not an invitation to begin.

The plan was expanded from a 75-line outline into a measured inventory. The three findings that
change its shape:

1. **A storefront evidence harness is a prerequisite, not a deliverable.** Eight measured gaps —
   chief among them that **the storefront has no dark mode at all** (zero files across all three
   themes) and **no harness can sign in as a customer** (all 112 drive the merchant `/login`).
2. **03-B is a data-model change before it is a UI.** The section taxonomy already exists as 959
   declared fields in `themes/*/theme_json/`, but `theme_customizes` has **no status, version or
   published_at column**, and `landing_page_settings.name` is **UNIQUE**, which structurally forbids
   a draft row. Preview, publish and rollback are net-new.
3. **The documentation centre starts from nothing** — one vendored `/api-docs` page, and **no search
   infrastructure of any kind**, verified four ways.

---

## 9. Exact commits and branch heads

### `fullstackfull/syriastore98` — `claude/vibrant-bell-12tquy`

| commit | what |
|---|---|
| `32f562c4` | P1-SEC-40 containment: dump untracked, deny rules, regression test, the schema-only cascade fixture |
| `69da2799` | two cross-tenant product reads; the live bypass of the financial-record prohibition; refusal-toast detection in CI |
| `fec293b3` | the security closure report |
| `a4c011d9` | the three same-major Composer bumps *(PR #133's head)* |
| `a71345d8` | **merge** of PR #133 into the Phase 2.5 branch |
| `cba47e2b` | the `x-ss.bar-chart` scalar fatal, found by rendering the blocked reports against real MySQL |
| `ec92b33a` | **issue #136** — the auction bid matches the storefront; and the fix had emptied a test |
| *(this commit)* | this closure document |

### `fullstackfull/StoreSyria_app98` — `claude/vibrant-bell-12tquy`

| commit | what |
|---|---|
| `40a7f4b` | dump containment; the Firebase keys recorded as *not* part of the incident |
| `ecb8124` | the two red Flutter analyzer gates, both this branch's own debt |

### State

| | |
|---|---|
| `main` (both repos) | **untouched** — no merge, no push |
| history | **not rewritten**, no force-push anywhere |
| working trees | clean |
| live host | **unchanged**, verified |

---

## 10. Remaining owner approvals

| # | decision |
|---|---|
| 1 | **Visual acceptance** of the 24 PREMIUM VERIFIED surfaces (§3) |
| 2 | **Add `SECURITY-DIFF-REVIEW: completed` to #134's description** — the one red check. It is a human acknowledgement and was deliberately not typed on your behalf |
| 3 | **Convert #134 and #9 from draft, and review them** — zero human reviews exist on either |
| 4 | Group A: capture the four report surfaces, or accept them on the measurement (§4) |
| 5 | Groups B/C/D: five dispositions awaiting a decision (§4) |
| 6 | **The Apache deny rules**: arm them, or leave them dormant in the conf. No longer urgent — they can no longer arm themselves on a merge or a pull (§7) |
| 7 | Whether #136 closes issue #136 — evidence posted, left open deliberately |
| 8 | `P1-SEC-61` — fix the misleading message, or accept it |
| 9 | Authorise Phase 03 implementation (§8) |
| 10 | The Phase 01 release gates, untouched by this work |

---

## 11. Production release-readiness verdict

> **NOT PRODUCTION RELEASE READY.**

Not because engineering is incomplete — it is complete — but because:

1. an **unresolved security exposure is live** on the test host by owner deferral, and a deferral is
   not a remediation;
2. the 24 surfaces are **not owner-accepted**;
3. **nothing is merged to `main`**;
4. the **Phase 01 release gates** remain outstanding and were not in this directive's scope.

Each is recoverable and none is secret. The first is a decision, the second is a review, the third
follows the second, and the fourth predates this phase.

---

## 12. The final-acceptance directive — item by item

The owner's directive of 2026-11-11 (*"PHASE 2.5 FINAL ACCEPTANCE AND MERGE"*) set ten tasks and
re-stated four standing prohibitions. This section records what each produced. **Seven are done,
one is done-and-stays-open by the directive's own condition, and two cannot be done by me at all.**

| # | Task | Outcome |
|---|---|---|
| 1 | Resolve the bounded **P1-SEC-61** misleading message, with regression tests | **DONE** — `88371517`. 26 cases / 189 assertions, eight new, each falsified. Root cause stays open and reported |
| 2 | Close Issue #136 **only after confirming the merged fix** | **CORRECTLY LEFT OPEN.** The fix is on the branch; the branch is not merged. The condition does not hold, so the issue stays open |
| 3 | Finalise the 10 BLOCKED dispositions without falsifying the matrix | **DONE** — all ten disposed, matrix untouched. §12.3 |
| 4 | Present the 24 Premium surfaces for **genuine** owner visual approval | **PRESENTED, NOT APPROVED.** A one-page viewer now exists; no approval is recorded anywhere |
| 5 | Prepare PRs #134 and #9 for final human review | **DONE** — both descriptions rewritten to the measured final state and the reviewer's checklist |
| 6 | Require an **authorised human** to supply the PR checklist acknowledgement | **CANNOT BE DONE BY ME, BY DESIGN.** Not added. §12.6 |
| 7 | After approval and gates, mark ready, merge, verify SHAs | **BLOCKED on 6.** Nothing merged. §12.7 |
| 8 | Keep Phase 03 PR #135 planning-only | **VERIFIED UNCHANGED** — draft, 1 file, 282 additions, no implementation |
| 9 | Ensure merging triggers no deploy and does not activate the deferred `.htaccess` rules | **DONE, and it found a real defect of mine.** `77ecae48`. §12.9 |
| 10 | Update this document with actual states | **DONE** — this section, plus §0 |

### 12.3 The ten blocked surfaces are disposed; the matrix is not touched

The directive settled all four groups in one sentence: *"accepting Group A's MySQL measurements and
Group C's invoice evidence, deferring external sandbox coverage, and scheduling inactive/new
functionality in its appropriate future phase."* That maps one-to-one onto A/C/B/D and matches what
`PHASE-2.5-BLOCKED-DISPOSITIONS.md` had already recommended, so nothing was re-argued.

**Four accepted on measurement, one on existing evidence, two deferred, three scheduled. None
awaiting a decision.**

**The matrix still reads 225 / 24 / 28 / 8 / 10 / 0.** A disposition is a decision about what to do;
it is not a claim that a capture now exists. All ten still have no four-combination visual capture,
which is the only thing `BLOCKED` has ever meant here. Promoting Group A on the strength of an HTTP
200 would be precisely the falsification the instruction forbids — a 200 with a byte count shows the
route executes, not that the screen is right in four combinations. `PHASE-2.5-COVERAGE-MATRIX.md` is
**generated** by `tools/build-coverage-matrix.py`, which has a `--check` mode that fails on drift, so
it could not have been quietly hand-edited in any case.

**One recommendation was deliberately not followed.** Group B's two rows had been recommended for
reclassification to OUT OF SCOPE. The owner said *deferring*, not reclassifying, so they stay
BLOCKED–DEFERRED: reclassifying would have moved two rows between authoritative counts on my own
initiative.

### 12.4 The 24 surfaces are now actually viewable

`PHASE-2.5-VISUAL-ACCEPTANCE-VIEWER.html` — all 96 captures on one page, four combinations per
surface, grouped as the package groups them, each opening full size. It is **generated by parsing
`PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md`**, so it cannot name a surface the package does not, and
every one of the 96 files was verified present and non-empty before it was written. Rendered and
screenshotted in light and dark at 1280px and at 390px: 96/96 images load, no horizontal overflow,
no console errors.

This is a presentation change, not an evidence change. The package was a table of 96 filenames,
which is not something a person can actually look through; asking for "genuine" approval against it
would have been asking for a formality. The page carries, prominently, that no approval is recorded
on it and none can be, and it repeats the two caveats that bear on judging the designs: **no
`before/` capture exists for any of the 24**, so "is this good" is answerable and "is this better"
is not; and the grouping **disagrees with the changed/already-premium split on seven rows**.

### 12.6 The acknowledgement was not added, and that is the point

> *"Require an authorized human to review the security-sensitive diff and personally supply the PR
> checklist acknowledgement. Do not add it automatically."*

Measured on 2026-11-11 from the GitHub API:

| | #134 (Laravel) | #9 (Flutter) |
|---|---|---|
| state | draft | draft |
| reviews, of any kind | **0** | **0** |
| `SECURITY-DIFF-REVIEW: completed` in the body | **absent** | n/a |

It is absent because it was not added. A human with authority has to read the security-sensitive
diff and type it themselves; there is no version of me doing that which is not forging the
acknowledgement the gate exists to collect.

**Nor is there a protection stopping me.** Both repositories were checked: `protected: false`,
`enforcement_level: "off"`, empty rulesets. GitHub would not have refused a merge. The reason
nothing is merged is that the condition is not met, not that the platform blocked it.

### 12.7 Nothing was merged, because item 7's precondition is item 6

Item 7 begins *"After approval and all mandatory gates pass."* The gates pass. The approval does not
exist. So the sequence stops, and both PRs remain drafts at:

- `fullstackfull/syriastore98` #134 — head `88371517`, base `main` @ `53971d4e`
- `fullstackfull/StoreSyria_app98` #9 — head `ecb8124`, base `main` @ `94cdcdb`

`main` is untouched in both repositories.

### 12.9 Merging cannot deploy, and can no longer arm the deferred deny rules

Full evidence: `PHASE-2.5-MERGE-SAFETY.md`.

**No deployment can be triggered.** Three workflows in the Laravel repo, one in Flutter; four
actions between them (`checkout`, `upload-artifact`, `gitleaks`, `setup-php`), none able to ship
files; the three deploy-shaped grep hits in `ci.yml` are prose in comments; no `.cpanel.yml` or any
other auto-deploy manifest on the branch or on `main`; no tracked git hooks; `core.hooksPath` set
nowhere. **One thing I could not check:** repository webhooks — that GitHub API path is blocked for
this session (HTTP 403). It is recorded as unverified rather than cleared, and the owner can settle
it in Settings → Webhooks.

**And this found a real defect in my own earlier work.** The branch had committed the P1-SEC-40 deny
rules *into* `/.htaccess` and `public/.htaccess`. The live document root **is** the repository root
and the deployed tree is a checkout whose `.git/HEAD` reads `ref: refs/heads/main`, so merging would
have armed them on the next `git pull` there — no pipeline, nobody intending it, against the
standing instruction not to change public access. They now live in
`docs/security/p1-sec-40-apache-deny.conf`, which no server reads. **Both served files are
directive-identical to `origin/main`; the entire diff is comments.** The rules were not deleted —
that would suppress a finding — and two pins fail if anyone re-arms either file.

### 12.11 What the directive prohibited, and what was done about it

| prohibition | state |
|---|---|
| secret rotation | **not done.** No key, credential or password was rotated or changed |
| live-host access changes | **not done**, and now provably un-doable by accident (§12.9) |
| production deployment | **not done.** Nothing was deployed; no deploy mechanism exists |
| git history rewriting or force-push | **not done.** Every push was a fast-forward to the feature branch |

**The publicly exposed Git metadata and historical credentials remain OWNER-DEFERRED and
UNRESOLVED.** `/.git/HEAD` and `/.git/objects/info/packs` still answered 200 on 2026-11-11. Nothing
in this round remediates them, and **release readiness is not claimed** — §11 stands unchanged.

### 12.12 One new finding was raised rather than absorbed

**P1-SEC-62.** Independent review of the P1-SEC-61 fix established that the operator diagnostic's
*choice* of log line reproduces the distinction the shared refusal message exists to withhold, and
`storage/logs/laravel.log` is served publicly on the live host. Incremental disclosure today is nil —
the same channel already serves `APP_KEY` and the `DB_*` credentials — but the control is now
**conditional on P1-SEC-40**, and remediating P1-SEC-40 closes it with no further work. Recorded in
`P01-SECURITY-WORK-QUEUE.md` S57 as open, accepted and dependent, rather than left implicit.
