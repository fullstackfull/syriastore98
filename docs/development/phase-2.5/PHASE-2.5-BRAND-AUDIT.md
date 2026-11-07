# Phase 2.5 — brand audit, measured

> **What this answers.** The owner's decision of 2026-11-03 (D-41) asks to *"audit existing Laravel
> and Flutter brand tokens, then establish one cohesive identity based on the real logo"*, and
> supplies the logo plus two sampled values. Everything below is measured from the artwork and from
> the two codebases. Nothing here is an impression of a screenshot.

---

## 1. The artwork, and what it actually contains

The authoritative file is committed at
[`../../design-system/brand/store-syria-logo-reference.png`](../../design-system/brand/store-syria-logo-reference.png)
(899×342, the full wordmark). The runtime copies live under `storage/app/public/uploads/`, which is
gitignored, so a test could not have relied on them.

Sampling every pixel with alpha > 200, HSV saturation > 0.25 and value > 0.15 — **30,235 chromatic
pixels**:

| | |
|---|---|
| hue span | **189.4° – 218.9°** |
| median hue | 201.7° |
| dense bands | ~191° (cyan) and ~217° (blue) |
| most common chromatic colour | **`#335DA8`**, hue 218.5°, **3,644 pixels** |
| centre of the cyan band | **`#0EAED1`**, hue 190.8°; **27%** of pixels within 3° of it |

**Both reproduce the owner's own sampled values** (`#335DA8` blue, `#0EAED1` cyan). That agreement
is the cross-check that matters: the file in the repository and the file the owner sampled are the
same artwork.

Distribution, in 5° buckets:

```
185-190 :    469 ( 1.6%)
190-195 :   8660 (28.6%)   <- the cyan band
195-200 :   4674 (15.5%)
200-205 :   4151 (13.7%)
205-210 :   4628 (15.3%)
210-215 :   2226 ( 7.4%)
215-220 :   5427 (17.9%)   <- the blue band
```

---

## 2. Every brand token in both repositories, before the change

Hue in degrees; "in band" means inside the artwork's own 189.4–218.9° gradient.

| | token | value | hue | in band | contrast on white | on `#0F1A2E` |
|---|---|---|---|---|---|---|
| **LOGO** | blue anchor | `#335DA8` | 218.5 | — | 6.42:1 | 2.71:1 |
| **LOGO** | cyan anchor | `#0EAED1` | 190.8 | — | 2.63:1 | 6.62:1 |
| web | `--ss-brand` | `#097465` | 171.6 | **NO**, 17.8° out | 5.68:1 | 3.06:1 |
| web | `--ss-brand-accent`, `--ss-focus-ring` | `#08917f` | 172.1 | **NO**, 17.3° out | 3.91:1 | 4.44:1 |
| web | `--ss-teal-600` *("closest step to the logo")* | `#0bb19b` | 172.0 | **NO**, 17.4° out | 2.70:1 | 6.44:1 |
| web | host chrome `theme-3` | `#10B77F` | 159.9 | **NO**, 29.5° out | 2.59:1 | 6.71:1 |
| Flutter | `LightThemeColor.cPrimary` | `#172C4E` | 217.1 | **yes** | 13.94:1 | 1.25:1 |
| Flutter | `LightThemeColor.cBlue` | `#2C57A2` | 218.1 | **yes** | 7.02:1 | 2.48:1 |
| Flutter | `LightThemeColor.cAccent` | `#00B4D8` | 190.0 | **yes** | 2.46:1 | 7.06:1 |
| Flutter | `DarkThemeColor.cPrimary` | `#10AFC0` | 185.8 | **no**, 3.6° out | 2.65:1 | 6.55:1 |
| Flutter | `DarkThemeColor.cAccent` | `#22C7EA` | 190.5 | **yes** | 2.01:1 | 8.63:1 |
| Flutter | `DarkThemeColor.cBlue` | `#6EA2FF` | 218.5 | **yes** | 2.54:1 | 6.85:1 |

