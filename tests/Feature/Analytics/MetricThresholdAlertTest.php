<?php

namespace Tests\Feature\Analytics;

use App\Models\Setting;
use App\Models\StoreNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * ANA-009 — THE SCHEDULED CHECK, and the two ways a scheduled alert goes wrong.
 *
 * THE FIRST WAY IS NOISE. The command is on an hourly schedule, so a breach that lasts a day would
 * write twenty-four identical notices unless the alert is deduplicated on the PERIOD rather than on
 * the run. A merchant who gets twenty-four of anything switches it off, and then the feature exists
 * but is not used — which is indistinguishable from not shipping it. Two tests here drive the
 * command twice and assert ONE row.
 *
 * THE SECOND WAY IS SILENCE, and it is the one a green suite hides. A command that visits no store,
 * or writes to the wrong store, or swallows its own exception, passes every test that only asserts
 * "it did not crash". So the tests assert the row's OWNER and STORE, and the no-configuration case
 * asserts an empty table rather than a successful exit code.
 *
 * WHAT THIS DELIBERATELY DOES NOT TEST, because it deliberately does not happen: no e-mail and no
 * push. `push_sent => false` is asserted as the contract it is — the alert is written to the store's
 * notifications and nothing more — so that ANA-009 cannot be reported as fully delivered while the
 * delivery half is unwired. That is recorded on the requirement row, not hidden here.
 */
