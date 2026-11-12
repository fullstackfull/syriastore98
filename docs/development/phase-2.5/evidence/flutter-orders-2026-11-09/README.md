# P2.5-FLUTTER-ORDERS — the Flutter orders list, and two harness gaps it exposed

**Date:** 2026-11-09 · **Branch (both repositories):** `claude/vibrant-bell-12tquy`
**Flutter commit:** `a50a914`

The screen this phase opened on. The ORDERS audit that produced the twelve findings fixed earlier
in this session was done against the **merchant web** list; the Flutter half of the same surface
had no captures at all, because until the screen harness existed no Flutter screen could be driven.

Four combinations, two real orders, by `test/screens/order_screen_capture_test.dart`.

---

## 1. Two things the captures refuted

**Neither was a defect.** Both were a wrong belief that a capture corrected, which is the argument
for looking at them.

### The list does not de-duplicate within a response

`getOrderData` builds `existingIds` from the list **once, before the loop**
(`order_controller.dart:471-475`), then appends every item whose id is not in that set. Read
quickly that looks like "no duplicates ever". It is not: two rows sharing an id inside one response
both enter the list, and a test asserting otherwise failed for exactly that reason.

What the guard really protects is the case that actually happens — an order placed between page 1
and page 2 shifts the server's offset so page 2 repeats a row from page 1. The test now drives that:
page 1 declares `last_page: 2`, the second page repeats order 1042 and adds 1043, and the list must
hold three rows with 1042 appearing once.

### The card was right about the status and the fixture was wrong

The first capture showed an order badged **"Cancelled"** although its fixture carried
`delivered_status_string: 'Shipped'`. The card reads the **integer**, not the display string, and
`2` is Cancel.

Checked against the server rather than assumed — `Order::getDeliveredStatusStringAttribute()`
(`app/Models/Order.php:191`):

| int | server | Flutter (`order_status_badge.dart:163`) |
|---:|---|---|
| 0 | Pending | `pending` |
| 1 | Delivered | `delivered` |
| 2 | Cancel | `cancelled` |
| 3 | Return *(with `return_status` sub-states)* | `return` |
| 4 | Confirmed | `confirmed` |
| 5 | Picked Up | `picked_up` |
| 6 | Shipped | `shipped` |

**They agree**, and `test/widgets/order_status_label_test.dart` already pins the Flutter half, so no
new test was added. The fixture was corrected so the evidence is coherent: SS-1041 is Shipped (6),
SS-1042 is Pending (0).

Two divergences are noted and **not** claimed as defects, because neither is reachable from this
screen's data and chasing them would widen the phase: the server has no branch for 7 or 8 (Flutter
shows `partially_paid` / `pre_order`, the server falls through to Pending), and the server
distinguishes three return sub-states where Flutter says only `return`.

---

## 2. Two harness gaps, both of which failed silently

Driving the list past its first page is what found them. Both are now fixed in
`test/support/screen_harness.dart` with regression tests in `screen_harness_test.dart`.

**The stub is a ZONE, and it ends when `pumpScreen` returns.** `http.runWithClient` is `runZoned`
with a zone value, so anything the test drives afterwards — a filter tap, a second page, a
pull-to-refresh, a controller method called directly — runs in the original zone and reaches the
real client. The request never lands in `api.calls`, so a `pumpUntil` waiting on it reports that
*the screen* never made the call. That is a wrong diagnosis handed to the next person, so
`runWithApi(api, body)` now exists and the measurement is a test.

**`ApiStub.calls` dropped the query string.** It recorded `url.path` while `on()` matched against
the full URL — so a rule keyed on `page=2` answered correctly while `sawRequestFor('page=2')` was
false: the stub working and the assertion claiming the request had never happened. Everything that
distinguishes one call to an endpoint from another lives in the query.

---

## 3. What the captures show

`after/` holds **4 PNGs** at 360×800 with the app's real fonts, all four distinct. Each card carries
the order reference, the customer name, the total with the order's own currency symbol, the payment
badge and the status badge.

Note that this screen takes its currency **from the order** (`order.currencySymbol`,
`order_screen.dart:607`), which is why it does not have the gap recorded for the tax list in
`StoreSyria_app98/docs/design/TAX-CURRENCY-SYMBOL.md`.

Reproduce with:

```
cd StoreSyria_app98
SS_CAPTURE_DIR=<dir> flutter test test/screens/order_screen_capture_test.dart
```

---

## 4. Tests

| file | tests | what it is |
|---|---:|---|
| `test/screens/order_screen_capture_test.dart` | 6 | four combinations with rows, an empty-page control, and the pagination-overlap guard |
| `test/support/screen_harness_test.dart` | 15 | the harness's own claims, now including the zone and the query string (13 before) |

`flutter analyze`: 0 errors, 0 warnings. `flutter test`: **1647 passed** (1639 before).
No production code changed for this surface.
