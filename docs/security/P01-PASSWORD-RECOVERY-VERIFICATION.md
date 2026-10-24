# P01 — Password-recovery security verification

An independent adversarial review of the password-recovery emergency fix (commit `58b8e287`).

**49 tests** written to disprove it (`tests/Feature/Security/CustomerPasswordResetAdversarialTest`),
plus **10** for the prepared column migration. The original 19 still pass unchanged.

The brief: *"Reinspect the current source code and registered routes, not just the previous report
or passing tests. Attempt to disprove the security of the implementation using isolated automated
tests."*

**Result: the emergency fix stopped both reported defects and left eleven findings — ten
demonstrated defects and one hardening gap.** Two of the ten are **cross-tenant account
takeovers**, and neither is in a recovery handler: both are on adjacent password-change endpoints,
which came into scope because the owner's question ("can an administrator or delivery-driver token
authorise a customer password change?") is a question about the **surface**, not about one handler.

All eleven are now closed, each pinned by a test that fails when that one fix is reverted. Where a
finding is a hardening gap rather than a demonstrated hole, it is labelled as one — see row 2 of
the table at the end.

---

## 1. Whether a password can still be changed without a valid OTP

**Yes, by three routes, all now closed.** A fourth — the delivery-driver reset — is in §6.

### 1a. `POST api/{slug}/change-password` — any merchant, operator or **delivery driver** token

`Api\ApiController::change_password`. Not a recovery handler; found because the brief asked
whether an administrator or delivery-driver token can authorise a customer password change, which
is a question about the **surface**, not about one handler.

```php
if (!auth('customers')->user()) {
    $rules = ['customer_id' => 'required'];
    $customer_id = $request->customer_id;      // attacker-supplied
} else {
    $customer_id = auth('customers')->user()->id;
}
...
$user = Customer::find($customer_id);          // no store predicate, no ownership check
$user->password = bcrypt($request->password);
```

The route carries `custom.auth`. `App\Http\Middleware\CustomAuth:78-86` logs a token's owner into
whichever guard matches its class — `Customer` → `customers`, `User` → `web`, `DeliveryBoy` →
`deliveryboy`. A merchant's or driver's token therefore **passes the middleware** and leaves
`auth('customers')` null, taking the first branch, where `customer_id` is read straight off the
request.

`$rules` has three assignment sites, the last unconditional, so `'customer_id' => 'required'` was
overwritten before `\Validator` ever saw it — the id was not even validated.

Measured:

- a merchant's token set an arbitrary customer's password;
- **a delivery driver's token set the password of a customer belonging to a different store** — a
  cross-tenant account takeover from the least-privileged principal the platform issues tokens to.

The driver case crosses the boundary because nothing resolves a tenant for the `deliveryboy`
guard, so `config/tenancy.php`'s `null_tenant => 'open'` leaves `Customer` unscoped for them. The
merchant-to-another-store case was refused — **but only** because a merchant token *does* resolve
a tenant and the global scope then narrowed the lookup. A control that works by accident for one
principal and not another is not a control, so the replacement checks the principal and the store
explicitly and does not rely on the scope at all.

**Closed:** the handler now requires an authenticated `customers` principal, acts only on that
account, requires the slug's store to be the customer's own, and ignores `customer_id` entirely.

### 1b. The compatibility Bearer accepted **any** customer login token

`forgot_password_save` accepted the Bearer on `$tokenable instanceof Customer` plus a store match
— true of every customer login token. A shopper's long-lived session therefore set that account's
password with **no OTP and no knowledge of the current password**, so one stolen or leaked token
was a permanent takeover and the OTP step was optional.

**Closed:** a Bearer authorises a reset only if the verify step **registered** it for a live
recovery round, and it is consumed on first use like the reset token beside it.

### 1c. `forgot_password_verify_otp` writing a password

Already closed by the emergency fix; re-verified here, including that the committed literal
`hello@1234` is gone and that verify writes no password at all.

---

## 2. Cross-store and cross-account reset authorisation

**No defect found in the recovery handlers.** Re-verified by test, not by reading the prior report:

- a reset token minted in store A is refused by store B, **and is not spent** by the misrouted
  attempt, so a legitimate customer does not lose their one chance;
- `send-otp` will not resolve a customer through another store's slug, and mails nothing;
- an unresolvable slug is refused rather than defaulted to a store;
- a reset token for one customer cannot reset another in the same store;
- a customer token from store A cannot authorise a reset in store B.

