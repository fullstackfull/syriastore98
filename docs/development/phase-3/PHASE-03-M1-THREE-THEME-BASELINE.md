# Phase 03 M1 — the real storefront baseline, across all three themes

**Branch:** `feature/phase-03-storefront` · **Measured:** 2026-11-12 ·
**Evidence:** `evidence/m1-storefront-baseline-2026-11-12/`

M0 built the harness and closed seven of its eight gaps. M1 is the measurement it was built for —
and the first thing it had to fix is that **M0 measured one theme three times.**

---

## 1. Two of the three themes had no fixture at all

`themes/` ships three, and they are not skins of one another:

| theme | blade files | default settings | fixture store before M1 |
|---|---|---|---|
| `stylique` | 13 | 511 keys | all three M0 stores |
| `techzonix` | 15 | 523 keys | **none** |
| `greentic` | 16 | 521 keys | **none** |

Each has its own `main_file`, its own layout, its own section markup and its own `default_data/
settings.php`, so a defect in one is invisible from the others. Every M0 fixture store carried
`theme_id = 'stylique'` — including the themeless one, which falls back to it — so the M0 baseline's
126 rows said nothing whatever about `techzonix` or `greentic`, and **nothing in the repository
would have told anyone.**

### The five fixture stores now

| id | slug | theme | default lang | catalogue |
|---|---|---|---|---|
| 1 | `damascus-textiles` | `stylique` | en | 32 products (pre-existing) |
| 2 | `aleppo-soap` | `stylique` | en | 3 / 2 / 8 |
| 4 | `p3fixt-no-theme` | **`''`** | en | — (exercises the fallback) |
| 5 | `p3fixt-homs-tech` | **`techzonix`** | en | 3 / 2 / 8 |
| 6 | `p3fixt-latakia-green` | **`greentic`** | **ar** | 3 / 2 / 8 |

Each has its own signable customer, its own logo, its own section copy and its own catalogue with
distinct product names — so a difference between two captures is a difference between **themes**,
not between fixtures. One store defaults to Arabic deliberately: the store default and the language
switch are different code paths, and M0 only ever exercised the switch.

### THE PATH POLICY REFUSED THE FIRST ATTEMPT, AND IT WAS RIGHT TO

The new catalogue images were first committed at `uploads/p3fixt/<store-slug>/`, chosen so that
committed paths could not go stale when the fixture is rebuilt and ids shift. Every row was refused
with *"The file path is not accepted."*

`App\Services\StoredPathPolicy` is **P1-SEC-28**: a stored path's first segment under `uploads/`
must be the owning store's **numeric** folder, or that of a sibling store with the same owner, so
that a poisoned row cannot name another tenant's file for one of the vendored deleters to `unlink`.
The policy is a tenant-isolation control and the path choice was wrong. Images are now at
`uploads/<store id>/p3fixt/`.

The id fragility that motivated the wrong choice is real and unavoidable, so it is made **loud**
instead of silent: the seeder refuses to seed a catalogue whose image directory is missing and names
the directory it wanted. Only the **logos** stay id-independent — they are `theme_customizes`
values, which no path-column policy judges.

---

## 2. The check that makes a three-theme baseline mean anything

A theme name that does not resolve falls back silently to `config('theme.active')`. So a capture
labelled `techzonix` that actually rendered `stylique` answers **200**, carries the right `dir`,
loads three theme stylesheets, and passes every premise check M0 had. **It is mislabelled evidence
and nothing would have caught it.**

The harness now records every theme name referenced by any stylesheet, script or image on the page
and asserts it against the store's declared theme. A correct page names exactly **one**.

| failure | reported as |
|---|---|
| page rendered the fallback | `WRONG THEME: page rendered stylique, store declares techzonix` |
| layout and sections disagree | `MIXED THEMES: stylique+techzonix on one page` |
| no theme resolved at all | `NO THEME ASSETS AT ALL: expected techzonix` |

Falsified, because a premise check that cannot fail is worthless: driving the `techzonix` store with
`--expect-theme greentic` reports `WRONG THEME`, and driving the themeless store with
`--expect-theme techzonix` reports it too (it renders the `stylique` fallback). The honest positive
is that `p3fixt-homs-tech` with no override reports `techzonix` and `ok`.

`tests/Unit/Ui/StorefrontThemeCoverageTest.php` is the ratchet: **a fourth theme added to `themes/`
fails it** until both the harness's expectation map and the fixture tool know about it. Six cases,
and planting a fake fourth theme fails three of them.

---

## 3. Route health across all three themes

Every public page route, all five stores, signed out — 80 combinations:

