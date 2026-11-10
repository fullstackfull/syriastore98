# Phase 2.5 — the Flutter bottom-sheet editors (2026-11-10)

Category, customer, tax and shipping are edited in a `showModalBottomSheet`, not on a screen of
their own. They appear in no route table, so no capture wave that navigates could reach them.
`test/screens/bottom_sheet_editors_capture_test.dart` drives them as the widgets the sheet
actually builds.

**40 captures: 10 surfaces × 4 combinations.** Create *and* edit for each of the four, plus both
steps of the customer wizard. `isUpdate` is not cosmetic — it decides the title, the submit label,
and whether the fields arrive populated — so a capture of the empty create state is not evidence
for the edit state.

## What it took to make them render

| problem | what it actually was |
|---|---|
| 32 of 32 threw `No Material widget found. TextField widgets require a Material widget ancestor` | pumped bare, these widgets have no Material. In the app `showModalBottomSheet` wraps them in the BottomSheet's own. The harness supplies `Material(type: MaterialType.transparency)` — **transparency** because all four paint their own surface with a `BoxDecoration`, and an opaque Material would put its colour under them, making the dark captures evidence of the harness rather than the sheet. |
| 16 of 32 threw `"CategoryController" not found` | `register: () => Get.put(Foo())` is an **arrow**, so Dart infers `Get.put<void>` from the `void Function()` parameter. The harness detects this and says so in the failure message — a guard a previous wave built after paying for it. Block bodies fix it. |

## What the captures found — an ON switch is invisible in dark mode

**Measured from the painted pixels, not from the declaration.** The switch's rect came from
`tester.getRect`, not from looking at the picture, and the PNG was sampled inside it:

| | before | after |
|---|---|---|
| ON track, dark (`ar_rtl_dark` and `en_ltr_dark`) | `#172C4E` on `#18263F` — **1.09:1** | `#2AC3E5` — **7.23:1** |
| ON vs OFF track, dark | 2.00:1 | 3.33:1 |
| ON track, light | `#172C4E` on white — 13.94:1 | unchanged |

WCAG 1.4.11 asks 3:1 for a non-text control. At 1.09:1 the ON state is not "slightly low", it is
invisible — and at 2.00:1 from the OFF state a merchant could not tell whether *trending*,
*status* or a customer's *account status* was on without toggling it and watching the label.

The cause is the one CLAUDE.md names: a light-mode value carried into dark instead of being
derived. The fix uses the app's own `DarkThemeColor.cPrimary` (`StoreSyriaBrand.cyanOnDark`
`#2AC3E5`) in dark and leaves light alone. Four sites: the category sheet's two switches, the
customer sheet's and the delivery-boy sheet's.

Pinned by `test/a11y/switch_active_track_contrast_test.dart`.

### Two ways this nearly went unmeasured

1. **The control is not the same widget on both sheets.** The category sheet uses
   `CupertinoSwitch`, where `activeTrackColor = activeTrackColor ?? activeColor`
   (SDK `cupertino/switch.dart:158`) so `activeColor:` *is* the track. The customer sheet uses
   `Switch.adaptive`, which builds a **Material** `Switch` here — and there `activeColor` is the
   **thumb**. A test that looks only for `CupertinoSwitch` finds zero widgets on the customer
   sheet, and a finder that matches nothing reads exactly like a passing check.
2. **The customer switch only exists on the create path.**
   `customer_bottom_sheet.dart:423` opens `if(!widget.isUpdate) ...[`, which holds the password
   field and `_buildStatusSwitch()`. Driving the sheet with `isUpdate: true` — the obvious way to
   get a switch into the ON state — renders no switch at all.

Both are recorded in the test so the next reader does not rediscover them.

## Surfaces

| slug | what it is |
|---|---|
| `flutter-sheet-category-create` / `-edit` | category editor; two switches (trending, status) |
| `flutter-sheet-customer-create` / `-edit` | customer wizard, step 1 — identity |
| `flutter-sheet-customer-create-step2` | step 2 of **create** — password and account status |
| `flutter-sheet-customer-edit-step2` | step 2 of **edit** — address and delivery notes |
| `flutter-sheet-tax-create` / `-edit` | tax editor; registers its own controller at `add_tax_bottomsheet.dart:36` |
| `flutter-sheet-shipping-create` / `-edit` | shipping editor |

Full Flutter suite after the change: **1907 passed**.
