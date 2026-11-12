# P2.5-FLUTTER-HOME — the merchant dashboard, and a harness bug that nearly became a defect report

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`
**Flutter commit:** `5264713`

The first screen a merchant sees, and the only one in the app that renders from a **single**
response: `POST /dashboard` carries the user, the stores, the QR code, three headline totals, the
top products, the recent orders, the coupons, both charts, and the merchant's permissions.

Four combinations with real data, by `test/screens/home_screen_capture_test.dart`.

---

## 1. The harness was producing half-dark captures

**This is the most important thing in this package**, because it almost went into the record as a
product defect.

The dark capture showed the store-selector row as a **white pill with near-invisible text** inside a
dark page — exactly the "light surface in dark mode" shape this phase has found before. The code
looked correct: `color: isDark ? DarkThemeColor.cCardBackground : AppColor.cWhite`, and
`cCardBackground` is `#18263F`.

Probed rather than argued about:

```
themeController.isDark = false
Theme.brightness      = Brightness.dark
AnimatedContainer fill = #FFFFFF
```

Many screens do not ask `Theme.of(context).brightness`. They build through
`GetBuilder<ThemeController>` and branch on `themeController.isDark.value`
(`home_screen.dart:81`). `pumpScreen` set the **MaterialApp** theme from the combination and left
that controller alone — and `ThemeController.onInit` seeds `isDark` from `Prefs.getBool(...)`,
which is **false** in a fresh harness. So the screen rendered its light branches inside a dark
`ThemeData`.

`pumpScreen` now aligns any registered `ThemeController` with the combination before the first
pump, and the regression is visible in the re-taken captures.

**Scope of the contamination, measured:** four files under `lib/view` branch on that controller —
`dashboard_manager.dart`, `home_screen.dart`, `setting_screen.dart`, `app_settings.dart` — and only
`home_screen.dart` is among the surfaces captured so far. **The dark captures already claimed in
`flutter-harness-`, `flutter-ops-` and `flutter-orders-` are unaffected**; every one of those
screens reads `Theme.of(context).brightness`.

---

## 2. Two real defects, both in both languages

### Every Overview tile label was clipped

Three tiles share a 360 pt row — about 105 pt each — and the label was one line with
`overflow: TextOverflow.ellipsis` (`home_screen_widget.dart:483`). The **figure** above it is
wrapped in `FittedBox(fit: BoxFit.scaleDown)` and therefore always fits; the label was given no
equivalent.

Measured with `RenderParagraph.didExceedMaxLines` — the renderer's own answer after layout, not a
reading of the source:

| | before |
|---|---|
| English | **2 of 3** clipped — `Total Produc…`, `Total Delive…` |
| Arabic | **3 of 3** clipped — `الإجمالي المنـ…`, `الإجمالي تم ال…`, `الإجمالي الطـ…` |

`الإجمالي الطـ` is not a word. Three numbers sat side by side on the merchant's home screen with
nothing legible to say which was which.

The label now wraps to two lines. The tile is a `Container` sized by its `Column` with no fixed
height, so that costs a few points of height — and it keeps the font size, which shrinking the text
would not. The `Semantics(label:)` above it was already complete and is untouched: this was never a
screen-reader problem, it was a problem for everyone who reads the screen.

### `Overview` was untranslated

`'Overview'.tr` is the section heading here **and** on the statistics screen, and it had no entry in
either translation file — so the Arabic build printed the English key above three Arabic tile
labels. Translated to `نظرة عامة` and removed from the known-untranslated ratchet in
`test/translations/translation_keys_test.dart` so it cannot quietly regress.

---

## 3. Two fixture errors, reported as fixture errors

Both times the code was right and the fixture was wrong. Recorded because each one taught something
about the contract:

* **`recent_order.delivery_status` is a STRING.** The server sends
  `$value->delivered_status_string` (`Api/DashboardController.php:1181`), not the integer the orders
  LIST uses. `RecentOrder.fromJson` assigns it straight into a `String?` with no guard, so an int
  there throws `type 'int' is not a subtype of type 'String?'` and takes down the **whole
  dashboard** — for a field this screen does not even render. Latent, since the server cannot send
  an int there, but worth knowing.
* **`sales` is a COUNT, not money** — `Order::where('delivered_status', 1)->count()`
  (`:1160`) — despite the tile reading "Total Delivered". A money-shaped `1840000` under that label
  would have made the evidence misleading, so the fixture uses `37`.

---

## 4. One structural fact: `HomeScreen` cannot be mounted alone

Its own `Get.put(NotificationController())` is **commented out** (`home_screen.dart:36`) while
`buildAppTitleRow` in `home_screen_widget.dart:21` still calls `Get.find<NotificationController>()`.
Mounting the screen on its own throws `"NotificationController" not found` from inside a
`GetBuilder`, which surfaces as a **blank screen** rather than as a thrown test — the first run
rendered **zero** `Text` widgets while the controller held the data perfectly. In the running app
the bottom-nav shell has already registered it.

Not a defect in the shipped app; a dependency the test has to satisfy, and the kind of thing a
screen test is for.

---

## 5. Captures and tests

`after/` holds **4 PNGs** at 360×800, all distinct, taken **after** both repairs and after the
harness fix.

| file | tests | what it is |
|---|---:|---|
| `test/screens/home_screen_capture_test.dart` | 6 | four combinations with data, a failed-response control, and the tile-gating premise |
| `test/ui/dashboard_tile_label_fit_test.dart` | 4 | the label-fit measurement, in all four combinations |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1657 passed** (1647 before).
