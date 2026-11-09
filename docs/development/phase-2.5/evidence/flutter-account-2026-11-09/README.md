# P2.5-FLUTTER-ACCOUNT — the three screens the copy deck unlocked, and what the deck missed

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`
**Flutter commit:** `993f149`

Profile / My Account, Store email settings and the Loyalty programme. All three were held back for
the same reason Store Information was: their labels were **hard-coded English literals**, so an
Arabic merchant read an English form. That was recorded in `COPY-DECK-UNTRANSLATED-SCREENS.md`
rather than patched, because a `.tr` lookup on a key with no copy renders the raw identifier and
would have made the screen worse than it was.

The owner authorised the copy in the Phase 2.5 final directive. These are the first captures of
all three screens in either language.

---

## 1. The capture found what the deck missed

With all 68 deck strings translated, the Arabic profile screen **still showed three English
strings** in its header card:

* `Hello, <name>`
* `Manage your account and profile`
* `Change Profile Photo`

None of the three was in the deck. The deck was built by **reading the files**, and these were
missed — which is the argument for capturing a screen rather than trusting a string inventory.

One of them, `Change Profile Photo`, **already had Arabic** — `تغيير الصورة الشخصية` was in
`ar.dart` all along and the screen simply did not use it. So the established value was kept and the
new duplicate dropped. The other two were written and added.

The greeting could not become a key: an interpolated string differs per merchant, so the **word**
is translated and the name interpolated around it. In Arabic the greeting precedes the name the
same way, so no reordering was needed.

### The premise test this produced

Each case asserts a label that came out of the deck, in whichever language the combination asked
for. The premise goes further and asserts the **English spelling is ABSENT** from the Arabic build,
not merely that the Arabic is present — a screen showing both would mean the conversion reached
only some of its sites, which is exactly what the header card was doing.

---

## 2. What the captures show

| screen | Arabic build |
|---|---|
| Profile / My account | `حسابي` · `مرحباً، سامر الحلبي` · `إدارة حسابك وملفك الشخصي` · `تغيير الصورة الشخصية` · `معلومات الحساب` · `الاسم الكامل` · `احفظ التغييرات` · `الأمان` |
| Store email settings | `إعدادات بريد المتجر` · `مزود البريد:` · `خادم البريد:` · `منفذ البريد:` · `اسم مستخدم البريد:` · `كلمة مرور البريد:` · `تشفير البريد:` · `البريد المُرسِل:` |
| Loyalty programme | `برنامج ولاء المتجر` |

**Mail configuration VALUES are never translated** — a merchant copies `smtp`, a host name and a
port from their provider, and translating them would break the setting. Only the labels moved.

---

## 3. Captures and tests

`after/` holds **12 PNGs** at 360×800, all distinct: three screens × four combinations.

| file | tests | what it is |
|---|---:|---|
| `test/screens/account_settings_capture_test.dart` | 13 | three screens in four combinations, each asserting a deck label by name, plus the Arabic-only premise |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1737 passed** (1724 before).
