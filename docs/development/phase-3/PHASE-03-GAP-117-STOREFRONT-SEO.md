# GAP-117 / P03-MP-01 — storefront SEO, indexing and structured data

**Date:** 2026-11-26 · **Row:** `GAP-117` / `P03-MP-01`, recorded `NOT IMPLEMENTED`
**State after this change:** PARTIALLY IMPLEMENTED — three of the five things the row's own next
action names are built and measured; one is refused on measured grounds and one is an owner
decision. §7 names every omission.

---

## 1. What the requirement actually is, and where it is

The traceability section for `P03-MP-01` cites **CLAUDE.md §4** and states *"the plan itself is not
in the repo"*. **That sentence is now stale.** The owner-approved plan was recovered on 2026-11-16
and committed under `docs/development/master-plan/`, and the obligation is a single Arabic bullet —
the **last** bullet of Phase 03 section **`03-B` — تجربة العميل** (customer experience), decision
family **`CUST-001` … `CUST-008`**:

| file | line |
|---|---|
| `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md` | 222 |
| `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md` | 236 |
| `docs/development/master-plan/DECISION-ID-REGISTER.md` | 365 |

> `- تحسين SEO والفهرسة والأداء والصور والبيانات المنظمة.`
>
> "Improve SEO and **indexing** and **performance** and **images** and **structured data**."

**So the requirement is RECOVERABLE and was not invented.** `MP` in the row id means **Master
Plan**, not marketplace. Three further facts the recovery settles:

1. **It is five obligations, not one.** The register's summary ("storefront SEO") loses two of them.
   *Performance* (الأداء) is already tracked as `P03-E-02` / `GAP-112`; *images* (الصور) belong with
   the theme/asset work. What was never built, dispositioned or reported is **SEO + indexing +
   structured data**, and that is what this change addresses.
2. **`DECISION-ID-REGISTER.md` records the mapping as FAMILY-LEVEL ONLY** — section `03-B` cites
   eight ids against nine bullets, so *which* of `CUST-001`…`CUST-008` carries this bullet is not
   derivable. The family's obligation is recovered in full; the per-id pairing is not, and is not
   guessed at here.
3. **A second, narrower SEO obligation exists for the platform's own website**, in Addendum B
   (`MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:616`): *"Premium visual treatment across every
   official-site page … Arabic/English, performance, SEO, trustworthy copy."* That is a different
   surface from the merchant storefront and is **out of scope here** (§7).

The register's own next action enumerates five deliverables — *"canonical, hreflang for ar/en,
sitemap, robots policy, product/organization structured data"* — and is the scoping this change
works to.

### Measured baseline, before any of this existed

| thing | occurrences in the shipped tree |
|---|---|
| `rel=canonical` | **0** in all four storefront layouts |
| `<meta name="robots">` | **0** |
| `application/ld+json` / `schema.org` | **0** under `resources/views/` or `themes/` |
| a sitemap route | **0** (`grep -rni sitemap routes/ app/Http/Controllers/`) |
| `public/robots.txt` | the vendored allow-all: `User-agent: *` / `Disallow:` |

Every storefront page of every store was served with no canonical URL, no indexing directive and no
structured data. `GAP-118` wired the merchant's own title/description `<meta>` into the same `<head>`
on 2026-11-25; this is the half that row's next action named and left.

---

## 2. Files changed

| file | what |
|---|---|
| `app/Support/Storefront/StorefrontSeo.php` | **new.** Canonical URL, robots directive, Organization JSON-LD, and the route→verdict classification |
| `app/Support/Storefront/StorefrontSitemapRoute.php` | **new.** The sitemap's route declaration, owned by first-party code (§5) |
| `app/Http/Controllers/Storefront/SitemapController.php` | **new.** One store's XML sitemap |
| `app/Helper/helper.php` | `metaKeywordSetting()` appends the block (§3). ~27 added lines, all inside that function |
| `tests/Feature/Storefront/StorefrontSeoIndexingTest.php` | **new.** 47 tests, 149 assertions |
| `tests/Feature/Storefront/StorefrontSitemapTest.php` | **new.** 15 tests, 123 assertions |
| `tests/Unit/Seo/StorefrontSeoRouteCensusTest.php` | **new.** 7 tests, 13 assertions — the blocking-gate ratchet |

