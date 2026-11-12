# P2.5-CLOSURE — the email template screens

`tools/render-matrix.cjs`, two paths × 4 combinations = **8 runs, 0 not clean**.

| path | what it is |
|---|---|
| `/email_template_lang` | the template list (`EmailTemplateController::emailTemplate`) |
| `/email_template_lang/13/ar` | one template's per-language editor (`manageEmailLang`) |

Both answered HTTP 200 with the right direction and the right theme in every combination.

---

## The second path was wrong, and the wrongness was invisible in the pictures

**The first version of this directory used `/email_template_lang/12` and this README called it
`manageEmailLang`. It never reached that handler.** Measured through the real router:

```
/email_template_lang        -> EmailTemplateController@emailTemplate   params=[]
/email_template_lang/12     -> EmailTemplateController@emailTemplate   params={"lang":"12"}
/email_template_lang/12/en  -> EmailTemplateController@manageEmailLang params={"id":"12","lang":"en"}
```

`routes/web.php:861` declares `email_template_lang/{lang?}` **before** `:862`'s
`email_template_lang/{id}/{lang?}`, so a single segment is swallowed by the first and `12` arrived as
a LANGUAGE, not an id. Both paths rendered the list's default template, and the eight PNGs were
**four byte-identical pairs**.

Every premise this harness checks passed: HTTP 200, correct `dir`, correct `data-ss-theme`, a real
title. "8 rows, 0 not clean" was true and meant nothing about whether two screens had been
photographed. What caught it was `check-distinct-surface-captures.py`, the project's own
identical-pixel detector, which went from 2 collisions to 6 — and those four were these.

**Why `/email_template_lang/13/ar` and not `/email_template_lang/12/en`.** The corrected two-segment
URL reaches the right handler, but `manageEmailLang` renders the same `email_templates.show` view,
and `emailTemplate()` defaults to `EmailTemplate::first()` — which is template 12. So id 12 in
English is still the same screen. A **different template and a different language** is what makes the
second capture a different picture, verified by md5 in all four combinations.

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
