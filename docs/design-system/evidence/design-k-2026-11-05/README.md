# P2.5-DESIGN-K — the row status switch is a 28 × 17.5 target; WCAG 2.2 asks for 24 × 24

**3 of 477 targets failed. Now 0.**

## 1. Measured against the rule, not against a threshold

SC 2.5.8 Target Size (Minimum), AA, is satisfied by a **24 × 24 CSS px** target **or** by 24px of
spacing **or** by the inline-in-a-sentence exception. A probe implementing all three, across
`/customer /order /product-list /dashboard /finance /product-brand /product-label`:

| screen | failing | targets |
|---|---:|---:|
| `/customer` | **3** | 68 |
| the other six | 0 | 409 |

All three are one component:

```
input.form-check-input.page-checkbox    28 × 17.5    nearest other target 1.8px away
```

**The spacing exception cannot rescue it.** The row's action cluster sits **1.8px** away, and that
exception needs a 24px-diameter circle centred on the target to touch nothing else.

## 2. This is not the "43 small targets" the screen audit reports

`audit-merchant-screens.cjs` flags anything under **44px** for a non-anchor — Apple's HIG
*recommendation* — and counts it in the same number as the 24px WCAG *requirement*. Against the
requirement, measured properly, the figure is **3**, not 43.

The audit's number is not wrong; it answers a different question. It is left alone, and this is
written down so the two are not confused again.

## 3. The cause is the unit, not the number

Bootstrap sizes the switch in `em`:

```css
.form-check-input          { height: 1.25em }
.form-switch .form-check-input { width: 2em }
```

`em` tracks the **local font size**, and this admin's base is **14px**, not 16. `1.25em` of 14px is
**17.5px**. On a 16px page the same declaration yields 20px — *still* short — so the unit has to
change as well as the value. `rem` is anchored to the root and does not drift with a screen's type
scale.

## 4. Measured by injection before it was written

Three candidate sizes injected into the live page, with no file changed:

| candidate | result | conforms | row height | table width |
|---|---|---|---|---|
| baseline | 28 × 17.5 | no | 55.4 | 1168 |
| `2.5rem × 1.5rem` | 40 × 24 | yes | 55.4 | 1168 |
| **`2.5rem × 1.5625rem`** | **40 × 25** | **yes** | **55.4** | **1168** |
| `2.75rem × 1.625rem` | 44 × 26 | yes | 55.4 | 1168 |

**The row height and the table width are identical for every candidate** — the switch grows into
space the row already had, and nothing reflows.

`40 × 25` was chosen for two reasons: it keeps Bootstrap's **1.6 : 1** toggle proportion, because a
square target would conform and read as a *checkbox*; and it leaves a pixel of headroom over the
24px floor rather than landing exactly on it.

## 5. After

**0 of 477 targets fail SC 2.5.8 (AA)** across all seven screens.

`pair-customer-row-light.png` — the row before (top) and after (beneath).

## 6. Scoped, because a pre-existing guard refused the first version

Written as `.form-switch .form-check-input`, the rule reaches **every Bootstrap switch in all 70
vendored modules**. `DesignSystemStylesheetScopeTest` refused it:

```
store-syria-ds.css is loaded on every admin page, across all 70 vendored modules.
These selectors carry no `.ss-` class and no `data-ss-` attribute …
  .form-switch .form-check-input
```

The refusal was right: **12 switches were measured and some hundreds were not.** The rule is now
`.ss-dt-card-body .form-switch .form-check-input` — the design system's own table card, which is
the container all 12 sit in.

**What that leaves, stated rather than implied.** The same three screens hold **27** switches in
total; **15** live in filter panels that are closed at rest. Those keep the host's `em` sizing and
will measure 28 × 17.5 when their panel is opened. They were not measured in that state and are
**not fixed here**.

## 7. Tests

`SwitchTargetSizeTest` — 4 cases. **Four bites:** back to `em` (reddens all 4); a height under the
24px floor; a square shape; and the rule removed entirely.

The test computes the pixel value from whatever unit the stylesheet declares, so it fails on a
value that is written in a unit it was not expecting rather than passing by not matching.

## 8. Not verified

One viewport (1440×900), one root font size (16px). A browser or user setting that changes the
root font size moves the computed target — upward if larger, which is safe, and the `rem` unit is
what makes that true. The seven screens are the ones reachable in the dev fixture; module screens
outside them inherit the same rule but were not measured.

## 9. Skills and principles applied

- **`ui-ux-pro-max`** — priority 2 *Touch & Interaction*: *`touch-target-size`* ("min 44×44pt /
  48×48dp; **extend hit area beyond visual bounds if needed**") and *`web-target-size`* ("web
  pointer targets need 24×24 CSS px or a documented exception; **do not substitute native
  units**"). The second is the one that settles this: the HIG number and the WCAG number are
  different requirements and this package measures against the right one. *`no-precision-required`*
  is the user-facing statement of the same thing.
- **`design-system`** — the unit *is* the token discipline here: a component sized in `em` inherits
  whatever type scale it lands in, which is the opposite of a design system's promise. `rem`
  anchors it.
