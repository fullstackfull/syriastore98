# E13 — architecture re-evaluation, 2026-10-31

**Why this file exists.** D-30 reopened Phase 02 and instructed: *"Re-evaluate the real current
architecture, not the stale 2026-10-26 analysis. D-21 subsequently routed the nine stock-writing
paths through the existing ProductStockService mechanisms. Reassess which earlier technical
objections are now resolved."*

Everything below is measured on `8483374f`, not carried over.

---

## 1. The three blockers of 2026-10-26, re-tested

| blocker | 2026-10-26 | 2026-10-31 |
|---|---|---|
| (i) no gateway refund driver | `hasGatewayRefundDriver()` hardcoded `false` | **UNCHANGED.** `RefundIntegrityService:119-122` still `return false;` |
| (ii) a movement record would be **silent on five writers** | true: five writers did a raw read-modify-write and touched neither the service nor the trait | **RESOLVED BY D-21** — see §2 |
| (iii) cancellation's stock effect is path-dependent | 1 of 5 doors restored, 4 did not, all wrote identical state | **UNCHANGED, and now measured exactly** — see §3 |

**So one of the three is gone, and it is the one that blocked the evidence design.** That is the
whole reason this re-evaluation was worth doing rather than inheriting the earlier conclusion.

---

## 2. `ProductStockService` IS now the single stock mutator — measured, not assumed

Two independent checks:

**(a) Nothing outside the service mutates stock.** A search for every mutating form —
`update(['product_stock' => ...])`, `->decrement('product_stock')`, `->decrement('stock')`,
`->increment(...)` — across `app/` returns, outside the service itself, **exactly one hit: a
commented-out line at `PosController:208`.**

**(b) The hits that look like stock writes are not.** Ten `'product_stock' => ...` occurrences
across the gateway controllers are array keys inside `OrderNote::order_note_data([...])` — the
order's own snapshot of what the stock was, with `'status' => 'Stock Manage'`. They record; they do
not mutate.

**The mutator surface is four public methods:**

```
ProductStockService::decrementProductStock($product, $qty, $allowOversell, $requireAvailability)
ProductStockService::decrementVariantStock($variant, $qty, $allowOversell, $requireAvailability)
ProductStockService::restoreProductStock($productId, $quantity)
ProductStockService::restoreVariantStock($variantId, $quantity)
```

**And the caller surface is 26 sites in 7 files**, which is smaller than "nine writers" suggests
because `AppliesCartStockChanges` is a trait shared by the gateway controllers:

| file | sites | what it is |
|---|---|---|
| `Concerns/AppliesCartStockChanges.php` | 6 | the shared trait: 8+ gateway controllers reach stock only through it |
| `Services/PaymentService.php` | 6 | |
| `Http/Controllers/OrderController.php` | 6 | merchant admin, manual order, cancel |
| `Models/Order.php` | 3 | **`product_return()` only** — returns, not cancellation (§3) |
| `Http/Controllers/OrderRefundController.php` | 2 | |
| `Http/Controllers/Api/DashboardController.php` | 2 | the Flutter admin app's writer |
| `Http/Controllers/PosController.php` | 1 | the till |

**THE OBJECTION THAT IS RESOLVED.** D-21's own text says why coverage was a precondition: *"A
movement record written from the shared service would be SILENT on all five, so an absent record
would mean 'no decrement, OR a decrement on an uninstrumented writer' — the exact inference D-15
forbids."* That is no longer the case. A record written inside those four methods now observes
**every** stock movement on every order path, so **absent means absent**.

### The one thing that still blocks it, and it is a signature

**None of the four mutators receives the order.** `decrementProductStock($product, $qty, ...)` knows
the product and the quantity and nothing about why. So a movement written there today could say
*"store 3 lost 2 of product 41 at 12:04"* but **not which order consumed it** — and E13's question is
exactly *"did THIS order consume stock?"*.

Three ways to close that, and only one is honest:

1. **Thread an explicit context through the four signatures.** 26 call sites, each a one-line
   addition. Explicit, no hidden state, and the compiler finds every site.
2. **An ambient context** (`ProductStockService::forOrder($id)` set before a loop). Fewer edits, but
   implicit per-request state that a forgotten call silently mis-attributes — and a movement
   attributed to the WRONG order is worse than one attributed to none, because E13 would then build
   a replacement on it.
3. **Record the movement without the order** and correlate later by (store, product, qty, time).
   **This fails D-15 outright**: correlation is inference, and D-15 forbids inferring stock movement.
   Two orders for the same product seconds apart are indistinguishable.

**Option 1 is the design.** Its cost is bounded and mechanical; the other two trade a smaller diff
for exactly the kind of unprovable attribution E13 exists to eliminate.

---

## 3. The five cancellation doors, and what each actually does to stock

**All five write the same three fields and then diverge on stock.** The shared arm is
`Order::order_status_change()`:

```php
$order->cancel_date = now();
$order->delivered_status = 2;
$order->payment_status = 'Unpaid';
```

…and **it restores nothing.** Measured: the cancel arm contains no call to any of the four mutators.

| door | reaches | restores stock? |
|---|---|---|
| `OrderController::status_cancel` | its own path | **YES** — proven by 9 passing cases |
| `OrderController::order_status_change` | `Order::order_status_change()` | **NO** |
| `Api/ApiController::order_status_change` | `Order::order_status_change()` | **NO** |
| `Api/DashboardController` (`type == 'cancel'`, `:5162`) | `Order::order_status_change()` | **NO** |
| `Api/DashboardController:546` (`cancel_date = now()`) | writes the field directly | **NO** |

`Order::product_return()` (`:1062`, `:1093`) does restore — but that is the **return/refund** event,
a different thing from cancellation, and conflating them is how a double-restore gets built.

### Why this is the reason E13 needs evidence, stated as the three failures it causes

Because the row is identical in all five cases, **the order tells you nothing about its stock**:

- a replacement built on *"it was cancelled, so stock is back"* is **wrong four times in five**;
- a replacement that **restores then re-deducts** double-restores on the one door that already did;
- and a replacement that **assumes the original decremented** is wrong whenever the original never
  reached a decrement — a refused shortfall, a rolled-back transaction, an untracked product.

D-15's prohibition is therefore not conservatism. It is the only correct reading of this table.

---

## 4. What this re-evaluation authorises, and what it does not

**Resolved and actionable now:** the evidence record is implementable, because the choke point
exists and the caller surface is 26 bounded sites.

**Still blocked, and not by anything this slice can reach:**

- **No gateway refund driver.** A collected payment cannot be reversed, so any scenario whose
  correctness depends on refunding the original stays BLOCKED. D-30 confirms no new refund or
  settlement semantics are approved.
- **The decrement still precedes payment confirmation** on the gateway paths. D-21's own closing
  line says so; it is D-13's open half.

**Explicitly out of scope by D-15 and restated by D-30:** a replacement with different product
lines, quantities or prices.
