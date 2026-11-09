# P2.5-FLUTTER-STORE-SETTINGS — one screen verified, one held for owner copy

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`

The two screens a merchant configures their store from, and the two that share
`StoreSettingController`. Both captured in four combinations by
`test/screens/store_settings_capture_test.dart`. **Only one is claimed.**

---

## 1. Why only one

| screen | Arabic build | state |
|---|---|---|
| Store language & currencies | `لغة المتجر والعملات`, `اللغات`, `العملات`, `أسعار الصرف` — complete | **REDESIGNED AND VERIFIED** |
| Store information | heading `معلومات المتجر` over field labels reading **Store Name**, **Email**, **Store URL**, **Created At**, **Owner Information**, **Owner Name**, **Owner Email** | **held at PENDING** |

A screen whose Arabic build is a third English is not Arabic-complete, and marking it verified
would be the same error as claiming the products list while it shows no prices.

**This is not a new finding and it is not reopened here.** P2.5-B-12 slice one already converted
the nine labels whose Arabic existed, and recorded the rest in
`StoreSyria_app98/docs/development/phase-2.5/COPY-DECK-UNTRANSLATED-SCREENS.md` with the reason:
`'key'.tr` renders **the raw identifier** when the key is absent, so converting a label whose
Arabic does not exist replaces a readable English word with `store_loyalty_program`. The deck is a
request for copy. It is the owner's, and it is listed among the outstanding decisions.

What this package adds to it is the thing it did not have: **the captures**. The deck listed
strings and line numbers; it now points at the Arabic screen itself and at the sibling screen whose
Arabic is complete, so the copy can be written against the real layout. Store information is
therefore waiting on one empty column — not on engineering and not on evidence. When that column is
filled, converting and promoting it is a small change.

---

## 2. Two things the tests pin

* **The store-info gate accepts BOTH status shapes** — `status == 'success' || status == 1`
  (`store_setting_controller.dart:204`), which is unusual in this API, where every other handler
  gates on the int alone. A dedicated premise drives both, so the fixtures are not relying on an
  accident and a server switching between them cannot silently blank the screen.
* **Language & currencies fans out across three endpoints** in `initState` —
  `getCurrenciesData`, `getStoreCurrencyLangData`, `getCurrencyRatesData`. A fixture answering only
  the first leaves the rest on the stub's empty fallback, so nothing crashes and nothing shows: the
  screen captures as finished-with-no-data. The test asserts two of the three were really called
  and that the currency list parsed into two options.

`getCurrenciesData` also short-circuits on a cache that is still inside its 24-hour window, under
the `cache_*` prefix `resetScreenHarness` clears — so each case really fetches rather than reading
the previous test's currencies.

---

## 3. Captures and tests

`after/` holds **8 PNGs** at 360×800, all distinct — including the four for Store information,
which are kept although the row is not claimed, because they are the evidence the copy deck now
cites.

| file | tests | what it is |
|---|---:|---|
| `test/screens/store_settings_capture_test.dart` | 9 | both screens in four combinations, plus the both-status-shapes premise |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1721 passed**.
