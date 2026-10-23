---
name: store-syria-laravel-tenancy
description: Use when writing or reviewing any Laravel code in Store Syria that touches authorization, tenancy, a controller, a policy, an Eloquent query, a route's middleware, an order, a payment, or any handler that reads an identifier from request input. Holds the counter-intuitive facts of this codebase that have each produced a real defect, and the test disciplines that catch them. Read before adding a controller, policy, middleware or query scope.
---

# Store Syria — Laravel, tenancy and authorization

First-party and committed, so it travels with the repository. It exists because `CLAUDE.md` used to
delegate this to an account-level `laravel-best-practices` skill that **does not exist in every
environment** — three of the four skills that file named were absent when the project moved
machines, and the two that survived were the two committed here.

This is not generic Laravel idiom. Generic idiom is not what breaks this codebase; the local facts
below are, and each one has produced a measured defect.

## 1. Authorization

**Laratrust, not spatie, and `permissions_as_gates => false`.** So `@can('Some Permission')` is
**always false**. Use `$user->isAbleTo('Permission Name')`. In Blade, Laratrust's `@permission`
works — it resolves through `hasPermission()` and never touches Laravel's Gate — so `@permission`
and `@can` are *not* interchangeable here.

**There is no super-admin bypass.** Operator status is a string comparison on `users.type`.
`isAbleTo()` alone **refuses the platform operator**, so the operator limb comes **first**:

```php
if ($this->isPlatformOperator($user)) { return $next($request); }   // FIRST
if ($permissionIsEnforceable && ! $user->isAbleTo($permission)) { return $this->deny(...); }
```

Reversing those two is a lockout, not a tightening — `App\Http\Middleware\FinancePermission`
records it happening once already for six unseeded finance names.

**A permission that no row exists for can be held by no role**, so gating on it is a permanent 403.
Check the row exists before enforcing. Each vendored module seeds its own permissions from its own
`PermissionTableSeeder`, which the enable path runs — `Utility::addNewData()` is the only **host**
mechanism, not the only one. (Assuming otherwise shipped a real within-tenant gap.)

## 2. Tenancy

Shared database, `store_id` discriminator, with an allowlist in `config/tenancy.php`'s
`scoped_models`. **Absence from that allowlist is what decides** — `StoreScope::isActiveFor()`
short-circuits for a model that is not in it, even if the model carries `BelongsToStore`.

`Product`, `Order`, `Category`, `Coupon` and every `Workdo\*` model add **no predicate for any
principal**. `null_tenant => 'open'`, so when no tenant resolves the scope adds nothing at all.

**Never let the scope be the only tenant check.** And a predicate built from caller-supplied input
(`$request->store_id`) is not a tenant check — it compares the caller's own two values.

## 3. The pattern that has produced the most defects

> Authenticate a principal, then act entirely on request input.

A **presence** test is not an **identity** test. `if (! auth('customers')->user()) { … }` proves
*some* customer of *some* store is signed in, and nothing more. Derive the target from the
authenticated principal; never from request input.

`CustomAuth` logs a token's owner into whichever guard matches its tokenable class, so a merchant's
or driver's token passes `custom.auth` while `auth('customers')` stays null — and a handler shaped
`if (! auth('customers')->user()) { $id = $request->some_id; }` takes the attacker-controlled
branch. **A customer-token probe cannot test this**: a `Customer` token takes the safe branch, so a
test that drives only one proves nothing. Drive a merchant and a driver too.

## 4. Which request key does the handler read?

A guard that authorises one row while the handler mutates another is not a guard. Read the
handler's own source line:

- `Request::__get` reads `all()` **before** route parameters, so `$request->id` resolves to the
  **posted body** when both are present.
- A handler taking `$id` as a **method argument** (`update(Request $request, $id)`) reads the
  **route parameter**, and a body key cannot change it.

Those are opposite answers to the same question. Get it from the code, not from the route
signature.

**Parse identifiers strictly.** `is_numeric` is not enough: it accepts `'5.9'` and rejects
`'5abc'`, while MySQL coerces `'5abc'` to row 5 in a `WHERE id = ?` comparison. Use
`filter_var($v, FILTER_VALIDATE_INT)`.

**Ask about the rows, not the values.** For a set predicate, run the handler's own query *without*
a store filter and refuse if any matched row is foreign. Verifying that the requested *values* are
owned is a different question, and MySQL's case- and accent-insensitive default collation separates
them.

