# GAP-274 — storefront image delivery: the pipeline half

The recovered Phase 03 plan bullet at `master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md:222`
(family `CUST-001`…`CUST-008`) names five obligations: SEO, indexing, performance, **الصور
(images)** and structured data. The SEO/indexing/structured-data ones are `GAP-117`. The images
obligation is `GAP-274`, and it splits in two:

| half | state |
|---|---|
| **markup** — `loading`, `decoding`, `fetchpriority`, honest `alt` | landed `71559a9ca`, and **extended here**: the census it shipped with could not see 30 of the storefront's image tags (below) |
| **pipeline** — responsive widths, a modern format, delivery | **this document** |

---

## 1. What was measured before anything was built

### 1.1 Where storefront product images are actually written

Four writers, all of which end up in the same place, and **the disk is `theme`, not `local` or
`public`**: `config/filesystems.php` gives the `theme` disk the root `base_path('')`, so a stored
path is relative to the repository root and `uploads/` sits beside `index.php`.

| writer | call | stored path |
|---|---|---|
| panel, cover image | `ProductController::store/update` → `Utility::upload_file($request, 'cover_image', …, 'uploads/<store id>')` | `uploads/<store>/<name>` |
| panel, gallery | `ProductController` → `Utility::keyWiseUpload_file(…, 'uploads/<store id>')`, which prefixes `uploads/` **again** | `uploads/uploads/<store>/<name>` |
| admin API | `Api\DashboardController` → the same two helpers | the same two shapes |
| importer | `Utility::upload_woo_file()` → `SafeRemoteFetch` + `ImportedImage::storedName()` | `uploads/<store>/<name>` |
| CSV import | writes `products.cover_image_path` from a spreadsheet cell, **with no uploader at all** | whatever the cell holds, held to `StoredPathPolicy` |

That last row is why the generation point is a model hook rather than an uploader hook: one writer
never touches an uploader.

### 1.2 What the storefront renders them at

Measured in Chromium against the real storefront — all three themes, both directions, with the
theme's runtime Tailwind JIT loaded (CLAUDE.md §6: a box measured without it is measured against a
cascade no shopper sees). Captured through the real routes, and for the product list through the
real `{slug}/product-filter` JSON that the page's own jQuery puts into `.product_filter`.

| element | 360 | 414 | 768 | 1024 | 1280 | 1536 | 1920 |
|---|---|---|---|---|---|---|---|
| product detail, main image | 294 | 348 | 670 | 446 | 574 | 702 | 702 |
| product detail, thumbnail | 87 | 105 | 155 | 99 | 131 | 163 | 163 |
| product card cover | 328 | 382 | 358 | 355 | 288 | 352 | 352 |

CSS pixels. The columns are **not monotonic** and that is real: the product page becomes two
columns at `lg` (1024), so its main image gets *narrower* than it was at 768, and the card grid goes
to three columns at `xl`. Identical in `ar` and `en` — the RTL storefront is a mirror, not a
different grid — which is why one `sizes` constant serves both.

### 1.3 Nothing already serves a resized copy

Zero `srcset` in any view or theme (the only two hits in the tree are inside
`swiper-bundle.min.js`); no `intervention`, `Image::make`, `imagewebp` or `->resize(` anywhere in
`app/`; no image library in `composer.json`.

### 1.4 What this host's `gd` can do

`imagewebp()` exists and `gd_info()['WebP Support']` is true. **`imageavif()` does not exist**, so
AVIF is out of scope here — a host rebuild, not an engineering decision. `exif` is loaded, which
matters (§3.2).

---

## 2. What was built

### 2.1 `App\Services\ImageVariants` — the generator

Bundled `gd` only. Given a stored original it writes narrower **WebP** copies into
`<the original's directory>/variants/`, named
`<reduced stem>-<8 hex of sha1(stored path)>-<width>w.webp`.

**The ladder is 160, 320, 480, 768, 1280**, derived from §1.2 rather than from a convention: the
real demand is 87–702 CSS px, which on a 2× display is 174–1404 device px. 160 serves the thumbnail
at 1×, 320 the thumbnail at 2× and the narrowest card, 480 every card at 1× and the main image on a
phone, 768 the main image at 1024 and every card at 2×, 1280 the main image at 2×. Each rung is at
most 2× the one below, so the candidate a browser picks is never more than one rung wider than it
needs. Five rungs and not six because the files are a storage cost and 1280 already serves 1404
device px within 10%.

A rung **wider** than the original is never written, so a 600px original gets three variants and a
150px one gets none.

### 2.2 The original is never touched

