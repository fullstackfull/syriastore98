# P5-2 — the metric layer

**Status:** engineering complete. **Not** wired into any surface: `ReportController` still computes
its own figures, and replacing those is P5-3. Nothing a merchant sees has changed.

**Gates, both closed before this slice started:** P4-F124 (cancellation ledger semantics) and
P4-F128 (settlement separated from entitlement, with reconciliation).

---

## 1. What this is

Ten query objects under `app/Support/Metrics/`, one per section of
`PHASE-05-METRIC-DEFINITIONS.md`, each carrying its tenant predicate, its currency handling and its
caveat flag. The plan's requirement — *"No controller computes money inline"* — is a property of
where the predicates live, not of how the SQL is written.

| § | class | record | dated by |
|---|---|---|---|
| 1 | `GrossMerchandiseValue` | settlement | `orders.order_date` |
| 2 | `CollectedCustomerPayments` | entitlement | `store_order_financials.paid_at` |
| 3 | `UnpaidOrders` (three sub-metrics) | entitlement | `orders.order_date` |
| 4 | `CancelledOrders` | entitlement | `COALESCE(orders.cancel_date, store_order_financials.updated_at)` |
| 5 | `CompletedRefunds` | movement | `store_wallet_transactions.created_at` |
| 6 | `NetFinancialReceipts` | movement | `store_wallet_transactions.created_at` |
| 7 | `MerchantWalletEntitlement` | movement | **none** — a running balance |
| 8 | `StoreRevenueMargin` | entitlement | `paid_at` / `created_at` by basis |
| 9 | `CouponRedemption` | settlement | `orders.order_date` — **never authoritative** |
| 10 | `ShippingAndTax` (two sub-metrics) | entitlement | `store_order_financials.created_at` |

Supporting value objects: `MetricScope`, `MetricWindow`, `MetricResult`, `MetricCaveat`,
`MixedCurrencyException`, and the two shape interfaces `SingleFigureMetric` / `MultiFigureMetric`.

---

## 2. The four design decisions worth arguing with

**The tenant predicate cannot be omitted, by construction.** `Metric` exposes exactly four table
accessors and each applies `whereIn('<table>.store_id', ...)` *in the same expression that names
the table*. Ten metric classes are built on them and none can obtain an unscoped builder.
`MetricScope` has **no constructor meaning "every tenant"** and refuses an empty list, so there is
no value a caller can pass that produces an unfiltered money query. This matters more here than
elsewhere: `Order` is not in `config/tenancy.php`'s `scoped_models`, `store_order_financials` has
no model at all, and `null_tenant => 'open'` means an unresolved tenant adds no predicate — a
forgotten `store_id` returns the whole platform's money under one merchant's heading.

**"Three sub-metrics, never one" is in the type, not in a comment.** `UnpaidOrders` implements
`MultiFigureMetric` and has no `for()` to call. A base class requiring every metric to yield one
figure would have made collapsing them the path of least resistance.

**Totals refuse to span currencies.** `MetricResult::total()` throws `MixedCurrencyException` when
the rows hold more than one. `byCurrency()` and `totalIn()` are always available. The layer does
**not** convert, because `CurrencyRate` is a rate at a moment and choosing the moment is a finance
decision, not a reporting one.

**A caveat is a key, not a sentence.** `MetricCaveat` holds nine keys, each citing the measured
property it describes; `message()` resolves the Arabic or English. One is *blocking* rather than
qualifying — §9's coupon redemption — and a blocking caveat makes `isAuthoritative()` false.

### Choices the definitions left open, resolved and labelled

The definitions name no default in three places. Each is a constructor flag whose value is always
reported in `notes()`, per §1's *"The report must label which it used. Never silently pick one."*

| choice | default | note key |
|---|---|---|
| §1 does GMV include cancellations | included (the convention) | `cancellations` |
| §8 which orders have margin | settled only (`payment_status = 'paid'`) | `basis` |
| §2 does `partially_paid` count as collected | yes, when `paid_amount > 0` | `partially_paid` |

