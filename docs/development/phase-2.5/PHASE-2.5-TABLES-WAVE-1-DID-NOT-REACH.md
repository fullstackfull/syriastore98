# The merchant tables Wave 1 did not reach — and a correction

**Measured 2026-11-05** by `docs/development/phase-2.5/tools/census-table-wrapper.py`.

## The correction first

P2.5-WAVE-1 is committed under the title *"the merchant list screen, which is ~100 of the 295
surfaces"*, and I repeated "~100 screens" several times while reporting it. **That number was
never measured.** It was inferred from the inventory's area sizes — 38 catalogue surfaces, 38
settings surfaces, plus products, orders and customers — on the assumption that all of them
render through the shared `x-datatable` component.

Measured, they do not:

| | |
|---|---|
| merchant Blade files that render a table at all | **85** |
| reached by Wave 1 (the `x-datatable` component, or the wrapper written inline) | **39** |
| not reached | **46** |

The commit title cannot be corrected in place — this project does not rewrite pushed history —
so it is corrected here and in the Arabic report. **Wave 1's shared-component changes reach 39
merchant Blade files.** That is still the highest-leverage change available in the admin, and it
is less than half of what I claimed.

Note what the 39 and the 85 are counting: **Blade files**, not inventory surfaces. One file can
back several inventory rows (a list screen reached by more than one route), and one inventory row
can be a component rather than a file. The 295-surface inventory and this census are measuring
different things, and no honest ratio between them has been computed. That is why the coverage
matrix still counts **only screens that were actually rendered and captured** — five — rather than
crediting Wave 1 with everything a shared component touches.

## Of the 46, twelve are correctly outside the pattern

These are not misses and must not be "fixed":

| file | why it is out |
|---|---|
| `email/productMail`, `email/test_mail` | a table used for **email layout**; it must not follow the app theme |
| `order/receipt`, `order/shippinglabel`, `pos/printview` | **printed documents** — correctly always-light, like the Flutter app's `invoice.dart` |
| `components/ss/bar-chart`, `reports/chart_data` | charts, not data tables |
| `roles/create`, `roles/edit` | a **permission matrix** — a checkbox grid, not a list |
| `country/filter`, `country/tab`, `state/filter` | fragments included by a screen that is itself wrapped |

## The 34 genuine misses

Ordered by what a merchant loses.

### Finance — money tables, and the directive names financial summaries specifically (3)

    resources/views/finance/index.blade.php
    resources/views/finance/unreconciled-captures.blade.php
    resources/views/finance/withdrawals.blade.php

These get none of Wave 1's tabular figures, none of its end-aligned money column, and none of its
row rule in dark mode — on the screens where the numbers matter most.

### Merchant list screens (13)

    resources/views/country/index.blade.php
    resources/views/dashboard.blade.php
    resources/views/dashboard/overview.blade.php
    resources/views/delivery-syria/index.blade.php
    resources/views/email_templates/index.blade.php
    resources/views/product/sku_combinations_edit.blade.php
    resources/views/setting/currency_setting.blade.php
    resources/views/setting/pixel_field_setting.blade.php
    resources/views/setting/webhook_setting.blade.php
    resources/views/store/custom_domain.blade.php
    resources/views/store/subdomain.blade.php
    resources/views/store/view-storelinks.blade.php
    resources/views/theme_analytic.blade.php

### Import previews — merchant-facing lists of records about to be imported (9)

    resources/views/shopify/{category,coupon,customer,product,sub_category}.blade.php
    resources/views/woocommerce/{category,coupon,customer,product}.blade.php

### Line-item tables inside a record screen (7)

    resources/views/customer/view.blade.php
    resources/views/order/order_show.blade.php
    resources/views/order/partials/preview.blade.php
    resources/views/order/refund_details.blade.php
    resources/views/order/show.blade.php
    resources/views/order/view.blade.php
    resources/views/plan-coupon/show.blade.php

An order's line items are a money table on the screen a merchant opens most often after the list.

### POS (2)

    resources/views/pos/create.blade.php
    resources/views/pos/index.blade.php

## Why this is not simply "add the wrapper to 34 files"

Wave 1's rules are scoped to `.ss-dt-card-body` **on purpose** — unscoped they reach every table in
all 70 vendored WorkDo modules, which `DesignSystemStylesheetScopeTest` refuses. Adding the class
to a file is therefore an opt-in, and each one needs checking rather than a sweep:

- a **detail-screen** line-item table has different needs from a list: no bulk-delete, no column
  visibility, and its "identifier" column is a product name rather than a record id;
- the **import previews** render records that do not exist yet, so row actions and the pinned
  actions column have nothing to point at;
- several of these are not DataTables at all but hand-written `<table>` markup, so the toolbar and
  pagination rules have nothing to attach to.

The finance three are the clearest candidates: they are real lists of money and want exactly what
Wave 1 gives. They are the next slice.
