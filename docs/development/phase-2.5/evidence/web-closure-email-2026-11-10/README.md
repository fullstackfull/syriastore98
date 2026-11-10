# P2.5-CLOSURE — the email template screens

`tools/render-matrix.cjs`, two paths × 4 combinations = **8 runs, 0 not clean**.

| path | what it is |
|---|---|
| `/email_template_lang` | the template list (`EmailTemplateController::emailTemplate`) |
| `/email_template_lang/12` | one template's per-language editor (`manageEmailLang`) |

Both answered HTTP 200 with the right direction and the right theme in every combination.

---

## The row was PENDING because the screen answered HTTP 500 on an empty database

`emailTemplate()` took `EmailTemplate::first()` and `$currEmailTempLang` without guarding either,
so a store with no templates — or a template with no language row — dereferenced `null` and the
screen was a white error page rather than an empty state. Two null guards now return the list with
a plain message instead.

**`tests/Feature/Merchant/EmailTemplateEmptyStateTest.php`** pins both, and the way its fourth case
is written is worth knowing about. The "populated" case asserts `assertNull(session('error'))`
rather than a 200, because a bare `User::factory()` row has **no store** and
`partision/header.blade.php` then 500s on `getStoreById(getCurrentStore())` — the same condition
behind this project's declared accepted failure `ProfileTest::test_profile_page_is_displayed`.
Asserting a 200 there would have failed for a reason that has nothing to do with this guard.

The fixture carries 11 templates and 22 language rows, seeded by `seed-dev-closure-rows.php` with
the platform and merchant template names taken from the production dump, so the captures show the
screen a real merchant sees rather than a single invented row.

## Reproducing

```bash
docs/development/phase-2.5/tools/serve-dev.sh
```

```bash
node docs/development/phase-2.5/tools/render-matrix.cjs --out <dir> --paths "/email_template_lang,/email_template_lang/12"
```
