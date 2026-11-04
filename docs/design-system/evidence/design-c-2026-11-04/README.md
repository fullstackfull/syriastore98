# P2.5-DESIGN-C — the order grid card: a closed span, a money figure, and the date it never showed

**This is not the premium restructure of this card, and it should not be read as one.** It is the
correctness-and-scannability half, done first because it is safe. What it is *not* is set out in §5,
with the constraints the restructure will have to work inside — which are the real product of this
slice.

---

## 1. What was wrong

### The card showed no date at all

Both siblings do. The **list** has a `Date` column (`OrderDataTable::getColumns()`, rendered through
`dateOrDash($item->order_date)`, measured as `Nov 4, 2026`); the **board** card carries one. The grid
card read `<customer> / Price : <amount> / #<ref>` and nothing else — 16 cards a page, none of them
dateable.

### The `<span>` around the price was never closed

Confirmed in the **raw server bytes**, not only in the source. The HTTP response for one card:

```html
<span class="text-dark text-md"><b>Price : </b>


$10.00                        




                    </div>
```

No `</span>`. Chromium recovers, so the DOM is not mis-nested and nothing looks wrong — which is
exactly why it survived a whole phase. The cost was real all the same: `OrderGridStatusLabelTest::`
`test_the_badge_is_not_inside_the_unclosed_text_dark_span` **exists because of it**. Anything placed
after that span is auto-closed inside it and inherits the dark-mode override on `.text-dark`, so the
previous slice had to put the status badge before `.user-content` rather than wherever it read best.

### The money was a sentence, not a figure

`Price : $10.00` — a label, a colon and a value in one body-text run, with no wrapper and no class.
The **list has the same defect** (`OrderDataTable` renders `final_price` as bare text). Fixed here;
the list is recorded as its own slice rather than widened into this one.

---

## 2. What changed

| file | change |
|---|---|
| `resources/views/order/grid.blade.php` | the span is closed; the price sits in `.ss-num`; the date is added through `dateOrDash()` |
| `app/DataTables/OrderDataTable.php` | `dateOrDash()` made **public**, with its reason |

**`dateOrDash()` is reused rather than reimplemented, and that is the whole point.** The obvious way
to add a date to a Blade view is `Utility::dateFormat($order->order_date)` — and that reintroduces
**F19**, the epoch printed for a missing date, on a second screen. F19 was already found and fixed
once, on the list. One helper, one behaviour; `hasDate()` stays private because it is that method's
internal test, not a second public rule.

---

## 3. Verified

Four combinations, measured in Chromium at 1440×900:

```
en/ltr/light  cards=16  cardH=182  overflow=0   money="$10.00" tabular-nums bidi=isolate   date="Nov 4, 2026"
en/ltr/dark   cards=16  cardH=182  overflow=0   money="$10.00" tabular-nums bidi=isolate   date="Nov 4, 2026"
ar/rtl/light  cards=16  cardH=182  overflow=0   money="$10.00" tabular-nums bidi=isolate   date="Nov 4, 2026"
ar/rtl/dark   cards=16  cardH=182  overflow=0   money="$10.00" tabular-nums bidi=isolate   date="Nov 4, 2026"
```

`.user-content` now holds **three properly-closed sibling spans** where it held one that swallowed
everything after it. Card height 161 → 182px. Pixel change against the same four captures before:
**28.24% / 28.02% / 21.46% / 21.14%**.

Tests: **11 new** (8 source/helper ratchets + 3 driving the real route). Unit **1087 passed**,
order-related **1412 passed**. **The bite:** restoring the pre-change card reddens **6 of 11** — the
five `dateOrDash()` cases stay green, correctly, because they are about the helper and not the card.

---

## 4. A claim I checked and could not support

The recon reported the grid as an N+1: it does not eager-load `CustomerData` while the sibling board
does. Measured rather than repeated:

```
warm cache:   1 query total,  0 against `customers`     <- Customer is Cachable; proves nothing
cold cache:   4 queries,      1 against `customers`     (eager-loaded: 3 / 1)
fixture:      55 customer-bearing orders, 1 DISTINCT customer
```

**This fixture cannot demonstrate an N+1** — every order belongs to the same customer, so it is one
query either way. The missing `->with('CustomerData')` is a real divergence from the sibling and
worth closing, but **no cost has been measured here and none is claimed**; proving it needs a
multi-customer fixture. The eager-load is therefore left for the restructure slice, with that test.

---

## 5. What this slice did NOT do, and what the next one has to work inside

The card is still, structurally, what it was: **the customer is the title and the order is not**.
Still outstanding:

- the **order reference** is a filled `btn btn-primary btn-sm` in the footer — the card's identity
  wearing a primary action's clothes. (The **list does the same**, so this is a two-screen decision,
  not a grid quirk.)
- no **payment status**, no **source**, no **shipping method** — three fields the list shows.
- the customer's **phone** renders with no label and no `tel:` affordance.
- considerable **dead space**: a 182px card carrying four short lines.

**Fifteen test files constrain this one view** — four grid-specific, seven whole-tree ratchets, four
driving the route — and they pin *literal strings and exact counts in the Blade source*, so the
restructure is a slice of its own and not a tidy-up. The map, which is this slice's most useful
output:

| constraint | where it is enforced | what it costs to change |
|---|---|---|
| `<div class="card-header` and `<div class="card-body` are **slice markers** | `OrderGridStatusLabelTest::header()`, `OrderGridRowActionNamesTest::header()` | renaming either fails three tests with a *premise* error |
| `<div class="bottom-icons` → `{!! Form::close()` is a third region | `OrderGridRowActionNamesTest::actions()` | same |
| exactly **5** row actions, and the strip renders **no visible text** | `test_premise_the_card_still_has_five_icon_only_row_actions` | adding a labelled control there reddens it |
| `aria-label` must stay the **last** attribute on Delete, after `data-confirm` | `test_the_delete_buttons_aria_label_stays_after_data_confirm` | the label contains a `>`, so a regex in `ConfirmationDialogContractTest` stops mid-attribute and reports a **false** failure in another file |
| the two return controls must be **direct siblings** inside `.bottom-icons` | `test_the_return_controls_gain_no_wrapper_element` | the live jQuery uses `.parent().find('.return_request')`; a wrapper leaves the buttons on screen after success |
| `substr_count($grid, 'href="#" class="btn btn-sm btn-') === 2` | `OrderGridStatusLabelTest` | re-classing either return link reddens it |
| the first `@php` block is **`eval()`ed** and must stay free of `__(`, `new`, `function`, `static`, `::` | `test_every_status_the_host_can_store_gets_its_own_correct_label` | moving the status mapping into a helper breaks the test's mechanism, not just its needle |
| 37 distinct `__()` keys must exist in **both** catalogues | the whole-tree Arabic ratchet | every new string needs `en.json` **and** `ar.json` in the same commit |
