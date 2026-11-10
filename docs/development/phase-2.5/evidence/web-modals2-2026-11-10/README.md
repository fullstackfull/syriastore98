# Phase 2.5 — the modals the route sweep left PENDING (2026-11-10)

Three surfaces attempted, two captured, and the third turned out not to be a merchant surface at
all. The attribute edit capture then found a defect in every `.ss-field` form in the admin.

## Captured

| slug | surface | note |
|---|---|---|
| `attribute-create__` | `/product-attributes` → create modal | |
| `attribute-edit__` | `/product-attributes` → edit modal | the missing half — the inventory row covers create **and** edit, and an earlier wave deliberately declined to claim it on the create capture alone |
| `_manage_language_en__` | `GET /manage-language/en` | the merchant's language screen, 4/4 clean, titles "Language" / "اللغة" |

## The required marker sat on a line of its own — FIXED, 15 sites

The Arabic and English edit captures both showed the red `*` **below** the field label rather than
beside it.

**Cause.** `.ss-field` is `display: flex; flex-direction: column`
(`store-syria-ds.css:921`). Where a view writes the marker as a **sibling** of the label —

```blade
{{ Form::label('name', __('Name'), ['class' => 'ss-label']) }}<span class="ss-label__required" …>*</span>
```

— the label and the span are two flex items, so each gets its own row with the field's `gap`
between them. `.ss-label__required { margin-inline-start: 2px }` was written for an inline sibling
and never applied.

**Scope, measured rather than estimated.** 176 markers exist across the views. 155 sit in a
`form-group` or similar block wrapper and flow inline correctly. 5 are already written *inside*
the `<label>` (`customer/create`, `components/ss/secret-field`) and were correct. **15** were
siblings inside an `.ss-field`:

`users/create` (4), `users/edit` (3), `users/reset` (2), `attributes/create`, `attributes/edit`,
`attribute_option/create`, `attribute_option/edit`, `setting/chatgpt_setting`,
`setting/whatsapp_msg_setting`.

**Fix.** Wrap the label and its marker in `<span class="ss-label-line">`, which is one flex item
whose children lay out inline again. `.ss-field`'s flex semantics are untouched for every other
consumer, and the forms that were already correct were not edited.

### Why it was not caught before

`measure-required-marker-live.cjs` already asserts, in its own words, *"The marker must sit beside
its own label, not wrap to a line of its own"* — and it reported **0 problems**. Its three
surfaces were the tag, role and coupon modals, and all three use `<div class="form-group">`, a
plain block where a sibling span flows inline. The assertion was right; the sample contained no
`.ss-field`, so it could not fail.

The tool now drives `/product-attributes` and `/users` as well. Proved in both directions:

| | markers measured | problems |
|---|---:|---:|
| extended sample, fix reverted | 44 | **20** |
| extended sample, fix applied | 44 | **0** |

`Tests\Unit\Ui\RequiredFieldMarkerTest` (7 cases) still passes, as do Unit (1579) and Contract (38).

## Not captured — and not a Phase 2.5 surface

`language-create` reported *"no visible create trigger"*, which was true and correct.
`language/index.blade.php:6` opens `@if (auth()->user()->type == 'super admin')` around the whole
`action-button` block, so **a merchant never sees the Create Language control** — even though
merchants do hold the `Manage Language` permission (`Utility::addNewData()` puts it in the `admin`
role's list at :2908; the super-admin list is separate at :3295). The create modal is a Phase 06
surface.

Two further measurements, neither a design issue and neither actioned:

* The inventory row names `GET /languages`. **That route is not registered** — it answers 404.
  The real one is `manage-language/{lang?}/{module?}` (`routes/web.php:1382`).
* `GET /manage-language` with **no** language segment answers **HTTP 500** —
  `file_get_contents(resources/lang/General.json): No such file or directory`. Every caller passes
  a language (`header.blade.php:410` passes `auth()->user()->language`, a NOT NULL column
  defaulting to `'en'`), so it is reachable only by typing the URL.

## Fixture note

`Manage Language` was added to `grant-dev-harness-permissions.sh`. Checked before granting, since
granting a permission a merchant does not really hold would verify a screen no merchant can open:
it is in the `admin` role's own permission list, so the dev fixture was simply missing its own
guard.
