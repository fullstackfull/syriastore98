# P2.5-PKG-G Slice 3 — the status write tells the truth

Captured from the **running merchant admin** by `render-matrix.cjs`. **8 rows, 0 not clean**, every
row asserting its own premise.

`BEFORE-AFTER.png` crops each panel to where the Status column actually sits — far right in LTR,
left in RTL.

---

## The structural consequence this slice closes

The package thesis names three. This is the second: **no state other than "it worked" was ever
drawn in this area.**

| measured | |
|---|---|
| `error:` arms against `success:` arms across the two views | **0 against 4** |
| `$('#loader').fadeOut()` calls, against an element anything ever shows | **4 against 0** |
| Status column cells rendered for a guest, or for a viewer without the permission | **nothing — not a dash, not a word** |

## The switch showed the opposite of the database

`CustomerController::customerStatus()` answers real refusals — **403 twice and 404 twice**, each
with a translated `error` body — and jQuery routes every one of them to an `error:` callback that
did not exist. The body was never read, no toast fired, and because the browser's native click had
already flipped the checkbox, **the switch sat where the merchant dragged it while `customers.status`
was unchanged**.

This project had already written the rule down for itself, at `store-syria-ds.js:1937-2012`:

> THE SERVER DECIDES, THE BUTTON REFLECTS … a refusal leaves the control showing the truth instead
> of a state the server rejected.

This switch was that rule's counter-example. Both call sites now restore the pre-click value and
toast the handler's own reason (`Permission denied.` / `Customer not found.`, already translated),
falling back to a generic line only when the response carries none. The falsy-`success` branch
reverts too — it used to toast and leave the switch moved.

## A loader that was never shown

`#loader` ships `style="display: none;"` at `layouts/app.blade.php:749` and **nothing in the
codebase ever shows it** — no `ajaxStart`, no `.show()`, no `.fadeIn()`. The four
`$('#loader').fadeOut()` calls were fading out an element that was never visible. They are replaced
with state scoped to the control that is actually waiting: `disabled` + `aria-busy` on the switch
for its own write, and on the Apply button for the metadata round trip —
`.ss-btn[aria-busy="true"]` already carries the spinner. A full-page curtain for a one-row toggle is
the wrong weight.

## The failure the filter bar could not report

If the field-change request fails, the operator and value controls stayed hidden for ever with
nothing saying why — the merchant picks a field and the bar simply never finishes. There is now an
`error:` arm writing a translated retry line into Slice 1's hint element, rather than a toast,
because the failure is scoped to the bar and a page-level toast would outlive it.

## The Status column printed a header over nothing

`status.blade.php` wrapped its switch in two conditions with **no else**, so the cell was literally
empty for two populous cases while `CustomerDataTable` still printed a `Status` header above them:

* **A guest.** A null `regiester_date` is how this codebase models one — `ReportController` queries
  `regiester_date = NULL` under the name `$new_guest`, and `PaymentController::createCustomer()`
  writes the column only for a registered customer. `CustomerDataTable::query()` applies no
  `regiester_date` predicate, so guests really do appear in the merchant's list.
* **A viewer without `Status Customer`.** `@permission` compiles to Laratrust's `hasPermission()`,
  which has **no super-admin bypass** — so the platform operator, whom `customerStatus()` itself
  admits through its own operator-first check, saw an empty column on every row.

Each now renders a read-only `x-ss.status-badge`. The state is shown; only the ability to change it
is withheld, which is what the permission actually governs.

## How the guest row was made visible

All three fixture customers are registered, so the guest branch could not be photographed against
the stock fixture. `docs/development/phase-2.5/tools/seed-dev-guest-customer-fixture.php` adds one
— idempotent, tagged `p25guest-`, reversible with `P25_REMOVE=1`, written through Eloquent because
`Customer` is `Cachable` and scoped. It follows `seed-dev-customer-filter-fixtures.php`, which
exists for exactly this reason: *the only honest evidence is a before/after where the answer
visibly changes.*

**The fixture was reversed after the capture** and verified back at its three original customers.
