# P0 — account deletion: a cross-tenant destructive authorization defect, and a cascade behind it

**Status: the authorization half is FIXED and pinned. The cascade half is an OPEN RELEASE
BLOCKER awaiting an owner decision. Nothing is merged to `main` and nothing is deployed.**

Raised under the owner's Priority 0 of 2026-11-10. No destructive test was run against production
or real merchant data; everything below is read from source, from the committed schema dump's
**schema section**, or measured on a disposable in-memory test database.

---

## 1. The headline, and it is not `/profile`

The directive named `DELETE /profile`. Investigating it found a **worse** path that shares the
same cascade:

```php
// app/Http/Controllers/UserController.php — BEFORE
public function destroy(User $user)
{
    if (auth()->user() && auth()->user()->isAbleTo('Delete User')) {
        Setting::where('created_by', $user->id)->delete();
        $user->delete();
```

* The target is **bound from the URL** (`DELETE /users/{user}`).
* The only gate was a **permission**, which every merchant holds for their own staff.
* **`User` is not tenant-scoped.** `config/tenancy.php`'s `scoped_models` holds six models —
  `Menu`, `SupportTicket`, `SupportConversion`, `FlashSaleCondition`, `ThemeCustomizeOrder`,
  `NotifyUser` — and `User` is not among them, so the global scope adds no predicate and
  route-model binding reaches every tenant's users.
* There is a **live button**: `resources/views/users/index.blade.php:124` opens a DELETE form to
  `users.destroy`.

This is the defect **SEC-F4 already fixed on `update()`** — *"checked only the 'Edit User'
permission … and then operated on `User::findOrFail($id)` with no ownership test at all, so any
merchant could edit any user on the platform"* — left open on the one operation that cannot be
undone.

**Verified directly** (not inferred): the route table shows exactly one `DELETE users/{user}` →
`UserController@destroy`; `scoped_models` parsed to six entries with no `User`; the button exists;
`UserPolicy` had `update()`, `resetPassword()` and `impersonate()` but **no `delete()`**.

### Reproduced before fixing

`tests/Feature/Security/UserDeletionAuthorizationTest`, run against the unfixed tree:

```
✓ premise the store cascade is real in this database
⨯ merchant cannot delete a user in another tenant
⨯ merchant cannot delete another tenants staff
⨯ merchant cannot delete a platform admin
✓ merchant may still delete their own staff
⨯ the platform operator may still delete a merchant staff user
```

The last one is a second, quieter defect: `isAbleTo()` alone **refuses the platform operator** in
this codebase (no super-admin bypass), so support could not delete anybody either.

### Fixed

`UserPolicy::delete()` now exists, mirroring `update()` limb for limb so the two cannot drift —
operator first, then permission, then *no-one below operator touches an operator*, then same
tenant. `UserController::destroy()` calls `$this->authorize('delete', $user)` as `update()` (:259)
and `resetPassword()` (:360, :374) already do. All six cases pass; four of them fail with the fix
reverted.

### One trap worth recording, because it nearly sent me to rewrite a correct policy

`SecurityTestCase::makeMerchant()` hardcodes `created_by => 1`, and the **first** merchant a test
creates is itself id 1. Two `makeTenant()` calls therefore produce an attacker with id 1 and a
victim with `created_by = 1` — and `sameTenant()` reads them as one tenant, **correctly**, because
by the data the victim really was created by the attacker. That is not production, where both
merchants are created by the platform admin and neither *is* it. The test now consumes id 1 with a
filler row, and asserts the two are not related before proceeding.

---

## 2. The cascade — the open blocker

`users` has no soft delete, no `deleting` hook and no observer, so `$user->delete()` is a hard
`DELETE` and the schema decides the rest.

**Verified here:** the premise test asserts, against the live test database, that
`pragma foreign_keys` is on and that `stores` declares a foreign key to `users` with
`ON DELETE CASCADE`. Both hold, so the regression tests are not vacuous.

**Reported by the investigation, measured against the committed dump's schema section** (sha256
matched the digest recorded in `docs/GATE-F-VERIFIED-SCHEMA.md:55-56`, so it is the same artefact
GATE F measured) — these figures are *theirs*, not independently re-derived here:

* **15** foreign keys reference `users(id)`; **all 15 are `ON DELETE CASCADE`**.
* **12** further reference `stores(id)`; **all 12 CASCADE**.
* Transitive closure: **27 tables destroyed**, 171 of 198 survive.
* **Seven are money tables**: `store_wallets`, `store_wallet_transactions`, `store_withdrawals`,
  `store_order_financials`, `delivery_earnings`, `currency_rates`, `plan_orders`.
* Of the schema's **89** foreign keys exactly **one** is not CASCADE, and **none** is `RESTRICT` —
  so the database will never refuse the delete.
