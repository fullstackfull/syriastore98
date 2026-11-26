# Phase 06 — "log in as" left no trace, and the trap that made the fix write two rows

**Status: FIXED.** Written after the change, because this repository is public.

---

## 1. The defect

`StoreController::LoginWithAdmin` (`GET store/{id}/login-with-admin`) is correctly gated —
`UserPolicy::impersonate` admits only the platform operator, refuses self, and refuses another
operator — and correctly bound (`User::findOrFail(decrypt($id))`). What it did not do is **leave a
trace**.

After it ran, the session guard held the merchant, so every subsequent write was attributed to the
merchant. A price, a refund or a role edited by an operator while impersonating reads in the audit
trail as the merchant's own action, with nothing anywhere recording that an operator was at the
keyboard. It is the one action a tenant can neither take nor see, which is why it is recorded rather
than merely gated.

**No permission changed.** Who may impersonate is an owner decision and the directive names it one;
`UserPolicy` is untouched.

### A second defect at the same door: a failure that reported success

`ImpersonateManager::take()` is `catch (\Exception $e) { unset($e); return false; }` — it swallows
everything — and the controller **discarded the return value**, then redirected to `dashboard`
unconditionally. So a failed impersonation sent the operator to a dashboard that was still *their*
dashboard, with no event dispatched and nothing logged, while the screen implied otherwise. The
return value is now checked, which also keeps the audit row and the redirect from disagreeing: the
row is written only on the event, and the event fires only when `take()` succeeded.

---

## 2. Why a listener, and the measurement that makes it non-trivial

lab404 dispatches `TakeImpersonation` and `LeaveImpersonation` from the manager, so a listener records
**every** impersonation, including one taken through a door this repository does not have yet. Two
controller lines record only the doors somebody remembered.

**`take()` dispatches its event AFTER the guard has already been switched.** Measured in the vendor
source:

```php
$this->app['auth']->guard($currentGuard)->quietLogout();
$this->app['auth']->guard($guardName)->quietLogin($to);     // the merchant is now signed in
...
$this->app['events']->dispatch(new TakeImpersonation($from, $to));
```

So at listener time `auth()->id()` is the **impersonated merchant**. A listener that let
`AuditLog::record()` read the guard would file the row as *the merchant impersonated themselves* — an
operator action attributed to the tenant, which is exactly what the audit-attribution work exists to
prevent. The actor is therefore passed **explicitly**, from the event's own `$impersonator`, and
`test_the_row_names_the_operator_although_the_guard_already_holds_the_merchant` fails if anyone
reverts to the guard.

`leave()` happens to dispatch after restoring the operator, so the guard would answer correctly
there — by accident. The explicit actor is used for both, because a property that holds by accident
is not a property.

**Store attribution is `null`, deliberately.** The subject is a `User` and `users` carries no
`store_id`, so `AuditAttribution` reaches "operator → null" on its own rather than the fabricated
store 1. A merchant may own several stores and naming one would be a guess; *which* merchant was
impersonated is recorded exactly, in `subject_type`/`subject_id`.

### Two additive changes to `AuditLog::record()`

| | |
|---|---|
| a new optional `$actor` | The only way the listener can name the real impersonator. Additive: all **18** existing call sites pass at most four arguments and none changes. |
| `user_id` now reads `auth('web')` | It was `auth()->id()` — the **default** guard — while the store attribution beside it read `auth('web')` explicitly. CLAUDE.md records that `CustomAuth` can leave the default guard set to `customers` or `deliveryboy`, so on such a request the two disagreed and a **Customer id could be written into a column that means a `users` row**. Latent rather than live (every current call site is panel-only) and now impossible; a non-`User` principal writes `null`, which fails safe. |

---

## 3. THE TRAP: the first version wrote TWO audit rows per impersonation

`RecordImpersonation` was written to `app/Listeners/` — the obvious home — and declared in
`EventServiceProvider::$listen`. Every impersonation then wrote **two identical audit rows**. Dumped
from the dispatcher's own `listeners` property:

```
TakeImpersonation: 2 raw entries
  [0] ["App\Listeners\RecordImpersonation","handleTake"]
  [1] App\Listeners\RecordImpersonation@handleTake
```

