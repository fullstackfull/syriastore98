# P2.5-R-06 / R-07 — the customer filter evidence

**Captured 2026-11-07.** Four faults, two screens, four locale/theme combinations, with a real
before/after rather than an after and a description.

## Why these four cases, and not any four

The faults were **wrong-answer** faults, so a capture is only evidence if the answer visibly
changes. Each case below is chosen so the correct set and the faulty set are **different sizes**.

The fixture is `database/seed-p2dev.php` plus
`docs/development/phase-2.5/tools/seed-dev-customer-filter-fixtures.php`:

| customer | orders | spend | AOV |
|---|---:|---:|---:|
| Layla Haddad | 55 | 550.00 | 10.00 |
| Omar Khalil | 2 | 300.00 | 150.00 |
| Nour Saleh | 0 | 0.00 | *none — no orders, so no average* |

**The second customer with orders is there for a reason.** `seed-p2dev.php` alone gives store 1
exactly one customer with any orders, and with one computable AOV in the store the AOV `'Equal'`
fault is **invisible**: the assignment bug matched "every customer with a computable average", and
that set and the correct set are both `{Layla}`. The screen looks right while the code is wrong,
which is the trap this phase keeps recording. `seed-dev-customer-filter-fixtures.php` adds two
tagged orders to Omar so the two sets differ. It is idempotent, tagged `P25CUSTFILTER-`, and
reversible with `P25_REMOVE=1`.

## The four cases, and what each screen did before

| fault | filter | correct | **list** before | **grid** before |
|---|---|---|---|---|
| 1 — `'Equal'` was an assignment | `AOV Equal 150` | 1 · Omar | **2** — Omar *and* Layla | **3** — all |
| 2 — duplicated `'Less Than'` testing `>` | `No. of Orders Less Than 3` | 2 · Omar, Nour | **3** — Layla (55) pulled into a less-than filter | **3** — all |
| 3 — no `'More Than'` branch at all | `No. of Orders More Than 2` | 1 · Layla | **0** — the localised empty state | **3** — all |
| 4 — `empty("0")` discarded the threshold | `Total Spend Equal 0` | 1 · Nour | **3** — no filter ran | **3** — all |

**The two screens failed differently, and that difference is the finding.** The list's inline
filter *ran* and returned a wrong set per fault. The grid returned **all three cards for every
filter**, because `customer_grid_view()` read no parameters at all — its filter did not answer
wrongly, it did nothing.

## How the before captures were made

Not reconstructed and not simulated. The four files were checked out from the pre-fix commit:

```
git checkout 7ba0af90 -- app/DataTables/CustomerDataTable.php \
  app/Http/Controllers/CustomerController.php \
  resources/views/customer/grid.blade.php resources/views/customer/index.blade.php
```

…the compiled Blade views were invalidated, the probe was run in `EXPECT=before` (capture-only, no
assertions), and the tree was then restored with `git checkout HEAD -- …`. The counts printed in
that run are reproduced in the table above.

## One collision the CI guard caught, which is corroboration rather than a problem

`check-distinct-surface-captures.py` initially failed on four pairs: in the **before** directory,
`grid-orders-less-than-3_*` and `grid-orders-more-than-2_*` were **byte-identical** in all four
combinations.

They were identical for the reason the repair exists. The pre-fix grid ignored the filter, so both
requests drew the same three cards; both cases select the same field, so the toolbar rendered
identically; and the pre-fix view had no restoration block, so the operator and value controls
stayed empty on a filtered page. Two different questions, one pixel-identical answer.

The captures were therefore **renamed to claim what they actually show** —
`grid-nofilter-<field>-field_<combo>.png` — and the duplicate removed. Three field selections,
three distinct images, no capture claiming a surface it does not show. The guard then passes.

## What is in here

```
before/   list-*  and grid-*   the pre-fix tree, captured, no assertions
after/    list-*  and grid-*   the current tree, every assertion below passing
```

Naming is `<surface>-<case>_<combo>.png` with `<combo>` one of `en_ltr_light`, `en_ltr_dark`,
`ar_rtl_light`, `ar_rtl_dark` — the suffix the distinct-capture guard parses.

## The assertions behind the `after` set

`docs/development/phase-2.5/tools/verify-customer-grid-filter-live.cjs`, run twice
(`SURFACE=grid`, `SURFACE=list`). Both reported **ALL CHECKS PASSED**. Per combination it asserts:

- **premises first** — `document.dir` really is the direction asked for, and `data-ss-theme` really
  is the theme. A capture run whose premises are not asserted is a capture run that can photograph
  the wrong thing for four hours.
- the unfiltered screen draws **3** items.
- each of the four filters narrows to the **exact expected count**.
- **on the grid, which customers remain, by name.** A count is not enough: two cards could be the
  right two or the wrong two.
- the field select on the reloaded page **shows the filter in force** — the other half of a GET
  filter, and the thing that lets a merchant edit a filter instead of rebuilding it.
- **Reset returns the full set.** A filter you cannot leave is its own defect.

**The list is asserted by count and screenshot, not by name, and that is deliberate.** Its identity
cell is built by string concatenation with a nested `<a>` and a `<br>` — `CustomerDataTable:65-69`
escapes each value individually rather than the finished cell precisely because of that markup — so
a text scrape of it is not a reliable identity. The list's name-level assertions are exact in
`tests/Feature/Merchant/CustomerNumericFilterOperatorsTest.php`, which compares the full
`first_name` set for every operator on both call sites.

## What this evidence does NOT claim

- **It is not a claim that the customer grid is "premium".** It is `REDESIGNED AND VERIFIED`: the
  filter toolbar was replaced and the result was driven and captured. The *cards* were redesigned
  earlier by P2.5-PKG-C and are not re-claimed here.
- **It says nothing about the other 230 PENDING surfaces.**
- A clean distinct-capture result does not prove any single capture shows the right screen — a lone
  impostor has no second file to collide with. That is why the premises above are asserted in the
  probe itself.
