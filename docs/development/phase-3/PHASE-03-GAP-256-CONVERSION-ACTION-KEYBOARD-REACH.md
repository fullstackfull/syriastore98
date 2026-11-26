# GAP-256 — the product card's conversion actions are reachable by a keyboard

Phase 03 · `P03-B-07` / `P2-A11Y` / `A11Y` · WCAG 2.1.1 (Keyboard, **Level A**)
Measured and closed 2026-11-26. Engineering complete; **one edit to a file this change may not
touch is outstanding and the blocking Unit suite is red until it is applied — see §8.**

---

## 1. What was wrong

Three of the four storefront conversion actions could not be reached by a keyboard **at all**, and
did not exist on a touch device.

`ProductQuickView`, `ProductCompare` and `ProductCSV` each ship an **inline `<style>` inside their
own Blade view**, declaring `opacity: 0; visibility: hidden` on their button and restoring it only
under `.product-box:hover`, `.product-item:hover` or `.product-card:hover`:

| module | file | line |
|---|---|---|
| `ProductQuickView` | `packages/workdo/ProductQuickView/src/resources/views/pages/button.blade.php` | 25–26 |
| `ProductCompare` | `packages/workdo/ProductCompare/src/resources/views/pages/button.blade.php` | 17–18 |
| `ProductCSV` | `packages/workdo/ProductCSV/src/resources/views/pages/button.blade.php` | 23–24 |

**`visibility: hidden` removes an element from the tab order.** So this was never "three invisible
buttons" — it was three conversion actions a keyboard shopper cannot reach, which is a flat Level A
failure, and a touch device has no hover state to reveal them with either.

`ProductsPDF` is the control: it ships **no stylesheet**, and that is the only reason it was
reachable.

### Measured before anything was changed

Chromium 1.63.0, on a rendered `/{store}/home` with the four modules really loaded:

```
       Quick view  visibility: hidden   opacity: 0    44x44
       Compare     visibility: hidden   opacity: 0    44x44
       Export CSV  visibility: hidden   opacity: 0    44x44
       PDF         visibility: visible  opacity: 1    44x44
```

Real `Tab` presses from the first focusable element in the first product card, identical in English
and Arabic:

```
  wishlist-btn -> product-pdf-btn -> product-pdf-btn(a) -> a(title) -> addtocart-btn -> [next card]
```

Quick view, Compare and Export are absent. `ProductsPDF` is present — twice, because its module
nests an `<a>` inside a `<button>`.

---

## 2. The hiding technique chosen, and why

**Resting state: `visibility: visible; opacity: 0; pointer-events: none`.
Reveal: card `:hover`, card `:focus-within`, wrapper `:focus-within`, own `:focus`, and
unconditionally under `@media (hover: none)`.**

Judged against the three audiences:

| | before | after |
|---|---|---|
| **keyboard** | cannot reach the control at all (2.1.1 failure) | reaches it by `Tab`; focusing it paints it to `opacity: 1` and draws a `2px` ring at 4.39:1 (2.1.1 and 2.4.7 both satisfied) |
| **touch** | no hover state exists, so the control never appears | present and tappable at rest, 44×44, `pointer-events: auto` |
| **mouse** | hidden at rest, revealed and clickable on card hover | **byte-identical** — hidden at rest, revealed and clickable on card hover (measured both ways, §5) |

### `opacity: 0` alone is not acceptable, and that is a measurement not an opinion

`visibility: hidden` removes an element from **hit testing** as well as from the tab order.
`opacity: 0` does not. Restoring `visibility` without replacing that would have left a 44×44
invisible control at `z-index: 2` sitting over the product image: a sighted mouse user clicking the
photograph would have fired Quick view, Compare, or a CSV download.

`pointer-events: none` is therefore part of the resting state, and the harness measures the
consequence directly — `document.elementFromPoint` at each button's centre, unhovered and
unfocused, on screen and with a real box:

```
       Quick view  elementFromPoint -> img.w-full.h-80
       Compare     elementFromPoint -> button.product-pdf-btn
       Export CSV  elementFromPoint -> img.w-full.h-80
```

The register row warned that `pointer-events: none` "breaks the mouse, since the modules' own hover
rules do not restore it". That is correct, and it is answered by **mirroring the modules' own three
ancestor `:hover` selectors in the host sheet**, in the *same declaration block* as the keyboard
reveal. One block for every reveal is the design decision rather than tidiness: it is not possible
to fix the keyboard and leave the mouse broken, or to restore paint and forget activation.

