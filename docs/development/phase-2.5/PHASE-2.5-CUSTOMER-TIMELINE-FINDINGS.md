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

## 3. The 7 date-pill hues are BLOCKED, correcting an earlier line in this file

An earlier version of this section called tokenising the 7 pill hues "genuinely ready". **That was
wrong and is withdrawn.** Measured since: `activity_logs` holds **0 rows**, so no pill renders at
all, and a live contrast reading returned EMPTY in both themes -- there was no `span.end-date` on
the page to measure.

The pills carry TEXT (a date, a product name, a variant), and no rule sets their text colour, so it
inherits the page's -- dark in light mode, light in dark mode -- over grounds that include
`#FFC107` (very light) and `#795548` (dark). Both ends of that range are suspect, but **suspect is
not measured**, and this project has been bitten by exactly that. They need `activity_logs` seeded
with the seven event types (each with the `remark` JSON shape the view decodes) before any colour
decision is made.

## 4. What WAS ready, and is now done (P2.5-WAVE-16)

The missing empty state -- and it was verifiable immediately, precisely BECAUSE the fixture has no
activity: the blank screen was the live state.

`<div @if (count($logs) > 0) class="my-custom-timeline" @endif>` with zero logs rendered an
unclassed, empty div and a `@foreach` over nothing: a blank panel under the stat cards, with no way
to tell "this customer has done nothing" from "this screen is broken". It now renders `<x-ss.empty>`
with a translated title and body, verified in all four combinations.

`ti-timeline` -- the obvious icon name -- does **not** exist in the bundled font, and a missing name
is valid CSS that paints nothing. `ti-activity` does, and is what the screen means.

## Status

**Nothing was changed on this screen.** The two edits the work-list invites are, measured, one
regression and one category error. Recorded so the next pass starts from the measurement instead of
repeating the analysis -- and so neither edit is made by someone reading only the work-list.
