# Phase 2.5 — the BLOCKED rows, reviewed one at a time (2026-11-10)

The owner asked which of the BLOCKED rows are *technical*, *external*, or *genuinely require an
owner decision* — and to resolve the technical ones **through actual fixes and verification, not
by relabeling**.

Eighteen rows. **Six of them were never blocked at all**: they carried `audit: unknown`, and the
generator files an unknown audit under BLOCKED with the catch-all *"nobody has rendered it and
nobody has decided anything"*. That is a statement about the audit, not about an obstacle. Each
was opened.

## One was a real screen nobody had opened

`/order-downloadable-reports` — **clean in all four combinations**, correct direction, correct
theme sheet, and its own page title: *Sales Downloadable Product* / *مبيعات منتج قابل للتنزيل*.
Claimed as REDESIGNED AND VERIFIED on the captures in `after/`.

## Three belong to another phase

| row | phase | how it is known |
|---|---|---|
| Theme customize / storefront visual editor | **03** Storefront | CLAUDE.md §4 assigns *"themes, visual editor"* to Phase 03 |
| Plan-order bank-transfer review modal | **06** SaaS & Super Admin | the inventory row title says so |
| Top brand/category chart fragment | **05** Marketing & Analytics | the inventory row title says so |

The last two were reported as blocked **only** because their audit column says `unknown` — the
classification was in the row name the whole time.

`/theme-customize` was driven anyway, for the record: it answers 200 in all four combinations and
lands on the **dashboard** — a `redirect()->back()` refusal, because `ThemeCustomizeController`
gates on `Manage Themes`, which the dev fixture does not hold. Granting it would have
photographed a Phase 03 surface, so it was not granted.

`/customize-settings` answers **405 Method Not Allowed**: it is a POST route, not a screen.

## Two are not screens

* **Attribute-option picker fragment** — `ProductController::attribute_option` returns
  `response()->json(...)` and never a view. It is called over XHR from
  `product/create.blade.php:848` and `product/edit.blade.php:1128`, so its output is already
  inside those screens' captures.
* **`order/create.blade.php` and `order/edit.blade.php` (DEAD)** — nothing renders them.
  `OrderController::create` returns `view('order.createOrder')` (:556), a *different file*, and a
  repo-wide grep for `view('order.create')` / `view('order.edit')` across `app/` and `packages/`
  returns nothing. The `'order.create'` that does appear (`MerchantQuickActions.php:77`) is a
  **route** name.

## Two were filed under the wrong state

`Customer filter result fragment` and `Customer filter field metadata` were BLOCKED while their
own blocked reasons already began *"not a screen"*. BLOCKED means work is owed; none is. Moved to
OUT OF SCOPE with those same reasons.

## The ten that remain, classified

| | rows | what would resolve it |
|---|---|---|
| **Technical — environment** | Invoice viewer; Customer report body; Sales report body; Sales report bar body; Top Sales Report | the four report fragments need a **MySQL dev fixture** (`MONTH()`, `YEAR()`, `SUBSTRING_INDEX` are MySQL-only and the fixture is SQLite); the invoice viewer needs a **device or emulator** (`PdfPreview` rasterises through a platform channel). **No MySQL server exists in this container**, so neither is resolvable here today. |
| **External** | Shopify import screens; WooCommerce import screens | a sandbox credential for each. Both controllers call the third-party API before returning a view, so there is no layout to photograph without a connected store. |
| **Owner decision** | COD/Stripe/Bank-transfer gateway editors; Edit customer; Wishlist create/edit/store/update | the gateway editors are 815 lines commented out and reviving them exposes financial configuration; the other two are registered routes that render nothing, and building them is new merchant functionality. |

**Nothing here was moved to improve a number.** The six reclassified rows were each opened,
driven or read, and the reason recorded; the ten that remain are still blocked, and five of them
are blocked on infrastructure this container does not have.
