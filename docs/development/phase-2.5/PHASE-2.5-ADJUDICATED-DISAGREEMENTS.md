# Phase 2.5 — the ten findings the two verification runs disagreed about

Two independent adversarial verifiers read the same 94 findings against the same codebase. **They
reached opposite conclusions on ten.** A third pass settled each one by reading the code rather than
the two verdicts.

| finding | run A | run B | **adjudicated** | who was right | final severity |
|---|---|---|---|---|---|
| `ds-no-field-component` | upheld | refuted | **UPHELD** | A | high |
| `theme-is-a-mutable-global` | upheld | refuted | **UPHELD** | A | medium |
| `flutter-no-single-source-of-design-truth` | upheld | refuted | **UPHELD** | A | medium |
| `flutter-no-thememode-system` | refuted | upheld | **UPHELD** | B | low |
| `shopify-hardcoded-english-count` | upheld | refuted | **UPHELD** | A | low |
| `f126-do-not-narrow` | upheld | refuted | **STALE** | B | none |
| `ds-btn-missing-active-and-filter-hover` | refuted | upheld | **REFUTED** | A | none |
| `flutter-dropdown-hint-white-on-white` | refuted | upheld | **REFUTED** | A | none |
| `notifications-silently-marked-read` | upheld | refuted | **REFUTED** | B | none |
| `webhook-modal-js-throws` | upheld | refuted | **REFUTED** | B | low |

**5 upheld, 4 refuted, 1 stale. The later, better-informed run was right 4 times out of 10.**

That last number is the one worth keeping. Run B had corrected ground rules and knew about every
Phase 2.5 slice shipped this week, and it was still wrong more often than it was right on the cases
where the two runs diverged. Being better informed is not the same as being correct, and *"the newer
verdict wins"* would have given the wrong answer six times.

---

## 1. The one that corrected my own work

**`flutter-dropdown-hint-white-on-white` — REFUTED, and I had already "repaired" it.**

The reading is easy to make and hard to doubt:

```
common_drop_down_widget.dart:22   fieldBg   = ... : AppColor.cWhite      // #FFFFFF
common_drop_down_widget.dart:26   hintColor = ... : AppColor.cHintFont   // #FFFFFF
common_drop_down_widget.dart:75   hintStyle: pMedium12.copyWith(color: hintColor)
```

White on white, 1.00:1, on three merchant forms — and on `add_new_product_screen.dart:366` the
label is empty, so the hint is that control's only text. **Two verifiers, a third adjudicator
working on an unrelated finding, and I all reached that conclusion.** I wrote a test that
"reproduced" it by reading `decoration.hintStyle.color` against `decoration.fillColor`, watched it
fail at 1.00:1, repaired the widget, watched it pass, and bit it twice.

**Every one of those steps measured a value the engine throws away.** `DropdownButtonFormField`
does not paint `InputDecoration.hintText`. The SDK converts it into a `DropdownButton.hint` widget
— `Text(effectiveDecoration.hintText!)`, constructed with **no style**
(`dropdown.dart:1883-1886`) — and then blanks the decoration's own hint under its own comment,
*"Clear the decoration hintText because DropdownButton has its own hint logic"* (`:1898-1899`). The
visible hint is painted at `:1604-1605` from `Theme.of(context).hintColor`.

Settled by rendering it rather than by argument:

```
DECLARED hintStyle.color = #66728C      (after my change)
DECLARED fillColor       = #FFFFFF
hint Text widgets found  = 1
  Text.style.color = null               <- hintStyle never reaches it
  RESOLVED color   = Theme.of(context).hintColor
```

and `theme_controller.dart:92` sets that to `LightThemeColor.cFont` **#66728C, 4.83:1** in light and
`DarkThemeColor.cSecondaryText` in dark. **The white glyph is never drawn.**

What survives is not a repair but a **test that measures the right thing**: it builds the app's own
`ThemeData` through `ThemeController` — not a bare `ThemeData(brightness: ...)`, which is what made
the first probe report a default black-at-60% and nearly produced a *second* wrong conclusion —
renders the dropdown, finds the hint a merchant sees, and measures its resolved colour. A change to
`theme_controller.dart:92` would make every dropdown hint in the app illegible and nothing else in
the suite would notice.

**The lesson is the project's own, learned again at my expense.** A test that reads a declaration is
not measuring what ships. It was the `title!` proxy in `icon_button_names_test`, it was the four
"end to end" tests that called a query builder, and here it was a decoration the SDK discards.

---

## 2. The one whose implied fix would have made things worse

**`notifications-silently-marked-read` — REFUTED.** The finding says the Flutter notification screen
calls `markAllAsRead()` on open, destroying unread state before the merchant can see it.

The code does say that. What the finding does not know is that the bell badge is deliberately
`currentTotal − lastSeenTotal`, pinned twice as an owner-level contract decision in the **Laravel**
repository (`MerchantNotificationController.php:54-59`, `DashboardController.php:733-736`) because
the shipped app diffs totals and never reads `is_read`. Under that model, zeroing on open is not an
accidental sweep — **it is the re-baseline that implements the badge.**

`markAllAsRead()` is the only *upward* re-baseline in the controller. Delete it and the badge
recomputes from a stale baseline on every restart and never clears: a permanently stuck count,
strictly worse than today. What actually survives is one stale comment that contradicts the line
above it.

---

## 3. Where "better informed" lost

**`theme-is-a-mutable-global` — UPHELD** against the later run, and the adjudicator found the
repository admitting the defect in its own tests: `store_syria_brand_test.dart:157` carries the
comment *"leave the globals where the rest of the suite expects"*, and `brand_is_painted_test.dart`
reaches dark mode by **mutating a global and restoring it** rather than by pumping a dark theme.
Run B mistook that workaround for a refutation.

**`ds-no-field-component` — UPHELD, high**, with four of the finding's six counts corrected upward
(`ss-label` 166 not 131, `ss-input` 131 not 91, `ss-select` 25 not 13). The finding understated its
own case.

**`f126-do-not-narrow` — STALE, not refuted.** Every factual claim in it is still true, including
that narrowing the F126 selector would uncover `style-dark.css:29464-29465` and move dark-card
labels from 5.66:1 to 3.71:1. It is stale because **P2.5-A-01 did the thing it warned against doing
wrongly** — the distinction between *was never real* and *was real and is now handled* is the
reason this table has three outcomes rather than two.

---

## 4. What this changes about the headline numbers

Four findings move from upheld to refuted and one to stale, so the counts in
[`PHASE-2.5-VERIFICATION-RESULTS.md`](PHASE-2.5-VERIFICATION-RESULTS.md) become **224 upheld, 66
refuted or stale**.

**None of the ten is a blocker, and none is in the 55-item repair package**, so no planned work
changes. The adjudication settles the record, which is what it was for.
