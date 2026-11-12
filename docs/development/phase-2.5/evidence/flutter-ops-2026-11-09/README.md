# P2.5-FLUTTER-OPS — the four operations screens, and the three defects their captures found

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`
**Flutter commit:** `f66a2f5`

The second tranche through the screen harness built in `flutter-harness-2026-11-09/`. Four
merchant screens — **tax, shipping, wallet, notifications** — driven with real rows in all four
combinations, by `test/screens/operations_screen_capture_test.dart` in the admin app repository.

The first tranche's README explains the harness itself. This one is about what the captures showed.

---

## 1. What the captures caught, and what they cost to confirm

**Every one of these was found by looking at a PNG and then confirmed in the source.** None was a
pattern match, and two of them looked at first like artefacts of the test environment rather than
product defects.

| # | screen | what the capture showed | what it really was |
|---|---|---|---|
| 1 | tax | `Name` in English between two Arabic headers | `"Name".tr` has no entry in `ar.dart`; the Laravel side has had `"Name": "الاسم"` for the same column since Phase 01 |
| 2 | tax | an 11% VAT row reading **"11% Discount"** | `'Discount'.tr` appended to the amount — wrong word, and untranslated in Arabic too |
| 3 | tax | **"$25 Discount"** on a municipality fee | a hard-coded dollar sign; `taxlist` sends no currency and the app caches none, so the symbol was invented |
| 4 | wallet | the amount drawn as three solid coloured bars | `RichText` does not inherit `DefaultTextStyle`, so the span resolved `fontFamily: null` — **measured `[null, null]`** |
| 5 | wallet | one button on the dark card where light showed two | the primary action at **1.03:1**. Invisible, not missing |

### Why 4 and 5 were nearly dismissed

Solid bars are what a test environment draws for a glyph it has no font for, so (4) read as a
harness gap. It is not: `Text` wraps `RichText` and merges `DefaultTextStyle` into its span;
`RichText` used directly does not. In production that span renders in the **platform** font —
Roboto beside Tajawal on the same card — which is a subtler fault a reviewer could easily miss. The
test environment, having no platform font to fall back on, showed it plainly.

(5) was arithmetic in the end. `AppColor.themeNavyBlueColor` is a **mutable static the active theme
reassigns**, and in dark mode it becomes `StoreSyriaBrand.cyanOnDark`. A gradient written
`[themeNavyBlueColor, themeNavyBlueColor, themeGreenColor]` reads as "navy, navy, cyan" and
evaluated to "cyan, cyan, cyan". Measured on the colours the widget actually used:

| element | dark | light |
|---|---:|---:|
| "Add Funds" label on its own fill | **1.03:1** | 2.16:1 |
| its border against the card | **1.02:1** | — |
| "Withdraw" label (white) on the card | 2.05:1 | 4.05:1 |
| the balance itself (white) on the card | **2.09:1** | 6.42:1 |

Dark failed all four; light failed three. The "premium" card was the least readable surface in the
app, in both themes.

---

## 2. The repairs

All three are in the admin app repository, each written as a failing test first.

* **`test/screens/tax_label_correctness_test.dart`** (4 tests) — the Arabic header, the `Discount`
  label and the invented `$`. The amount is now the rate and nothing else; the column is already
  headed `Amount`/`القيمة` on a screen headed `Tax`/`الضريبة`, so the suffix carried no
  information even where it was not actively wrong.
* **`test/ui/rich_text_font_inheritance_test.dart`** (3 tests) — the wallet amount and the coupon
  form labels, which had the identical fault. Includes a census of all three direct `RichText` uses
  in `lib/`, which fails if a fourth appears without a root-span style.
* **`test/a11y/balance_card_contrast_test.dart`** (7 tests) — reads the gradient, both fills, both
  borders and both labels **off the rendered widgets**, then measures. Both buttons are now solid,
  self-contained surfaces, so each label is measured against its own fill — **13.94:1**, the same
  number in both themes and wherever along the gradient the button lands — with a border in the
  opposite tone, so at every point of the card at least one of (fill, border) clears 3:1.
  Light mode is visually unchanged.

**What was deliberately NOT fixed.** The missing currency on a fixed-amount tax needs the server to
send one: `TaxList` returns the paginator and nothing else, and no screen in the app holds a store
currency that did not come from its own endpoint's response. Adding a field to a public response is
an owner decision — `StoreSyria_app98/docs/design/TAX-CURRENCY-SYMBOL.md`. The same file records
that `shippinglist` rows carry an `amount` the screen never displays, which is a separate gap and is
not claimed here.

---

## 3. One thing the fixtures had to get right

Tax and shipping both `clear()` their list **before** checking the response status
(`tax_controller.dart:49`, `shipping_controller.dart:60`), so a fixture that failed the gate would
leave an empty list and render the screen's `EmptyState` — which looks exactly like a merchant who
has no tax rates. Every case therefore asserts a row from the SECOND fixture entry is really on
screen before it captures, and a control case proves the same screen shows no such text when served
an empty page.

The wallet and notifications needed different envelopes again: the wallet's is
`{status, data: {available_balance, transactions, pagination}}`, not the paginated `data.data`
shape, and `NotificationModel.fromJson` is handed the **whole body** with `count` beside `data`.
A fixture copied from the first tranche would have been wrong in a way that still rendered.

---

## 4. Captures

`after/` holds **16 PNGs** at 360×800, the design size, with the app's real fonts loaded — four
combinations for each of the four screens. They are **post-repair**: the wallet images were taken
again after the font and contrast fixes, so what they show is the screen as it now is.

There is no `before/` set: these screens had no captures at all before today. The pre-repair state
is recorded in the tables above, and reproducible by reverting the three commits' `lib/` changes
and running the same capture command:

```
cd StoreSyria_app98
SS_CAPTURE_DIR=<dir> flutter test test/screens/operations_screen_capture_test.dart
```

With `SS_CAPTURE_DIR` unset nothing is written and the same tests run as ordinary mount tests, so
CI never writes files and the images are never asserted against.

---

## 5. Tests

| file | tests | what it is |
|---|---:|---|
| `test/screens/operations_screen_capture_test.dart` | 18 | four screens × four combinations, with rows, plus a wallet-balance premise and an empty-page control |
| `test/screens/tax_label_correctness_test.dart` | 4 | the three tax-list defects |
| `test/ui/rich_text_font_inheritance_test.dart` | 3 | the font-inheritance defect, its premise, and the `RichText` census |
| `test/a11y/balance_card_contrast_test.dart` | 7 | the card's contrast in both themes, measured off the rendered widgets |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1639 passed** (1607 at the start of this
tranche).