§8 uses `paid` only and **not** §2's wider eligibility: §2 counts a cancelled-but-captured order
because the *shopper* paid; margin must not, because the *merchant* did not keep it.

§3 has a fourth case the definition does not name — a `partially_paid` row is in **none** of the
three buckets. Inventing a fourth sub-metric would be inventing a requirement; staying silent would
lose the money. Each result carries `partially_paid_outside_all_three` with the count.

---

## 3. Measured during implementation

- **`order_coupon_details` has no `store_id` column.** Its tenant predicate can only come from the
  order it belongs to, so §9 joins from `orders`. A test drives a foreign-tenant coupon.
- **`orders.delivered_status` is `integer()->default(0)` — NOT NULL.** The null limb of §1's
  cancellation exclusion is therefore unreachable under the migration as declared. It is kept, and
  the test that was meant to drive it was **replaced** with one that drives the real fulfilment
  vocabulary, rather than left asserting something it could not reach. The migrations are not the
  schema, and `NULL != 2` is not true, so the predicate stays.
- **Nothing in the schema forbids a financial row whose order belongs to another store.**
  `fk_order_financial_order` and `fk_order_financial_store` do not constrain each other. §3 and §4
  join on `orders.store_id = store_order_financials.store_id` rather than on `order_id` alone, so
  such a row cannot be classified by a foreign order's `payment_type`.
- **§7's drift check is the corrected form of Q-F032-5, not a copy.** `GateFReconciliation` R1 and
  the F032 procedure both filter on `t.status` and both include `withdrawal_paid` rows, whose
  `balance_after == balance_before` **by design** — summing them debits the wallet a second time
  and reports phantom drift on every paid withdrawal. This restricts to rows that actually moved
  the balance and reports a multi-currency ledger as its own fact.
- **`reset()` cannot read a readonly property.** It takes its array by reference. Found by the
  tests, fixed by indexing a copy.

---

## 4. Evidence

```
php artisan test tests/Unit/Metrics/MetricValueObjectTest.php       # 19 passed
php artisan test tests/Feature/Metrics/MetricLayerTest.php          # 39 passed
php artisan test tests/Feature/Tenancy/RawQueryTenancyInventoryTest.php \
                 tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest.php   # 5 passed

# on a real engine -- creates and drops its own throwaway database
php tests/Support/Security/bin/verify-metric-layer-on-mysql.php     # 37 checks, 0 failed
```

**Mutation evidence — 34 of 34.** Every predicate, label, caveat rule and validation in the layer
was removed or inverted one at a time and the tests re-run. All 34 reddened, with no unpinned
predicate and no anchor failure. That battery is the reason this slice is trustworthy, and it is
not decoration: **the first run found seven unpinned predicates and one test that passed for the
wrong reason**, and the run after the review findings found two more.

The flawed test was §5's — *"an agreed but unsettled refund contributes nothing"*. Its fixture held
no wallet movements at all, so the metric returned zero whatever it queried: deleting the
`type = order_refunded` filter entirely left it **green**. It proved only that an empty table sums
to nothing, on the exclusion the definitions call the sharpest in the document. A decoy capture
credit of exactly the refund's amount now makes both wrong implementations report 100.

The other six gaps were a `paid_amount > 0` guard, §2's capture-date basis, the cross-tenant join
equality, §4's cancelled-status predicate, §6's `reference_type` filter, and the two value-object
validations. All are now pinned by named cases.

The raw-query census moved `app` 184 → 188 and the literal-table total 177 → 181. All four new
sites are the `Metric` table accessors, all four name `store_id`, and **the unscoped totals did not
move**. Each is classified in `RawQueryTenancyInventoryTest` rather than merely renumbered.

---

## 4a. Independent review, and what it changed

