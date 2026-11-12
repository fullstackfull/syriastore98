# Phase 03 M2 — premium storefronts, light and dark, across three themes

**Branch:** `feature/phase-03-storefront` · **Measured:** 2026-11-12

M2-A built the appearance foundation. M2-B is the first theme, and it changed what the rest of M2
has to do.

---

## 1. M2-A — the foundation (complete, `a84540e7`)

Per-viewer browser storage scoped to the individual store, as the owner approved.

| | |
|---|---|
| key | `store-syria:storefront:appearance:s<store id>` |
| states | `auto` (default, follows the device), `light`, `dark` |
| verified | **40 browser checks, 0 failures** (`tools/verify-appearance.cjs`) |
| cascade safety | **1,134 computed-style comparisons, 0 differences** when the design system is loaded (`tools/verify-no-cascade-regression.cjs`) |

The store id, not the slug: the slug is changeable through `updateStoreOwnerInfo`, so keying on it
would silently discard a shopper's choice when a merchant renames their shop.

---

## 2. M2-B — THE FINDING: a tokenised stylesheet is not enough

**Tokenising a theme's `main-style.css` is necessary and NOT sufficient**, and establishing that is
most of this package.

The storefront themes load `tailwind.js` — the runtime JIT CDN — and their Blade markup carries
colours as **utility classes**: `bg-white`, `text-black`, `text-gray-600`, `border-gray-200`. Those
are generated with fixed palette values and injected **after** the theme stylesheet, so they win on
source order and no amount of `:root` token work reaches them.

Measured on stylique with its stylesheet **fully tokenised**:

| failure | ratio | min |
|---|---|---|
| `bg-white` panels staying `#ffffff` while text became `#e8eaed` | **1.21:1** | 4.5 |
| `text-black` on the dark page `#1b1f25` | **1.27:1** | 4.5 |
| `text-gray-600` on the dark page | **2.19:1** | 4.5 |
| `bg-gray-50` panels staying `#f9fafb` | **1.15:1** | 4.5 |

The page re-themed and the components did not. That is what "dark mode does not work" looks like
when only the stylesheet was tokenised.

`public/css/storefront-appearance.css` maps the utilities to design-system tokens. **Shared, not
per-theme**: the mapping is utility→token, contains nothing stylique-specific, and all three themes
load the same `tailwind.js`. M2-C and M2-D inherit it.

Three details that are easy to get wrong:

- **Tailwind's grey ramp must be INVERTED, not mapped across.** `text-gray-900` is the strongest ink
  in light mode and must stay strongest in dark — which is the *lightest* value. Mapping it straight
  makes the most important text the faintest.
- **`color-scheme` is the one declaration no class mapping can replace.** After every utility was
  mapped, two `<input>` elements were still `#ffffff` on a dark page — painted by the *browser's*
  stylesheet from the `Field` system colour until the document declares its scheme. It also
  re-themes scrollbars, the canvas behind a short page and the autofill tint.
- **Every dark rule needs an `auto` arm.** `auto` is the default state, so a rule written only for
  `[data-ss-theme="dark"]` misses most visitors. The stylique page background made exactly that
  mistake first: dark surfaces and a white page.

### Contrast, measured

| | failures |
|---|---|
| before M2-B, both appearances, 4 routes | **42** |
| after | **4** |
| dark mode specifically | **18 → 0** |

All four remaining appear in the before list, so none was introduced — verified by re-running the
probe against the original stylesheet with the new layer blocked, not by reasoning about it.

Still pre-existing and deliberately untouched: coral used as *text* (`text-primary`, 2.53–2.64:1)
and `text-white` on a near-white hero section.

### One deliberate change to stylique's identity

White ink on the coral `.btn-primary` measures **2.64:1** at 16px/500 — a real pre-existing AA
failure on "Shop Now", "Login" and "Apply Filters", and worse on the 10px cart badge. **All five**
original `--white-color` sites turned out to sit on the coral, including an svg stroke inside
`.btn-primary` at 2.64:1 — below even the 3:1 minimum for a graphical object. Dark ink on the same
coral gives **6.39:1**.

**The coral is kept and the ink changes.** The brand colour is what makes the theme recognisable;
the ink on it is not. Darkening the coral enough for white to pass would need a luminance below
0.18 against its current 0.33 — a different colour, not a tweak.

### Three invalid declarations, found by the test rather than by reading

