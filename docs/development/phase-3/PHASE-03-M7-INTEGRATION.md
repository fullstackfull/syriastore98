# Phase 03 / M7 — integration, evidence and closure

**Status:** engineering complete. Not released; not merged to `main`.
**Branch:** `feature/phase-03-storefront`.

---

## 1. What M7 is for

M0–M4 built the storefront and its theme editor. M5 and M6 added the platform's own public site
and the documentation centre. M7 is the question none of them answer on their own: **do these work
together, in a browser, for a person, in both languages and both appearances** — and is the
evidence for that honest about what it does not cover.

Three harnesses, all in `docs/development/phase-3/tools/`, all reusing the project's existing
Chromium and the locally installed `axe-core`, none of them reaching off-origin:

| tool | question |
|---|---|
| `platform-site-matrix.cjs` | what does each surface actually render, in all four combinations |
| `platform-site-a11y.cjs` | does it pass the WCAG rules a machine can check |
| `platform-site-journeys.cjs` | can a person get where they are going, and does every link work |

## 2. The result

44 loads: 11 surfaces × {en, ar} × {light, dark}.

| | |
|---|---|
| premises satisfied | **44 / 44** |
| off-origin requests | **0** |
| console errors | **0** |
| light/dark pairs whose background really differs | **22 / 22** |
| WCAG A/AA violation nodes | **0** |
| axe best-practice violation nodes | **0** |
| journeys completed | **3 / 3** |
| internal links followed | **64**, 0 broken |
| in-page anchors resolved | **32**, 0 broken |
| worst body contrast | **13.73:1** |
| worst lab LCP / CLS | **224 ms** / **0.0234** |

Evidence, with the full per-row data and 44 screenshots:
`docs/development/phase-3/evidence/m7-platform-2026-11-15/`.

## 3. What a capture has to clear before it counts

Four of the five ways these captures can lie answer HTTP 200, so status is not the check. A row is
evidence only when the page is not Laravel's error page, the content area holds real text (the
`@yield`/`$slot` mismatch CLAUDE.md records renders perfect chrome, 200 and nothing inside), `dir`
agrees with the locale asked for, the surface's own content marker is present — a string that
exists only inside that page's `@section('content')`, so the chrome cannot supply it — and nothing
was fetched off-origin or logged to the console.

The light/dark pair is compared **as a pair**. A run producing the same background in both columns
has photographed the light page twice and would otherwise report success.

## 4. Three real defects, found by rendering rather than by reading

### A. The public site followed the merchant admin's setting, not the visitor's

The M5 layout read `cust_darklayout` — the **merchant admin's** dark-layout preference
(`Utility.php:45` seeds it `off`, `helper.php:1419` reads it for the admin chrome). A visitor to
the company's marketing site has no account and no such row, so on a dark OS they got a light page
unless the operator happened to have switched the *admin* to dark.

Fixed by defaulting to `data-ss-theme="auto"`, which is the design system's own name for "follow
`prefers-color-scheme`" (`store-syria-ds.css:555`, `:618-619`) — no script, no cookie, no new CSS.
The operator's setting is kept as an explicit override rather than discarded. Two tests added; the
matrix's 22 differing pairs are what prove it reaches the browser.

### B. `color-contrast`, 15 nodes

`--ss-brand-accent` was used for small brand-coloured **text**: the hero eyebrow, the current-page
navigation link, the documentation sidebar's current item. In light mode that token is `#138ba5`
and on `--ss-brand-subtle` (`#f4f5f8`) the measured ratio is **3.64:1** — below the 4.5:1 AA needs
for text that is not large, and 12px at weight 600 is not large.

All nine text uses moved to `--ss-brand`: **5.85:1** light, **7.18:1** dark.

### C. `landmark-complementary-is-top-level`, 12 nodes