### Why not `:focus` / `:focus-visible`

**A focus-driven reveal of a `visibility: hidden` element is dead code.** Focus cannot enter an
element that has been removed from the tab order, so the selector can never match. It compiles, it
reads correctly, and it changes nothing. This is the central trap in the gap and
`ProductActionKeyboardReachCssTest::test_the_resting_rule_depends_on_no_hover_or_focus_state`
exists so a later "simplification" cannot reinstate it.

### Why not `clip-path`, or off-screen-until-focused

Both hide by **geometry**, and the modules' own hover rules restore only `opacity`, `visibility` and
`transform` — never size, clipping or offset. A geometric resting state would stay clipped or
off-screen **for the mouse user on hover** unless this change also re-derived the geometry, and
these three buttons are `position: absolute` at three different `top` offsets set by three different
modules, one of them as an inline attribute (`style="top: 120px"`). `opacity` is the one property
the modules already restore, so keying off it is the minimal congruent change.

This is also the technique `public/css/storefront-keyboard.css` (GAP-095) chose for the skip link,
and its docblock reaches the same conclusion independently: *"`display: none`, `visibility: hidden`
and the `hidden` attribute all remove the element from the tab order, which would make the control
decorative."*

### What is deliberately **not** decided

Whether a storefront card **should** reveal its conversion actions only on hover is a design
question. This change does not answer it: the resting appearance for a mouse user is left exactly as
the modules wrote it. Only reachability is fixed.

---

## 3. Why the fix is host-side and wins on specificity

`packages/workdo/` must not be edited (CLAUDE.md; `app/Http/Middleware/DisabledModuleRouteGuard.php`
is the house precedent for correcting vendored behaviour from the host). The fix is section 6 of
`public/css/storefront-appearance.css`, reaching the page through
`resources/views/front_end/partials/appearance.blade.php`.

The three module `<style>` blocks render **in the body**, inside the product card, while the host
sheet is linked **in `<head>`** — asserted in both directions by
`ProductActionKeyboardReachTest::test_the_host_sheet_is_in_head_and_the_module_styles_come_later`.
At equal specificity the later `<style>` wins on source order, so a bare
`.quickview-btn { visibility: visible }` in the host sheet would have measured **no change at all**.

Every section-6 selector therefore carries `.ss-product-actions` as well:

| | selector | specificity |
|---|---|---|
| module, resting | `.quickview-btn` | (0,1,0) |
| **host, resting** | `.ss-product-actions .quickview-btn` | **(0,2,0)** |
| module, hover | `.product-card:hover .quickview-btn` | (0,3,0) |
| **host, reveal** | `.product-card:hover .ss-product-actions .quickview-btn` | **(0,4,0)** |

`ProductActionKeyboardReachCssTest::test_every_selector_out_ranks_the_module_rule_it_overrides`
computes this rather than asserting it.

**No `!important` is used or needed.** The rest of this stylesheet needs it only to beat Tailwind
utilities injected at runtime by the theme's JIT CDN; these four buttons carry no utility classes,
and the computed styles in §5 settle it. `test_the_fix_needs_no_important` pins the claim.

`.product-box` and `.product-item` are mirrored although **no theme uses them today** (measured:
zero occurrences under any theme's own `views` directory; all three themes and the
`resources/views` fallback carry `product-card` on the outer card, which *is* an ancestor of the
hook). They are mirrored because the **modules** name them: an override covering two of three would
silently stop covering the mouse path for any call site using the third.

---

## 4. The comment that deleted the fix

**The first draft of section 6 did not work, and the reason is worth more than the fix.**

The docblock described the mirrored ancestor classes as *"zero occurrences in `themes/*/views`"* —
written with a real glob. **A CSS comment ends at its first terminator, and that glob contains
one.** The comment closed mid-sentence, the remaining prose became live CSS, and Chromium's error
recovery consumed the next qualified rule it found: **rule 6a, the resting state, the one
declaration the whole fix depends on.**

What the symptoms looked like:

* `/*` openers and `*/` terminators still balanced, 17 / 17;
* braces still balanced, 34 / 34;
* `preg_replace` comment-stripping produced plausible-looking CSS;
* and the live browser measurement reported the three buttons still `visibility: hidden` — which
  reads **exactly** like "the host override does not win on specificity".

It won on specificity fine. It was not there. Chromium's CSSOM told the truth when nothing else did:
`document.styleSheets` held **21 rules and rule 6a was not among them**.

