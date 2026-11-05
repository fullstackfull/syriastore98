# P2.5-DESIGN-I — the pinned actions column covers the heading beside it

The merchant reads **"STATU"** on `/customer` and **"ORDER STATU"** on `/order`.

## 1. Measured

`docs/development/phase-2.5/tools/measure-pinned-column-overlap.cjs`, 1440px, light:

| screen | table | port | overflow | pinned column covers | by |
|---|---:|---:|---:|---|---:|
| `/customer` | 1132 | 1095 | 37px | `Status` | **7px of its text** |
| `/order` | 1199 | 1095 | 104px | `Order Status` | **23px of its text** |

> ### CORRECTED 2026-11-05 — the `/customer` figure above was measured on a reduced UI
>
> Those numbers were taken against a dev fixture whose merchant held **44 of 202 permissions**
> (see `F-DESIGN-8`). With the full set granted, `/customer` measures:
>
> | | reduced | full |
> |---|---:|---:|
> | `Action` column width | 90px | **126px** |
> | table width | 1132 | **1168** |
> | overflow beyond the port | 37px | **73px** |
> | `Status` text covered | 7px | **43px** |
>
> **Every other column is byte-identical.** The whole difference is the `Action` column, which
> grows because the full permission set adds a third row action — the cart view — beside the
> activity timeline and delete.
>
> 43px of a 47px word: the header reads **"S"**, not "STATU".
> See `thead-full-permissions-light.png`, which is what a fully-permissioned merchant sees.
>
> `/order` is unaffected: its columns do not change with the permission set.
>
> The conclusion is unchanged and the fix is unchanged — the overlap is inherent to an end-pinned
> sticky column and the affordance is the remedy. **The defect is simply larger than recorded.**

In **RTL both tables fit exactly** (1095 in 1095) and nothing is covered — so an English-only
check calls this screen clean. That is why the tool takes a `--dirs` argument and the evidence
below includes an RTL pair.

## 2. Three explanations that are wrong, each closed by measurement

**Not a broken pin.** `.table-responsive` *is* a scrollport — `overflow-x: auto` — and it really
scrolls. Driven to the end it moves 37px and 104px, and the covered text comes **fully clear**
(coverage `+7 → −30` and `+23 → −81`). The sticky column behaves exactly as specified. My first
hypothesis was that it had no scrollport to pin to; the probe refuted it.

**Not something to un-pin.** P2-03-1 added the pin on evidence: at 390px, **848 of the Arabic
table's 1168 pixels are off-screen**, and *with the class removed* a real click on Edit at the
table's starting scroll position **navigated nowhere — in all eight configurations, 1440px
included**. Un-pinning trades a cosmetic overlap for an unreachable control.

**Not fixable by narrowing the sort gutter — and this is where a guess was caught.** The sortable
headers carry `padding-right: 30px`, which looks like 10px of slack across five columns: 50px
against 37px of overflow, comfortably enough. Measured, the sort indicator is two
absolutely-positioned pseudo-elements — `::before` at `right: 13px`, `::after` at `right: 6.5px`,
each 11.7px wide — so the **ink runs to `right: 24.7px`**. Real slack is **5.3px** per header,
**25px** in total: *less* than the 37px needed, and the 20px gutter I had arithmetic for would
have clipped the arrows.

## 3. So the fix is the signal, not the geometry

The column genuinely floats above content that genuinely continues underneath, and it was drawn
with a **1px hairline** — which reads as one more table rule. It now carries a soft outward cast
on the edge content scrolls under: the standard data-grid signal for exactly this.

Two tokens, not one, and not for symmetry: **`box-shadow` has no logical form**, so the
inline-start direction cannot be expressed once. In LTR the column sits at the trailing edge and
casts back towards the start (`-10px`); in RTL it sits at the leading edge and casts the other way
(`+10px`). The hairline beside it already needed the same mirror. The `-10px` spread pulls the
cast off the opposite edge entirely, so the column shows an edge only where there is something to
be under it.

The alpha differs per theme because it must: `rgba(11,31,28,0.20)` on a white page,
`rgba(0,0,0,0.60)` on a near-black one, where the light value is invisible.

## 4. Nothing moved

Column geometry captured in all four combinations, before and after:

```
light/ltr  th lefts: 300,350,496,694,849,1007,1082,1191,1253,1305   table 1132   — identical
light/rtl  th lefts: 1089,961,760,603,465,399,304,241,141,45        table 1095   — identical
dark/ltr   th lefts: 300,350,496,694,849,1007,1082,1191,1253,1305   table 1132   — identical
dark/rtl   th lefts: 1089,961,760,603,465,399,304,241,141,45        table 1095   — identical
```

`box-shadow` is outside the box model, which is why it is the right tool — the design system's own
note beside this rule records that a **border** on a `border-collapse: separate` table shifts the
cell and knocks the column a pixel out of line with its heading. A test asserts the rule sets no
geometry property, so that cannot creep back in.

## 5. What this does NOT do

**It does not reveal the covered text.** `/customer` still reads "STATU" at rest; `/order` still
reads "ORDER STATU". The change tells the reader that something continues under the column — it
does not move the column, widen the port or shorten the table. Removing the overlap needs either a
narrower table (shown above to be unavailable at this gutter) or a layout where the pinned column
sits *outside* the scrolling area rather than inside it, which is a different component than the
one this codebase has.

Stated here rather than left for a reader to discover, because the before/after images show a
changed edge and an unchanged word.

## 6. Tests

`PinnedColumnEdgeAffordanceTest` — 4 cases, 23 assertions. **Five bites, each reddening exactly
one case:** the token dropped from the dark arm; the RTL rule given the LTR token; the outward
cast removed while the hairline stays; a layout property added to the rule; and the two tokens
made to cast the same way.

## 7. Not verified

Headless Chromium, 1440×900, one machine. The 390px case that justifies the pin is quoted from
P2-03-1's record, not re-measured here. No device testing.

## 8. Skills and principles applied

- **`ui-ux-pro-max`** — *`compact-label-overflow`*: "keep essential labels available and **disclose
  unavoidable truncation**". The truncation here is unavoidable at this geometry, so it is
  disclosed. *`elevation-consistent`* argued for a token rather than a literal shadow, and
  *`motion-meaning`*'s sibling principle — a visual device must express a real relationship —
  is why the cast is on the pinned column and nowhere else: it is the only element that really is
  above something.
- **`design-system`** — the three-layer model. This is a **component** token: every elevation
  token in the system casts downward, and none of them can express a sideways cast, so a new one
  is defined at the component layer rather than bending a semantic one.
- **`frontend-design`** — "structural devices encode useful information about the content rather
  than decorate it", and "spend your boldness in one place". A hairline that says nothing was
  replaced by an edge that says one true thing; nothing else on the table changed.
