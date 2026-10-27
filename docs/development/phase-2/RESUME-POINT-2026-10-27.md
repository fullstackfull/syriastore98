# Resume point — 2026-10-27, end of the F117 / P2-05-6 / P2-05-7 round

Written because the owner asked for a precise resume point rather than a report. It records
where the work actually stands, what the next person should pick up first, and — more usefully —
the things that are true about this codebase and its harnesses that are not obvious from the
diff.

**Branch:** `claude/store-syria-p02-continuation-smlpwi` on both repositories. `main` untouched,
no force push, no migration, no deployment.

---

## 1. What closed in this round

| | |
|---|---|
| **F117** | **CLOSED.** All sixteen credential fields converted, display and persistence together. The exposure ratchet's list is EMPTY and still bites. |
| **P2-05-6 (Reports)** | **DONE.** 11 of 11 screens on the design system, every figure carrying its period, every chart shipping its numbers as a real table. 244 live browser checks, 32 screenshots in four genuinely different configurations. |
| **P2-05-7 / P2-05-8** | **PARTIAL.** The credential half is done and asserted. The panel POST contract is pinned. Five of ~31 panels migrated. |

Four defects found by independent review, three of them created by the F117 change itself,
are described in `PHASE-02-TEST-EVIDENCE.md` §34f. The most serious — a staff member being able
to make the server authenticate to a host they choose using the merchant's stored SMTP password
— is the one to read first if you read only one thing.

---

## 2. Pick this up first

**P2-05-7's remaining ~26 settings panels.** Everything needed to do it safely now exists:

- `tests/Unit/Ui/SettingsPanelContractTest.php` pins each panel's POST target and field names, so
  a migration that renames a field or moves a form fails immediately instead of silently
  breaking the save. **Extend `CONTRACT` as you reach each panel** — the eleven in there now are
  the ones that were already converted or integration-bearing.
- `resources/views/setting/twilio_setting.blade.php` is the worked example: `ss-card`,
  `ss-card__header` with `ss-card__title`, `ss-card__body`, `ss-card__footer ss-card__footer--end`,
  `ss-field` / `ss-label` / `ss-input`, `<x-ss.switch>` for a toggle, `<x-ss.secret-field>` for a
  credential, and `ss-field__hint` with `aria-describedby` for a note.
- `resources/views/setting/*` is now in `ArabicUiCoverageTest::SURFACES`, so a new English string
  fails the Unit suite. Add the Arabic in the same change.

**Look for the empty label.** Every integration panel had
`<label class="custom-control-label" for="…"></label>` — a toggle with no accessible name. It is
the house pattern in this directory; assume the next panel has it too.

---

## 3. Things that will waste your time if you do not know them

**The settings panels could not be rendered on the preview instance at all until this round.**
`currency_rates` has no migration (GATE F) and `settingForm()` seeds it before dispatching on the
tab, so without the table every tab answers "something went wrong" and renders nothing.
`database/seed-p2dev.php` creates it now, as a dev fixture. If you rebuild the preview database
from an older checkout you will hit this again and it looks exactly like a broken page.

**Direction follows the USER'S LANGUAGE, not the store's `SITE_RTL`.** `layouts/app.blade.php`
lines 48–50 override it to `on` whenever `auth()->user()->language` is `ar` or `he`, and the
p2dev merchant is Arabic. A harness that flips only `SITE_RTL` shoots an RTL page in all four
"configurations" — measured: the `ltr` and `rtl` PNGs were byte-identical.
`shoot-reports-live.cjs` drives `/change-language/{lang}` and **hashes its screenshots to prove
the four differ.** Seventeen other harnesses in `tools/` do not do this, so any
four-configuration claim resting on them is really a two-configuration claim. That is a claim
about THEIR evidence and has not been fixed.

**`ReportController` cannot run on SQLite.** 36 uses of `MONTH()`, `YEAR()`, `DATE()` and
`SUBSTRING_INDEX()` mean four of the hub's five windows, the whole Top Sales body and all three
sale-by bodies answer 500 in the suite and in the browser. Tests that need those paths render the
PARTIAL through a throwaway route instead (the admin layout needs `Request::route()`, so a bare
`view()->render()` dies in the header). **There is no MySQL in this container** — no mysqld, no
client — so the MySQL path is UNVERIFIED and must not be claimed.

**A source-text check matches its own documentation.** Three times this round: `@php` inside a
Blade comment, `<x-ss.bar-chart>` inside a JavaScript comment, and `role="tablist"` inside a
Blade comment explaining the `role="tablist"` defect. Strip comments before scanning, or assert
against rendered output.

**The `<tfoot>` total is a `<th scope="row">` too.** Counted as data three times this round,
which doubled every sum in one test. Scope row scans to `<tbody>`.

**`ConvertEmptyStringsToNull` means a blank box arrives as NULL, not `''`.** Pinned by
`SecretSettingNonSettingStoresTest::test_an_empty_box_arrives_as_null…`. An earlier draft of
three docblocks reasoned about `''` and was wrong.

---

## 4. Then, in the owner's order

1. **P2-06** — not started this round.
2. **P2-07 Flutter** — not started this round.
3. Open items in **P2-01 / P2-03 / P2-04**.
4. **P2-08**.

Unchanged and still open, as the owner instructed: **D-13 NOT DONE** with `$plan` suspended,
**D-20 PARTIAL**, **E13 BLOCKED**, **D-22 / F121 not started** (`PayUPaymentController:507` is a
known site), and the security incident **#120**.

---

## 5. Evidence standing at the end of the round

```
Unit       479 passed
Contract    26 passed
Feature   6176+ testcases, 2 declared failures, 17 declared skips, 0 errors
           (assert-known-test-outcomes.php: the outcome set matches the manifest)
Live       244 browser checks over 4 report screens x 4 configurations, 0 failed
```

**RUN AT THE END OF THE ROUND, ON THE FINAL TREE:** `Feature` 6179 testcases, 2 declared
failures, 17 declared skips, 0 errors — `assert-known-test-outcomes.php` reports the observed
outcome set matches the manifest exactly. That is not "the suite is green": it is the suite
failing and skipping exactly what the project has recorded, for the reasons it has recorded.

**NOT CLAIMED:** MySQL. There is no mysqld and no mysql client in this container, so nothing in
this round was executed against it, and SQLite's type affinity is why P01-SEC-13 survived a
green suite once already.
