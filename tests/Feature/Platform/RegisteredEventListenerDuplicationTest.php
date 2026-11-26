<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as FrameworkEventProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ONE `Registered` EVENT SENT A VERIFICATION E-MAIL ONCE PER BOOTED MODULE.
 * =================================================================================================
 *
 * Measured in this environment: firing `Registered` once delivered **four** `VerifyEmail`
 * notifications, and `Event::getRawListeners()[Registered::class]` held four entries, all of them
 * the identical string `Illuminate\Auth\Listeners\SendEmailVerificationNotification`.
 *
 * THE MECHANISM, read out of the framework rather than guessed.
 * `Illuminate\Foundation\Support\Providers\EventServiceProvider::configureEmailVerification()` is:
 *
 *     if (! isset($this->listen[Registered::class]) ||
 *         ! in_array(SendEmailVerificationNotification::class, Arr::wrap($this->listen[Registered::class]))) {
 *         Event::listen(Registered::class, SendEmailVerificationNotification::class);
 *     }
 *
 * The guard inspects **`$this->listen`** -- the provider's OWN array. It is a per-provider
 * idempotence check, not a per-application one. So:
 *
 *   - `App\Providers\EventServiceProvider` declares `$listen[Registered]`, so its guard holds and
 *     the listener is registered once, through `$listen`.
 *   - EVERY OTHER provider extending that base has an empty or unrelated `$listen`, so its guard
 *     passes and it calls `Event::listen()` again.
 *
 * AND THE COUNT SCALES WITH THE NUMBER OF ENABLED MODULES. All **70**
 * module `EventServiceProvider` classes under `packages/workdo/` extend that base and **zero** of
 * them declare `$listen[Registered]` -- measured. Only two modules boot in this environment, which
 * is why the figure here is four rather than seventy-one; the total is
 * `1 + (number of booted base-extending providers)`.
 *
 * IT IS LATENT TODAY, AND SAYING SO IS PART OF THE FINDING. `Registered` is dispatched nowhere a
 * request can reach: `Auth\RegisteredUserController:166` and `StoreController:410` both have
 * `// event(new Registered($user));` COMMENTED OUT and call
 * `$user->sendEmailVerificationNotification()` directly instead, which sends exactly one. Every
 * other occurrence in the tree is under `vendor/laravel/breeze/stubs/`, which is scaffolding and
 * never executes. So no merchant receives four e-mails today. The defect fires the moment anyone
 * uncomments either line, or adds a registration path that dispatches the event -- which is the
 * idiomatic Laravel thing to do and is exactly what those two commented lines were.
 *
 * That is why this test drives the EVENT and not the registration route: the route does not
 * dispatch it, so a test through the route would pass against the defect.
 *
 * THE FIX IS FIRST-PARTY AND CANNOT BE ANYTHING ELSE. 70 of the 71 duplicate sources are vendored
 * WorkDo providers, which this project does not edit. `NormaliseDuplicateEventListeners` runs on
 * `$app->booted()`, after every provider has registered, and collapses listeners on `Registered`
 * that are the identical class string. It does not remove a listener registered once, and it does
 * not touch closures or other events.
 */
class RegisteredEventListenerDuplicationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function rawRegisteredListeners(): array
    {
        $raw = Event::getRawListeners()[Registered::class] ?? [];

        return array_values(array_map(
            fn ($l) => is_string($l) ? $l : '(closure or object: ' . get_debug_type($l) . ')',
            $raw
        ));
    }

    // ---------------------------------------------------------------------------------------
    //  THE PREMISE: the mechanism that creates duplicates is still live in this environment
    // ---------------------------------------------------------------------------------------

    /**
     * At least one loaded provider would add a duplicate if nothing collapsed them.
     *
     * Without this, every assertion below passes vacuously the day the module loader stops booting
     * module providers in tests -- and CLAUDE.md records that no WorkDo module loads in a test run
     * unless the test inserts an `add_on_managers` row, so that is a real possibility, not a
     * hypothetical. If this premise fails, the suite is no longer able to see the defect at all and
     * the fix below is untested rather than unnecessary.
     */
    public function test_the_duplicating_mechanism_is_present_in_this_environment(): void
    {
        $base = FrameworkEventProvider::class;
        $guardPassing = [];

        foreach (array_keys(app()->getLoadedProviders()) as $provider) {
            if ($provider !== $base && ! is_subclass_of($provider, $base)) {
                continue;
            }

            $reflection = new \ReflectionProperty($provider, 'listen');
            $reflection->setAccessible(true);
            $listen = (array) $reflection->getValue(new $provider(app()));

            // The framework's guard: a provider whose OWN $listen does not name the pairing calls
            // Event::listen() again.
            $declares = isset($listen[Registered::class])
                && in_array(SendEmailVerificationNotification::class,
                    (array) $listen[Registered::class], true);

            if (! $declares) {
                $guardPassing[] = $provider;
            }
        }

        $this->assertNotSame([], $guardPassing,
            'PREMISE: no loaded provider passes `configureEmailVerification()`\'s guard, so nothing '
            . 'in this environment would register a duplicate and the assertions below cannot fail '
            . 'for the reason they exist. The defect is invisible here, not absent.');
    }

    // ---------------------------------------------------------------------------------------
    //  THE DEFECT
    // ---------------------------------------------------------------------------------------

    public function test_the_verification_listener_is_registered_exactly_once(): void
    {
        $listeners = $this->rawRegisteredListeners();
        $duplicates = array_filter(
            array_count_values(array_filter($listeners, 'is_string')),
            fn (int $n) => $n > 1
        );

        $this->assertSame([], $duplicates,
            'a listener is registered more than once for Registered: '
            . json_encode($duplicates) . '. Full list: ' . implode(', ', $listeners));
    }

    public function test_one_registration_sends_one_verification_email(): void
    {
        Notification::fake();

        /*
         * THE USER MUST BE UNVERIFIED, and the first version of this test was not.
         * `SendEmailVerificationNotification::handle()` is
         * `if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail())`, and
         * `makeMerchant()` stamps `email_verified_at`, so the listener returned early and the
         * measurement was ZERO notifications rather than four -- a test that could not see the
         * defect, failing for the wrong reason. Asserted as a premise below so it cannot regress
         * into that state silently.
         */
        $user = $this->makeMerchant(['email_verified_at' => null]);
        $fresh = User::find($user->id);

        $this->assertFalse($fresh->hasVerifiedEmail(),
            'PREMISE: the fixture user is already verified, so the listener returns early and no '
            . 'number of duplicate registrations would send anything.');

        event(new Registered($fresh));

        // The COUNT is the assertion. `assertSentTo` alone passes for four e-mails as readily as
        // for one, which is why the original measurement needed `assertSentToTimes`.
        Notification::assertSentToTimes($fresh, VerifyEmail::class, 1);
    }

    /**
     * THE LISTENER IS STILL THERE. The fix collapses duplicates; it must not remove the pairing.
     *
     * Asserted separately because `Event::forget()` followed by a faulty re-registration would
     * satisfy both cases above -- zero listeners is also "not more than once", and zero listeners
     * sends zero e-mails, which `assertSentToTimes(..., 1)` would catch but a careless rewrite of
     * that assertion would not.
     */
    public function test_the_verification_listener_is_not_lost(): void
    {
        $this->assertContains(SendEmailVerificationNotification::class,
            $this->rawRegisteredListeners(),
            'the verification listener is gone entirely, so a newly registered user would never be '
            . 'asked to verify their e-mail address');
    }

    /**
     * A GENUINELY DISTINCT LISTENER ON THE SAME EVENT SURVIVES.
     *
     * The normalisation dedupes identical class strings. A second, different listener is not a
     * duplicate and must still run -- otherwise the fix would silently disable any module that
     * legitimately listens for `Registered`.
     */
    public function test_a_distinct_listener_on_the_same_event_still_runs(): void
    {
        $ran = 0;
        Event::listen(Registered::class, function () use (&$ran) {
            $ran++;
        });

        $user = $this->makeMerchant();
        Notification::fake();

        event(new Registered(User::find($user->id)));

        $this->assertSame(1, $ran,
            'a distinct listener registered for Registered did not run exactly once');
    }
}
