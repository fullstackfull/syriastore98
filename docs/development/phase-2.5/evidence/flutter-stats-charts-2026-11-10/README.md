# P2.5-FLUTTER-COMP — the analytics charts that were mounted but never photographed

The **Statistics widgets** inventory row stayed PENDING while the **Analytics / statistics** SCREEN
row was claimed, and the reason was a fold.

`statistics_screen_capture_test.dart` already asserted that `PlatformBarChart`, two
`BrowsersGraph`s and `TopUrlChart` were all mounted. Those assertions are true and they are not
evidence that anybody has seen the charts: the capture is 360×800 and three of the four sit below
it, so the image stopped just under the visitors chart.

A second capture is now taken after scrolling, and the scroll position is **asserted** rather than
assumed — `scrollUntilVisible` returning does not mean the widget is inside the surface, and a
capture at the wrong offset would look like a perfectly good photograph of something else.

| | |
|---|---|
| `flutter-statistics-charts_*` | 4 — the Platforms bar chart and the Browsers donut, in all four combinations |

`PlatformBarChart` is also the one widget in `lib/` holding a `VisibilityDetector`
(`statistics_screen_widget.dart:1077`): it starts its bar animation when it scrolls **into** view,
so a capture taken without scrolling would have photographed bars at zero height even if the
viewport had reached them. `initScreenHarness` pins the detector's interval to zero so that
callback fires synchronously instead of behind a timer the binding then fails on.

Both directions and both themes read correctly: the legend rows mirror, the donut's centre total
and its label mirror, and the axis labels (`Android`, `iOS`, `Windows`) stay LTR, which is right —
they are product names, not translated text.
