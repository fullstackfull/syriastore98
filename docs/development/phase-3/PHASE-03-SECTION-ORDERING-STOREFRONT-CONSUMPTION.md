# GAP-099 / P03-B-06 — the storefront now renders the merchant's saved section order

**Date:** 2026-11-26
**Requirement:** `P03-B-06`, 03-B *Controlled visual theme editor* (OWNER-SUSPECTED GAP A)
**Register row:** `GAP-099`, status **NOT IMPLEMENTED** before this change
**Status after this change:** the mechanism is complete and proved end to end; **one named half
remains and is an owner decision**, recorded in §7.

---

## 1. Why this was actionable

`PHASE-03-FINAL-REPORT.md` §9 listed *"the M4 reorder UI | deferred; waits on the storefront
consuming saved ordering (Phase 04)"*. That is an **engineering** deferral. CLAUDE.md §4.1 is
explicit that *"every deferral [must be] grounded in an actual owner decision; an engineering report
deferring something is not one"*, and the gap register adds that the landing place named for it does
not exist — Phase 04 is closed and never received it. So the deferral fell through and the
requirement was open.

---

## 2. Which half was missing — measured before anything was written

A census over `app/`, `themes/`, `resources/`, `config/` and `routes/` for `ThemeCustomizeOrder`,
`resolveFor` and `theme_customize_orders`, **with PHP and Blade comments stripped** (prose about the
feature must not be able to satisfy the measurement):

```
=== A. WRITE HALF: who writes theme_customize_orders ===
  app/Http/Controllers/ThemeCustomizeController.php: $theme_order = ThemeCustomizeOrder::resolveFor($orderStoreId, $theme, $slug);
  app/Http/Controllers/ThemeCustomizeController.php: ThemeCustomizeOrder::updateOrCreate(

=== B. READ HALF: every non-comment reference to the model/table/resolver ===
  config/tenancy.php                                   <- ThemeCustomizeOrder
  app/Models/ThemeCustomizeOrder.php                   <- ThemeCustomizeOrder
  app/Http/Controllers/ThemeCustomizeController.php    <- ThemeCustomizeOrder
  resources/views/theme_customize/order.blade.php      <- theme_customize_orders
  total files: 4
```

**The write half worked. The read half did not exist.** `resolveFor()`'s only caller was
`changeOrder()` — the editor screen redisplaying what it had just saved. No theme view, no
controller and no composer read the table. The same conclusion was then reproduced as a test
failure rather than as a reading (§4).

Nothing in the write path was rebuilt. `updateOrder()`, its validation, `ThemeEditorGate`, the
`theme_id` keying and the keyboard-accessible editor screen (GAP-100) are untouched.

---

## 3. What was built

### 3.1 `app/Support/Theme/StorefrontSectionOrder.php` (new)

`sequence($storeId, $themeId, $pageSlug, $blocks, $orderable)` returns the block sequence a
storefront page should render.

**The ordering rule is a slot permutation, and the alternative would have shipped as a surprise.**
Only the blocks the saved order *names* move, and they move only among the slots they already
occupy. Everything else — a block the ordering does not name, and a block that is not orderable at
all — keeps its position.

The obvious rule ("named first, in saved order, then the rest") relayouts the page for every store
that has ever saved an ordering, because the shipped `orders` strings do not name every section
(§7). Measured against the real theme data, under "named first" a merchant who had saved stylique's
own shipped default would find `logo`, `collection`, `blog` and `service` shunted to the bottom of
their home page the day this shipped. Under the slot rule the same row moves `product` and
`category` past each other and nothing else.

The permutation is total and injective — every block is emitted exactly once — and **saving the
order a page already renders in is the identity**, which is the property that makes switching
consumption on safe for existing stores.

### 3.2 The three `main_file.blade.php` templates

Each theme's home template now:

- wraps each top-level block in `@push('ss-home:<key>') … @endpush`, **where it already stood**;
- declares `$ssBlocks` (the default sequence) and `$ssOrderable` (the subset a merchant may
  permute) in a raw PHP block beside that markup;
- emits them through one `@foreach ($ssSequence as $ssBlock) @stack('ss-home:' . $ssBlock) @endforeach`.

| theme | blocks | orderable | not orderable (keep their slots) |
|---|---|---|---|
| stylique | 10 | 9 | `product_list` (module hook anchor) |
| techzonix | 12 | 9 | `service` (a techzonix *page*, not a home section), `product_list`, `subscribe` |
| greentic | 11 | 9 | `product_list`, `subscribe` |