`routes/web.php` is **not** changed; the one line it needs is handed over in §5.

---

## 3. Where the markup is emitted, and why it is not a Blade edit

`metaKeywordSetting()` is the **only seam called by all four copies** of
`front_end/layouts/app.blade.php` — line 12 of each of
`themes/{stylique,greentic,techzonix}/views/front_end/layouts/app.blade.php` and of
`resources/views/front_end/layouts/app.blade.php`. The other candidate,
`@include('front_end.partials.appearance')`, is in only **three** of the four; the `resources/views`
fallback omits it, recorded in that file's own header.

CLAUDE.md records that a layout-level storefront change made in fewer than four places silently does
nothing for the other themes, so four-of-four is the seam to use — and it keeps the change out of
`themes/`, which this work package does not own.
`StorefrontSeoIndexingTest::test_every_theme_emits_the_seo_block` drives each installed theme, so
that claim is measured rather than reasoned.

**Acknowledged cost:** the function's name is now narrower than what it emits. The SEO facts
themselves live in `App\Support\Storefront\StorefrontSeo`; the helper only prints them.

---

## 4. What was built

### 4.1 `rel=canonical`

Built from the **route name and its parameters**, never echoed from the request. Two consequences,
both measured:

* **The store slug is normalised to `stores.slug`.** `Store::where('slug', $raw)` runs under the
  column's collation. On MySQL that is `utf8mb4_*_ci`, so `/SEO-STORE-A/home` resolves the same
  store and serves the same page as `/seo-store-a/home` — one page, two URLs. **This suite cannot
  see it:** measured on SQLite, `Store::where('slug','PROBE-A')->exists()` is `false`, because
  SQLite's default collation is BINARY, so the upper-cased URL 404s here and answers 200 in
  production. That is CLAUDE.md's *"SQLite hides real MySQL defects"* in its collation form. The
  normalisation is what fixes it; proving the fix needs a real MySQL (§8).
* **Every query parameter is dropped except a validated `page`.** `utm_*`, `fbclid`, `gclid` and the
  storefront's own filter parameters do not change which page this is. `page` survives only for the
  four routes that really paginate — measured: `HomeController::product_page` ends in
  `->paginate(12)` (line 1377) and `blog_page` in `->paginate(6)` (line 1221). Page 1 is not spelled
  out; `page` is parsed with `FILTER_VALIDATE_INT`, not `is_numeric`.

The canonical also agrees with the `og:url` `metaKeywordSetting()` already emitted; a canonical that
disagreed with its own page's `og:url` is its own defect.

### 4.2 The robots directive — the الفهرسة half

`index, follow` for the 13 content routes; `noindex, follow` for everything else. `follow` is kept in
both: a withheld page's links are still worth following.

**It fails closed.** An unclassified or unnamed route is withheld, because a new private page
silently entering the search index is the expensive direction. The *other* direction — a new content
page silently never indexed — is caught by `StorefrontSeoRouteCensusTest`, which reddens the
**blocking Unit gate** until somebody rules on the route.

**Two things the census found that reading the routes file did not:**

* **`theme.draft.preview`** (`theme-draft/{storeSlug}/{version}/preview`) renders the storefront
  layout for a theme version the merchant has **not published**. Indexing it would publish a store's
  unreleased design. Its URI does not *begin* with the slug, so the census's first derivation ("first
  URI segment is a parameter") missed it entirely — which is the whole argument for keying the census
  on the `storeSlug` **parameter**, the same question `StorefrontSeo::forRequest()` asks.
