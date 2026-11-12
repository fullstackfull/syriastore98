# P2.5-DESIGN-D — the dashboard's stat grid had no visible structure at all

The design system states the decision in its own comment:

> *"Measures get no card. A number does not need a box, and if every region on the dashboard is a
> card then nothing on it has emphasis. Cells are divided by hairlines instead."*

That is a good decision. **It was not the one being delivered.** The hairlines measured **1.28:1**
against the page in light and **1.38:1** in dark — at that ratio they are not a quiet structure,
they are nothing, and the four figures read as text floating on the page.

---

## 1. Measured, in Chromium, against the real painted background

| | before | after |
|---|---:|---:|
| stat-grid leading hairline | **1.28:1** | **3.23:1** |
| cell hairlines | **1.28:1** | **3.23:1** |
| *(dark)* leading hairline | **1.38:1** | **4.00:1** |
| *(dark)* cell hairlines | **1.38:1** | **4.00:1** |

WCAG 1.4.11 asks **3:1** of *"parts of graphics required to understand the content"*. When a
component deliberately has no box, its dividers are exactly that.

### The text was never the problem, and my first probe said it was

`.dash-content`'s computed `background-color` is `rgba(0, 0, 0, 0)`. A probe that reads it compares
every colour against **black**:

```
FIRST (WRONG) RUN — against rgba(0,0,0,0), i.e. black
  label  3.27:1        value  1.24:1        period  3.27:1      <- all false
```

A value at 1.24:1 is the kind of number that sends you to fix text. Composited up to the first
opaque ancestor, the same colours are fine and always were:

```
light (page #f9fafb)   label 6.14:1   value 16.15:1   period 6.14:1
dark  (page #22242c)   label 6.72:1   value 12.84:1   period 6.72:1
```

The finding survived the correction. The first diagnosis did not. This is the project's own recorded
trap — *"the probe sampled the lighter surface"* — in a second form.

---

## 2. What changed

`.ss-stats` and `.ss-stat` take **`--ss-border-strong`**, which is an **existing token**, not a new
value. The change is **scoped to this component**: `--ss-border` is correct for a line on a card,
where this strength would be loud. The stat grid is the one component whose rules carry all of its
structure, because it deliberately has no box — and a test asserts the card did **not** change.

---

## 3. Tests, and the bite that found a weak test

`tests/Unit/Ui/StatGridHairlineContrastTest` — 7 cases, 46 assertions:

- the contrast instrument is calibrated (21:1 black on white, 1:1 on itself)
- the hairline clears 3:1 in **both** themes
- **`--ss-border` would not have cleared it** in either — which is what makes this a finding rather
  than a preference
- the grid declares the strong token on all three sides
- the **card keeps the ordinary token**, so the change stayed scoped

**The first bite exposed a weak test of my own.** Reverting the component to `--ss-border` reddened
only the declaration case; both contrast cases stayed **green**, because they resolved a hard-coded
`var(--ss-border-strong)` — a token the component no longer used. *A contrast test that cannot
notice the component changing is measuring an aspiration.* They now read the token out of
`.ss-stats`'s own rule, and the same bite reddens **3 of 7**.

Two parser defects were also found and fixed in writing this, both in borrowed code:

- `declarations()` captures `([^;}]+)`, which **runs into a trailing comment** — `--ss-bg` in the
  dark block resolved to forty words of prose followed by the hex, and the resolver failed. Comments
  are stripped first now. The same helper is copied in `SwitchKnobContrastTest` **without** that
  strip; it passes today because the tokens it resolves happen to have no comment after them, which
  is luck rather than correctness.
- I wrote a literal comment-close inside a PHP docblock and ended the docblock — **the second time
  in this session**, the first being in `DesignSystemStylesheetScopeTest`, describing the very same
  class of defect in CSS.

---

## 4. Not verified

- One machine, headless Chromium, 1440×900. No device matrix.
- The ratchet resolves tokens from the stylesheet, so it computes against **`--ss-bg`**, the design
  system's own page surface. In dark mode the painted page is `#22242c`, from the **vendored host
  sheet**, not this token. `--ss-bg` (`#1b1f25`) is the darker and therefore stricter of the two, so
  a pass there implies a pass here — but the browser figures above are the ones measured on the
  real page.
