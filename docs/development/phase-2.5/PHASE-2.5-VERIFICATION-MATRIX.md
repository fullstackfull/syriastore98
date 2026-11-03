# Phase 2.5 — screen-by-screen verification matrix

**Every row here was produced by a command whose output is quoted or whose script is committed.**
A screen with no row has not been verified and must not be described as done. Rows are added as
slices land; the matrix is deliberately shorter than the audit, because auditing a screen and
verifying a change to it are different claims.

**Fixtures:** `database/seed-p2dev.php` — a single invented tenant (*Damascus Textiles*, merchant
*Amina Haddad*), 32 products, 7 orders, 4 categories. **No real customer data, in any screenshot
or fixture, anywhere in this phase.**

---

## 1. Verified by driving the running application

| # | screen / surface | route | viewport | lang + dir | theme | role | interaction driven | a11y / contrast finding | result | evidence |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Category list → **Add Category** modal | `GET /category` → `GET /category/create` (ajax modal) | 1440×1000 | **en / ltr** | **dark** | merchant (`admin`, own tenant) | opened the real modal from the list's own button | 13 labels, 6 fields, title, select — **all ≥ 4.5:1**; `.modal-content` confirmed `#ffffff`; server confirmed to have sent `style-dark.css` | **10 checks, 0 failed** | `evidence/p2-5-a-01/modal-dark-ltr-en.png` |
| 2 | the same screen | the same | 1440×1000 | **ar / rtl** | **dark** | the same | the same | identical readings; `dir="rtl"` asserted | **10 checks, 0 failed** | `evidence/p2-5-a-01/modal-dark-rtl-ar.png` |

| 3 | **Merchant dashboard** — the merchant's own card | `GET /dashboard` | **1440, 1280, 1024, 768, 390** | en / ltr | dark | merchant | measured each child's rendered geometry at all five widths | `.user-info` **87px → 332px**, greeting **9 lines → 3**; four other widths **byte-identical** | **5 widths measured, 1 fixed, 4 unchanged** | `evidence/p2-5-b-01/dashboard-before-dark-ltr.png`, `…-after-dark-ltr.png`, `usercard-after-1440.png` |
| 4 | the same screen | the same | 1440 | **ar / rtl** | dark | the same | full-page render with the Arabic locale | stacked layout mirrors correctly; QR and actions centre | **rendered and inspected** | `evidence/p2-5-b-01/dashboard-after-dark-rtl-ar.png` |
| 5 | the same screen | the same | 1440 | en / ltr | **light** | the same | full-page render, light stylesheet asserted | no change from the A-01/A-02 rules (dark-scoped) and B-01 applies in both themes as intended | **rendered and inspected** | `evidence/p2-5-b-01/dashboard-after-light-ltr.png` |
| 6 | **Header greeting** (every page) | `GET /dashboard`, header partial | 1440 | **ar / rtl** and **en / ltr** | dark | merchant | read each child node's **rendered client rect**, not the source string | visual order `["!", "Amina Haddad", "مرحباً،"]` — correct RTL reading; English `["Hi,", "Amina Haddad", "!"]` | **both directions correct** | `evidence/p2-5-a-03/greeting-after-ar.png`, `…-en.png` |

Command, rows 1–2:

```bash
node docs/development/phase-2.5/tools/check-dark-form-surfaces-live.cjs
# 20 checks, 0 failed
```

**The premises are asserted before anything is measured, and they have already earned it.** The
harness fails if the server did not send a dark stylesheet, if `<html>` does not carry
`data-ss-theme="dark"`, if `dir` is not what the run asked for, or if `.modal-content` is not
white. On its first run **all three theme premises failed** — `set-admin-preview-state.php` had
been invoked without the server's `DB_DATABASE`, so it wrote `cust_darklayout` into a *different*
sqlite file, reported success, and every contrast reading underneath was of the **light** theme.
That is the Phase 02 §4 defect ("my own dark-mode screenshots were not dark") reproducing itself
on the first attempt of a new phase, and it was caught in one run because the premise is a check
rather than an assumption.

---

## 2. Verified against the stylesheets, independently of the application