final class MetricThresholdAlertTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const COMMAND = 'analytics:check-metric-thresholds';

    private function setThreshold(int $storeId, string $name, $value): void
    {
        Setting::putForStore($name, (string) $value, $storeId, 1);
    }

    private function paidOrder($store, float $amount, string $paidAt, string $currency = 'USD'): void
    {
        $order = $this->makeOrder($store, ['final_price' => $amount]);

        DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->update([
                'payment_status' => 'paid',
                'paid_amount' => $amount,
                'remaining_amount' => 0.0,
                'paid_at' => $paidAt,
                'currency_code' => $currency,
            ]);
    }

    /** A store below a floor it set, with today's date so the default window reaches it. */
    private function breachedStore(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '500');
        $this->setThreshold((int) $store->id, 'alert_window_days', '7');
        $this->paidOrder($store, 100.0, now()->subDay()->toDateTimeString());

        return [$merchant, $store];
    }

    private function alerts(int $storeId)
    {
        return StoreNotification::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('type', 'metric_threshold_breached')
            ->get();
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Silence
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_platform_where_nobody_configured_a_floor_gets_no_alerts(): void
    {
        [, $store] = $this->makeTenant();

        // No sales at all, and no configuration. A shipped default would fire here.
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertCount(0, $this->alerts((int) $store->id),
            'an alert was written for a store whose merchant configured nothing');
    }

    public function test_a_store_above_its_floor_gets_no_alert(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '50');
        $this->setThreshold((int) $store->id, 'alert_window_days', '7');
        $this->paidOrder($store, 900.0, now()->subDay()->toDateTimeString());

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertCount(0, $this->alerts((int) $store->id),
            'a store comfortably above its own floor was alerted');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The alert itself
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_breached_floor_writes_one_alert_to_the_right_store_and_owner(): void
    {
        [$merchant, $store] = $this->breachedStore();

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $alerts = $this->alerts((int) $store->id);

        $this->assertCount(1, $alerts, 'the breach was not recorded');

        $alert = $alerts->first();

        $this->assertSame((int) $store->id, (int) $alert->store_id);
        $this->assertSame((int) $merchant->id, (int) $alert->user_id,
            'the alert was addressed to somebody other than the store\'s owner');

        // The figures, so the sentence can be checked against what was measured.
        $data = is_array($alert->data) ? $alert->data : json_decode((string) $alert->data, true);

        $this->assertSame('payments_received', $data['metric']);
        $this->assertSame(500.0, (float) $data['floor']);
        $this->assertSame(100.0, (float) $data['actual']);
        $this->assertSame(7, (int) $data['days']);
    }

    public function test_the_alert_is_not_pushed_and_that_is_the_contract(): void
    {
        [, $store] = $this->breachedStore();

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $alert = $this->alerts((int) $store->id)->first();

        $this->assertNotNull($alert);
        $this->assertFalse((bool) $alert->push_sent,
            'the alert claims to have been pushed. ANA-009 writes a notification and does not send: '
            .'a row that says `push_sent` would make the requirement look delivered when the '
            .'delivery half is deliberately unwired');
    }

    /**
     * THE DEDUP KEY MUST FIT THE COLUMNS IT IS WRITTEN INTO, and this is the test that was missing.
     *
     * FOUND BY REVIEW, NOT BY THE SUITE, and that is the point of writing it down. The first version
     * of this feature wrote `payments_received:2026-06-15` into `store_notifications.reference_id`,
     * which the only migration for that table declares `unsignedBigInteger`. SQLite's type affinity
     * stores a non-numeric string in an INTEGER column VERBATIM, so every assertion in this file
     * passed. On a real MySQL it would not: the `mysql` connection is `strict => false`, which
     * COERCES the string to 0 — so the first alert a store ever raised would match every later one
     * and suppress it, one notice per store for ever. The `mariadb` connection is `strict => true`,
     * which raises 1366, the writer catches it, and NO alert is written at all.
     *
     * That is the P01-SEC-13 shape exactly (`customers.password_otp` INTEGER receiving a 60-char
     * bcrypt hash), and CLAUDE.md names it as the reason schema-sensitive work needs a real MySQL.
     * `tests/Support/Security/bin/verify-ana009-alert-key-on-mysql.php` is that proof.
     *
     * WHAT THIS TEST CAN DO ON SQLITE is read the value back and insist it is integer-shaped. That
     * is engine-independent and it WOULD have caught the original defect, which is the only reason
     * to add it.
     */
    public function test_the_dedup_key_is_written_in_the_shape_its_columns_declare(): void
    {
        [, $store] = $this->breachedStore();

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $alert = $this->alerts((int) $store->id)->first();
        $this->assertNotNull($alert);

        $referenceId = $alert->getRawOriginal('reference_id');

        // Integer-shaped, not merely numeric: `(int)` of a string that is not an integer is 0, and 0
        // is exactly the value a non-strict MySQL would have silently stored.
        $this->assertSame((string) (int) $referenceId, (string) $referenceId,
            '`reference_id` is an unsignedBigInteger column and received '
            .var_export($referenceId, true).'. On a real MySQL that is coerced to 0 (non-strict) or '
            .'rejected with 1366 (strict); SQLite stores it verbatim and hides both.');

        $this->assertGreaterThan(0, (int) $referenceId,
            '`reference_id` is 0, which is the value a non-strict MySQL writes when the string it '
            .'was given is not a number — every later alert would dedup against this row');

        $this->assertSame(
            (int) \Carbon\CarbonImmutable::now()->startOfDay()->format('Ymd'),
            (int) $referenceId,
            'the integer is not the window\'s end day, so the dedup key does not identify the period'
        );

        $this->assertLessThanOrEqual(50, strlen((string) $alert->reference_type),
            '`reference_type` is a string(50) and would be truncated — a truncated dedup key '
            .'deduplicates the wrong things together');

        $this->assertSame(
            \App\Support\Analytics\MetricThreshold::REFERENCE_PREFIX.'payments_received',
            (string) $alert->reference_type);
    }

    public function test_every_metric_key_still_fits_the_reference_type_column(): void
    {
        /*
         * A FORWARD RATCHET on the column width. Adding a metric to `MetricThreshold::METRICS` is a
         * one-line change and the truncation it could cause is silent: MySQL in non-strict mode cuts
         * the value to 50 characters, and two metrics whose prefixed names agree in their first 50
         * would then share one dedup key and suppress each other's alerts.
         */
        foreach (array_keys(\App\Support\Analytics\MetricThreshold::METRICS) as $key) {
            $reference = \App\Support\Analytics\MetricThreshold::REFERENCE_PREFIX.$key;

            $this->assertLessThanOrEqual(50, strlen($reference),
                "the reference_type for metric `{$key}` is ".strlen($reference)
                .' characters and the column is 50');
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Noise
     * ---------------------------------------------------------------------------------------------
     */

    public function test_running_the_check_again_the_same_day_does_not_write_a_second_alert(): void
    {
        [, $store] = $this->breachedStore();

        $this->artisan(self::COMMAND)->assertExitCode(0);
        $this->artisan(self::COMMAND)->assertExitCode(0);
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertCount(1, $this->alerts((int) $store->id),
            'the hourly schedule would write one notice per tick — twenty-four a day for one breach');
    }

    /**
     * THE DEDUP KEY MUST IDENTIFY THE PERIOD, not merely exist — and this pair is what proves it.
     *
     * WRITTEN BECAUSE A MUTATION SURVIVED. Replacing the dedup read's
     * `where('reference_id', $period_day)` with `whereNotNull('reference_id')` passed every test in
     * this file: within one day and one metric there is only one row, so a key that identifies
     * nothing still finds it. The failure that loose key would actually cause is the one review
     * found on the MySQL side — the first alert a store ever raises suppressing every later one —
     * and these two cases are the only ones that can see it.
     *
     * TWO METRICS, ONE WINDOW. Both floors are breached in the same period, so a key that drops the
     * metric collapses them into one notice and the merchant is told about one of their two
     * problems.
     */
    public function test_two_metrics_breaching_in_the_same_window_get_their_own_alerts(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '500');
        $this->setThreshold((int) $store->id, 'alert_min_order_value', '500');
        $this->setThreshold((int) $store->id, 'alert_window_days', '7');
        $this->paidOrder($store, 100.0, now()->subDay()->toDateTimeString());

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $alerts = $this->alerts((int) $store->id);

        $this->assertCount(2, $alerts,
            'two different metrics breached and the store was told about '.$alerts->count()
            .'. A dedup key that does not name the metric collapses them.');

        $this->assertSame(
            [
                \App\Support\Analytics\MetricThreshold::REFERENCE_PREFIX.'order_value',
                \App\Support\Analytics\MetricThreshold::REFERENCE_PREFIX.'payments_received',
            ],
            $alerts->pluck('reference_type')->map(static fn ($t): string => (string) $t)->sort()->values()->all()
        );
    }

    /**
     * A LATER PERIOD IS A NEW ALERT, which is the other half of the key.
     *
     * DRIVEN AT THE SERVICE, not through the command, and deliberately: the command reads the clock
     * with `time()` and takes no injectable `$now`, so there is no way to move its day from a test.
     * The predicate being tested lives in `metricThresholdBreached()`, so that is where it is driven
     * — two breach arrays for the same metric in two different windows. Without this, a key that
     * drops the day reads as correct for ever, because every test in this file runs inside one day.
     */
    public function test_the_same_metric_in_a_later_window_gets_a_new_alert(): void
    {
        [, $store] = $this->makeTenant();

        $service = app(\App\Services\StoreNotificationService::class);

        $today = (int) \Carbon\CarbonImmutable::now()->startOfDay()->format('Ymd');
        $tomorrow = (int) \Carbon\CarbonImmutable::now()->addDay()->startOfDay()->format('Ymd');

        $breach = static fn (int $day): array => [
            'key' => 'payments_received',
            'label' => 'Payments received',
            'floor' => 500.0,
            'actual' => 100.0,
            'days' => 7,
            'currency' => 'USD',
            'period_type' => \App\Support\Analytics\MetricThreshold::REFERENCE_PREFIX.'payments_received',
            'period_day' => $day,
            'period_key' => 'payments_received:'.$day,
        ];

        $first = $service->metricThresholdBreached((int) $store->id, $breach($today));
        $again = $service->metricThresholdBreached((int) $store->id, $breach($today));
        $next = $service->metricThresholdBreached((int) $store->id, $breach($tomorrow));

        $this->assertNotNull($first);
        $this->assertNotNull($again);
        $this->assertNotNull($next);

        $this->assertSame($first->id, $again->id,
            'the same metric in the same window wrote a second notice');

        $this->assertNotSame($first->id, $next->id,
            'the NEXT period reused the previous period\'s notice, so a store that stays below its '
            .'floor is told once and then never again');

        $this->assertCount(2, $this->alerts((int) $store->id));
    }

    public function test_the_second_run_reports_no_new_alert(): void
    {
        [, $store] = $this->breachedStore();

        $this->artisan(self::COMMAND)->expectsOutputToContain('recorded 1 new alert');
        $this->artisan(self::COMMAND)->expectsOutputToContain('recorded 0 new alert');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Tenancy
     * ---------------------------------------------------------------------------------------------
     */

    public function test_one_stores_breach_does_not_alert_another_store(): void
    {
        [, $mine] = $this->breachedStore();
        [, $theirs] = $this->makeTenant();

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertCount(1, $this->alerts((int) $mine->id));
        $this->assertCount(0, $this->alerts((int) $theirs->id),
            'a breach in one store wrote a notification into another store');
    }

    public function test_every_breached_store_is_visited_not_just_the_first(): void
    {
        [, $first] = $this->breachedStore();
        [, $second] = $this->breachedStore();
        [, $third] = $this->breachedStore();

        $this->artisan(self::COMMAND)->assertExitCode(0);

        foreach ([$first, $second, $third] as $store) {
            $this->assertCount(1, $this->alerts((int) $store->id),
                'store '.$store->id.' was not checked — the command stopped early or visited one store');
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The schedule
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_check_is_actually_scheduled(): void
    {
        /*
         * A COMMAND NOBODY RUNS IS NOT A FEATURE. The engine and its tests can all be green while
         * the alert never fires in production because nothing calls it — the exact shape of
         * "implemented but inert". Asserted against `routes/console.php` with comments stripped,
         * because that file's own prose names this command repeatedly (CLAUDE.md records four
         * censuses on 2026-11-21 alone that were satisfied by a comment about the thing measured).
         */
        $source = $this->sourceWithoutComments('routes/console.php');

        $this->assertStringContainsString("Schedule::command('".self::COMMAND."')", $source,
            'the threshold check is not on the schedule, so no merchant would ever be alerted');

        $this->assertMatchesRegularExpression(
            "/Schedule::command\('".preg_quote(self::COMMAND, '/')."'\)[^;]*->withoutOverlapping\(\)/",
            $source,
            'the scheduled check may overlap itself; two concurrent ticks race on the dedup read'
        );
    }
}
