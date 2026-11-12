# Phase 03 M0 — the storefront harness, and the first real baseline

**Branch:** `feature/phase-03-storefront`, cut from `main` at `4e878b8d` (the Phase 2.5 merge) ·
**Measured:** 2026-11-12 · **Evidence:** `evidence/storefront-baseline-2026-11-12/` (56 captures)

M0 exists because Phase 03's acceptance criteria assume the Phase 2.5 evidence standard and **the
Phase 2.5 harness cannot produce it for a customer-facing page.** This records what was built, what
it found, and — twice — what it found that turned out to be the harness rather than the product.

---

## 1. The gap that blocked everything

Measured on `main` before any of this existed:

| | |
|---|---|
| `.cjs` harnesses in the repository | **128** |
| ...that mention a customer at all | 6 |
| ...that drive `{storeSlug}/login` | **0** |

All six navigate to the **merchant** `/login` on the `web` guard. The storefront sign-in is a
different route, controller and guard — `routes/customer.php:106-109`,
`Customer\Auth\CustomerLoginController`, guard `customers`. So "capture the storefront as a
customer" was not a thing this repository could do, and no storefront claim before now rested on a
customer session.

**Two tools close it:**

- `tools/seed-storefront-fixture.php` — the repeatable customer-auth fixture. Idempotent,
  `--remove`-able, refused outright by `DevFixtureDatabaseGuard` outside a dev fixture, which
  matters more here than usual because it writes password hashes.
- `tools/storefront-matrix.cjs` — the capture harness. Signs in as a customer, walks the journey in
  both locales across three viewports, and records why a row is not evidence.

`aleppo-soap` had **no customer at all**, so there was no way to sign in to the second tenant and
therefore no way to run a cross-tenant negative test. The fixture creates one.

---

## 2. Twice, the first finding was mine

Both are recorded because the correction is the useful part.

### The locale control that controlled nothing

`ThemeLanguage::handle()` and `ViewServiceProvider:50` both read `Cookie::get('LANGUAGE')`, so
setting that cookie looks like the obvious control. It is not: **Laravel 11 encrypts cookies in the
`web` group and this application adds no `except` list**, so a raw cookie written by the browser
fails to decrypt and is silently discarded. The locale falls back to the store default.

Measured consequence: **seven of seven Arabic rows reported `dir="ltr"`** and the harness was about
to record "the storefront has no RTL" as a product defect. It has RTL. Driving the application's
own switch — `change.languagestore`, what the header's language dropdown links to — gives
`dir="rtl"` on every Arabic row.

The harness now **attributes** such failures: a page with `lang="ar"` and `dir="ltr"` is a product
defect; `lang="en"` when Arabic was asked for is the harness failing to switch the locale, and it
says so in those words.

### The baseline that was of an unstyled page

`themes/` sits at the **repository root**, not under `public/`. On the live host that needs no rule
— the document root *is* the repository root (P1-SEC-40 measured this), and the front-controller
rewrite is guarded by `RewriteCond %{REQUEST_FILENAME} !-f`, so Apache serves
`<root>/themes/.../all.min.css` directly. Note `themes` is deliberately **absent** from
`.htaccess`'s `^(css|assets|…|vendor)/` alternation; it does not need to be there.

`php artisan serve` serves `public/` only. So in development **23 images, 6 stylesheets and 4
scripts, all present on disk, all 404'd**, and the first baseline was a photograph of a storefront
with no theme CSS.

`serve-dev.sh` now creates a dev-only, gitignored `public/themes` symlink so development answers
what production already answers. Committing that symlink would put a second redundant path to the
same files on the live host, which is why it is ignored rather than tracked.

**The difference this made to the measurement:**

| | broken images, before | after |
|---|---|---|
| `aleppo-soap` | 70 of 98 (71%) | **0 of 98** |
| `damascus-textiles` | 933 of 1026 (91%) | 710 of 1026 (69%) |

---

## 3. The baseline

`storefront-matrix.cjs`, both tenants, journey routes `/home /product-list /collections /cart
/wishlist /track-order /contact-us`, signed in as a customer.