| surface | before | after | threshold |
|---|---|---|---|
| modal `.form-label` | 2.52:1 | **16.06:1** | 4.5 |
| modal bare `<label>` | 2.52:1 | **16.06:1** | 4.5 |
| modal `.ss-label` | 2.52:1 | **16.06:1** | 4.5 |
| modal `<p>` | 3.84:1 | **5.94:1** | 4.5 |
| modal `.form-text` | 4.69:1 | **5.94:1** | 4.5 |
| modal `<option>` | 4.03:1 | **13.63:1** | 4.5 |
| escaped modal (`data-ss-theme="light"`) `<p>` | 3.84:1 | **5.94:1** | 4.5 |
| form control value — modal / escaped / dark card / dark page | 3.71–4.03:1 | **12.55–13.63:1** | 4.5 |
| form control placeholder | 3.04:1 | **5.66:1** | 4.5 |
| **sidebar nav text** *(must not move)* | 5.66:1 | **5.66:1** | 4.5 |
| **DataTables length / filter / info / dropdown labels** *(must not move)* | 6.14:1 | **6.14:1** | 4.5 |
| **breadcrumb link** *(must not move)* | 6.14:1 | **6.14:1** | 4.5 |
| **sidebar search input** *(must not move)* | 5.66:1 | **5.66:1** | 4.5 |
| **dark-card label** *(must not move)* | 5.66:1 | **5.66:1** | 4.5 |
| dark-card `<p>` — **F152, out of slice** | 3.71:1 | 3.71:1 | 4.5 |

```bash
node docs/development/phase-2.5/tools/measure-f126-cascade.cjs f126-cascade-fixture.html ltr
node docs/development/phase-2.5/tools/measure-f126-cascade.cjs f126-cascade-fixture-rtl.html rtl
# before: 26 surfaces measured, 7 below AA
# after : 26 surfaces measured, 1 below AA   (identical in both directions)
```

**The five "must not move" rows are the point of the slice, not a footnote.** The rule being
narrowed was added to fix exactly those surfaces; a contrast fix that repairs the modal and
quietly regresses the sidebar is a worse outcome than the defect.

---

## 3. Verified by the suites

| gate | command | result |
|---|---|---|
| design-system contrast | `python3 docs/design-system/tools/check-contrast.py` | **58 pairs pass (light + dark); auto scope identical to dark across 36 tokens** |
| new ratchet | `php artisan test tests/Unit/Ui/DarkModalSurfaceContrastTest.php` | **11 passed, 58 assertions** |
| `.claude` configuration | `php artisan test tests/Unit/Ci/ClaudeConfigurationTest.php` | **76 passed, 618 assertions** |
| Unit suite | `php artisan test --testsuite=Unit` | **732 passed, 4,608 assertions** (721 before this slice; +11 new) |
| Feature suite | `php artisan test --testsuite=Feature --log-junit … --log-teamcity …` | **7,519 passed, 2 failed, 1 skipped, 0 errors** (37,351 assertions, 2,283s) |
| known-outcome gate | `php .github/scripts/assert-known-test-outcomes.php …` | **PASS** — 7,522 testcases observed; 2 failures observed / 2 declared; 1 skip / 1 declared; 0 errors |

The gate's own words, quoted rather than paraphrased:

```
testcases observed   : 7522  (manifest floor 1000)
failures observed    : 2  (manifest declares 2)
errors observed      : 0  (always expected: 0)
skips observed       : 1  (manifest declares 1)

OK: the observed outcome set matches the manifest exactly -- 2 declared failures,
1 declared skips, 0 errors, and nothing undeclared.

This is NOT a statement that the suite is green. It is a statement that the suite is
failing and skipping exactly the tests the project has recorded, for the reasons it has
recorded.
```

**This matches the integrated-`main` baseline the owner's directive cites — 7,519 passed, 2
declared failures, 1 declared skip, 0 unexpected errors — figure for figure.** The directive said
to verify the current baseline rather than copy those numbers into a new report; verified, and
they are unchanged. **Nothing was added to the manifest**: the gate asserts the set in both
directions, so an added entry would itself have failed it.

---

## 4. What is NOT verified, stated plainly

| | |
|---|---|
| **Flutter — what IS verified** | `P2.5-D-01` is verified by **rendering each widget in the Flutter test harness** and reading `tester.getSize`, plus a behavioural test that taps the extended margin and asserts the callback fired. `flutter analyze` **247 issues / 0 warnings / 0 errors**; `flutter test` **1,373 passed**, run alone after the Laravel Feature suite finished. That is a real render, and it is **not** a device. |
| **Flutter on a device** | **NOT POSSIBLE HERE.** No Android SDK, no release keystore, no emulator, no device — handoff blocker #6, still open. `flutter analyze` runs (**247 issues: 247 info, 0 warnings, 0 errors**, Flutter 3.47.7 / Dart 3.13.7) and `flutter test` can run. Anything requiring a rendered device frame is reported as unverified, not substituted. |
| **every other merchant screen** | The audit that scopes them is still running; §1 covers one screen in four configurations. The A-01/A-02 rules are written against `.modal-content` and `.form-control`, which every host and module form shares, so the *expected* reach is far wider than one screen — **and that is a prediction, not a measurement**, until each screen has its own row here. |
| **light mode** | Both rules are scoped to `html[data-ss-theme="dark"]`, so light mode is unreachable by them by construction. Asserted in the ratchet, not re-measured per screen. |
| **browsers other than Chromium** | Only Chromium is available in this container. The `::-moz-placeholder` rule is written and committed but has **not** been rendered in Firefox. |
