# Proposal: scope customer e-mail uniqueness to `(email, store_id)`

**STATUS, 2026-11-07, after the owner's conditional decision.** Two halves, and they are not in
the same state:

| half | state |
|---|---|
| **the application-level policy** | **APPROVED in principle and now IMPLEMENTED.** The owner: *"You may implement the application-level policy after completing the tenant-authorization review and confirming that the payment and storefront lookups are properly store-scoped."* Both conditions were met first. §6 Steps 2–3 |
| **the database migration** | **NOT AUTHORISED. Nothing is written and nothing is applied.** The owner: *"Database migration is NOT yet authorized. First obtain the production MySQL version, collation, index definitions and duplicate-data assessment."* That evidence is now in §2; the plan, impact and rollback are in §6 Step 4, **awaiting a separate decision** |

Written in response to the owner instruction of 2026-11-07, item 4: *"The proposed rule is
uniqueness by `(email, store_id)`, consistent with the reported existing writers. Verify database
constraints, archive/re-registration behavior and authentication implications. Present the exact
proposal for my approval before changing the business policy."*

**The approved policy, in the owner's own three clauses:** the same e-mail **may** exist in
different stores; two **active** customers with the same e-mail in **one** store must **not** be
allowed; **archived** customers **may** re-register safely. Together: `(email, store_id)` unique
over **live rows only**.

**Two corrections of my own are recorded in this document rather than quietly edited out** — §2
holds both. The first was the owner's: I claimed database enforcement was impossible, and it is
not. The second was mine, after obtaining the engine: I recommended a route that **does not exist
on production's database**.

---

## 1. What is inconsistent today, measured

**This section describes the state BEFORE the change, which is what justified it.** The three
merchant write paths disagreed with each other on **both** axes of one operation:

| merchant write path | per-store? | archived-aware? |
|---|---|---|
| `POST /customer` — `CustomerController::store()`, the web form | **NO, global** | yes |
| `POST api/admin/AddCustomer` — the shipped admin app | yes | **NO** |
| `POST api/admin/update-customer` — the shipped admin app | yes | **NO** |

So the merchant web form and the Flutter app gave **opposite answers** to the same question, which
is what CLAUDE.md's second standing principle (Flutter parity) forbids. **All three now read
`(email, store_id)` over live rows**, and a parity test asserts the web and API answers agree rather
than assuming it.

`CustomerController::store()` was the **only** writer of `customers.email` enforcing uniqueness
**globally**. Every other first-party writer already scoped it to the store:

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

**Two consequences of the inconsistency, both live until this change:**

1. **A merchant could not add their own customer** if that address happened to belong to a different
   merchant's store. Normal use, prevented.
2. **A platform-wide existence oracle.** A merchant holding `Manage Customer` learned whether any
   address is a customer **anywhere on the platform**, one address per request. It is now scoped to
   their own store, which is information they are entitled to.

**It changed nothing about existing data**, and that is measured rather than assumed: the dump holds
**0 addresses live in more than one store** (§2), so no row's status changes — only what is accepted
next.

---

## 2. Database constraints — I was wrong TWICE, and both corrections are measured

