# P2.5-FLUTTER-COMP — the shared feedback surfaces, and the three defects photographing them found

Five inventory rows name two widgets, under two sets of names, because two audit passes recorded
them separately: **Shared toast (live)** / **Shared action feedback (AppSnackBar)** are
`AppSnackBar`, and **Shared confirmation dialog** / **Shared confirmation dialog (AppDialog)** are
`AppDialog.showCustomDialog`. A sixth, **Theme switch animation**, is `ThemeSwitchOverlay`.

All three are **overlays**, and that is why none of them had ever been photographed.
`component_gallery_capture_test.dart:187` says so in as many words — *"`AppDialog` opens an
overlay, which a static gallery cannot hold; its BUTTON is the part that ships inside the dialog"*
— so the gallery captured `AppButton` and left the dialog alone.

Driven here through their real public entry points: `commonToast`, `AppDialog.showCustomDialog`,
and `ThemeController.changeTheme`.

## 24 captures

| | |
|---|---|
| `flutter-component-toast-{success,error,warning,info}_*` | 16 — all four types in all four combinations |
| `flutter-component-confirm-dialog_*` | 4 |
| `flutter-component-theme-switch_*` | 4 — mid-reveal, ~260 ms into the 650 ms animation |

The toasts are captured in the **single-line** shape, because that is the only shape the
application ships: `AppSnackBar.success` takes a `message` as well as a `title`, but `commonToast`
— the function all ~271 call sites use — passes only `title`. A two-line toast would be evidence
for a layout no merchant can reach.

## Three defects, each found by looking at a capture rather than at the source

### 1. Every capture of an overlay was blank, and had been all along

`captureScreen` photographed the first `RenderRepaintBoundary` it found walking down from the root.
That is not the view: `_ModalScopeState.build` wraps the first route's **page** in one, so the
search stopped there and the image held that page and nothing above it. A toast, a dialog, a modal
bottom sheet and a popup menu are all `OverlayEntry`s inserted as *siblings* of that page.

**Measured:** the first 20 captures came back as a single flat colour across all 288,000 pixels —
the scaffold background — while `find.text(message)` in the same test found the toast every time.
The widget was there; the evidence was not. No existing guard could see it: a uniform 360×800 PNG
still encodes to ~2.9 KB and cleared every `lengthSync() > 1000` check.

The boundary is now pinned at the top of the pumped tree and looked up by key
(`kCaptureBoundaryKey`), so a capture cannot silently photograph a sub-tree again — if the key
resolves nothing, `captureScreen` fails by name instead of falling back to a search.
`screen_harness_test.dart` carries the regression test, which asserts the structural property that
failed: the thing an overlay drew is a descendant of the boundary the capture is taken from. With
the boundary moved back to its old position that test fails; with it pinned, it passes.

**No previously committed capture is affected.** Every other capture file pumps its subject as the
first route, which is exactly what the old search found. Checked rather than assumed.

### 2. The toast's close control was 2.54:1

`#9CA3AF` on white. It is the only way to dismiss a toast early — no second target, no label, no
gesture — so SC 1.4.11 applies and the bar is 3:1. Dark mode was fine at 7.53:1.

Repaired to `#6B7280` = **4.83:1**, which is not a new colour: it is the shade this same widget
already uses for its secondary text in light mode, and it is exactly the relationship dark mode
already had, where the close icon and the message text are both `#A9B8D0`.

`toast_type_contrast_test.dart` now measures it. The first draft of that test declared the colours
as constants and pinned them with the same `source.contains('0xFF…')` check the palette uses — and
**passed instantly against the broken widget**, because the value it pinned was already in the file
as the message colour. It now parses the close control's own ternary out of the source instead.

### 3. The destructive confirm button's label was 3.76:1, at every call site

**Measured from the rendered pixels**, not from a token: white `#FFFFFF` on `#EF4444`. The label is
14.sp at `w600`, nowhere near WCAG's large-text threshold, so the bar is 4.5:1.

It was not one call site. Twelve `AppDialog.showCustomDialog` call sites pass one of four reds, and
white failed on three of them:

| confirm colour | white label | call sites |
|---|---:|---|
| `AppColor.cRedText` `#dc0303` (light) | 5.18:1 | 7 |
| `AppColor.cRedText` `#FF6B6B` (dark) | **2.78:1** | 7, in dark mode |
| `Colors.red` `#F44336` | **3.68:1** | 4 |
| `danger` `#EF4444` | **3.76:1** | 1 |

So *"delete this customer"*, *"delete this order"* and *"delete this product"* were all under the
minimum in at least one theme.

Repaired at the root: `AppButton` now picks whichever of white and the app's near-black ink
contrasts better with its fill. A button whose white label already passes keeps it — white wins on
every dark fill in this app, the navy primary included at 14.43:1 — so **the only labels that
change are ones that were failing**. The design system reaches the same answer from the other side:
`--ss-on-danger` is `#ffffff` in a light scope and `#3b0d0a` in a dark one. Re-measured from the
pixels afterwards: **4.81:1** in all four combinations.

## And one more, found in the theme-switch captures

In both frames whose destination is the **light** theme, the app bar's title band came back as
7,770 pixels of pure `#FFFFFF` while the back control beside it rendered normally.

`simpleAppBar` resolved `Theme.of(Get.context!)` — a context that is not its own. Reading an
InheritedWidget through a foreign context registers no dependency, so a theme change never marked
the subtree dirty, and when it did rebuild it read a `Theme` resolved elsewhere in the tree, which
during a rebuild pass can still be the old one. The title then stayed on the dark branch,
`AppColor.cWhite`, **on a white bar — 1.00:1, invisible, on the 28 screens this app bar serves**.
The back control survived only because its colour is not conditioned on the theme at all, so the
screen looked fine and the title was simply gone.

Fixed by giving the subtree a `Builder`, so `Theme.of(context)` both reads the right value and
subscribes — the app bar now rebuilds itself on a theme change.
`appbar_title_follows_theme_test.dart` drives the real toggle in both directions and measures the
title's **contrast** against the bar. The first draft asserted only that the colour had changed;
that catches white-on-white and misses navy-on-navy, which is the other direction of the same
defect.

## What the captures do not show

The animation is one frame. A 650 ms reveal is a *motion* and a still photograph of it is evidence
that it ran and what it looks like, not that it is smooth on a handset — there is no device in this
container. What the test asserts beyond the image is that the frozen frame really was captured
(`_capture()` swallows its own failure by design, so a broken animation and a working one are
otherwise indistinguishable — both end with the new theme on screen), that it is being clipped, and
that it removes itself afterwards rather than leaving a screenshot the merchant cannot interact
with.
