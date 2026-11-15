# Phase 03 / M5 — the official Store Syria website

**Status:** engineering complete. Not released; not merged to `main`.
**Branch:** `feature/phase-03-storefront`.

---

## 1. What M5 is, and the three things it is not

The owner's directive: *"Build the official company/platform website using the existing WorkDo
LandingPage foundation. Do not create a second unrelated landing-page engine… Get real
subscription/pricing values from canonical plan data. Do not invent supported features. Do not
hardcode fictional prices."*

So M5 ships seven public pages under `/platform` describing the platform itself.

It is **not** the merchant's storefront (`{storeSlug}/…`, themed, tenant-scoped — that is M0–M4).
It is **not** the merchant admin. And it is **not** a second landing-page builder, which is the
constraint that shaped the whole design.

## 2. Why first-party routes rather than LandingPage content

Measured, not assumed:

| | |
|---|---|
| `packages/workdo/LandingPage/src/Routes/web.php` | every route but two is inside `Route::middleware(['auth','web','xss','setlocate','verified'])` — the module **configures** a page, it does not serve one |
| its two public routes | `/` (via `HomeController::Landing` → `landing-page::layouts.landingpage`) and `landing-pages/{slug}` (`CustomPageController::customPage`) |
| `composer.json` | carries **no** PSR-4 entry for `Workdo\` — measured: `class_exists('Workdo\LandingPage\Entities\LandingPageSetting')` is **false** under a bare autoloader and **true** only once `loadAddons()` has run |

That last row is the decisive one. A first-party view that named a `Workdo\LandingPage\*` class
would be a fatal error wherever the module is off — which includes **every test run that has not
inserted an `add_on_managers` row**, i.e. all of them. (`resources/views/layouts/guest.blade.php:133`
does exactly this today; it is left alone, not copied.)

`landing-pages/{slug}` was the other candidate. Its content is HTML typed into the admin builder and
stored in a row, so shipping the company website that way would mean:

- the pages exist only after somebody runs a seeder **on production**, which M5 is not authorised to do;
- the marketing copy lives in a database row rather than in git, where no test can read it and no
  review can see a diff.

**What is kept from the foundation:** `/` is untouched and still renders the module. Nothing here
adds a block system, a section builder or a second set of configurable pages. What M5 adds is fixed,
code-owned pages beside it.

## 3. The surfaces

| URL | Name | Content |
|---|---|---|
| `/platform` | `platform.home` | what Store Syria is; the three surfaces; Arabic-first positioning; themes; a three-plan teaser |
| `/platform/features` | `platform.features` | themes on disk + four capability groups from the registry |
| `/platform/pricing` | `platform.pricing` | every `plans` row with its real limits |
| `/platform/getting-started` | `platform.onboarding` | six steps, what you need before you start |
| `/platform/faq` | `platform.faq` | ten questions, native `<details>` |
| `/platform/contact` | `platform.contact` | `contactus_url` where set, otherwise an honest blank |
| `/platform/legal` | `platform.legal` | five documents, each marked **not published yet** |

Header and footer are shared, responsive, bilingual, and carry the language switch.

### Files

```
app/Http/Controllers/PlatformSiteController.php     7 actions
app/Support/Platform/PlatformCapabilities.php       the claims registry
resources/views/platform/{layout,home,features,pricing,onboarding,faq,contact,legal}.blade.php
public/css/store-syria-platform.css                 ~670 lines, additive over the DS
routes/web.php                                      the group, before customer.php is required
app/Models/User.php                                 `platform` reserved in `slugs()`
resources/lang/{en,ar}.json                         +214 keys each
```

## 4. Route precedence — and the measurement that proves it matters

`routes/customer.php` is **required from `routes/web.php:1691`**, so it is the same file load and
its declarations come later. It declares `{storeSlug?}/faq`, `{storeSlug?}/contact-us`,
`{storeSlug?}/home` and the rest. Laravel matches in declaration order.

The platform group is declared at `routes/web.php:~425`, before that require. **Falsified:** moving
the identical group to the end of the file makes `Route::getRoutes()->match()` resolve
`/platform/faq` to `App\Http\Controllers\HomeController` — the storefront — and three tests fail.
Both 200s look the same from outside, which is why the test asserts the **controller class** and not
the status.

The mirror risk is a store whose slug really is `platform`: every one of its storefront URLs would
silently resolve to a marketing page. So `User::slugs()` — the single place store slugs are minted
(`StoreController` :134, :283, :563) — treats `platform` exactly like an already-taken slug and the
existing suffix loop hands the merchant `platform-1`. Nothing fails for them.

## 5. The two rules that keep the site honest

### Prices come from `plans`

There is **no price in any view**. `PlatformSiteController::publishedPlans()` reads the table the
subscription flow charges against. `plans.price` is a **string** column, so the sort is done in PHP
after the fetch — a SQL `ORDER BY` would put "100" before "20".

An installation with no plans renders *"No subscription plans have been published yet"*. That is a
supported state, not a fallback to illustrative figures.

**`-1` is the unlimited sentinel, not `0`.** Measured at `User.php:339`
(`$plan->max_stores == -1`), `DashboardController.php:2966`
(`$total_products < $plan->max_products || $plan->max_products == -1`) and `Utility.php:868`
(`$plan->storage_limit != -1`). Under those checks a `0` permits **zero** — `0 < 0` is false and
`0 == -1` is false. The first draft of `PlatformCapabilities` had the sentinel backwards and would
have printed "Unlimited" beside a plan that sells nothing. `storage_limit` is in **megabytes**
(`DashboardController.php:7511`).

`''` and `'off'` are **not** collapsed: *not configured* and *not included* are different answers to
a buyer. (The flag columns are `NOT NULL DEFAULT 'off'`, so the reachable unconfigured value is the
empty string, not `null` — found by an integrity violation while writing the test.)

### Capabilities come from a registry with anchors

`App\Support\Platform\PlatformCapabilities` holds 34 claims in four groups. A claim naming a WorkDo
module renders **only while `packages/workdo/<Module>` is on disk**; `PlatformCapabilitiesTest`
fails if the registry names a module this repository does not contain, and
`PlatformSiteRoutesTest::the_features_page_renders_the_filtered_registry_and_nothing_else` ties the
rendered page to the filtered list in both directions.

Theme names are read from `themes/` rather than listed in code.

**What "installed" honestly means:** the module *ships with the platform*. It does **not** mean a
given merchant has it switched on — `module_is_active()` resolves an `add_on_managers` row against a
store and a user, and a public visitor is neither. Every string is worded as what the platform
offers, and the features page says so in its own lede.

## 6. Where the site deliberately says nothing

Two pages decline to invent content, and both are **owner items, not missing engineering**:

- **Contact.** A sweep of the settings keys the application reads found `contactus_url` and nothing
  else — there is no shipped setting for a postal address, telephone number or support mailbox. The
  page links `contactus_url` where it is set (and only when it is an absolute `http(s)` URL, since
  the value is operator-supplied and ends up in a public `href`), and otherwise states that the
  published details are being confirmed while pointing at the routes that do work.
- **Legal.** The owner's standing restrictions reserve "unapproved changes to legal or commercial
  policy". Terms of service bind a business from the moment they are published. The page gives each
  of five documents a stable address and a description of what it will cover, and says once, at the
  top, that none of it is binding.

## 7. Design

`public/css/store-syria-platform.css` is additive over `public/css/store-syria-ds.css` and takes
**every** colour, radius, space and type value from a `--ss-*` token — asserted, not intended:
`PlatformSiteAssetsAreLocalTest` fails on any literal colour and on any token the design system does
not define. (The first draft referenced `--ss-success`; the token is `--ss-success-fg`, and a
`var()` that resolves to nothing fails silently.) Dark mode is therefore correct by construction.

Marketing components (hero, cards, steps, plan tiles, FAQ, footer) live here rather than in the
admin design system, which is loaded on every merchant screen and would otherwise carry ~8 KB it can
never use. Naming is `.ss-ps-*`, inside the reserved `.ss-` namespace, and no bare element selector
is styled.

Every directional rule is a logical property, so one file serves both directions. The layout uses
`@extends`/`@yield` — **never** the `<x-*-layout>` shape, which CLAUDE.md records as rendering
perfect chrome with an empty content area and HTTP 200.

**No off-origin request at all.** No web font, no CDN, no icon pack, no analytics; the brand mark is
a letter in a token gradient. Ratcheted in both the views and the stylesheet.

The small-screen menu is a native `<details>` with the panel as its **child** — an earlier draft put
the panel in a sibling `<div hidden>`, which nothing could ever have revealed.

## 8. Tests — and the falsification of each

**53 tests** (440 assertions, measured by running them), counting the ones the independent review's
findings added (§10) and the two M7 added for the appearance default.

| Suite | Tests |
|---|---|
| `tests/Feature/Platform/PlatformSiteRoutesTest` | 11 |
| `tests/Feature/Platform/PlatformPricingTest` | 10 |
| `tests/Feature/Platform/PlatformSiteBilingualTest` | 11 |
| `tests/Feature/Platform/ReservedStoreSlugTest` | 7 |
| `tests/Unit/Platform/PlatformCapabilitiesTest` | 10 |
| `tests/Unit/Ui/PlatformSiteAssetsAreLocalTest` | 4 |

Each control was removed and the suite re-run. Every one of these **failed** as predicted:

| Falsification | Result |
|---|---|
| platform routes moved after `require customer.php` | 3 failures; `/platform/faq` → `HomeController` |
| `$reserved = []` in `User::slugs()` | slug test fails |
| a heading removed from `home.blade.php` | content test fails |
| the platform stylesheet `<link>` removed | chrome test fails |
| price and name hardcoded in `pricing.blade.php` | "follows the row" fails |
| sentinel flipped to `$n === 0` | "minus one is unlimited" fails |
| four Arabic keys deleted | 4 bilingual failures |
| `dir="ltr"` hardcoded | direction test fails |
| a fictional module added to the registry | "actually vendored" fails |
| `moduleIsInstalled()` → `return true` | filter test fails |
| `is_disable` predicate removed | 2 pricing failures |
| reserved list emptied | 5 slug failures |
| only the web rename guard removed | 1 failure |
| only the API rename guard removed | 1 failure |
| a name shadowed inside the prefixed route group | both guards report it |

The bilingual assertions are written as **English must be ABSENT**, not Arabic present: `__()`
returns its key when a translation is missing and the key here *is* the English sentence, so a
missing translation puts English back on the page. (M4's bilingual test passed with its key deleted
because the Arabic string was a substring of another sentence; that lesson is applied here.) The
locale is set with `withSession(['LANGUAGE' => 'ar'])`, because `setlocate` re-derives the locale
each request and would overwrite `app()->setLocale()`.

### Gates at M5 close

| Gate | Result |
|---|---|
| Unit | **1845 passed, 1 skipped** (15,903 assertions) — M5 and M6 together |
| Contract | **45 passed** |
| `TranslationFileIntegrityTest` | green; `ar.json` surplus still **31**, the ratchet baseline |
| `SlotLayoutViewsRenderTest`, `ClaudeConfigurationTest`, `ViewportAllowsZoomTest` | green |
| Feature + known-outcome gate | see §10 |

## 9. Translations

215 strings (214 for the pages, one for the reserved-slug refusal), added to **both** `en.json` (identity-mapped) and `ar.json`. Counts 3,882 → 4,097 and
3,878 → 4,093 at M5 close (4,111 / 4,107 once M6's 14 are in); the diff is additions only. The `ar.json` surplus over `en.json` stays at **31**, so
the ratchet keeps recording the pre-existing ones.

**Native review of the new Arabic strings is outstanding**, as it was for M4's 151. They follow
the conventions already in the file and use storefront terminology deliberately (a theme is `قالب`,
a draft `مسوّدة`), but that is a claim about consistency, not about idiom.

## 10. What the independent review found, and what changed

`tenant-authz-reviewer` read the controller, the registry, all eight views, the route group and its
placement, `User::slugs()`, the five test files, `getSuperAdminAllSetting()` /
`withoutPageExposedCredentials()`, `SetLocate`, `XSS`, `bootstrap/app.php` and both route-integrity
guards. It reported the seven pages clean as an authorization surface — no tenant, merchant or
customer data read or printed, no `auth()` call, no request input, no credential path, no
order/payment path, zero instances of the `if (! auth('...')->user())`-falls-back-to-input shape —
and five findings. **All five are fixed.** Each was re-measured before acting.

### F1 — both route-name guards would have failed CI

The group-level `->name('platform.')` is the only one in `routes/`, and **both** scanners — the
deliberately duplicated `RouteRegistrationIntegrityTest` and `RouteShadowingTest` — extracted
`->name('literal')` per line and asserted `Route::has($literal)`. Eight names would have been
reported lost in each, undeclared, failing the known-outcome gate twice.

**Not fixed by contorting the routes.** Both scanners now track group prefixes from the token
stream (brace depth taken from real `{`/`}` tokens, so a URI such as `'{storeSlug?}'` cannot
mis-count the nesting). Falsified: a name deliberately shadowed **inside** the prefixed group is
still reported, now as `platform.shadowed-faq`. Both copies were changed together — the file's own
comment says one of two identical guards being updated alone is the precise failure the
duplication exists to catch.

A third false positive surfaced from the same cause and the reviewer had not reached it: the
duplicate-URI scanner keyed on the bare literal, so `platform/pricing` collided with the unrelated
`Route::get('pricing', …)` at `web.php:460`. It now resolves `->prefix()` the same way. Falsified
in both directions — a duplicate inside the group and one across groups at the same real URI are
each reported as `get platform/legal`.

### F2 — the site advertised plans the operator had switched off

`publishedPlans()` filtered only on a non-empty name. `plans.is_disable` is **inverted** (1 means
enabled — measured at `PlanController.php:27`, `StoreController.php:1160` and
`Api\DashboardController.php:7433`), so every row was rendered: a **strictly wider view than a
signed-in merchant gets**, since the unfiltered read was previously operator-only. A draft,
retired or privately negotiated plan would have appeared on the company's own pricing page under a
"Start with this plan" button that `StoreController:1160` then refuses.

Fixed with one predicate mirroring `PlanController:27`, covering all three surfaces that share the
method. Two tests added; removing the predicate reddens both.

### F3 — the slug reservation did not actually reserve anything

`User::slugs()` is **not** the only writer of `stores.slug`. Two more assign it straight from
request input, both reachable by an ordinary merchant on their own store:
`AppSettingController::ThemeSettings()` and the Flutter store-settings endpoint. A merchant could
rename to `platform` through either. The seven M5 paths would still win, but **every other**
`{storeSlug?}/…` pattern would not — `/platform/login` and `/platform/register` would serve that
merchant's customer sign-in and registration forms at URLs a visitor reads as the platform's own.
Brand impersonation, by a principal and a request that can both be named.

Fixed centrally: `Store::RESERVED_SLUGS` and `Store::slugIsReserved()`, consulted by all three. The
mint path **suffixes** (`platform-1`) because nobody is watching a registration derive a slug; the
two rename paths **refuse**, because a merchant typing a name should be told. The API refusal is a
validation rule, not a hand-written response, so its shape is identical to the `unique` failure
beside it — a 422 the shipped app already handles. `ReservedStoreSlugTest` drives all three and
each guard was falsified **separately**: a fix covering one writer fails the other two.

**For the owner:** before deploy, run `SELECT id, name FROM stores WHERE slug = 'platform'`.
Nothing renames a store that already holds it.

### F4 — the caller inventory in a comment was wrong in two directions

`User::slugs()`'s comment named three call sites and called itself the only mint. It missed
`Auth\RegisteredUserController` :137 and :273 (store slugs, covered by the guard), and counted
`ShopifyProductController` :252 and :537, which mint a `ProductAttribute` slug and not a store's.
Corrected in the method and in the test that repeated it. This project treats such comments as
load-bearing; a stale one is the failure mode the correction-propagation rule exists for.

### F5 — hardening, no exposure found

`getSuperAdminAllSetting()` is the one settings reader that does **not** pass through
`withoutPageExposedCredentials()` (compare `getAdminAllSetting()`, which filters all three
returns). No platform view prints the bag — the layout reads `cust_darklayout`, contact reads
`contactus_url` — so nothing leaks. Both call sites are now wrapped anyway, because the bag is on a
**public** page. The helper itself is deliberately unchanged: ~100 callers including currency
formatting and payment paths, and filtering it centrally is the mistake `helper.php:691-697` warns
against.

### Checked and confirmed correct, no change

The `contactus_url` scheme filter and escaping (`parse_url` lowercases the scheme; Blade escapes
quotes; `javascript:`, `data:` and `vbscript:` are unreachable; the seeded default `'#'` fails
`FILTER_VALIDATE_URL` so the honest branch renders). The middleware set — and the absence of `xss`
is **right rather than merely harmless**: `XSS.php:56-78` runs a pending-migrations scan for a
signed-in super admin and can redirect to the installer, so putting it on the company home page
would bounce an operator off the marketing site.

### One disclosure carried forward

`the_features_page_renders_the_filtered_registry_and_nothing_else` has `$filtered === 0` today, so
its "filtered-out titles must not be on the page" loop executes zero assertions. The test says so
in its own body. The registry→view link is currently proven by the unit test on `available()`; the
page-level loop becomes evidence the day a named module is removed.

---

## 11. Outstanding, recorded rather than hidden

| | Owner |
|---|---|
| published contact details (address / telephone / support mailbox) | owner content |
| approved legal text for the five documents | owner / legal |
| native Arabic review of the new Arabic strings | owner |
| ~~a documentation link in the site navigation~~ | **done in M6.** The M5 test asserted the route was absent and named its own staleness condition; M6 registered it, the test failed exactly as designed, and it was inverted to assert the link is present on all seven pages |
| visual capture of the seven pages in 4 combinations (ar/en × light/dark) | **M7** |
| `Security-sensitive diff: human checklist` on the PR | owner; fails by design |