`$ssOrderable` is asserted to equal exactly the sections that theme's `theme_json/home.json`
declares, using the same walker `ThemeCustomizeController::acceptableOrdering()` validates writes
with — with one documented exception: stylique's `more_offer` is nested *inside* its slider block
(the offer banner beside the hero) and has no slot of its own.

**The markup was not moved into partials, and that was a deliberate reversal of the first design.**
`StorefrontImageFallbackTest` and `StorefrontAccessibleNamesTest` both read these templates as TEXT
and assert on markup inside these sections (`themes/techzonix/views/main_file.blade.php` slider
backdrops; every theme's testimonial avatar). Extracting the blocks would have turned two passing
tests red while changing nothing a shopper sees.

**No controller, route, provider or migration changed.** `$store` and `$currentTheme` were already
supplied to `main_file` by `ViewServiceProvider`, resolved from `request()->route('storeSlug')`.

### 3.3 greentic

greentic declares **no** `is_order` page, so `updateOrder()` refuses every write for it and its
merchants' reorder screen lists nothing. The consumer is wired up anyway because a legacy
`theme_id IS NULL` row applies to whichever theme has no row of its own, greentic included, and
because adding the declaration later then needs no further change. **Whether to offer the control
on greentic is a product decision and was not taken here.**

---

## 4. Failing first, and failing again on revert

**Before any implementation** (the service existed, the templates did not consume it):

```
  ✓ the fixture store really renders stylique
  ✓ the merchant form really stores an ordering
  ✓ with nothing saved the page renders the themes own order
  ⨯ a saved ordering changes what a shopper sees
  ✓ saving the pages current order leaves the page identical
  ⨯ a second stores ordering does not reach this shop
  ✓ an ordering saved for another theme does not apply
  ⨯ a legacy row with no theme still applies
  ✓ an ordering for another page does not apply
  ⨯ a visitor with a panel session for another tenant sees this shops order
  Tests:    4 failed, 6 passed
```

The premises pass and the four "the saved order takes effect" assertions fail — the same split the
census found.

**After the stylique template change:** `Tests: 10 passed`.

**Reverted** (`themes/stylique/views/main_file.blade.php` restored byte for byte, `php artisan
view:clear` between — Blade recompiles only on a newer mtime, and this project has had a mutation
survive that trap):

```
=== REVERTED (original stylique main_file restored) ===
  ⨯ a saved ordering changes what a shopper sees
  ⨯ a second stores ordering does not reach this shop
  ⨯ a legacy row with no theme still applies
  ⨯ a visitor with a panel session for another tenant sees this shops order
  Tests:    4 failed, 6 passed
```

**Fix restored:** `Tests: 10 passed` (12 with the Arabic and techzonix cases added later).

---

## 5. Mutation battery — ten mutants, nine caught, one survivor and why

| # | mutation | result |
|---|---|---|
| M1 | `permute()` uses "named first, then the rest" | **caught** — 9 failures |
| M2 | `savedOrdering()` keeps `StoreScope` (`::query()`) | **SURVIVED** — see below |
| M3 | `savedOrdering()` drops the `store_id` predicate | **caught** — *a second store's ordering does not reach this shop* |
| M4 | `savedOrdering()` ignores `theme_id` | **caught** — *an ordering saved for another theme does not apply* |
| M5 | stylique `$ssOrderable` drops `service` | **caught** — 2 unit failures |
| M6 | stylique `$ssBlocks` drops `service` | **caught** — 10 failures |
| M7 | stylique reads the `blog` page's ordering | **caught** — 6 failures |
| M8 | the loop iterates `$ssBlocks` (saved order ignored) | **caught** — 5 failures |
| M9 | techzonix stack loop deleted | **caught** — 2 failures |
| M10 | greentic pushes an undeclared key | **caught** — *every declared block is pushed and every push is declared* |

### M2 survived, and the first version of this change described it wrongly

The service's docblock originally claimed the global scope would hand a storefront renderer `null`
for the store it is rendering, citing `ThemeCustomizeOrder`'s own docblock. **M2 falsified that** —
putting the scope back left all eleven end-to-end tests green. Measured from inside a real
storefront request, with a merchant who owns store 2 loading store 1's shop:

```
[PROBE] inside request: storeId=1 theme=stylique mode=tenant ids=[1,2]
        reason="storefront request; slug-resolved store 1 admitted alongside the owned stores"
        user=2 currentStore=2
[PROBE] SCOPED read => 'testimonial,bestseller,category,product,slider'
```

`StoreScope::resolveTenant()` has a **storefront branch** (`app/Scopes/StoreScope.php:656-686`) that
admits the slug-resolved store alongside the owned ones, and its own comment at `:640` names
`ThemeCustomizeOrder` as one of the eight models it exists for. `storefrontStoreId()` resolves from
the `storeSlug` route parameter, which every route that renders `main_file` carries — the
`theme-draft/{storeSlug}/{version}/preview` path included — and both storefront modes (`slug_only`
and the default owned-plus-slug) admit it.

**So `resolveFor()` would have worked today.** The explicit predicate stays because it is
config-independent — `resolveFor()`'s correctness here rests on `tenancy.enforcement_enabled`,
`tenancy.storefront_tenant_mode` and the slug resolver, two of them operator-tunable, and CLAUDE.md's
rule is that the scope must never be a handler's only tenant check. **It is defence in depth, not
the fix for a live defect**, and both the service and the test now say so.

---

## 6. Rendered-page evidence, both locales, in a real browser

`tests/Support/bin/check-storefront-section-order-live.cjs` renders the real route through the
Feature test (`SS_HOME_HTML_DIR`, `SS_HOME_ASSET_BASE`), serves `public/` **and the repository-root
`themes/`** from a local port, and opens the four captures in Chromium.

```
148 checks, 0 failed
```

Per capture (stylique; the fixture store carries `theme_id = 'stylique'` and the page was confirmed
to load `themes/stylique/assets`, because `config('theme.active')` is also stylique and an empty
`theme_id` would have rendered the same views):

| capture | dir | tailwind | painted top-to-bottom order |
|---|---|---|---|
| `en-default` | ltr | defined, 252 rules injected | slider > logo > collection > product > category > bestseller > testimonial > blog > service |
| `en-reordered` | ltr | defined, 252 rules | **testimonial > logo > collection > bestseller > category > product > slider** > blog > service |
| `ar-default` | **rtl** | defined, 252 rules | slider > logo > collection > product > category > bestseller > testimonial > blog > service |
| `ar-reordered` | **rtl** | defined, 252 rules | **testimonial > logo > collection > bestseller > category > product > slider** > blog > service |

Also measured on every capture: all nine sections present, each with a painted height and spanning
the viewport, **no two sections overlapping**, and the full-page height **identical** before and
after reordering (3477px in all four).

**Contrast.** The question this change is accountable for is not "is the storefront accessible" — it
is "did reordering move a section onto a different background band and change a pairing". Measured
directly:

```
  ok   en: 15 headings present in both orders
  ok   en: reordering changed no heading's contrast
  ok   ar: 15 headings present in both orders
  ok   ar: reordering changed no heading's contrast
```

Assessable headings run 14.68:1 to 21.00:1. Five headings per page are `text-white` over a
merchant-supplied category photograph and are reported as **not assessable by computed style**
rather than as failures — the backdrop is an image, not a colour. That is a real and separate
question about image-backed storefront text; it reads identically in the default order and is not
this change's.

**Two capture artefacts were found and fixed, and both would have read as defects in this change:**

1. `config(['app.url' => …])` does not affect `asset()` — `UrlGenerator::asset()` formats against
   `$this->assetRoot` or the current request's root. The captures carried
   `src="http://localhost/themes/…"`, the 477 KB runtime Tailwind bundle 404'd, the page rendered
   unstyled, and sixteen headings read **1.00:1 on `rgba(0, 0, 0, 0)`**. The harness now uses
   `URL::useAssetOrigin()` (note: `useAssetRoot` does not exist on this Laravel and answers
   `BadMethodCallException` through `Macroable`).
2. `public/themes` **does not exist** — the theme assets live at the repository root. The harness
   serves that directory explicitly.

Screenshots were reviewed by eye in both directions. The Arabic page mirrors the inline axis
(header, category grid, footer) while the vertical section order is identical to the English one,
which is correct: sections stack on the block axis and RTL does not mirror it.

---

## 7. What is NOT complete — the half that is an owner decision

**The shipped `orders` strings do not describe the themes.** Both stylique and techzonix declare the
same nine tokens for `home`:

```
slider,category,product,top_category,bestseller,more_offer,article,testimonial,logos
```

Measured against the real top-level blocks:

| theme | declared tokens that name a block | tokens that name nothing | blocks no token names |
|---|---|---|---|
| stylique | 5 — `slider`, `category`, `product`, `bestseller`, `testimonial` | 4 — `top_category`, `more_offer`, `article`, `logos` | 4 — `logo`, `collection`, `blog`, `service` |
| techzonix | 6 — `slider`, `category`, `product`, `more_offer`, `article`, `testimonial` | 3 — `top_category`, `bestseller`, `logos` | 2 — `collections`, `logo` |
| greentic | n/a — declares no orderable page | — | — |

So the mechanism is complete and **a stylique merchant can really reorder 5 of their 9 home
sections**, a techzonix merchant 6 of 9. The remaining rows on the editor screen are inert — as they
already were — and four real stylique sections cannot be reordered at all.

**This was not fixed here, and the reason is a test, not a judgement.**
`tests/Feature/Theme/ThemeOrderKeyboardPathTest.php:65-69` hard-codes those nine tokens as
`STYLIQUE_HOME` and asserts at `:250-258` that the rendered rows are exactly that list, in that
order. It is an existing test file this work package was not permitted to edit. Changing the
declaration also changes what the merchant's editor offers, which is a product surface.

**Recommended follow-up, as one owner decision:** align each theme's `pages.json` `orders` string
with its real top-level sections, and update `ThemeOrderKeyboardPathTest::STYLIQUE_HOME` in the same
change. The exact strings are:

```
stylique   slider,logo,collection,product,category,bestseller,testimonial,blog,service
techzonix  slider,category,collections,logo,product,more_offer,offer,testimonial,article
greentic   slider,category,bestseller_product,top_category,logos,featured_product,more_offer,testimonial,article
           (greentic also needs "is_order": true on its `home` entry — a new capability, not a correction)
```

Nothing in this change has to move for that: `$ssOrderable` already names every one of those keys,
and `acceptableOrdering()` accepts declared ∪ real-sections, so existing stored rows stay valid.

**Also outstanding, and pre-existing:** the editor's row labels are built as
`ucfirst(str_replace('_', ' ', $key))` in `resources/views/theme_customize/order.blade.php` with no
`__()`, so section names render in English on an Arabic merchant's screen. That is GAP-100's
surface, not this one's.

---

## 8. Files

**New**

- `app/Support/Theme/StorefrontSectionOrder.php`
- `tests/Feature/Storefront/StorefrontSectionOrderRendersTest.php` — 12 tests, 291 assertions
- `tests/Unit/Ui/StorefrontSectionOrderPermutationTest.php` — 23 tests, 250 assertions
- `tests/Support/bin/check-storefront-section-order-live.cjs` — 148 live-browser checks
- this document

**Changed**

- `themes/stylique/views/main_file.blade.php`
- `themes/techzonix/views/main_file.blade.php`
- `themes/greentic/views/main_file.blade.php`

No route, controller, provider, model, migration, language file or vendored package was touched.
**No new user-facing string was added**, so no Arabic key is needed for this change.

---

## 9. Test runs

| suite | result |
|---|---|
| `tests/Feature/Storefront/StorefrontSectionOrderRendersTest.php` | 12 passed, 291 assertions |
| `tests/Unit/Ui/StorefrontSectionOrderPermutationTest.php` | 23 passed, 250 assertions |
| `tests/Unit/Ui` (whole directory) | **1400 passed**, 10023 assertions |
| `tests/Feature/Storefront` + `tests/Feature/Theme` + `tests/Unit/Theme` | 522 passed, **1 failed** |
| `tests/Unit/Ui/BladeViewsCompileTest` + `BladeDivBalanceTest` | 5 passed |
| neighbouring theme/tenancy/journey tests | 128 passed |
| live browser harness | 148 checks, 0 failed |

The one failure is `Tests\Feature\Storefront\PlatformDocsArticleRenderTest > the date is direction
isolated` (`<time>` missing `dir="ltr"` on the Arabic documentation page). **It is pre-existing and
unrelated**: it fails identically with all three `main_file.blade.php` templates reverted to their
original bytes, and this change touches no documentation view. It is not declared in
`.github/known-test-outcomes.json`, whose Feature failure set is empty, so somebody owns it —
it is not this work package.

The full Unit, Contract and Feature suites were **not** run: forbidden during implementation.