| store | rows | premise-fail | images | broken |
|---|---|---|---|---|
| `damascus-textiles` | 42 (2 locales × 3 viewports) | **0** | 1,026 | 710 (69%) |
| `aleppo-soap` | 14 (2 locales × desktop) | **0** | 98 | **0** |

Every row renders, in both languages, with the correct `dir`, at desktop, tablet and mobile. No row
is a redirect, a login form, a merchant-admin page or a blank.

### What is actually wrong

**1. A product with no image renders `<img src="">`.** All 60 remaining broken images on
`damascus-textiles/home` have an **empty** `src` — not a wrong path, not a 404, an empty string.
A browser renders that as a broken-image icon. The fixture is the reason there are so many (2 of 32
products have a `product_images` row), but emitting `src=""` instead of a placeholder is the
storefront's behaviour and would do the same to a real merchant mid-upload. **Product defect,
not just a fixture gap.**

**2. The second tenant is nearly empty, and it shows.** `aleppo-soap`'s `/home` renders 1,031
characters of text against `damascus-textiles`' 3,204, and `/product-list` 535 against 1,735. It has
no products. Its zero broken images are therefore *not* a clean bill of health — there is almost
nothing on the page to break. This is gap 7 of the Phase 03 inventory, still open.

**3. An unknown store slug answers 200 and serves another tenant's shop.**
`/no-such-store-p3/home` → HTTP 200, landed on `/damascus-textiles/home`. `getStore()`
(`helper.php:3576`) is `Store::where('slug', …)->first()` and returns **null** for an unknown slug;
something downstream then falls back rather than refusing. Storefronts are public, so this is not a
data leak — but a typo'd URL serving a different merchant's shop is wrong, and it is an
evidence-integrity hazard: a capture of a nonexistent store photographs a real storefront. The
harness records the store each row actually landed on, so it cannot be fooled by this; the
behaviour itself is **open**.

### Cross-tenant negative checks

| case | result |
|---|---|
| store slug that does not exist | 200, **served `damascus-textiles`** — finding 3 above |
| the other tenant's storefront, signed in here | 200, renders — **correct**, storefronts are public |
| the other tenant's cart, signed in here | 200, renders — correct for the same reason |
| the merchant admin, signed in as a customer | **redirected to `/login`** — correct, the guards are separate |

---

## 4. What M0 still owes

| gap (from the Phase 03 inventory) | state |
|---|---|
| 1 — the storefront has no dark mode at all | **open**, unchanged: 0 files in `themes/` reference `cust_darklayout`, `data-ss-theme` or `style-dark` |
| 2 — the state control does not drive the storefront | **closed** — the harness drives the application's own language switch |
| 3 — premise checks are merchant-admin shaped | **closed** — storefront-shaped checks, listed in §3 |
| 4 — no harness can sign in as a customer | **closed** |
| 5 — the fixture cannot render a credible storefront home | **partly** — it renders; `theme_customizes` still has zero rows, so content is still WorkDo demo fallback |
| 6 — almost no imagery | **open** — 2 of 32 products have an image |
| 7 — store 2 is empty | **open** — it can now be signed in to, but has no products |
| 8 — storefront RTL unverified | **closed, and it works** — `dir="rtl"` on every Arabic row once the locale is switched properly |

Still to do in M0: imagery and store-2 catalogue (6, 7), `theme_customizes` rows (5), and the
empty-`theme_id` fallback, which this fixture cannot exercise because **both** of its stores carry
`theme_id = 'stylique'`. The inventory recorded store 1 in the *production dump* as having an empty
`theme_id`; reproducing it needs a third fixture store, not a change to either of these.

---

## 5. Standing constraints, unchanged by any of this