| property | sites | effect |
|---|---|---|
| `--primary-color-rgb` | `:696`, `:702` | used, never declared → both box-shadows never rendered |
| `--white` | `:715` | declared nowhere; resolves to `""` → invalid at computed-value time, the icon silently inherited |
| `--black` | `:828` | same |

All three repaired. The progressbar icon's step is filled with `--primary-color` (verified), so it
takes the brand ink; the countdown digits take body text.

### Light mode: exactly four differences, and nothing else

710 computed-style comparisons against the pre-M2-B stylesheet. **Zero** changes to type, spacing,
layout, radius or shadow.

| count | change | why |
|---|---|---|
| 416 | `color` `rgb(0,0,0)` → `rgb(26,29,35)` | `body` had **no** text colour, so everything inherited the browser's pure black. 21:1 → 16.88:1, both far above AA, and required for dark mode to be possible at all |
| 33 | `borderColor` `#d3d3d3` → `#dddfe3` | the design system's border token |
| 8 | `backgroundColor` transparent → `#ffffff` | `body` is now explicitly white — **visually identical** over the canvas |

### The capture

The harness now carries an **appearance** dimension, so the matrix is four combinations rather than
two. Each is driven the way a shopper produces it — the context's `colorScheme` plus the
store-scoped `localStorage` key — not by forcing the attribute, which would test the stylesheet
while skipping the preference mechanism. A row whose *resolved* appearance does not match what it
claims is a premise failure.

| locale | appearance | rows | premise-fail | images | broken | overflow | page background |
|---|---|---|---|---|---|---|---|
| en | light | 21 | **0** | 513 | **0** | **0** | `rgb(255,255,255)` |
| en | dark | 21 | **0** | 513 | **0** | **0** | `rgb(27,31,37)` |
| ar | light | 21 | **0** | 513 | **0** | **0** | `rgb(255,255,255)` |
| ar | dark | 21 | **0** | 513 | **0** | **0** | `rgb(27,31,37)` |
| | | **84** | **0** | **2,052** | **0** | **0** | |

`evidence/m2b-stylique-2026-11-12/` — **84 captures, 84 distinct contents.** The M1 baseline is
preserved untouched as the before evidence.

### The cross-store gate refused to report clean, correctly

`check-cross-store-captures.py` answered *"PREMISE FAILED: parsed 0 captures, so a pass would mean
nothing"* — its filename regex did not know the new appearance segment, and it refuses to bless
files it cannot parse. Fixed, and the fix proven by re-running it over M1's 210 old-format captures
(still clean).

It then refused a second time, also correctly: **one store cannot answer a cross-store question.**
That check becomes meaningful at M2-E, when all stores are captured together.

---

## 3. M2-C and M2-D — the other two themes (`7f317ab2`, `480adcd9`)

Each theme was measured on its own and **no conclusion was carried across**, because every one of
them wanted a different answer:

| theme | brand | as a FILL, which ink passes | decision |
|---|---|---|---|
| stylique | coral `#f8796c` | dark `6.39:1`, white `2.64:1` | dark ink on the fill; a deeper coral `hsl(6 91% 45%)` for coral-as-text |
| techzonix | violet `#9952e0` | white `4.53:1`, dark `3.73:1` | white ink on the fill; the dark variant re-hued to 280 |
| greentic | green `#4caf50` | **neither** passed on the `#388e3c` hover | the colour itself had to move — `#39843c` / `#367c39`, same `hsl(122 39%)` hue |

Carrying stylique's answer to techzonix would have broken it, and either answer applied to greentic
would have left the hover state failing whatever ink it used. The full reasoning, including the
rejected option for greentic, is in `480adcd9`.

techzonix also contributed the one case where tokenising was already right: its Tailwind config
reads `hsl(var(--primary))` from the CSS custom properties, so it has no second source of truth to
drift from. That is the pattern the other two should move to and it is now asserted
(`StorefrontAccessibleNamesTest::test_no_theme_declares_a_brand_colour_tailwind_can_override`).

---

## 4. M2-E — the integrated cross-store verification

### 4.1 The matrix

`tools/verify-a11y-matrix.cjs` — **160 page loads over 40 combinations**, and every load reads the
rendered appearance and text direction back before trusting its own result:

| | |
|---|---|
| stores | 5 — `damascus-textiles`, `aleppo-soap`, `p3fixt-homs-tech`, `p3fixt-latakia-green`, `p3fixt-no-theme` (empty `theme_id`) |
| locales | 2 — `en` LTR, `ar` RTL |
| appearance states | 4 — `light`, `dark`, `auto` under an OS that says light, `auto` under an OS that says dark |
| routes | 4 — `/home`, `/product-list`, `/cart`, `/login` |
| appearance applied as asked | **160 of 160** |
| axe violations, `wcag2a`/`wcag2aa`/`wcag21a`/`wcag21aa` | **0** |

**`auto` is swept because `auto` is the default.** A shopper who has never touched the control is on
`auto`, which resolves through `prefers-color-scheme`. Driving only `light` and `dark` tests the two
states fewest people are in — and a rule written for `[data-ss-theme="dark"]` alone misses most
visitors, which is the mistake the stylique page-background fix made on its first draft.

### 4.2 Accessibility: what M2 introduced, and what was already there

Every rule was run twice — once as the branch stands, once with the two stylesheets M2 adds served
**empty**. A rule that fires identically in both was already there:

| rule | impact | WCAG tag | with M2 | without M2 | verdict |
|---|---|---|---|---|---|
| `color-contrast` | serious | **wcag2aa** 1.4.3 | **0** | 47 | **M2 removed 47** |
| `button-name` | critical | **wcag2a** 4.1.2 | 107 | 107 | pre-existing → **fixed** |
| `meta-viewport` | critical | **wcag2aa** 1.4.4 | 20 | 20 | pre-existing → **fixed** |
| `link-name` | serious | **wcag2a** 2.4.4 | 16 | 16 | pre-existing → **fixed** |
| `select-name` | critical | **wcag2a** 4.1.2 | 12 | 12 | pre-existing → **fixed** |
| `image-alt` | critical | **wcag2a** 1.1.1 | 8 | 8 | pre-existing → **fixed** |
| `label` | critical | **wcag2a** 4.1.2 | 5 | 5 | pre-existing → **fixed** |
| `list` | serious | **wcag2a** 1.3.1 | 4 | 4 | pre-existing → **fixed** |
| `region` | moderate | best-practice only | 119 | 119 | pre-existing, **still owed** |
| `heading-order` | moderate | best-practice only | 17 | 17 | pre-existing, **still owed** |
| `landmark-unique` | moderate | best-practice only | 12 | 12 | pre-existing, **still owed** |
| `landmark-one-main` | moderate | best-practice only | 6 | 6 | pre-existing, **still owed** |

**THE NODE COUNT IS NOT THE WORK.** 107 unnamed buttons were **eight markup sites**, because a
product card is one Blade partial rendered once per product. 20 `meta-viewport` nodes were **one
line**, copied into four layout files. Reading the count as the effort would have deferred a day's
work for a year.

**The tag column decides the claim.** The eight fixed rules all carry a WCAG 2.0/2.1 A or AA tag.
The four outstanding ones carry **`best-practice` and no WCAG tag at all** — verified by reading
`axe.getRules()` rather than by assuming. So the accurate statement is: **no axe-detectable WCAG 2.0
or 2.1 A/AA violation on any of the 160 loads**, with four axe best-practice recommendations open.
They need landmark and heading restructuring across three vendored themes, which changes document
semantics on every page and is its own slice with its own verification — not an M2-E tail.

### 4.3 The fixes, and why none of them is visible

| what | where | why it was invisible |
|---|---|---|
| `maximum-scale=1` removed | 4 storefront layouts | capped zoom at 100%; the page looks identical |
| 8 icon-only buttons named | 3 theme headers/footers, 3 product cards, pagination, layout toggles | an icon has no text to miss |
| 2 selects, 1 range input named | stylique header, shared product list | the control renders the same |
| 4 decorative images given `alt=""` | techzonix slider | a screen reader read out file names |
| `<button>` and the appearance control wrapped in `<li>` | techzonix header | `display: contents` — **21 bounding boxes compared across 3 viewports, 0 differed** |
| struck-through price re-tokenised | all 3 themes | `2.54:1` → `5.05:1` light, `4.98:1` dark |
| **21 untranslated `aria-label`s** | 3 themes + 6 shared views | announced in English on Arabic pages |

Three of those 21 were worse than untranslated. All three themes labelled their **sign-out** link
`aria-label="Account"`, and greentic labelled **every** partner logo in a loop `"Visit Apple
website"`. Each now says what it does, through a translation key that already existed.

### 4.4 Core Web Vitals

`tools/verify-performance-a11y.cjs` — **80 page loads, 0 outside the "good" thresholds**:

| theme | LCP p75 | LCP max | CLS p75 | CLS max |
|---|---|---|---|---|
| techzonix | 380–400 ms | 428 ms | 0.0778 | 0.0778 |
| stylique (fallback) | 428–488 ms | 520 ms | 0.0833 | 0.0836 |
| stylique | 620–656 ms | 1456 ms | 0.0836 | 0.0845 |
| greentic | 608–660 ms | 672 ms | 0.0772 | 0.0772 |

**What these are not.** Not production figures: `php artisan serve` on `127.0.0.1`, no network
latency, no TLS, no CDN, no HTTP/2, no opcache, a SQLite fixture instead of MySQL, and `tailwind.js`
compiling in the browser at runtime. The gap to production is not estimable from here. They are a
**regression baseline for comparing M3 against M2**, nothing more.

**INP is not measured and cannot be.** Interaction to Next Paint is a field metric over a real
person's whole session. The `click p75` column (23–30 ms) is the latency of one scripted click to
the next paint. It is not INP and is not reported as INP.

### 4.5 Three defects in my own measuring tools

Each produced a confident number about nothing, and each is recorded where the next reader meets it:

1. **`verify-performance-a11y.cjs` clicked the appearance toggle and then ran axe.** The control
   cycles `light → dark → auto`, so every violation was attributed to the state *before* the click.
   It reported **59 `color-contrast` nodes over 19 rows** — greentic light `/home` at 19 nodes among
   them — where a direct sweep of those same combinations found **none**. axe now runs first and the
   click is last. `verify-a11y-matrix.cjs` reads the appearance back so this class of error cannot
   recur silently.

2. **`verify-light-mode-unchanged.cjs` only knew two themes.** `THEME === 'techzonix' ? 'tz' : 'sty'`
   meant passing `greentic` injected **stylique's** committed stylesheet into greentic's page. The
   run reported coral-to-green "regressions" and button-geometry changes that no greentic edit could
   have caused. Now one baseline file per theme, and an unknown theme exits non-zero.

3. **A cascade probe filtered on `rule.style.color`,** which is the empty string when the value
   contains `var()`. It found zero rules for a selector that demonstrably had one, and nearly led to
   the conclusion that the theme stylesheet was not loading at all.

With the baselines corrected, the light-mode deltas from this package are small and entirely the
ink-on-brand-fill work — no geometry, font, padding or border movement:

| theme | changed properties |
|---|---|
| stylique | `color` ×8: 5 white→`#0b121f` on the coral fill, 3 coral→`#0b121f` |
| techzonix | `color` ×3: violet→white on the violet fill |
| greentic | `color` ×3: green→white on the green fill |

### 4.6 Regression cover

`tests/Unit/Ui/StorefrontAccessibleNamesTest.php` — **24 tests, 105 assertions, no skips.** It
guards the source, because none of these failures changes a pixel and none of them changes an HTTP
status:

- every theme's Tailwind `primary` matches its `--primary-color`, or reads the token through
  `var(--…)`. **Verified by reverting:** putting `#4caf50` back makes it fail and name the file.
- the struck-through price reads a token that flips, and grey-400 cannot come back.
- 15 named icon-only controls still carry an accessible name, anchored on their own markup rather
  than a line number.
- **every** `aria-label` in a storefront view goes through the translator — the general rule, not the
  21 instances. This is what found 20 beyond the 1 I was looking for.
- techzonix's slider backdrops declare `alt=""`, and its header `<ul>` wraps its non-`<li>` children
  with balanced tags. HTML auto-close hides an unclosed `<li>` by absorbing later siblings into it,
  so axe goes quiet while the structure is wrong — the opening and closing counts are compared.

`ViewportAllowsZoomTest` gained the three theme layouts and a theme-wide scan; its Phase 03 entry in
`OUT_OF_PHASE` is deleted, as that test's own instructions require. The NMI payment hand-off stays
listed: it was considered here and deliberately left, because the owner's standing restrictions put
payment flows outside an incidental accessibility sweep. It is a real WCAG 1.4.4 failure and still
owed.

### 4.7 Gates

| gate | result |
|---|---|
| Unit | **1,709 passed**, 1 skipped (pre-existing) |
| Contract | **38 passed** — the Flutter API contract is untouched |
| Feature + known-outcome gate | **8,582 passed**, 2 failed, 1 skipped — the outcome **set** matches `.github/known-test-outcomes.json` exactly: 2 declared failures, 1 declared skip, 0 errors, nothing undeclared. No entry was added to make it pass |
| capture integrity | `check-distinct-surface-captures.py`, `check-cross-store-captures.py`, `detect-refusal-toast-captures.py` — all clean |