Not overwritten, not deleted, not renamed, and **no stored path changes** — a merchant's own content
already references it. The storefront's `<img src>` is still the original.

### 2.3 `<picture>` + `<source type="image/webp">`, and why not `srcset` on the `<img>`

`srcset` candidates carry no format negotiation: a browser that cannot decode WebP would still pick
one and show nothing. A `<source type="image/webp">` is skipped by exactly those browsers, so the
`<img>`'s own `src` stays the fallback — the original, in its original format, which is what a
browser gets today. One mechanism delivers both the modern format and the responsive widths, and
only one extra file per rung is stored.

The `<img>` elements are **unchanged**: `loading`, `decoding` and `fetchpriority` from the markup
half still govern the fetch, because inside a `<picture>` the `<img>` is still the element that
loads. An image with no variants emits no `<source>` at all, so the page renders exactly as before.

A `<picture>` wrapper was checked for collateral before it was used: no storefront stylesheet has a
`> img` child selector or a positional pseudo-class on `img`, and the only JS that reaches for the
element (`swiper-bundle`) uses `.find("img")`, a descendant search.

**Eight tags in five files** are delivered this way: the product detail page's two main images and
two thumbnails, and the product card's cover in **all four copies** (`resources/views/components`
plus one per theme). All four, because `FileViewFinder::find()` memoises by view name, so under
php-fpm whichever store a worker serves first fixes which copy every later store in that process
renders.

### 2.4 Generation point: the model, not the mechanism

`App\Observers\ProductImageVariantObserver`, registered in `AppServiceProvider::boot()` on
`Product` and `ProductImage`.

**Rejected: on demand.** A route that resizes on first request needs a public route, a cache, a cap
on the widths a stranger may ask for (or an anonymous visitor makes the server resize a
40-megapixel image at 400 arbitrary widths), and an answer for a cold cache during a traffic spike.
Bigger decision, and it adds an attack surface where this adds none.

**Rejected: inside the uploaders.** `Utility::upload_file()`, `keyWiseUpload_file()`,
`multi_upload_file()` and `upload_woo_file()` carry *every* upload the platform takes — payment
receipts, KYC documents, invoice marks, ticket attachments, PDFs. Four functions, and they are not
the only writers (§1.1).

**Chosen: the two columns a storefront product image is ever stored in.** Every writer goes through
Eloquent to put a value there, so two hooks cover the panel, the admin API, both importers and the
next writer somebody adds. It is also where the value has *already* been held to
`StoredPathPolicy` (which runs on `saving`).

`created` **and** `saved` are both needed, and `wasRecentlyCreated` is not a substitute for either —
measured: after an insert Laravel's `performInsert()` never calls `syncChanges()`, so `wasChanged()`
is false on a new row; and `wasRecentlyCreated` stays true on that *instance* for the rest of its
life, so `wasChanged(…) || wasRecentlyCreated` fires on every later save of the same object. A test
caught exactly that.

### 2.5 Measured effect

On a 1600×1200 synthetic photographic JPEG (gradients plus noise; a real photograph compresses
differently):

| | bytes |
|---|---|
| original JPEG q85 | 477,716 |
| 160w WebP | 1,562 |
| 320w WebP | 5,864 |
| 480w WebP | 20,156 |
| 768w WebP | 64,514 |
| 1280w WebP | 205,862 |
| **all five together** | **297,958 — 62% of the original** |

So an image's stored footprint goes from 1× to about 1.6×. Against that: a product card whose box
measures 288 px used to download the whole original and now downloads the 320w WebP — **477,716 →
5,864 bytes, 99% less** — and a product list shows twelve of them.

Confirmed in Chromium after the change, reading `img.currentSrc`:

* the rendered box widths are **identical** to §1.2 in every theme and both directions, so the
  `<picture>` wrapper changed no layout;
* the browser picks the right rung: a 288 px card box takes `320w`, a 358 px box takes `480w`, a
  670 px main image takes `768w`;
* an unwrapped image (the header logo) still loads its original.

---

## 3. Two ways a naive resize produces a visibly wrong picture

Both are handled, and both are the kind of thing that only shows up on a merchant's real photograph.

### 3.1 Animation

`gd` decodes one frame. Because the `<source>` makes the variant the copy that paints, generating
one for an animated GIF or WebP would silently stop a merchant's animation. An animated source is
skipped and keeps its original only.

### 3.2 EXIF orientation

A browser rotates a JPEG according to its EXIF `Orientation` tag (`image-orientation: from-image`
is the CSS default). `gd` does not read it and `imagewebp()` writes no EXIF, so a phone photo saved
as orientation 6 displays upright from the original and **sideways** from an unrotated variant —
a regression caused by this feature. The tag is read and applied, and when it swaps the axes the
*displayed* width is what the no-upscale rule compares against.