Reviewed by the `tenant-authz-reviewer` subagent against the design claim *"a metric in this
namespace cannot read another tenant's money, by construction"*, with all four legs of the argument
attacked. **It could not break the claim** — no path to a money table without a `store_id`
predicate, no `selectRaw`/`DB::raw`/subquery escape, no injection surface (every argument is a
literal or a whitelisted constant), scope propagated intact through `NetFinancialReceipts`'s three
composed legs, and `MetricScope` failing closed on empty, zero, negative, non-numeric and null.

It refined the claim usefully: what is actually true is *a metric cannot read outside the scope it
is handed*. **Whoever builds the `MetricScope` is the authorization boundary, and that code does
not exist yet.** The predictable defect when P5-3 wires this up is this codebase's most productive
shape — `MetricScope::store($request->store_id)`, which compares the caller's own two values and is
not a tenant check. Note also that `getCurrentStore()` returns **store id 1** for a `super admin`
and is a convenience resolver, not an authorization check.

Six findings, **all accepted and all fixed in this slice**:

| | finding | fix |
|---|---|---|
| F1 | §3's three predicates do **not** partition the rows. A gateway order whose capture could not be verified sits at `pending` with a client-supplied `payment_type`; anything that is not a COD spelling or exactly `bank_transfer` was in no bucket **and in no disclosure**. | A second count, `pending_with_unclassified_payment_type`, with an explicit null limb — `NULL NOT IN (...)` is NULL, not true, so a null type would have fallen out of the very count meant to catch it. |
| F2 | `test_every_metric_refuses_to_read_another_tenant` proved the property for **8 of its 13 legs**: store B held no row the other five would have matched even unscoped, so those assertions passed against both the fixed and the reverted code. | Store B gained a cancelled row, a refund movement and one row per unpaid bucket; the test now evaluates **every** leg before asserting, so one run names all thirteen. Measured after the fix: with the predicates deleted, all 13 report store B's money. |
| F3 | `drift()` was the one join relying on data integrity instead of a predicate — it joined on `wallet_id` alone, though `store_wallet_transactions` carries its own `store_id` and nothing forces the two to agree. | `whereColumn('store_wallet_transactions.store_id', '=', 'store_wallets.store_id')`, with a test driving a row that pairs store B's `store_id` with store A's `wallet_id`. |
| F4 | The four accessors were not `final`, so *"a subclass cannot obtain an unscoped builder"* was a convention. | Four `final` keywords, plus a reflection ratchet — a mutation test **cannot** catch this, because removing `final` breaks nothing that exists today. |
| F5 | The "no all-tenants constructor" ratchet asserted three method *names*; `forAllStores()` or `unscoped()` would have passed it. | Asserts the exact public static constructor set is `['store', 'stores']`. |
| — | `NetFinancialReceipts`'s docblock disclosed the movement-vs-settlement dating split but not that the **status side is itself date-hybrid** — its refund term is §5 and is movement-dated. | Stated in the docblock. |

One behaviour recorded rather than changed: `sumByCurrency` groups on the raw `currency_code`, so
under production's `utf8mb4_general_ci` `'USD'` and `'usd'` collapse into one group while SQLite
would make two. Production is the forgiving side, so the risk is a misleading test, never a wrong
figure for a merchant.

Also surfaced, and **outside this slice**: `bank_transfer` is in
`PaymentVerificationService::OFFLINE_PAYMENT_TYPES`, so the storefront records such an order **Paid
at creation**. §3's `awaiting_manual_settlement` bucket is therefore populated by a merchant moving
a row back to `pending` through the finance screen, not by checkout. Named in the class docblock
because it decides whether that bucket measures what its name says.

---

## 4b. Verified on a real server

`tests/Support/Security/bin/verify-metric-layer-on-mysql.php` — **37 checks, 0 failed**, on
MariaDB 10.11.14 with `ONLY_FULL_GROUP_BY` **forced on** (this container's server does not enable
it by default, and testing under the stricter mode is the only direction safe to be wrong in). It
creates and drops its own throwaway database and refuses any name that does not say it is
throwaway.

What it answers that the SQLite suite cannot:

- **every metric executes under `ONLY_FULL_GROUP_BY`** — each one aggregates with a `GROUP BY` over
  a `selectRaw` currency column, which SQLite permits in any shape;