* **`store.get.Powertranz.status`** (`store-get-Powertranz-status{storeSlug?}`), a gateway return
  leg, for the same reason.

The first derivation was also too **wide**: four merchant-panel routes begin with `{id}`
(`{id}/stores-link`, `{id}/free-shipping-edit`, `{id}/local-shipping-edit`,
`{id}/{amount}/plan-paypal-payment`). They are now declared by name with a reason, and a fifth fails
the gate so somebody reads it.

**An inactive store is withheld too, and it is still reachable — which took measuring twice.**
`ActiveTheme` looks the store up with `->where('is_active', 1)` but its
`abort('403', 'The store is not active.')` **is commented out**, and `ViewServiceProvider`'s composer
resolves the store with no `is_active` predicate at all. So nothing refuses a deactivated store.
The first version of the test asserted 200 and got **503**, and the reason is worth keeping:

* `main_file` exists only in `themes/<theme>/views/`, never in `resources/views/`.
* For an inactive store `ActiveTheme` never calls `Theme::set()`, so no theme view root is prepended
  and `HomeController::landing_page:1163` takes `abort(503, 'The store theme is not available.')`.
* But `Theme::set()` prepends a location that survives for the rest of the **process**, and the
  storefront view composer calls it for every store it resolves. So once a worker has served **any**
  active store on the same theme, the deactivated store's pages render at **200** — measured both
  ways.

In production that is a persistent php-fpm worker serving many stores, so **the 200 path is the
ordinary one**: a deactivated shop really does keep serving its storefront, and the `noindex` is the
only thing keeping a switched-off shop out of the index.

### 4.3 Structured data — البيانات المنظمة

One `Organization` block (`name`, `url`) on indexable pages only, named for **the store in the URL**.

**The JSON-LD block is a script body, which is a different sink from an attribute.** `e()` — the
escaping `GAP-118` put on the neighbouring `<meta>` values one row earlier — is the **wrong** tool
inside `<script>`: the HTML parser does not decode entities there, so an HTML-escaped `</script>`
reaches the JSON parser as the literal text `&lt;/script&gt;` (invalid data), while a **raw**
`</script>` in a store's name closes the element and spills the rest into the document as markup.
`stores.name` is merchant-supplied through three writers, so this is reachable input.

`JSON_HEX_TAG` is the load-bearing flag: `<` and `>` become `<` / `>` — valid JSON that
decodes back to the original string and that no HTML parser can read as a tag, a comment opener or a
script-data escape. Both mutations (dropping `JSON_HEX_TAG`; using `e()` instead) are caught.

### 4.4 The per-store XML sitemap

`GET /{storeSlug}/sitemap.xml` → the store's own content pages, its active categories and its active
products, with `<lastmod>`.

**It is per store, and that is not a style choice.** The storefront is multi-tenant by **path
prefix** on one host, so a single host-root `/sitemap.xml` would have to enumerate every tenant's
catalogue in one document — a cross-tenant disclosure and unbounded in size.

**An ampersand in a product slug would otherwise destroy the whole document.** The first draft of the
controller's comment called the XML escaping defensive, on the assumption that `route()` encodes
everything. Measured:

```
route('page.product', ['amp-store', 'a&b<c>d'])
  => http://localhost/amp-store/product/a&b%3Cc%3Ed
```

`<` and `>` come back percent-encoded. **`&` does not.** A bare `&` in XML character data is not an
entity reference, so one product slug containing an ampersand makes the **whole** sitemap malformed
and a crawler rejects the whole file. `products.slug` is a plain `string` column with no constraint
and more than one writer. The escaping is therefore load-bearing, and pinned.

---

## 5. The one line `routes/web.php` needs

`routes/web.php` was shared with another work package this round, so it is untouched. **It was never
edited and nothing was reverted.** The declaration lives in first-party code; the routes file needs
exactly this, **after `require __DIR__ . '/customer.php'` (line 1994), outside any group, at the tail
of the file**:

```php
// GAP-117 / P03-MP-01 — the per-store XML sitemap. Declared from first-party code so the URI, the
// name and the action have one owner; see App\Support\Storefront\StorefrontSitemapRoute. Must stay
// AFTER the customer.php require: a later declaration replaces an earlier one for the same
// method+URI, and no existing route matches `{storeSlug}/sitemap.xml` (`page/{page_slug}`,
// `product/{product_slug}` and the rest all have a literal second segment), so this adds a slot
// rather than taking one. Outside a group it takes the `web` group bootstrap/app.php maps this file
// to, which is what the storefront's other routes carry.
\App\Support\Storefront\StorefrontSitemapRoute::declare();
```

**The tests drive the real declaration, not an imitation.** `declare()` is guarded by `Route::has()`,
so the test files call it themselves today and that call becomes a no-op the moment the line above is
in place. A test that wrote its own `Route::get(...)` would have proved the controller works at a URI
of the test's choosing and nothing about the one production serves.

`declare()` also calls `Route::getRoutes()->refreshNameLookups()`, and that is **not** a test
artefact: `RouteCollection::add()` runs inside `Route::get(...)`, before `->name()` has put the name
on the action, so `addLookups()` registers no name. The framework papers over this with one
post-boot refresh, so a declaration made *after* boot leaves `Route::has()` false and `route(...)`
throwing. Measured: without that line every case in `StorefrontSitemapTest` failed on its own
`Route::has()` premise. The mutation that removes it is caught.

---

## 6. Evidence

### 6.1 Failing first — measured, not asserted

The three files were written and run **before** any production code existed:

| file | baseline |
|---|---|
| `StorefrontSeoIndexingTest` (then 46 cases) | **43 failed, 3 passed** |
| `StorefrontSitemapTest` (then 11 cases) | **10 failed, 1 passed** |
| `StorefrontSeoRouteCensusTest` (then 5 cases) | **4 failed, 1 passed** |

The five that passed at baseline are exactly the cases that are correct about an **absence** — no
ld+json on a withheld page, no hreflang, an unknown slug having no sitemap, and the census's own
reachability premise. The "identical in Arabic and English" case was **vacuous at baseline** (both
absent) and is noted as such.

### 6.2 Green now

```
tests/Feature/Storefront/StorefrontSeoIndexingTest.php    47 passed (149 assertions)
tests/Feature/Storefront/StorefrontSitemapTest.php        15 passed (123 assertions)
tests/Unit/Seo/StorefrontSeoRouteCensusTest.php            7 passed ( 13 assertions)

tests/Feature/Storefront/ + tests/Unit/Seo/              317 passed (1455 assertions)
tests/Unit/Routing/ added to the same run                 319 passed (1458 assertions)
tests/Unit/Routing/ tests/Unit/Tenancy/ tests/Unit/Ui/  1413 passed, 1 failed — NOT THIS CHANGE (§6.5)
the four line-keyed inventories                          106 passed ( 194 assertions)
```

The four inventories CLAUDE.md §6b warns about were run because `app/Helper/helper.php` grew ~27
lines: `SecurityLogInventoryTest`, `ResidualTenancyExposureTest`,
`EloquentScopeBypassInventoryTest`, `StoredFilePathSafetyTest`. All 106 pass — the two
`app/Helper/helper.php:NNN` anchors in `P01-OBSERVABILITY-INVENTORY.md` are lines **444** and **687**
and the edit is inside `metaKeywordSetting()` at ~1333, below both, so nothing moved.

### 6.3 Mutation testing — 36 applied one at a time, **36 caught**

Through `tests/Support/bin/mutate.py`, which asserts each anchor occurs exactly once and rejects a
run whose `--filter` matched no test. Grouped: the seam removed (2), tenancy (4), the canonical (6),
the robots directive (5), structured data (5), the sitemap (11), the census and the comment trap (3).