CLAUDE.md records this failure mode for Blade comments and for censuses that read comments. This is
the same mistake one layer down, where **the prose about a fix deleted the fix**. It then happened a
third time, in PHP, while writing the census that guards against it: quoting the stripper's regex
verbatim in a docblock put `*/` inside it and made the test file a syntax error. Three languages,
one mistake — *prose about a delimiter must not contain the delimiter.*

Guarded now by two assertions, neither of which can be satisfied by prose:

* `test_no_comment_in_the_layer_closes_early` — a sequential parse must consume as many comments as
  there are `/*` openers. **Counting openers against terminators does not catch it; both were 17.**
  When a comment closes early its intended terminator is orphaned and the walk consumes fewer.
* `test_no_comment_prose_leaks_into_the_live_stylesheet` — a backtick in the comment-stripped sheet.
  This repository's stylesheet comments are full of them; CSS has no syntax that uses one.

---

## 5. Browser evidence

`node tests/Support/bin/check-product-action-keyboard-live.cjs` — **128 checks, 0 failed, exit 0.**
The page is rendered **out of process** by `tests/Feature/Storefront/ProductActionKeyboardReachTest.php`
with the four modules really loaded, because per CLAUDE.md loading a WorkDo module in-process
pollutes later tests through the process-global Composer `ClassLoader`.

### Premises, in the browser, per locale

