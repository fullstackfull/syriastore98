# Proposal: scope customer e-mail uniqueness to `(email, store_id)`

**For owner approval. Nothing in this proposal is implemented.** Written 2026-11-07 in response to
the owner instruction of the same date, item 4: *"The proposed rule is uniqueness by
`(email, store_id)`, consistent with the reported existing writers. Verify database constraints,
archive/re-registration behavior and authentication implications. Present the exact proposal for my
approval before changing the business policy."*

The current behaviour is **pinned by a test**
(`CustomerCreateFormTest::test_another_stores_live_email_is_still_refused_which_is_the_open_question`)
whose own failure message says it is what should be rewritten once this decision lands.

---

## 1. What is inconsistent today, measured

`CustomerController::store()` — the merchant web form — is the **only** writer of `customers.email`
that enforces uniqueness **globally**. Every other first-party writer scopes it to the store:

| # | site | rule |
|---|---|---|
| 1 | `Customer/Auth/CustomerLoginController.php:104` — storefront registration | `->where('store_id', $store->id)` |
| 2 | `Api/DashboardController.php:6283` | `->where('store_id', $storeId)` |
| 3 | `Api/DashboardController.php:8611` | `->where('store_id', $storeId)` |
| 4 | `AccountProfileController.php:127` | per store |
| 5 | `AccountProfileController.php:183` | per store |
| 6 | `Api/ApiController.php:6559` | `->where('store_id', $customer->store_id)` — **and its own comment states the rule**: *"Store-scoped uniqueness, as registration and the web account form both enforce: without it a shopper can take over another shopper's address in their store and break both accounts' login and recovery."* |
| — | **`CustomerController::store()`** | **global — the odd one out** |

So **two stores holding the same shopper is a state the product already produces**, through the
storefront's own registration. The admin form is the only place that refuses it.

**Two consequences of the inconsistency, both live:**

1. **A merchant cannot add their own customer** if that address happens to belong to a different
   merchant's store. Normal use, prevented.
2. **A platform-wide existence oracle.** A merchant holding `Manage Customer` learns whether any
   address is a customer **anywhere on the platform**, one address per request.

---

## 2. Database constraints — I WAS WRONG, and the owner's correction is measured

**My first version of this section said database-level enforcement was impossible because MySQL has
no partial-index syntax. That was wrong.** The owner's instruction — *"Do not claim enforcement is
impossible merely because MySQL lacks native partial-index syntax"* — is correct, and the reason is
**NULL semantics**: a MySQL `UNIQUE` index permits any number of rows whose indexed value is `NULL`.
So an expression that is `NULL` for archived rows and `(email, store_id)` for live ones *is*
`UNIQUE (email, store_id) WHERE deleted_at IS NULL`.

**Measured on real MySQL 8.0.46** by
`tests/Support/Security/bin/verify-customer-email-uniqueness-on-mysql.php` — **17 checks, 0 failed**.
It creates and drops its own throwaway database. **Two routes work, not one:**

### Route A — a STORED generated column plus a unique index

```sql
ALTER TABLE customers
  ADD COLUMN email_store_live VARCHAR(220)
    GENERATED ALWAYS AS (IF(deleted_at IS NULL, CONCAT(email, '|', store_id), NULL)) STORED,
  ADD UNIQUE INDEX customers_email_store_live_unique (email_store_live);
```

### Route B — a functional unique index, no column added (needs 8.0.13+)

```sql
CREATE UNIQUE INDEX customers_email_store_live_unique
  ON customers ((IF(deleted_at IS NULL, CONCAT(email, '|', store_id), NULL)));
```

**Both routes satisfied all four properties the policy needs:**

| property | Route A | Route B |
|---|---|---|
| two LIVE rows, same address, **same** store | **rejected** (SQLSTATE 23000) | **rejected** |
| two LIVE rows, same address, **different** stores | allowed | allowed |
| three **ARCHIVED** rows, same address and store | allowed (NULL semantics) | allowed |
| archive, then re-add the same address | allowed | allowed |
| …and a second live row after the re-add | still rejected | still rejected |