* The inverse is worse: `orders`, `order_billing_details`, `customers`, `products`, `carts`,
  `personal_access_tokens`, `role_user` and `permission_user` carry no foreign key to `users` or
  `stores` and **survive as orphans**. The per-order accounting row is destroyed while the order
  remains — revenue that can no longer be reconciled.
* Replayed over the dump's own rows for the one merchant with any trade: **515 rows destroyed**
  across 14 tables, including a wallet balance in the hundreds of thousands and all 92 per-order
  accounting rows, leaving **7,258 orphaned rows** including all 97 orders.

### What is NOT fixed, and why

The repair above answers *may this principal delete this row*. It does **not** refuse deleting a
user who **owns stores** — a merchant removing their own tenant root still takes the cascade with
them.

That is a change to destructive-data behaviour, not an authorization repair, and the directive
reserves it: *"Any change to account-deletion business behavior or destructive data handling
requires explicit owner approval before deployment"* and *"Do not redesign the account deletion
system or change database relationships as part of Phase 2.5."* So it is **recorded as a release
blocker, not decided here**.

**The options, for the owner, in increasing order of change:**

1. Refuse deletion of a store-owning user in `UserPolicy` (fail-closed, no schema change).
2. Soft-delete `users` (schema change; CLAUDE.md's GATE F rules apply).
3. Require explicit tenant tear-down first, as `StoreController::ownerstoredestroy` already does
   for decision **D14**.

---

## 3. `DELETE /profile` itself

Lower severity than the above, and the directive's warning — *"Do not assume that hiding the
account-deletion UI protects the endpoint"* — is exactly right.

* Live and uniquely declared at `routes/web.php:451`.
* Gated by a `web` session **and** CSRF **and** the caller's own password. **Self-target only** —
  no id is read from the request — so there is no cross-principal path here.
* **No UI anywhere.** The only form posting to `profile.destroy` is
  `profile/partials/delete-user-form.blade.php`, included only by `profile/edit.blade.php`, which
  `ProfileController@edit` no longer renders (it redirects — this branch's own earlier fix).
* But it **executes**, and the suite proves it: `tests/Feature/ProfileTest.php:124`
  `test_user_can_delete_their_account` drives a crafted `DELETE /profile` and asserts the row is
  gone. **That test is green and undeclared.**

### Three further findings on this route, none actioned

| | |
|---|---|
| **Unthrottled** | no `ThrottleRequests` in the resolved stack, so `current_password` is an unlimited confirmation oracle for anyone holding a session. |
| **Password mangled before validation** | `App\Http\Middleware\XSS` rewrites the `password` field before the rule reads it — measured, `my <script>alert</script> pass` is compared as `my  pass`. The credential check is evaluated against a mangled string. |
| **The existing test blocks the obvious repair** | any fail-closed guard on `/profile` turns `test_user_can_delete_their_account` red. It is **not** in `.github/known-test-outcomes.json`, so that breaks the required gate unless the test is rewritten in the same commit. It is green today only because `UserFactory` creates a **childless** user — the blast radius is empty, so the cascade is invisible, not absent. |

---

## 4. Testing on a disposable database

The directive asked for regression tests against a disposable database, and the investigation
settled a question that would otherwise have produced a vacuous suite:

* **SQLite in this suite DOES enforce `ON DELETE CASCADE`.** `config/database.php:39` defaults
  `foreign_key_constraints` to true and Laravel issues the pragma at connection construction, so
  `pragma foreign_keys` reads 1. A cascade assertion here is real.
* **But a bare `new PDO("sqlite:…")` reads 0**, so a standalone probe script measures the wrong
  thing and would wrongly conclude there is no cascade.
* **A foreign key added by `Schema::table()->foreign()` after table creation is silently absent on
  SQLite.** The `users` cascade escapes that only because it is declared inline inside
  `Schema::create`. This is why the premise is asserted per-run rather than assumed.
* **No MySQL server exists in this container**, so the throwaway-MySQL half of the strategy
  (`tests/Support/Security/bin/verify-sec13-migration-on-mysql.php` is the pattern) cannot run
  here today.

---

## 5. Three doors, one cascade

A guard on one door is not a mitigation. All three reach `users.delete()`:

| door | status |
|---|---|
| `UserController::destroy` — `routes/web.php:454`, live button | **authorization FIXED** |
| `ProfileController::destroy` — `routes/web.php:451`, no UI | self-only; cascade open |
| `StoreController::destroy` | not reviewed in this pass |

If the owner approves a cascade guard, it belongs in **one shared service** called by all three —
the pattern `App\Services\VariantRemovalPolicy` already sets, and whose own docblock records that
a per-door copy was caught once in review.
