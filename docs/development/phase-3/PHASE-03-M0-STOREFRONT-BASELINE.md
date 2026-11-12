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
