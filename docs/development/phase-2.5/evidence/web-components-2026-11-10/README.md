# Phase 2.5 — web component rows, clipped to their own box (2026-11-10)

Ten surfaces × 4 combinations. Each is a **component** row in the inventory, not a page, so the
harness clips the container's own bounding box — a capture of the host screen would be evidence
for the host, which is the overclaim the matrix forbids.

| slug | surface |
|---|---|
| `x-datatable__` | the DataTable component on `/product`, its richest host (11 columns) |
| `product-filter-bar__` / `-applied__` | the product filter bar, empty and with filters applied |
| `order-filter-bar__` | the order filter bar, filters applied |
| `order-saved-views__` | the saved-view chips and the save box |
| `customer-status-toggle__` / `-off__` / `customer-status-guest__` | the status cell's three arms |
| `variant-delete-dialog__` | the variant delete confirmation, open |
| `attribute-row-actions__` | the attribute list's action cell |

## The status switch failed WCAG 1.4.11 in one state per theme — FIXED

**Read off the running page, not off the picture.** A 1px border is a few hundredths of a
control's pixels, so a sampler reporting the *dominant* colour never sees it. That is not
hypothetical: after the repair, the pixel sampler still reported the checked track at 2.22:1,
because the fill had not changed — only the border had.

| | before | after |
|---|---|---|
| light, **unchecked** | white fill on a white cell = 1.00:1, leaving only the border → `#BFBFBF` **1.84:1** | border `#6B6F76` **5.05:1** |
| dark, **checked** | `#335DA8` on cell `#292A33` **2.22:1** | border `#68CBE1` **7.61:1** |
| states apart | 6.42:1 | **6.42:1 — unchanged** |

**The obvious fix was measured and rejected.** Re-colouring the checked fill to the dark brand
(`--ss-cyan-500`, 6.81:1 on the cell) fixes the boundary and *wrecks* the state difference: cyan
against the white unchecked track is **2.09:1**, worse than today. Fill carries state, border
carries boundary; only the border was changed, and only inside `.ss-dt-card-body` — the scope
where it was measured.

Ratchet: `tools/measure-switch-boundary-live.cjs`. It asserts both properties, because a "fix"
that made both states equally invisible would otherwise pass the first one. Proved in both
directions: **4 problems** with the CSS reverted, **0** with it applied.

## Four harness corrections, each from a finding it produced

| symptom | what it really was |
|---|---|
| `landed on /order, asked for /order?ss_status=1&ss_apply=1` ×8 | the premise compared the landed **path** against a route carrying a **query string**. Several surfaces need one to exist at all — the filter bars only render their summary receipt, Clear control and save box when a filter is applied. Now compares paths. |
| `43px past the container` ×56 on `x-datatable` | the card scrolls horizontally and its rows slide under the pinned action column — the design. Overflow is now reported only when no ancestor scrolls in that axis. A **narrowing**, not a relaxation: a non-scrolling container still reports at 4px. 56 false findings per run is how a real one stops being read. |
| `no visible trigger matching a.delete-option-comment (0 matched)` | the saved-variant panel fetches itself on document-ready, so the trigger is injected, not served. The harness now waits for it. |
| `container holds 5 chars of text (fragment never arrived)` | a status cell is a switch and a badge — a toggle has no text to count. Arrival is now 20 characters **or** a visible control, and a surface that knows it is a word-long badge declares its own `minText` rather than the floor being lowered for everyone. |

## Fixture

`seed-dev-saved-views.php` seeds two saved views so the chip group renders — with `saved_views`
empty the strip is just the save box, 32 characters, and claiming the row off that would be
evidence for half a surface. `SavedView` is one of the sixteen models that are *not* `Cachable`,
so the raw insert needs no flush; ownership (`store_id`, `created_by`) is set explicitly rather
than mass-assigned, as the model's deliberately narrow `$fillable` requires.
