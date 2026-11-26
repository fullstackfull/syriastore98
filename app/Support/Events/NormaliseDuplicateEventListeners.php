<?php

namespace App\Support\Events;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;

/**
 * COLLAPSE LISTENERS THAT EVERY BOOTED PROVIDER REGISTERED AGAIN.
 * =================================================================================================
 *
 * `Illuminate\Foundation\Support\Providers\EventServiceProvider::configureEmailVerification()` is:
 *
 *     if (! isset($this->listen[Registered::class]) ||
 *         ! in_array(SendEmailVerificationNotification::class, Arr::wrap($this->listen[Registered::class]))) {
 *         Event::listen(Registered::class, SendEmailVerificationNotification::class);
 *     }
 *
 * The guard reads **`$this->listen`** -- the provider's OWN array. It is per-provider idempotence,
 * not per-application. `App\Providers\EventServiceProvider` declares the pairing, so it registers
 * once through `$listen`; every OTHER provider extending that base has an unrelated `$listen`, so
 * its guard passes and it calls `Event::listen()` again.
 *
 * MEASURED: four identical `SendEmailVerificationNotification` entries on `Registered` in the test
 * environment, and firing the event once delivered FOUR `VerifyEmail` notifications. And the figure
 * is not four in general -- it is `1 + (number of booted base-extending providers)`. All 70 module
 * `EventServiceProvider` classes under `packages/workdo/` extend that base and ZERO declare
 * `$listen[Registered::class]`, so a fully-enabled install reaches about seventy-one.
 *
 * WHY THIS IS FIRST-PARTY AND CENTRAL RATHER THAN 70 EDITS. Seventy of the seventy-one sources are
 * vendored WorkDo providers, which this project does not edit. One normalisation after boot is the
 * only available shape.
 *
 * IT IS LATENT TODAY AND THE FIX IS STILL WORTH HAVING. Nothing a request can reach dispatches
 * `Registered`: `Auth\RegisteredUserController` and `StoreController` both carry
 * `// event(new Registered($user));` commented out and call
 * `$user->sendEmailVerificationNotification()` directly, which sends one. The remaining occurrences
 * are under `vendor/laravel/breeze/stubs/`, which never executes. So no merchant receives four
 * e-mails today -- and the defect arrives the moment anyone uncomments either line or adds a
 * registration path that dispatches the event, which is the idiomatic thing to do and is exactly
 * what those two commented lines were.
 *
 * WHAT IT DOES, AND DELIBERATELY DOES NOT DO.
 *
 *   - Only `Registered`. That is the only event the framework auto-registers a listener for, so it
 *     is the complete scope of this mechanism -- not a sample of it.
 *   - Only EXACT class-string duplicates. A listener registered once is left alone, closures and
 *     objects are passed through untouched, and the surviving order is the original order. A
 *     module that legitimately listens for `Registered` keeps its listener.
 *   - Nothing is removed outright. If deduplication would empty the list, the list is left as it
 *     is, because a `Registered` with no verification listener means a new user is never asked to
 *     verify their address -- a worse defect than the one being fixed.
 *
 * ORDERING IS THE WHOLE DIFFICULTY, and it was measured rather than reasoned about.
 * `PackageServiceProvider` loads database-enabled modules from inside its own `$app->booted()`
 * callback. `AppServiceProvider::boot()` runs BEFORE `PackageServiceProvider::boot()`, so a plain
 * `$app->booted()` callback queued there is queued FIRST and runs BEFORE those modules register
 * anything. Probed:
 *
 *     1. plain booted() queued at the AppServiceProvider position
 *     2. late module loader queued at the PackageServiceProvider position
 *     3. outer callback
 *     4. NESTED callback, appended while the queue was being fired
 *
 * `Application::fireAppCallbacks()` walks a growing list, so a callback appended during firing runs
 * in the same pass, after everything already queued. Hence `register()` nests one level: the outer
 * callback does nothing but queue the inner one, which therefore runs after the module loader.
 *
 * It is idempotent, which matters because `fireAppCallbacks()` can run more than once in a process
 * (measured: the nested callback fired twice under the console kernel). Normalising an already
 * normalised list is a no-op.
 */
class NormaliseDuplicateEventListeners
{
    /**
     * The events whose listener list is collapsed. See the class docblock for why this is the
     * complete set and not a sample.
     *
     * @var list<class-string>
     */
    public const EVENTS = [Registered::class];

    /**
     * Queue the normalisation to run after every provider -- including late module providers -- has
     * registered its listeners.
     */
    public static function queue(\Illuminate\Contracts\Foundation\Application $app): void
    {
        $app->booted(static function () use ($app): void {
            // The nesting is load-bearing; the class docblock has the measurement.
            $app->booted(static function () use ($app): void {
                self::run($app->make(DispatcherContract::class));
            });
        });
    }

    /**
     * Collapse exact duplicate class-string listeners on each event in self::EVENTS.
     *
     * @return array<class-string, int> event => how many duplicate registrations were removed
     */
    public static function run(DispatcherContract $events): array
    {
        $removed = [];

        if (! $events instanceof Dispatcher) {
            /*
             * A REPLACED DISPATCHER IS DECLINED. The first version of this comment said a faked
             * dispatcher "has no raw listener list to read", which is FALSE and was measured to be
             * false: `EventFake::getRawListeners()` works, because `EventFake::__call()` forwards
             * to the dispatcher it decorates.
             *
             * The real reason to decline is action at a distance. `forget()` and `listen()` would
             * forward the same way, so normalising through a fake would rewrite the listener list
             * of the REAL dispatcher hidden behind it, during a test that asked for events not to
             * be dispatched at all. That is a surprise worth refusing.
             *
             * IT IS A CONSERVATIVE SCOPE LIMIT, NOT A CRASH GUARD, and nothing in the suite
             * depends on it: a mutation removing this branch SURVIVED, which is how the false
             * justification above was found. `test_a_faked_dispatcher_is_left_alone` pins the
             * behaviour; no test can show harm from its absence, so none claims to.
             */
            return $removed;
        }

        foreach (self::EVENTS as $event) {
            $raw = $events->getRawListeners()[$event] ?? [];

            if ($raw === []) {
                continue;
            }

            $seen = [];
            $kept = [];

            foreach ($raw as $listener) {
                if (is_string($listener)) {
                    if (isset($seen[$listener])) {
                        continue;
                    }

                    $seen[$listener] = true;
                }

                $kept[] = $listener;
            }

            $dropped = count($raw) - count($kept);

            // `$kept === []` cannot happen while `$raw !== []` -- the first entry is always kept --
            // but the guard states the invariant the class docblock promises, because an empty
            // list would silently stop new users being asked to verify their address.
            if ($dropped === 0 || $kept === []) {
                continue;
            }

            $events->forget($event);

            foreach ($kept as $listener) {
                $events->listen($event, $listener);
            }

            $removed[$event] = $dropped;
        }

        return $removed;
    }
}