---

## 4. Tenancy and the confinement

A variant path must land inside the same store's folder; a name that traversed out of it would be a
new door into another tenant's files. Nothing is re-derived:

1. `StoredPathPolicy::isSafe()` for the string — the three roots uploads live under, the refusal of
   `..`, `.`, an empty segment, a control byte and a backslash, and the doubled `uploads/` peeled;
2. a store-folder shape check, because `isSafe()` short-circuits on a null store id and so does not
   answer it — `uploads/0/`, `uploads/007/`, `uploads/profile/` and a loose file directly in
   `uploads/` all get no variant;
3. when the owning store is known, the folder must be **that** store's. This is deliberately
   **tighter** than `StoredPathPolicy`, which also admits another store of the same owner: a variant
   is written for a model whose `store_id` is known, so it never needs a sibling's folder, and
   refusing one keeps the derivation free of a database query;
4. `StoredUpload::resolve()` for the filesystem — `realpath()` resolves `..` and symlinks the way
   the operating system does, and refuses anything that is not a regular file inside
   `base_path('uploads')`;
5. the variant directory is built from the **resolved** original's directory, and after `mkdir` its
   own `realpath()` must be exactly that directory plus `/variants`. A `variants` symlink pointing
   elsewhere is refused by the same comparison that admits the real thing, and the final `rename`
   target is built from the verified path rather than from the stored string.

`variants/` is a name no uploader writes: every writer puts the file directly in the directory it
was handed, and `UploadedFile::getClientOriginalName()` is `basename()`'d by Symfony, so a client
cannot introduce a path separator.

**Every URL is percent-encoded per segment and then escaped.** Not habit: `srcset` separates a URL
from its width descriptor by whitespace and candidates by commas, `StoredPathPolicy` deliberately
refuses neither a space nor a quote (real upload names carry them, and refusing them once broke a
product save *after* its file had been written), and `get_file()` answers a bare `url($path)`. An
unencoded directory segment with a space splits one candidate into two — malformed markup with
nothing HTML-special in it, which an escaping assertion alone cannot see.

**A memory budget, because this is the one failure here a `try`/`catch` cannot contain.** Exceeding
`memory_limit` is a fatal error, so a large enough source would take the whole product save down
rather than skipping the picture — and the platform's upload ceiling does not bound the pixel count
(2048 KB by default, and a heavily compressed JPEG of that size can be tens of megapixels). Three
`gd` copies at 4 bytes per pixel are budgeted against the remaining limit.

---

## 5. The markup half's census could not see 30 tags

Found while building this, and fixed here because it is the same row.

`StorefrontImageDeliveryTest`'s roots were `themes/<t>/views/front_end` and
`resources/views/front_end`. The three themes keep their **home page** at
`themes/<t>/views/main_file.blade.php` and their **cards** at `themes/<t>/views/components/`, both
one level *above* `front_end`. So 30 tags were never read, the census's own premise (`>= 40`) was
satisfied by the 45 it could see, and:

* **every product card** — rendered on every listing, every slider and the similar-products row of
  every product page — was invisible. techzonix's and greentic's copies carried **no loading policy
  at all**, plus a shipped demo `alt` announcing every product in the catalogue as
  "Ultra HD Smart Watch Pro" and "Fresh Organic Onion";
* techzonix's blog card did the same with "5G Technology", greentic's with
  "Seasonal Eating: Why It Matters…", and greentic's home page with "Vegetables" and
  "Organic Pantry Staples";
* all three theme home pages (22 tags) were unread.

`Fresh Organic Onion` was **already on the census's false-alt list and the test was green**, because
the file that carries it was outside the roots. The list was not wrong; it was unenforced.

The roots are now each theme's whole `views` directory (storefront by construction — a theme renders
nothing else) plus the one storefront component in the fallback tree that carries an image. 74 tags.
Five false alts fixed and enforced; `loading="lazy" decoding="async"` added to the four cards and
the three blog cards.

**11 tags are recorded as undecided, by file and exact count**, in
`LOADING_POLICY_UNDECIDED`: `themes/techzonix/views/main_file.blade.php` (9) and
`themes/stylique/views/main_file.blade.php` (2). Not a relaxation — those tags were outside the
census entirely until now, the count is asserted exactly so the list can only shrink, and adding a
tenth silent image to techzonix's home page fails the test. They are deferred rather than set to
`lazy` because the home page is the one storefront surface whose LCP element has **not** been
measured (`/{storeSlug}` answered 404 in the capture harness), and `loading="lazy"` on the element
that paints largest delays it by a round trip — which is the reason "lazy everything" was rejected
as a performance defect when the markup half was written. greentic's home page is absent from the
list because all six of its tags already declare a policy.

