# P2-03-3 — the full-page product editor, read before it is rebuilt

**Status of this document:** the preservation inventory the ledger row needs before any editor work
starts. Written 2026-10-25 at HEAD `e8faf393` (branch `claude/store-syria-p02-merchant-experience`).

**Why it exists twice over.** The ledger row `P2-03-3` is `TODO` with all three evidence columns
empty. A previous session began this survey and did not finish it; `grep -rn 'P2-03-3' docs/ app/
tests/ resources/` returned **nothing** but the ledger row itself, so **no part of that survey was
ever committed** and none of it was recoverable. This replaces it from a first-hand read.
`P2-04-6-RECONNAISSANCE.md` is the format precedent and concerns the ORDER detail page; it has no
product content.

**How to read the claims.** Every finding below carries one of three labels, and they are not
interchangeable:

| label | means |
|---|---|
| **[READ]** | line-exact from the source at this HEAD. Structure and predicates verified by reading. |
| **[RUN]** | executed in this container and observed. |
| **[NOT DRIVEN]** | the code path was read and the defect follows from the structure, but no request was issued to make it happen. Treat as a strong hypothesis, not a measurement. |

Nothing here is labelled from a commit message or a previous document.

**One finding was withdrawn during review** (P3-09): a claim that `products.preview_video` is not a column, refuted by reading the schema. It is kept in the table as withdrawn rather than deleted, because the next reader benefits more from knowing it was checked and failed than from a silent gap. Every other `[READ]` label was re-checked against the source or the schema before this document was committed.

---

## 1. The surface, by size

| file | lines | bytes | role |
|---|---|---|---|
| `resources/views/product/create.blade.php` | 989 | 44,718 | create form |
| `resources/views/product/edit.blade.php` | 1200 | 68,532 | edit form |
| `app/Http/Controllers/ProductController.php` | 3735 | — | `store()` **:110–872** (763 lines), `update()` **:1097–1856** (760 lines), `create()` :77–100, `edit()` :1020–1069 |
| `resources/views/product/attribute_combinations.blade.php` | 246 | — | variant rows, **CREATE path** (`attribute_combination()`, :2061) |
| `resources/views/product/attribute_combinations_edit.blade.php` | 441 | — | variant rows, **EDIT path** (`attribute_combination_edit()`, :2397) |
| `resources/views/product/attribute_combinations_data.blade.php` | 412 | — | variant rows, **EDIT page-load** (`attribute_combination_data()`, :2088) |
| `resources/views/layouts/app.blade.php` | — | — | **`add_more_choice_option()` :552–603** — emits create's attribute fields. Create has none of them in its own markup. |

**65 form fields and payload keys were inventoried.** The full field table is long; what follows is
the part that governs a rewrite — the asymmetries. A rewrite that reproduces `create.blade.php` and
`edit.blade.php` faithfully and ignores this section will still drop behaviour.

---

## 2. The eleven asymmetries a rewrite must preserve or deliberately close **[READ]**

These are fields or payload keys that exist on only one side, or exist on both by *different
mechanisms*. Eleven of the twenty-one divergences found are of this kind, and the ledger's own
`P2-03-8` acceptance criterion ("without hiding any existing field") is why they matter.

1. **The attribute block has no markup in `create.blade.php` at all.** It is injected by
   `layouts/app.blade.php:552–603`. A rewrite that reads only the two blade files will not know the
   create-side attribute fields exist. *This is the single biggest hazard in the feature.*
2. **The variant-row carrier key differs by path:** `options[]` on create
   (`attribute_combinations.blade.php:131`, note the space in `name ="options[]"`), `options_datas[]`
   on edit. Cross-wiring them silently forces `variant_product=0`.
3. **`ss_last_seen_at`** (edit:44) — the P2-03-5 concurrent-edit token. Invisible in the visual form.
4. **`_method=PUT`** (edit:28) — carried only because `serializeArray()` picks it up; the `$.ajax`
   type is POST.
5. **`product_attribute` option values are written with different semantics by the two handlers.**
   `store():197–201` stores the posted values verbatim (option IDs). `update():1456–1465` looks each
   posted value up as `ProductAttributeOption::where('terms', …)` and stores the resulting IDs. The
   same column is therefore populated from IDs on create and from term-strings on update.
6. **`attribute_combinations_edit.blade.php` is missing six per-variant controls its own handler
   reads** — the variant sale price, variation price, weight, threshold and description keys are
   emitted by `attribute_combinations.blade.php` and `_data` but **not** by `_edit`, while
   `update():1766–1779` reads them.
7. **`cover_image` / `product_image` are `required` in `store():125–126,140–141` and have no rule at
   all in `update():1183–1202`.**