### 4.8 THE FIXTURE DEFECT THAT MADE AN EARLIER "0 VIOLATIONS" WORTH LESS THAN IT LOOKED

The first clean matrix run was **measured on near-empty pages for four of five stores**, and that
was found by looking at a screenshot rather than at a number.

**`/product-list` rendered an empty grid for every store except the vendor-seeded store 1.** The
chain took three steps to see:

1. `product_type` is **`NULL`** for an ordinary physical product. A non-null value names a *module*
   product — `'auction'`, `'digital'` — and the application reads it that way everywhere:
   `ProductDataTable`, `HomeController`, and roughly a dozen `ApiController` queries all select
   `where('product_type', null)`.
2. The M0/M1 fixture seeded **`'simple'`**, which is not a type anything handles. 24 rows across the
   three stores I created.
3. `HomeController:979` computes the price-range maximum with `where('product_type', null)`, found
   nothing, fell back to `'0'`, the slider rendered `max="0"`, and the page's own AJAX call then
   asked for products priced **between 0 and 0**.

So four stores answered HTTP 200 with a correct-looking, correctly-themed, correctly-translated page
and **no products at all**. Every Phase 03 measurement of that route since M0 was thinner than its
numbers suggested. Fixed in the seeder with the reasoning in place, and the 24 rows repaired.

**Re-running the matrix on pages that actually had products immediately found two more defects**,
both on techzonix, both invisible while its grid was empty:

| rule | nodes | what | fix |
|---|---|---|---|
| `link-name` | 128 | techzonix's wishlist control is an **`<a>`**, not a `<button>` like the other two themes, so a sweep looking for buttons never reached it | the same conditional label as the others |
| `color-contrast` | 48 | the `/home` countdown reads `--brand-ink-on-surface` on `rgba(153 82 224 / 0.1)` — **4.33:1 light, 4.30:1 dark** | `hsl(270 70% 58%/70%)` → **`56%`/`74%`**: 4.69 and 5.06 on the tint, 5.32 and 5.58 on a plain surface |

**The lesson is the token one.** techzonix's ink was chosen to clear 4.5:1 against *white* and did —
4.91:1. It failed on a **tinted** surface the same theme puts it on. An ink token has to clear every
surface the theme uses it on, not just the one it was picked against. The alpha matters too: the
backdrop is 10% violet, which composites to `#f5eefc` over white and `#322f44` over the dark card,
so the two arms fail for different reasons and needed different corrections.

**What is still thin, stated rather than buried.** `p3fixt-no-theme` has **no catalogue at all** —
it was created in M0 purely to prove the empty-`theme_id` layout fallback, which it still does. But
it contributes 32 of the 160 loads, and on `/home` and `/product-list` those are pages with an empty
grid. The matrix is therefore strong on 4 stores and weak on the fifth for 2 of its 4 routes. Giving
that store a catalogue is the next fixture task.

---

## 5. What M2 does NOT claim

- **Not a production release.** Nothing is merged to `main`; no deployment, no migration, no key
  rotation, no live-host change.
- **Zero axe violations is not an accessibility pass.** axe automates roughly a third of WCAG.
  Keyboard order, focus management, whether alt text is *meaningful* and whether a label *describes*
  its control all still need a person. No human accessibility review has happened.
- **The Core Web Vitals are lab numbers on localhost**, not a user-experience claim (§4.4).
- **Four axe best-practice rules are open** — `region`, `heading-order`, `landmark-unique`,
  `landmark-one-main`, 154 nodes between them (§4.2). Pre-existing, measured, not suppressed.
- **The `Logout` translation key is wrong in Arabic** — `إغلاق` means "Close". Found while labelling
  the sign-out links, and avoided by using `Log Out` (`تسجيل الخروج`) instead. The bad key is used
  in one non-storefront view and two vendor stubs. Several other locales have worse: `it.json` gives
  `Log Out` as `Iscriviti`, which means "Sign up". A 16-locale translation-quality pass is its own
  work and is not in this package.
- **The `auto` state's rendering is swept; its *transition* is not.** `verify-appearance.cjs` proves
  `auto` re-themes on a live device change without a reload; no capture exists of mid-transition
  paint.
- **Coral-as-text on the near-white hero remains open**, pre-existing, as M2-B recorded.
