# P2.5-FLUTTER-SHELL — the bottom-navigation shell, and three rows that were never undecidable

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`
**Flutter commit:** `fb8bfe9`

---

## 1. Three BLOCKED rows, unblocked by being able to look

`Nav destination 1 — Home/dashboard`, `2 — Orders` and `3 — Products` were all **BLOCKED** with the
same reason: *"audit could not determine its state; it needs to be rendered and classified."*

They were blocked on **rendering**, which is precisely what the screen harness does. Nothing about
them was undecidable — there was no way to look. The phase's BLOCKED count goes **13 → 10**.

The other ten BLOCKED rows are not of this kind and are untouched: four are routes with no
renderable output or no caller (`Customer filter result fragment`, `Customer filter field
metadata`, `Wishlist create/edit/store/update`, `Edit customer`), each needing an **owner decision**
rather than a design pass; the rest are out-of-phase or dead files.

---

## 2. What mounting the shell really costs

Measured, not hoped for. The body is an `IndexedStack` (`dashboard_manager.dart:94`), which **builds
every child**, and `DashboardManagerController.onInit` constructs all five screens up front. So
mounting the shell mounts the dashboard, the orders list, the products list, the categories tab and
settings **at once**, and every controller any of them does `Get.find` for has to be registered:
`ThemeController`, `NotificationController`, `OrderController`, `ProductController`,
`CategoryController`, `CustomerController`, `DeliveryBoyController`, then
`DashboardManagerController` last — last because its `onInit` is what constructs the screens whose
field initialisers do the finding.

That is also exactly what the running app does. This is the first test in the project that
exercises the whole merchant surface together.

---

## 3. The repair: a navigation label nobody could read

| | bar background | selected | unselected |
|---|---|---:|---:|
| light (before) | `#172C4E` | 13.94:1 | **3.14:1** |
| light (after) | `#172C4E` | 13.94:1 | **5.15:1** |
| dark (unchanged) | `#14213A` | 7.67:1 | 5.60:1 |

The bar is **dark navy in both themes** (`cBottomNavyBlueColor`), so its inactive label has to be a
light tint in both. In light mode it was `AppColor.cDarkGreyFont` — a grey designed for light
backgrounds, applied to a dark bar. Four of the five labels are unselected at any moment, on the one
surface that stays on screen for the entire session.

`Colors.white54` is what the dark theme already used, so the fix makes the two themes agree rather
than inventing a third value. The colours are read off the rendered `StylishBottomBar`, not from the
source.

---

## 4. Why these are distinct surfaces from the tab screens

The owner's constraints forbid using one picture as evidence for two surfaces, so this matters.

`flutter-home_*` and `flutter-orders-list_*` (earlier packages) are those screens **alone**, mounted
standalone. `flutter-shell-home_*` and `flutter-shell-orders_*` are the same screens **inside the
shell**, with the navigation bar drawn over them and a destination selected — a different widget
tree, a different picture, and the only place the navigation bar itself can be reviewed.

All **20** captures are distinct by content, and `check-distinct-surface-captures.py` scanned 1274
captures across the phase with no collisions.

A `premise` test also drives all five destinations in one mount and asserts the shell really reached
`{0,1,2,3,4}` — because a shell stuck on destination 0 would have produced five identical pictures
under five names, and catching that after the fact is what the collision checker is for. This
catches it before.

---

## 5. What is deliberately NOT claimed

**The two shell rows** — `Dashboard shell (bottom navigation)` (line 58) and `Bottom-nav shell
(DashBoardManagerScreen)` (line 267) — stay **PENDING**. They are the same surface listed twice in
two inventory sections, and the shell is never on screen without a destination, so it has no picture
that is not already one of the five above. Claiming it would mean using one capture as evidence for
two surfaces.

Its navigation bar **was** measured and repaired here. What those rows need is an inventory decision
about whether a frame with no content of its own is a surface at all — not another engineering pass.

---

## 6. Captures and tests

`after/` holds **20 PNGs** at 360×800, all distinct: five destinations × four combinations.

| file | tests | what it is |
|---|---:|---|
| `test/screens/dashboard_shell_capture_test.dart` | 23 | 20 destination mounts, the all-destinations premise, and the navigation-bar contrast in both themes |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1699 passed** (1686 before).