**Recommendation: Route B.** It adds no column, so no row grows and no `SELECT *` changes shape —
which matters because this codebase has 79 `Cachable` models and a widened row touches cache
payloads. Route A is the fallback if production turns out to be older than 8.0.13.

### What this changes about the race — and a REQUIRED application change

**The engine closes the window the application cannot.** Measured: two connections racing the same
insert, **exactly one survived**, and the loser was refused with SQLSTATE 23000. Laravel's
`validate()` is check-then-act and cannot close that; the index can.

**So this is not purely additive.** With the index in place, `Customer::create()` can now throw a
duplicate-key `QueryException` where it previously succeeded. **Every writer of `customers.email`
must handle that** and turn it into the same validation message a user would otherwise have seen.
The six writers are listed in §1. This is work, and it is part of the proposal rather than a
surprise after it.

### Existing data — the ALTER refuses a dirty table, which is the safe direction

Measured: against a table already holding live duplicates, the ALTER **fails** with
`Duplicate entry 'dup@x.test|1' for key '…_email_store_live_unique'`. **So the migration cannot be
applied blindly** — and that is correct behaviour, not an obstacle.

The pre-flight query, which belongs **in the migration** as a check that throws with the offending
rows named:

```sql
SELECT email, store_id, COUNT(*) c FROM customers
 WHERE deleted_at IS NULL GROUP BY email, store_id HAVING c > 1;
```

Measured: it finds exactly the offending group and does **not** flag archived rows sharing an
address, which are legitimate.

**I cannot tell you what production holds.** The dev fixture has **3 customers and no duplicates of
any kind**, so it is no evidence at all about production. This query must be run against production
before the migration is scheduled.

### COLLATION — the finding most likely to block this, and it is not a schema question

**The index folds case AND accents.** Measured on 8.0.46: `Case@x.test` and `case@x.test` in one
store **collide and the second is rejected**; so do accent-different pairs.

That is consistent with how the application already compares addresses, but it means:

> **If production holds `Alice@x.com` and `alice@x.com` as two separate LIVE customers in one
> store, the migration will refuse until somebody decides which of them owns the address.** That is
> a product decision about two real people's accounts, not a schema step, and it cannot be
> automated.

The pre-flight query above uses the same collation, so it finds those pairs too.

## 3. Archive / re-registration behaviour — verified

`destroy()` is `$customer->delete()` and `Customer` uses `SoftDeletes`, so archiving sets
`deleted_at` and the merchant is told *"Customer archived successfully."*

`P2.5-PKG-E` (E-1) already narrowed the admin form's rule to **live** rows
(`Rule::unique('customers','email')->whereNull('deleted_at')`), because without it an archived
address was reserved for ever and there is **no restore route, no archived-customer view and no
`withTrashed()` anywhere in `CustomerController`**.

**This proposal composes with that, giving `(email, store_id)` over live rows only.** Both
dimensions are needed and each was found separately:

| | archived row in my store | live row in my store | live row in another store |
|---|---|---|---|
| before PKG-E | refused | refused | refused |
| after PKG-E (today) | **allowed** | refused | refused |
| **with this proposal** | allowed | refused | **allowed** |

**One consequence of PKG-E that this proposal does NOT widen, because it is already fixed:** a
trashed row and a live row sharing an address in one store made
`ModuleStorefrontInputGuard::sameShopperInStore()` resolve a shopper to the **archived** twin,
attributing a storefront comment to a deleted customer. Fixed with `->whereNull('deleted_at')` and
covered by `ArchivedCustomerAttributionTest` (5 tests, 3 bites, one through the real module route).

---

## 4. Authentication implications — verified, and this is the one precondition

I censused every first-party resolution of a `Customer` **by e-mail**. Most are already scoped and
are unaffected:

| site | scoped | effect of this proposal |
|---|---|---|
| `CustomerLoginController:723` (login), `:1055`, `:1124` (recovery) | `->where('store_id', ...)` | none |
| `Api/AuthController:461`, `:545`, `:703` | `->where('store_id', $store->id)` | none |
| `Api/ApiController:6559` | `->where('store_id', $customer->store_id)` | none |

