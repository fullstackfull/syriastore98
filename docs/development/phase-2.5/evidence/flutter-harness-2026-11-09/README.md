# P2.5-FLUTTER-HARNESS — the Flutter screen harness, and the first Flutter surfaces with evidence

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`
**Flutter commits:** `9536efb` (harness), `5ef5333` (capture + populated screens)

Until today the two Flutter areas were **75 surfaces with zero done**, which
`PHASE-2.5-ACCOUNT-HANDOFF.md` §4 called "the largest single gap in the phase, and the one the
current container could not work on, because `flutter` is not installed". The SDK is installed now
at **3.47.7**, the version `.github/workflows/ci.yml` pins, and this is the infrastructure that
turns that into coverage.

---

## 1. The blocker, and what actually caused it

`test/ui/setting_gate_rendering_test.dart` recorded it: screens "fire `getTopOrderData()`,
`_fetchInitialData()` and `getDeliveryBoysData()` themselves, in `initState` and a post-frame
callback — an `onInit` override touches none of them, the calls reach the network, and their
failure toasts leave GetX timers pending so `pumpAndSettle` hangs for reasons unrelated to any
gate." `SettingScreen` was the only screen that could be pumped, and only because it builds through
`GetBuilder<ThemeController>(init: ThemeController())` and constructs its own controller.

Four things were **measured** before a line of the harness was written, and each decided a design
choice. Two of them contradicted the recorded explanation.

| # | question | measured answer |
|---|---|---|
| 1 | can a zone-scoped `http` client reach a fetch started from `initState`? | **yes** — `hits=1 [GET /api/admin/customerlist]` from a post-frame callback |
| 2 | why does `pumpAndSettle` hang? | **not GetX timers.** `shimmer.dart:76` is `_controller.repeat(...)` — the loading animation never ends, so the tree never reaches quiescence |
| 3 | does the failure toast leave a pending timer? | **no.** `Get.snackbar`'s 4s duration drains when the fake clock is advanced past it |
| 4 | what does a screen need registered? | `StoreChangeService` — six controllers call `StoreChangeService.instance.register(...)` in `onInit`, and `main.dart:55` is the only place it is put |

**No production code changed.** Not one line under `lib/` is touched by the harness.

### Why `runWithClient` and not a repository fake

`package:http` warns that "Flutter does not guarantee that callbacks are executed in a particular
Zone", so the seam was measured rather than assumed (row 1 above). The alternative — swapping each
controller's repository field — is **not universal**: of 23 repository fields across the
controllers, **8 are `final`**, `OrderController`'s three among them. That seam would have needed
production edits to reach the screens that matter most.

---

## 2. Two traps found while building it, both now named failures

**The arrow trap.** `register: () => Get.put(Foo())` is silent and expensive: the parameter is
`void Function()`, so Dart infers the generic of `S Get.put<S>(S)` from that `void` **context** and
registers the instance under `void`. The screen's own `Get.find<Foo>()` then throws
`"Foo" not found`, which reads like a missing registration rather than a mis-typed one. Measured:

```
arrow  isRegistered<Foo>=false  isRegistered<void>=true
block  isRegistered<Foo>=true   isRegistered<void>=false
```

Nothing in this application registers anything under `void`, so `pumpScreen` detects it and fails
by name with the remedy.

**The cache trap**, which cost two red tests before it was understood. `CacheService.save`
(`cahe.dart:16`) writes every successful page to GetStorage under `cache_*` and controllers read it
back on mount. `Get.reset()` does not touch GetStorage, so a test that stubbed a **500** still
rendered the **previous test's customers** — the screen showed data the stub never served. The
signature was unmistakable once seen: the two tests failed together and passed in isolation.

Both have their own regression test.

---

## 3. What is claimed here, and what is deliberately not

| surface | state | why |
|---|---|---|
| **Customers list** (`GetX /customers`) | **REDESIGNED AND VERIFIED** | driven and captured in four combinations with three rows of data |
| **Delivery men (staff) list** (`GetX /delivery_boys`) | **REDESIGNED AND VERIFIED** | four combinations, two rows |
| **Categories (tab 3)** | **REDESIGNED AND VERIFIED** | four combinations, two rows |
| **Coupons list** (`GetX /coupon`) | **REDESIGNED AND VERIFIED** | four combinations, two rows |
| **Products list (tab 2)** | **still PENDING** | four combinations, two rows — **and an open defect its own capture found** |

**An empty list is not a verified list screen**, so all five were re-captured with rows in them
before anything was claimed. A reviewer who has only seen "No customers found" has not seen the
screen.

**Products is deliberately held back.** Its capture is what found **P2.5-FLUTTER-PRICE**: the card
is handed `salePrice` alone, and `sale_price` is the optional DISCOUNT (`->default(0)`, validated
`lte:price`), so every product nobody has discounted renders an em dash where the web prints
`$product->price`. A screen that shows no prices is not a verified screen, whatever its captures
look like. It stays PENDING until the owner decides the repair —
`StoreSyria_app98/docs/design/PRODUCT-PRICE-PARITY.md`, with
`test/screens/product_price_parity_test.dart` as the executable half.

**The route needle is not optional.** "Customers list" and "Delivery men" are names this inventory
also uses for web surfaces. Each Flutter entry carries `GetX /...` as its needle so a Flutter
capture can never mark a Laravel screen verified — the matrix row for the web `Customer list` still
cites its own P2.5-WAVE-1 captures.

---

## 4. Why a populated fixture is the whole point

`DeliveryBoyModel.fromJson` reads `status` as `json['status']?.toString()`, but the **controller**
gates on the raw value: `data['status'] == 'success' || data['status'] == 1`
(`delivery_boy_controller.dart:71`). The first version of this fixture carried the **string** `'1'`,
which matches neither — so the list stayed empty and the screen rendered its empty state, **which
looks exactly like a working screen with no data**.

`pumpUntil` turned that into a named failure instead of a passing test. Without it the capture
would have frozen an empty list and called it verified. Every case therefore asserts the
controller's list filled *and* that a row's text is really on screen, before any capture is taken.

This is the same class of error that twice froze a wrong golden baseline in this repository
(`email` nested inside `customer_info` instead of at the root; `mobile` instead of `contact`).

## 5. One thing the captures raised and the code settled

The delivery-man rows show a `معطلة` ("disabled") pill although the fixture sets `status: 1`. That
is **not** a status defect: the pill is `isCommissionEnabled ? 'enabled' : 'disabled'`
(`delivery_boy_widget.dart:191`) — the **commission** state, not the account state — and the
fixture omits `commission_percentage`. Recorded because the capture is what raised it and reading
the code is what settled it.

---

## 6. Captures

`after/` holds **40 PNGs** at 360×800, the design size, with the app's real fonts loaded:

* `flutter-*-list_*` — **populated**, four combinations each, for all five screens. Four of the
  five are the claimed surfaces; `flutter-products-list_*` is the evidence for the price defect.
* `flutter-customers_*`, `flutter-products_*`, `flutter-categories_*`, `flutter-coupons_*`,
  `flutter-delivery-men_*` — **empty state**, four combinations each, from the mount matrix.

There is no `before/` set: these screens had no captures at all before today, which is the gap this
package closes. Reproduce with

```
cd StoreSyria_app98
SS_CAPTURE_DIR=<dir> flutter test test/screens/
```

With `SS_CAPTURE_DIR` unset nothing is written and the same tests run as ordinary mount tests, so
CI never writes files and the images are never asserted against.

## 7. Tests

| file | tests | what it is |
|---|---:|---|
| `test/support/screen_harness_test.dart` | 13 | the harness's own claims, including that the shimmer really repeats for ever and that `pumpUntil` fails readably instead of hanging |
| `test/screens/screen_mount_matrix_test.dart` | 21 | five screens × four combinations, each asserting the call was made, nothing threw, and the direction and brightness are the ones asked for |
| `test/screens/populated_screen_capture_test.dart` | 20 | all five screens, with rows |
| `test/screens/product_price_parity_test.dart` | 2 | the recorded price defect, and the control proving the card works when given a price |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1607 passed** (1529 at the start of the
session).