The cross-store defect that **did** exist was in `change-password` (§1a).

---

## 3. Replay of OTPs or reset tokens

The OTP and the reset token were each single-use. **The guarantee did not hold at the level that
matters**, in two ways:

1. **One completed OTP step authorised unlimited resets.** The reset token was consumed, but the
   Bearer returned in the same response was not — and there is **no `config/sanctum.php`**, so
   Sanctum's default `expiration => null` applies and that token never expires. The single-use
   reset token guaranteed nothing while its twin sat beside it.
2. **A successful reset left sibling reset tokens alive.** Two recovery rounds minted two tokens;
   spending one left the other usable.

**Closed** by a **generation record** per round: every authorisation carries the generation current
when it was minted; verifying again replaces it, so the newest round is the only live one; and
consuming any authorisation deletes it, so every sibling of the one just spent stops working.

Through HTTP this property is now defended twice — the session revocation in §8 also deletes the
twin Bearer — so the isolating test addresses the service directly. Two independent controls is
right for production and wrong for a test meant to pin one of them.

---

## 4. Concurrent requests and single-use guarantees

**Defect found.** `Cache::get` → check → `Cache::put` on the attempt counter was a read-modify-write
with nothing serialising it. Concurrent guesses each read the same count and each wrote the same
increment, so parallelism bought guesses the limit had already paid for. The `throttle:auth`
limiter (10/min per ip+email) caps the rate but does nothing about the interleaving.

**How this one was established, stated plainly because it differs from the others.** The absent
serialisation is read off the code — there is nothing between the get and the put — rather than
raced in-process, which one PHP process cannot do deterministically. What the test asserts is the
**mechanism**: it takes the lock the service uses, holds it, and requires verify to refuse even a
correct code. A verify step that proceeds while the lock is held is the bug, and that is what the
test would catch.

**Closed:** the whole verify step runs inside a cache lock keyed on store+customer, and **fails
closed** — a caller that cannot take the lock within 3 seconds is refused rather than proceeding
unserialised. The OTP survives such a refusal, so a legitimate user who lost a race simply retries.

ArrayStore, FileStore and DatabaseStore all implement `LockProvider` (measured, all three;
`.env.example` configures `database`). A driver without locking degrades to the unlocked path
rather than throwing, and the class docblock says so rather than claiming an absolute guarantee.

**This test found a bug in the fix itself.** `Lock::block()` *throws* `LockTimeoutException` rather
than returning false; the first version tested it as a boolean, which would have made a contended
request a 500 instead of a clean refusal.

---

## 5. Expired tokens and attempt-limit bypasses

Expiry of the OTP and of the reset token: **correct**, re-verified.

Two defects in the attempt limit:

1. **A wrong guess extended the OTP's lifetime.** The increment was re-put with a **fresh** ten
   minutes — while the comment immediately above it claimed the opposite — so a guesser held a hot
   OTP open for as long as they kept guessing. The deadline is now stored in the record and
   honoured on re-put.
2. **The limit bound one code, not the account.** Re-sending reset the counter for free, so five
   guesses cost one extra request and the limit bounded nothing. Sends are now capped at
   **5 per account per 60 minutes**, with the window fixed from the first send rather than pushed
   forward by each one — a rolling window would never reopen for a victim under sustained attack,
   turning a rate limit into a permanent lockout of the real owner. A test asserts the window does
   reopen.

### The arithmetic, because it decides whether a 4-digit code is defensible

5 sends × 5 attempts = **25 guesses per hour per account**. Against 10,000 values that is 0.25% per
hour, so an attacker sustaining the maximum rate reaches roughly a one-in-three chance over a week.
Against six digits it is 0.0025% per hour — about 0.4% over the same week.

`CustomerPasswordResetService::OTP_DIGITS` is **left at 4 deliberately**, and it is the one number
in this package the owner should consider changing. Four digits is the shape the unreleased
storefront client has always been sent, this branch cannot test that client, and raising it during
an emergency would be an unverifiable contract change. Changing the constant to `6` is the whole
change — the generator takes the length as an argument and the handler reads it from the constant.

---

## 6. Whether administrator or delivery-driver tokens can authorise a customer reset

**On the three recovery endpoints: no** — verified by test for a merchant token, a platform
operator token, a delivery-driver token, another customer's token, a customer token from another
store, and an expired token. Each is refused and the password is unchanged.

**On `change-password`: yes, and it was the worst finding of this review.** See §1a.

