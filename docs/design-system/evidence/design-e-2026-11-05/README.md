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
| info | **2.52 BAD** | **8.72** | **2.52 BAD** | **17.23** |
| light | 14.63 ok | 14.63 | 14.63 ok | 14.63 |
| dark | 11.26 ok | 11.26 | **1.02 BAD** | **12.87** |

**Worst figure: light 1.62 → 6.42, dark 1.02 → 4.80.** All sixteen combinations clear AA.

## 3. I found dark first and assumed that was the problem

It was not. The screen sweep that found it only sees the variants a screen happens to use, and
`/finance` uses four — so the first measurement said "four of eight unreadable in dark mode."
Injecting all eight showed **light is the worse theme**. A dark-only fix would have left the worse
half untouched and pointed the record at the wrong theme. The heading of the CSS block was written
twice for that reason.

## 4. Only the text colour moves

The fills stay exactly as the host paints them, so every button keeps its shape and weight on the
page. These classes are used across **70 vendored modules**; a fill change would move all of them.
A test asserts the block sets no `background`, `border-width` or `padding`.

`--ss-fg-muted` is the obvious token for the light `.btn-secondary` and is **the wrong one** — it
resolves to grey-600, which *is* the host's failing colour. A test asserts it is not used there,
because that mistake would leave the ratio unchanged while every other check passed.

## 5. Tests, and a vacuous pass the bite caught

`tests/Unit/Ui/SoftButtonContrastOverrideTest` — 12 cases, 41 assertions. Removing the whole
override block reddens **all 12**, and the live measurement returns to `1.02:1` / `1.62:1`.

The first bite reddened only **11**. `test_every_override_carries_important` asserts *"no override
lacks `!important`"* — **trivially true when there are no overrides at all.** It now asserts the
block exists and that each variant is overridden before checking how. That is the project's
recorded failure mode: a guard that passes when the thing it guards is gone.

## 6. Not verified

The ratios cannot be computed from the design system's own stylesheet — the fills are
`--bs-btn-bg` values in the **vendored** host sheets, composited against a page the host also
paints. So the suite holds a source ratchet and the ratios are measured out of suite by
`docs/development/phase-2.5/tools/measure-button-contrast.cjs`. **Run it after touching any of
this.** One machine, headless Chromium, no device matrix.
