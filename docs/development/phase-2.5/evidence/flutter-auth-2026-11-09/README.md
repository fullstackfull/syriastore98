# P2.5-FLUTTER-AUTH — the three screens before a merchant is signed in

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`

Splash/Welcome, Onboarding and Login: the first thing anyone sees of this product, the only part a
merchant meets before they have an account — and until now none of the three had a capture.

Four combinations each, by `test/screens/auth_flow_capture_test.dart`.

---

## 1. The welcome screen navigates away from itself

It is the one screen here that cannot simply be pumped and photographed. `_checkAndNavigate` is a
`Future.delayed` of **1500 ms** that then calls `Get.offAllNamed` for `/onboarding`, `/dashboard`
or `/login` depending on stored state (`welcome_screen.dart:60-73`). Pump past that and the capture
is of whatever it left for — a different surface under this one's name, which is exactly the class
of error the project's capture guards exist to catch.

The test therefore pumps a bounded window that stays **inside** the delay (12 frames × 50 ms = 600
ms) and asserts `find.byType(WelComeScreen)` still finds it *before* taking the picture. A
companion premise then drives 4 seconds past the delay and asserts it **does** leave — because
"still on screen at 600 ms" would also be true of a welcome screen whose navigation had silently
stopped working, and the bounded window would then be guarding nothing.

The capture catches it mid-load, with its progress bar part-filled.

### A harness capability this needed

`pumpScreen` now takes an optional `pages:` route table. Without one,
`Get.offAllNamed('/onboarding')` throws *"Could not find a generator for route"* — **during
teardown**, so it reads as an unrelated failure rather than as a missing route table. The three
redirect targets are stubbed as keyed empty `Scaffold`s, which also makes "which one did it land
on?" something a future test can assert.

---

## 2. What the captures show

Both of the other two are already premium and neither needed a repair:

* **Login** — fully Arabic and correctly mirrored in RTL: `تسجيل الدخول`, `البريد الإلكتروني`,
  `كلمة المرور`, with the email placeholder `name@company.com` still rendering left-to-right inside
  the right-to-left field, which is the correct bidi behaviour for an address.
* **Onboarding** — a three-page carousel with its page indicator, Skip and Next; the premise test
  asserts the controller really ships pages, because an empty carousel renders a blank screen and
  photographs as a finished one.

Each test also asserts at least two input fields on the login form: a sign-in screen with no fields
is not a sign-in screen, and that is the shape a mis-registered controller would produce.

---

## 3. Captures and tests

`after/` holds **12 PNGs** at 360×800, all distinct: three screens × four combinations.

| file | tests | what it is |
|---|---:|---|
| `test/screens/auth_flow_capture_test.dart` | 13 | three screens in four combinations, plus the premise that the welcome screen really does redirect |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1721 passed** (1699 before).