**On `POST api/admin/deliveryboysReset-password/{id}`: yes, in the other direction.** Read while
checking this route's logging (§10), and it turned out to have no authorisation at all beyond
`custom.auth`:

```php
$store_id = $request->query('store_id');                     // attacker-supplied
...
$deliveryBoy = DeliveryBoy::where('id', $id)->where('store_id', $store_id)->first();
...
$deliveryBoy->update(['password' => Hash::make($request->password)]);
```

`store_id` arrives in the **query string**, so the `where('store_id', ...)` predicate was not a
tenant check — it compared the attacker's two supplied values against each other. Any principal
holding any valid token — **a shopper, another store's driver, any merchant** — could set **any
delivery driver's password in any store**, cross-tenant, by naming the matching `store_id`.

Three tests demonstrated it (customer token, another driver's token, a merchant of a different
store); each fails when the fix is reverted.

**Closed:** the caller must be a `User` on the `web` guard — a customer's or a driver's token no
longer reaches the write — and the named store must be one they own, derived with `creatorId()`
like the rest of the admin surface.

**It was safe to fix here rather than defer to P01-SEC-11** because the shipped admin app sends
`store_id = Prefs.getStoreID()`, its own selected store (repo B
`lib/config/repository/delivery_boy_repository.dart:47`), against base URL
`https://storesyria.com/api/admin` (`lib/utils/base_api.dart:3`). Every request it makes still
succeeds — same path, method, query parameters, body and response shapes. A test pins that
journey. The only requests newly refused are ones naming a store the caller does not own, which
that client never sends.

---

## 7. Whether the fallback Bearer token provides excessive authority

**It did.** See §1b and §3. It accepted any customer login token, for unlimited resets, forever.

It now carries exactly the authority of the reset token it stands in for: registered by one
recovery round, bound to that customer and store, expiring with the round, single-use.

The route's middleware is unchanged (`['APILog','throttle:auth']`) and the token is still resolved
in the handler via Sanctum's `findToken()`. Adding `custom.auth` would make authentication
mandatory and answer 401 to a client that sends no token, and repo B's `network.dart` turns any
401 into a forced logout.

---

## 8. Reset authorisation invalidation following a successful change

**Two defects.** Sibling authorisations survived (§3), and **every pre-existing session survived**
— so the owner could recover their password while an attacker kept their token, and the reset
accomplished nothing against the actor it was aimed at.

**Closed:** a successful recovery reset deletes all of the customer's Sanctum tokens, keeping only
the one that this recovery round itself minted when the caller is holding it — so a client that
signed in at verify-otp and then saved a password is not logged out mid-flow. Both halves are
tested: the pre-existing token is gone, and the recovery session still authenticates.

---

## 9. Cache expiration, concurrency and failure behaviour

- **Fails closed.** A flushed cache (deploy, `cache:clear`, eviction) refuses the reset; it does
  not authorise it. Tested.
- **Nothing replayable is stored.** The OTP is held only as a bcrypt hash; the reset token is the
  cache **key**, hashed with SHA-256, so a reader of the cache store cannot replay what they find.
  Tested both ways — the plaintext key is absent, the hashed key is present.
- **Concurrency** — §4.
- **`CACHE_STORE=array` would break the flow entirely** (per-request state, so no OTP could ever
  verify). That is an availability failure, not a bypass. `.env.example` sets `database` and the
  `cache` table migration exists; the test environment uses `array`, which is why the tests can
  drive the flow within a single process.
- **A cache flush invalidates resets in flight.** Acceptable; worth knowing before a deploy.

---

## 10. Password, OTP and token exposure in API logs

### `APILog` — fixed by the emergency, re-verified here

A full authorised recovery writes **zero** occurrences of the chosen password, the OTP, the reset
token or the Bearer token to `storage/logs/api-*.log`. Tested by reading the file.

### `AdminApiLog` — **the same defect, still open, on the higher-privilege surface**

This was not a new discovery: **S4 in the work queue named both loggers.** The emergency fixed
`APILog` and left its twin with the original body — `terminate()` passing the whole `$request`
(which stringifies to the complete HTTP request, Authorization header and body included) and the
whole `$response` to the same `API_log` channel, `daily` with **15-day retention**.

It guards **128 route declarations** in `routes/api.php`, among them:

| Route | Handler | What leaked |
|---|---|---|
| `routes/api.php:134` `adminlogin` | `DashboardController::login` | every submitted operator/merchant password, successful or not |
| `routes/api.php:304` `updatepassword` | `DashboardController::UpdatePassword` | an administrator's new password |
| `routes/api.php:363` `deliveryboysReset-password/{id}` | `DeliveryBoyApiController::resetPassword` | a driver's password, set by an admin |

So administrator passwords, driver passwords and admin bearer tokens were written to disk in
plaintext for the entire period the storefront side was considered fixed.

**Closed:** the redaction now lives in one place,
`App\Http\Middleware\Concerns\RedactsApiLogFields`, used by both. A test asserts both use it and
that neither logs the raw objects — because fixing one logger and not its twin is exactly how this
survived.

### What logging remediation still needs the owner

Redaction applies to **future requests only**. The existing files on the running server may hold up
to 15 days of plaintext customer, administrator and driver credentials. Only the owner can read or
rotate them, and **whether any past use was malicious cannot be determined from this repository.**

---

## The ten defects, and how each was pinned

Each fix was reverted **individually** and the named test re-run. A fix whose removal leaves the
suite green has not been shown to do anything.

| # | Defect | Severity | Removal check |
|---|---|---|---|
| 1 | `change-password`: driver token → any customer, any store | **critical** | 2 of 5 fail |
| 2 | `change-password`: no store binding | low (defence in depth) | 1 fails |
| 3 | Compatibility Bearer = any login token | **high** | 1 fails |
| 4 | One OTP step → unlimited resets | **high** | 1 fails |
| 5 | Siblings survive a successful reset | medium | 1 fails |
| 6 | Pre-existing sessions survive a reset | medium | 1 fails |
| 7 | Wrong guess extends the OTP's life | medium | 1 fails |
| 8 | Attempt limit resets on re-send | medium | 1 fails |
| 9 | Attempt counter not serialised | medium | 1 fails |
| 10 | OTP from `rand()`, not a CSPRNG | medium | 1 fails |
| 11 | `deliveryboysReset-password`: any token → any driver, any store | **critical** | 3 fail, individually |

**Three of the removal checks were wrong on the first attempt** and were corrected:

- #2's first anchor was unreachable by the driver path (the principal check refuses first), so it
  proved nothing until re-pointed at a customer calling through the wrong slug — and it is
  reported as defence in depth rather than as a closed hole, because no exploitable path was
  demonstrated;
- #5's first check removed `Cache::forget($generationKey)`, but the endpoint test passes anyway
  because minting a second token already retires the first — a separate service-level test now
  isolates retirement-on-consume;
- #7's first check removed the TTL argument, but the **stored deadline** is what does the work, so
  the test stayed green until the deadline check itself was removed.

This is the same failure mode as earlier in this project — a removal that does not remove the
control proves nothing — and it is recorded because the first pass of a removal check is not
trustworthy without checking that it bit.

---

## What this package did NOT do

- **No merge into `main`.** `main` is untouched.
- **No deployment.** Nothing here protects the running server.
- **No migration applied anywhere.** The P01-SEC-13 migration is a `*.php.draft` in a directory
  Laravel's glob cannot reach; it is *executed* only inside a test, against the test schema.
- **No server data modified.**
- **No backup functionality touched.**
- **The next commerce phase not started.**
- **The MySQL failure was not executed.** This container has the PDO mysql client driver and no
  MySQL server. The column defect rests on the declared type plus documented MySQL semantics; the
  first precondition on the migration is to confirm it against the real server.
- **The unreleased storefront client could not be tested.** Neither Flutter repo calls these four
  endpoints — they are the storefront customer API. Compatibility is argued from the route
  contract, and for `change-password` from the fact that the removed branch was reachable **only**
  by a non-customer token, so no customer-facing client could ever have used it.

## Still owed by the owner, carried forward

1. **Block `POST /api/*/forgot-password-save` at the edge** until a release ships. Unchanged from
   the emergency report; a Git commit does not protect a running server.
2. **Rotate operator and administrator passwords**, and now **delivery-driver passwords** too —
   `adminlogin` and `deliveryboysReset-password` were logging them in plaintext.
3. **Treat `storage/logs/api-*.log` as compromised** and rotate it.
4. **A migration window** for P01-SEC-13, in a release that already contains the web attempt limit
   and the two route throttles.
5. **Decide `OTP_DIGITS`** — see §5. Requires a storefront-client check.
6. **`config/sanctum.php`** — tokens currently never expire, which is what made §3 unbounded.
