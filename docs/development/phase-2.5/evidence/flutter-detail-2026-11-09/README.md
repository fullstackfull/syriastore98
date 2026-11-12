# P2.5-FLUTTER-DETAIL — the store-settings hub and customer detail

**Date:** 2026-11-09 · **Branch:** `claude/vibrant-bell-12tquy`

Two surfaces a merchant reaches by pushing from a list, and the first captures of either. Four
combinations each, by `test/screens/detail_screens_capture_test.dart`.

## What each capture is actually evidence of

**The store settings hub** is a grid of cards leading to three screens captured elsewhere, so its
own value as evidence is narrow and specific: **every label on it is a translation key**. The test
therefore asserts the raw identifiers — `store_settings`, `store_language_and_currencies`,
`store_information`, `store_design` — are **not** on screen in either language. That is a stronger
claim than "some Arabic appeared": a missing key in this framework renders as the identifier
itself, so this is the assertion that would catch it.

**Customer detail** shows a customer beside that customer's orders, so it needs two controllers.
It also calls `clearCustomerOrders()` **synchronously in `initState`**, before its post-frame
fetch — so a response that fails to parse leaves an empty list that looks exactly like a customer
who has never ordered. A premise drives the genuinely-empty case and requires the customer still be
identified on screen, because otherwise a merchant cannot tell the two states apart.

## One harness-usage trap, measured

`StoreSettingScreen` is a `StatelessWidget` whose **field initialiser** calls `Get.put`, and that
controller's `onInit` reaches `StoreChangeService.instance`. A field initialiser runs at
**construction**, so building the widget in the test's argument list ran it before `pumpScreen` had
registered that service, and the screen threw `"StoreChangeService" not found`. Constructing it
inside a `Builder` puts it back in order — the same shape as the ScreenUtil trap already recorded
for `transactionCard`.

## Captures and tests

`after/` holds **8 PNGs** at 360×800, all distinct: two screens × four combinations.

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1746 passed**.
