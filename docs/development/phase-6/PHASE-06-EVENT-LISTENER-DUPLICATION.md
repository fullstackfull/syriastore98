# Phase 06 — one `Registered` event registered the same verification listener once per booted provider

**Status:** engineering complete, local. Not pushed at the time of writing; not released.
**Scope:** `app/Support/Events/NormaliseDuplicateEventListeners.php` (new),
`app/Providers/AppServiceProvider.php` (one call).
**Task:** #209 `P06-EVENT-DUP`. Found on 2026-11-24 while debugging a *different* duplicate — the
impersonation audit listener — and carried as a lead until now.

---

## 1. The measurement

Firing `Registered` once delivered **four** `VerifyEmail` notifications.
`Event::getRawListeners()[Registered::class]` held four entries, every one the identical string
`Illuminate\Auth\Listeners\SendEmailVerificationNotification`.

```
raw listeners for Registered: 4
  [0] Illuminate\Auth\Listeners\SendEmailVerificationNotification
  [1] Illuminate\Auth\Listeners\SendEmailVerificationNotification
  [2] Illuminate\Auth\Listeners\SendEmailVerificationNotification
  [3] Illuminate\Auth\Listeners\SendEmailVerificationNotification
```

## 2. The mechanism — read out of the framework, not guessed

`Illuminate\Foundation\Support\Providers\EventServiceProvider::configureEmailVerification()`:

```php
if (! isset($this->listen[Registered::class]) ||
    ! in_array(SendEmailVerificationNotification::class, Arr::wrap($this->listen[Registered::class]))) {
    Event::listen(Registered::class, SendEmailVerificationNotification::class);
}
```

The guard inspects **`$this->listen`** — *the provider's own array*. It is per-provider idempotence,
not per-application. Measured, by reflecting over every loaded provider:

| provider | extends the base | declares `$listen[Registered]` | contributes |
|---|---|---|---|
| `App\Providers\EventServiceProvider` | yes | **yes** (6 keys) | 1, through `$listen` |
| `Workdo\LandingPage\Providers\EventServiceProvider` | yes | no (1 key) | 1, the guard passes |
| `Workdo\LynomiaChat\app\Providers\EventServiceProvider` | yes | no (2 keys) | 1, the guard passes |
| `Illuminate\Foundation\Support\Providers\EventServiceProvider` | — | no (0 keys) | 1, the guard passes |

**Four.** And four is not the general figure. The total is
`1 + (number of booted base-extending providers)`:

- **70** module `EventServiceProvider` classes under `packages/workdo/` extend that base.
- **0** of them declare `$listen[Registered::class]`.

Only two modules boot in the test environment, which is why the figure here is four. A fully-enabled
install reaches about **seventy-one**.

## 3. It is LATENT, and saying so is part of the finding

This task was carried as *"pre-existing, user-visible"*. **That was wrong, and the correction is
load-bearing**: nothing a request can reach dispatches `Registered`.

| site | state |
|---|---|
| `app/Http/Controllers/Auth/RegisteredUserController.php:166` | `// event(new Registered($user));` — **commented out** |
| `app/Http/Controllers/StoreController.php:410` | `// event(new Registered($user));` — **commented out** |
| everything else | `vendor/laravel/breeze/stubs/…` — scaffolding, never executed |

Both first-party sites were replaced with a direct `$user->sendEmailVerificationNotification()`,
which sends exactly **one** e-mail. So **no merchant receives four e-mails today.** The four were
measured by firing the event directly in a test.

The defect arrives the moment anyone uncomments either line, or adds a registration path that
dispatches the event — which is the idiomatic Laravel thing to do, and is exactly what those two
commented lines were. That is why it is worth fixing now and why the fix is cheap.

It also explains the test shape: the test drives **the event**, not the registration route. A test
through the route would pass against the defect, because the route does not dispatch it.

## 4. The fix, and why it has to be central

**Seventy of the seventy-one sources are vendored WorkDo providers**, which this project does not
edit. Seventy identical edits is not available and would not be right if it were. One first-party
normalisation after boot is the only shape there is.

`App\Support\Events\NormaliseDuplicateEventListeners` collapses listeners on `Registered` that are
the *identical class string*. Deliberately bounded:

- **Only `Registered`.** It is the only event the framework auto-registers a listener for, so this
  is the complete scope of the mechanism rather than a sample of it. `EVENTS` is asserted.
- **Only exact class-string duplicates.** A listener registered once is untouched; closures and
  objects pass through unchanged; surviving order is the original order, first occurrence winning.
  A module that legitimately listens for `Registered` keeps its listener.
- **Never empties the list.** A `Registered` with no verification listener means a new user is never
  asked to verify their address — worse than the defect being fixed.

