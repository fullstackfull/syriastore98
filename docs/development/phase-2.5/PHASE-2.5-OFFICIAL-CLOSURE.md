# Phase 2.5 — Official Closure

**Date:** 2026-11-11 · **Laravel branch:** `claude/vibrant-bell-12tquy` ·
**Flutter branch:** `claude/vibrant-bell-12tquy` (`StoreSyria_app98`)

---

## 0. The four states, and which actually hold

The directive asks these to be distinguished and not claimed until their requirements hold. They
are, and they do not all hold.

| state | verdict | why |
|---|---|---|
| **ENGINEERING COMPLETE** | **YES** | Every executable item in the directive is done and evidenced. The matrix is frozen and reconciled, the security work is closed or explicitly reported, the dependency work is integrated and re-measured on the integrated tree, and both repositories' quality gates were run to completion rather than described. |
| **OWNER ACCEPTED** | **NO** | The 24 PREMIUM VERIFIED surfaces are *prepared for* visual approval. No design has been accepted and none is signed on the owner's behalf. Several dispositions in §4 are explicitly owner decisions. |
| **MERGED TO MAIN** | **NO** | Both Phase 2.5 pull requests are **drafts**, which cannot merge. §6 states exactly what blocks each and what would unblock it. PR #133 **is** merged — into the Phase 2.5 branch, which is where the directive placed it. |
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

**One thing the owner must know.** The deny rules committed earlier in `.htaccess` *would* close that
channel **on the first deploy** of that file — a change the current instruction does not want. They
are dormant and have never been deployed. They were **kept rather than deleted**, because deleting a
written control would be suppressing a finding, which the same instruction also forbids; and a note
at the block tells a deployer to remove it before deploying if the deferral is still in force.
**This is an owner decision, not a deployer's.**

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
| 6 | **The `.htaccess` deny rules**: keep them dormant, or remove them before any deploy (§7) |
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