The documentation sidebar was an `<aside>` inside `<main>`. It is not complementary content —
`_nav.blade.php` renders a real `<nav aria-label>` inside it, which is the landmark that belongs
there — so the wrapper is now a plain `<div>`.

**All three were invisible in the source.** The token pair reads as deliberate in the stylesheet,
the `<aside>` reads as semantic markup, and `cust_darklayout` reads as "the dark-mode setting".
That is the case for M7 existing at all.

## 5. Two harness defects, recorded because each looked like a product defect

**A raw cookie is not the product's cookie.** The first matrix run wrote `LANGUAGE` with
`context.addCookies()` and all 22 Arabic loads came back `dir="ltr"` in English — 22 red rows that
read as a broken language switch. `EncryptCookies` runs on the `web` group and this application
excepts nothing, so a raw cookie of that name fails decryption and is discarded;
`Cookie::get('LANGUAGE')` answers null and `SetLocate` falls through to the super admin's language.
The harness now drives `/change-languages/{lang}`, which is both correct and the journey a visitor
takes from the footer switch.

**A selector must be visibility-scoped where navigation is rendered twice.** The site renders its
navigation as a wide-screen sidebar and a narrow-screen `<details>` disclosure from shared markup,
so `locator(sel).first()` lands on the hidden copy and the click times out against an element that
is present, correct and invisible. The journeys use `:visible`.

Both are worth a paragraph because each produced a red result that a reader would reasonably have
filed as a product bug, and the first one cost a full run.

## 6. The journeys

Written as a person would describe them, not as a list of routes, because a sequence that is green
step by step can still be nonsense end to end. Each step clicks a **visible** link and asserts both
the URL and a string on the page that follows.

| journey | steps |
|---|---|
| *I want to know what this costs* | home → pricing → FAQ |
| *I am stuck on publishing a theme* | home → documentation → Your storefront → Preview and publish |
| *I am deciding whether to sign up* | home → getting started → contact → legal |

## 7. What these numbers do not say

- **Zero axe violations is not an accessibility pass.** axe automates roughly a third of WCAG.
  Keyboard order, focus management, whether a label *describes* its control, and whether the Arabic
  reads like Arabic are not measured and not claimed. Human review is outstanding, as it is for
  M2-E.
- **The vitals are lab numbers.** `artisan serve` on localhost: no network, no TLS, no CDN, no
  HTTP/2, no opcache, SQLite rather than MySQL. INP is a field metric and is absent. A regression
  baseline, with the same caveat M2-E records.
- **650 KB is dominated by `store-syria-ds.css`** (365 KB uncompressed), which the platform site
  loads whole for a handful of components, and `artisan serve` does not compress. Trimming it is a
  design-system question, recorded rather than done.
- **The capture is of the `p2dev` fixture**, not of production data.

## 8. Engineering gates at M7 close

| Gate | Result |
|---|---|
| Unit | see §9 |
| Contract | see §9 |
| Feature + known-outcome gate | see §9 |
| `platform-site-matrix.cjs` | 44/44 premises, 0 off-origin, 0 console errors, 22/22 pairs differ |
| `platform-site-a11y.cjs` | 0 WCAG nodes, 0 best-practice nodes |
| `platform-site-journeys.cjs` | 3/3 journeys, 64 links, 32 anchors, 0 broken |

## 9. Outstanding at Phase 03 close — owner items, not engineering gaps

| | Owner |
|---|---|
| `Security-sensitive diff: human checklist` on the PR | owner; fails by design until a person attests |
| native Arabic review of the new strings and of the 16 translated articles | owner |
| published contact details (address / telephone / support mailbox) | owner content |
| approved legal text for the five documents | owner / legal |
| human accessibility review (keyboard order, focus, label quality) | owner |
| a production check for an existing `stores.slug = 'platform'` | owner / operations |
| merging Phase 03 to `main`, and release | owner |

The reorder UI deferred in M4 stays deferred: it waits on the storefront consuming saved section
ordering, which is Phase 04 work.
