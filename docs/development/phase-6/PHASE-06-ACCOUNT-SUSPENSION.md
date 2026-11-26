# Phase 06 — suspension revoked nothing, and three ways the fix for it went wrong first

**Status: FIXED.** Written after the patch, because this repository is public.

Directive item 2(A): *"account suspension must actually revoke/invalidate access including API
tokens and active sessions, verified through real authentication paths."*

---

## 1. What was measured

Suspension was enforced at exactly **two places in the whole application**, and both are login
**forms**:

| Principal | Flag(s) | Column | Enforced at | Anywhere else |
|---|---|---|---|---|
| `User` | `is_active`, `is_enable_login` | `varchar NOT NULL DEFAULT '1'`, `int NOT NULL DEFAULT '1'` | `Auth\AuthenticatedSessionController:68`, `:72` | **no** |
| `Customer` | `status` | `int NOT NULL DEFAULT '1'` | `Customer\Auth\CustomerLoginController:862`, `:890` | **no** |
| `DeliveryBoy` | **none** | — | — | — |

`CustomAuth` (`:60-91`) resolved the Sanctum token, checked only `expires_at` and that the tokenable
was non-null, then logged its owner in — and CLAUDE.md records that Sanctum tokens here **never
expire** (there is no `config/sanctum.php`), so `expires_at` is null for every token ever minted.

**So disabling an account stopped the next sign-in and nothing else.** An existing session kept
working; an existing token kept working indefinitely. The operator's only account-suspension control
had no effect on any client that had already signed in once.

**`delivery_boys` has no suspension column at all.** The real column list is `id, name, email,
profile_image, type, password, contact, created_by, store_id, created_at, updated_at,
commission_percentage, is_commission_enabled`. A driver therefore cannot be suspended. That is a
schema gap plus a product question, not something to invent here —
`AccountSuspensionPredicateTest::test_a_delivery_boy_has_no_suspension_column_to_enforce` asserts
the claim so that adding a column fails the test instead of silently admitting a suspended driver.

---

## 2. The fix

`app/Support/Accounts/AccountSuspension.php` answers one question — *may this principal act?* — per
tokenable type, mirroring each login form's own semantics. Two seams:

1. **`CustomAuth`**, before `auth()->login($tokenable)`. One chokepoint for all three token types, so
   a fourth tokenable cannot be added without passing it.
2. **`SuspendedAccountGuard`** on the `web` group, so an existing session is ended too.

**Revoke = refuse, not delete.** A refusal makes the token and session unusable while the account is
suspended and costs nothing to undo. Deleting `personal_access_tokens` rows is irreversible, forces
every client of that account to sign in again afterwards, and on a GET would mean a cached or retried
request silently destroying credentials. Which the product wants is **an owner decision** and is in
the confidential handoff; the refusal is needed either way.

**401 is correct and is not a new regression.** CLAUDE.md warns that the shipped Flutter app forces a
logout on any 401, so a new 401 on a path a client calls is user-visible — but for a *suspended*
account a forced logout is the intended outcome, and no non-suspended path gains a 401.
`CustomAuth::unauthenticated()` is reused, so the JSON shape the app parses at repo-B
`lib/network/network.dart:285-288` is unchanged.

### Two deliberate divergences from the login form

**The operator exemption is normalised.** The form compares `type != 'super admin'` raw; this uses
`strtolower(trim(...))`, exempting more spellings. Fail-safe on purpose: an operator refused on
*every request* cannot sign in to undo it, and CLAUDE.md records a letter-case reconciliation defect
(P5-R9) as a real one here. The cost of being permissive is bounded to "a row whose `type` spells
super admin in another case keeps access" — and that row is the platform operator by definition.

**The form's third check is NOT mirrored per-request.**
`AuthenticatedSessionController:72`'s second limb is `$companyUser->is_enable_login == 0`, where
`$companyUser = User::where('id', $user->created_by)->first()` — the **creator's** flag, with no
operator exemption on that limb. Enforcing it per-request would be wrong specifically:

- every first-party creation site writes `created_by = 1` (`RegisteredUserController:133`, `:286`,
  `StoreController:280`) and user 1 is the operator, so it would consult the **operator's own flag on
  every authenticated request by every merchant** — one row's flag as a platform-wide kill switch.
  At the login form it only blocks a new sign-in;
- CLAUDE.md records reading `created_by` as authority as the exact trap that produced an account
  takeover here. Amplifying its blast radius from "one login" to "every request" is the wrong
  direction;
- it is already redundant where it matters: `UserController:915` cascades `is_enable_login` to the
  store's non-admin users **directly**, so a disabled merchant's staff have their own flag at 0.

Stated as a difference rather than hidden. If the owner wants it per-request, it is a one-line
addition — but it needs that decision first.

---

## 3. Three ways this fix was wrong before it was right

Each was caught by execution, not by reading, and each is worth more than the fix itself.

### 3.1 The first version answered HTTP 500 — worse than the defect

