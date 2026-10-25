# P2-03-1 — product list column set and row-action reachability

Eight screenshots of the product list, Arabic (RTL) and English (LTR) × light and dark × 1440 and
390, taken against the running application by
`docs/development/phase-2/tools/shoot-product-list-live.cjs`. Invented fixture
data only (`database/seed-p2dev.php`): no real customer, order or credential appears in any of
them.

## What to look for

**The ACTION column at the trailing edge in every shot** — the right in English, the left in
Arabic — with a one-pixel separator where the rest of the row scrolls under it, and with the same
background as the card it sits on. The dark shots are the ones worth checking: the design system's
dark surface is `#263331` and the host paints this card `#292a33`, so a pinned cell taking the
token would read as a band of the wrong grey down the table. It does not.

**The column-visibility button** in the table toolbar (the columns icon). It was already in the
bundle on every page and rendering nothing.

**At 390px**, the name column and the actions are both visible with the table at its starting
scroll position. That is the whole slice.

## The numbers behind them

| configuration | table | wrapper | hidden behind the scroll |
|---|---|---|---|
| en / 1440 | 1250px | 1095px | 155px |
| en / 390  | 1250px |  320px | 930px |
| ar / 1440 | 1168px | 1095px |  73px |
| ar / 390  | 1168px |  320px | 848px |

The table is that same width at 1440, 1280, 992, 768 and 390 — it does not respond. The document
does **not** overflow at any of them; `.table-responsive` absorbs it, which is why this cost shows
up as unreachable actions rather than as a page-level scrollbar.

With `ss-col-pinned` removed, a real click on Edit at scroll position 0 navigated **nowhere in all
eight configurations**, 1440 included. That is the before; these shots are the after.

## What these do not show

Row density. Rows are 55px at every viewport and nothing here changes that — see ledger P2-03-10.
