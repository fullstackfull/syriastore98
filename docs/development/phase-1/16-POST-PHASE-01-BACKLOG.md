# Post-Phase-01 implementation backlog

**Owner direction, 2026-10-24:** *"For #61 and #62, keep dormant auction functionality safely
disabled and transfer noncritical implementation work to a clearly documented future backlog. Do not
delete historical data."*

This file is that backlog. It exists so that deferred work is **a named item with a precondition**
rather than a defect that quietly stops being tracked. Nothing here is a Phase 01 deliverable and
nothing here is closed by Phase 01 acceptance.

Three rules govern every entry:

1. **The deferral rests on a containment claim, and the claim is asserted by a test.** If the test
   reddens, the deferral is void and the item becomes live work. The test is named in each entry.
2. **No historical data is deleted.** Dormant does not mean removed: existing rows stay, and the
   code that reads them stays, so that nothing already recorded becomes unreadable.
3. **An entry states what must be true before it is picked up**, so the next reader does not have to
   reconstruct why it was safe to leave.

---

## A — Auction order path: `P1-INV-16` (#61) and `P1-ORD-06` (#62)

### Why these are deferred rather than fixed

Both are defects in code that runs **only on an existing `auction_product_orders` row belonging to
the caller's own store**. Auction orders are written by exactly one thing — the `auction:order`
console command (`app/Console/Auction.php:92`) — and the owner's decision **D-03** keeps that command
unscheduled. So the preconditions for either defect cannot be created through any request.

**The containment claim, asserted:** `tests/Feature/Security/AuctionOrderDormancyTest.php` asserts
over `app/`, `packages/workdo/`, `routes/` and `bootstrap/` that

- the console command is the **only** writer of auction orders,
- it is registered as a command but **scheduled nowhere**,
- **no route** creates an auction order.

Each of its three assertions has a mutant — a second writer, a schedule entry, a controller-side
create — and each mutant fails the file. That is what makes "dormant" a measured property of the
tree rather than an assumption, and it is why this work can be deferred without the defects being
reachable in the meantime.

**What the test does NOT establish, and it is an operator check, not an engineering one:** that
production holds no pre-existing `auction_product_orders` rows. It is a single count:

```sql
SELECT COUNT(*) FROM auction_product_orders;
```

A non-zero result does not make the defects reachable by itself — a caller still has to own the row
— but it does mean the merchant-facing surfaces below operate on real data, which moves these items
from "deferred" to "scheduled before D-03 is revisited". **This count is a release prerequisite, not
a completed test.**

### A1 — `AuctionProductOrder::product_return` (#61, `P1-INV-16`)

Three distinct defects in one method, all read and none of them fixed:

| | defect | why it matters |
|---|---|---|
| 1 | restocks a `Product` resolved **without a store predicate** | `Product` is **not** in `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds no predicate for any principal. If an auction order's `product_json` can reference a product outside the order's store, this is a cross-tenant stock write. **Whether it can is the open question** — answer it first, because it decides whether this is critical or defence-in-depth. |
| 2 | **no replay guard** | A repeated return reduces `final_price` again each time. The host-order equivalent of this was closed under `P1-INV-15`; this path never got the per-line marker. |
| 3 | the **non-variant path is a silent no-op** | It either restocks nothing or is dead code. Whichever it is must be *asserted*, not assumed — a branch that silently does nothing is indistinguishable from one that works until inventory is counted. |

**Acceptance when picked up:** answer defect 1's question with evidence; a replay cannot reduce
`final_price` twice; the non-variant path either restocks correctly or is shown to be dead, and
whichever it is carries a test; **no file under `packages/workdo/` is edited** (a fix that appears to
need a module file belongs in the host app as a policy, scope or middleware).

### A2 — `auction-order-status-change` (#62, `P1-ORD-06`)

The auction status-change path reads the **host `orders` table with an auction order id** and POSTs
the result — PII included — to a **merchant-configured URL**.

Two things are unresolved, and the first decides the severity:

- **Can a merchant self-serve a `Status Change` webhook row?** If yes, this is a live cross-tenant
  PII exfiltration and high severity. If it needs an operator-created webhook, it is medium. This is
  a question about the webhook admin surface, not about the auction code.
- The path must stop reading the host `orders` table with an auction order id at all. Two id spaces
  are being used interchangeably; that is the defect underneath the disclosure.

**Acceptance when picked up:** the webhook-provisioning question answered with evidence; the auction
status-change path no longer reads host `orders` by an auction id; **no `packages/workdo/` edit**.

### What Phase 01 did for these, so it is not re-litigated

- Measured and recorded both defects rather than describing them.
- Established and **asserted** the dormancy that makes deferral safe, with mutants.
- Left every existing row and read path intact.
- Did **not** fix the three implementation defects in A1 or the two in A2 — stated plainly here
  rather than implied by their absence from a completed list.

---

## How to pick an item up

1. Re-run the containment test named in the entry. If it is red, the deferral is already void and the
   item is live work — start there.
2. Run the operator check if the entry names one, and record the answer in the entry.
3. Answer any open question the entry marks as deciding severity **before** writing code; the answer
   may change what the fix is.
4. Work it as an ordinary task under `13-PHASE-01-EXECUTION-PROTOCOL.md`'s evidence rules — a failing
   test first, then the fix, then the removal check.

## Related documents

| | |
|---|---|
| owner decisions still open | `15-DEFERRED-OWNER-DECISIONS.md` |
| the task ledger and per-row evidence | `12-PHASE-01-TASK-LEDGER.md`, `phase-01-tasks.json` |
| release prerequisites that are not tests | `PHASE-01-CLOSURE-EXECUTION.md` |
| the GATE F schema and reconciliation items | `../../GATE-F-VERIFIED-SCHEMA.md` |
