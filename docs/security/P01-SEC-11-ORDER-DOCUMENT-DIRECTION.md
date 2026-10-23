# P1-SEC-11 criterion 4 — the order documents' direction, language and logo

*Written 2026-10-23, from execution. Every number below is a measurement, and the command that produced
it is named.*

Issue [#78](https://github.com/fullstackfull/syriastore98/issues/78), acceptance criterion 4: **"checked
in Arabic RTL and English LTR on every order document"**. Criteria 1–3 were closed earlier (the
`created_by = 1` address fallback). This is the remainder, and checking it found three defects rather
than a styling preference — one of them outside the order documents entirely.

---

## 1. The direction was never emitted, and the right-to-left stylesheet was dead code

Both **host** order documents opened with a hardcoded tag and no direction at all:

```blade
<html lang="en">
```

`resources/views/order/shippinglabel.blade.php:2` and `resources/views/order/receipt.blade.php:7`.

The shipping label's own stylesheet ends with a block of `html[dir="rtl"]` rules — table cells
right-aligned, `.text-right` flipped, the QR block moved (lines ~156–163 before this change). **That
selector could never match**, because nothing on the page ever set `dir`. So its author's right-to-left
support was dead code, and an Arabic store's shipping label was laid out left to right with Arabic text
in it. The receipt had no right-to-left rules at all and no direction to key them on.

The `lang="en"` is the same defect in the other attribute: an Arabic store's document declared itself
English to every screen reader, every PDF engine and every browser hyphenation and quotation rule.

### What it is now

`App\Services\DocumentDirection` resolves both from **the order's store**, and
`OrderController::shippinglabel()` / `order_receipt()` pass them in:

```blade
<html lang="{{ $documentLocale ?? app()->getLocale() }}" dir="{{ ($documentRtl ?? false) ? 'rtl' : 'ltr' }}">
```

`ltr` is emitted explicitly rather than omitted, because the label's rules key on the attribute being
*present* with a value — an absent attribute is the state the defect was in.

**`SITE_RTL` decides, not the locale**, and the asymmetry is deliberate. The whole application keys
direction on that per-store flag (`resources/views/layouts/app.blade.php:54`), so a store whose language
is Arabic while its `SITE_RTL` is off is laid out left-to-right on every other screen *by its own
configuration*. Making these two documents the only surface that infers direction from the language
would make them disagree with the panel around them. The mismatch is therefore **reported, not
reinterpreted**: `DocumentDirection::languageDisagreesWithFlag()` is what the diagnostic script counts,
so the owner can see how many live stores are in that state before anyone proposes changing which of the
two wins. **This is an owner decision and it is not taken here.**

`localeFor()` reads `stores.default_language`, a **column**, not a setting name —
`GetValueByName('default_language', …)` finds nothing. `App\Providers\ViewServiceProvider:50` reads it
the same way for every other screen.

---

## 2. The logo was the viewer's — criterion 1's remainder

Both views fetched the logo themselves:

```blade
{{ get_file(\App\Models\Utility::GetValueByName('invoice_logo')) }}
```

with **no store id**, which resolves `getCurrentStore()` — the **viewer's** store. Criterion 1 fixed the
five address names in the controller and did not touch this line, so the platform operator printing a
merchant's label or receipt put **the operator's own logo** on another store's document. Measured, at
HTTP 200, on both documents:
`tests/Feature/Security/ShippingLabelStoreSettingsTest::test_the_documents_print_the_orders_stores_logo_and_not_the_viewers`.

`DocumentDirection::invoiceLogoFor($orderStore)` is now the single resolution for both.

### 2a. And the fallback behind it was an undefined variable

When that value came back empty, both templates fell back to

```blade
src="{{ $profile . '/logo.png' }}"
```

with **`$profile` defined nowhere** — not by the controller, not by a view composer, not shared; grepped
across the host app. So an empty `invoice_logo` turned both documents into a 500 with
`Undefined variable $profile`.

**The first defect is what kept this one latent**: reading the *viewer's* store almost always found a
row, so the empty branch was rarely taken. Fixing the store id alone would therefore have traded a wrong
logo for a dead document. The fallback is resolved in the service instead, to the same path
`Utility::Setting()`'s own defaults use for `invoice_logo` (`app/Models/Utility.php:53`), and the dead
expression is gone from both templates.

Which state reaches that fallback is worth stating, because it is not the obvious one:
`GetValueByName()` falls through to `Utility::Setting()`'s defaults when the row is **absent**, so a
store with no `invoice_logo` row never sees an empty value. A row that **exists and is empty** is
`isset()`, so it is returned as `''` — and that is what the settings form writes when no file is chosen.
Both states are asserted.

---

## 3. Found while fixing the above: `get_file()` answered `''` for every path

Not an order-document defect. `app/Helper/helper.php`'s `get_file()` turns a stored relative path into a
URL and is called from the shared layout partials, so it runs on essentially every panel page. It
selected its storage branch with

```php
if (isset($settings) && (count($settings) > 0) && $settings['storage_setting'] == 'wasabi')
```

