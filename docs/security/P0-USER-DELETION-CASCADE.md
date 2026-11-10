# P0 — account deletion: a cross-tenant destructive authorization defect, and a cascade behind it

**Status: BOTH halves are now implemented and pinned — authorization and fail-closed data
safety — under the owner's decision of 2026-11-10. Nothing is merged to `main` and nothing is
deployed.**

> **Owner decision, 2026-11-10:** *"I approve a bounded, fail-closed mitigation … Under no
> circumstances may deleting a user silently destroy a store, subscription, financial ledger,
> wallet, transaction history, or other business-critical data."*

**One limitation is carried deliberately and is reported in §6.**

> **§7 is a SECOND and worse finding, turned up by the review of this one and fixed here:** any
> merchant could take over any other merchant's account in two ordinary requests, because
> `created_by = 1` was being read as a tenant. Pre-existing; measured end to end through the real
> routes before a line of it was changed.

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


---

## 6. The implemented mitigation (owner decision, 2026-11-10)

### `App\Services\UserDeletionSafety`

One shared service, called by every door, answering a question `authorize()` does not:
**is deleting this row safe at all?** It is *not* an authorization control and refuses the
platform operator exactly as it refuses a merchant — an operator deleting a store owner destroys
the same wallet.

`blockers(User $user)` returns the reasons a hard delete is refused, or `[]`. It checks:

| | |
|---|---|
| **store ownership** | first, because it is the single edge that turns a row-delete into a tenant wipe, and refusing on it short-circuits the whole second hop |
| **15 CASCADE dependencies** | every foreign key to `users(id)` in the production schema — subscriptions, plan requests, plan coupons, delivery boys, email templates, brands, labels, questions, enquiries, notifications, refund settings, contact verifications, sidemenu order |
| **5 orphan dependencies** | `orders`, `customers`, `products`, `order_notes`, `settings` — tables with **no** foreign key, which survive pointing at a row that is gone |

**The list is pinned, not discovered at runtime.** A guard that reflects over the live schema
silently narrows itself on any database missing a table, and in this project *the migrations are
not the schema*. `UserDeletionSafetyCoverageTest` reads the production dump's schema section and
asserts the list in **both** directions — every production cascade is covered, and every pinned
entry is a real production foreign key. A new cascade added to production and not added here
**fails that test** rather than quietly escaping the guard.

A table absent from the live schema is skipped, because a row cannot be lost from a table that is
not there; the coverage ratchet is what keeps the *list* complete. The alternative — refusing
every deletion wherever any listed table is missing — would disable staff management on every
fresh environment (`product_enquiries` and `sidemenu_order` are in production and in no migration)
to guard against a row that cannot exist.

### Race safety

The check and the delete are **one transaction with the row locked**:

```php
DB::transaction(function () use ($user, &$blockers) {
    $locked = User::whereKey($user->getKey())->lockForUpdate()->first();
    if ($locked === null) return;
    $blockers = UserDeletionSafety::blockers($locked);
    if ($blockers !== []) return;
    $locked->delete();
});
```

A check-then-delete is a race: a store created between the count and the `DELETE` would be
destroyed by a guard that had just proved there was none. On MySQL `lockForUpdate()` takes a row
lock for the transaction's duration; on SQLite it compiles to nothing, but SQLite serialises
writers, so the window is closed on both.

### The three doors

| door | authorization | data safety |
|---|---|---|
| `UserController::destroy` — `DELETE users/{user}`, live button | `UserPolicy::delete()` | `UserDeletionSafety` |
| `ProfileController::destroy` — `DELETE /profile`, no UI | self-target only + password + CSRF | `UserDeletionSafety` |
| `StoreController::destroy` — `DELETE stores/{store}`, **no UI** | operator only | `UserDeletionSafety` |

`Api\DashboardController` was checked and is **not** a fourth door: it deletes a `DeliveryBoy`,
not a `User`, and is already guarded by `resolveManageableDeliveryBoy()`.

**`ProfileController::destroy` had a second defect fixed here:** `Auth::logout()` ran *before*
`$user->delete()`, so a refusal would have signed the merchant out of an account that still
exists. The logout now runs after the delete.

### What independent review changed, 2026-11-10

The project's `tenant-authz-reviewer` was run on the finished change before it was committed, as
CLAUDE.md §6 requires. It confirmed the census — **exactly three doors**, verified by its own
greps rather than by taking this document's word, with no fourth in `packages/workdo/`, no raw
`DB::table('users')->delete()`, no model hook, and **no inbound foreign key to `users` at all** in
the production schema, so no delete of any other row can take a user with it. It also confirmed
`CASCADE_DEPENDENCIES` against three sources rather than one: the dump, `database/migrations`
(12 CASCADE FKs, all 12 listed) and `packages/workdo/` (3 more, all 3 listed).

