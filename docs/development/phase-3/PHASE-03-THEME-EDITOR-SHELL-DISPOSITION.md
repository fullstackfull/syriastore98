# The theme editor shell — what was closed, what remains, and the one ruling it needs

`GAP-098` (`P03-B-02`), `GAP-111` (`P03-E-01`), `GAP-113` (`P03-E-03`) · measured 2026-11-26

`GAP-098` offers two branches: *"either move the editor shell onto the design system and capture it
in four combinations with contrast checked, or record the shell as explicitly out of scope with an
owner decision — and correct the M-document that overstates it."* This document takes neither
branch wholesale, because the measurements do not support either as a single answer. It records
what was closed with evidence, what remains with a number against it, and the one question that is
genuinely the owner's.

---

## 1. Closed, with rendered evidence

**`GAP-113` is closed.** The row said the editor *"has zero rendered evidence, authenticated or
otherwise, in any language, direction or appearance"*. It now has 16 cells: four screens ×
light-en, dark-en, light-ar, dark-ar, driven as an authenticated merchant,
`APP_DEBUG=false`, **0 premise failures**.

| screen | route |
|---|---|
| index | `/theme-customize` |
| pages | `/theme-customize/stylique/pages` |
| section editor (+ version panel) | `/theme-customize/stylique/page/header` |
| order screen | `/theme-customize/stylique/pages/order/home` |

The capture asserts **the landed path and the `h1`**, which is what `GAP-113` asks for and what the
earlier evidence lacked: the four `_theme_customize__*.png` in
`docs/development/phase-2.5/evidence/web-blocked-2026-11-10/after/` are pictures of the
**dashboard**, each row carrying `h1` "Dashboard" against a request for `/theme-customize`.
`--forbid-h1 Dashboard,<its Arabic>` was never triggered in any of the 16 cells; the headings read
*Themes* / *Theme Customize* in English and *الموضوعات* / *تخصيص المواضيع* in Arabic.

**`GAP-098`'s contrast obligation and `GAP-111` are closed.** 14 findings in 5 groups → **2**, and
the 2 are the excluded `D-25` shared-sidebar item. The repaired elements, measured pass-or-fail on
the element that carries the text:

| element | before | after (light / dark) |
|---|---|---|
| draft badge | 1.68:1 light | 8.83:1 / 9.81:1 |
| live badge | passed | 7.10:1 / 8.79:1 |
| Publish draft | 2.58:1 dark | 6.42:1 / 7.92:1 |
| Preview draft | 3.04:1 dark | 16.88:1 / 11.81:1 |

Plus the pushed `<style>` block's light-only literals, measured through the real cascade in all four
combinations: `--ss-surface-hover` `#f6f7f8`→`#2d323b`, `--ss-border` `#dddfe3`→`#363b44`,
`--ss-shadow-md` `rgba(11,31,28,.06)`→`rgba(0,0,0,.45)`, and `[data-repeater-item]`'s computed
`border-color` `rgb(221,223,227)`→`rgb(54,59,68)`.

`store-syria-ds.css` is **byte-unchanged**: every class used already existed.

---

## 2. The M-document that overstates it — the correction, and it is a numbering collision

`GAP-098` says the shell redesign *"named in M4-7"* was not done while the M4 report presents M4 as
delivered. Measured, both documents in hand:

| | `PHASE-03-M4-THEME-EDITOR-PLAN.md` | `PHASE-03-FINAL-REPORT.md` §2 |
|---|---|---|
| **M4-7** | *"The editor shell (UI)"* (`:266`) | *"added a **third boundary**, `theme.draft` (membership), beside `theme.editor`"* |

**They are two different pieces of work under one number.** The report's M4-7 bullet is an
authorization boundary; the plan's M4-7 is the shell. A reader matching the plan's ten packages
against the report's bullets sees M4-7 accounted for and concludes the shell shipped — which is
exactly the overstatement the row records.

Measured further: the report **never mentions the editor shell, the design system in connection
with the editor, a four-combination capture of the editor, or its contrast**. Its "four
combinations" (`:21`) is M7's storefront browser matrix, its 13.73:1 (`:106`) is a storefront
figure, and its design-system paragraph (`:224-226`) is about Arabic page weight.

The report is corrected in place with a note recording the collision, rather than by renumbering —
a delivery report is evidence of what was done and editing its bullets to match a plan it did not
follow would destroy that.

**The plan is deliberately left unedited.** Its own M4-9 block quote states the rule: *"a planning
record that gets edited to match the present cannot be used as evidence of anything."* The three
stale claims below are recorded here instead.

---

## 3. What remains, with a number against it

Design-system share of the shell's `class` attributes, comment-stripped, measured from git:

| | class tokens | `ss-` tokens | share |
|---|---|---|---|
| before this slice (`HEAD~1`) | 546 | 29 | 5.3% |
| after | 544 | **46** | **8.5%** |

So **roughly 498 class tokens across five views are still the host's Bootstrap.** Per view, after
the repair:

