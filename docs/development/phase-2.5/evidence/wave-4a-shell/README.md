# P2.5-WAVE-4a — the merchant shell: two defects, one refutation

**Date:** 2026-11-09 · **Branch:** `claude/vibrant-bell-12tquy`

The shell is the highest-reach surface in the product: `layouts/app.blade.php` is the layout for
all 334 screens that set `@section('page-title')`. Everything below was **measured by driving the
running admin**, not read out of `PHASE-2.5-REMAINING-AREAS-WORKLIST.md` — and that turned out to
matter, because the work-list's entries for this area are a mixture of live, stale and
already-overridden.

---

## 1. The sidebar search emptied the whole navigation and said nothing

**Measured on /dashboard**, a term that matches nothing:

| | items visible | zone headings visible | message |
|---|---:|---:|---|
| before | **0** of 13 | **0** of 5 | none |
| after | 0 of 13 | 0 of 5 | **`No menu item matches "zzzzzz"`** |

The sidebar went blank below the store switcher with nothing to distinguish a failed search from a
broken page. `partision/menu-zones.blade.php:31-38` already recorded that the zone headings hide
too, which is what makes it total rather than partial.

### Who actually reaches it — narrower than it looks, and worth recording

Clicking the search box opens the **command palette** (`store-syria-ds.js`), and `cmdOpen()` focuses
the palette's own input. So a **mouse** user clicking the box lands in the palette and never drives
this filter. That binding is deliberate and documented at length under P2-06-2: it is bound to
`click` and not `focus` because a focus binding took the keyboard user's focus into a dialog with
its own Tab cycle and left **72 of 75 controls unreachable** on every merchant screen.

A **keyboard** user does reach it. Tab produces no click, so focus lands on `#sideBarsearch` with
the palette closed and every keystroke runs the navigation filter. Verified by focusing the input
*without* a click: `paletteOpen: false`, sidebar emptied. Escape from the palette returns focus to
the same box and reaches it the same way.

**So the repair serves exactly the audience the palette's own redesign was written to protect.**

### Verified in Arabic, by dispatching the event the handler really binds

Synthetic Arabic keystrokes silently failed to register, which made an earlier reading of this look
like "the filter matches everything". Dispatching the `keyup` the handler binds removes the
keystroke mapping from the measurement:

```
الطلبات   ->   1 of 13 visible, the right one, message hidden
zzzzzz    ->   0 of 13 visible, message shown
(empty)   ->  13 of 13 visible, message hidden
```

### The echoed term is escaped

The message repeats the term, which is merchant-typed input going back onto the merchant's own
page. Written with `.text()`, never `.html()`. **Driven proof:** typing
`<img src=x onerror=alert(1)>` renders `"&lt;img src=x onerror=alert(1)&gt;"` and creates **zero**
`<img>` elements, in both locales.

`role="status"` with `aria-live="polite"` because the hiding happens in JavaScript with no page
change — without a live region a screen-reader user hears nothing at all and arrows into an empty
list. Polite, so it does not interrupt the character still being typed.

---

## 2. Five keyboard-focusable controls in the shell had no focus indicator

A census of every visible focusable element — focus it, keep the ones that really match
`:focus-visible`, read the computed `outline` and `box-shadow`
(`tools/census-focus-indicators.cjs`):

| screen | before | after |
|---|---|---|
| `/dashboard` | 23 class patterns **with** an indicator, 3 **without** — 5 controls | **26 with, 0 without** |
| `/order` | 28 with, 0 without | 28 with, 0 without — **nothing lost** |

The five were vendored Bootstrap `.btn` variants in the dashboard's profile card: *Quick add*,
*Share store link*, *Copy theme link* (two of them twice). A keyboard user tabbing the dashboard
lost the cursor on them entirely — WCAG 2.4.7.