## 5. Vendored modules

**Never edit `packages/workdo/`.** An in-place edit is reverted by the next upstream update and
leaves no patch record. `LynomiaChat` is the one exemption. Enforced by a `PreToolUse` hook locally
and by `guard-no-workdo-changes.sh` in CI, which is the authority.

A fix that seems to need a module file belongs in the host app — a policy, FormRequest, query
scope, or **middleware appended to the `web` group**:

```php
$middleware->appendToGroup('web', \App\Http\Middleware\YourGuard::class);
```

Not a route re-declaration. Module route files load from `app->booted()` — *after* `routes/web.php`
— and `RouteCollection` keys on method+domain+uri, so the module's declaration wins the slot and
your middleware silently disappears. The group registration also survives `route:cache`, because
the cache stores declared middleware *names* unexpanded.

Row existence in `add_on_managers` is what loads a module. `loadDynamicAddons()` plucks modules with
**no `is_enable` predicate**, so **"disabled" is not containment** — a disabled row still registers
every route.

## 6. Tests that actually bite

**After writing a regression test, revert the fix and confirm the test fails.** This has caught
vacuous tests repeatedly. Four ways they pass for the wrong reason, all measured in this project:

1. **The endpoint 500s for everyone**, so "the row didn't change" holds either way. And a handler
   that `catch (\Exception)`es its own failure returns **200**, so even `assertNotSame(500, …)` is
   not a liveness check. Assert the **refusal** — a 403 the guard alone produces.
2. **The route does not exist.** A row assertion is satisfied by a 404. Assert
   `assertNotSame(404, …)` too.
3. **A precondition was never met**, so the handler was never reached: a missing plan entitlement
   (`PlanModuleCheck`), a missing `add_on_managers` row, a validator refusing first, a fixture row
   the write path is gated on. Assert the premise in the fixture.
4. **The engine hides it.** SQLite's affinity and BINARY collation hide real MySQL defects — a
   string in an INTEGER column, NULL into NOT NULL under `strict => false`, and case/accent-
   insensitive `whereIn`. Evidence that cannot live in the suite goes in
   `tests/Support/Security/bin/` as a script stating its own preconditions.
5. **`actingAs` tightened the scope the test was meant to measure.**
   `actingAs($customer, 'customers')` also calls `shouldUse('customers')`, so `auth()->user()` —
   which `StoreScope::resolveTenant()` calls, web-guard-only by design — starts returning the
   **Customer**. It cannot tell, falls through `getCurrentStore()` to
   `Store::where('created_by', $user->id)`, and resolves a tenant for a shopper. Measured:
   `OrderRefund::where('id', $id)` compiled with `and "order_refunds"."store_id" = ?` attached
   where a real request (default guard `web`, no principal → `MODE_UNRESOLVED` → `open`) attaches
   nothing. So a **direct-call** test on the `customers` guard runs under a *tighter* scope than
   production, and the global scope silently substitutes for the handler's own predicate —
   removing that predicate then changes nothing the test can see. In a direct-call test, put the
   default guard back: `$this->app['auth']->shouldUse('web')`, then assert `auth()->user()` is
   null. Over HTTP the kernel sets the guard itself, so this applies to direct calls only.

**Assert through `DB::table()`.** `User`, `Product` and `Order` are `Cachable`; an Eloquent read can
serve a mutated in-memory instance. That produced a false *reproduction* once — a model read
reported a hijacked row while the raw row was untouched.

**Strip comment lines from any source-grep ratchet.** A docblock quoting the string a ratchet greps
for makes the ratchet satisfiable by prose. That has happened here.

## 7. Censuses fail in a specific way

Four times now, a completeness claim missed what it claimed to cover — each time because it was
keyed on *spelling* rather than on the handler:

1. keyed on uri **words** → missed four document routes
2. keyed on `Route::post` **lines** → missed `Route::resource`
3. keyed on the uri **prefix** → would miss a prefixed re-declaration
4. an **exemption scoped to the methods somebody happened to look at** → said "update and destroy
   are stubs", true, while the live defect was in `store()`

Key on `$route->getActionName()`. State what was read **and what was not**. Add a reachability
assertion, so a rename cannot make the scan pass vacuously.