Six things it found that this document or the code claimed wrongly, all now fixed:

| | |
|---|---|
| **a dead constant naming a real gap** | `STORE_MONEY_TABLES` was declared with a docblock saying it covered "a user who owns no store but is named on a store's money rows" — and `blockers()` never read it. Each of the four tables was then measured: two have no user-bearing column, and **two do**. `store_wallet_transactions.created_by` and `store_withdrawals.requested_by` are both `bigint` with **no foreign key**, both written from `auth()->id()`, both carrying live rows in production. They are now `ORPHAN_DEPENDENCIES` entries, along with `lynomia_platform_notices.requested_by`, found by the same sweep. A constant nothing calls is not a check; it is a paragraph that tells an auditor a control exists when it does not. |
| **the third door had no transaction** | `StoreController::destroy` was a plain check-then-delete while the other two doors locked the row. Same transaction and lock now. |
| **the race claim was too broad** | See below. |
| **an exemption ratchet that could not fail** | `CREDENTIAL_KEYS` was pinned by `str_contains($key, 'password') \|\| str_contains($key, 'token')`, which a future `api_token` or `password_hint` would pass. Pinned exactly now. |
| **a premise measuring its own copy** | `CredentialSanitisationTest` re-declared the sweep's four substrings instead of reading them. `XSS::STRIPPED_SUBSTRINGS` exists so it reads the real list. |
| **an operator-protection case with two limbs** | `test_merchant_cannot_delete_a_platform_admin` passes with `UserPolicy.php:187` deleted, because the attacker and the operator are in different tenants anyway. `test_operator_created_staff_cannot_delete_the_operator` removes that second limb — an attacker the operator created is rooted at the operator, so `sameTenant()` admits them — and it is now the only case that fails when the limb is removed. Measured in both directions. |

### Race safety: what the lock closes, and what it does not

The first version of this document said the window was "closed on both". It is not, and the line
runs along the same split the two dependency lists are built on:

* **Closed for the 15 `CASCADE_DEPENDENCIES`** — InnoDB takes a *shared record lock on the parent
  row* while checking a foreign key, so `INSERT INTO stores (created_by = X)` blocks behind our
  exclusive lock on user X. That, and not `lockForUpdate()` reaching into the child table, is the
  mechanism; it works only because all 15 have a real FK.
* **Open for the `ORPHAN_DEPENDENCIES`** — those tables are in that list *precisely because they
  carry no foreign key*, so an insert into one takes no lock on `users`. T1 locks user X and
  counts zero orders; T2 inserts `orders.created_by = X` and commits; T1 deletes. The order is
  orphaned.

The window needs the target user to be writing during their own deletion, which is narrow, and it
is real. There is no cheap lock that covers an FK-less table, so it is **reported rather than
closed**.

### A behavioural consequence that should be said out loud

`settings` is an `ORPHAN_DEPENDENCIES` entry, and `settings.created_by` is written with the tenant
root's id. **So every tenant admin is now permanently undeletable through all three doors**, and
the only accounts any of them can remove are staff rows with nothing attached.

That is exactly what the owner's fail-closed decision asks for, and it is still a shipped change
in behaviour. It is written here rather than left for a merchant to discover. The two
`Setting::where('created_by', …)->delete()` calls inside the transactions are consequently
unreachable; they are kept, annotated, as the correct cleanup if `settings` ever leaves that list.

### A gap in the ratchet's own coverage