8. **Summernote bodies** are explicitly appended on create and not on edit.
9. **`description` / `preview_content` use different markup:** create uses `Form::textarea` (so
   `Form::model` repopulation applies); edit uses a raw `<textarea>` with a meaningless `value=""`.
10. **`stock_status` radios:** create hard-codes `checked` on `in_stock`; edit compares strictly
    against `$product->stock_status`, so a product with an empty value renders with **no radio
    selected** and posts no `stock_status`.
11. **`image_id[]`** (edit:355, one per existing `ProductImage`) drives `DELETE products/{id}/delete`
    and has no create-side equivalent — product images are **purely additive** on update
    (`:1404–1428`); removal happens only through that separate route.

---

## 3. The two handler contracts **[READ]**

Both return **a bare array → JSON, HTTP 200 in every case**, including every error.
`store()` → `{flag:'success', msg:'Product saved successfully.'}` (:860–862).
`update()` → `{flag:'success', msg:'Product update successfully.'}` (:1850–1852).

**Gates differ, and the difference is a real inconsistency inside one controller:**

- `store():113` — `isAbleTo('Create Product')` with **no operator-first limb**. So a `super admin`
  holding no Laratrust permission **cannot create a product**, although `edit()`, `update()`,
  `destroy()` and `product_preview()` all exempt `UserPolicy::PLATFORM_ADMIN`. This is exactly the
  ordering CLAUDE.md §1 warns about, applied inconsistently. *Not* a cross-tenant hole.
- `update()` has three gates: permission (:1100), **ownership before any write** (:1123–1133), and
  the concurrent-edit check (:1164–1179).

**Neither handler wraps its writes in a transaction.** `store()` has a try/catch that returns the
exception message to the client verbatim; **`update()` has no try/catch at all**, so a mid-save
failure is a 500 with the row half-written.

Side effects a rewrite must keep: slug derivation, the silent "first Tax row" fallback on create,
lookup-or-create of tags, five media paths, the preview-precedence chain
(`preview_video` → `video_url` → `preview_iframe`, last non-empty wins, :284–291), storage-quota
accounting, the plan `max_products` gate (create only), five module `saveData` calls, the
`New Product` webhook (create only) and the FCM notify sweep (update only).

---

## 4. Defects found while surveying — these are not rewrite scope, they are findings

Each is in `ProductController.php` unless stated. **None of these is introduced by Phase 02**; all
are pre-existing.

### 4a. Verified line-exact

| # | finding |
|---|---|
| **P3-01** | **`update()` validates `cost_price` as `required` (:1192) and never writes it.** The only write is `:303`, in `store()`. So a merchant must re-enter cost price on every edit and the value is then discarded — it is **mandatory and unchangeable from the editor**. **This sits on a GATE F column:** `products.cost_price` has **no applied migration** (the draft is parked at `database/migrations/_gate_f_pending/0004_draft_add_cost_price_to_products_table.php.draft`) and `docs/GATE-F-VERIFIED-SCHEMA.md:35` records it as existing in production, `decimal(15,2) NOT NULL DEFAULT 0.00`, populated on all 45 products. So it is absent from the SQLite test database and **no Feature test can reach this path at all** — which is why a money-bearing field being mandatory-and-discarded has gone unnoticed. **[READ]** |
| **P3-02** | **`default_variant_id` is unreachable.** `sku_combination()` (:2079) returns `view('product.sku_combinations')` — **no such file exists**. Only `sku_combinations_edit.blade.php` exists, and nothing renders it. So the writes at `store():833–837` and `update():1650–1654,1791–1795` are dead and **the default variant cannot be set from the web UI**. **[READ]** |
| **P3-03** | **Duplicate route, middleware silently dropped.** `routes/web.php:915` and **`:932`** both declare `POST products/attribute_combination` with the same name. The later wins; the earlier group's middleware is discarded. This is CLAUDE.md §1's routing hazard, live in this feature. **[READ]** |
| **P3-04** | **Both blades pass the STORE SETTING as `low_stock_threshold`'s value** (create:199, edit:226), so `Form::model` never reaches the product's own column and **the edit form always shows the store default**, not the saved threshold. **[READ]** |
| **P3-05** | **`store()`'s non-variant branch never writes `custom_field` / `custom_field_status`** (0 hits over :186–449) while its variant branch does (:620–623). **Creating a simple product discards its custom fields.** **[READ]** |
| **P3-06** | **Three live DOM id collisions, in both files:** `tax_status` reuses `id="tax_id"`; the `preview_type` wrapper div reuses `id="preview_type"`; and on edit a `<source id="preview_video">` is a third element with that id. `#attribute_id` and `#enable_product_variant` each carry **two** change handlers, so every toggle fires twice. **[READ]** |
| **P3-07** | **`update():1280–1285` only writes `brand_id`/`label_id` when truthy**, so **neither can be cleared** once set. **[READ]** |
| **P3-08** | **`update():1291–1298` inverts `store()`'s tax behaviour.** An empty `tax_id` NULLs the column; the "fall back to the store's first Tax" limb is dead because the `elseif` already caught the empty case. **[READ]** |
| ~~P3-09~~ | **WITHDRAWN — the claim was wrong and is recorded rather than quietly dropped.** A survey pass asserted that `update():1309`'s `$product->preview_video` "is not a column, always null, so the old video is never cleaned up". **`products.preview_video` EXISTS** (`PRAGMA table_info(products)`, checked directly), so the read is of a real column and the premise fails. Whether the old file is actually unlinked was **not** then established either way, so no finding is claimed here. Left in the table because a withdrawn finding is worth more to the next reader than a gap. |
| **P3-10** | **`store():844` assigns `$product->stock` after the last `save()`.** Verified by line: the final saves are `:831` and `:836`, the assignment is `:844`, and the returns are `:856`/`:862` with no save between — so the assignment is discarded. `products.stock` is also absent from the migrated schema. Harmless today; it means the `out_of_stock` derivation at that point has no effect. **[READ]** |
| **P3-11** | **`choice_no` / `choice_options_*` are read in six places across both handlers and emitted by no blade**, so `store():732` and `update():1620–1686` are unreachable. **[READ]** |
| **P3-12** | **`CartQuantityControl::saveData` is called BEFORE `$Product->save()`** in both handlers (store:372, update:1513), so it receives a model **with no id**. **[READ]** |
| **P3-13** | **The blank-toast defect P2-03-5 fixed is still live on six other exits** — `update()` :1131, :1591, :1594, :1854 and `store()` :696, :870 return a shape the page cannot render a message from. P2-03-5's own fix at :1171–1174 is verified present. **[READ]** |