| view | class tokens | `ss-` | distinct host component classes |
|---|---|---|---|
| `index.blade.php` | 46 | 4 | 20 |
| `pages.blade.php` | 60 | 3 | 16 |
| `customize.blade.php` | **301** | 8 | **34** |
| `order.blade.php` | 79 | 19 | 21 |
| `_version-panel.blade.php` | 58 | 12 | 21 |

Design-system **components**, as opposed to classes: the shell uses exactly **one** — `x-ss.empty`,
in `_version-panel.blade.php`. No `x-ss.panel`, no `x-ss.confirm-dialog`, no `x-ss.advanced-fields`,
no `x-ss.error-summary`.

### Three of the plan's M4-7 premises are now stale, and two of them make the remainder *cheaper*

1. **"`.ss-tabs`/`.ss-tab` have zero consumers and no behaviour (verified exit 1)"** — stale.
   Measured today, ten views carry `class="…ss-tab…"`, including `platform/content/index`,
   `platform/docs/editorial`, three `setting/*` panels, three `finance/*` screens and
   `order/order_show`; `SettingsTabRailKeyboardTest` exists and pins a tab rail's keyboard story.
2. **"a tabbed shell means writing the roving-tabindex implementation"** — *does not apply at all*.
   Measured: `nav-tabs` 0, `role="tab"` 0, `class="modal…"` 0 across all five shell views. **The
   shell as built has no tabs and no modals.** The plan's single largest named cost for this
   package is not a cost of this shell.
3. **"dirty tracking is opt-in and clears on the `submit` event, which an AJAX editor never
   dispatches"** — half stale: the opt-in exists and has two consumers (`product/create`,
   `product/edit` via `data-ss-dirty-track`). Whether the editor's save path dispatches `submit` is
   **not** measured here and remains open.

### What the remainder actually is

`customize.blade.php` is 301 of the 544 tokens and 34 of the distinct host classes — and it is not
a hand-written page. It renders **23 `form-control` fields** generated from the theme's own JSON
(the plan's M4-8 counts 122 field labels, 73 section titles and 29 page titles across three
themes). Converting its form controls is therefore a change to **how every theme's settings render,
for three themes**, not a re-skin of one view.

---

## 4. The one question that is the owner's

Everything measured as a **legibility failure** on this surface is now closed, in all four
combinations, with the evidence above. What is left is **visual-language alignment**: 498 class
tokens, one `x-ss.*` component, and a 301-token generated form at the centre of it.

> **The ruling needed:** should `customize.blade.php`'s generated form controls move onto the
> design system — which changes how all three themes' settings render for every merchant — or does
> the shell stay on the host's Bootstrap now that its measured contrast failures are closed?

Three things make this a decision rather than engineering:

1. **It is not an accessibility repair.** Every AA failure the 16-cell capture found is closed. A
   further conversion would close **no measured failure**, and this project has the record for what
   that produces: 23 of `GAP-179` slice 1's 30 conversions closed nothing measurable, and
   `setting/index.blade.php:283` produced byte-identical captures.
2. **It is merchant-visible across three themes at once**, through generated markup, which is the
   class of change `Phase 2.5` was created for and which the owner reserves.
3. **The plan's own cost estimate for it is wrong in the cheap direction.** Whoever rules on this
   should know the tab work the plan budgeted does not exist here (§3), so a "too expensive" answer
   given on the plan's figures would be given on stale ones.

**Recommendation, stated as a recommendation.** Close `GAP-113` and `GAP-111`; keep `GAP-098` open
on its *second* branch only — the design-system conversion of the generated form — and record the
contrast half as done. Declaring the whole shell out of scope would be the wrong record now that
8.5% of it is converted and its contrast is clean; declaring it *in* scope without this ruling
would start a three-theme visual change on a slice's own authority.

---

## 5. How to reproduce every number here

```sh
# the dev fixture, the editor's own prerequisites, and APP_DEBUG=false in .env
php artisan tinker --execute="require 'tests/Support/bin/grant-dev-lynomia-merchant.php';"   # the pattern
#   plus: Manage Themes on the merchant's role, and one published + one draft theme_versions row
#   (the version panel is @includeWhen(isset($liveVersion) || isset($latestDraft)))

node tests/Support/bin/measure-lynomia-merchant-live.cjs --base http://127.0.0.1:<port> \
  --surfaces /theme-customize,/theme-customize/stylique/pages,/theme-customize/stylique/page/header,/theme-customize/stylique/pages/order/home \
  --forbid-h1 Dashboard,لوحة

php artisan test tests/Unit/Ui/ThemeEditorShellLegibilityTest.php
```

**Measure with `APP_DEBUG=false`.** With debug on, the PHP Debugbar injects
`span.phpdebugbar-badge` at 3.89:1 and the text walk counts it as product markup — **8 spurious
findings per surface** across four combinations. The harness now excludes `.phpdebugbar` by
ancestor and prints how many elements it skipped, so a run made with debug on says so.

**A note on one of this document's own figures.** The 546→544 / 29→46 comparison is read from
`git show HEAD~1:` and `git show HEAD:` rather than from the working tree, because the first
reading of it was taken *after* the repair and reported 46/544 as the *before* state. A census of a
file you have just edited measures the edit, not the baseline.