No secret rotation, no live-host access change, no deployment, no history rewrite, no force-push.
The publicly served `.git` metadata and historical credentials on the test host remain
**OWNER-DEFERRED — UNRESOLVED** (Issue #120, and Issue #137 for the end-of-Phase-06 review).
Phase 03 is not merged to `main` and nothing here claims production readiness.

---

## 6. What the second pass found, and two corrections to section 3

**Measured 2026-11-12, after the harness worked.** Three of the four items below are product
defects the first pass could not see because the fixture could not reach them. One is a correction
to section 3's own wording.

### 6.1 CORRECTION — an unknown slug never served another tenant's shop

Section 3 finding 3 reads "an unknown store slug answers 200 and serves another tenant's shop".
**It does not, and the mechanism is worth recording because the same mistake is easy to repeat.**

`HomeController::storeSlug()` answered `redirect()->back()`. `redirect()->back()` reads the
`Referer`. The harness had navigated from `damascus-textiles/home`, so the unknown slug sent it
**back** there and it photographed a real storefront — which the report recorded as the storefront
*serving* one. Driven with no `Referer` the same route redirects to `/`, which redirects to the
**merchant** login at `/login`. No tenant's data was ever served for a slug that does not exist.

It was a soft 404 and a wrong-page defect, not a leak. That is a smaller finding than the one
reported, and recording the correction is the point: **a capture can be a photograph of the
harness's own navigation history.** This is the fourth time in Phase 03 that the first reading was
the tooling rather than the product.

### 6.2 Ten public routes did not fail closed — and the guard was already written

The full sweep: 45 public `{storeSlug?}` GET page routes, unknown slug, no `Referer`.

| answer | routes | verdict |
|---|---|---|
| **500** | `about` `address` `order` `wishlist` `support-ticket` | a crash |
| **302** → merchant login | `home` `login` `privacy-policy` `forgot-password` `reset-password` `error` | soft 404 |
| 404 | `blog` `cart` `checkout` `collections` `contact-us` `faq` `product-list` `track-order` `order-complete` | already correct |
| 200 | 5 AJAX fragments | already refuse **in the body** |
| 500 on a REAL slug too | `process-order` `support-ticket` `blogs/filter/view` | pre-existing, see 6.5 |

**The 500s were one bug in one place, and `abort(404)` was already written for it.**
`ViewServiceProvider`'s composer had `if (!$store) { abort(404); }` — **four lines after** three
statements that dereference `$store`. On PHP 8 "Attempt to read property on null" is a warning,
Laravel's handler promotes a warning to an `ErrorException`, and so the 404 three lines later was
**unreachable code that had never once executed**. `about_page()` does not even look the store up;
it crashed in the composer. Moving the guard above the dereferences fixed all five routes at once.

**The five AJAX fragments are deliberately unchanged.** `search-product` answers
`{"html_data":null,"message":"<store does not exist>"}` and `product-filter`
`{"product_count":0,"status":false,"html":""}`. Measured: 44–117 bytes, **0 id-bearing tokens** in
any of them, against 3.5–30 KB for a real store. A refusal in the body with HTTP 200 is this
codebase's documented convention — `ApiResponser::error()` returns 200 with `status: 0`, and the
shipped Flutter app force-logs-out on a new 401. They are already fail-closed.

**A PHP falsy-string rule hid a second defect in two places.** `!empty($storeSlug)` is false for
the string `'0'`, so a store whose slug is `0` was unreachable (403 "The Link is not active."), and
`request()->route('storeSlug')` was tested for truthiness in the composer with the same effect. The
regression test found the second one; reading had not.

| | before | after |
|---|---|---|
| unknown slug, 45 routes | 5× 500, 6× 302, 9× 404, 5× fragment-200 | **0× 500, 0× 302, 20× 404** |
| `Referer` from store A | 302 → store A | **404** |
| no `Referer` | 302 → merchant `/login` | **404** |
| `/0/home` | 403 | **404** |
| every known slug, both tenants | 200 | **200, unchanged** |

Pinned by `tests/Feature/Storefront/UnknownStoreSlugFailsClosedTest.php` — 35 cases, 65 assertions.
**Every unknown-slug assertion is paired with the same route on a real slug**, because this test's
whole subject is a 404 and without the pair a typo'd route name would satisfy all of it. All 8
pairs pass. Seven falsifications (one per fix) each fail exactly the cases that name them.

The refusal renders the framework's styled not-found page. A **branded** storefront 404 is M2 work
and is recorded as such, not as an oversight.

### 6.3 A store with no theme did not degrade — it crashed, on eleven of twelve routes

Gap "empty `stores.theme_id`" was recorded as **open, unreproduced**, predicted to give "base
layout, no `dir`, `themes//…` asset path". Reproducing it needed a third fixture store, because
both existing ones carry `theme_id = 'stylique'`.

`stores.theme_id` is `$table->string('theme_id')` — **not nullable** — so the empty case is the
empty **string**, and `''` is falsy. Measured on `p3fixt-no-theme`:

| | before | after |
|---|---|---|
| `/home` | could not resolve `main_file` | **200** |
| 11 other page routes | **500** (`Undefined array key "abouts_us_banner_status"`) | **200** |
| `themes//assets/…` URLs on `/home` | **6** — every stylesheet and script | **0** |
| `themes/stylique/assets/…` URLs | 0 | **30** |
| `<html dir>` | absent | `dir="ltr"`, and `rtl` for an Arabic store |

Three places each built an answer from the empty string, and now all three read the same existing
configuration — `config/theme.php`'s `'active' => 'stylique'`, which is what the theme resolver
falls back to anyway:

- `getThemeSetting()` built `themes//default_data/settings.php`, found nothing, and returned an
  array **with no keys** for views that index ~525 of them.
- `ActiveTheme::handle()` tested `$store->theme_id` for truthiness and so never called
  `Theme::set()`; no theme view root was prepended.
- the composer set `$currentTheme` to the raw empty string, and the layout builds every asset URL
  from it.

**This is not a new business rule.** No merchant's stored theme choice is altered, a store that has
a theme never reaches the fallback, and stored customisations are still looked up on the store's
**own** `theme_id` so a themeless store shows defaults rather than settings saved against some other
theme. Pinned by `EmptyThemeIdFallsBackTest.php` — 32 cases, 61 assertions, 12 positive controls,
three falsifications.

### 6.4 Both tenants' home pages were the same document

`theme_customizes` had **zero rows** (gap 5), so `getThemeSetting()` returned each theme's
`default_data/settings.php` verbatim and both storefronts read "Shop by Category / Featured
Products". Two different merchants' pages were identical above the product grid — which makes a
capture of one indistinguishable from a capture of the other, exactly what the capture-integrity
gates exist to catch.

20 rows now carry store-specific copy for the 24 keys `main_file.blade.php` actually reads. Only
the text keys; the repeaters keep the theme's own images, because inventing image paths here would
re-create the broken-image problem the previous commit fixed. Measured after: the two tenants'
`/home` differ by **2,722 lines**, and each store reads its own heading.

`theme_customizes` is **absent from `config/tenancy.php`'s `scoped_models`**, so it adds no store
predicate for anybody; the only thing keeping one merchant's copy off another's page is the explicit
`->where('store_id', …)` inside `getThemeSetting()`. `ThemeCustomizeIsStoreScopedTest.php` pins that
predicate — 6 cases, and removing it fails three of them. **M3 and M4 will add editor, preview and
publish paths that each need their own predicate; this fixture does not solve that.**

### 6.5 Three routes answer 500 for a store that DOES exist

`process-order`, `support-ticket` and `blogs/filter/view` answered 500 on both tenants before and
after this work. They are **pre-existing and out of this scope** — `process-order` is a payment
route and was deliberately not touched. Recorded for the M1 defect inventory.

---

## 7. What the independent review added

`.claude/agents/tenant-authz-reviewer` was run over the fail-closed diff before commit, as
`CLAUDE.md` requires for a change touching authentication or a route's middleware. **It found no
authorization or tenancy defect** — the change grants nothing, removes no check, and adds no
caller-supplied tenant predicate — and it confirmed by enumeration that no route or principal
existed for which `$store` was legitimately null and the page was expected to render.

It also found **four things the first pass missed**, three of them real.

### 7.1 `{slug}/register` had the same defect and was not in the sweep

A public storefront GET of exactly the same shape as the three auth forms already fixed. Measured:
**302 to the merchant login** for an unknown slug, 200 for a real one. It escaped the 45-route sweep
because the sweep's filter took only routes with no further parameters, and `register` is declared
`{storeSlug?}/register/{ref?}`. Fixed; in the test provider now.

### 7.2 `/order` answered 404 for a guest and 500 for a signed-in customer

The same URL, two different answers, decided by who asked. `AccountProfileController::order_list()`
reads `$store->id` in the query it builds for an authenticated customer — **before any view
renders** — so the composer's guard could not reach it. A guest took the empty-`collect()` branch
and was refused later by the composer. Fixed by refusing before the read, and the test now drives
**both** principals and asserts they get the *same* status.

### 7.3 The composer was the only store check for four handlers

`about_page()` (which never looks the store up at all), `wishlist()`, `address()` and
`order_list()`'s guest branch had no refusal of their own. That works, but it is one
`return response()->json(...)` away from silently disappearing with nothing noticing.

`about_page` now checks for itself. **And this is recorded honestly: removing that check leaves
every route case GREEN**, because the composer still catches it — measured. The handler check is
redundancy, not the load-bearing control, and no behavioural test can tell the two apart. A source
pin asserts the redundancy exists, which is the only thing that *can* be asserted about it.

### 7.4 The positive control was too weak to see the one regression this fix could cause

The review was right and this is the most useful of the four. The control asserted only "neither 404
nor 500", so a 302 **or the new `abort(503)` theme arm** would have satisfied it — meaning the one
plausible failure mode, *refusing a store that exists*, was exactly the mode the control could not
detect. It is now `assertOk()` across all eleven routes.

Two of the review's own suggestions were **checked and not acted on**, with reasons:

- it reported `EmptyThemeIdFallsBackTest` as red and undeclared. It was reading the tree mid-change,
  before the `helper.php` half was written. 32 passed, 61 assertions.
- it noted `wishlist()` does an unscoped `Wishlist::with('ProductData')->get()`. Verified **not a
  leak** — the view never renders `$wishlists` (the table body is AJAX-filled) and no theme
  overrides it. A wasted full-table read; recorded, not fixed here.

### 7.5 A FINDING FOR THE OWNER — every Arabic store name produces the slug `-`

Raised by the review and then measured step by step. `User::slugs()` keeps `\p{L}` (so Arabic
survives), tests `empty()`, and **then** strips every non-ASCII character:

| step | `متجر دمشق` | `Damascus Textiles` |
|---|---|---|
| 1 keep letters/numbers | `متجر دمشق` | `Damascus Textiles` |
| 4 spaces → hyphens | `متجر-دمشق` | `damascus-textiles` |
| 5 `empty()` fallback | **did not fire** | did not fire |
| 6 **strip non-ASCII** | **`-`** | `damascus-textiles` |
| → final slug | **`-`** | `damascus-textiles` |

The intended `store-<uniqid>` fallback is written at step 5 and **can never be reached**, because
the string is still non-empty there. `صابون حلب` also gives `-`; the mixed `متجر Damascus` gives
`-damascus`.

**This is not only stores.** `Product::slugs()`, `Tag::slugs()`, `ProductBrand::slugs()` and
`ProductLabel::slugs()` are the same method, and `Api\DashboardController:7369` is the **admin app's**
store-creation path — so a merchant naming anything in Arabic through the shipped Flutter app gets
a degenerate slug. `getRelatedSlugs()` then de-duplicates with `LIKE '<slug>%'`, so the second
Arabic shop becomes `-1`. It is also directly relevant to **P1-SEC-61**, whose root cause is
`products.slug` not being unique.

**NOT FIXED HERE, and that is a deliberate scope call.** The repair is two lines, but it decides
what an Arabic merchant's storefront URL *should* be — a transliteration (`matjar-dimashq`; the
`transliterator_transliterate` call for exactly this is commented out in the source, presumably
because `intl` was unavailable) or an opaque `store-<uniqid>`. That is a product decision about
public URLs for the platform's primary language, it affects products, tags and brands as well as
stores, and the owner's standing rule reserves such decisions. **Escalated, with the measurement
above, for an owner decision.** No existing slug would change either way; only newly created rows.