### 4b. Needs a driven test before it is called a defect

| # | finding |
|---|---|
| **P3-14** | **The variant sweep may destroy earlier groups' rows.** `ProductVariant::where('product_id',…)->whereNotIn('variant', $com)->delete()` sits at **:1801–1803**, nested inside **both** `foreach ($attribute_option as $key => $com)` (:1719) and `foreach ($com as $key => $item)` (:1721) — confirmed by indentation walk. `$com` is the **current attribute group's** combination list, so with more than one group pushed into `$attribute_option` (`array_push`, :1709, once per group with `for_variation==1`), a later pass deletes what an earlier pass wrote. **Structurally verified; NOT DRIVEN** — no two-attribute-group product was saved to observe the loss. **This is the highest-severity item in this document and the one to test first.** **[READ]/[NOT DRIVEN]** |
| **P3-15** | **`update():1458` and `:1551` resolve posted attribute terms with `ProductAttributeOption::where('terms', $fValue)->first()` and NO `store_id` predicate.** `ProductAttributeOption` would need checking against `config/tenancy.php`'s `scoped_models` before this is called cross-tenant either way — CLAUDE.md records `Product` itself as unscoped, so the default assumption must be that no predicate is added. **Not established; do not report as a security finding until driven with two tenants.** **[READ]/[NOT DRIVEN]** |
| **P3-16** | **Variant image upload key built from the unsanitised name.** `store():771–774` and `update():1731–1734` build the upload key with the UNsanitised `$str` while testing with the sanitised one, so a variant whose name contains a dot would throw on `->getClientOriginalName()`. **[READ]/[NOT DRIVEN]** |

---

## 5. What this survey did NOT cover, by scope

`show.blade.php` (53,769 bytes — note it *also* emits `attribute_no[]` at :471), `list.blade.php`,
`index.blade.php`, `action.blade.php`, `partials/filters.blade.php`, `partials/preview.blade.php`.

**And one key set that cannot be inventoried from these files at all:** both handlers pass
`$request->all()` to `AdditionalCustomFields` (store:398, update:1845), so that module's accepted
keys are open-ended.

---

## 6. What this means for the rewrite

1. **Do not start from the two blade files.** Start from this inventory plus
   `layouts/app.blade.php:552–603`, or the create-side attribute block will vanish.
2. **The three variant partials are not interchangeable** and `_edit` is already missing six
   controls its handler reads. Unify them deliberately, as a change with its own tests, or keep all
   three — do not assume they are copies.
3. **P3-14 first, and with a test.** A rewrite that preserves the current loop structure preserves a
   probable data-loss bug; one that "tidies" it without a test may change which variants survive.
4. **The defects in §4 are not the rewrite's job to fix silently.** Each needs its own ledger row, so
   that a behaviour change is a decision rather than a side effect of a redesign. Several
   (`cost_price`, the tax inversion, `brand_id` clearing) change what a merchant's saved product
   contains, which CLAUDE.md §2 puts behind an owner decision when it touches price fields.