---

## 6. Blocked, and on whom

| what | why it is blocked | who decides |
|---|---|---|
| **AVIF** | `imageavif()` does not exist in this PHP build — measured. Nothing to engineer until the build changes | infrastructure / owner |
| **A CDN** | infrastructure: a host, a cache policy, a cost. The variants are plain files under `uploads/`, so a CDN in front of them needs no application change | owner |
| **Backfilling images already uploaded** | a write over live merchant data across every tenant. Designed, not run: iterate `products.cover_image_path` and `product_images.image_path` in store order, call `ImageVariants::generate()` per row (idempotent, skips what exists, never touches an original), with a per-store rate limit and a dry-run count first. Until it runs, an older image simply has no `<source>` and renders as it does today | owner |
| **Remote storage (`s3` / `wasabi`)** | the bytes are not on this filesystem, so `gd` cannot read them without a download, and writing variants back costs egress and storage decisions. Such an installation gets no variants and renders exactly as today | owner |
| **The other 68 storefront image tags** | each needs its own measured `sizes`; an invented figure makes the page *heavier* than before. The three measured elements are done | engineering, next slice |
| **A queue** | generation is synchronous because `QUEUE_CONNECTION=database` and nothing confirms a worker runs. Synchronous is bounded (five resizes, only when the path changes) and works with no worker; a queue would make the feature silently inert where none runs | engineering, if a worker is confirmed |

---

## 7. Found here, not fixed here

* **`Api\DashboardController` sets `$product->store_id = $request->store_id` on the product update
  path.** A product's tenant comes from request input. `StoredPathPolicy` happens to contain the
  blast radius for image paths (it validates the path against that same store id, so a mismatch
  refuses the save), and `ImageVariants` writes only into the folder the path names — so this slice
  adds no exposure. But the line itself is the shape CLAUDE.md §1 records as the source of four
  separate defects, and it is not this row's to fix.
* **`SecurityLogInventoryTest` is red on `int/checkpoint` from other workstreams**: 25 `Log::warning`
  lines in `ProductController`, `WoocomProductController`, `ModuleResourceOwnershipGuard`,
  `Utility`, `PackageServiceProvider`, `RefundIntegrityService`, `StoredUpload` and `ModuleTrust`
  have no entry in `docs/security/P01-OBSERVABILITY-INVENTORY.md`. The two lines this slice adds are
  documented and the `warning` heading re-derived (278 → 280).
* **`<x-product-card>` resolves to one copy per php-fpm process, not per store.** Measured: three
  stores with three different themes rendered in one process all got the *first* store's card. That
  is `FileViewFinder::find()`'s memoisation, already noted against `Theme::set()` in `GAP-117`, and
  it is why the card change had to be made in all four copies.

---

## 8. Evidence

| | |
|---|---|
| `tests/Unit/Media/ImageVariantPathTest` | 22 tests — where a variant may and may not go: the three roots, the doubled prefix, the store folder, 14 refused paths, the collision hash, the name's character set, and that the reader and writer derive the same name |
| `tests/Unit/Media/ImageVariantGenerationTest` | 15 tests — real `gd`, real files under `base_path('uploads')`. Every rung and no upscale, the original untouched byte for byte, idempotency by mtime, JPEG/WebP/GIF sources, a hand-built two-frame GIF skipped with a still control beside it, a hand-built EXIF orientation 6 applied, a recognised-but-undecodable ICO, the memory budget, another store refused, and a `variants` symlink refused |
| `tests/Feature/Storefront/StorefrontResponsiveImageRendersTest` | 15 tests — rendered through the real route, **both directions**, every assertion scoped to one `<picture>` block. Generation at save, every rung offered ascending, the two `sizes` not interchanged, the loading policy intact through the wrap, no `<source>` when there are no variants, a sibling store's folder refused, the cost guarantee, and the encoding/escaping |
| `tests/Unit/Ui/StorefrontPictureSourceTest` | 6 tests — every `<picture>` holds exactly one `<img>`, every `<source>` is built from the same image its `<img>` falls back to, every `sizes` is a measured constant, no call outside a `<picture>`, and the constants' own shape |
| `tests/Unit/Ui/StorefrontImageDeliveryTest` | 7 tests — the markup half's census, widened from 45 tags to 74 |
| `tests/Support/bin/mutate-gap274-pipeline.py` | 39 mutations, **39 caught** |
