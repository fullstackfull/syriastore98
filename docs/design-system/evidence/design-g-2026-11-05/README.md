# P2.5-DESIGN-G — 34 of 35 sites painting the brand colour as text failed AA on the dark page

Light fails none of them. The mechanism that fixes light cannot fix dark, and the reason is one
hard-coded constant.

## 1. The existing mechanism, and why it stops at the light page

`--ss-brand-ontext` (`resources/views/layouts/app.blade.php`) already solves this for light: it
takes the merchant's **own** colour and darkens it only until it clears 4.5:1, so no brand colour
changes and a colour-picker value goes through the same arithmetic as the ten presets.

It darkens against a hard-coded **light** page, `PAGE = [249, 250, 251]`. For a merchant whose
colour is already dark the loop exits on its **first test** and returns the brand unchanged —
correct on white, and the whole defect on a dark surface.

Store Syria's own `#335da8` is 6.42:1 on white and **2.22:1** on the dark card.

## 2. Measured

`docs/development/phase-2.5/tools/find-brand-text-failures.cjs` reads `--theme-color` off the live
body and finds every element whose computed text colour **is** that colour — it does not look for
a colour I nominated. Across `/dashboard /finance /customer /order /product-list /setting`:

| | sites painting the brand as text | failing AA |
|---|---:|---:|
| light, before | 44 | **0** |
| dark, before | 35 | **34** |
| light, after | 50 | **0** |
| dark, after | 0 | **0** |

`/setting` alone contributed **20** — its entire tab rail, twenty labels at 2.22:1.

Whole-screen audit, low-contrast findings in dark: **26 → 17**, and the inactive
pagination numbers gained a fix nobody asked for: **3.37:1 → 5.15:1**. Light is **identical to HEAD** on
all six screens (1, 1, 1, 1, 2, 1).

Images: `pair-setting--en-ltr-dark.png`, `pair-setting--ar-rtl-dark.png`,
`pair-dashboard--en-ltr-dark.png` — before on top, after beneath. The RTL pair is not decoration:
Arabic is served `rtl-custom.css`, a **different file**, and the pair is what proves the fix was
written into both.

## 3. The fix is the same mechanism pointing the other way

`--ss-brand-ontext-dark`: the merchant's own colour **lifted towards white** only until it clears
4.5:1. No brand colour changes — every fill, border and badge keeps the merchant's exact value.

Against the **card** surface `rgb(41,42,51)`, not the page `rgb(34,36,44)`. Both were measured,
and for light text the **lighter** surface is the harder one, so a value that clears the card
clears the page too. Computing against the page would under-shoot every card — which is where the
failing text actually sits.

`BrandTextOnDarkContrastTest` parses the surface and the step out of the shipped script and
**runs the algorithm** over all ten presets plus Store Syria's own, asserting each result both
clears AA and keeps its hue. Store Syria's lifts to `rgb(120,148,198)` — within a few points of
`--ss-blue-400` (`#809ac9`), which the design system had already hand-picked as its dark-surface
blue. The arithmetic and the palette agree.

## 4. Three regressions, each caught by measurement, each now a test

This rule looked specific three times and was wrong three times. Every one was found by
re-measuring the running application after the change, not by review.

| exclusion | what it did without it |
|---|---|
| `:not(.ss-btn)` | `.ss-btn` is **the design system's own button class** and is not `.btn`, so it went straight through `:not(.btn)`. `rgb(120,148,198)` landed on the cyan fill of the primary call to action on two screens — "Add Product" and "Create Order" — at **1.47:1**. Both were white before. |
| `.bg-white` | `/finance` hard-codes a **white card header inside a dark card**. The dark token on a white strip took a passing **6.42:1 to 3.07:1**. |
| `:not(.active)` | An **active** tab sits on the brand fill, so its label is white via `--ss-onbrand`. Both arms hit it and the undo arm won: `rgb(51,93,168)` on `rgb(51,93,168)` — **the same colour twice, 1:1** — worse than anything this package set out to fix, on a tab that was correct at HEAD. Confirmed by re-measuring at HEAD rather than assumed. |

| `:not(.page-link)` | Found **after** the package was committed, by the general census. The **active pagination number** sits on the brand fill: `rgb(255,255,255)` on `rgb(51,93,168)` at **6.42:1** before, `rgb(120,148,198)` on the same fill at **2.09:1** after — measured at the parent commit and at HEAD, not inferred. The *inactive* numbers keep the lift and are a genuine gain: **3.37:1 → 5.15:1**, a failure that was already there. |

The third and fourth are instances of the first principle: **the text colour follows the surface,
not the theme.**

### My own probe could not see the fourth

`find-brand-text-failures.cjs` looks for elements painting the **raw** brand colour. After the fix
the regressed element paints the **lifted** colour, so the targeted tool reported "0 of 0" on the
very screens carrying it. `census-low-contrast-text.cjs`, which groups *every* failing colour
rather than hunting one, found it in the first run.

**A probe built for a fix cannot validate that fix.** It is the instrument that agrees with you.

## 5. Two guards were vacuous and are repaired

- `assertStringContainsString(':not(.ss-btn)', $block)` passed when the exclusion was deleted from
  the arm that needed it, **because it was still present in the other arm**. It now extracts that
  specific arm and checks it. The bite confirms all three exclusions redden.
- The block-slice started at the heading text, which is **inside** the comment, so the comment
  stripper had no `/*` to open on and the prose survived — and the prose names `.nav-link` without
  `:not(.active)` while explaining why it needs it. Second time this exact mistake was made here.

## 6. A pre-existing guard was reading the wrong text

`ReportExportControlContractTest::test_no_element_qualified_anchor_button_rule_reaches_the_page_header_region`
failed on this change. It was **right to exist and reading raw CSS**: the new block comment
contains the words *"an `a.btn` that DOES need the lift"*, the scan matched that prose, ran on to
the next `{`, and reported 900 characters of English as an unscoped rule.

The guard now strips comments first. Bitten three ways: a bare `a.btn` rule is still caught, a
`.btn-icon` rule is still caught, and a commented-out rule is correctly ignored by the anchor scan
(the twins-must-agree case still fires for it, correctly, because only one sheet was touched).

## 7. Recorded, not fixed

**`/finance` renders `.card-header.bg-white` inside a dark card** — a light surface appearing in
dark mode, hard-coded in the markup. This package stops making it worse and does not repair it; it
belongs with dark-mode surface work, not with the brand-colour mechanism.

## 8. Not verified

Headless Chromium, 1440×900, one machine. RTL was photographed but not read by an Arabic speaker.
The PHP test mirrors the shipped loop and pins its **constants and direction** by parsing them; it
does not execute the JavaScript, so a change to the loop body would be caught by the direction and
surface guards rather than by re-running it.

## 9. Skills and principles applied

- **`ui-ux-pro-max`** — *`color-dark-mode`*: "dark mode uses desaturated / lighter tonal variants,
  not inverted colours; **test contrast separately**." This package is that rule applied to a
  brand colour: the same hue, a lighter tonal step, measured separately per theme. Also
  *`color-semantic`* (a token, not a literal) and priority 1 *`color-contrast`*.
- **`design-system`** — primitive vs semantic. The light token and the dark token are two
  **semantic** values of one idea, derived per merchant rather than chosen; the agreement with
  `--ss-blue-400` is the check that the derivation sits on the existing ramp.
- **`frontend-design`** — "the brief's own words always win." The owner's standing condition is
  that **no brand colour changes**; the merchant's fills, borders and badges are untouched and
  only text derived from the brand moves.
