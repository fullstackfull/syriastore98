# E13 — can durable proof of a stock decrement be had with less change than a new table?

**Status: analysis complete, nothing built.** The owner's instruction of 2026-10-26 was explicit
about the order of work:

> قبل اعتماد شكل الجدول، راجع الخدمات والمخطط الحالي وحدد ما إذا كان بالإمكان الحصول على الإثبات
> الدائم بأقل تغيير آمن. لا تنشئ محرك مخزون ثانياً.

This file is that review. It answers one question — *can the durable proof E13 needs be obtained
with the least safe change?* — and it reaches a conclusion the obvious one does not: **a new table
is probably necessary, but it is not the first change, and the reason the cheaper options lose is
not storage. It is writer coverage.**

**It also corrects itself in several places.** The first pass of this analysis was independently
refuted, and three of its findings were wrong in ways that changed the answer. Those are marked
*(corrected)* rather than quietly fixed, because the shape of the error is the useful part.

---

## 1. The question E13 actually needs answered

E13 wants to offer a **linked replacement order** for a cancelled one. Before it can reuse the
original's lines, it must know a single boolean about the original order:

> **Did this order consume stock?**

If it did, the replacement must not decrement again. If it did not, the replacement must
decrement. Getting this wrong in either direction is a real inventory error, and the owner's
D-15 forbids guessing:

> لا تعتبر الطلب الأصلي قد استهلك المخزون إلا بدليل من مسار المعالجة الفعلي. لا تستخدم علامة
> "المخزون خُصم سابقاً" على طلب لم يثبت خصم مخزونه.

**Nothing in the database answers it today.** There is no column, no marker and no log that
records a decrement. `Order::LINE_STOCK_RESTORED` exists, but it is the *restore* half — its
absence means "not restored", not "not decremented".

---

## 2. The candidates, and why each loses

### Candidate 1 — stamp the cart line inside `product_json`

**The cheapest thing that could work, and it genuinely would answer the boolean.** The decrement
loop mutates line objects that an existing `json_encode($products)` already persists, so the
marker costs no new write and no new query, and it commits or rolls back with the order.

**Why it loses anyway:**

- It stores **state, not movements**. "Decremented 3, restored 2, restored 1 more" with three
  timestamps and three reasons is inexpressible, and E13's refund path needs exactly that.
- The stored line carries **no `variant_id`** in the storefront shape — measured in the dev
  fixture, the keys are `product_id, name, qty, price, total_orignal_price, final_price`. That is
  why `OrderRefundController` already has to guess which variant was returned.
- It lives in the one column the whole E11 immutability rule exists to freeze.
- *(corrected)* `product_json` has **four** writer shapes, not three. The Flutter admin writer
  (`Api\DashboardController::createOrderViaApi`) emits `quantity`, `subtotal`, `orignal_price`,
  `variant_id` and `return` — **no `qty`, no `final_price`**. Any reader keyed on `qty` reads null
  from it. The heterogeneity is worse than the first pass said, not better.

### Candidate 2 — an `order_notes` row

**Disqualified twice over**, and both are measurable rather than stylistic:

- `order_notes.order_id` is `onDelete('cascade')`, and orders are **hard-deleted** —
  `orders` has no `deleted_at` and `Order` carries no `SoftDeletes`. So the evidence is destroyed
  by the single operation that most needs it. *(corrected)* The delete sites are
  `OrderController.php:1599` and `BulkDeleteController.php:77/:88` — the latter maps **both**
  `'order'` and `'sales-downloadable-product'` to `Order::class`. The first pass also cited
  `BankTransferController:218`, which is wrong: that method takes a `PlanOrder`, a SaaS plan
  order, and destroys no `order_notes`.
- A merchant holding the default `Delete Order Note` permission can delete it.

### Candidate 3 — a column on `orders`

Costs a migration, same as a table, and proves strictly less than candidate 1 gives free: one
boolean, no movements, no reasons, no quantities per line.

### Candidate 4 — a `stock_movements` table

The only candidate that can carry movement type, quantity, reason, operation reference and a
database-enforced idempotency key. **Neither `orders` nor `order_notes` carries any unique index**,
so the owner's "مفتاح Idempotency دائم وفريد" cannot be enforced by the database on either without
a migration — and once a migration is unavoidable, the table costs the same as the column.

---

## 3. The finding that actually decides it: **writer coverage**

A record is only evidence if its *absence* means something. Today it would not.

Raw read-modify-write decrements that touch **neither** `ProductStockService` **nor**
`AppliesCartStockChanges`:

| writer | spelling | reachable as |
|---|---|---|
| `Api/ApiController` (×2 paths) | `$remain_stock = …` | storefront order writers |
| `AuthorizeNetPaymnetController` | `$remain_stock = …` | gateway return |
| `CinetPayController` | `$remain_stock = …` | gateway return |
| `EasebuzzController` | `$remain_stock = …` | gateway return |
| `KhaltiPaymnetController` | `$remain_stock = …` | gateway return |
| `PaiementProPaymentController` | `$remain_stock = …` | gateway return |
| `PayUPaymentController` | `$remain_stock = …` | gateway return |
| **`Api/DashboardController::createOrderViaApi`** | **`$variant->stock -= $qty;`** | **`POST api/admin/createOrderViaApi` — the shipped Flutter ADMIN app's order writer** |
| `OrderController::store` | its own **private** copy of the service | merchant admin, manual order |