`dir` is the expected one · `window.tailwind` is an object (the theme's runtime JIT really loaded) ·
both host stylesheets are linked · all four buttons are in the **first** product card · no script
errors.

### Premises, in PHP (8 tests, 59 assertions, all passing)

`module_is_active()` has **three** gates and the fixture opens all three — an `add_on_managers` row
alone renders a card with no conversion actions on it, on which every assertion would pass by being
about nothing:

1. the four modules really loaded — asserted by the **routes** they register, not by the row;
2. the **store owner's `Plan.modules`** names all four (`module_is_active`, `app/Helper/helper.php:1864`);
3. each module's own per-store `settings` switch is `on`.

Plus: all four buttons in the bytes · the three modules' **own `visibility: hidden` arrived in the
capture** (so a green run cannot mean "the modules stopped hiding and the override is dead code") ·
`product-card` really precedes the hook as an ancestor · both host sheets linked · the host sheet in
`<head>` and the module styles after it.

### Tab order after the fix — identical in English (LTR) and Arabic (RTL)

```
  wishlist-btn -> quickview-btn -> quickview-btn(a) -> compare-btn
               -> product-pdf-btn -> product-pdf-btn(a) -> csv-btn
               -> a(title) -> addtocart-btn -> [next card]
```

All three are reached, and the hit is asserted to be **inside the first card** — a button on card 4
would otherwise satisfy a walk that never left card 1.

### Per button, per locale (EN and AR measured identically)

| state | Quick view | Compare | Export CSV | PDF (control) |
|---|---|---|---|---|
| **resting** | `visible` / `0` / `none` / 44×44 | same | same | `visible` / `1` / `auto` |
| **focused** (settled) | `visible` / `1` / `auto` / 44×44 | same | same | — |
| focus ring | `solid 2px rgb(79,119,192)` | same | same | — |
| **card hover** | `visible` / `1` / `auto` / `matrix(1,0,0,1,0,0)` | same | same | unchanged |
| **`(hover: none)`** | `visible` / `1` / `auto` / tap hits **the button** | same | same | unchanged |

The focus reveal is read **twice**: immediately (the modules declare `transition: all 0.3s ease`, so
the first read catches the fade in flight at `opacity: 0.0384374`) and after it settles. The settled
value is the one gated. The first version of this harness read once and reported the in-flight value
as a failure — which looks exactly like "the focus reveal does not work" and is in fact the reveal
happening.

The touch context is a Chromium context with `hasTouch`, and the harness **asserts
`matchMedia('(hover: none)').matches` as a premise** before reading anything, so a desktop
measurement cannot be reported as a touch one. Recorded for the next person: the CDP
`Emulation.setEmulatedMedia` `features` route does **not** change `hover` or `pointer` in this
Chromium — measured, `hover` stayed `hover`.

### Contrast of the revealed icon, both appearances

| | light | dark |
|---|---|---|
| Quick view | `rgb(5,48,62)` on white — **14.01:1** | **14.01:1** |
| Compare | `rgb(107,114,128)` on white — **4.83:1** | **4.83:1** |
| Export CSV | `rgb(107,114,128)` on white — **4.83:1** | **4.83:1** |

All clear WCAG 1.4.11's 3:1 for a non-text indicator, in both appearances and both directions. The
figures are identical across appearances because the modules hard-code `background: white` on the
button, so the chip does not re-theme — visually plain on a dark card, but not a contrast failure.
The focus ring resolves `--ss-focus-ring` → `--ss-blue-500` = `#4f77c0`, **4.39:1** against the
white chip.

---

## 6. Failing-first, and the revert

The harness is the instrument and it was written **before** the fix existed.

| run | GAP-256 checks | failed |
|---|---|---|
| **before the fix** | 110 | **32** (later 30, after the adjacent PDF-contrast check was reclassified as a non-gate) |
| after the fix | 128 | **0** |
| **fix reverted** (`storefront-appearance.css` restored to its 197-line pre-fix state) | 110 | **30** |
| fix restored | 128 | **0** |

The failures on the reverted run are the same ones, by name: the three resting-state checks, the
three `Tab REACHES` checks, the three focus-appearance checks, and the six touch checks, in both
locales.

One measurement in the first failing-first run **passed for the wrong reason** and is recorded
rather than quietly corrected: the phantom-click check reported `outside-viewport` for all three
buttons, because the harness had not scrolled the card into view. `elementFromPoint` is
viewport-relative, so an off-screen element is indistinguishable from a `pointer-events: none` one.
The harness now scrolls first **and** asserts on-screen-ness as a separate premise.

### Mutation matrices on the census — 15 applied, **15 caught, 0 survived**

Eleven on the shape of the fix:

```
  CAUGHT   drop `visibility: visible` from the resting rule
  CAUGHT   drop `pointer-events: none` from the resting rule
  CAUGHT   drop `pointer-events: auto` from the reveal block
  CAUGHT   gate the resting rule on :focus-within (the dead-code trap)
  CAUGHT   remove the @media (hover: none) touch branch
  CAUGHT   remove the reduced-motion arm
  CAUGHT   hide the always-visible PDF button too
  CAUGHT   drop the .product-item:hover mirror
  CAUGHT   add !important to the resting rule
  CAUGHT   re-introduce the early-closing comment (a real glob in the prose)
  CAUGHT   weaken the resting selector to the bare module class (loses on source order)
```

And four on the colour-free bound that pays for §8's exception:

```
  CAUGHT   a `color` in the resting rule
  CAUGHT   a `background` in the reveal block
  CAUGHT   a `border-color` in the touch branch
  CAUGHT   an `outline-color` in the reduced-motion arm
```

---

## 7. Files changed

| file | what |
|---|---|
| `public/css/storefront-appearance.css` | **the fix** — new section 6 (resting state, one reveal block of 24 selectors, `@media (hover: none)`, `@media (prefers-reduced-motion: reduce)`); 197 → 365 lines |
| `tests/Unit/Ui/ProductActionKeyboardReachCssTest.php` | new — 19 tests, 117 assertions: the shape of the fix, the specificity computation, the comment guards, the control group, the colour-free bound that pays for the dark-mode layer's exception (§8), the vendored-source drift guard |
| `tests/Feature/Storefront/ProductActionKeyboardReachTest.php` | new — 8 tests, 59 assertions: the premises and the two captures the harness photographs |
| `tests/Support/bin/check-product-action-keyboard-live.cjs` | new — the browser harness |
| `docs/development/phase-3/PHASE-03-GAP-256-CONVERSION-ACTION-KEYBOARD-REACH.md` | this document |

Nothing under `packages/workdo/` was touched. No route, no Blade view, no layout.

### Suites run

* `tests/Unit/Ui` — **1417 passed, 1 failed** (the failure is §8, and is the only one; re-run after adding the census test: 1418 passed, 1 failed)
* `tests/Feature/Storefront` — **310 passed, 0 failed**
* `tests/Unit/Ui/ProductActionKeyboardReachCssTest.php` — 19 passed, 117 assertions
* `tests/Unit/Ui/StorefrontAccessibleNamesTest.php` — 25 passed, 0 failed
* `tests/Unit/Ui/StorefrontDarkModeLayerTest.php` — 14 passed, **1 failed** (§8; the only failure anywhere)
* `tests/Feature/Storefront/ProductActionKeyboardReachTest.php` — 8 passed, 59 assertions
* the live harness — 128 checks, 0 failed

The full Unit, Contract and Feature suites were **not** run; they were out of scope for this change.
No entry in `.github/known-test-outcomes.json` is needed: every new test passes.

---

## 8. OUTSTANDING — one edit, in a file this change may not touch, and why it is the right one

`tests/Unit/Ui/StorefrontDarkModeLayerTest::test_no_rule_applies_outside_an_appearance_scope_except_the_declared_one`
**fails with this change in place, and the blocking Unit suite is red until the edit below is
applied.** The rest of that file is green: **14 passed, 1 failed.**
`tests/Unit/Ui/StorefrontAccessibleNamesTest` is **25 passed, 0 failed**.

### First: the gate is right, and its stripper is not being defeated

A guess was circulating that a new CSS **comment** was defeating the test's comment stripper. It is
wrong, and it is wrong by measurement rather than by assurance. Running the test's own stripper
(non-greedy `/* … */`, first terminator wins) over the real stylesheet:

```
'.ss-product-actions .quickview-btn'                          in COMMENT-STRIPPED css: True
'.product-card:hover .ss-product-actions .csv-btn'            in COMMENT-STRIPPED css: True
'.product-item:focus-within .ss-product-actions .compare-btn' in COMMENT-STRIPPED css: True

backticks surviving the strip (prose-leakage canary): 0
`/*` surviving the strip: 0    `*/` surviving: 0
```

The strings in the failure body are **real, live, parsed rules** — Chromium's CSSOM lists the
resting rule as rule #18 of 22 in that sheet. The stripper must not be touched, and was not.

### Second: the exception is correct, on the merits — option (b)

The gate offers two honest outcomes: scope the rules, or declare them an exception. **They belong in
both appearances**, for four reasons, three of them measured.

**1. The properties have no appearance.** Section 6 declares `visibility`, `opacity`,
`pointer-events`, `transform` and `transition` and **nothing else**. There is no dark-mode value of
"is in the tab order". The gate exists because a *colour* leaking across appearances is how this
project shipped 1.21:1 panels; a rule that declares no colour cannot do that.

**This is not a promise — it is asserted, in the file that asks for the exception.**
`ProductActionKeyboardReachCssTest::test_the_unscoped_section_declares_no_property_that_can_change_a_colour`
measures **exactly the rule set the exception permits** (every rule all of whose selectors contain
`.ss-product-actions`), checks every declared property against that five-item allowlist, and
asserts the set is the expected size of 4 so it cannot pass by matching nothing. Mutation-proven:

```
  CAUGHT   a `color` in the resting rule
  CAUGHT   a `background` in the reveal block
  CAUGHT   a `border-color` in the touch branch
  CAUGHT   an `outline-color` in the reduced-motion arm
  4 caught, 0 survived
```

So the exception is bounded by a test rather than by trust: the first colour property anyone adds
to section 6 fails **here**, and the message says to scope the new rule or re-argue the exception —
not to extend the list.

**2. MEASURED — scoping would make a Level A guarantee conditional on a JavaScript-written
attribute.** `data-ss-theme` is set by the inline script in
`resources/views/front_end/partials/appearance.blade.php` (`root.setAttribute('data-ss-theme', …)`).
The stylesheet, by contrast, is a `<link>`. Loading the real capture in Chromium with scripting off:

```
javaScriptEnabled=true   data-ss-theme=auto   visibility=visible opacity=0 pointer-events=none
     an appearance-SCOPED selector would match: true  | the UNSCOPED one matches: true
javaScriptEnabled=false  data-ss-theme=null   visibility=visible opacity=0 pointer-events=none
     an appearance-SCOPED selector would match: FALSE | the UNSCOPED one matches: true
```

With no attribute, an appearance-scoped `visibility: visible` **does not match** and the WCAG 2.1.1
failure comes straight back, while the stylesheet is still loaded and the module's
`visibility: hidden` still applies. Deliberate JS-off browsing is the cheapest way to produce that
state, but not the only one: a CSP that blocks inline scripts, a script error earlier in `<head>`,
or a refactor that defers that script all reach it. (Honest nuance: with scripting off the Quick view
and Compare buttons would not *function* either, since their handlers are jQuery-delegated — but
Export CSV is a plain `<a href>` and works, and the general point is that the attribute is not
guaranteed while the reachability guarantee must be.)

**3. Scoping would triplicate the fix.** Sections 1–3 need a `[data-ss-theme="dark"]` arm *and* an
`[data-ss-theme="auto"]` + `prefers-color-scheme` arm — that is what the sibling
`test_every_utility_override_has_an_auto_arm` exists to enforce, because the `auto` arm was
forgotten once already. Scoping section 6 means three identical copies of 24 reveal selectors, a
resting rule and two media arms, all of which must stay in step. Three copies of a Level A fix is a
drift hazard introduced for no benefit.

**4. House precedent.** `public/css/storefront-keyboard.css` — the other host layer created for a
storefront keyboard defect (GAP-095, WCAG 2.4.1 / 2.4.7) — is entirely unscoped by appearance and
takes its colours from `--ss-*` tokens so it needs no arms. Keyboard operability is appearance-scoped
nowhere in this repository.

### The exact edit

In `tests/Unit/Ui/StorefrontDarkModeLayerTest`, method
`test_no_rule_applies_outside_an_appearance_scope_except_the_declared_one`, append to `$allowed`
(around line 113):

```php
            // GAP-256: the product card's conversion actions — the first rule set in this layer
            // that is NOT a colour mapping. Section 6 restores `visibility` on three vendored
            // module buttons so a keyboard can reach them at all (WCAG 2.1.1, Level A) and adds an
            // `@media (hover: none)` branch for touch. It belongs in BOTH appearances because it
            // declares only `visibility`, `opacity`, `pointer-events`, `transform` and
            // `transition` — none of which has an appearance — and because `data-ss-theme` is
            // written by an inline SCRIPT: measured in Chromium with scripting off, the attribute
            // is absent and an appearance-scoped selector does not match, so scoping this would
            // make a Level A guarantee conditional on a script running.
            // THE EXCEPTION IS BOUNDED, NOT TRUSTED: ProductActionKeyboardReachCssTest::
            // test_the_unscoped_section_declares_no_property_that_can_change_a_colour measures
            // exactly the rules this entry permits and fails on the first colour property added to
            // them (mutation-proven 4/4 against `color`, `background`, `border-color`,
            // `outline-color`). If that assertion is ever removed, remove this entry with it.
            // Narrow by construction: every section-6 selector carries `.ss-product-actions`, and
            // nothing else in this stylesheet does — 33 occurrences, all in section 6.
            '.ss-product-actions',
```

**Verified sufficient without editing the test**: the gate's own algorithm, re-run verbatim over the
real stylesheet with that one entry added, reports **0 unscoped rules remaining** — and confirms the
entry is not a blanket pass (33 occurrences of `.ss-product-actions` in the live stylesheet, all in
section 6). The other 14 tests in that file already pass and the allowlist does not affect them.

---

## 9. What is fixed, and what is not

### Fixed

* **`ProductQuickView` / Quick view** — reachable by `Tab`, revealed on focus, visible and tappable
  on touch, mouse behaviour unchanged, 14.01:1 revealed. Both locales.
* **`ProductCompare` / Compare** — same, 4.83:1.
* **`ProductCSV` / Export CSV** — same, 4.83:1.
* The focus reveal is instant under `prefers-reduced-motion: reduce` — this change gave the modules'
  existing `transition: all 0.3s ease` a **new trigger** (focus), and a 20px slide on every `Tab`
  press is motion a shopper who asked for less should not get. The modules ship no such arm.

### NOT fixed, by name

* **`ProductsPDF`'s printer icon is `1.00:1` white-on-white in LIGHT mode** — newly measured here,
  and a WCAG **1.4.11** failure, not 2.1.1. The module writes `style="color: white"` **inline** on
  the `<i>`, and the button has no background of its own, so it paints on the design system's white
  `--ss-surface`. In dark mode it is fine (14.23:1). Beating an inline style requires `!important`
  from the host, which is a decision of its own rather than a by-product of a tab-order fix, and the
  button is the one of the four `GAP-256` is not about. The harness measures and prints it on every
  run as an **adjacent observation that never affects the exit status**. A register row is proposed
  in §10.
* **Quick view now yields TWO tab stops.** `ProductQuickView` nests `<a class="quickview-btn">`
  inside `<button class="quickview-btn">` — invalid HTML (interactive content inside a button), and
  Chromium keeps it nested (measured). Both are focusable once `visibility` is restored, and the
  module's delegated handler is bound to the class, so both activate the same action. This is not a
  Level A failure; it is a nuisance, and **it cannot be fixed from a stylesheet** — no CSS property
  removes focusability while keeping an element visible, and hiding the inner `<a>` would hide the
  icon and leave an empty chip. `ProductsPDF` already had exactly this shape, before and after.
* **The four chips overlap by 4–5px on a narrow viewport.** The modules place them at `top: 1px`,
  `40px`, `80px` and `120px` while the design system gives each a 44px floor (WCAG 2.5.5), so
  consecutive pairs overlap. On the 390px touch context a tap at the PDF chip's centre lands on the
  Compare chip's icon. Pre-existing, unchanged by this fix, and all three subjects report
  `tap hits: the-button`.
* **The chip does not re-theme.** `background: white` is hard-coded in all three module stylesheets,
  so a white chip sits on a dark card in dark mode. Not a contrast failure (the icon clears 3:1 on
  the white chip in both appearances) and not a reachability question.
* **`resources/views/front_end/layouts/app.blade.php`** — the fourth layout copy — does not
  `@include('front_end.partials.appearance')`, so a page rendered from it loads **neither** the
  design system **nor** this layer, and section 6 does not reach it. Measured, not inherited: that
  file names the partial only inside a comment (*"one of the four that omits
  `front_end.partials.appearance`"*), so a plain grep for the partial's name reports a false hit on
  all four layouts — the comment-census trap, in the direction of a false reading.
  `config('theme.active')` is `stylique`, so a store with an empty `theme_id` renders stylique's
  views and does load the partial. Unchanged by this fix and out of scope.
* The fix was verified on **stylique** (the active theme) in both directions. greentic and techzonix
  were verified structurally only: all three themes, and the `resources/views` fallback, carry
  `product-card` on the outer card — which is the ancestor every reveal selector keys on — but no
  browser capture was taken for them.

### Reproducing

```bash
# the shape of the fix, and the comment guards (blocking Unit suite)
php artisan test tests/Unit/Ui/ProductActionKeyboardReachCssTest.php

# the premises and the captures
php artisan test tests/Feature/Storefront/ProductActionKeyboardReachTest.php

# the behaviour, in Chromium, both locales, both appearances
node tests/Support/bin/check-product-action-keyboard-live.cjs
```

---

## 10. Proposed register text

### `GAP-256` — status **CLOSED**

> **CLOSED 2026-11-26.** Three of the four storefront conversion actions were unreachable by
> keyboard and absent on touch: `ProductQuickView` (`button.blade.php:25-26`), `ProductCompare`
> (`:17-18`) and `ProductCSV` (`:23-24`) each declare `opacity: 0; visibility: hidden` on their own
> button in an **inline `<style>` in the body**, restored only under `.product-box:hover` /
> `.product-item:hover` / `.product-card:hover`. `visibility: hidden` removes an element from the
> tab order. **Measured before the fix in Chromium on a rendered `/{store}/home` with the four
> modules really loaded** (plan gate and all four per-store switches opened, not just an
> `add_on_managers` row): `Tab` from the first product card ran
> `wishlist -> product-pdf -> product-pdf(a) -> title -> add-to-cart -> next card` in **both**
> directions — the three are not in it; and on a `(hover: none)` context all three computed
> `visibility: hidden; opacity: 0`.
> **Fixed host-side in `public/css/storefront-appearance.css` section 6** — `packages/workdo/` is
> untouched. The resting state is `visibility: visible; opacity: 0; pointer-events: none`:
> focusable (the fix), unpainted (the mouse user's resting view, unchanged), and not clickable —
> because `visibility: hidden` was also removing the button from hit testing and `opacity: 0` does
> not, so restoring visibility alone would have left a 44×44 invisible control at `z-index: 2` over
> the product image. **Every reveal is one declaration block** — the three ancestor `:hover`
> selectors the modules themselves use, the ancestor and wrapper `:focus-within`, the button's own
> `:focus`, plus an unconditional `@media (hover: none)` branch and a
> `prefers-reduced-motion: reduce` arm — so the mouse path and the keyboard path cannot be fixed
> independently of each other, which is the register's own warning about `pointer-events: none`
> answered. Specificity, not source order: `(0,2,0)` against the modules' `(0,1,0)` and `(0,4,0)`
> against their `(0,3,0)`, **no `!important`**.
> **Measured after: 128 browser checks, 0 failed, exit 0.** `Tab` now reaches all three inside the
> first card in EN and AR; focused they compute `visibility: visible; opacity: 1;
> pointer-events: auto`, 44×44, with a `2px` `--ss-focus-ring` indicator at 4.39:1; on hover they are
> byte-identical to before; on `(hover: none)` all three are visible, 44×44 and tappable; revealed
> icon contrast is 14.01:1 / 4.83:1 / 4.83:1, identical in light and dark. Failing-first and revert
> both executed (32 → 0 → 30 → 0), and a mutation matrix of 11 mutations on the census was caught
> 11/11, plus 4/4 on the colour-free bound below.
> **THE FIRST DRAFT DID NOT WORK AND THE REASON IS THE LESSON:** its docblock wrote the glob
> `themes/*/views`, whose `*/` closed the CSS comment mid-sentence; the prose became live CSS and
> Chromium's error recovery **swallowed the resting rule**. Openers and terminators balanced 17/17,
> braces balanced 34/34, and the only symptom was a live measurement that read exactly like a
> specificity failure. The same mistake then recurred in a PHP docblock. Guarded by
> `test_no_comment_in_the_layer_closes_early` (openers vs comments a parser consumes — counting
> terminators does NOT catch it) and `test_no_comment_prose_leaks_into_the_live_stylesheet`.
> Evidence: `docs/development/phase-3/PHASE-03-GAP-256-CONVERSION-ACTION-KEYBOARD-REACH.md`;
> instruments `tests/Unit/Ui/ProductActionKeyboardReachCssTest` (19/117),
> `tests/Feature/Storefront/ProductActionKeyboardReachTest` (8/59),
> `tests/Support/bin/check-product-action-keyboard-live.cjs`.
> **ONE EDIT OUTSTANDING, and the blocking Unit suite is red until it lands:**
> `StorefrontDarkModeLayerTest::test_no_rule_applies_outside_an_appearance_scope_except_the_declared_one`
> needs `'.ss-product-actions'` added to `$allowed` — verified sufficient (0 unscoped rules remain)
> by re-running the gate's own algorithm over the real stylesheet without editing the test. The
> exception is ARGUED, not widened, and the argument is measured: section 6 declares only
> `visibility`, `opacity`, `pointer-events`, `transform` and `transition`, so it cannot carry the
> colour regression that gate exists to catch; and with scripting off in Chromium `data-ss-theme` is
> **absent**, so an appearance-scoped selector does not match and scoping would make a Level A
> guarantee conditional on a script running. The exception is BOUNDED by
> `test_the_unscoped_section_declares_no_property_that_can_change_a_colour`, which measures exactly
> the rules the entry permits and fails on the first colour property added (mutation-proven 4/4).
> `StorefrontAccessibleNamesTest` is green (25/0); `StorefrontDarkModeLayerTest` is 14 green and
> that one gate red until the entry lands.
> **NOT fixed, by name:** `ProductsPDF`'s icon at 1.00:1 in light mode (new row below); Quick view
> now yields two tab stops because its module nests an `<a>` inside a `<button>` — not a Level A
> failure and not fixable from a stylesheet, and `ProductsPDF` already had the same shape; the
> modules' 4–5px chip overlap on a narrow viewport; the hard-coded white chip that does not
> re-theme; and greentic/techzonix verified structurally rather than in a browser.

### Proposed new row — `ProductsPDF` icon contrast

> **OPEN.** `ProductsPDF`'s printer icon is **1.00:1 white-on-white in LIGHT mode — the default
> appearance** — so the Export-PDF conversion action on every product card is invisible to a sighted
> shopper. WCAG **1.4.11** (Non-text Contrast, AA). Measured in Chromium 2026-11-26 by
> `tests/Support/bin/check-product-action-keyboard-live.cjs`, which prints it on every run as an
> adjacent observation: `rgb(255,255,255)` on `rgb(255,255,255)`. Dark mode is fine (14.23:1 on
> `rgb(38,43,51)`). Cause: `packages/workdo/ProductsPDF/src/resources/views/pages/button.blade.php`
> writes `style="color: white"` **inline** on the `<i class="ti ti-printer">`, and the button has no
> background of its own, so the icon paints on the design system's `.ss-product-actions` surface —
> white under light. Found while closing `GAP-256` and deliberately **not** fixed there: it is a
> different success criterion on the one button of the four `GAP-256` was not about, and beating an
> inline style needs `!important` from the host, which is a decision rather than a by-product.
> Resolution: one declaration in `public/css/storefront-appearance.css` giving
> `.ss-product-actions .product-pdf-btn i` a token colour with `!important`, verified in both
> appearances — note that the icon must stay legible in DARK mode too, where it currently passes,
> so this is a both-appearances change and not a light-mode patch.
