# Route 1 — what the captured pixels actually measure

Source: the eight PNGs from the **CI** artifact `golden-baselines-f9d2cf20ccdd87098274232b4bebeb2aee1d6f22`
(run 42401367328, `fullstackfull/StoreSyria_app98`), verified byte-identical to the local captures.
Probe: `pill_contrast.py` (pure-Python PNG decoder; no image library, so it cannot be a library
version artifact).

**None of this makes the eight images reviewed.** They are measurements taken *from* the images.
Step 2 is still a human looking at the contact sheet.

---

## 1. Status-pill contrast — PASSES in all four combinations, 7.18:1 to 8.24:1

Two independent methods, agreeing exactly.

**(a) From the declared colours.** `lib/view/new_widgets/status_pill.dart` declares the foreground
and a 9%-alpha tint; the tint is composited over the real card colour
(`white`, or `DarkThemeColor.cCardBackground` `#18263F`, `lib/utils/colors.dart:47`). This is what
WCAG 1.4.3 measures. The label is 9sp bold — not large text, so the threshold is **4.5:1**.

| state | theme | background | foreground | ratio | |
|---|---|---|---|---|---|
| active | light | `#E8F9F5` | `#165E34` | **7.19:1** | PASS |
| active | dark | `#163347` | `#7FD6A0` | **7.52:1** | PASS |
| inactive | light | `#FFF0F0` | `#8C1D17` | **8.24:1** | PASS |
| inactive | dark | `#2D2B42` | `#F3A9A4` | **7.18:1** | PASS |

**(b) From the rendered pixels.** The probe locates each pill by its exact composited background
value, restricts to that region's bounding box, and reports the extreme pixel inside it. In all
eight images the extreme pixel **is** the declared foreground, present as fully-covered pixels
(10–20 of them per pill). So the render matches the declaration exactly — the ratios in (a) are the
ratios on screen, not a theoretical figure.

This confirms a repair made in an earlier wave (the comment at `status_pill.dart:14-21`: the label
and dot used to be painted in the same saturated hue as their own tint, measuring 2.33:1 and
2.74:1). **It is now verified in pixels, in all four combinations, for the first time.**

## 2. A FALSE POSITIVE, recorded because it nearly became a finding

A first probe ranked candidate glyph colours inside a hand-drawn box by pixel frequency and took the
worst ratio above an arbitrary 1.8 floor. It returned **all eight pills below 3.0:1** — e.g.
`customer-card.light-ltr.png`, "worst glyph `#A0B3D6`, 1.95:1".

`#A0B3D6` is a light blue-grey. The declared foreground of that pill is `#165E34`, a dark green.
**The candidate was not inside the pill at all** — the hand-drawn box caught the adjacent info-row
icon tints (`AppColor.themeNavyBlueColor.withValues(alpha: .07)`). Every one of the eight readings
was an artifact of the heuristic.

Those numbers were **never reported** as findings. They are written down here because a frequency
heuristic over anti-aliased text is exactly the kind of measurement that looks like a result, and
the next person to reach for one should see this first. The fix was to stop guessing at glyphs and
read the declared colours, which the source states exactly.

## 3. A second, independent proof that the Arabic baselines are not frozen English

The pill bounding boxes differ between directions, measured in the same pass:

| image | pill box |
|---|---|
| `customer-card.light-ltr.png` | 49 × 23 |
| `customer-card.light-rtl.png` | **45** × 23 |
| `delivery-boy-card.light-ltr.png` | 58 × 23 |
| `delivery-boy-card.light-rtl.png` | **52** × 23 |

A pill is sized by its label. Had the RTL captures frozen the untranslated English keys — the
defect fixed in `652b532`, where the harness passed no `translations:` and every `.tr` rendered its
raw key — the LTR and RTL boxes would be **identical**, because they would hold the same string.
They are not. This is independent of the "نشط"/"الطلبات" glyph check done at capture time, and it
is measured on the CI images rather than the local ones.