### The finding that inverted the expected direction of work

**Five of the Flutter app's six brand colours were already inside the logo's gradient.** `cBlue` is
**0.4°** from the owner's blue anchor and `cAccent` is **0.8°** from the cyan one. The app was never
the thing that had drifted.

**The web design system was.** `--ss-brand` sat 17.8° outside, and the host chrome 29.5° outside.

### Why the web ramp was wrong, which is not the same as carelessly made

`docs/design-system/README.md` said the ramp came from *"the merchant's **own logo mark**
(`uploads/2/*logoStore.svg`, a 168-197° teal-green gradient with a median hue of 177°)"*. That is an
accurate description of that file — decoding its embedded PNG gives hue **167.2–197.5°, median
177.3°**, and the ramp at 172° does sit inside it.

The problem is the path. `uploads/2/` is an **upload under store id 2** — a tenant's own file. Its
artwork is a teal-green *recolour* of the same mark. A sound derivation from the wrong copy of the
logo, and nothing in the test suite could catch it because nothing in the suite could see a picture.

Both files, measured side by side:

| file | geometry | hue span | median |
|---|---|---|---|
| `uploads/2/74_1788254368_logoStore.svg` (embedded PNG) | 198×280 | 167.2–197.5 | 177.3 |
| `uploads/19/80_1787052894_logoStore.png` | 288×316 | 189.1–219.5 | 199.4 |
| `uploads/*/Logo-01.png` — **the authoritative wordmark** | 899×342 | **189.4–218.9** | **201.7** |

The same mark exists in the uploads directory in both a teal recolour and the real blue-cyan.

---

## 3. What was changed, and what deliberately was not

### Web — `P2.5-BR-01`, token layer only

Not one component was edited. Every `--ss-teal-*` reference lived inside the token blocks, so
re-pointing **layer 2** reached the whole system — which is what the three-layer architecture exists
for, and the only reason a brand change of this size is a small diff.

| token | light | dark |
|---|---|---|
| `--ss-brand` | `--ss-blue-600` `#335da8`, **6.42:1** on white | `--ss-cyan-500` `#2ac3e5`, **7.40:1** on the host dark page |
| `--ss-focus-ring` | `--ss-blue-500` `#4f77c0`, **4.44:1** | `--ss-cyan-400` `#68cbe1`, **8.27:1** |
| `--ss-brand-accent` | `--ss-cyan-700` `#138ba5`, **3.99:1** | `--ss-cyan-400` |
| `--ss-brand-gradient` | `linear-gradient(135deg, blue-600, cyan-600)` | the 500 steps |

Blue is primary because **only the blue can carry a white label**: at 218° a step light enough to
still read as the brand clears 4.5:1 on white, while the cyan does not reach 4.5:1 until it has
darkened to a petrol. The dark brand is **derived**, not the light hex re-used — `#335da8` on the
dark card measures 2.11:1.

### Web — two consequences that were not optional

**The neutrals.** Every grey sat at hue 168–180°, deliberately *"teal-biased so greys sit with the
brand"*. A grey tuned to a teal brand reads **green** beside a blue one, and the dark screenshots
showed it. They moved to 218° at **unchanged HSL lightness and saturation** — which also put them in
the same family as the host's own dark page (`#22242c`, hue **228°**) that the dark cards float on.
All 58 contrast pairs were re-measured rather than assumed, because blue is the least luminous
channel and every step darkened slightly.

**Informational.** It sat at hue 208.5° — ten degrees from the new brand. Two saturated blues nobody
could tell apart. It is now indigo `#3f3a8f`, 40° clear of the brand and 70°+ clear of every other
status. The owner's decision requires the status colours stay distinct; once the brand lands on
blue, that requirement *moves one of them*.

### Web — `P2.5-BR-02`, the host chrome

