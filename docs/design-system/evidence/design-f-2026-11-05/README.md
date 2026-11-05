# P2.5-DESIGN-F — every piece of subdued text on every merchant screen failed AA, in both themes

86 failing text nodes across six screens. After: **0**.

## 1. It is two greys, not one

A per-screen sweep prints its first five findings, which is right for a sweep and wrong for
planning a fix: it hides how many distinct **colours** are involved. `census-low-contrast-text.cjs`
groups by `(colour, composited background, theme)`:

| theme | colour | on | ratio | count | screens |
|---|---|---|---:|---:|---|
| dark | `rgb(128,129,145)` | `rgb(41,42,51)` | **3.71** | 51 | /dashboard /product-list |
| dark | `rgb(108,117,125)` | `rgb(41,42,51)` | **3.04** | 15 | /finance |
| dark | `rgb(128,129,145)` | `rgb(34,36,44)` | **4.03** | 5 | /product-list |
| dark | `rgb(108,117,125)` | `rgb(34,36,44)` | **3.30** | 6 | all six — the footer |
| light | `rgb(108,117,125)` | `rgb(249,250,251)` | **4.49** | 7 | all six |

Aiming at either grey alone leaves the other, while the five-line sweep output looks addressed.

## 2. Asked of Chromium, not grepped

Three host rules, all `!important`:

- **`.text-muted { color: #6c757d !important }`** — Bootstrap's default, **byte-identical in the
  light and the dark sheet**. It is theme-blind, which is exactly why it fails in both: 4.49:1 on
  white (under AA *by a hair*, on every screen's footer) and 3.04:1 on a dark card.
- **`.details-card, .dashboard-card { color: #808191 !important }`** — a **container** rule, so
  every element inside a dashboard card inherits it whatever its tag. **51 of the 86 failing nodes
  are `<b>`, `<span>` and bare `<p>` with no colour of their own.** Overriding only `p` leaves them.
- `… .dataTable-info, p:not([class^="ss-"]):not([class*=" ss-"]) { color: #808191 !important }`

## 3. What it carries is not decoration

"Available Balance", "Pending Withdrawals", "Pending Balance", "Total Income", the `500.00` column
on `/finance`, `$462.00` on the dashboard. **Money, at 3.04:1.**

## 4. The defect is a theme asymmetry

`<p>` is **full-contrast body text in light** — `rgb(41,50,64)`, 12.37:1 — and **subdued in dark**
— `rgb(128,129,145)`, 3.71:1. That is not a design decision expressed in two places; it is one
theme missing a level the other has.

The fix gives dark the two levels light already has:

| | light | dark |
|---|---:|---:|
| body (`--ss-fg`) | 12.37:1 | **12.87:1** |
| muted (`--ss-fg-muted`) | **6.14:1** | **6.19:1** |

Both levels clear AA and the hierarchy between them is wider after than before.

## 5. The design-system exclusion is the host's own idiom

`:not([class^="ss-"]):not([class*=" ss-"])` is **already in the rule being overridden**. It anchors
at the start of the class list or after a space, so it cannot be fooled by a class that merely
*contains* `ss-` — `press-note` does. `.ss-section__note`, `.ss-page-header__subtitle` and
`.ss-stat__period` already declare `var(--ss-fg-muted)` and already pass; measured after the
change, all three are unmoved.

**And on the muted arm it is load-bearing for a second reason: specificity.** The host's
`p:not(…ss-…)` is `(0,2,1)`; a plain `html body .text-muted` is `(0,1,2)` — fewer classes, so it
**loses**. Measured: with the plain selector the census fell from 86 to **47**, and every survivor
was a `p.text-muted` still at `#808191`. The same idiom on both arms makes the muted arm `(0,3,2)`,
which wins, and states the true thing while doing it.

`:not(.text-muted)` on the body arm is load-bearing too: without it the `p` arm `(0,4,3)` outranks
the muted arm and every muted paragraph becomes body text — the two levels collapse back into one.

## 6. Result

| | low-contrast findings |
|---|---|
| before | **86** across 6 screens × 2 themes |
| after | **0** |

Nothing else in the audit moved: broken images, nameless controls and small targets are unchanged
— different defect classes, still open.

Images: `pair-finance--en-ltr-dark.png`, `pair-dashboard--en-ltr-dark.png`,
`pair-finance--ar-rtl-dark.png`, `pair-finance--en-ltr-light.png`; before on top, after beneath.

## 7. Tests

`MutedTextContrastTest` — 7 cases, 45 assertions. **Six bites, all red:** the block removed;
`:not(.text-muted)` removed; the DS exclusion removed from the muted arm; the muted arm scoped to
dark only; the container arm removed; and the block written into `custom.css` only, which is the
case that leaves every Arabic merchant unfixed while the English screenshots show it working.

## 8. Not verified

Headless Chromium, 1440×900, one machine, six screens. A seventh screen could carry a fourth grey;
the census is a measurement of what was looked at, not a proof about the whole application.
RTL was photographed but not read by an Arabic speaker.

## 9. Skills and principles applied

- **`ui-ux-pro-max`** — *`contrast-readability`* and priority 1 *`color-contrast`*; *`color-semantic`*
  ("define semantic color tokens, not raw hex in components") is why the fix is two token
  references rather than two hexes; *`visual-hierarchy`* ("establish hierarchy via size, spacing,
  contrast") is the reason the fix keeps **two** levels instead of raising everything to body text.
- **`design-system`** — the semantic layer is what makes one theme-agnostic rule work: `.text-muted`
  is theme-blind in the host and `--ss-fg-muted` flips, so a single declaration repairs both themes.
  Scoping the rule per theme as well would have left light at 4.49:1.
- **`frontend-design`** — "structural devices encode information rather than decorate it." Subdued
  text that cannot be read encodes nothing; the question asked here was *what is this text for*,
  and the answer — balances, totals, a money column — is what settled it at body level rather than
  muted.