**This repository already knew, and I had not read it.** `AppServiceProvider`'s note on
`NormaliseRewardRedemptionFlag` says it outright: Laravel 11's `Application::configure()` calls
`->withEvents()` unconditionally, and the framework EventServiceProvider it registers has **discovery
ON over `app/Listeners`** — so a class there is auto-registered by its handler signature *in addition
to* the explicit mapping. `App\Providers\EventServiceProvider::shouldDiscoverEvents()` returning
`false` is about that provider only, and misleads a reader into thinking discovery is off.
`EnforceMarketingConsentOnMail` lives in `app/Services/Marketing/` for precisely this reason and says
so in its own docblock.

**My first explanation was wrong, and two measurements settled it.** I thought the array-callable
mapping was being normalised and registered twice. Rewriting it to the `Class@method` string form
changed **nothing** — still two rows. **Moving the class** to `app/Support/Audit/` dropped it to one.
The control case is `MessageSending`, whose listener sits outside the scanned directory: exactly one
registration.

**Why move rather than make it idempotent**, which is how the two listeners that do live in
`app/Listeners/` are handled: **an audit row is not idempotent.** Two rows is two rows, and a trail
that double-counts what an operator did is worse than an absent one, because it will be believed. So
this class follows the `EnforceMarketingConsentOnMail` precedent and sits beside `AuditAttribution`,
its collaborator. Two tests make moving it back a red test rather than a silently doubled trail: one
asserts each event has exactly one listener, the other that the file is not under `app/Listeners/`.

---

## 4. A PRE-EXISTING DEFECT FOUND ON THE WAY, NOT FIXED HERE

While measuring the above I dumped the dispatcher for unrelated events. **`Registered` has FOUR
identical registrations of `SendEmailVerificationNotification`**, while `$listen` declares it once
and nothing in `app/` or `packages/workdo/` declares it at all. Driven with `Notification::fake()`:

```
Expected [Illuminate\Auth\Notifications\VerifyEmail] to be sent 1 times, but was sent 4 times.
```

**So every new merchant registration sends four identical verification emails.** `VerifyReCaptchaToken`
is similarly doubled (2 registrations, 1 declaration); `MessageSending` is correctly 1.

This is **not** the `app/Listeners` discovery trap — `SendEmailVerificationNotification` is in
`vendor/`, outside the scanned directory, so discovery cannot be adding it. The cause is therefore
**unknown and must be measured rather than guessed**: the `$listen` mapping appears to be applied more
than once although `App\Providers\EventServiceProvider` shows exactly once in `getLoadedProviders()`.

It is recorded and **not fixed in this change**. The blast radius is every listener in the
application — the marketing-consent seam and the reCAPTCHA listener among them — so it needs its own
failing-first test, its own root-cause measurement and its own review, not a guess bundled into an
unrelated slice.

---

## 5. Evidence

| | |
|---|---|
| failing first | 6 of the 11 behaviour cases failed against the unrecorded door; the 5 that passed are the refusal guards (a merchant refused, an operator refused, a no-op exit recording nothing) |
| after | `tests/Feature/Audit/` **22 passed** (87 assertions) — the 8 pre-existing attribution cases plus 14 here |
| mutations | **7 applied, 7 CAUGHT** — one only after the harness exposed a worthless assertion of mine |

| Mutation | |
|---|---|
| the listener lets `record()` read the guard instead of the event | CAUGHT |
| `record()` ignores the explicit actor | CAUGHT |
| `record()` reads the default guard again (a Customer becomes the actor) | CAUGHT |
| the take listener records nothing | CAUGHT |
| the leave listener records nothing | CAUGHT |
| the controller ignores `take()`'s return value again | **SURVIVED, then CAUGHT** |
| the recorder is registered a second time (the discovery double) | CAUGHT |

### The survivor was my own assertion, and it is the most useful line in this document

`test_an_impersonation_that_fails_is_reported_rather_than_redirected_as_success` read
`assertNotSame('dashboard', $response->headers->get('Location'))`. `Location` is the **absolute**
`http://localhost/dashboard`, so the bare string could never match and the case passed whether or not
the return value was checked. Reverting the fix left the test **green**. Compared against
`url('dashboard')` now, plus `assertSessionHas('error')`, and then CAUGHT. This is the project's own
"passes for the wrong reason" failure mode, in my own test, found by the control that exists for it.

### How the failure case is driven

Through the container, not by breaking the session: `ImpersonateManager` is resolved from it by the
`Impersonate` facade, so binding a subclass whose `take()` returns `false` drives the **real**
controller against a **real** refusal, instead of asserting on source text. The case also asserts as a
premise that the guard did not change, so a fake that failed to model a real failure would be visible.