- **`DECIMAL(15,2)` is carried end to end.** Collected payments keep the last cent of
  `1,234,567.89 + 0.07 + 0.03`, and §6's two computations agree **on real decimals** rather than on
  two floats that agree for the wrong reason — the same blind spot that let P01-SEC-13 survive;
- **`COALESCE(DATE, DATETIME)`** really does place a cancellation with no `cancel_date` inside a
  bounded window, where MySQL casts the DATE to midnight;
- **`LOWER(TRIM(payment_type))`** matches `'  COD  '` and `'Bank_Transfer'` under the server's own
  collation;
- **`NULL NOT IN (...)`** — the F1 disclosure counts a null `payment_type` row on the real engine;
- **tenant isolation by exact total**, not by threshold. The first draft of that section asked
  whether any figure was `>= 9999` and reported five false leaks, because store A's own books run
  to 1.2 million: a check that cannot tell a tenant leak from a large merchant is not a tenancy
  check.

---

## 5. What this slice does NOT do

- **No surface uses it.** P5-3 points `ReportController` at it, one surface at a time, with a
  before/after figure recorded for each. Those numbers **will** change, because the figures they
  replace count orders nobody paid for.
- **No currency conversion.** Deliberate; see §2 above.
- **§9 is reportable only with its warning.** P4-F134 and P4-F135 are open and pull in opposite
  directions, neither quantified, so the true value cannot be bounded. `isAuthoritative()` is false
  for every result it can produce.
- **Partial refunds are excluded from §5 entirely**, per `P4-F127-PARTIAL-REFUND-CAPABILITY.md`,
  and the caveat is unconditional.
- **§4 under-counts from one door** until `DashboardController::orderCancel` writes a `cancel_date`.
  The fallback catches those rows; the caveat declares it when it fires.

---

## 6. Which owner-approved decisions this slice answers to

From `docs/development/master-plan/DECISION-ID-REGISTER.md` §`ANA-001`…`ANA-010`. **Delivered by
this slice is not the same as delivered for the phase** — most of these need a surface, which is
P5-3 onward.

| id | decision | what P5-2 contributes |
|---|---|---|
| `ANA-005` | Separate sales, collection, settlements and profitability | **The core of this slice.** §1 GMV, §2 collected, §6 net receipts, §7 entitlement and §8 margin are five distinct figures, and `MetricResult::record()` makes every one state whether it measures settlement, entitlement or movement. |
| `ANA-006` | Do **not** claim precise profitability when cost or fee data is missing | `MetricCaveat::COST_UNKNOWN`, attached when and only when rows carry `product_cost = 0`, with the count in `rows_without_cost`; plus the unconditional `EXCLUDES_SHIPPING_AND_TAX`. |
| `ANA-007` | Show data quality, estimates and omissions | The whole caveat mechanism: nine keys, each citing a measured property, resolved bilingually; `isAuthoritative()` for the blocking case. |
| `ANA-008` | Document metric definitions and their sources | Every metric's `definition()` returns its section of `PHASE-05-METRIC-DEFINITIONS.md`, and `datedBy()` names the column the figure is dated by. |
| `ANA-010` | Do **not** build a new accounting engine | This layer **writes nothing**. Ten read-only query objects over the ledger that P4-F124/F128 already established. No new table, no new column, no new movement type. |

**Still open after this slice**, and none of them is started: `ANA-001` (the analytics centre —
P5-4), `ANA-002` (comparisons, filters, saved periods), `ANA-003` (custom reports, export and
scheduling, permission-gated — P5-8), `ANA-004` (conversion, device and channel analysis — no
event source exists for it today), `ANA-009` (alerts and proactive analytics).

`ANA-006` and `ANA-007` are **partly** delivered: the mechanism exists and is pinned by tests, but
nothing renders it yet. A caveat no surface displays is not a disclosure. That is P5-3's and P5-4's
to finish, and the plan's §4 verification standard requires it per surface.