`appendToGroup('web', …)` puts a guard at the **end** of the group, and `Authenticate` is in
Laravel's middleware **priority** list while a new guard is not. So the sort ran `Authenticate` at
position 6 and the guard at 31: `auth` passed on a principal the guard then logged out, and the
controller ran with `auth()->user()` null. On `/dashboard` that is a null dereference —
**HTTP 500 instead of a redirect**. The probe that found it printed `auth('web')->check() === false`
next to `status: 500`.

### 3.2 The priority anchor has to be the *interface*, and the wrong one fails silently

`prependToPriorityList(\Illuminate\Auth\Middleware\Authenticate::class, …)` matches **nothing**:
Laravel's priority list holds `\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests` — the
interface the concrete middleware implements. `addToMiddlewarePriorityBefore()` then falls back to
**appending at the end**, so the guard landed at position 12 of 13 and the 500 persisted with no
error to say the registration had not taken. Found by printing the live priority list.

Anchored on the interface, the guard sits at position 6 with the auth entry at 7, and the suspended
request 302s to `/login` — which is also why the guard needs no redirect logic of its own: the
framework already knows where to send an unauthenticated visitor, per guard.

### 3.3 The predicate would have signed out most of the platform

The first version mirrored the form literally: `$user->is_enable_login == 0`. **In PHP 8 `null == 0`
is true**, and the NOT NULL default applies to the stored **row**, not to an in-memory model —
`User::factory()` sets neither flag. So an ordinary **active** merchant read as suspended.

Caught because a **control** failed before any suspension was applied: an active merchant fixture was
redirected to `/login`. Probed rather than adjusted, per this project's rule. The blast radius, had
it reached the suite: **425 Feature test files and 3,114 `actingAs(` call sites**, none of whose
fixtures set either flag — and in production, any handler holding a partially-selected `User`.

The fix is that **a missing attribute is not a suspended account**: `flagSays()` requires the
attribute to be present *and* equal to 0. A null can only mean "not loaded on this instance", because
the column is NOT NULL. The deliberate consequence — a principal whose flag is genuinely unknown is
admitted — is the right direction for a control whose false positive is "nobody can use the
platform", and it opens nothing: a suspended row carries `0`, never null, and both seams load the
full row.

**Why copying the form was not enough:** `AuthenticatedSessionController` only ever sees a row it
just loaded with `User::where('email', …)->first()`, so its flags are always the stored 1 or 0. This
class is called on whatever instance a caller holds, which is a strictly wider contract.

---

## 4. Evidence

Driven through **real authentication paths**, as the directive requires: a real `createToken()`
against a real `custom.auth` route, and a real session against a real web route. Every case has its
**control in the same test** — "suspended gets 401" proves nothing alone, since the route might
answer 401 for an unrelated reason or the fix might refuse everybody.

**12 mutations applied, 12 CAUGHT:**

| Mutation | |
|---|---|
| `CustomAuth` no longer checks suspension | CAUGHT |
| the `CustomAuth` check does not cover customers | CAUGHT |
| drop the operator exemption entirely | CAUGHT |
| the operator exemption compares raw, as the form does | CAUGHT |
| `flagSays` drops the null guard — **§3.3, the outage** | CAUGHT |
| the customer branch drops its presence check | CAUGHT |
| the customer branch never suspends | CAUGHT |
| the web guard stops logging the principal out | CAUGHT |
| the priority anchor reverts to the concrete class — **§3.2, the 500** | CAUGHT |
| the guard only checks `web`, not `customers` | CAUGHT (after the gap below) |
| the guard only checks `customers`, not `web` | CAUGHT |
| the guard's `SESSION_GUARDS` narrowed to `['web']` | **SURVIVED first** → test added → CAUGHT |

That survivor was a real gap: the customer branch was covered only at the **token** seam, so a
shopper whose merchant disabled them would have kept browsing signed in and nothing would have said
so. `test_a_disabled_customers_storefront_session_is_ended` closes it, using this project's
`asAShopper()` shape — which restores `web` as the default guard, because CLAUDE.md records that
leaving `customers` as the default is what hides this whole class of defect.

```
tests/Feature/Accounts/   11 passed (15 assertions)
tests/Unit/Accounts/      16 passed (32 assertions)
Unit suite                6,166 passed, 1 skipped (37,432 assertions)
Contract suite               45 passed (182 assertions)
```

---

## 5. Open, and owner's

- **Delete tokens on disable, or refuse them?** The refusal is shipped. Deletion is irreversible and
  forces a fresh sign-in after re-enabling. In the confidential handoff.
- **A driver cannot be suspended at all** — `delivery_boys` has no column. Adding one is a schema
  change plus a product question (what happens to work already assigned?).
- **Whether the creator-flag limb should be enforced per-request** — §2 above. One line, but it needs
  the decision first because of the `created_by = 1` blast radius.
- **Past use.** If any account was ever disabled in the belief that this cut off access, it did not.
  That is in the confidential handoff as a question only the owner can answer.