A store that has chosen no colour used to get `theme-3`. The design system painted blue while the
sidebar and every `.btn-primary` beside it stayed green — **two identities on one page**, measured
in the running admin. The fallback is now `custom-color` plus `StoreSyriaBrand::CHROME_DEFAULT`,
which is the host's **own** mechanism (`public/css/custom-color.css` drives 39 selectors off
`--color-customColor`, the active sidebar item among them), so nothing vendored is edited. **A store
that has chosen a colour still wins** — white-labelling is untouched.

### Flutter — `P2.5-BR-03`, centralisation and one correction

`lib/utils/store_syria_brand.dart` now holds the two measured anchors and mirrors
`App\Support\Branding\StoreSyriaBrand`, so the two platforms cite the same numbers.

| | before | after | why |
|---|---|---|---|
| `LightThemeColor.cPrimary` | `#172C4E` | `StoreSyriaBrand.blue` `#335DA8` | the logo sets its own tagline in this blue |
| `LightThemeColor.cAccent` | `#00B4D8` | `StoreSyriaBrand.cyan` `#0EAED1` | 0.8° correction onto the anchor |
| `DarkThemeColor.cPrimary` | `#10AFC0` (185.8°) | `#2AC3E5` | the app's only off-brand colour; now the same step the web uses for its dark brand |
| `cLabel`, `cBottomNavyBlueColor` | `#172C4E` | **unchanged**, now named `StoreSyriaBrand.navy` | *"Keep navy where appropriate as a secondary or neutral design color"* — white on it is 13.94:1 |

**The 293 hardcoded `Color(0x…)` literals are deliberately untouched**, per *"avoid a blind global
replacement"*. They are in the right hue family already; migrating them is screen-by-screen work.

Two globals keep misleading names on purpose: `AppColor.themeGreenColor` and `AppColor.cGreenFont`
have held a **cyan** since long before Phase 2.5. Renaming them touches 121 call sites for no
behaviour change, so the names stay and `store_syria_brand_test.dart` states what they really hold.

---

## 4. How it is verified

| | |
|---|---|
| `docs/design-system/tools/check-contrast.py` | **58/58 pairs pass**, light and dark; auto scope identical to dark across 37 tokens |
| `tests/Unit/Ui/StoreSyriaBrandTokensTest.php` | 8 tests / **131 assertions**; two of them **decode the committed PNG** and assert the hue band and the dominant colour |
| `test/brand/store_syria_brand_test.dart` | 13 tests pinning the anchors, band membership in both palettes, the derived dark variant, and the retained navy |
| `test/brand/brand_is_painted_test.dart` | **reads the pixels back out of a rasterised frame** — the engine's own output, with an anti-vacuity control that paints the retired green and checks the measurement calls it off-brand |
| `docs/development/phase-2.5/tools/render-matrix.cjs` | `/dashboard`, `/category`, `/product-list` at HTTP 200 in en/ltr and ar/rtl × light and dark, direction and dark stylesheet asserted as premises per row |
| `docs/development/phase-2.5/tools/find-offbrand-colours.cjs` | walks every element of a rendered screen; `/dashboard` clean in both themes |

### Bites

| bite | fails |
|---|---|
| point `--ss-brand` back at the retired teal | exactly 2 tests |
| put info back on the brand's blue ramp | exactly 1 |
| restore the `theme-3` chrome default | exactly 1 |

---

## 5. The limitation, stated plainly

**There is no on-device Flutter verification in this environment, and none is claimed.**

* `flutter devices` offers only **Linux (desktop)** and **Chrome (web)** — no Android SDK
  (`ANDROID_HOME` unset, no `adb`, no `emulator`), no attached device.
* `flutter build web` **fails** before anything can be screenshotted: `dart2js` aborts inside
  `firebase_messaging_web-3.5.18`'s interop with `Type 'PromiseJsImpl' not found` and
  `Method not found: 'handleThenable'` — a package/SDK incompatibility in a dependency, not in this
  app's code.

So the Flutter evidence is: **the Flutter engine rasterised these widgets and these are the pixels
it produced.** That is a real render by the real pipeline. It is not a phone.

The Laravel evidence has no such gap — those screens were driven in Chromium against the running
application.
