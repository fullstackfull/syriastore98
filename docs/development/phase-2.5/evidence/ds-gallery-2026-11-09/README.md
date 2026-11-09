# P2.5-DS-GALLERY — every `x-ss.*` component, alone, in four combinations

**Date:** 2026-11-09 · **Branch:** `claude/vibrant-bell-12tquy`

26 specimens × 4 combinations = **104 captures, all distinct**, covering every component in
`resources/views/components/ss/`.

The coverage inventory carries each one as a surface of its own, and that is right: each appears on
many screens, so a defect in one is a defect everywhere at once. A capture taken inside some host
page is evidence for **that page** — one instance, one state, one width.

---

## 1. A file, not a route

A gallery route would be production code: a URL that ships, needs a guard, and can be reached in an
environment nobody meant it to be reachable in.

`tests/Support/DesignSystem/bin/render-ds-gallery.php` goes through Laravel's own view factory —
the real Blade compiler, the real components, the real `public/css/store-syria-ds.css` — and writes
a standalone HTML file per combination. `shoot-ds-gallery.cjs` then screenshots **each specimen's
own bounding box**, so a component's capture is its own height, not a page crop.

`routes/web.php` is untouched. Nothing is registered. The application is unchanged.

---

## 2. The first run was 96 captures and 48 distinct images

Every single component rendered **identically in light and dark**. Read one way that is
twenty-four components ignoring the theme — a spectacular finding, and wrong.

It was one harness bug. The design system keys its dark block on **`[data-ss-theme="dark"]`**
(`store-syria-ds.css:560`); the renderer was setting `data-theme`, which means something else
entirely in this application. The dark captures were light ones.

**The only reason it was caught before anything was claimed** is that the distinct-capture count
was checked first. 96 files and 48 distinct is not a subtle signal, but nothing would have reported
it if the captures had simply been filed.

---

## 3. Specimens carry real content, and states are shown together

An empty specimen renders a box, and a box proves nothing about a component whose job is to present
text, a number, a status or a control. Where a component's point is that it has **states**, the
states are in one frame:

* `status-badge` — success, warning, danger and neutral together. A badge whose tones are
  indistinguishable is the defect, and no single-tone capture can show it.
* `switch` — on, off and disabled.
* `progress` — 25% and 80%.
* `chip` — the inert form and the interactive form with a count.

**Prop shapes were copied from real call sites after guessing failed.** `bar-chart` takes parallel
`labels`/`values` lists and a required `title` (`reports/order_chart_data.blade.php:27`), not a map;
`error-summary` takes a real `MessageBag`, because that is what a failed submit hands it;
`setup-checklist` takes a `steps` array. Each wrong guess surfaced as a hard failure from the real
Blade compiler rather than a quietly empty specimen, which is why the renderer exits non-zero on a
component that throws.

**`stat`/`stats` and `attention`/`attention-item` are separate inventory rows**, so each has its own
specimen rather than one picture doing duty for two surfaces: the tile alone, then the container
with three tiles showing the grid and its dividers.

---

## 3b. A phone-width set, and the six duplicate rows

The same galleries are also shot at **390px**, giving 208 captures in all and genuine responsive
evidence: `x-ss.stats` renders three tiles side by side at 760px and **stacked** at 390px, which
only a narrow render can show.

Six inventory rows — *Report period filter*, *Chart*, *Contextual help*, *Field help*, *Setup
checklist*, *Needs-attention block*, each marked "(shared component)" — name the **same artefacts**
as `x-ss.report-filter`, `x-ss.bar-chart`, `x-ss.help`, `x-ss.field-help`,
`x-ss.setup-checklist` and `x-ss.attention`. They sit in a different inventory section and carry
the same `partial/component` route. **The duplication is the inventory's.**

They are deliberately **not** claimed on the desktop captures, because that would make one picture
the evidence for two rows. They are claimed on the phone-width set instead — different files,
different renders, and a dimension the phase requires regardless.

## 4. What is claimed

Twenty-five component rows. The inventory lists `x-ss.bar-chart`, `x-ss.setup-checklist` and
`x-ss.progress` **twice** each; those are duplicate rows in the inventory itself, and resolving the
name resolves both.

Not claimed from this package: `x-datatable`, `custom.css F126 region`,
`Store Syria Design System stylesheet`, `DS theme hook + opt-in partial` and the legacy Tailwind
component set. The first is a table that needs real rows and a page around it; the rest are not
components at all — a stylesheet, a Blade include and a co-resident library — and none is evidenced
by a component photograph.

---

## 5. Reproducing

```bash
for c in "light ltr en" "dark ltr en" "light rtl ar" "dark rtl ar"; do
  DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php tests/Support/DesignSystem/bin/render-ds-gallery.php <dir> $c
done
node tests/Support/DesignSystem/bin/shoot-ds-gallery.cjs <dir>
```

No dev server is needed: the renderer does not boot HTTP, only the framework.