**One mutation survived the first run, and it was a real coverage gap rather than an equivalent
mutant.** Substituting `Store::find(getCurrentStore()) ?? …` in the sitemap controller — the exact
trap CLAUDE.md warns about — passed every case, because no case held a `web`-guard principal, so
`getCurrentStore()` answered null and the `??` fell through to the correct lookup. Under that
mutation, merchant A fetching store B's sitemap was served **store A's catalogue at store B's URL**:
a cross-tenant product-list disclosure in a document whose purpose is to be published.
`test_a_merchant_holding_a_panel_session_gets_the_sitemap_of_the_store_in_the_url` was added and the
mutation is now caught.

**The harness also refused three runs rather than lying about them.** Adding
`->whereNull('product_type')` (§6.6) displaced three mutation anchors; they reported
`anchor x0 — NOT APPLIED, proves nothing` and `anchor x2`, were re-pointed, and all three then
caught. That is the guard CLAUDE.md records three earlier runs being meaningless for want of.

### 6.4 A real browser, both directions

libxml (the PHPUnit oracle) parses HTML4; a browser re-parses as HTML5, and CLAUDE.md records that a
balanced, allowlisted libxml output can still close the host page's elements once a browser sees it.
Seven rendered pages were loaded in Chromium via Playwright — three store names (plain, Arabic with
quotes and an ampersand, and `Soap </script><img src=x onerror="window.PWNED=1"> House`) × both
locales, plus a withheld page:

```
OK  plain-en     dir=ltr lang=en ldjson=1 elementsInBlock=0 pwned=false strayImg=0 robots="index, follow"
OK  plain-ar     dir=rtl lang=ar ldjson=1 elementsInBlock=0 pwned=false strayImg=0 robots="index, follow"
OK  arabic-en/ar  name = "صابون حلب الأصلي \"الممتاز\" & المزيد"        (round-tripped exactly)
OK  hostile-en/ar name = "Soap </script><img src=x onerror=\"window.PWNED=1\"> House"
OK  cart-en      dir=ltr lang=en ldjson=0 elementsInBlock=0                robots="noindex, follow"
```

`window.PWNED` undefined, zero stray `<img src=x>`, zero elements inside the ld+json block, and
`JSON.parse` succeeded on every indexable page. **Both writing directions render** (`dir=rtl`/`ltr`
asserted on the same store).

Six `ReferenceError: $ is not defined` messages appear on every page. **They are a `file://`
artefact and pre-exist this change** — proved with a control: the same pages re-rendered with the
seam removed produce the identical six errors (and `ldjson=0, robots=null`, the §1 baseline).

**No contrast check applies, and that is a statement about the change, not an omission.** The slice
adds only `<head>` metadata and paints nothing. Measured: a diff of one page rendered with and
without the block differs by **exactly** the three added lines, plus the per-request CSRF token and
asset cache-buster. Body text is unchanged.

### 6.5 One failure that is not this change

`tests/Unit/Ui/StorefrontDarkModeLayerTest::test_no_rule_applies_outside_an_appearance_scope_except_the_declared_one`
fails. Attribution, for the integrator:

* it reads only `public/css/storefront-appearance.css` and theme view files;
* that CSS file's mtime is **2026-11-26 02:29:39**, *after* this change's last edit (02:27:23), and
  this work package never touched it — another package was editing it concurrently;
* the test contains **zero** references to `helper.php`, `StorefrontSeo`, `Sitemap` or
  `metaKeywordSetting`;
* the failure body shows **prose from the stylesheet's own comments** in the selector list, i.e. that
  file's new comment defeats the test's `/* */` stripper — CLAUDE.md's "a census can be broken by
  prose about the thing it measures", in its CSS form.

It belongs to whoever is editing that stylesheet.

---

### 6.6 The `tenant-authz-reviewer` pass — run by hand, and it found two things