---

## 8. The second capture pass

`storefront-matrix.cjs`, **three** stores, 2 locales x 3 viewports x 7 journey routes, signed in as
each store's own fixture customer. Evidence:
`evidence/storefront-baseline-2026-11-12b/<slug>/`.

| store | theme | rows | premise-fail | images | broken |
|---|---|---|---|---|---|
| `damascus-textiles` | `stylique` | 42 | **0** | 1,026 | **0** |
| `aleppo-soap` | `stylique` | 42 | **0** | 528 | **0** |
| `p3fixt-no-theme` | **`''`** | 42 | **0** | 294 | **0** |
| | | **126** | **0** | **1,848** | **0** |

Against the first pass: `aleppo-soap` went from 14 rows to 42 (it had no catalogue, so there was
nothing to capture at tablet or mobile), `damascus-textiles` from 710 broken images to 0, and the
third store did not exist. Every Arabic row carries `dir="rtl"`; every English row `dir="ltr"`;
three theme stylesheets on every row.

**The headline copy is still the theme's.** `slider_repeater` was deliberately left alone, so
`damascus-textiles`' hero reads "Accessorize with Elegance" — a jewellery slogan on a textile shop.
The 20 seeded rows cover the section titles, which is what makes the two tenants distinguishable;
the hero is **M2 content work** and is recorded here rather than quietly left.

