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
