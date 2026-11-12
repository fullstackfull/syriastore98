# P2.5-CLOSURE — the AJAX create and edit modals, complete set

`tools/shoot-modals.cjs`, **28 surfaces × 4 combinations = 112 captures, 0 findings.**

Eight of those surfaces were the last modal rows the coverage matrix still carried as PENDING:
`flash-sale-edit`, `faq-edit`, `users-edit`, `users-resetpassword`, `webhook-edit`, `pixel-edit`,
`attribute-option-create`, `attribute-option-edit`. The other twenty re-verified unchanged in the
same run, which is why the whole set is here rather than only the new eight — a modal whose
container, theme or label rules moved should be seen again, not assumed.

"0 findings" is measured under the **strict accessible-name rule**: a `placeholder` is no longer
accepted as a control's name. That rule was tightened in this wave and its blast radius was
measured at exactly one field before being applied, so the zero is not a weakened test.

---

## Why each of the eight was PENDING, and it was never the design

| surface | what was actually wrong |
|---|---|
| `users-edit` | the trigger is an `<a>` inside a per-card `.dropdown-menu` (`users/index.blade.php:106-122`); the harness assumed a `<button>` outside any menu and reported "no visible edit trigger" for a control that was always there. 11 triggers matched on `/users` and every one was rejected. |
| `users-resetpassword` | the URL is `/store-reset-password/<encrypted>`, not `/reset-password/`, so neither default pattern matched. |
| `attribute-option-create` / `-edit` | they live on `/attribute-option/{id}`, not `/product-attributes`. |
| `webhook-edit`, `pixel-edit` | they are settings-hub tabs, so the surface needs a `tab:` as well as a route. |
| `flash-sale-edit`, `faq-edit` | the fixture had no rows to edit until `seed-dev-closure-rows.php` and `seed-dev-catalogue-rows.php` seeded them. |

The harness now discriminates triggers by **`data-url`** rather than by tag, and **opens a row
menu** when nothing is exposed — excluding the shell's menus by LOCATION (`nav, header, .ss-topbar`)
rather than by being a `.dropdown-menu`, which is what actually distinguishes them.

## Two defects fixed in the surfaces themselves

**FAQ edit answered HTTP 500.** `faq/edit.blade.php` read `$item['question']` and `$item['answer']`
unguarded out of free-form JSON. A row carrying only one of the two raised "Undefined array key" →
ErrorException → 500, and the modal opened showing its title and nothing else. Measured twice.
Production ships **zero** `faqs` rows, so this is a fail-safe rather than a live repair — but an
import reaches it, and a blank field a merchant can fill is the right answer to a missing key.

**Four label-association defects and two unlabelled selects.** `Form::label('Platform', ...)`
emits `for="Platform"` while the control's generated id is `platform`, so the label pointed at
nothing — same in `pixel/create`, `pixel/update`, `webhook/create`, `webhook/edit`, plus a missing
`Coupon Code` label and two unlabelled condition selects on the flash-sale editor. One Arabic
mistranslation went with them: `"Url": "الرمز"` ("the code") → `"الرابط"` ("the link").

## Two harness false positives, corrected rather than worked around

* **`choices__button` at 1.37:1 was not a contrast failure.** The control is a background-image SVG
  with `text-indent: -9999px` (`public/assets/css/style.css:21960`); the "text" measured is
  off-screen by design. The harness now skips text hidden that way.
* **A failed screenshot used to be silent.** `page.screenshot(...).catch(() => {})` with the tally
  running regardless meant a refused clip produced `combos=4` and no file. Found in
  `shoot-fragments.cjs` (see the sibling README) and fixed here too.

## Reproducing

```bash
docs/development/phase-2.5/tools/serve-dev.sh            # 127.0.0.1:8899
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file SESSION_DRIVER=file \
  php docs/development/phase-2.5/tools/seed-dev-closure-rows.php
node docs/development/phase-2.5/tools/shoot-modals.cjs --out <dir>
```

`--remove` on the seeder reverses every row it created.