Five non-200 references remain on `/home`, **none of them images**: four links to category routes
that do not exist (`/{slug}/textiles`, `/{slug}/soaps`, …) and `/public/js/flipdown.js`. Those are
storefront defects for the M1 inventory, not capture failures — the premise checks pass because the
page itself renders.

### 8.1 The capture-integrity gates did not cover this evidence, and said so cleanly

Running `phase-2.5/tools/check-distinct-surface-captures.py` and
`detect-refusal-toast-captures.py` with a Phase 03 path printed **the same clean Phase 2.5 result
three times**, naming a merchant coupon modal. Both tools **hardcode**
`docs/development/phase-2.5/evidence` and had been silently ignoring the path argument. A clean
report for evidence a tool never opened is worse than no report.

`check-distinct-surface-captures.py` now takes an optional evidence root. CI invokes it with no
arguments (`.github/workflows/ci.yml:347`), so the Phase 2.5 gate is byte-for-byte unchanged —
verified: still 2,558 captures scanned by default.

The refusal detector was **not** repointed. It resolves surface names through
`build-coverage-matrix.py`, which is Phase 2.5-specific, so it would produce nothing meaningful
here. `storefront-matrix.cjs`'s own premise checks are the storefront equivalent and are
purpose-built for it — login form, admin chrome, left-the-store, blank, missing `dir`, broken theme
asset, locale-did-not-take. That is stated rather than papered over.

