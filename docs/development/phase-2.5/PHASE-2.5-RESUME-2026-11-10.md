# Phase 2.5 — checkpoint, 2026-11-10

**Phase 2.5 is NOT complete.** This is a resumable checkpoint. Both working trees are clean and
both branches are pushed. It supersedes `PHASE-2.5-RESUME-2026-11-09.md` for state; that file's
classification of the PENDING groups is still the map.

---

## 1. Exact state

| | |
|---|---|
| Laravel `syriastore98` | **`8187c83f`** |
| Flutter `StoreSyria_app98` | **`8789619`** |
| Branch (both) | `claude/vibrant-bell-12tquy` |
| Working trees | **clean**, local == remote on both |

### Coverage matrix — 295 surfaces

| state | at 2026-11-09 | now |
|---|---:|---:|
| REDESIGNED AND VERIFIED | 119 | **157** |
| PREMIUM VERIFIED | 24 | **24** |
| PENDING | 141 | **101** |
| BLOCKED | 10 | 10 |
| OUT OF SCOPE | 1 | 3 |

**No PREMIUM VERIFIED promotions.** That count is unchanged on purpose — promotion needs the
owner's visual acceptance.

### Tests at this commit

| | |
|---|---|
| Laravel `--testsuite=Feature` + gate | **8334 passed, 2 declared failures, 1 declared skip, 0 errors.** Gate: *"the observed outcome set matches the manifest exactly … and nothing undeclared."* |
| Laravel `--testsuite=Unit` | **1560 passed** |
| Laravel `--testsuite=Contract` | **32 passed** |
| Flutter `flutter test` | **1843 passed** |
| Flutter `flutter analyze` | **0 errors, 0 warnings** (262 pre-existing info lints) |
| matrix `--check`, distinct-capture guard, refusal detector | all clean |

Nothing was added to `.github/known-test-outcomes.json`.

---

## 2. CI — the two known red checks, unchanged

**Dependency advisories (composer audit)** and **Security-sensitive diff: human checklist** are the
only failing checks on PR #132; the other eight pass. Neither was bypassed, relabelled or worked
around. The human checklist must be signed by a person. PR #8 (Flutter) is green. Both stay draft.

**Not release-ready.**

---

## 3. Groups completed this session

| group | surfaces | captures | findings at close |
|---|---:|---:|---|
| Settings hub AJAX tabs | 21 | 84 | **0** |
| DataTable row-action cells | 11 | 44 | **0** |
| AJAX create/edit modals | 16 | 64 | 0 real (20 premise for 5 uncapturable) |
| Flutter detail/editor screens | 4 | 16 | **0** |
| Parameterised web routes | 2 | 8 | **0** |

Four reusable harnesses, built once and applied across groups:
`measure-settings-tabs.cjs`, `shoot-row-actions.cjs`, `shoot-modals.cjs`, and
`test/support/nested_fixtures.dart`. `render-matrix.cjs` gained `--click-each` tab interaction and
a landed-path premise.

---

## 4. Application defects fixed, by surface area

**Arabic copy and localisation**
* 32 payment **brand names** translated as words — `Stripe` read `الشريط` ("the ribbon"), so a
  merchant could not find the gateway. Aligned with `PaymentTypeLabel::BRANDS`, the existing
  first-party rule.
* 13 currency names and the retired-gateway suffix: wrapped in `__()` with no key, so `__()`
  returned the key.
* **Every date in the Flutter app** rendered its month in English — `DateFormat` built with no
  locale — and a Latin date inside an RTL line reorders, so the coupon history read
  `Nov 2026 01`. Now `٠١ نوفمبر ٢٠٢٦`.
* Flutter order status and payment status printed the server's English strings; both now resolve
  client-side through the existing `StatusBadgeHelper`.
* `"payment".tr` had no key in either file and printed the literal lowercase word.
* **Choices.js** shipped its own English UI, including a `"Remove item"` the library hardcodes —
  screen-reader-only text, invisible in every screenshot ever taken of that surface.

**Accessibility and contrast**
* `<code>` chip at 3.73:1 → 5.65:1, confined to DS-owned ancestors because
  `DesignSystemStylesheetScopeTest` rejected the bare element selector, correctly.
* Required-field marker at 4:1 → 6.54:1 light / 7.47:1 dark, converged on `.ss-label__required`.
* Two unlabelled selects in the flash-sale builder — one of which the **blade does not render**;
  JavaScript rebuilds it.

