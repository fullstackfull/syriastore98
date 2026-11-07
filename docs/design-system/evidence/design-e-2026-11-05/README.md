# P2.5-DESIGN-E — seven of the host's eight button variants failed AA somewhere

Found by sweeping the screens the earlier packages had not touched. One of the failures is the
control that debits a merchant's wallet.

## 1. The cause is one pattern copied from a light page

`style.css` and `style-dark.css` build their soft buttons as `--bs-btn-color: X` over
`--bs-btn-bg: rgba(X, 0.15)` — **a 15% tint of the button's own text colour**. It fails in both
themes, for opposite reasons:

- over **white**, the tint leaves a pale chip and the host puts the **undiluted hue** on it —
  amber on pale amber is **1.62:1**;
- over a **dark** page, the tint composites *towards* the page until text and background converge —
  `.btn-dark` ends as `rgb(28,35,47)` on `rgb(33,36,44)`, **the same colour twice, 1.02:1**.

`.btn-success` fails in light for a different reason again: `--bs-btn-bg` is `var(--third-color)`,
which resolves transparent with a 0px border — green text on the page that happens to be clickable.

## 2. Measured, all eight variants, both themes

Every variant injected into the live admin so the cascade and the page background are real, and
**every translucent layer composited** rather than skipped:

| variant | light before | light after | dark before | dark after |
|---|---:|---:|---:|---:|
| primary | 6.42 ok | 6.42 | 6.42 ok | 6.42 |
| secondary | 3.31 `<AA` | **11.91** | **2.50 BAD** | **5.09** |
| success | **2.39 BAD** | **7.48** | 6.18 ok | 6.18 |
| danger | 4.14 `<AA` | **7.09** | **1.09 BAD** | **4.80** |
| warning | **1.62 BAD** | **8.47** | 6.09 ok | 6.09 |
| info | **2.52 BAD** | **8.04** | **2.52 BAD** | **8.04** |
| light | 14.63 ok | 14.63 | 14.63 ok | 14.63 |
| dark | 11.26 ok | 11.26 | **1.02 BAD** | **12.87** |

**Worst figure: light 1.62 → 6.42, dark 1.02 → 4.80.** All sixteen combinations clear AA.

Images: `pair-buttons-light.png` and `pair-buttons-dark.png` are the eight variants before (top
row) and after (bottom row), injected into `/finance` at 2× so the real cascade applies.
`page-finance-{before,after}-{light,dark}.png` are the unmodified screen.

**The dark BEFORE image is the clearest single piece of evidence here: `.btn-dark` is not faint in
it, it is absent.** Nothing renders at the right-hand end of the row.

## 3. I found dark first and assumed that was the problem

It was not. The screen sweep that found it only sees the variants a screen happens to use, and
`/finance` uses four — so the first measurement said "four of eight unreadable in dark mode."
Injecting all eight showed **light is the worse theme**. A dark-only fix would have left the worse
half untouched and pointed the record at the wrong theme.

That draft left two false sentences in the shipped block comment, both corrected here: it claimed
*"nothing here applies in light mode, where all eight variants already pass"* — contradicted by its
own table and by the light rules directly beneath it — and it named a ratchet, `SoftButtonDark&#8203;ContrastTest`,
that does not exist.

## 4. `.btn-info` took two wrong tokens before the right one

It is **the only variant whose chip does not change with the theme** — the host paints it `#e8f8ff`
in both sheets — so it must take one colour across both, from a token that does not itself flip.

- `--ss-fg-onfill` (the first attempt, dark half) flips: white in light, grey-950 in dark.
- `--ss-info-fg` (the first attempt, light half) flips too, and is **the wrong hue**: the design
  system's info family is **indigo**, the host's chip is **cyan**. Indigo-800 on pale cyan cleared
  AA at 8.72:1 and still read as two different "info" colours on one button.
- `--ss-cyan-900` is the dark step of **the logo's own cyan**, a primitive defined once on `:root`,
  so it is the chip's own hue and the same in both themes. **8.04:1.**

One chip, one colour.

## 5. Only the text colour moves

The fills stay exactly as the host paints them, so every button keeps its shape and weight on the
page. These classes are used across **70 vendored modules**; a fill change would move all of them.
A test asserts the block sets no `background`, `border-width` or `padding`.

`--ss-fg-muted` is the obvious token for the light `.btn-secondary` and is **the wrong one** — it
resolves to grey-600, which *is* the host's failing colour. A test asserts it is not used there,
because that mistake would leave the ratio unchanged while every other check passed.

## 6. What this does NOT fix, visible in the same screenshots

`.btn-success` in light and `.btn-dark` in dark now have **readable labels and still no visible
fill** — their `--bs-btn-bg` resolves to nothing and to the page colour respectively. They read as
text, not as buttons. That is a **shape** defect; its fix is a fill change, deliberately out of
scope here for the 70-module reason above. Recorded rather than left for a reader to notice.

## 7. Tests, and two vacuous passes the bites caught

`tests/Unit/Ui/SoftButtonContrastOverrideTest` — 13 cases, 52 assertions. Removing the whole
override block reddens **all 13**, and the live measurement returns to `1.02:1` / `1.62:1`.

**The first bite reddened only 11.** `test_every_override_carries_important` asserts *"no override
lacks `!important`"* — **trivially true when there are no overrides at all.** It now asserts the
block exists and that each variant is overridden before checking how.

**The second was the test's own window.** Both block-scoped cases read `substr($css, $at, 4200)`, a
magic length that covered the whole block when written and **63% of it one comment later**. That
silently weakened `test_no_override_changes_a_fill` — a `background:` in the uncovered tail would
have passed — and made the info case match a `.btn-info` written *in prose*, run on to the
`.btn-dark` rule, and report that rule's colour as the info colour. The window is now bounded by
the next section heading, with comments stripped; a separate bite adds `background: red` to the
**last** rule in the block and confirms the fill case reddens.

## 8. Not verified

The ratios cannot be computed from the design system's own stylesheet — the fills are
`--bs-btn-bg` values in the **vendored** host sheets, composited against a page the host also
paints. So the suite holds a source ratchet and the ratios are measured out of suite by
`docs/development/phase-2.5/tools/measure-button-contrast.cjs`. **Run it after touching any of
this.** One machine, headless Chromium, `/finance`, LTR only; the override is direction-agnostic
(text colour) but RTL was not photographed for this package.

## 9. Skills and principles applied

- **`ui-ux-pro-max`** — priority 1 *Accessibility · `color-contrast`* (4.5:1 normal text) is the
  rule the whole package serves; *`color-dark-mode`* ("dark mode uses desaturated / lighter tonal
  variants, not inverted colours; **test contrast separately**") is precisely the rule the host
  sheet breaks by reusing one tint formula for both themes; *`state-clarity`* and
  *`color-semantic`* drove using the design system's own `*-fg` tokens rather than new hexes.
- **`design-system`** — the three-layer token rule ("never use raw hex in components — always
  reference tokens") is why the fix is five token references, and the primitive/semantic
  distinction is exactly what resolved `.btn-info`: a **semantic** token flips with the theme, a
  **primitive** does not, and the chip does not.
- **`frontend-design`** — "spend your boldness in one place… cut any decoration that does not serve
  the brief" argued against restyling the buttons while fixing them; the change is text colour
  only. Its warning about defaults is why the info text is the logo's cyan rather than a generic
  near-black, which would have been the safe, characterless choice.