### 8.2 TWENTY-EIGHT CAPTURES WERE BYTE-IDENTICAL ACROSS STORES, and the Phase 2.5 check is right to miss it

Once the distinctness check actually read the Phase 03 evidence, it reported 126 captures with 98
distinct contents and **no collision** — correctly, by its own rules. Looking at the 28 identical
groups by hand showed every one of them spanned **two different stores**:
`aleppo-soap_cart__ar_mobile.png` and `p3fixt-no-theme_cart__ar_mobile.png`, and so on for `cart`,
`wishlist`, `track-order` and `contact-us`.

**The Phase 2.5 tool groups candidate collisions by DIRECTORY FIRST, deliberately** — there, a
different directory meant a different capture *wave*, and the same merchant surface shot twice
across waves is legitimately identical. In Phase 03 a different directory means a different
**store**, so that grouping is exactly backwards for this evidence: a cross-directory collision is
the single most important thing to catch.

**It was not a tenancy fault.** Both pages were correct. The storefront header renders
`themeSettings['header_logo']`, neither fixture store had set one, so both fell back to the
**theme's** logo — and the two pages' HTML differed by 99 lines of which every single one was a
`csrf-token` or an `og:url`. Nothing visible. The storefront behaviour is right (a merchant who has
uploaded no logo should get the theme's, which is why §6.3's image work deliberately left
theme-setting images alone); the **fixture** was unrealistic.

