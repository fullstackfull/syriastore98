<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use App\Support\Audit\RecordImpersonation;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;
use Lab404\Impersonate\Services\ImpersonateManager;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE OPERATOR CAN BECOME ANY MERCHANT, AND NOTHING RECORDED THAT IT HAPPENED.
 * =================================================================================================
 *
 * `StoreController::LoginWithAdmin` (`GET store/{id}/login-with-admin`) is correctly gated --
 * `UserPolicy::impersonate` admits only the platform operator, refuses self, and refuses another
 * operator -- and it is correctly bound: `User::findOrFail(decrypt($id))`. What it did NOT do is
 * leave a trace. After it ran, every subsequent write in the session was attributed to the
 * merchant, so a price, a refund or a role edited while impersonating reads in the audit trail as
 * the merchant's own action, with nothing anywhere saying an operator was at the keyboard.
 *
 * This is the one action a tenant can never take for themselves and can never see, which is why it
 * is recorded rather than merely gated. IT CHANGES NO PERMISSION. Who may impersonate is untouched
 * -- that is an owner decision and the directive names it as one -- and `UserPolicy::impersonate`
 * is not edited by this slice.
 *
 * WHY A LISTENER AND NOT TWO LINES IN THE CONTROLLER. lab404 dispatches `TakeImpersonation` and
 * `LeaveImpersonation` from the manager, so a listener records EVERY impersonation, including one
 * taken by a door this repository does not yet have. Two controller lines record only the doors
 * somebody remembered.
 *
 * AND THE TRAP THAT MAKES THIS NON-TRIVIAL -- MEASURED IN THE VENDOR SOURCE.
 * `ImpersonateManager::take()` dispatches its event AFTER `quietLogin($to)`:
 *
 *     $this->app['auth']->guard($currentGuard)->quietLogout();
 *     $this->app['auth']->guard($guardName)->quietLogin($to);     // the merchant is now signed in
 *     ...
 *     $this->app['events']->dispatch(new TakeImpersonation($from, $to));
 *
 * So at listener time `auth()->id()` is the IMPERSONATED MERCHANT. A listener that let
 * `AuditLog::record()` read the guard would file the row as *the merchant impersonated themselves*
 * -- a false attribution of an operator action to the tenant, which is exactly what §2 of this
 * work forbids. The actor is therefore passed EXPLICITLY, from the event's own `$impersonator`.
 * `test_the_row_names_the_operator_although_the_guard_already_holds_the_merchant` is the assertion
 * that fails if anyone reverts to the guard.
 *
 * `leave()` happens to dispatch after restoring the operator, so the guard would answer correctly
 * there -- by accident. The explicit actor is used for both, because a property that holds by
 * accident is not a property.
 *
 * STORE ATTRIBUTION IS `null`, DELIBERATELY. The subject is a `User`, and `users` has no `store_id`
 * column, so `AuditAttribution` falls through to "operator -> null". That is the honest answer: a
 * merchant may own several stores and naming one of them would be the fabrication §2 forbids. WHICH
 * merchant was impersonated is recorded exactly, in `subject_type`/`subject_id`.
 */
class ImpersonationIsAuditedTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        (new User)->flushCache();

        $this->assertTrue($this->operator->canImpersonate(), 'PREMISE: the operator cannot impersonate.');
        $this->assertTrue($this->merchant->canBeImpersonated(), 'PREMISE: the merchant is not impersonable.');
    }

    private function rows(?string $action = null): array
    {
        $query = AuditLog::query()->withoutGlobalScopes()->orderBy('id');

        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query->get()->all();
    }

    private function takeRoute(User $target): string
    {
        return route('login.with.admin', Crypt::encrypt($target->id));
    }

    // ---------------------------------------------------------------------------------------
    //  TAKING
    // ---------------------------------------------------------------------------------------

    public function test_an_operator_taking_a_merchant_identity_is_recorded(): void
    {
        $this->assertCount(0, $this->rows(), 'PREMISE: the trail is not empty before the action.');

        $this->actingAs($this->operator)->get($this->takeRoute($this->merchant))
            ->assertRedirect('dashboard');

        $recorded = $this->rows('impersonation_started');

        $this->assertCount(1, $recorded,
            'taking a merchant identity left no audit row, or left more than one');

        $row = $recorded[0];

        $this->assertSame(User::class, $row->subject_type);
        $this->assertSame((int) $this->merchant->id, (int) $row->subject_id,
            'the row does not say WHICH merchant was impersonated');
    }

    /**
     * THE CORE ASSERTION OF THIS FILE. See the class docblock: the guard holds the MERCHANT by the
     * time the event fires, so a row built from `auth()` names the merchant as the actor.
     */
    public function test_the_row_names_the_operator_although_the_guard_already_holds_the_merchant(): void
    {
        $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        $row = $this->rows('impersonation_started')[0] ?? null;

        $this->assertNotNull($row, 'no row was written at all');
        $this->assertSame((int) $this->operator->id, (int) $row->user_id,
            'the audit row names the impersonated merchant as the actor -- it reads as the merchant '
            . 'having impersonated themselves, which is the attribution this slice exists to prevent');
        $this->assertNotSame((int) $this->merchant->id, (int) $row->user_id);
    }

    /** An operator action concerning a multi-store merchant names no single store. */
    public function test_the_row_attributes_no_store_rather_than_defaulting_to_store_one(): void
    {
        $this->makeStore($this->merchant, ['default_language' => 'ar']);

        $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        $row = $this->rows('impersonation_started')[0] ?? null;

        $this->assertNotNull($row);
        $this->assertNull($row->store_id,
            'an operator impersonation was filed under a store id (' . var_export($row->store_id, true)
            . '); the merchant owns two, so naming one of them is a fabrication');
    }

    // ---------------------------------------------------------------------------------------
    //  LEAVING
    // ---------------------------------------------------------------------------------------

    public function test_returning_to_the_operator_identity_is_recorded(): void
    {
        $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        $this->assertCount(1, $this->rows('impersonation_started'), 'PREMISE: the take was not recorded.');

        $this->get(route('exit.admin'))->assertRedirect('stores');

        $ended = $this->rows('impersonation_ended');

        $this->assertCount(1, $ended, 'leaving an impersonation left no audit row');
        $this->assertSame((int) $this->operator->id, (int) $ended[0]->user_id);
        $this->assertSame((int) $this->merchant->id, (int) $ended[0]->subject_id,
            'the row does not say which identity was left');
    }

    public function test_an_exit_with_no_active_impersonation_records_nothing(): void
    {
        $this->actingAs($this->operator)->get(route('exit.admin'))
            ->assertRedirect('stores');

        $this->assertCount(0, $this->rows(),
            'a no-op exit wrote an audit row, so the trail now contains events that did not happen');
    }

    // ---------------------------------------------------------------------------------------
    //  A REFUSED ATTEMPT MUST NOT BE RECORDED AS ONE THAT HAPPENED
    // ---------------------------------------------------------------------------------------

    public function test_a_merchant_attempting_to_impersonate_is_refused_and_records_nothing(): void
    {
        $victim = $this->makeMerchant();
        (new User)->flushCache();

        $this->actingAs($this->merchant)->get($this->takeRoute($victim))
            ->assertForbidden();

        $this->assertCount(0, $this->rows(),
            'a REFUSED impersonation was written to the trail as though it had succeeded');
    }

    public function test_an_attempt_against_another_operator_is_refused_and_records_nothing(): void
    {
        $other = $this->makePlatformAdmin();
        (new User)->flushCache();

        $this->actingAs($this->operator)->get($this->takeRoute($other))
            ->assertForbidden();

        $this->assertCount(0, $this->rows());
    }

    /**
     * A FAILED take() MUST NOT REPORT SUCCESS -- and before this slice it did.
     *
     * `ImpersonateManager::take()` swallows every exception (`catch (\Exception $e) { unset($e);
     * return false; }`) and the controller IGNORED its return value, redirecting to `dashboard`
     * regardless. The operator was then still themselves, on a page that said otherwise, with no
     * event dispatched and nothing logged.
     *
     * Forced through the container rather than by breaking the session, because the manager is
     * resolved from it by the `Impersonate` facade -- so this drives the real controller against a
     * real refusal instead of asserting on the source text.
     */
    public function test_an_impersonation_that_fails_is_reported_rather_than_redirected_as_success(): void
    {
        $this->app->instance(ImpersonateManager::class, new class($this->app) extends ImpersonateManager
        {
            public function take($from, $to, $guardName = null)
            {
                return false;
            }
        });

        $response = $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        /*
         * COMPARED AGAINST THE REAL URL, and the first version of this assertion was worthless:
         * it read `assertNotSame('dashboard', $location)`, and `Location` is the absolute
         * `http://localhost/dashboard`, so the bare string could never match and the case passed
         * whether the return value was checked or not. The mutation harness caught it -- reverting
         * the fix left this test GREEN. This is the project's own "passes for the wrong reason"
         * failure mode, in my own test, found by the control that exists for it.
         */
        $location = (string) $response->headers->get('Location');

        $this->assertNotSame(url('dashboard'), $location,
            'a failed impersonation still redirected to the dashboard as though it had worked');
        $response->assertSessionHas('error');
        $this->assertCount(0, $this->rows(),
            'a failed impersonation was recorded as having happened');
        $this->assertSame((int) $this->operator->id, (int) auth('web')->id(),
            'PREMISE: the guard changed anyway, so the fake did not model a real failure');
    }

    // ---------------------------------------------------------------------------------------
    //  EXACTLY ONE LISTENER, AND WHY THAT NEEDS A TEST
    // ---------------------------------------------------------------------------------------

    /**
     * A TRAP IN THIS APPLICATION THAT COST TWO AUDIT ROWS PER IMPERSONATION, MEASURED.
     *
     * `RecordImpersonation` was first written to `app/Listeners/` -- the obvious home -- and
     * declared in `EventServiceProvider::$listen`. Every impersonation then wrote TWO identical
     * audit rows, because the listener was registered TWICE. Dumped from the dispatcher's own
     * `listeners` property:
     *
     *     TakeImpersonation: 2 raw entries
     *       [0] ["App\Listeners\RecordImpersonation","handleTake"]
     *       [1] App\Listeners\RecordImpersonation@handleTake
     *
     * EVENT AUTO-DISCOVERY OVER `app/Listeners/` IS ACTIVE HERE, AND THIS REPOSITORY ALREADY SAID
     * SO -- in `AppServiceProvider`'s note on `NormaliseRewardRedemptionFlag` and in
     * `EnforceMarketingConsentOnMail`'s docblock, neither of which I read before writing the class
     * into the obvious directory. Laravel 11's `Application::configure()` calls `->withEvents()`
     * unconditionally and the framework EventServiceProvider it registers discovers that directory,
     * so a class there is registered by its handler signature ON TOP of any `$listen` mapping.
     * `App\Providers\EventServiceProvider::shouldDiscoverEvents()` returning `false` is about that
     * provider only, and misleads.
     *
     * Two measurements settled it, because my first explanation was wrong: I thought the
     * array-callable mapping was being normalised and registered twice. Rewriting it to the
     * `Class@method` string form changed NOTHING -- still two rows. MOVING THE CLASS to
     * `app/Support/Audit/` dropped it to one. The control case is `MessageSending`, whose listener
     * lives in `app/Services/Marketing/`, outside the scanned directory: exactly one entry.
     *
     * The two listeners that DO sit in `app/Listeners/` are handled the other way -- made
     * idempotent, with a test pinning that, so running twice is harmless. THAT OPTION IS NOT OPEN
     * TO AN AUDIT ROW: two rows is two rows, and a trail that double-counts what an operator did is
     * worse than an absent one because it will be believed. So this class follows the
     * `EnforceMarketingConsentOnMail` precedent, and these two cases make moving it back a red test
     * rather than a silently doubled trail.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('impersonationEvents')]
    public function test_each_impersonation_event_has_exactly_one_listener(string $event): void
    {
        $listeners = \Illuminate\Support\Facades\Event::getListeners($event);

        $this->assertCount(1, $listeners,
            class_basename($event) . ' has ' . count($listeners) . ' listeners. Two means the '
            . 'recorder is registered twice -- most likely because it was moved into '
            . '`app/Listeners/`, which this application auto-discovers ON TOP of the explicit '
            . '`$listen` mapping. Every impersonation would write two audit rows.');
    }

    public static function impersonationEvents(): array
    {
        return [
            'take' => [TakeImpersonation::class],
            'leave' => [LeaveImpersonation::class],
        ];
    }

    /** The recorder is NOT in the auto-discovered directory, stated as a property and not a habit. */
    public function test_the_recorder_does_not_live_in_the_auto_discovered_listener_directory(): void
    {
        $file = (new \ReflectionClass(RecordImpersonation::class))->getFileName();

        $this->assertIsString($file);
        $this->assertStringNotContainsString(
            DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Listeners' . DIRECTORY_SEPARATOR,
            (string) $file,
            'the recorder is back in app/Listeners/, where auto-discovery registers it a second '
            . 'time on top of the explicit $listen mapping');
    }

    // ---------------------------------------------------------------------------------------
    //  THE RECORDER ITSELF
    // ---------------------------------------------------------------------------------------

    /** An explicit actor beats the guard; this is what the listener relies on. */
    public function test_record_prefers_an_explicitly_passed_actor_over_the_guard(): void
    {
        $this->actingAs($this->merchant);

        AuditLog::record('test_action', $this->store, [], [], $this->operator);

        $row = $this->rows('test_action')[0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame((int) $this->operator->id, (int) $row->user_id);
    }

    /** With no explicit actor the existing behaviour is unchanged: the web guard answers. */
    public function test_record_still_reads_the_web_guard_when_no_actor_is_passed(): void
    {
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        (new User)->flushCache();

        $this->actingAs(User::find($this->merchant->id));

        AuditLog::record('test_action', null);

        $row = $this->rows('test_action')[0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame((int) $this->merchant->id, (int) $row->user_id);
        $this->assertSame((int) $this->store->id, (int) $row->store_id,
            'a merchant lost the store attribution they already had');
    }

    /**
     * A SHOPPER IS NOT AN ACTING `User`, AND `auth()->id()` DID NOT KNOW THAT.
     *
     * `user_id` means a `users` row. The recorder read `auth()->id()` -- the DEFAULT guard, which
     * CLAUDE.md records that `CustomAuth` can leave set to `customers` or `deliveryboy` -- while
     * reading the actor for store attribution from `auth('web')` explicitly. So on any request
     * where the default guard is not `web`, the two disagreed and a CUSTOMER id could be written
     * into a column that means a `User` id. Latent rather than live (every current call site is
     * panel-only), and now impossible: both halves read `web`.
     */
    public function test_a_customer_session_does_not_become_the_acting_user(): void
    {
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'C', 'last_name' => 'Shopper',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('x'),
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($customer, 'customers');

        AuditLog::record('test_action', null);

        $row = $this->rows('test_action')[0] ?? null;

        $this->assertNotNull($row);
        $this->assertNull($row->user_id,
            'a Customer id (' . var_export($row->user_id, true) . ') was written as the acting User');
    }
}
