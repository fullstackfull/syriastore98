# Phase 2.5 — web route sweep, 2026-11-10

`render-matrix.cjs` over **32 PENDING web routes x 4 combinations = 128 renders**, driven
against the dev fixture on 127.0.0.1:8899, signed in as `merchant@example.test` with a real
store and the thirteen harness permissions granted. 100 PNGs in `after/`; the routes that
never produced a page produced no file.

**The premise check asks whether the CHROME is right** — the observed `dir` matches the
requested one, and the dark sheet is loaded when dark was requested. It deliberately does
not ask whether the page has any content, and one route in this sweep passed it with an
entirely empty body. See *What the sweep found* below.

## Result

| outcome | routes | what it means |
|---|---:|---|
| clean in all 4 | 13 | a real page, right direction, right theme, its own title in both languages |
| clean in exactly 1 of 4 | 10 | **the signature of an HTML fragment** — see below |
| never clean | 9 | 500 on this fixture, a permission refusal, or not a route at all |

### Clean in all four (13)

| route | Arabic title it printed |
|---|---|
| `/Brand-order-sale-reports` | Store Syria - تقرير مبيعات العلامة التجارية |
| `/Status-reports` | Store Syria - تقرير حالة الطلب |
| `/category-order-sale-reports` | Store Syria - تقرير فئة المبيعات |
| `/merchant-notifications` | Store Syria - الإخطارات |
| `/order-country-reports` | Store Syria - أفضل مبيعات حسب تقرير البلد |
| `/order-reports` | Store Syria - تقرير المبيعات |
| `/order-reports-data` | Store Syria - تقرير المبيعات |
| `/order/create` | Store Syria - إنشاء طلب |
| `/product-order-sale-reports` | Store Syria - تقرير منتج المبيعات |
| `/profile/edit` | Store Syria - |
| `/reports` | Store Syria - تقارير العملاء |
| `/stock-report` | Store Syria - تقارير المخزون |
| `/stock-reports` | Store Syria - تقارير المخزون |

### Clean in exactly one of four (10) — HTML fragments

Every one of these answered 200 with **no `<html>` element at all**, so the harness read
`dir` as `null` and printed no title. `null != "rtl"` is true, so the direction premise
fails for both Arabic rows; the dark-sheet premise fails for both dark rows. The single
pass is `en-ltr-light`, which succeeds **by coincidence** — the two premises it has to
clear are the two a document fragment happens not to contradict. One clean combination out
of four is therefore not a partial result; it is a positive identification of a fragment,
and these are captured by `shoot-fragments.cjs`, which drives the parent page instead.

- `/brand-order-reports`
- `/category-order-reports`
- `/create-language`
- `/merchant-notifications/feed`
- `/pixel-setting/create`
- `/product-attributes/create`
- `/stock-report/low-stock`
- `/stock-report/most-stocked`
- `/stock-report/out-stock`
- `/webhook/create`

### Never clean (9)

| route | HTTP | cause |
|---|---|---|
| `/languages` | 404 | **not a registered route.** The real one is `manage-language/{lang?}/{module?}`. This path was a bad entry in the PENDING list, not a defect. |
| `/order-reports-chart` | 500 | `ReportController::order_reports_chart` builds raw SQL with `MONTH()`. SQLite has no such function; production is MySQL. |
| `/order-reports-chart-data` | 500 | `order_reports_chart_data` uses `MONTH()` and `YEAR()`. Same cause. |
| `/product-order-reports` | 500 | `order_product_reports` uses `MONTH()`. Same cause. |
| `/refund-request` | 200 | a `redirect()->back()` permission refusal. It landed on `/profile/edit` and so inherited that page's empty title — which is how the blank-page defect below was noticed. |
| `/reports-chart` | 500 | `reports_chart` uses `MONTH()`. Same cause. |
| `/shopify_product` | 500 | needs a connected Shopify store: the controller requires `shopify_setting_enabled`, a store URL and an access token, then calls the Shopify Admin API before returning a view. |
| `/top-all-reports` | 500 | `top_product` joins on `SUBSTRING_INDEX` (:2814, :2826). The controller's own comment at :2883 already records that SQLite cannot run it. |
| `/woocom_product` | 200 | empty body, no `<html>`. Needs a connected WooCommerce store. |

**None of the 500s is a product defect.** Production runs MySQL, where `MONTH()`,
`YEAR()` and `SUBSTRING_INDEX` all exist. They are blocked on the dev fixture's database
engine, and the matrix records them as BLOCKED with that remediation rather than as
failures — and equally, none of them can be called verified, because nobody has seen them.

---

## Correction, 2026-11-11 — `/woocom_product`'s row above is wrong, and its four captures were pictures of another surface

The table says `/woocom_product` answered *"200, empty body, no `<html>`"*. **Re-measured against the
running fixture, every clause of that is false**, and the four PNGs that carried this surface's name
have been removed. They were byte-identical to `_webhook_create__*.png`, which is what
`check-distinct-surface-captures.py` reported as a collision — correctly.

### What actually happens

Both refusal arms of `WoocomProductController::index` end in **`redirect()->back()`**
(`:50` for the integration arm, `:53` for the permission arm). So the browser is sent **wherever it
came from**. Measured, logging in as the fixture merchant and driving it from two different pages:

| driven from | HTTP | landed on | title |
|---|---|---|---|
| `/dashboard` | 200 | `/dashboard` | `Store Syria - لوحة التحكم` |
| `/webhook/create` | 200 | `/webhook/create` | `""` |

**The landing page follows the referrer.** This sweep drove `/webhook/create` immediately before
`/woocom_product`, so the refusal returned to the webhook page and the screenshot photographed it.
The recorded `title: ""` came from the same place: `/webhook/create` is a modal fragment with no
`<title>`, so a page that had never changed read as an empty title — which was then written down as
"empty body".

This is the **`redirect()->back()` trap** that `PHASE-2.5-DEV-FIXTURE-PERMISSIONS.md` already
records, in its most literal form. There it keeps the previous page's legitimate *title*; here it
kept the previous page's legitimate *pixels*.

### Why the captures were not regenerated

Because **this surface has no pixels of its own.** Regenerating would only photograph a different
previous page. The evidence for a surface that refuses before rendering is the *measurement*, and it
is now at `after/_woocom_product__MEASUREMENT.json`, which also records the two gates:
`isAbleTo('Manage Woocommerce Product')` — which the fixture merchant does not hold — and then
`woocommerce_setting_enabled == 'on'`, whose settings row does not exist. Granting the permission
would only reach the second gate.

**The matrix state is unchanged: still BLOCKED, no promotion.** Only its stated reason was corrected.

### `_webhook_create__*.png` is kept, and here is what it is

Those four are genuine pictures of `GET /webhook/create` — but that route returns a **modal body**
(`WebhookController::create()` is `return view('webhook.create')`, a fragment with no layout), so
driven as a page it renders as unstyled browser-default markup on white. `shoot-fragments.cjs`'s own
header states the rule: *"a capture taken anyway is a screenshot of unstyled markup on a white page.
That is not what any merchant sees, so it is not evidence about the surface."*

They are left in place because they are honest pictures of that URL and they back no claim: the
matrix's **Webhooks (create/edit modals)** row is verified from
`evidence/web-closure-2026-11-10/`, where the modal is opened in its real context. Read these four
as a record of what the sweep did, not as evidence of the surface.