**No agent-spawning tool was available in this session**, so the eight defect shapes in
`.claude/agents/tenant-authz-reviewer.md` were worked through by hand against the changed code. The
integrator may still want the real reviewer run; the pass below is what it would be checking.

| shape | verdict |
|---|---|
| 1 · guard-class trap | **clean.** This change calls `auth()` nowhere. The only `auth`/`getCurrentStore` occurrences in the three new files are two docblock sentences explaining why they are not used |
| 2 · tenant predicate from caller input | **clean, and the distinction matters.** The slug is caller-supplied, but it is the *subject* of a public page, not a predicate on another principal's rows — there is one caller value, not two compared against each other, and everything served belongs to the store that slug names |
| 3 · relying on the global scope | **clean by construction.** `Product` and `Category` are the two models whose `StoreScope` is inert, and both queries carry an explicit `where('store_id', …)`; `Store` carries no scope and is looked up by slug |
| 4 · permission checks | **n/a.** No permission is added, invented or gated on — correct, because a public shop's pages and its sitemap are public. In particular no new name needed seeding in `Utility.php`, so there is no gate that would refuse everybody |
| 5 · credential and token boundaries | **n/a.** Nothing touches a password, an email, a token or a session |
| 6 · order, payment, account ownership | **n/a.** Nothing read or written |
| 7 · API authorization and session handling | **TWO FINDINGS, both in this change's own new route, both fixed** — below |
| 8 · tests that cannot fail | **addressed by execution:** the failing-first baseline (§6.1) plus 36 mutations (§6.3). The one test that was vacuous at baseline is named in §6.1 |

**Finding 7a — the new route was unthrottled (hardening, fixed).** An anonymous GET running two
queries bounded only by `SITEMAP_MAX_URLS` — up to 50,000 product rows and as many category rows —
where the storefront's own list paginates at 12, and every store's slug is public. That is a cheap
amplifier. `throttle:30,1` was added, matching the precedent two routes above it in
`routes/customer.php` (the public unsubscribe GET carries the same per-IP limiter for the same
stated reason). Pinned by `test_the_sitemap_route_is_throttled` and mutation-tested. **Confidence:
certain on the shape, speculative on the impact** — no load measurement was taken, so this is
hardening, not a demonstrated outage.

**Finding 7b — the sitemap advertised products the shop itself does not list (correctness, fixed).**
The first draft filtered `status = 1` but not `product_type`. **Every** host storefront product
listing carries `->where('product_type', null)` — `HomeController` lines 1172, 1251, 1294, 1332,
1459 — so an `'auction'` or `'digital'` product (`ModuleResourceOwnershipGuard:128,253`) never
appears on the shop's own list, while `product_detail` has no such predicate and *will* serve it by
direct URL. A sitemap listing them would therefore have **changed their discoverability rather than
described it**. `whereNull('product_type')` now matches the storefront exactly; pinned by
`test_the_sitemap_lists_only_the_product_kinds_the_shop_itself_lists`, with a control leg so it
cannot pass by the handler returning nothing. **Confidence: certain.**

**One pre-existing finding noted and deliberately NOT fixed:** the ImportExport module writes
`'product_type' => $row['product_type'] ?? ''` (`ProductsImportData:148`) — the empty **string** —
and `where('product_type', null)` compiles to `is null`, which `''` does not satisfy. So an ordinary
product imported without a declared type is invisible on the storefront's own product list today,
and is therefore absent from this sitemap too. Consistent with the shop, and both are wrong. Out of
scope here; recorded rather than silently diverged from.

## 7. What is NOT done, by name

