<?php

namespace Tests\Feature\Analytics;

use App\Models\Setting;
use App\Support\Analytics\MetricThreshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-009 — THE MERCHANT CAN ACTUALLY SET THE NUMBER.
 *
 * THIS FILE IS THE REQUIREMENT, not a nicety on top of it. ANA-009 asks for merchant-CONFIGURABLE
 * thresholds, and an engine with no configuration surface is not configurable however good the
 * engine is: the floors would be settable only by somebody with database access, which is not the
 * merchant. So the screen is driven here as a merchant would drive it — render, save, read back,
 * clear — and the engine tests next door are not allowed to stand in for it.
 *
 * THE DOOR IS DRIVEN BEFORE THE FORM. A write that decides what a store is told about its money
 * must not be reachable by somebody who cannot read those figures, and this codebase's default
 * ordering is wrong in both directions: Laratrust has no super-admin bypass (so `isAbleTo()` alone
 * refuses the platform operator) and a merchant created before a permission name existed would be
 * locked out of their own store. `ReportPermission` encodes the right ordering; these tests assert
 * the route really inherits it.
 *
 * NO `store_id` IS A PARAMETER, and one is sent anyway to prove it is ignored. CLAUDE.md names a
 * tenant predicate built from caller-supplied input as the single most productive defect class in
 * this repository — four separate defects — so the test sends the other tenant's id in the body and
 * asserts their settings are untouched.
 */
final class ThresholdConfigurationFormTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/analytics';

    private const POST = '/analytics/thresholds';

    private const PERMISSION = 'Manage Reports';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    private function refused($response): bool
    {
        return $response->status() === 403 || ($response->isRedirect() && session()->has('error'));
    }

    /** A merchant signed in on their own store, holding the report permission. */
    private function merchantOn($store, $merchant)
    {
        $merchant->current_store = $store->id;
        $merchant->save();

        return $merchant->fresh();
    }

    private function stored(int $storeId, string $name): ?string
    {
        $value = Setting::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('name', $name)
            ->value('value');

        return $value === null ? null : (string) $value;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The surface exists and shows what is stored
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_screen_offers_a_field_for_every_metric_a_floor_can_be_set_on(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($store, $merchant))->get(self::URI);

        $response->assertOk();

        foreach (array_keys(MetricThreshold::METRICS) as $metricKey) {
            $response->assertSee('name="'.$metricKey.'"', false);
        }

        $response->assertSee('name="window_days"', false);
        $response->assertSee(route('analytics.thresholds.store'), false);
    }

    public function test_the_form_shows_the_floor_the_merchant_already_set(): void
    {
        [$merchant, $store] = $this->makeTenant();

        Setting::putForStore('alert_min_payments_received', '750.5', (int) $store->id, (int) $merchant->id);

        $response = $this->actingAs($this->merchantOn($store, $merchant))->get(self::URI);

        $response->assertOk();
        $response->assertSee('value="750.5"', false);
    }

    public function test_the_form_does_not_show_another_stores_floor(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [$other, $theirs] = $this->makeTenant();

        Setting::putForStore('alert_min_payments_received', '12345.67', (int) $theirs->id, (int) $other->id);

        $response = $this->actingAs($this->merchantOn($mine, $merchant))->get(self::URI);

        $response->assertOk();
        $response->assertDontSee('12345.67', false);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Saving
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_merchant_can_set_a_floor_and_the_checker_reads_it(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($store, $merchant))->post(self::POST, [
            'payments_received' => '500',
            'order_value' => '',
            'window_days' => '14',
        ]);

        $this->assertFalse($this->refused($response), 'the merchant could not save their own thresholds');

        // THE ROUND TRIP, not just the row: what the checker reads is what the merchant typed.
        $configured = MetricThreshold::configuredFor((int) $store->id);

        $this->assertArrayHasKey('payments_received', $configured, 'the saved floor is invisible to the checker');
        $this->assertSame(500.0, $configured['payments_received']['floor']);
        $this->assertSame(14, $configured['payments_received']['days']);
        $this->assertArrayNotHasKey('order_value', $configured,
            'a field left blank became a watched metric');
    }

    public function test_clearing_the_field_switches_the_alert_off(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $signedIn = $this->merchantOn($store, $merchant);

        Setting::putForStore('alert_min_payments_received', '500', (int) $store->id, (int) $merchant->id);

        $this->actingAs($signedIn)->post(self::POST, [
            'payments_received' => '',
            'order_value' => '',
            'window_days' => '',
        ]);

        $this->assertSame([], MetricThreshold::configuredFor((int) $store->id),
            'clearing every field left the alerts on — a merchant cannot turn this off');
    }

    public function test_a_floor_of_zero_can_be_saved_and_is_kept(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->actingAs($this->merchantOn($store, $merchant))->post(self::POST, [
            'payments_received' => '0',
            'window_days' => '7',
        ]);

        /*
         * "Tell me if I take nothing at all" is a real instruction and must survive the form. A
         * single `empty()` or a falsy check anywhere between the input and the row turns it into
         * "do not watch this", silently.
         */
        $configured = MetricThreshold::configuredFor((int) $store->id);

        $this->assertArrayHasKey('payments_received', $configured,
            'a floor of 0 was discarded as though the field were blank');
        $this->assertSame(0.0, $configured['payments_received']['floor']);
    }

    /**
     * WHAT THE SHIPPED PATH DOES — and it is not this handler that does it.
     *
     * MEASURED while mutation-testing this slice: `TrimStrings` AND `ConvertEmptyStringsToNull` are
     * both in the GLOBAL middleware stack (seven entries, both present at runtime). So a field
     * holding only spaces reaches the handler as **null**, not as spaces, and a cleared field
     * reaches it as null too. Mutating this handler's own `trim()` therefore could not fail this
     * test — it SURVIVED the mutation, which is precisely the "passes for the wrong reason" failure
     * CLAUDE.md names.
     *
     * So this test is kept for the end-to-end property a merchant actually experiences, and labelled
     * as such; the test below it is the one that holds the handler to its own share of the
     * guarantee. Both are wanted: this one fails if the global stack is ever narrowed, the next one
     * fails if the handler stops trimming.
     */
    public function test_whitespace_alone_is_treated_as_blank_on_the_shipped_path(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->actingAs($this->merchantOn($store, $merchant))->post(self::POST, [
            'payments_received' => '   ',
            'window_days' => '',
        ]);

        $this->assertSame('', $this->stored((int) $store->id, 'alert_min_payments_received'),
            'a field holding only spaces was stored verbatim; it means the same as empty');

        $this->assertSame([], MetricThreshold::configuredFor((int) $store->id),
            'a field holding only spaces became a watched metric');
    }

    /**
     * THE HANDLER'S OWN SHARE OF IT, with the framework's answer taken away.
     *
     * `TrimStrings` is removed for this request only, so the spaces really arrive as spaces and the
     * handler's `trim()` is the single thing standing between them and a settings row holding
     * `'   '`. Without this, that `trim()` is unreachable code that reads as a control.
     *
     * This is not a hypothetical: the global stack is editable in `bootstrap/app.php`, and a handler
     * whose correctness silently depends on middleware somebody else configured is the shape of a
     * defect that appears years later in a different context (an artisan call, a queued job, a
     * console command reusing the method).
     */
    public function test_the_handler_trims_even_when_the_framework_does_not(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\TrimStrings::class);

        $this->actingAs($this->merchantOn($store, $merchant))->post(self::POST, [
            'payments_received' => '   ',
            'window_days' => '7',
        ]);

        $this->assertSame('', $this->stored((int) $store->id, 'alert_min_payments_received'),
            'with the framework\'s trimming removed, the handler stored a value of spaces');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Refusals the merchant can see
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_negative_floor_is_refused_with_a_message(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($store, $merchant))->post(self::POST, [
            'payments_received' => '-100',
            'window_days' => '7',
        ]);

        /*
         * The CHECKER already refuses a negative floor, silently and correctly. But a silent refusal
         * of something the merchant just typed presents as "my setting did not save", so the form
         * says so instead.
         */
        $this->assertTrue($this->refused($response),
            'a negative floor was accepted by the form; it can never be breached, so the alert would be dead and silent');

        $this->assertNull($this->stored((int) $store->id, 'alert_min_payments_received'),
            'the refused value was written anyway');
    }

    public function test_an_absurd_window_is_refused_by_the_form(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($store, $merchant))->post(self::POST, [
            'payments_received' => '500',
            'window_days' => '100000',
        ]);

        $this->assertTrue($this->refused($response), 'a 100,000-day window was accepted by the form');
        $this->assertNull($this->stored((int) $store->id, 'alert_min_payments_received'),
            'a rejected submission still wrote the other fields');
    }

    public function test_a_non_numeric_floor_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($store, $merchant))->post(self::POST, [
            'payments_received' => 'lots',
            'window_days' => '7',
        ]);

        $this->assertTrue($this->refused($response));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_signed_out_visitor_cannot_write_a_threshold(): void
    {
        $response = $this->post(self::POST, ['payments_received' => '500']);

        $this->assertTrue($response->isRedirect(), 'a guest wrote a store\'s alert configuration');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; this codebase redirects or answers 403');
    }

    public function test_a_staff_member_without_the_report_permission_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();

        $this->assertFalse($staff->fresh()->isAbleTo(self::PERMISSION),
            'Premise: the staff member holds no report permission.');

        $response = $this->actingAs($staff->fresh())->post(self::POST, [
            'payments_received' => '500',
            'window_days' => '7',
        ]);

        $this->assertTrue($this->refused($response),
            'a staff member who cannot read the figures configured what the store is alerted about');

        $this->assertNull($this->stored((int) $store->id, 'alert_min_payments_received'));
    }

    public function test_the_seeded_report_permission_is_enough(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);
        $holder->current_store = $store->id;
        $holder->save();

        $response = $this->actingAs($holder->fresh())->post(self::POST, [
            'payments_received' => '500',
            'window_days' => '7',
        ]);

        $this->assertFalse($this->refused($response),
            'the seeded report permission did not open the form that the same permission opens the screen with');
        $this->assertSame('500', $this->stored((int) $store->id, 'alert_min_payments_received'));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Tenancy — the store is never a parameter
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_store_id_in_the_body_is_ignored(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($mine, $merchant))->post(self::POST, [
            'store_id' => $theirs->id,
            'payments_received' => '500',
            'window_days' => '7',
        ]);

        $this->assertFalse($this->refused($response));

        $this->assertSame('500', $this->stored((int) $mine->id, 'alert_min_payments_received'),
            'the merchant\'s own store did not receive the setting');

        $this->assertNull($this->stored((int) $theirs->id, 'alert_min_payments_received'),
            'a `store_id` in the request body placed the threshold in another tenant — the exact '
            .'pattern CLAUDE.md names as this codebase\'s most productive defect class');
    }

    /**
     * GAP-223(b). An operator with nothing selected must not land on store 1.
     */
    public function test_an_operator_who_has_selected_no_store_cannot_write_a_threshold(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $response = $this->actingAs($operator)->post(self::POST, [
            'payments_received' => '500',
            'window_days' => '7',
        ]);

        $this->assertTrue($this->refused($response),
            'the platform operator wrote an alert threshold with no store selected; `getCurrentStore()` '
            .'answers the hardcoded literal 1 for a super admin, so this would configure a real merchant\'s store');

        $this->assertNull($this->stored(1, 'alert_min_payments_received'),
            'a threshold was written onto store 1');
    }
}