**Password reset is safe, and was checked rather than assumed.**
`Customer::getEmailForPasswordReset()` deliberately returns `'customer:' . $this->getKey()` — **not
an address** — so a reset token is bound to one account id and cannot be found by the `users` broker
or by another customer. That is the control that closed the earlier platform-takeover defect, and it
is untouched here. The broker resolves the account through the provider, which is soft-delete-scoped.

### **The precondition: two sites resolve a customer by e-mail with NO store predicate**

| site | code | consequence |
|---|---|---|
| `PaymentController.php:2070` | `Customer::where('email', $billing['email'])->first()` | **guest checkout.** With two live rows sharing an address, this can bind the order to the **wrong store's** customer. `$store` is in scope on this path (it is passed to `createCustomer($billing, $store, false)` on the very next branch), so the predicate is available |
| `HomeController.php:1218` | `Customer::where('email', $order->email)->first()` | order-status page, read-only into a view. `$store` is also in scope |

`Customer` is in `config/tenancy.php`'s `scoped_models`, so `StoreScope` normally supplies a
predicate — **but on an anonymous storefront checkout no tenant resolves**, and
`null_tenant => 'open'` means the scope then adds **nothing**. So on exactly the path that matters,
the global scope is not a backstop.

**This ambiguity already exists**, because the storefront's own registration already produces
same-address customers across stores. This proposal does not create it — it makes it more common.

> **Therefore: scoping these two sites is a precondition of approving the policy change, not a
> follow-up.** Both are one-line additions of `->where('store_id', $store->id)` using a `$store`
> already in scope. `PaymentController` is a payment path, so it needs its own reproduction, its own
> tenant-isolation test and its own `tenant-authz-reviewer` pass before it is touched.

---

## 5. Safeguard status — what is done, measured, and by what

| owner safeguard | state | evidence |
|---|---|---|
| **1.** fix the two unscoped lookups | **DONE** | `UnscopedCustomerEmailLookupTest` — 7 tests, **3 bites**. `PaymentController` was a **cross-tenant WRITE**, not just a wrong read: the `else` branch writes `last_active` to the row it found and returns it as the order's customer. `HomeController`'s is **unreachable dead code** — `orders` has no `email` column — which is a correction to my own earlier description |
| **2.** independent tenant-authorization review, guest checkout especially | **RUNNING** | `tenant-authz-reviewer`, asked specifically whether `$store` can ever be caller-supplied on any path into `handleCustomerRegistration` — because if it can, I would have replaced an unscoped lookup with one scoped by request input, which is worse |
| **3.** account selection, archived accounts, recovery across stores | **DONE** | `SameEmailAcrossStoresRecoveryTest` — 7 tests, 21 assertions, driven through the **real broker**. The reset key is `customer:<id>`, not the address; a token minted for one store's account is **refused** for the other's, in both directions; two simultaneous resets stay independent; an archived account is **not resolvable**; and each store's credentials resolve only that store's account, checked against **different passwords** so it measures selection rather than coincidence |
| **4.** database-level enforcement on real MySQL | **DONE, and it refutes my earlier claim** | `verify-customer-email-uniqueness-on-mysql.php` — **17 checks, 0 failed on MySQL 8.0.46**. §2 |
| **5.** concurrency and existing duplicate data | **DONE** | §2: exactly one of two racing inserts survived, loser SQLSTATE 23000; the ALTER is **refused** on a dirty table; the pre-flight query finds the offending group and not legitimate archived pairs |
| **6.** present the exact implementation and migration impact | **THIS DOCUMENT** | §6 below. **Nothing is applied** |

---

## 6. The exact implementation and migration impact — for approval

### Step 1 (precondition, **already done**) — scope the two lookups

`PaymentController.php` guest checkout and `HomeController.php::order_track()` each gained
`->where('store_id', $store->id)`, using a `$store` already in scope. Committed, 7 tests, 3 bites,
authz review in flight. **No payment behaviour changed** — amounts, gateways, totals, wallet,
coupons and transitions are untouched; only which `customers` row a lookup may return, and it can
only narrow.

### Step 2 — the application rule, one line