`UserDeletionSafetyCoverageTest` skips when `storesyria_ecommercego.sql` is absent — deliberate,
and better than passing. But **removing that dump is the planned remediation for P1-SEC-40 (#120)**,
and a skip in the *Unit* suite is covered by no outcome gate: `.github/known-test-outcomes.json`
asserts the outcome set for the Feature log only. On the day the dump goes, the only thing keeping
`CASCADE_DEPENDENCIES` honest stops running with no CI signal. Recorded against #120.

### THE LIMITATION, reported as the owner asked

**`DELETE stores/{store}` now always refuses.** It deleted a store by deleting its *owner*, and a
store owner always owns a store, so the guard always fires. Per the instruction — *"If
implementing a safe guard requires a breaking business decision, keep deletion disabled for the
affected cases and report the limitation. Never proceed with destructive behavior."*

This costs **no shipped workflow**: a repo-wide grep finds no view linking to `stores.destroy`; it
is reachable only by a crafted request. The merchant's own store removal is a different route with
a real UI — `ownerstore-remove` (`AppSetting/index.blade.php:285`) — and that one deletes the
**store**, preserving orders and plan orders under decision **D14**.

If the operator needs to remove a store, that capability now needs a path that deletes the store
rather than its owner. **That is a product decision and is not taken here.**

### The childless-user test no longer stands as evidence

`ProfileTest::test_user_can_delete_their_account` was green only because `User::factory()` creates
a user with no store, no subscription and no orders — the blast radius was empty, so the cascade
was invisible rather than absent. It is renamed
`test_a_dependency_free_user_can_delete_their_account` and now **asserts its own scope**: that the
user owns no store and that `UserDeletionSafety::blockers()` is empty. If the factory ever changes,
the premise fails loudly instead of the case quietly becoming a test of something else.

### Credential sanitisation and throttling

Both reported in §3 and both fixed:

* **`XSS` no longer rewrites credentials.** It ran
  `str_replace(['&lt;','&gt;','javascript','alert'], '', $value)` over every string in every
  request. Verifying, `current_password` compared a mangled string; **setting**, a registration or
  reset *stored* one — a credential the user has never seen. A pinned `CREDENTIAL_KEYS` list is
  now exempt, and a test asserts a **non**-credential field is still swept, so the fix cannot
  become "the sweep is off".
* **`DELETE /profile` is throttled** at `throttle:6,1`, the bound `routes/auth.php:45,49` already
  use. Laravel keys it on the authenticated user, so one merchant cannot exhaust another's budget.

### The two raw statements, and why they are not scoped

`blockers()` counts with the query builder — `DB::table('stores')->where('created_by', $user->id)`
and `DB::table($table)->where($column, $userId)` — and neither carries a `store_id` predicate. Four
tenancy ratchets failed on that, correctly, and the resolution was to **declare both with the
argument** rather than to scope them:

> Everywhere else in those censuses a missing `store_id` predicate means a statement can see too
> much. Here a predicate would mean it sees too **little**. A dependant the guard fails to count is
> a dependant the delete destroys, so a scoped count turns a refusal into a silent cascade — the
> failure direction is inverted.

Both are counts keyed on the target user id, both can produce only a refusal naming a number, and
`UserPolicy::delete()` has already refused a cross-tenant target before either runs. `users` is not
in `config/tenancy.php`'s `scoped_models` either, and `stores` keys on `created_by` — a user id —
not on a `store_id` of its own.

The declarations live in `ServiceLayerRawQueryInventoryTest::STORE_LESS_BY_DESIGN`; the moved
counts are recorded in both census files with the date and the reason, and **nothing was added to
`.github/known-test-outcomes.json`**.

### Tests

`tests/Feature/Security/UserDeletionAuthorizationTest` — **15 cases**, covering every class the
owner listed: cross-tenant attempts (owner and staff shapes), missing permission, operator
protection, store-owner deletion, financial dependencies, orphan dependencies, dependency-free
staff deletion, self-deletion through `/profile`, the third door, and one case asserting stores,
orders and subscription records are all **still there** after a refused deletion.

`tests/Feature/Security/CredentialSanitisationTest` — **6 cases**, including a premise that the
sweep really would have mangled the chosen password.

`tests/Unit/Security/UserDeletionSafetyCoverageTest` — **4 cases**, the pinned-list ratchet.

**Proved in both directions.** With the guard reverted, 5 of the 15 deletion cases fail — exactly
the data-safety ones — and 3 of the 6 credential cases fail. The authorization, premise and
legitimate-path cases correctly pass either way.

---

## 7. P0-TENANT-ROOT — a separate, worse finding the review turned up

**Status: CONFIRMED by measurement, and FIXED here. Pre-existing — nothing in the deletion change
introduced it; the deletion change is what made somebody read `UserPolicy::sameTenant()`.**

### Any merchant could take over any other merchant's account, in two requests

`User::creatorId()` returns `$this->id` for type `admin` / `super admin`, and `created_by` for
everything else. Self-registration writes `['type' => 'admin', 'created_by' => 1]`
(`RegisteredUserController` :124/:133, :261/:269), so **every merchant on the platform carries
`created_by = 1`**. A principal whose type is anything else therefore resolves `creatorId()` to 1,
and `sameTenant()`'s two limbs then matched every merchant on the platform plus user 1 itself.

A merchant could put themselves in that state **using the product as designed**. Each line below
was driven through the real HTTP route and the real controller, not reasoned about:

```
attacker id=2 type=admin created_by=1              creatorId() = 2
POST a role named "Manager" in their own store     (an ordinary merchant capability)
PUT /users/{self} with role=<it>   -> 302          type now "Manager"
                                                   creatorId() = 1      <- collapsed
sameTenant(attacker, ANOTHER MERCHANT)  = true
UserPolicy::update(attacker, that merchant) = true
PUT /users/{that merchant}                         their email is now the attacker's
```

`UserController::update` derives `type` server-side from a tenant-**scoped** role (:299-306),
which is the right shape, and `RolePolicy::RESERVED_NAMES` blocks only the two `admin` spellings —
so `Manager` is accepted and the demotion is a supported action rather than a bypass. Owning
another merchant's email address owns every password-recovery flow. The same predicate also gated
`resetPassword()`.

### The fix, and its blast radius

`sameTenant()` now asks whether the row the actor's tenant resolves to is **a merchant** — a
type-`admin` user. `User::creatorId()` says the same thing from the other side: a legitimate
tenant root is `admin` or `super admin`, and `super admin` is the platform rather than a tenant.
An actor rooted at the platform is in **no** tenant rather than in everybody's.

It cannot refuse the operator: `update()`, `resetPassword()` and `delete()` all admit
`isPlatformAdmin($actor)` before reaching `sameTenant()`, and an actor acting on their own row
returns true first.

Who loses authority, stated so the radius is not a guess: **only a non-operator whose `creatorId()`
is not a merchant** — a self-demoted merchant (the attack) and a staff row the *operator* created.
The second belongs to no merchant tenant; treating it as a member of every tenant was the defect.
A merchant resolves to their own id and is unaffected; their staff resolve to the merchant and are
unaffected.

### The first version of the fix was too narrow, and the suite caught it within the hour

It asked "is the root the **operator**". In a fixture where the collapsed actor's own id equalled
their `created_by`, the root resolved to the **actor** — not the operator, so the narrow check
passed it — and the limbs matched every row carrying that `created_by`. Asking whether the root is
a *merchant* closes that and the operator case together. Recorded because the narrow version
looked right and had a green test beside it.

### Proved in both directions

`tests/Feature/Security/TenantRootIsNotThePlatformTest` — 8 cases. Every one drives an HTTP route
and asserts a **row**, not a predicate return, because the question is "can one merchant change
another merchant's email", not "what does the function say". With the repair reverted, three
redden; the three legitimate-path cases (a merchant editing own staff, staff editing a sibling,
the operator editing a merchant) pass either way, which is what stops the fix being "refuse
everything".

The delete case passes either way too, and the file says so: the victim is a merchant, a merchant
owns a store, and `UserDeletionSafety` refuses a store owner whatever the policy decided — the
right outcome reached by the wrong layer. It is kept as the case that notices if the data-safety
guard is ever narrowed, and labelled so it is not read as evidence the policy was correct.

### Reported, not fixed: `UserPolicy::update()` has the wrong ordering

`update()` tests `isAbleTo('Edit User')` **before** `isPlatformAdmin($actor)` (`:130` then `:134`).
Its two siblings do the opposite. CLAUDE.md records why operator-first is the right order here —
Laratrust's `permissions_as_gates` is false and operator status is a string on `users.type`, so
**`isAbleTo()` alone refuses the platform operator**. Measured: an operator holding no Laratrust
role cannot edit any user through this route, and it behaves identically with the tenant-root
repair reverted.

It is a lockout rather than a hole, and reordering it would hand every operator edit rights they
do not hold today. **That is a product decision and is left to the owner.**

---

## 8. Owner decisions of 2026-11-10 (closure instruction), and what they changed

### 8.1 Operator user-editing stays behind an explicit permission — DECIDED, not a defect

§7 reported that `UserPolicy::update()` tests `Edit User` **before** operator status, unlike its
three siblings, so an operator holding no Laratrust role cannot edit any user. The owner's
decision:

> *"Do not grant operators unrestricted user-editing privileges. Preserve least-privilege
> authorization. Use existing explicit roles and permissions. Do not reorder the policy merely to
> bypass a permission denial."*

So the asymmetry is a **control**, and it is now pinned as one:
`CrossTenantPolicyGridTest::test_an_operator_without_edit_user_may_not_update_anyone` fails if
anybody reorders it, and its pair
`test_an_operator_holding_edit_user_may_still_do_the_work` fails if least privilege ever becomes
no privilege. The reported-defect framing in §7 is superseded by this.

**THE SCOPED CHANGE, written out for approval rather than made.** If a real operator workflow
needs user editing without hand-granting a role to each operator account, the smallest change that
does it — and the only one this document recommends — is:

```
  Create ONE Laratrust role, `platform-operator`, carrying exactly:
      Edit User          (so UserPolicy::update admits the operator)
  and nothing else. Assign it to accounts whose `users.type` is already `super admin`.
```

Why this and not a code change: it adds no bypass, it is visible in the roles UI, it is revocable
per account, and it leaves `update()`'s ordering — and therefore the control above — untouched.
What it does NOT do is grant `Delete User`, `Reset Password` or anything else; those three paths
already admit the operator first and need no grant, which is the asymmetry working as intended.

**Not implemented. It needs the owner's word, and it changes who can edit accounts.**

### 8.2 The target-is-operator test is now normalised on all four paths

The closure instruction asks to *"preserve cross-tenant protection across update, delete, password
reset and impersonation paths."* Measuring all four as one grid found that only `delete()` used
`UserPolicy::isOperator()`; the other three compared `$target->type` against the literal
`'super admin'`.

MySQL's `utf8mb4_unicode_ci` treats `'Super Admin'` and `'super admin '` as **equal** to that
literal while PHP's `===` does not — so a row typed either way was the operator to the database
and a stranger to `update()`, `resetPassword()` and `impersonate()`. Measured: a merchant could
update, reset the password of, and (as an operator) impersonate a `'Super Admin'` row.

All three now use the shared helper. **This is a tightening, not a reorder**: it can only refuse
more than it did, it admits nobody it previously refused, and it moves no operator check past a
permission check. `CrossTenantPolicyGridTest` drives all three spellings on all four paths, with a
premise asserting the target really is inside the attacker's tenant — without that premise the
first version of the case passed against the unrepaired policy, because `sameTenant()` refused it
on a limb the case was not testing.

### 8.3 Store deletion is a FUNCTIONAL LIMITATION, not a feature

> *"Document the current inability to delete stores as a functional limitation requiring a
> separate safe account/store lifecycle design. Do not treat permanent disabling as completed
> functionality."*

Recorded plainly:

**`DELETE stores/{store}` is disabled, and disabling it is not the same as solving it.** The route
deleted a store by deleting its **owner**, and a store owner always owns a store, so
`UserDeletionSafety::blockers()` always fires. Nothing links to the route and the merchant's own
store removal is a different route under decision D14 — so no shipped workflow is lost today — but
**the platform currently has no safe way for an operator to retire a store or an account.**

What that needs, and what this repair deliberately did not attempt:

| | |
|---|---|
| a store lifecycle | retire / suspend / archive as states on `stores`, distinct from deleting the owning `users` row |
| an account lifecycle | what happens to a tenant owner's row when the tenant is retired — the 15 cascade and 8 orphan dependencies decide this, not a flag |
| a data decision | which of orders, customers, products, wallet transactions, withdrawals and plan orders are retained, anonymised or exported, and for how long |
| an actor and an audit trail | who may retire a tenant, and what the record of it is |

None of that is a rendering or a guard change; it is a product design. **It is open, and it is not
Phase 2.5 work.** The guard is what stops silent destruction in the meantime, and the owner's
instruction is explicit that the guard must not be read as the feature.

### 8.4 What was re-verified for closure

The seven checks the closure instruction names, and where each is measured:

| check | where |
|---|---|
| cross-tenant user updates and deletions are rejected | `CrossTenantPolicyGridTest` (policy and routes), `UserDeletionAuthorizationTest` |
| password resets cannot target another tenant | `CrossTenantPolicyGridTest`, `CredentialBoundaryTest` |
| operator privileges remain explicitly controlled | `CrossTenantPolicyGridTest` §3, both directions |
| the tenant-root identity cannot be manipulated through role changes | `TenantRootIsNotThePlatformTest`, driven through the routes |
| store ownership and financial dependencies survive a rejected deletion | `UserDeletionAuthorizationTest::test_nothing_is_lost_when_a_deletion_is_refused` |
| plan-activation replay protection remains effective | `PlanActivationReplayGuardTest` |
| the fixes do not block legitimate staff management | `CrossTenantPolicyGridTest` §4, `UserDeletionAuthorizationTest`, `TenantRootIsNotThePlatformTest` |

92 cases across those six classes, all passing, on SQLite `:memory:` — a disposable database per
case through `RefreshDatabase`. No production or fixture database is touched by any of them.
