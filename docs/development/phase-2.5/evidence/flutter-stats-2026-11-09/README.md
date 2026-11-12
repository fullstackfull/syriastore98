# P2.5-FLUTTER-STATS — the analytics screen, and the dependency timer that failed every first test

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`
**Flutter commit:** `b0f05fe`

The one surface in the admin app that is almost entirely charts: a visitor line chart, a top-URL
list, and three breakdowns (platform, browser, device). Four combinations with all five sections
populated, by `test/screens/statistics_screen_capture_test.dart`.

---

## 1. A pending timer that named no widget

The first test of every run failed with:

```
A Timer is still pending even after the widget tree was disposed.
```

— at teardown, pointing at nothing. Extra pumping did not help, because the timer is **rescheduled**
rather than drained.

Found by bisecting the screen section by section: `platform only` failed and `empty`,
`visitorchart only`, `topurl only`, `browser only` and `device only` all passed. `PlatformBarChart`
wraps its chart in a `VisibilityDetector` (`statistics_screen_widget.dart:1077`) so the bars animate
once the chart scrolls into view — and that package batches its callbacks behind an internal
`Timer` whose period is `VisibilityDetectorController.updateInterval`, 500 ms by default. It is the
**only** `VisibilityDetector` in `lib/`.

`initScreenHarness` now sets that interval to `Duration.zero`, which is the package's own documented
setting for tests: callbacks fire synchronously and no timer is scheduled. **Production behaviour is
correct and is not touched**, and the setting has its own regression test in
`screen_harness_test.dart`.

Worth recording because the failure mode is misleading in both directions: it reads as a harness
fault, and it only ever hits the first test, so a casual re-run of a single later test "passes".

---

## 2. Four English labels in the Arabic build

Visible in the first populated Arabic capture, on the summary card and the top-links section:

| key | was | now |
|---|---|---|
| `Total page views` | *(English key)* | `إجمالي مشاهدات الصفحات` |
| `Unique visitors` | *(English key)* | `الزوار الفريدون` |
| `Top links` | *(English key)* | `أهم الروابط` |
| `Most visited links` | *(English key)* | `الروابط الأكثر زيارة` |

All four were already tracked in `test/translations/translation_keys_test.dart`'s
known-untranslated list — which is exactly what that list is for, and the capture is what made them
worth clearing. Translated and removed from the ratchet, so they cannot regress.

`Overview`, the heading above the first card, was fixed in the same session for the dashboard and
is correct here too: the key is shared between the two screens.

---

## 3. One assertion corrected, not a defect reported

The first version looked for `Android`, `Chrome` and `Mobile` as text and found **none**. The
breakdown names are painted by the chart library's own title builders and are not mounted as `Text`
widgets. The sections are now asserted on their widgets instead: `PlatformBarChart` carries the
platform breakdown, and `BrowsersGraph` carries **both** the browser and the device one
(`isDevice: false` / `true`), so one of the first and two of the second is the whole set.

That matters because each section is conditional on its own list being non-empty
(`statistics_screen.dart:256, :261, :267`): a fixture that filled one list would have captured a
screen with three sections silently absent and still passed a needle on the top-URL text.

---

## 4. Captures and tests

`after/` holds **4 PNGs** at 360×800, all distinct, taken after the translations landed.

| file | tests | what it is |
|---|---:|---|
| `test/screens/statistics_screen_capture_test.dart` | 5 | four combinations with all five sections, and a refused-response control that also exercises the `status == 1` gate |
| `test/support/screen_harness_test.dart` | 16 | the harness's own claims, now including the visibility-detector interval (15 before) |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1663 passed** (1657 before).