where `$settings` is **every** `settings` row of the **super admin's** current store. `count() > 0` and
"`storage_setting` is present" are different questions: one row of any other name satisfies the count.
PHP 8 then raises `Undefined array key`, Laravel's error handler promotes the warning to an
`ErrorException`, and `get_file()`'s own `catch (\Throwable) { return ''; }` swallows it — so the
function returns an **empty string** rather than the local URL its `else` limb exists to produce. Every
logo, avatar, product image and invoice mark on the page resolves to `src=""` at once, silently, with
nothing in the log.

Measured directly, in one test, before and after inserting a single `storage_setting` row:

```
with only invoice_logo on the super admin's store: get_file('uploads/2/X.png') = ''
with storage_setting=local added:                  get_file('uploads/2/X.png') = 'http://localhost/uploads/2/X.png'
```

`storage_setting` is `NOT NULL` nowhere, the settings defaults in `Utility::Setting()` do **not** reach
this read (it queries the `settings` table directly, not through `Utility`), and the storage screen
writes the row only once storage is configured. So a platform whose operator has never opened that
screen — while having any other setting row at all — is in exactly this state. **This is availability,
not confidentiality**, and it is the third sibling of the two null guards already recorded in the same
function.

The fix reads the key with `?? ''` and compares with `===`. It is byte-identical wherever the key exists,
and an unset value now takes the local limb, which is what an unset `storage_setting` means.
`tests/Feature/Security/StoredFileUrlResolutionTest.php` holds it, including both remote branches so the
guard could not have been written as a blanket `local`.

---

## 4. What was executed

```
php artisan test tests/Feature/Security/ShippingLabelStoreSettingsTest.php     11 passed (58 assertions)
php artisan test tests/Feature/Security/StoredFileUrlResolutionTest.php          8 passed (12 assertions)
```

**Removal checks — 12 mutants, 12 bite.** `docs/development/phase-1/tools/mutation-harness.py`:

| | mutant | outcome |
|---|---|---|
| M1 | label: `dir` attribute removed | BITES (4 failed) |
| M2 | receipt: `dir` attribute removed | BITES (3 failed) |
| M3 | label: `lang` hardcoded back to `en` | BITES (2 failed) |
| M4 | receipt: `lang` hardcoded back to `en` | BITES (2 failed) |
| M5 | `isRtl()` always answers left-to-right | BITES (2 failed) |
| M6 | `isRtl()` ignores the store id (reads the viewer's) | **SURVIVED**, then BITES |
| M7 | `localeFor()` hardcoded to `en` | BITES |
| M8 | `invoiceLogoFor()` reads the viewer's store | BITES |
| M9 | `invoiceLogoFor()` loses its platform-default fallback | **SURVIVED**, then BITES |
| M10 | the label handler resolves all three from the viewer's store | BITES |
| M11 | `get_file()`'s undefined-key guard removed | BITES (2 failed) |
| M12 | `get_file()`'s guard defaults to `wasabi` instead of local | BITES (3 failed) |

**The two survivors were real test gaps, and they are the useful part of this record.**

* **M6** survived *nine* passing tests. Every direction test had the order's **own merchant** as the
  viewer, so viewer store and order store were the same row and the argument could be dropped without
  any of them noticing — the recorded "a presence test is not an identity test" trap, in its tenancy
  form. Closed by `test_the_direction_and_language_come_from_the_orders_store_and_not_the_viewers`,
  where the operator's store is `off`/`en` and the order's is `on`/`ar`.
* **M9** survived because the test that was *meant* to reach the fallback used a store with **no**
  `invoice_logo` row, and `GetValueByName()` answers such a store from `Utility::Setting()`'s defaults —
  so the fallback was never on the path. Closed by
  `test_a_store_whose_invoice_logo_row_is_empty_still_renders_an_image`.

Both are the project's own rule paying for itself: the fix was already correct, and without the removal
check two of its controls would have been untested.

### The census, and what it does not cover

`test_no_host_order_document_hardcodes_its_language_or_omits_its_direction` asserts that **every** host
template under `resources/views/order/` that opens its own `<html>` takes both attributes from the
document's data, and that the number of such templates is exactly **2**. A different number fails the
test rather than passing vacuously.

**Vendored module order documents are not covered and cannot be**, by the project's own rule: files
under `packages/workdo/` are not editable here. Any module that prints its own order document still
emits whatever it emits. That is the residual this criterion closes with, and it is stated rather than
implied.

Two traps hit while writing that census, both recorded because they are reusable:

* the first cut matched `/<html\b[^>]*>/`, which **stops inside a Blade expression** —
  `app()->getLocale()` contains a `>` — and reported the corrected tag as carrying no `dir`. It now
  matches to the end of the line.
* it then matched the explanatory **Blade comment** each template now carries, which quotes the old
  `<html lang="en">` in order to explain it. Blade comments are stripped first. This is the recorded
  "a docblock quoting the string a ratchet greps for makes the ratchet satisfiable by prose" trap, in
  its inverse direction: here the prose made a **correct** file fail.
