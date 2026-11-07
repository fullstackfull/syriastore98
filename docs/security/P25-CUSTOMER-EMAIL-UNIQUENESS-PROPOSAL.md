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

## 2. Database constraints — verified, and the answer changes the proposal

**There is no unique index on `customers.email`. Anywhere.**

- No migration declares one: `grep -c 'unique('` over every migration touching `customers` → **0**.
- Measured on the live schema (`database/p2dev.sqlite`, 133 tables): the only indexes on
  `customers` are `customers_billable_id_billable_type_index` and `customers_store_id_index`. The
  column is `"email" varchar not null` with no constraint.
- `tests/Feature/Security/ImportCustomerFailureReportingTest.php:69` already asserts this as a
  premise, because the import handlers depend on a duplicate being *impossible to throw*.

**So uniqueness is purely a validation rule today, with no database backing, and the race is
check-then-act.** `validate()` runs a `SELECT`, `Customer::create()` inserts, nothing between them,
and the engine cannot catch the loser. **That is true before and after this proposal** — the change
narrows which rows the `SELECT` counts; it does not touch the window.

**A filtered unique index is not available.** The invariant we would actually want is
`UNIQUE (email, store_id) WHERE deleted_at IS NULL`. MySQL **cannot express a filtered unique
index**, and a plain `UNIQUE (email, store_id)` would be *stricter than the application*: it would
reject a live row whose archived twin still exists, which is precisely the dead end `P2.5-PKG-E`
(E-1) removed, and would break writers 1, 2, 3 and 6 above.

**Recommendation on the schema: add no constraint in this change.** A persistent migration is owner-
gated in its own right, and the only correct constraint is one MySQL will not accept. Recorded as an
open item rather than smuggled in.

---

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

## 5. The exact proposal

**A. Precondition, before the policy changes** — scope the two unscoped lookups:

- `app/Http/Controllers/PaymentController.php:2070` → add `->where('store_id', $store->id)`
- `app/Http/Controllers/HomeController.php:1218` → add `->where('store_id', $store->id)`

Each with a reproduction that fails first, a cross-tenant test with a positive control, and the
`tenant-authz-reviewer` pass `CLAUDE.md` requires for a payment path.

**B. The policy change itself** — one rule in one handler:

- `app/Http/Controllers/CustomerController.php`, the `email` rule in `store()`:
  `Rule::unique('customers', 'email')->whereNull('deleted_at')`
  becomes
  `Rule::unique('customers', 'email')->where('store_id', $store)->whereNull('deleted_at')`

  `$store` is the already-resolved `getCurrentStore()` value, never request input. (Note that
  `store()` now also refuses an operator who has named no store — issue #84 — so `$store` is
  always an explicitly chosen store by the time the rule is built.)

**C. Rewrite the pinning test.** `test_another_stores_live_email_is_still_refused_which_is_the_open_question`
becomes its inverse: another store's live address is **accepted**, and a live duplicate **in my own
store** is still refused. Its message already says this is what should happen.

**D. No schema change** (see §2), and **no change to the `addCustomer` API rule**, which is already
per-store — so this closes the web ↔ Flutter divergence on the cross-store axis rather than widening
it.

**What this does NOT do:** it does not remove uniqueness, it does not touch password reset or any
token, it does not change the operator's rights, and it adds no database constraint.

---

## 6. What remains open even if this is approved

- **The check-then-act race** is unchanged and unfixable without a filtered unique index MySQL
  cannot express (§2).
- **The archived axis of the web ↔ Flutter divergence stays.** The web form allows an archived
  address (PKG-E); `Api\DashboardController::addCustomer` does not. Converging that is a second,
  separate decision.
- **The existence oracle narrows but does not close.** After this change a merchant still learns
  whether an address is a customer **in their own store**, which is information they are entitled
  to. The platform-wide bit is what goes away.
