# P2.5-FLUTTER-SETTINGS — the More grid, where three of four card elements were below threshold

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`
**Flutter commit:** `fb8bfe9`

Two surfaces — the **More grid** (`tab 4 of /dashboard`) and **App settings**
(`GetX /appSettings`) — captured **standalone** by
`test/screens/settings_screen_capture_test.dart`.

Standalone on purpose: `flutter-shell-more_*` in the sibling package shows this grid with the
navigation bar over it, as the shell's fifth destination. These are the screens on their own, so
each inventory row has evidence that is its own rather than a second use of one picture.

---

## 1. The More grid is the merchant's navigation hub, and it was unreadable

Analytics, Shipping, Customers, Delivery Men and Coupon are reached **only** from this grid. Each
card carries a title, a one-line explanation of what the card is for, a chevron saying the card is
navigable, and an icon. Three of those four were below threshold — and the **light** theme was worse
than the dark one:

| element | dark (before) | light (before) | needs | after |
|---|---:|---:|---:|---|
| subtitle | 3.30:1 | **2.15:1** | 4.5:1 | 7.53:1 / 4.83:1 |
| chevron | 2.38:1 | **1.99:1** | 3:1 | 5.37:1 / 3.28:1 |
| card icon | **1.89:1** | 5.72:1 | 3:1 | 5.94:1 / 5.72:1 |

**The cause is the same in all three: an alpha laid over a colour that already cleared the
threshold.** `AppColor.cFont` resolves to `DarkThemeColor.cSecondaryText` (`#A9B8D0`) in dark and
`LightThemeColor.cFont` (`#66728C`) in light — both designed as secondary text, both fine on their
own card at full opacity. `withValues(alpha: 0.55)` is what pushed them under.

Two details worth keeping:

* **The subtitle's branch was a no-op** — `isDark ? ...alpha: 0.55 : ...alpha: 0.55`, the same
  expression on both sides. A conditional whose branches are identical is not a choice; it is a
  value tuned once and copied, and it hid that one number was serving two very different
  backgrounds. A guard now fails if that literal returns.
* **The dark icon was at `alpha: 0.3` while the light icon was already at full opacity**, so the two
  themes disagreed about whether the icon was an illustration or an indicator. They now agree.

The chevron is held to **3:1 and not 4.5:1** deliberately: it is a non-text indicator. It is held to
*something*, rather than treated as decoration, because it is the only thing on the card that says
the card leads somewhere.

---

## 2. How this was measured

`test/a11y/settings_card_contrast_test.dart` renders the real `settingCard`, reads the card's fill
from its own `BoxDecoration`, the subtitle colour from its `TextStyle` and the chevron colour from
its `Icon`, flattens each onto what is actually behind it, and computes WCAG contrast. Nothing is
read from a constant, so the measurement moves if the tokens move.

**One guard was corrected rather than loosened.** Its first version read the raw source file and
failed on its own fix — the comment explaining why `alpha: 0.55` was removed contains the literal
`alpha: 0.55`. It now strips comments with the project's own `codeOnly` helper
(`test/support/source_tree.dart:62`), which is the distinction that file already records.

---

## 3. Why neither screen needs a `register` callback

Worth keeping straight, because the two reasons are different:

* `SettingScreen` is a `StatelessWidget` that builds through
  `GetBuilder<ThemeController>(init: ThemeController())`, so it constructs its own. It is the one
  screen the project could pump before the harness existed, and
  `test/ui/setting_gate_rendering_test.dart` records why.
* `AppSettingsScreen` puts both of its controllers in `State` field initialisers, which run inside
  `pumpWidget` and therefore inside the client zone.

The grid is permission-gated with `mayOffer` rather than `may`, which returns true for an **empty**
permission set so a client shipped ahead of its backend never hides a surface merely because the
server is older than it is. A fresh harness has no permissions, so every card is offered — which is
the right state for a visual review of the grid, and is what these captures show.

---

## 4. Captures and tests

`after/` holds **8 PNGs** at 360×800, all distinct: two screens × four combinations, taken after the
repairs.

| file | tests | what it is |
|---|---:|---|
| `test/screens/settings_screen_capture_test.dart` | 8 | both screens in four combinations, with four grid cards asserted by name in whichever language the combination asked for |
| `test/a11y/settings_card_contrast_test.dart` | 5 | subtitle and chevron contrast in both themes, measured off the rendered card, plus the no-op-branch guard |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1699 passed** (1686 before).