`app/Http/Controllers/CustomerController.php`, the `email` rule in `store()`:

```php
// from
Rule::unique('customers', 'email')->whereNull('deleted_at')
// to
Rule::unique('customers', 'email')->where('store_id', $store)->whereNull('deleted_at')
```

`$store` is the already-resolved `getCurrentStore()` value, never request input — and since issue
#84's fix, `store()` refuses an operator who has named no store, so `$store` is always explicitly
chosen by the time the rule is built.

### Step 3 — rewrite the pinning test

`CustomerCreateFormTest::test_another_stores_live_email_is_still_refused_which_is_the_open_question`
inverts: another store's live address is **accepted**; a live duplicate **in my own store** is still
refused. Its current message already says this is what should happen.

### Step 4 — the migration, and **this is the part with real impact**

**Recommended: Route B**, the functional unique index. No column added, so no row grows and no
`SELECT *` changes shape — which matters with 79 `Cachable` models.

```php
// up()
DB::statement("
    CREATE UNIQUE INDEX customers_email_store_live_unique
      ON customers ((IF(deleted_at IS NULL, CONCAT(email, '|', store_id), NULL)))
");
```

**It must be preceded by a pre-flight check that THROWS, naming the offending rows** — following the
GATE F pattern (`GateFSchemaGuard::assertCompatible()` throws rather than trusting `hasTable()`):

```sql
SELECT email, store_id, COUNT(*) c FROM customers
 WHERE deleted_at IS NULL GROUP BY email, store_id HAVING c > 1;
```

**MIGRATION IMPACT, stated plainly:**

1. **It can fail on production data, and that is correct.** The ALTER is refused on live duplicates
   — measured, with the offending key named in the error.
2. **Case and accent folding may block it for a product reason, not a technical one.** Measured: the
   index folds both. If production holds `Alice@x.com` and `alice@x.com` as two separate **live**
   customers in one store, somebody must decide **which of two real people owns the address**. That
   cannot be automated and is not a schema step.
3. **`Customer::create()` can now throw a duplicate-key `QueryException`** where it previously
   succeeded, because the engine closes a window validation cannot. **All six writers in §1 must
   catch it and render the same validation message a user would otherwise have seen.** This is
   required work, part of the proposal, not a surprise after it.
4. **Index size:** one more unique index on `customers`, over an expression of roughly
   `email` + 1 + `store_id`. No column, no row growth under Route B.
5. **Rollback:** `DROP INDEX` is a clean no-data-loss reversal — unlike the GATE F drafts, where a
   `down()` that dropped a column destroyed 45 `cost_price` values. Nothing here removes data.
6. **Route A is the fallback** if production is older than 8.0.13, and it *does* add a STORED
   column, with the row-growth and cache consequences that implies.

**NOT VERIFIED, and it gates scheduling:** production's MySQL **version, collation and `sql_mode`
are not established in this repository** — `docs/development/HANDOFF-CURRENT.md:136` says to confirm
them. Everything above was measured on **MySQL 8.0.46** in a throwaway database in this container.
**The script must be re-run against production, and the pre-flight query run against production
data, before the migration is scheduled.** The dev fixture has **3 customers and no duplicates of
any kind**, so it is no evidence about production whatsoever.

### Step 5 — what is explicitly NOT in this proposal

- **No change to the `addCustomer` API rule**, which is already per-store — so this *closes* the
  web ↔ Flutter divergence on the cross-store axis rather than widening it.
- **The archived axis of that divergence stays.** The web form allows an archived address; the API
  does not. A second, separate decision.
- **No change to any token, broker or password flow.** Safeguard 3 pins that.
- **The storefront's own archive dead end is not fixed here** (§4 of
  `P25-SECURITY-FINDINGS-2026-11-07.md`) — it is a storefront authentication path and belongs in
  its own slice.

---

## 7. What remains open even if this is approved

- **The existence oracle narrows but does not close.** A merchant still learns whether an address is
  a customer **in their own store**, which is information they are entitled to. The platform-wide
  bit goes away.
- **The archived web ↔ Flutter axis**, above.
- **Production verification** of version, collation, `sql_mode` and duplicate data.