**Security**
* `GuardsPlanActivation::HISTORICAL_SPELLINGS` — see §5.

---

## 5. The regression this session introduced and fixed

`e513c80f` removed 32 Arabic brand keys. Its message said they reach `orders.payment_type`. **That
was wrong** — they reach `plan_orders`, and `GuardsPlanActivation::planProviderTypeSpellings()`
built its replay-lookup set by globbing the language files. The removal **silently narrowed the
plan-activation replay guard**: a buyer who paid in an Arabic session had a row the guard could no
longer see, so a replayed gateway return could have written a second `PlanOrder`.

Three Feature tests went red and were right to; one had also become **vacuous**. Fixed by pinning
the historical spellings, not by reverting, and ratcheted by
`tests/Unit/Payments/HistoricalPaymentSpellingsTest.php`. The false claim is corrected in both
committed artefacts.

---

## 6. Two findings for the owner — recorded, not fixed

| | |
|---|---|
| **F153** | `plan_orders.payment_type` is written through `__()` and compared against English literals, so a bank transfer submitted in Arabic leaves the super admin's **approve control unrendered**. Pre-existing, a Phase 06 surface, and the real fix is a data-shape change. `PHASE-2.5-PLAN-ORDER-LOCALE-FINDINGS.md` |
| **F154** | The admin API **never runs `SetLocate`** — measured from the booted kernel: the `web` group carries it, the `api` group has two middleware and neither is it. So **every** server-rendered string reaches the Flutter app in the application default language. Two symptoms were fixed client-side; a third would be a workaround. The proper fix changes what a shipped API returns. `PHASE-2.5-API-LOCALE-FINDINGS.md` |

---

## 7. First task on resume

**A fragment harness.** Five surfaces answer 200 with no `<html dir>` and no theme stylesheet,
because the parent page's JavaScript injects them into a panel: `/user-info/{id}`,
`/order-preview/{id}`, `/orders/order_view/{id}`, `/order-receipt/{id}`, `/product-preview/{id}`.
Captured alone they are unstyled markup on a white page, which is not what any merchant sees. They
need the parent driven and the fragment triggered — the same shape as `shoot-modals.cjs`, which
already does this for `#commanModel`.

Then, in order of size:

1. **Remaining Flutter screens** (~9): product detail, add/edit product, create order, invoice
   viewer, add rating, notifications detail. `test/support/nested_fixtures.dart` already holds the
   product payload; the rest follow its pattern. Watch the two traps it records: a tolerant parser
   renders zeros instead of failing, and a type mismatch is caught and reported as "Something went
   wrong" with an empty list.
2. **Flutter infrastructure rows** (~8) — routing table, bindings, theme tokens. Not screens; each
   needs a decision on whether a test or an owner note is the right closure.
3. **Web non-component rows** (~10) — a stylesheet and a Blade include are not components a
   photograph evidences.
4. **The 10 BLOCKED**, four of which need an owner decision rather than engineering.

Before any web capture:

```bash
docs/development/phase-2.5/tools/grant-dev-harness-permissions.sh
```

```bash
docs/development/phase-2.5/tools/serve-dev.sh &
```

```bash
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file php docs/development/phase-2.5/tools/seed-dev-catalogue-rows.php
```

The dev fixture now also needs the 36 action permissions and the 13 create/manage ones; see
`PHASE-2.5-DEV-FIXTURE-PERMISSIONS.md`, which documents all of them and the `redirect()->back()`
refusal shape that defeats a title check.

---

## 8. Standing constraints, unchanged

No merge to `main`. No force push. No history rewrite. PRs #132 and #8 stay draft. No production
access, migration or deployment. Never create or sign the human security acknowledgement. Do not
weaken the Composer advisory gate or add to `.github/known-test-outcomes.json` to get green. Do not
edit `packages/workdo/` except first-party `LynomiaChat`. No PREMIUM VERIFIED promotion without the
owner's visual acceptance. One screenshot may not be evidence for two surfaces.

**A dict key claimed twice silently overwrites.** That cost the web `Coupon detail` its PREMIUM
VERIFIED status this session before the counts were read carefully. `classify()` now accepts a list
of entries per name so a web row and a Flutter row with the same name can coexist, and every claim
script asserts the exact key is absent first.
