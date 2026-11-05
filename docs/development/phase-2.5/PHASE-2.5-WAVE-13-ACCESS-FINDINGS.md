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

## RESOLVED, same day

**Row 7 is DONE.** `tools/grant-dev-screen-permissions.php` (the general form of the finance grant
tool) granted `Show Customer` to the dev role; `/customer/1` then answered **HTTP 200 titled
"Orders" / "الطلبات"** instead of the dashboard, and the table was wrapped into
`.ss-dt-card-body` -- the wrapper now matches `components/datatable.blade.php:37` exactly. All four
combinations captured in `evidence/wave-13/`, 0 not clean. The Arabic RTL-dark capture shows the
full Wave-1 treatment arriving at once: sorted header band, row rules, design-system status chips,
brand-blue order identifiers, aligned money and the pinned action column.

**Row 11 is a DEAD VIEW and is struck, not deferred.** `EmailTemplateController` returns
`view('email_templates.show', ...)` at :37 and :56 and nowhere else; a tree-wide search for
`email_templates.index` / `email_templates/index` outside `vendor/` returns **zero** hits, and no
`@include`/`@extends` pulls it in. `resources/views/email_templates/index.blade.php` is therefore
unreachable -- the same category as the `product/sku_combinations_edit.blade.php` exclusion §2
already records. Wrapping it would style a screen no merchant can open.

(The HTTP 500 above stands on its own as a finding about `email_templates.show`, which IS reachable:
`EmailTemplate::first()` is null-dereferenced on an empty table and carries no store predicate.)

## Row 8 is Phase 06 as well -- settled by the codebase's own comment

`store/view-storelinks` is reached only through `store/index.blade.php:94` and
`store/action.blade.php:32`, and **`StoreController::index()` opens with
`type == 'super admin'`** exactly as `customDomain()` does. The `StoreLinks()` method carries a
comment that states the family outright (NEW-STORELINKS-UNGATED-01):

> "It is the only one in this **platform-operator family** without a `type == 'super admin'` test --
> upgradePlan (:813), activePlan (:826), customDomain (:867), subDomain, index (:44) and
> LoginWithAdmin ... all have one ... the only screens that mint one
> (store/index.blade.php:94 and store/action.blade.php:32) are themselves **super-admin-only**
> because StoreController::index refuses anyone else."

So rows **8, 9 and 10 are one operator family**, not three merchant tables, and no fixture grant
would make them merchant surfaces. They go to Phase 06 together.

## Net result for §2

Of the five rows this wave was scoped to:

| row | surface | outcome |
|---|---|---|
| 7 | customer orders | **implemented and verified** |
| 8 | store links modal | Phase 06 (operator family) |
| 9 | store custom domain | Phase 06 (operator) |
| 10 | store subdomain | Phase 06 (operator) |
| 11 | email templates index | **dead view**, struck |

**One of five is a merchant table.** §2's headline of "20 merchant tables on 20 render sites" is an
overcount: it classified by markup and parent chain, which were read correctly, but not by whether
a merchant can reach the screen at all. The remaining rows (roles, WooCommerce, Shopify) should be
access-checked the same way *before* any of them is implemented, not after.

## Also noticed, not acted on

`routes/web.php` declares **`Route::resource('stores', StoreController::class);` twice**, identically
(two separate lines in the same group). CLAUDE.md records this shape as a live hazard -- "later route
declarations REPLACE earlier ones for the same method+URI, silently dropping the earlier
middleware". Both copies sit in the same group here so the practical effect looks nil, but a
duplicate resource declaration is exactly the construct that makes a middleware change silently
ineffective later. Recorded for the routing cleanup, not changed here.

## Still next

1. Access-check the remaining §2 rows (roles done; WooCommerce and Shopify not yet) before
   implementing them.
2. Decide whether the `email_templates.show` null guard is in scope for a later wave.
