# P2.5-PKG-M — ORDERS: what the before-captures show

**Status: survey only. No code has been changed for this package.** Twelve captures, three screens
× four language/theme combinations at 1440, taken with
`docs/development/phase-2.5/tools/render-matrix.cjs` — **12 rows, 0 premise failures**, every one
signed in on the right screen in the right direction and theme.

The area: **ORDERS — 17 surfaces, 3 REDESIGNED AND VERIFIED, 3 BLOCKED, 11 PENDING.** The three
screens below are the merchant's three ways of looking at the same orders, and they are the
natural first slice.

| screen | route | title (en / ar) |
|---|---|---|
| Order list (table) | `GET /order` | `Order` / `الطلب` |
| Order grid (cards) | `GET /order-grid` | `Order` / `الطلب` |
| Order board (Kanban) | `GET /order-kanban` | `Order board` / `لوحة الطلبات` |

## Findings, each from a capture rather than from reading code

### F-M-01 — two different screens share one name, and the name is singular
`/order` and `/order-grid` both title themselves `Order` / `الطلب`. They are different screens,
so a merchant cannot tell from the title which one they are on, and the browser tab says the same
thing for both. **And it is singular**: the sidebar says `الطلبات` (plural) while the page says
`الطلب` ("the order") — the same disagreement P2.5-PKG-L fixed between `العميل` and `الزبائن`.

### F-M-02 — `mm/dd/yyyy` is shown to a Syrian merchant
Both date inputs on the Kanban filter bar render the browser's US placeholder. The Arabic page
shows a US month-first format with Latin digits.

### F-M-03 — the date pair is split across rows
`من` ("from") sits in the filter grid's last column; `إلى` ("to") wraps to its own row beneath,
orphaned from the field it labels, and the apply button lands beside it rather than at the end of
the row. The two halves of one range read as two unrelated controls.

### F-M-04 — a status is untranslated on the cards
Most cards show `مقبوض`; at least one shows the raw English `pending`. A status that is sometimes
translated and sometimes not is worse than one that never is, because the merchant cannot tell
whether `pending` is a different state from `معلق`.

### F-M-05 — `#` lands on the wrong side of the order number in RTL
Order ids render as `ORD-2043#` in Arabic. The `#` is a prefix in the source; without a `<bdi>` or
an isolating wrapper the bidi algorithm moves it to the visual end.

### F-M-06 — two controls per card, on every card
Every Kanban card carries a `نقل إلى…` select **and** a `نقل` button. With 60 orders that is 120
controls on one screen, each of which can move an order to another status. The board's own
drag-and-drop presumably does the same thing.

## What this is not

* Not a defect list to fix blind. The owner's instruction is that the worklist is "leads requiring
  fresh verification". Each of the six above is from a capture taken today, but none has been
  re-checked at 390/320, none has had its cause traced, and F-M-06 may be a deliberate
  accessibility affordance for people who cannot drag.
* Not a plan. The slice boundaries come after the causes are known.

## Captures

`render-matrix.cjs --paths /order,/order-grid,/order-kanban --width 1440` — twelve images, kept
out of the repository until this package has an after-set to compare them against.