**Correction 1 (the owner's).** My first version said database-level enforcement was impossible
because MySQL has no partial-index syntax. That was wrong, and the owner's instruction — *"Do not
claim enforcement is impossible merely because MySQL lacks native partial-index syntax"* — was
right. The reason is **NULL semantics**: a `UNIQUE` index permits any number of rows whose indexed
value is `NULL`, so an expression that is `NULL` for archived rows and `(email, store_id)` for live
ones *is* `UNIQUE (email, store_id) WHERE deleted_at IS NULL`.

**Correction 2 (mine, after obtaining the engine).** My second version recommended **Route B**, a
functional unique index, and said Route A was only a fallback "if production is older than 8.0.13".
**Both statements were wrong, because production is not MySQL at all.**

### The production engine, established without touching production

`storesyria_ecommercego.sql` is **tracked in this repository** (20,470,785 bytes — this is security
incident #120, the database-dump exposure, and it is a separate matter from this proposal). Its own
header records the server that produced it:

```
-- phpMyAdmin SQL Dump
-- version 5.2.3
-- Host: localhost:3306
-- Serverversie: 11.4.9-MariaDB
-- PHP-versie: 7.4.33
```

**Production is MariaDB 11.4.9** — and **this repository already said so, in two places, and I did
not read either before recommending a MySQL-only feature.** That is the more useful correction, so
it is recorded rather than smoothed over:

* `docs/GATE-F-VERIFIED-SCHEMA.md:58` states the source server as **"MariaDB 11.4.9 — this
  project's notes assume MySQL"**, measured on a restored copy on 2026-10-24. It carries the exact
  warning I then walked into.
* `.github/known-test-outcomes.json` says the GATE F structures were measured *"on a restored copy
  of the production dump (2026-10-24, MariaDB 11.4.9)"*.
* And `docs/development/HANDOFF-CURRENT.md:136` says the opposite is still open — *"every MySQL
  claim below was measured on MySQL 8.0.46 only — confirm production's version, collation and
  `sql_mode`"* — which is why the question read as unanswered.

So three routes now agree, two of them pre-existing and one measured here from the dump's own
header. **`sql_mode` remains the one property none of them establishes.**

The `customers` DDL from the same dump:

```sql
CREATE TABLE `customers` (
  `email` varchar(255) NOT NULL,
  `store_id` bigint(20) UNSIGNED DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  ...
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `customers` ADD PRIMARY KEY (`id`), ADD KEY `customers_store_id_index` (`store_id`);
```

**There is no unique index on `customers.email`.** Uniqueness is validation only, today and after
the application change.

**MariaDB has never implemented functional indexes, at any version** — so **Route B is withdrawn
entirely.** It is not a fallback and it is not an option. **Route A is the only route.** The
verification script's own version gate was a real flaw for the same reason: it compared version
numbers, and MariaDB 11.4 is numerically above 8.0.13, so a pure number comparison called Route B
"supported" on an engine where it cannot work. It now detects the vendor.

### Measured on MariaDB 11.4.9 — production's own version

`tests/Support/Security/bin/verify-customer-email-uniqueness-on-mysql.php` — **22 checks, 0
failed**, transcript at
`docs/development/phase-2.5/evidence/pkg-e-customer-write/mariadb-11.4.9-email-uniqueness.txt`. The
throwaway database and table are created with **production's declared `utf8mb4` /
`utf8mb4_unicode_ci`**, not the server defaults (MariaDB 11.4 defaults to `utf8mb4_uca1400_ai_ci`),
so the collation findings are about production's collation.

### Route A — a STORED generated column plus a unique index

```sql
ALTER TABLE customers
  ADD COLUMN email_store_live VARCHAR(300)
    GENERATED ALWAYS AS (IF(deleted_at IS NULL, CONCAT(email, '|', COALESCE(store_id, 0)), NULL)) STORED;

ALTER TABLE customers
  ADD UNIQUE INDEX customers_email_store_live_unique (email_store_live);
```

Three things in that statement are corrections, each one measured:

1. **`COALESCE(store_id, 0)`, not `store_id`.** Production's `store_id` is
   `bigint(20) UNSIGNED DEFAULT NULL`, and **`CONCAT()` returns `NULL` if any argument is `NULL`**
   — so the naive expression is `NULL` for a row with no store, and a `NULL` indexed value is
   *exempt from the unique index*. Every such row would escape the constraint silently. Measured
   side by side in section C2: with the naive expression, **two live rows with `store_id NULL` and
   the same address were BOTH accepted**; with `COALESCE` the second is rejected (SQLSTATE 23000),
   and a `NULL` store is still treated as a different tenant from store 1. The dump holds **0 live
   customers with a NULL `store_id` today**, so nothing is exempt right now — the hole is what a
   future `NULL` would do, without a sound.
2. **`VARCHAR(300)`, not `VARCHAR(220)`.** Production's `email` is `varchar(255)`; 220 could not
   hold `email` + `'|'` + a store id.
3. **Two statements, not one.** Same end state; the difference is the error message. Measured in
   section G2: the single-statement form, on a table that holds archived rows, reports
   `Duplicate entry 'NULL' for key ...` — **which is not the colliding value** and would send an
   operator to the wrong rows during a production migration. Adding the column first and the index
   second reports `Duplicate entry 'c@x.test|1'`, naming the address that actually has to be
   resolved.

**All of the policy's properties hold on MariaDB 11.4.9:**

| property | Route A on MariaDB 11.4.9 |
|---|---|
| two LIVE rows, same address, **same** store | **rejected** (SQLSTATE 23000) |
| two LIVE rows, same address, **different** stores | allowed |
| three **ARCHIVED** rows, same address and store | allowed (NULL semantics) |
| archive, then re-add the same address | allowed |
| …and a second live row after the re-add | still rejected |
| two LIVE rows, same address, **both `store_id` NULL** | **rejected** (only with `COALESCE`) |
| a `NULL` store vs. store 1, same address | allowed — still different tenants |

### Does the NULL exemption survive the index BUILD, not just inserts?

**Yes — asked because section E raised it, and answered by execution rather than by reasoning.** The
production dump holds **37 archived customers**. If the index *build* treated their `NULL` generated
values as colliding, the migration would be refused for a reason no pre-flight query could find.
Measured in section G1: **three archived rows sharing one address did not block the ALTER**, and all
three carried a `NULL` generated value. The exemption holds at build time.

### Migration impact — the ALTER algorithm, measured

| `ALGORITHM=` | MariaDB 11.4.9 |
|---|---|
| `INSTANT` | **refused** — "not supported for this operation. Try ALGORITHM=COPY" |
| `NOCOPY` | **refused** — same |
| `INPLACE` | **refused** — same |
| `COPY` | accepted |

**A full table rebuild is forced.** A `COPY` rebuild holds a lock and blocks writes to `customers`
for its duration. The dump holds **62 customer rows in total**, so today that is milliseconds — but
the figure belongs in the plan, because it scales with the table and `customers` is the platform's
tenant-facing customer table.

### What this changes about the race — and a REQUIRED application change

**The engine closes the window the application cannot.** Measured: two connections racing the same
insert, **exactly one survived**, and the loser was refused with SQLSTATE 23000. Laravel's
`validate()` is check-then-act and cannot close that; the index can.

**So the migration is not purely additive.** With the index in place, `Customer::create()` can throw
a duplicate-key `QueryException` where it previously succeeded. **Every writer of `customers.email`
must handle that** and turn it into the same validation message a user would otherwise have seen.
The writers are listed in §1. This is work, and it is part of the proposal rather than a surprise
after it.

### Existing data — the ALTER refuses a dirty table, which is the safe direction

Measured: against a table already holding live duplicates the ALTER **fails**. **So the migration
cannot be applied blindly** — and that is correct behaviour, not an obstacle. The pre-flight query,
which belongs **in the migration** as a check that throws with the offending rows named:

```sql
SELECT email, store_id, COUNT(*) c FROM customers
 WHERE deleted_at IS NULL GROUP BY email, store_id HAVING c > 1;
```

Measured: it finds exactly the offending group and does **not** flag archived rows sharing an
address, which are legitimate.

### The duplicate-data assessment the owner asked for

Run by `tests/Support/Security/bin/assess-customer-email-duplicates.php` over the committed dump.
**It streams the file, hashes every address immediately and prints counts only — no address, name,
id or row, and there is deliberately no verbose mode.**

| measure | count |
|---|---|
| live customers | **25** |
| archived customers | **37** |
| exact `(email, store_id)` duplicate groups among live rows | **0** |
| case-insensitive duplicate groups among live rows | **0** |
| addresses live in more than one store | **0** |
| live customers with a `NULL` `store_id` | **0** |

**So on the data in the dump the migration would succeed with nothing to resolve first.** Two
caveats that are not decoration: a dump is **a point in time**, so the query must be re-run against
production immediately before any schema change; and *because* there are 0 addresses live in more
than one store, the application change in §6 **changes nothing about the existing data** — it only
changes what is accepted next.

### COLLATION — the finding most likely to block this, and it is not a schema question

**The index folds case AND accents** under `utf8mb4_unicode_ci`. Measured on MariaDB 11.4.9:
`Case@x.test` and `case@x.test` in one store **collide and the second is rejected**; so do
accent-different pairs.

That is consistent with how the application already compares addresses, but it means:

> **If production holds `Alice@x.com` and `alice@x.com` as two separate LIVE customers in one
> store, the migration will refuse until somebody decides which of them owns the address.** That is
> a product decision about two real people's accounts, not a schema step, and it cannot be
> automated.

The pre-flight query uses the same collation, so it finds those pairs — and **it found 0 of them in
the dump.**

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

**THE PRECONDITION IS MET.** Both now carry `store_id`, and the owner's condition — *"confirming
that the payment and storefront lookups are properly store-scoped"* — was satisfied before a line of
the policy changed. `UnscopedCustomerEmailLookupTest`, 6 tests.

**The two are not equally live, and the distinction is kept rather than flattened.**
`PaymentController` (now `:2095-2096`) was a **cross-tenant WRITE**: the `else` branch wrote
`last_active` to the row it found and returned it as the order's customer. That is a live repair.
`HomeController` (now `:1229-1230`) is **latent**: the same shape, but the test establishes by
measurement that `orders` has **no `email` column**, so `$order->email` is always null and the
lookup never runs — the predicate added there is consistency, and fixes nothing reachable. Its
docblock says so outright, and says it therefore has no failing-test coverage and cannot have any.

A fourth review round re-read both and found neither to be a cross-tenant path any more.

**And a third site exists that is NOT first-party, so it is recorded rather than repaired:**
`packages/workdo/SignInWithSlack/.../SignInWithSlackController.php:102-106` resolves a customer by
e-mail with **no store predicate** and then calls `Auth::guard('customers')->login()`. It is
vendored (CLAUDE.md forbids editing `packages/workdo/` as a shortcut), module-gated, and not an
escalation — `->first()` returns the **older** row, so a planted duplicate loses to the victim's
existing row. See `P25-SECURITY-FINDINGS-2026-11-07.md` §5.5.

---

## 5. Safeguard status — what is done, measured, and by what

| owner safeguard | state | evidence |
|---|---|---|
| **1.** fix the two unscoped lookups | **DONE** | `UnscopedCustomerEmailLookupTest` — 7 tests, **3 bites**. `PaymentController` was a **cross-tenant WRITE**, not just a wrong read: the `else` branch writes `last_active` to the row it found and returns it as the order's customer. `HomeController`'s is **unreachable dead code** — `orders` has no `email` column — which is a correction to my own earlier description |
| **2.** independent tenant-authorization review, guest checkout especially | **DONE — three rounds, and it found three faults in my own work** | `tenant-authz-reviewer`. Round 3 caught (a) an **overclaim**: I had said `order-status.blade.php:29` causes a shopper-facing 500, which **cannot fire** because `Order.php:519` sets `name = $bi_f_name . ' ' . $bi_l_name`, always containing a space, and `empty(" ")` is `false` — the test was deleted and the claim withdrawn; (b) a **premise that could not detect its own condition**: it asserted `$order->email` is null, which is true because `email` is not in `Order::$fillable` regardless of the column, so it was changed to `Schema::hasColumn('orders','email')`; (c) that the `HomeController` change has **no failing-test coverage and cannot have any**, now stated plainly in its own docblock rather than implied |
| **3.** account selection, archived accounts, recovery across stores | **DONE** | `SameEmailAcrossStoresRecoveryTest` — 7 tests, 21 assertions, driven through the **real broker**. The reset key is `customer:<id>`, not the address; a token minted for one store's account is **refused** for the other's, in both directions; two simultaneous resets stay independent; an archived account is **not resolvable**; and each store's credentials resolve only that store's account, checked against **different passwords** so it measures selection rather than coincidence |
| **4.** database-level enforcement on the real production version | **DONE on MariaDB 11.4.9 — production's own engine — and it refuted my claim twice** | `verify-customer-email-uniqueness-on-mysql.php` — **22 checks, 0 failed**. First the owner's correction (NULL semantics make it possible), then my own (production is MariaDB, so Route B is impossible and `COALESCE` is required). §2 |
| **5.** concurrency and existing duplicate data | **DONE** | §2: exactly one of two racing inserts survived, loser SQLSTATE 23000; the ALTER is **refused** on a dirty table; the pre-flight query finds the offending group and not legitimate archived pairs. Existing data assessed from the committed dump without touching production: **25 live, 37 archived, 0 duplicate groups of any kind, 0 addresses live in more than one store, 0 live rows with a NULL store_id** |
| **6.** present the exact implementation and migration impact | **THIS DOCUMENT** | §6 below. **Nothing is applied** |

---

## 6. The exact implementation and migration impact — for approval

### Step 1 (precondition, **already done**) — scope the two lookups

`PaymentController.php` guest checkout and `HomeController.php::order_track()` each gained
`->where('store_id', $store->id)`, using a `$store` already in scope. Committed, 7 tests, 3 bites,
authz review in flight. **No payment behaviour changed** — amounts, gateways, totals, wallet,
coupons and transitions are untouched; only which `customers` row a lookup may return, and it can
only narrow.

### Step 2 — the application rule. **IMPLEMENTED** (owner approved the policy in principle)

`app/Http/Controllers/CustomerController.php`, the `email` rule in `store()`:

```php
// from
Rule::unique('customers', 'email')->whereNull('deleted_at')
// to
Rule::unique('customers', 'email')->where('store_id', $store)->whereNull('deleted_at')
```

`$store` is the already-resolved `getCurrentStore()` value, never request input — and since issue
#84's fix, `store()` refuses an operator who has named no store, so `$store` is always explicitly
chosen by the time the rule is built. A test asserts that a `store_id` in the **request body**
changes nothing.

**AND THE TWO ADMIN-APP PATHS CHANGED IN THE SAME SLICE, which is the point rather than a side
effect.** §1 showed the three merchant write paths disagreeing on *both* axes, so fixing only the
web form would have left the shipped Flutter app giving the opposite answer — against CLAUDE.md's
second standing principle:

```php
// Api\DashboardController::addCustomer()      (POST api/admin/AddCustomer)
// Api\DashboardController::updateCustomerInfo() (POST api/admin/update-customer)
// both were per-store but archived-INSENSITIVE; both gained:
    ->whereNull('deleted_at')
```

In both, the ownership resolution happens **above** `validate()`, and `$storeId` at the rule is
taken from the resolved `$store->id` rather than from raw caller input.

**I claimed that of both handlers before it was true of both, and the review of this change caught
it.** `updateCustomerInfo()` always reassigned `$storeId = $store->id` (`:9156`). `addCustomer()`
did **not**: the caller's own `$request->query('store_id')` reached the rule, the `Customer::create`
and the `DeliveryAddress::create`. It was not exploitable — `resolveOwnedStore()` matched
`Store::where('id', $storeId)`, so the value identifies the store that was checked, and
`required|integer|exists:stores,id` rejects the shapes that could make the two queries disagree —
but the sentence was false, so the line has now been added. It is hardening and it says so: there
is no input that behaves differently before and after, so it has no bite test, and claiming one
would be the same error in a different place.

**Evidence:** `tests/Feature/Security/CustomerEmailUniquenessPolicyTest.php` — **15 tests, 36
assertions**, covering all three clauses on all three paths, plus the parity assertion between the
web form and the admin app, plus a test that states the race the policy does **not** close. Each of
the three edits was **reverted individually** and re-measured:

| edit reverted | tests that fail |
|---|---|
| web form per-store predicate | `an address live in another merchants store may now be created here`, `…agree about ANOTHER STORES LIVE address` |
| `addCustomer()` live-rows-only | `an archived address may be reused on the api`, `…agree about an ARCHIVED address` |
| `updateCustomerInfo()` live-rows-only | `an update onto an address only an archived row holds is allowed` |

**Re-measured after the review's fixes, not carried forward** — and the result changed, which is the
point of re-running it. The review found that the single parity test covered only the ARCHIVED axis
(the pre-change web rule was global but already live-only, so it accepted an archived address too)
and therefore passed whether or not the per-store predicate existed. With the second parity case
added, reverting the web form's predicate now fails **two** tests instead of one.

So every edit bites, and no test passes because of a different edit.

**WHAT IS NOT CHANGED, and why.** Three paths resolve a customer address and were left alone,
because the owner's instruction in the same message is *"No silent payment behavior or
authentication policy changes"* and all three decide who may hold a **login credential**:

| path | state today | why not changed |
|---|---|---|
| `Api\AuthController:76` — `unique:customers,email`, **global** | live on the routed, public `POST api/{slug}/register` | scoping it changes who may create a storefront login. **Needs its own owner decision** |
| `Customer\Auth\CustomerLoginController` registration existence check | soft-delete-blind: an archived shopper is told to log in and cannot | storefront authentication path; pinned by `StorefrontArchivedCustomerDeadEndTest` |
| `AccountProfileController:127` and `:183` | per-store but archived-insensitive | **stricter** than the policy, i.e. failing safe. Loosening it would let a live shopper take an address an archived account holds, and a restore would then produce the state clause 2 forbids. No restore route exists today, so this is latent, not live |

### Step 3 — rewrite the pinning test. **DONE**

`CustomerCreateFormTest::test_another_stores_live_email_is_still_refused_which_is_the_open_question`
said in its own message that if it ever started failing the decision had landed and it was what
should be rewritten. It did, and it is now
`test_another_stores_live_email_is_now_accepted_because_the_owner_decided`: another store's live
address is **accepted**, the new row lands in **this** merchant's store, and the other store's row
is asserted unchanged — a create, not an adoption. A live duplicate **in my own store** is still
refused, by a separate test that passed before and after.

### Step 4 — the migration. **NOT AUTHORISED, NOT WRITTEN, and here is exactly what it would be**

The owner's instruction is explicit: *"Database migration is NOT yet authorized."* **No migration
file exists in this change.** This section is the plan being presented for a decision, not a
description of something applied.

**Route A is the only route**, because production is MariaDB 11.4.9 and MariaDB implements no
functional indexes. **Two statements, so a refusal names the real address:**

```php
// up()  -- after the pre-flight check below has passed
DB::statement("
    ALTER TABLE customers
      ADD COLUMN email_store_live VARCHAR(300)
        GENERATED ALWAYS AS (
          IF(deleted_at IS NULL, CONCAT(email, '|', COALESCE(store_id, 0)), NULL)
        ) STORED
");
DB::statement("
    ALTER TABLE customers
      ADD UNIQUE INDEX customers_email_store_live_unique (email_store_live)
");
```

**It must be preceded by a pre-flight check that THROWS, naming the offending groups** — following
the GATE F pattern (`GateFSchemaGuard::assertCompatible()` throws rather than trusting
`hasTable()`), and for the same reason: two GATE F drafts dropped a column *because* it existed and
a rollback destroyed 45 `cost_price` values.

```sql
SELECT email, store_id, COUNT(*) c FROM customers
 WHERE deleted_at IS NULL GROUP BY email, store_id HAVING c > 1;
```

**ROLLBACK.** `down()` is the exact inverse and destroys no data, because the column it drops is
**generated** — every value in it is derived from `email`, `store_id` and `deleted_at`, all of which
remain:

```php
// down()
DB::statement("ALTER TABLE customers DROP INDEX customers_email_store_live_unique");
DB::statement("ALTER TABLE customers DROP COLUMN email_store_live");
```

That is the one respect in which Route A is *safer* than it looks: a GATE F `down()` had to be a
documented no-op because dropping a real column loses what was in it. Here there is nothing to
lose. The rollback still forces a second `COPY` rebuild.

**MIGRATION IMPACT, stated plainly:**

1. **A full table rebuild is forced.** `ALGORITHM=COPY` is the only algorithm MariaDB 11.4.9 accepts
   for this operation — `INSTANT`, `NOCOPY` and `INPLACE` are all refused, measured. Writes to
   `customers` are blocked for the duration. **62 rows today**, so milliseconds; the figure is in
   the plan because it scales.
2. **The row grows.** Route A adds a STORED column of up to 300 characters to every `customers`
   row. `Customer` is `Cachable`, so cached payloads widen too. This is the cost Route B would have
   avoided, and Route B does not exist on this engine.
3. **It can fail on production data, and that is correct.** The ALTER is refused on live duplicates.
   The dump shows **0 duplicate groups of any kind**, so it would currently succeed — but a dump is
   a point in time and the pre-flight query must be re-run against production immediately before.
4. **Case and accent folding may block it for a product reason, not a technical one.** Measured: the
   index folds both under `utf8mb4_unicode_ci`. The dump holds **0 such pairs**. If production has
   gained one since, somebody must decide **which of two real people owns the address**. That cannot
   be automated and is not a schema step.
5. **`Customer::create()` can then throw a duplicate-key `QueryException`** where it previously
   succeeded, because the engine closes a window validation cannot. **All writers in §1 must catch
   it and render the same validation message a user would otherwise have seen.** Required work,
   part of the proposal.
6. **A `NULL` `store_id` is folded onto `0`** by `COALESCE`, so all NULL-store rows in one "tenant"
   share a namespace. There are **0 live such rows** today. Without `COALESCE` they would be exempt
   from the constraint altogether, which is worse — measured side by side in section C2.

**WHAT IS STILL NOT ESTABLISHED, and it gates scheduling:**

- **production's `sql_mode`** — a dump does not record it. It affects neither NULL index semantics
  nor the forced algorithm, but it is not measured, and I will not claim it is.
- **production's data *now*** — everything above about duplicates comes from the committed dump,
  not from the live table.
- **Production was not accessed** and *"Do not access production without separate authorization"* is
  the standing instruction. Re-running the script and the pre-flight query against production
  requires that authorisation, separately from authorising the migration itself.

### Step 5 — what is explicitly NOT in this change

- **No migration, and no schema change of any kind.** Step 4 is a plan awaiting a decision.
- **No change to any token, broker or password flow.** Safeguard 3 pins that.
- **No change to the three storefront authentication paths** listed in Step 2 — `AuthController`'s
  global register rule, the soft-delete-blind registration existence check, and the shopper's own
  profile rule. Each needs its own owner decision because each decides who may hold a login.
- **The storefront's own archive dead end is not fixed here** (§4 of
  `P25-SECURITY-FINDINGS-2026-11-07.md`) — same reason.
- **Security incident #120 is untouched.** This proposal *reads* the committed dump as evidence; it
  does nothing about the fact that the dump is committed. That is a separate matter and UI or
  validation work does not remediate it.

---

## 7. What remains open now that the application policy is in

- **The check-then-act race is still open, and that is not concealed.** With no unique index, two
  concurrent creates of one address in one store can both succeed. Measured: with the index exactly
  one survives (§2, section D). Without it, nothing stops the second. There is **one test that says
  so in the suite** rather than only here —
  the failure message of
  `CustomerEmailUniquenessPolicyTest::test_premise_the_database_has_no_unique_index_on_customer_email`,
  which really can fail. (An earlier draft named a dedicated test for this; it was deleted for being
  an `assertTrue(true, '…')` that could not fail, and the citation was left behind.)
- **The existence oracle narrows but does not close.** A merchant still learns whether an address is
  a customer **in their own store**, which is information they are entitled to. The platform-wide
  bit is gone.
- **Three storefront authentication paths** still disagree with the policy (Step 2 table). Two are
  looser than it (`AuthController`'s rule is global; the registration check is soft-delete-blind,
  producing a dead end), one is stricter (`AccountProfileController`). Each is an owner decision.
- **`Api\DashboardController::store()` (line ~6283) is a second, UNROUTED copy of `addCustomer()`**
  carrying the same ownership-after-validation ordering that was the e-mail oracle. **It is not
  reachable** — 115 `DashboardController` routes and none maps to `store` — so it is a trap for
  whoever routes it, not a live hole. My earlier commit said the oracle was "fixed"; that was true
  of `addCustomer()`, and this records the copy it did not touch.
- **Production verification** of `sql_mode` and of duplicate data *as it stands now*, both of which
  need separate authorisation to access production.