**Why the rule lives in `custom.css` / `rtl-custom.css` and not in the design-system sheet.** None
of the five is inside `.ss-workspace` or any `.ss-scope` — measured, `closest('.ss-workspace')` is
null for all five — and `DesignSystemStylesheetScopeTest` requires every selector in
`store-syria-ds.css` to be confined by an `.ss-` class or a `data-ss-` attribute.

**Both sheets, byte-identical.** `rtl-custom.css` is loaded *instead of* `custom.css` on the Arabic
layout, never alongside it, so a fix written to one gives half the merchants nothing. That is the
P2.5-WAVE-16b lesson, and `ShellSearchAndFocusTest` asserts the block byte-for-byte.

`.ss-btn` carries no `.btn` class token, so the design system's own buttons are untouched — and a
CSS class selector matches a whole token, which is the thing this slice's own first test got wrong
(`\bbtn\b` matches the `btn` inside `ss-btn`, because a hyphen is a word boundary; a class selector
does not work that way). The rule was right and the test was wrong; it now compares class tokens.

---

## 3. What the work-list claimed here, and what the measurement refuted

Treating the work-list's rows as leads rather than defects was the right call: **three of its five
shell colour rows are dead**, and two more were already fixed.

| claim | measured | verdict |
|---|---|---|
| a pre-rebrand magenta glow `rgba(146,44,136,.3)` under every active and hovered sidebar item | the active item's computed `box-shadow` is `rgba(0,0,0,.45) 0 2px 4px, rgba(0,0,0,.4) 0 6px 16px` | **overridden — dead** |
| `#e9d5e7` on `.btn-light-primary` | computed `background-color: rgba(0,0,0,0)`, border `rgb(198,204,219)` | **overridden — dead** |
| `--bs-btn-focus-shadow-rgb: 146,44,136` paints a magenta focus ring | the property *is* set, but under genuine `:focus-visible` the computed `box-shadow` is `none` | **never painted — dead** |
| eight hard-coded `fill="#060606"` on the numbered pager | `layouts/global-pagination.blade.php` uses `fill="currentColor"` throughout; the `#060606` copy is `resources/views/vendor/pagination/global-pagination.blade.php`, which no first-party view references | **stale** |
| a U+1F4E2 emoji in the cookie-consent title, and dingbats in the command palette | a Unicode scan of both files returns nothing | **stale** |

They are left alone rather than churned. Removing dead declarations has a cost and no benefit, and
a diff that touches them invites the next reader to assume they mattered.

**One more claim I raised myself and then refuted.** An early capture showed the command palette
open over the dashboard while the sidebar filtered behind it, which looked like a modal appearing
on every keystroke. It is the documented `click` binding, and `cmdOpen()` focuses the palette input;
the earlier probe reported `visible: false` for the palette only because it tested
`offsetParent !== null`, which is **null for `position: fixed`** whether or not the element is
visible. Not a defect. Recorded because the first measurement was wrong and the second one is why.

---

## 4. Evidence and reproduction

* `before/` and `after/` — the sidebar at a non-matching term, `en` and `ar`, **keyboard path only**
  so the palette stays closed. Both pairs differ by content hash. The before set is the same tree
  with only the four touched files reverted, same server, same fixture.
* `tools/census-focus-indicators.cjs` — the focus census, both screens.
* `tools/capture-sidebar-search.cjs` — the capture, both locales, and it prints the state it
  measured so the picture and the number come from the same run.

```
docs/development/phase-2.5/tools/serve-dev.sh &
node docs/development/phase-2.5/tools/census-focus-indicators.cjs
node docs/development/phase-2.5/tools/capture-sidebar-search.cjs <output-dir>
```

**Never `export` the fixture's `DB_DATABASE` into a shell that then runs the test suite.**

## 5. Tests

`tests/Unit/Ui/ShellSearchAndFocusTest.php` — 11 tests. **Bite-proved:** reverting the four
production files fails **9 of the 11**; the two that still pass are the premise about the class-token
scan and the `ss-btn`/`btn` collision check, neither of which the repair changes.
