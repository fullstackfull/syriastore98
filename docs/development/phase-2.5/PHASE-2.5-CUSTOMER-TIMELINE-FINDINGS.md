# The customer timeline — measured, and why the obvious fix would make it worse

**Date:** 2026-11-05 · `resources/views/customer/timeline.blade.php` (511 lines), reachable and
verified: `/customer-timeline/1` answers HTTP 200 titled "Customer Timeline" / "خط الزمن للعميل"
in all four combinations.

Every claim the remaining-areas work-list makes about this screen was re-measured against the file
and **all of them hold**:

| claim | measured |
|---|---|
| hand-drawn inline SVGs | **14** |
| `fill="black"` | **42** |
| distinct `viewBox` values | **6** |
| Tabler icons mixed in beside them | **4** |
| hand-rolled KPI tiles (`h6` + `h3`) | **4** |
| `x-ss.stat` used | **0** |
| `@forelse` / `@empty` (an empty state) | **0** |
| hard-coded hues for the date pills, `custom.css:1973-1997` | **7**: `#2196F3` `#32d7a2` `#4CAF50` `#607D8B` `#795548` `#9E9E9E` `#FFC107` |

Seven Material-Design hues and six different icon grids inside a product that has its own design
system. The work-list's description -- a Material Design demo -- is accurate.

## 1. `fill="black"` MUST NOT simply become `currentColor`

That is the obvious fix and it is **a regression**, which is why it was measured before being
applied rather than after.

The chips these glyphs sit on carry **hard-coded light grounds** of their own, at
`public/css/custom.css:1830-1847`:

```
#FFF1F1   #E0FFF5   #FFF3D6   #ECFFE4
```

Those grounds ignore the theme. So a black glyph on them is readable **in both themes today** --
wrong by coherence, right by contrast. Switching the glyph to `currentColor` makes it inherit the
page's text colour, which on the dark page is *light* -- producing a **light glyph on a hard-coded
light ground**, i.e. invisible. The screen would get worse while the diff looked like a cleanup.

**The correct fix is coupled**: the chip ground and the glyph colour have to move together, to
theme-aware tokens with a dark arm, and the resulting pair has to be contrast-measured per chip in
both themes. That is its own slice, not a find-and-replace.

## 2. `x-ss.stat` is the WRONG component for these four tiles

The work-list suggests the four hand-rolled KPI tiles should use
`resources/views/components/ss/stat.blade.php`. Read against the component, that does not fit:

```php
@props(['label', 'value' => null, 'period', 'href' => null, 'hint' => null])
```

`period` has no default -- the component models a **metric measured over a window**. The four tiles
here are `Customer Name`, `Customer Email`, `Total Spend` and one more: profile FACTS, not
period-scoped statistics. Two of them are not numbers at all. Forcing them through a metric
component would either invent a period that does not exist or render one unavailable.

The tiles do need work -- they are `h6` + `h3` with a coloured `theme-avtar` badge, off the type
scale, and `custom.css:1968-1970` forces the `h3` to 24px. But the answer is a **profile-fact
card**, which the design system does not have yet, not a metric component bent to fit.

## 3. What is genuinely ready to do

* The 7 date-pill hues have **no dark arm at all**, so the pills keep Material colours on the dark
  page regardless of theme. Tokenising them is self-contained -- they are used by this one view.
* The screen has **no empty state**: `@forelse`/`@empty` appear zero times in 511 lines, and the
  `@else` that exists answers "never registered", not "registered but no activity" -- the commoner
  case, which currently renders nothing.

## Status

**Nothing was changed on this screen.** The two edits the work-list invites are, measured, one
regression and one category error. Recorded so the next pass starts from the measurement instead of
repeating the analysis -- and so neither edit is made by someone reading only the work-list.