`home` `product-list` `collections` `cart` `wishlist` `track-order` `contact-us` `faq` `blog`
`about` `privacy-policy` `login` `register` `forgot-password` `checkout` `error`

**80 of 80 answer 200.** No theme has a route the others do not, and the six routes M0 fixed
(`home`, `login`, `privacy-policy`, `forgot-password`, `register`, `error`) stay healthy on the two
themes M0 never measured.

That is a floor, not a result. **A 200 is not evidence** — the per-theme rendering measurements are
in §4.

---

## 4. The measured baseline

`storefront-matrix.cjs`, five stores, signed in as each store's own customer, 2 locales × 3
viewports × 7 journey routes = **210 rows**.

| store | theme rendered | rows | premise-fail | images | broken | overflow | console 404s |
|---|---|---|---|---|---|---|---|
| `damascus-textiles` | `stylique` | 42 | **0** | 1,026 | **0** | **0** | 42 |
| `aleppo-soap` | `stylique` | 42 | **0** | 528 | **0** | **0** | 42 |
| `p3fixt-homs-tech` | **`techzonix`** | 42 | **0** | 324 | **0** | **0** | 42 |
| `p3fixt-latakia-green` | **`greentic`** | 42 | **0** | 324 | **0** | **0** | 42 |
| `p3fixt-no-theme` | `stylique` (fallback) | 42 | **0** | 294 | **0** | **0** | 42 |
| | | **210** | **0** | **2,496** | **0** | **0** | **210** |

Every row renders, in both languages, with the correct `dir`, at desktop, tablet and mobile, in
the theme its store declares. **`dir="rtl"` on all 105 Arabic rows, including both themes M0 never
measured.** No horizontal overflow anywhere. No broken image anywhere.

### Capture integrity

| gate | result |
|---|---|
| cross-store (`check-cross-store-captures.py`) | **210 captures, 210 distinct contents, 0 collisions** |
| distinctness (`check-distinct-surface-captures.py`) | **0 groups of identical files** |
| theme premise (in-harness) | every row rendered exactly one theme, and the right one |

M0's first pass had **28 cross-store collisions**; this has none, and **every one of the 210 files
is byte-unique** — so no capture in this set can be mistaken for another store's, another locale's
or another viewport's.

---

## 5. What is actually wrong — and the one finding that matters

### 5.1 The 210 console 404s are one reference, and it is ALREADY TRACKED — with my first reading wrong

One per row, on every theme, identical: `/public/js/flipdown.js` → 404. The file **exists**, at
`public/js/flipdown.js`. The reference is `resources/views/front_end/jQueryRoute.blade.php:178`:

```php
<script src="{{ asset('public/js/flipdown.js') }}"></script>
```

**MY FIRST CONCLUSION WAS THAT THIS IS A DEV-ENVIRONMENT ARTEFACT. It is recorded here because it
was wrong, or at least unproven, and the correction is the useful part.**

I reasoned: `asset()` builds a URL from the document root; under `php artisan serve` the root is
`public/`, so `/public/js/…` doubles and 404s; on the live host the root **is the repository root**
— which P1-SEC-40 established, because that is the only way `GET /.git/HEAD` answers 200 — so the
same URL resolves and production is fine.

**Phase 02 measured the opposite, directly.** `resources/views/layouts/app.blade.php:168-188`
(P2-A-01) says, of the sibling reference on the dark RTL stylesheet:

> `asset('public/assets/css/rtl-style-dark.css')` resolves to `/public/assets/css/rtl-style-dark.css`,
> and the docroot already IS `public/`, so the segment is doubled and the request 404s. **MEASURED
> against the running application:** that url answers 404 while `/assets/css/rtl-style-dark.css`
> answers 200, and the file is on disk at `public/assets/css/rtl-style-dark.css`.

That is a measurement of a real request; mine was an inference from a different host's behaviour.
**The two cannot both describe the same server**, and I cannot settle it: resolving it needs a
request against the live host, which the owner's standing restrictions forbid me from making.

So the honest statement is: **`/public/js/flipdown.js` 404s in at least the development
environment, and on the evidence of P2-A-01 very likely in production too.** It is not dismissed as
a dev artefact, and it is not asserted as a production defect either. **What it needs is one
measurement against the live host that I am not permitted to take.**

### 5.2 AND THE WIDER PATTERN WAS FOUND, SCOPED AND DEFERRED IN PHASE 02

This is the second time in Phase 03 that I reported something already tracked, so the correction
goes here rather than in a footnote.

The same `layouts/app.blade.php` note continues:

