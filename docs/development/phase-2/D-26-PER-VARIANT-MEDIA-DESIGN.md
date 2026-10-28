# D-26 — per-variant media: the design, and what it is measured against

**Status: design settled, implementation pending.** Written before any code so the choices are
reviewable on their own, and because two of them turn on facts about this codebase that would be
invisible from the finished diff.

## What the owner approved, and what is already done

D-26 approves **one image per Product Variant** for Phase 02, reusing the existing media model, the
Gallery Upload path and `UploadLimits`; bound to the correct variant id and the correct store;
add, replace and remove; without losing a variant's price, stock or SKU; without changing a
variant's identity; and preventing access to another store's media. The migration is authorised as
*"the smallest additive"* and **for DEV and TEST only**. Bulk Edit Across Combinations is deferred
to Phase 04 and Gallery Image Reorder to Phase 03 (D-06), both by name.

P2-03-4 already delivered the surrounding work and it is not redone here: `UploadLimits`, the
client-side `accept`/size declaration, the server's by-name rejection reporting (F75), the variant
key-escaping fixes (F72, F73's sibling) and the variant persistence fixes under D-09/D-16 (F82,
F83, F84, F105). What was left of that row is exactly this: the per-variant surface.

## The design

**The column goes on `product_images`, not on `product_variants`** — one nullable `variant_id`.

That is what *"reuse the existing media model"* means literally, and it buys three things that a
column on `product_variants` would not:

- `App\Models\ProductImage` already carries a `saving` hook calling
  `App\Services\StoredPathPolicy::productImage()` (P1-SEC-28), so path safety is inherited rather
  than re-implemented;
- it already carries `store_id`, which the gallery writer sets from `getCurrentStore()`, so tenancy
  has an existing answer on the row;
- the gallery upload loop in `ProductController` already validates through
  `Utility::keyWiseUpload_file()` and reports refusals by name, so the accepted-type and size rules
  are the ones already driven under P2-03-4 rather than a second set.

A column on `product_variants` would have put an image path next to a variant's price, stock and SKU
— the three values D-26 says must not be lost — on a table whose rebuild path has already destroyed
financial values twice (F83, F105). Keeping media off that table is a safety property, not a
preference.

**One image per variant is enforced in the handler, not by a unique index.** Phase 02 wants one;
Phase 03's reorder work and any future multi-image-per-variant would have to drop a unique index
again, and a dropped index on a live table is a migration this project may not run. The handler
replaces: a new upload for a variant deletes that variant's existing row first.

**The migration is idempotent**, via `Schema::hasColumn()`. This is not caution: CLAUDE.md records
that *"the migrations are not the schema"* — this file's own predecessor asserted two missing
`delivery_boys` columns that the restored production dump refuted — and GATE F records 15 tables
that live code reads and writes with no migration at all. I cannot establish from here whether
production's `product_images` already carries such a column, and no production access is permitted,
so the migration adds it only if absent. That makes it safe in both worlds and it is the pattern
CLAUDE.md names.

**`product_variants` is exactly its migration, which was checked rather than assumed.** The model's
`$fillable` lists `minimum_quantity` and `maximum_quantity`, which do not appear in any migration —
the GATE-F shape. They turned out to be vestigial: `ProductController` writes both through
`Workdo\CartQuantityControl\app\Models\CartQuantityControl::saveData()`, into that module's own
table, never into `product_variants`. The dev database's `PRAGMA table_info` confirms the table
holds precisely the migration's eighteen columns and no image column.

## Where the control goes

`resources/views/product/attribute_combinations_edit.blade.php` renders one accordion panel per
variant, each already carrying `data-id="{{ $ProductVariantData->id }}"`. That id is the binding
D-26 requires, and it is already in the markup.

## The tenancy predicate, stated before it is written

`ProductVariant` carries **no** global scope and is **not** in `config/tenancy.php`'s
`scoped_models` (CLAUDE.md's table), so `ProductVariant::find($id)` reaches every tenant's variants.
The predicate therefore cannot be the variant id alone, and it cannot be
`where('product_id', $request->product_id)` either — that is the caller's own two values, the
pattern CLAUDE.md records as having produced four separate defects, and S19's measured cross-tenant
stock drain went through exactly that shape on this table.

The variant must be resolved by joining to a product owned by the **current** store, with
`getCurrentStore()` checked non-empty first, because that helper returns `null` for a staff member
who owns no store and the hardcoded literal `1` for a `super admin` — the trap F157 was.

## Not part of this slice

- **Bulk edit across combinations** — Phase 04, by the owner's split, because it writes prices and
  stock to many rows at once and that is nearer the engine Workstream D reserves.
- **Gallery image reorder** — Phase 03 by D-06, by name.
- **F76** (the dropzone panel ignores dark mode) and **F73** (`$safe_str` collides for two terms
  differing only in a special character) are recorded against this row and are separate fixes with
  their own measurements; they are not folded in here.
- The `accept` attributes still missing from `downloadable_product` and `preview_video`: the
  server's single configured list is an **image** list, so reusing it would wrongly refuse a video
  or a ZIP. That needs a per-field setting, which is its own change.
