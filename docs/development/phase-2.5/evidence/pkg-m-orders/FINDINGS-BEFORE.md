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

## At 390px — twelve more captures, 0 premise failures

The table becomes stacked label/value rows, which is the phone pattern this project already has.
Three of the six findings above survive the width, and two more appear that 1440 did not show.

### F-M-05 confirmed
`#P25CUSTFILTER-2-2` still renders as `P25CUSTFILTER-2-2#`. Not a desktop-only artefact.

### F-M-07 — the dates are not localised at all, not just the placeholders
Rows read `Nov 6, 2026` on the Arabic page: a Latin month abbreviation in a right-to-left line.
F-M-02 was the browser's `mm/dd/yyyy` *placeholder*; this is the rendered value, and it is the same
defect P2.5-PKG-L fixed on the customer timeline with `App\Support\TimelineMoment` — `format()`
rather than `translatedFormat()`. **The helper already exists**, so this is a reuse rather than a
new mechanism.

### F-M-08 — a status chip with a date inside it
`حالة الطلب` renders `معلق : Nov 6, 2026` — an Arabic status, a Latin colon and a Latin date inside
one coloured chip. Two facts in one control, in two scripts, with punctuation from a third
convention.

### F-M-09 — four unlabelled icon buttons in the action row
Delete, edit, view and one more, at phone size, with no visible text. This project has an
established `aria-label` pattern for exactly this (P2.5-PKG-I's action cells); whether these four
carry one has not been checked yet.

### Not a finding, worth recording: `نقداً عند التسليم`
The order list **does** translate the payment type. The customer timeline did not until PKG-L, and
the two go through different code paths — so the fix there was not redundant, and this screen is
evidence that the translation exists to be reached.

## What this is not

* Not a defect list to fix blind. The owner's instruction is that the worklist is "leads requiring
  fresh verification". Each of the six above is from a capture taken today, but none has been
  re-checked at 390/320, none has had its cause traced, and F-M-06 may be a deliberate
  accessibility affordance for people who cannot drag.
* Not a plan. The slice boundaries come after the causes are known.

## Captures

`render-matrix.cjs --paths /order,/order-grid,/order-kanban --width 1440` — twelve images, kept
out of the repository until this package has an after-set to compare them against.