Three committed, deliberately synthetic logos (`uploads/p3fixt/logo-<slug>.png`, flat colour plus
initials, 1.2–2.4 KB) are now seeded into each store's `logo`, `header_logo` and `footer_logo`.

**And a new gate exists so this cannot come back:**
`phase-3/tools/check-cross-store-captures.py`. It groups by content and fails on any image shared
between stores. It refuses to report clean on a premise failure — fewer than two stores, zero
parsed captures, or any filename it cannot parse, because an unparsed name is a capture it is not
covering. Verified against the pre-logo evidence: it found all 28.

**This is the failure mode worth naming: evidence that is individually valid and collectively
worthless, because no reader can tell which store a screenshot is of.** Every premise check passed
on all 28.

---

## 9. M0 closure — the eight gaps, and what the suite now holds

| # | gap | state |
|---|---|---|
| 1 | the storefront has **no dark mode at all** | **open — M2 by design.** `grep -rln "cust_darklayout\|data-ss-theme\|style-dark" themes/` is still zero files across all three themes |
| 2 | the state control does not drive the storefront | **closed** — the harness drives `change.languagestore`, the application's own switch |
| 3 | premise checks are merchant-admin shaped | **closed** — storefront-shaped, and a locale failure is now *attributed* to harness vs product |
| 4 | no harness can sign in as a customer | **closed** — 0 of 128 `.cjs` harnesses could; now one does, for three stores |
| 5 | `theme_customizes` has 0 rows, so the fixture cannot render a credible storefront | **closed** — 29 rows; the tenants' `/home` differ by 2,722 lines and each store shows its own logo |
| 6 | almost no imagery (2 of 32 products) | **closed** — `storefront_image()` at 22 merchant-owned sites, committed placeholders; 710 broken → **0** |
| 7 | store 2 is empty | **closed** — 3 categories, 2 brands, 8 products, one deliberately out of stock |
| 8 | storefront RTL unverified | **closed, and it works** — `dir="rtl"` on all 63 Arabic rows, including the themeless store |

**Seven of eight closed. Gap 1 is M2 work and is not claimed.**

### The tests this milestone leaves behind

| file | cases | what it would catch |
|---|---|---|
| `UnknownStoreSlugFailsClosedTest` | 46 | an unknown slug 500ing, bouncing, or refusing a store that exists |
| `EmptyThemeIdFallsBackTest` | 32 | a store with no theme crashing, losing `dir`, or emitting `themes//` |
| `StorefrontImageFallbackTest` | 20 | `<img src="">` returning to a merchant-owned picture |
| `ThemeCustomizeIsStoreScopedTest` | 6 | one merchant's storefront copy reaching another's page |
| | **104** / 274 assertions | |

Every one of the eleven fixes in this milestone was **falsified individually** — reverted, the
naming case observed to fail, restored. Two of those falsifications caught a test of my own that
passed for the wrong reason: a `Log::shouldNotHaveReceived` arity match in the Phase 2.5 work, and
here a `/function about_page.*?getStore\(/s` regex whose `.*?` crossed the closing brace and matched
the *next* handler's call. **Both were invisible to reading and only the revert found them.**

### What M0 does NOT claim

- no dark mode anywhere on the storefront (gap 1)
- the hero/slider copy is still the theme's demo content; only section titles are per-store
- an unknown-slug 404 is the framework's page, not a branded storefront 404
- `process-order`, `support-ticket` and `blogs/filter/view` answer **500 for a store that exists** — pre-existing, untouched, M1
- four category links and one script 404 on `/home` — pre-existing, M1
- **every Arabic store name still produces the slug `-`** (§7.5) — escalated for an owner decision, not fixed
- Phase 03 is not merged to `main`, and nothing here claims production readiness

The publicly served `.git` metadata and historical credentials on the test host remain
**OWNER-DEFERRED — UNRESOLVED** (#120, #137). Verified unchanged by this branch: both `.htaccess`
files are **directive-identical to `origin/main`** — 0 differing non-comment lines — so merging it
cannot arm the dormant Apache rules, and `DumpAndVcsPathsAreDeniedTest`'s 32 cases still pass.