> **THE WIDER PATTERN IS NOT FIXED HERE.** `asset('public/...')` appears about 100 times across 32
> view files — payment pages, theme layouts, the marketplace — and a sweep of those is a different
> blast radius with different callers to verify. **Recorded in the ledger.** What is fixed is the
> admin layout, which is this phase's own surface.

It is **P2-A-01 / F16**, carried in `phase-2/PHASE-02-HANDOFF.md` and in the Arabic acceptance
report. Phase 02 fixed the admin layout — the surface it owned — and deliberately deferred the
sweep. Phase 03 has now hit the storefront end of the same deferral, which is what a scoped
deferral looks like working correctly.

**My own count was also wrong, in the same way as two tests earlier in this milestone.** The first
scan reported 88 references across 32 files and ten missing targets. Five of those matches were
inside Blade **comments** — three view files carry `{{-- … asset('public/…') … --}}` notes
*describing* this very pattern, so the scan counted the documentation as code. Stripping comments
gives the real figures:

| | first scan | measured |
|---|---|---|
| references | 88 | **76** |
| files | 32 (Phase 02's estimate) | **18** |
| statically-resolvable missing targets | 10 | **2** |

Of the two genuinely absent, **neither is on a storefront**: `public/js/theme-custom.js`
(`layouts/theme_layout.blade.php:207`, reached from the merchant layout) and
`public/images/pos_image1.png` (`marketplace/landing.blade.php:1093`). A third,
`public/market_assets/images/dash-banner`, was a false positive — the path is concatenated
*outside* the `asset()` call and the real file `dash-banner-image.png` exists.

`tests/Unit/Security/PublicAssetPrefixCouplingTest.php` now pins all of it: the count cannot grow,
every resolvable target must exist, the two known-absent must stay absent (or the list is stale),
and the `flipdown` reference that surfaced it is named so the trail is not lost.

**The coupling is still worth stating, whichever way the doc-root question resolves.** If the live
root is `public/`, these 76 references are latent 404s awaiting a sweep. If it is the repository
root, they work — and then **closing P1-SEC-40 by moving the root to `public/`, the standard
remediation, breaks all 76 at the moment it closes the exposure.** Either way, whoever takes that
change needs this list, and nothing said so before now.

### 5.3 Carried forward from M0, unchanged and still open

| finding | state |
|---|---|
| the storefront has **no dark mode in any of the three themes** | **open — Phase 03 theme implementation.** 0 files in `themes/` reference `cust_darklayout`, `data-ss-theme` or `style-dark` |
| the hero/slider copy is still each theme's demo content | open — theme implementation |
| an unknown-slug 404 is the framework's page, not a branded storefront 404 | open — theme implementation |
| `process-order`, `support-ticket`, `blogs/filter/view` answer **500 for a store that exists** | open, pre-existing; `process-order` is a payment route and untouched |
| four category links on `/home` point at routes that do not exist | open — they are `<a href>`, not sub-resources, so they do not appear in the console count |
| `theme_customizes` is absent from `scoped_models` | open — M3/M4 editor paths each need their own predicate |
| Arabic store names produce the slug `-` | **already pinned** as audit 3.1/3.2, gated by the owner directive of 2026-10-22 |

### 5.4 What this baseline does NOT establish

- **No performance or accessibility numbers.** Neither was measured; neither is claimed. They are
  separate instrumentation and belong with the theme work that will change the pages anyway.
- **No product-detail or checkout-flow capture.** `/product/{slug}` needs a per-store product slug
  the harness does not yet thread through; the 16-route HTTP sweep covers their status only.
- **No dark mode**, because there is none to capture.

---

## 5.5 THE GATES, as measured rather than assumed

Run on the committed tree after the observability-inventory correction:

| gate | result |
|---|---|
| **Unit** | **1,640 passed**, 12,228 assertions, 1 skip (pre-existing: the production-dump fixture is absent) |
| **Contract** | **38 passed**, 149 assertions — the Flutter API contract |
| **Feature** | **8,582 passed**, 43,964 assertions, 2 failed, 1 skipped |
| **known-outcome gate** | **OK — the observed outcome set matches the manifest exactly:** 2 declared failures, 1 declared skip, 0 errors, nothing undeclared |

The gate's own words are worth keeping: *"This is NOT a statement that the suite is green. It is a
statement that the suite is failing and skipping exactly the tests the project has recorded, for the
reasons it has recorded."*

**The gate caught a regression this milestone caused.** The first run reported **3** failures against
a manifest declaring 2: M0's explanatory comments in `CustomerLoginController` had shifted two
security log lines, so `P01-OBSERVABILITY-INVENTORY.md` disagreed with the source. Mechanical, and
the gate said so. The inventory's line numbers are corrected — `770`→`778`, `995`→`1007` — and the
failure was **not** added to `known-test-outcomes.json`, which records failures the owner has
accepted with a named root cause, not a list of things currently broken.

That hazard is now recorded in `PROJECT-STATE.md`: **adding a comment above a logged line breaks
this gate**, and the repair is always the inventory, never the manifest.

---

## 6. M2 scoping — measured, so the dark-mode work is not guessed at

M1's inventory records "the storefront has no dark mode in any of the three themes" as open. Before
starting M2 that was measured rather than estimated, because the obvious fear — three themes with
107–138 KB of CSS each — would make it a very large job.

**It is not. Two of the three pieces already exist.**

### What is already built

`public/css/store-syria-ds.css` carries **146 dark-mode rules**, driven by `data-ss-theme="dark"` on
`<html>` or on any container, with `data-ss-theme="auto"` following `prefers-color-scheme`. Its own
note explains the posture: only the subtree carrying the attribute re-themes, and `auto` applies
only where a page asks for it, "so a merchant on a dark OS never gets a surprise dark admin."

The merchant admin shows the pattern working: `resources/views/layouts/app.blade.php:86` sets
`data-ss-theme="dark" data-ss-theme-source="host"` on `<html>` from the store's `cust_darklayout`
setting, alongside a 594 KB `style-dark.css`.

### What the storefront is missing

| | measured |
|---|---|
| `data-ss-theme` anywhere in `themes/` or `front_end/` | **0 references** |
| shopper-side toggle or preference of any kind | **none** — no `localStorage`, no toggle markup |
| hardcoded colours, `stylique` | 26 hex + 32 `rgb()` + 4 named — **14 distinct hex** |
| hardcoded colours, `techzonix` | 30 hex + 29 `rgb()` + 8 named — **18 distinct hex** |
| hardcoded colours, `greentic` | 27 hex + 30 `rgb()` + 4 named — **20 distinct hex** |

**14–20 distinct colours per theme is a tokenisable surface**, not an open-ended rewrite. The CSS is
large because of layout and component rules, not because of colour sprawl.

### The one genuine decision in it

The merchant's dark mode is a **server-side store setting** — a merchant choosing how their admin
looks. A shopper's preference is a different thing: it belongs to the viewer, not the store, and
there is no mechanism for it at all today.

The conventional answer is per-viewer browser storage, which needs no schema change and no tenant
consideration. That is the default M2 will take unless the owner says otherwise, and it is recorded
here rather than decided silently, because the alternative — a `customers` column — would be a
schema change on a tenant-scoped table and a different conversation.

### THE STOREFRONT DOES NOT LOAD THE DESIGN SYSTEM AT ALL

Measured: **zero** references to `store-syria-ds.css` in `themes/*/views/` or
`resources/views/front_end/`. So those 146 dark-mode rules are not available to the storefront
today, and loading the sheet is M2's first step, not an optional extra.

`CLAUDE.md` says the design system is confined by a `.ss-` class or a `data-ss-` attribute, with no
physical edges. That claim decides whether loading it on the storefront is safe, so it was
**measured rather than trusted**: of **708 selectors, 705** are scoped by `.ss-` or `data-ss-`.
The three that are not are Bootstrap/DataTables class names:

| selector | on the storefront? |
|---|---|
| `.dataTables_processing` | **no** — 0 storefront views, 0 at runtime |
| `.invalid-tooltip` | **no** — 0 storefront views |
| `.invalid-feedback` | **3 views** (`add_tickets`, `support_ticket_edit`, both in the authenticated customer area); 0 at runtime on `/login`, `/register`, `/contact-us` |

And the one rule that reaches them is `color: var(--ss-danger)` and nothing else — deliberately, with
its own note explaining that overriding the theme's `display: none` "would show every message on
every form at all times." So the whole blast radius of loading the design system on the storefront is
**the colour of validation text on two support-ticket views.**

That is the property that makes M2 low-risk, and it is now a measurement rather than an assurance.

### What M2 therefore has to do

1. set `data-ss-theme` on each theme's `<html>`, from a shopper preference;
2. replace each theme's 14–20 distinct colours with tokens that flip under `[data-ss-theme="dark"]`;
3. add the toggle and its persistence;
4. re-capture the 210-row baseline in **four** combinations rather than two, which is what makes a
   dark-mode claim checkable at all — the Phase 2.5 contrast failure (option names at 1.00:1
   white-on-white) was found by exactly that capture, not by reading CSS.

The 210-row M1 baseline is the before-picture M2 is measured against.