1. **hreflang for ar/en — REFUSED ON MEASURED GROUNDS, not skipped.**
   `LanguageController::changeLanquageStore()` is the storefront's language switch and its entire
   body is `Cookie::queue('LANGUAGE', $lang, 1440)`, a session write and `redirect()->back()`. The
   language is a **cookie**; there is no per-language URL. An hreflang annotation must name a
   distinct URL per language version, so with one URL serving both, every alternate would point at
   the page itself — self-contradictory where it is read at all. Giving the storefront a URL-level
   language dimension is a **public-URL-shape decision for the owner** across ~75 storefront routes
   plus a redirect policy for every existing link. `test_no_hreflang_is_emitted_because_the_
   storefront_has_no_per_language_url` pins the absence so nobody adds a decorative one.
2. **`Product` structured data — deliberately held back.** A `Product` block is only useful with
   `offers`, and a marked-up price that disagrees with the displayed one is a commercial
   misrepresentation rather than a markup bug. The displayed price is assembled in
   `HomeController::product_detail` from `products.price`/`sale_price`, the `product_variants`
   min/max and any live `FlashSale`; reproducing that arithmetic in the SEO layer would create a
   second source of truth that silently drifts. It needs the price to come from one place first.
   **So "product/organization structured data" is half delivered.**
3. **`public/robots.txt` — untouched, and it is an owner decision.** It is the vendored allow-all,
   it sits at the **host** root, and it is shared by every tenant's storefront *and* by the
   platform's own website. Its contents — including the `Sitemap:` line that would make the
   per-store sitemaps discoverable without Search Console — are a platform-operator decision.
   Today a merchant's sitemap is discoverable only by submission.
4. **The sitemap is not cached.** Each fetch runs the two queries; the throttle bounds abuse but
   nothing memoises the document. A `Cache` entry keyed on the store plus its newest `updated_at`
   is the obvious follow-up and is not built.
5. **No sitemap index.** The protocol caps one document at 50,000 URLs; the cap is enforced
   (`StorefrontSeo::SITEMAP_MAX_URLS`) and the overflow is simply not listed, which is the safe
   truncation — a document over the limit is rejected whole. A larger catalogue needs an index
   document and paged children.
6. **Custom pages and articles are not in the sitemap.** `page.custom` and `page.article` are both
   classified indexable, but their rows live in vendored module tables that do not load in any test
   run (CLAUDE.md), so they cannot be enumerated here with evidence.
7. **Module storefront routes have no verdict and therefore get `noindex`.** The census reads the
   host application's routes; no WorkDo module loads in any test run without an `add_on_managers`
   row. Closing this needs the out-of-process pattern of
   `tests/Support/Security/bin/route-census.php`.
8. **No merchant-facing surface.** Nothing tells a merchant their sitemap URL or lets them exclude a
   page. That is a Phase 02/06 settings surface and the first thing in this slice that would need
   Arabic strings.
9. **The official website and documentation centre are untouched.** Addendum B's SEO clause
   (`…-WITH-AMENDMENTS.md:616`) is a separate surface; `resources/views/platform/layout.blade.php`
   already carries a per-page meta description and `robots index,follow`, and no canonical,
   sitemap or structured data. **Not in this change.**
10. **`performance` (الأداء) and `images` (الصور)** from the plan bullet are not addressed here —
   tracked as `P03-E-02`/`GAP-112` and the theme/asset work respectively. Named because the bullet
   names them and the register's summary does not.

## 8. The one thing that needs a real MySQL

The case-folding duplicate-content case in §4.1 is **not reproducible on this suite's SQLite** and is
real in production. It wants an executable script on the pattern of
`tests/Support/Security/bin/verify-sec13-migration-on-mysql.php` (a throwaway database it creates and
drops), asserting that `Store::where('slug', 'UPPER-CASE')` resolves the row under
`utf8mb4_*_ci` and that the canonical emitted for that request is the lower-cased one. That directory
is outside this work package's files, so the script is **not written** — named here so it is not
lost.

## 9. New user-facing strings

**None.** Every output in this slice is machine-facing: a canonical URL, a robots directive, a
JSON-LD block and an XML document. No `__()` call was added and no view was edited, so there are no
`en`/`ar` pairs to write. Item 8 in §7 is the first follow-up that would need them.
