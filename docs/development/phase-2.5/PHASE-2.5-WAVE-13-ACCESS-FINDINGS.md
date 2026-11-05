# Wave 13 — STOPPED BEFORE IMPLEMENTING, and why that is the right outcome

**Date:** 2026-11-05 · measured on the running application, before any edit was made.

Wave 13 was to wrap §2 rows 7–11 (customer orders, store links, custom domain, subdomain, email
templates) into `.ss-dt-card-body` so P2.5-WAVE-1's 51 DataTable rules reach them. Unlike rows 1–6
these carry the `dataTable` CLASS, so `custom.js:12` really does initialise them — `.ss-dt-card-body`
is the correct treatment, not `.ss-table`.

**The before-capture was taken first, and it refuted the premise for four of the five rows.**
`evidence/wave-13-access-findings/render-matrix.json` is that reading (16 rows, 4 HTTP 500).

## 1. `store/custom_domain` and `store/subdomain` are OPERATOR screens — Phase 06, not 2.5

`StoreController::customDomain()` opens with:

```php
if (auth()->user() && auth()->user()->type == 'super admin') {
```

A merchant (`type = 'admin'`) falls through and is redirected: both paths answered **HTTP 200 with
the title "Dashboard" / "لوحة التحكم"**, not the screen. So **§2 rows 9 and 10 are not merchant
surfaces at all.** They belong with the exclusions §2 already records for `country/index.blade.php`
(operator-gated) and `order/show.blade.php` (plan-purchase gateway) — Phase 06 by the approved plan.

This is a correction to the work-list, not a blocker: wrapping them would have been out-of-scope
work on a screen no merchant can open, and claiming them "verified" from a dashboard screenshot
would have been a false reading of exactly the kind the directive forbids.

## 2. `customer.show` (row 7) — a DEV-FIXTURE permission gap, not a product defect

`CustomerController::show()` requires `isAbleTo('Show Customer')`. Measured on the fixture: the dev
merchant's role carries **50 permissions including `Manage Customer` but NOT `Show Customer`**, so
`/customer/1` redirects to the dashboard. The screen is a real merchant surface; the fixture simply
cannot open it. Granting that one permission to the dev role (the precedent is
`tools/grant-dev-finance-permissions.php`) unblocks it.

## 3. `/email_template_lang` answers HTTP 500 — a latent null-guard defect

`EmailTemplateController::emailTemplate()`:

```php
$emailTemplate     = EmailTemplate::first();
$currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $emailTemplate->id)...
```

`EmailTemplate::first()` returns **null** when the table is empty, and `$emailTemplate->id` then
throws. The fixture has no `email_templates` rows, so every combination answered 500. In production
the installer seeds them, so this is **latent rather than live** — but it is a real missing guard on
a merchant-facing screen, and `first()` carries no store predicate either.

**It is also the wrong route for row 11.** That route renders `email_templates.show`; §2 row 11 is
`email_templates/index.blade.php`, reached by a different path which still has to be identified.

## What was deliberately NOT done

No `.ss-dt-card-body` edit was applied to any of the five. Four of the five could not be rendered,
and the directive is explicit that a screen is not verified without rendering evidence — so applying
the class would have produced an unverifiable change and, for rows 9 and 10, an out-of-scope one.

`.ss-dt-card-body` itself was checked and is **safe to apply anywhere**: all three of its
own-element rules declare only custom properties (`--ss-dt-card-bg`, `--ss-dt-tool-*`,
`--ss-dt-ident-*`, `--ss-dt-head-*`, `--ss-dt-rule`, `--ss-dt-empty-*`), with 47 descendant rules
consuming them. So the treatment is correct; only the access is missing.

## Next

1. Grant `Show Customer` to the dev role, re-render `customer.show`, then wrap row 7.
2. Identify the real route for `email_templates/index.blade.php`, then wrap row 11.
3. Re-scope rows 9 and 10 to Phase 06 in the work-list.
4. Row 8 (`store/view-storelinks`, a modal with no `.card-body`) still needs a modal-drive capture.
