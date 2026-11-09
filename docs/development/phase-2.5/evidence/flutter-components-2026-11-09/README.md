# P2.5-FLUTTER-COMPONENTS — the shared components, alone, and the button that did not say it was disabled

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`
**Flutter commit:** `6941700`

Six shared components rendered **alone**, in all four combinations, with the states that actually
differ placed side by side in one frame — by `test/screens/component_gallery_capture_test.dart`.

The coverage inventory carries these as surfaces of their own, and they are: each appears on many
screens, so a defect in one is a defect everywhere at once. A capture taken inside some host screen
is evidence for **that screen**, not for the component — it shows one instance, in one state, at
one size.

---

## 1. Rendering them found a real defect, and then a larger one behind it

In the buttons gallery, the specimen captioned **"disabled (no handler)"** was indistinguishable
from the one captioned **"idle"**: same brand blue, same white label, and nothing happens on tap.

Pulling that thread found the bigger problem. `CommonButton` is a `GestureDetector` wrapping a
`Container`, so it carried **no button semantics at all**. A screen reader announced its label as
plain text, **in both states** — so *"this is a button"* and *"this button is unavailable"* were
equally unsaid. Dimming alone would have fixed only the half a sighted merchant can see.

Both are repaired:

| | before | after |
|---|---|---|
| inert appearance | identical to enabled | `opacity: 0.5` |
| semantics | none — plain text | `Semantics(button: true, enabled: …)` |

`0.5` is the upper end of the 0.38–0.5 disabled band: dim enough to read as unavailable, light
enough that the label stays legible, which a disabled control still needs. A test holds that label
to **3:1** against its own fill for exactly that reason — WCAG exempts disabled elements from the
4.5:1 minimum, so dimming one into invisibility would replace one defect with another.

`isLoading` is deliberately not folded in: it already replaces the label with a spinner, which is
its own clear state, and already blocks the tap.

### It is LATENT, not live

**All six `CommonButton` call sites in `lib/` pass a handler today**, so no shipped screen renders
the broken state. It is fixed because `onPressed` is **nullable by design** — the signature invites
`null` — so the state is reachable the moment any call site disables a button during validation or
a permission check.

---

## 2. Three test corrections, each a measurement error rather than a code one

Worth keeping, because each would have produced a confident wrong reading:

* **The button dims through `AnimatedOpacity`**, so `BoxDecoration.color` is identical in both
  states. The first version of the test read the declared fill and compared the wrong property.
* **`bySemanticsLabel` matches a merged label**, and what was being asserted was the node's
  *flags*. The test now takes the button's own semantics node.
* **The `StaleDataNotice` specimen invented a key.** It rendered as the raw identifier underneath
  the component's own Arabic heading — a reviewer would have read that as a missing translation
  *in the component*. The specimen was wrong: every real call site passes a server message
  (`StaleDataNotice(message: controller.errorMessage.value)`).

One usage fact also came out of it: **`EmptyState` and `ErrorState` build a `CustomScrollView`**
(`error_empty_state.dart:49`). They are full-viewport components meant to be the body of a scroll
area, and they throw *"Vertical viewport was given unbounded height"* if dropped into an unbounded
column — which is exactly what would happen to anyone placing one inside a scrolling form. The
gallery gives them bounded height and says why.

---

## 3. What these rows claim, and what they do not

**They claim the evidence, not new assertions.** The contrast and layout claims for these
components already exist and are stronger than a picture —
`settings_card_contrast_test.dart`, `card_intrinsic_height_test.dart`, `toast_overlay_test.dart`,
`order_status_label_test.dart`, `image_placeholder_contrast_test.dart`. What was missing was
something a reviewer can **look at**, in both languages and both themes.

So each gallery test asserts only what a capture can honestly carry: the component rendered, in the
direction and brightness asked for, without throwing. The one exception is a premise that measures
the two status-pill fills apart — a pill that looks the same active and inactive carries no
information, and a gallery capture would not notice, because both specimens would simply be
present.

---

## 4. Captures

`after/` holds **24 PNGs** at 360×800, all distinct: six galleries × four combinations.

| gallery | specimens |
|---|---|
| `flutter-component-states_` | EmptyState, ErrorState, StaleDataNotice |
| `flutter-component-skeletons_` | AppShimmer over a bone, ShimmerTable |
| `flutter-component-textfield_` | idle, with icon |
| `flutter-component-pill_` | active, inactive |
| `flutter-component-buttons_` | idle, loading, disabled |
| `flutter-component-search-filter-menu_` | SearchRowWidget, orderStatusTabsWidget, AppPopupMenu |

The last two together cover the inventory row that names five things — buttons, loader, search,
filter and popup menu.

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1775 passed** (1746 before).