### 4.1 Ordering is the whole difficulty, and it was measured

`PackageServiceProvider` loads database-enabled modules from inside its **own `$app->booted()`**
callback. `AppServiceProvider::boot()` runs *before* `PackageServiceProvider::boot()`, so a plain
`$app->booted()` callback queued there is queued **first** and runs **before** those modules
register anything. Probed on a real application:

```
1. plain booted() queued at the AppServiceProvider position
2. late module loader queued at the PackageServiceProvider position
3. outer callback
4. NESTED callback, appended while the queue was being fired
```

`Application::fireAppCallbacks()` walks a growing list, so a callback appended during firing runs in
the same pass, after everything already queued. `queue()` therefore nests one level: the outer
callback does nothing but queue the inner one.

It is idempotent, which matters because `fireAppCallbacks()` can run more than once in a process
(measured: the nested callback fired twice under the console kernel).

## 5. Two things my own first version got wrong

**A survived mutation exposed a false justification.** The `! $events instanceof Dispatcher` guard
was documented as necessary because a faked dispatcher *"has no raw listener list to read"*.
**Measured: false.** `EventFake::getRawListeners()` works — `EventFake::__call()` forwards to the
dispatcher it decorates. The guard is still right, for a different reason: `forget()` and `listen()`
forward the same way, so normalising through a fake would rewrite the **real** dispatcher's listener
list during a test that asked for events not to be dispatched at all. It is a conservative scope
limit, not a crash guard, and **nothing in the suite depends on it** — so it stays a declared
survivor and the comment says so instead of implying coverage.

**The nesting was a survived mutation until a test could see it.** Removing it broke nothing
observable, because the test environment boots no database-enabled modules and a plain callback
happens to see the same list. Rather than leave the load-bearing part of the fix unpinned,
`test_the_normalisation_runs_after_a_late_registration` builds a bare `Application`, queues the
normalisation where `AppServiceProvider` queues it, then queues a duplicate from a *later* callback
exactly as a module provider would — and asserts it was still collapsed. The mutation is now
**CAUGHT**.

**And one test fixture of mine could not see the defect.** The first version of
`test_one_registration_sends_one_verification_email` used `makeMerchant()`, which stamps
`email_verified_at`. `SendEmailVerificationNotification::handle()` is
`if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail())`, so the listener returned
early and the measurement was **zero** notifications, not four — failing for the wrong reason. The
user is now explicitly unverified, and that is asserted as a premise so it cannot silently regress.

## 6. Evidence

- `tests/Feature/Platform/RegisteredEventListenerDuplicationTest.php` — 5 tests. Failing-first:
  **2 failed, 3 passed**, the failures being the listener count (`{"…SendEmailVerificationNotification":4}`)
  and the notification count (*"Expected … to be sent 1 times, but was sent 4 times"*). Carries a
  premise test that at least one loaded provider still passes the framework's guard — without it,
  every case would pass vacuously the day module providers stop booting in tests, which CLAUDE.md
  records as the normal state.
- `tests/Unit/Events/NormaliseDuplicateEventListenersTest.php` — 10 tests, covering the cases the
  integration test cannot build: closures, an already-clean list, idempotence, an empty list, a
  faked dispatcher, the declared scope, and the ordering property above.
- **Mutations: 10 caught, 1 declared survivor** (the `instanceof Dispatcher` guard, §5).
- Unit **6,212 passed** + 1 declared skip; Contract **45 passed**.
- The single declared Feature failure, `Auth\RegistrationTest::test_new_users_can_register`, was
  checked against this change rather than assumed unaffected: the manifest records its cause as the
  `admin` role not being seeded under `RefreshDatabase`, so `Role::where('name','admin')->first()`
  is null and the assignment is silently skipped. Nothing about e-mail verification or the
  `Registered` event, so the declared outcome set is unchanged in both directions.

## 7. Recorded, not fixed

| | |
|---|---|
| **The two commented-out `event(new Registered($user))` lines.** Re-enabling them is the idiomatic shape and would make the verification mail flow through the event rather than a direct call — but it changes a registration path, and with it which notifications a merchant receives. A product question, not a defect. | needs a decision |
| **`$listen[Registered]` in `App\Providers\EventServiceProvider` is now redundant** in the sense that the framework would register the pairing anyway. It is kept because removing it would make `App\Providers` pass the guard too, adding a *fifth* duplicate for the normaliser to remove — the opposite of a simplification. | deliberate |
| **The same per-provider-guard shape may exist elsewhere in the framework.** `configureEmailVerification()` is the only auto-registration in this Laravel version, which is why `EVENTS` holds one entry and a test pins that. A framework upgrade could add another. | re-measure on upgrade |