So a movement row written from the trait would be **silent on most order writers**, and an absent
row would mean *"no decrement, OR a decrement on an uninstrumented writer"* — precisely the
inference D-15 forbids.

### *(corrected, and this is the important one)* the census that produced that table was itself incomplete

The first pass reported "24 sites in 7 controllers" and called it the complete uninstrumented set.
It was keyed on the `$remain_stock` spelling. **`createOrderViaApi` spells the same operation
`-=`**, so it was invisible — and it is the writer a Phase 02 reader would most want to know
about, because it is the admin app's. It is recorded as **ledger F118**.

`OrderController` was also mis-described: it does not use the trait, it carries its **own private**
`stockService()`, so a change to the trait does not reach it either.

**The lesson is not about these two writers.** A grep-shaped census of a behaviour that has several
spellings reports a complete picture and is silent about whatever is spelled differently. The next
census of this kind must enumerate **writers of the columns** (`product_stock`, `stock`), not
occurrences of a phrase.

---

## 4. So what is the least safe change, in order?

1. **Nothing about storage comes first.** The first change is enrolling the writers, and that is
   also the owner's own instruction — *"استخدم خدمات `ProductStockService` و`AppliesCartStockChanges`
   الحالية… لا تنشئ محرك مخزون ثانياً"*.
2. **Authority is not uniform, and D-18 is the limit.** D-18 approves routing through the service
   for **Khalti, PaiementPro, CinetPay and Easebuzz** by name. `Api/ApiController`,
   `AuthorizeNetPaymnetController`, `PayUPaymentController`, `Api/DashboardController` and
   `OrderController` are **not** covered and need their own decision.
3. **Only then does a movement store become trustworthy**, because only then does an absent row
   mean "no decrement".

### Two corrections that change how step 3 must be built

- *(corrected)* **The "write the movement in its own immediately-committed transaction" shape is
  not achievable at two writers.** `OrderController::store` and `createOrderViaApi` both decrement
  **inside** an already-open `DB::beginTransaction()`. A nested `DB::transaction` in Laravel is a
  SAVEPOINT, not an independent commit, so the "orphan row that outlives a rollback" reasoning
  evaporates there. The movement row rolls back with the order — which is correct behaviour, but
  it is not what the first pass claimed, and a design that depends on the orphan is wrong for
  those two.
- *(corrected)* **The proposed idempotency key could not work.** It was "a per-attempt operation
  id plus the line index". A per-attempt UUID is fresh on every attempt, so two keys can never
  collide and the unique index can never fire. It also conflated two different things: a genuinely
  replayed checkout that decrements twice **should** produce two rows — two real movements. What
  must be deduplicated is *one movement recorded twice*. Only the restore side has a naturally
  deterministic key (`ord:<id>:line:<idx>:res:refund:<refundId>`), which mirrors the real
  precedent at `OrderRefundController.php:927`.

---

## 5. What a `stock_movements` table would cost beyond the migration

*(corrected — the first pass missed all of this)* Adding one model is not a neutral act in this
repository:

- `tests/Feature/Tenancy/TenantModelClassificationTest.php` asserts an exact model count and an
  exact count of tenant-discriminated models. A new `store_id`-bearing model changes **both**, and
  neither test is in `.github/known-test-outcomes.json`, so the CI gate fails in the
  undeclared-failure direction unless both are updated in the same change.
- `test_every_trait_carrier_is_either_enforced_or_explained` fails unless the model is added to
  `config/tenancy.php`'s `scoped_models` with its per-entry rationale.
- `tests/Feature/Tenancy/ModelInitializationTest.php` is data-provider driven over discovered
  `BelongsToStore` carriers, so the new model is immediately driven by five further assertions.

None of these is an obstacle. They are simply the real cost, and a plan that does not name them
would fail CI on its first push.

---

## 6. Conclusion

**A new table is the right storage** — it is the only candidate that can carry movements rather
than state, and the only one where a unique idempotency key can be enforced by the database.

**But it is not the first change, and building it first would produce a record that cannot be
trusted**, because an absent row would not mean "no decrement" while most order writers are
uninstrumented.

**Recommended order:**

1. Enrol the four writers D-18 already authorises (this is the D-13 work, in flight).
2. Put the remaining five writers — two of them API paths the Flutter apps depend on — to the
   owner as their own decision. Recorded as **D-21**.
3. Build `stock_movements` only after coverage is real, with a deterministic key on the restore
   side, no dependency on an "orphan row" at the two transactional writers, and the three tenancy
   tests updated in the same change.

**E13 itself stays BLOCKED** regardless, for the separate reason the owner established: reversing
a merchant's balance is not a refund to the customer, and `RefundIntegrityService::hasGatewayRefundDriver()`
returns a hardcoded `false` with the three gateway refund clients having zero callers. Stock
evidence is necessary for E13, not sufficient.
