<?php

namespace Tests\Unit\Saas;

use App\Services\SaasOperationsProbe;
use App\Services\SaasPlatformOperations;
use App\Services\SaasPlatformResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * OPS-001 — "مراقبة الخوادم وقواعد البيانات والطوابير والتكاملات" / MONITOR SERVERS, DATABASES,
 * QUEUES AND INTEGRATIONS.
 * =================================================================================================
 *
 * The SERVERS half is `GAP-195`/`OPS-002` and is covered by `PlatformResourceMeasurementTest`.
 * This file is the other three subjects, and the thing it has to be able to fail on is the same
 * thing: not "is there a screen" but "does the number come from the running system".
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * EVERY FIGURE IS ASSERTED BY CHANGING THE THING IT MEASURES, NOT BY LOOKING PLAUSIBLE
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * A test that asserts a queue depth is a non-negative integer passes against
 * `return 0;` forever, and that is precisely the defect `OPS-002`'s one load-bearing word —
 * حقيقية, REAL — was written to prevent. So the shape of nearly every case here is: read the
 * figure, change the system, read it again, and assert the DIFFERENCE. A collector whose body is
 * replaced by any constant fails, because no constant moves.
 *
 *   jobs waiting              insert a jobs row -> the figure rises by exactly one
 *   jobs held by a worker     stamp reserved_at -> it moves from waiting to held
 *   oldest waiting job        a row with a known past dispatch time -> that time, to the second
 *   failed jobs               insert a failure -> total and window both rise; an old failure
 *                             raises the total and NOT the window
 *   tables                    create a table -> the count rises by exactly one
 *   size                      insert a megabyte -> the figure grows
 *   gateway enablement        switch a gateway on for two stores -> two; blank a required
 *                             credential -> complete falls and incomplete appears
 *   cache/session rows        insert a row -> the count rises
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * AND THE REFUSALS ARE ASSERTED TOO, because they are the half an operator is misled by
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The test suite runs `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` and `SESSION_DRIVER=array`
 * (`.env.testing`), while production runs all three on the database. So the refusing branches are
 * the DEFAULT here and the measuring branches have to be driven by configuration — which is the
 * right way round, because it means the "this deployment cannot be measured" sentences are
 * exercised by every run rather than only on a host nobody has.
 *
 * `sync` IS THE CASE TO GET RIGHT. A deployment with no queue has no depth, and reporting 0
 * pending there is true and useless: every job ran inside the web request. The figure must be
 * withheld with that sentence, not shown as zero.
 */
final class PlatformOperationsMeasurementTest extends TestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const PRODUCER = 'app/Services/SaasPlatformOperations.php';

    private const PROBE = 'app/Services/SaasOperationsProbe.php';

    /** Put the deployment on the database queue, which is what production runs. */
    private function onDatabaseQueue(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.table' => 'failed_jobs',
        ]);
    }

    /** @return array<string, array<string, mixed>> keyed by row key */
    private function rowsOf(array $group): array
    {
        $keyed = [];

        foreach ($group as $row) {
            $keyed[$row['key']] = $row;
        }

        return $keyed;
    }

    private function group(string $name, ?SaasOperationsProbe $probe = null, int $hours = 24): array
    {
        return $this->rowsOf(SaasPlatformOperations::measure($probe, $hours)[$name]);
    }

    /*
     * =============================================================================================
     * THE QUEUE
     * =============================================================================================
     */

    /**
     * THE DEPTH IS THE NUMBER OF ROWS THERE REALLY ARE, measured by changing it.
     *
     * Three readings, so the figure has to move twice. One reading against one insert would also
     * pass for a collector that returns `1`.
     */
    public function test_the_queue_depth_rises_with_each_job_that_is_queued(): void
    {
        $this->onDatabaseQueue();

        $before = (int) str_replace(',', '', $this->group('queue')['pending']['value']);

        $this->queueJob('default');
        $after = (int) str_replace(',', '', $this->group('queue')['pending']['value']);

        $this->queueJob('default');
        $this->queueJob('mail');
        $later = (int) str_replace(',', '', $this->group('queue')['pending']['value']);

        $this->assertSame($before + 1, $after,
            'one job was queued and the figure did not rise by one, so it is not counting the queue');
        $this->assertSame($before + 3, $later,
            'two more jobs were queued and the figure did not follow, so it is not counting the queue');
    }

    /**
     * A JOB A WORKER HAS TAKEN IS NOT A JOB THAT IS WAITING, and the two are counted apart because
     * they fail differently: waiting is throughput, held-and-not-shrinking is a dead worker.
     *
     * The discriminating move is that the SAME row changes group — a collector that counted all
     * rows for both figures passes a rising-depth test and fails this one.
     */
    public function test_a_job_held_by_a_worker_leaves_the_waiting_count_and_enters_the_held_count(): void
    {
        $this->onDatabaseQueue();

        $id = $this->queueJob('default');

        $rows = $this->group('queue');
        $this->assertSame('1', $rows['pending']['value']);
        $this->assertSame('0', $rows['reserved']['value']);

        DB::table('jobs')->where('id', $id)->update(['reserved_at' => time()]);

        $rows = $this->group('queue');
        $this->assertSame('0', $rows['pending']['value'],
            'a job a worker has taken is still being reported as waiting');
        $this->assertSame('1', $rows['reserved']['value'],
            'a job a worker has taken is not being reported as held');
    }

    /**
     * THE OLDEST WAITING JOB IS DATED BY WHEN IT WAS HANDED OVER, NOT BY WHEN IT BECOMES DUE.
     *
     * The two rows differ ONLY in `available_at`, and the expected answer is the older
     * `created_at` — so a collector reading `available_at` instead reports the wrong row's time
     * and fails. That is the whole point of the case: `min(available_at)` is the plausible mistake,
     * and a delayed job has not been waiting at all.
     */
    public function test_the_oldest_waiting_job_is_dated_by_its_dispatch_and_not_by_when_it_is_due(): void
    {
        $this->onDatabaseQueue();

        $old = now()->subHours(5);
        $recent = now()->subMinutes(2);

        // Handed over five hours ago, due in an hour: the oldest WAIT.
        $this->queueJob('default', createdAt: $old->timestamp, availableAt: now()->addHour()->timestamp);
        // Handed over two minutes ago, due immediately.
        $this->queueJob('default', createdAt: $recent->timestamp, availableAt: $recent->timestamp);

        $row = $this->group('queue')['oldest_pending'];

        $this->assertSame($old->copy()->startOfSecond()->toDateTimeString(), $row['value'],
            'the oldest waiting job is not dated by when it was dispatched');
        $this->assertNull($row['reason'], 'a figure was produced and a refusal was given beside it');
    }

    /** With nothing waiting there is no oldest one, and that is said rather than dated. */
    public function test_an_empty_queue_withholds_the_oldest_job_instead_of_inventing_one(): void
    {
        $this->onDatabaseQueue();

        $row = $this->group('queue')['oldest_pending'];

        $this->assertNull($row['value']);
        $this->assertNotNull($row['reason'], 'a withheld figure must say why');
    }

    /**
     * THE `sync` DEPLOYMENT HAS NO QUEUE, AND SAYING SO IS THE MEASUREMENT.
     *
     * This is the suite's own default, so it runs on every execution. A zero here would be true
     * and useless: on `sync` every job ran inside the web request that dispatched it, so a slow
     * notification is a slow checkout and there is no backlog anywhere to look at.
     *
     * THE ROWS IN THE TABLE ARE THE DISCRIMINATOR. Two jobs are inserted into `jobs` first, so a
     * collector that counted the table regardless of the driver would answer `2` and fail. The
     * refusal has to come from the driver, not from the table being empty.
     */
    public function test_a_deployment_with_no_queue_withholds_the_depth_and_says_jobs_run_inline(): void
    {
        config(['queue.default' => 'sync', 'queue.connections.sync.driver' => 'sync']);

        $this->queueJob('default');
        $this->queueJob('default');

        $rows = $this->group('queue');

        foreach (['pending', 'reserved', 'oldest_pending'] as $key) {
            $this->assertNull($rows[$key]['value'],
                $key . ' was reported as a figure on a deployment that has no queue');
            $this->assertNotNull($rows[$key]['reason'], $key . ' was withheld without saying why');
        }

        $this->assertStringContainsString('inline', $rows['pending']['reason'],
            'the refusal does not say that jobs run inside the request, which is the fact an operator needs');

        $this->assertStringContainsString('sync', (string) $rows['driver']['value'],
            'the driver row does not name the driver that made the depth unmeasurable');
    }

    /**
     * A QUEUE DRIVER THIS SCREEN CANNOT SEE IS REFUSED AND NAMED, not reported as empty.
     *
     * Reporting 0 pending on a Redis deployment is wrong by however long the backlog is, and it is
     * wrong silently — the `jobs` table really is empty there, because nothing uses it.
     */
    public function test_a_queue_driver_this_screen_cannot_read_is_refused_by_name(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.driver' => 'redis']);

        $this->queueJob('default');

        $rows = $this->group('queue');

        $this->assertNull($rows['pending']['value'],
            'a Redis backlog was reported from a SQL table, which is a figure about the wrong queue');
        $this->assertStringContainsString('redis', $rows['pending']['reason']);
    }

    /*
     * =============================================================================================
     * FAILURES
     * =============================================================================================
     */

    /**
     * THE WINDOW IS A REAL PREDICATE, and the old failure is what proves it.
     *
     * A failure inside the window raises both figures; one outside it raises the total only. A
     * collector that ignored `failed_at` and counted the table twice would pass a one-row test and
     * fail this one — which is the point, because a failure count with no window is the commonest
     * way an operations page misleads.
     */
    public function test_the_failure_window_counts_only_failures_inside_it(): void
    {
        $this->onDatabaseQueue();

        $this->failJob('default', now()->subMinutes(10));
        $this->failJob('mail', now()->subDays(9));

        $rows = $this->group('queue', hours: 24);

        $this->assertSame('2', $rows['failed_total']['value'],
            'the total does not count every recorded failure');
        $this->assertSame('1', $rows['failed_window']['value'],
            'the windowed figure counted a failure from outside its own window');
        $this->assertStringContainsString('24', $rows['failed_window']['period'],
            'the windowed figure does not state the window it covers');

        $wider = $this->group('queue', hours: 24 * 30);

        $this->assertSame('2', $wider['failed_window']['value'],
            'widening the window did not include the older failure, so the window is not a predicate');
        $this->assertStringContainsString('720', $wider['failed_window']['period']);
    }

    /** The newest failure is the newest row's own timestamp. */
    public function test_the_most_recent_failure_is_the_newest_recorded_one(): void
    {
        $this->onDatabaseQueue();

        $this->failJob('default', now()->subDays(3));
        $newest = now()->subMinutes(4)->startOfSecond();
        $this->failJob('default', $newest);

        $this->assertStringContainsString($newest->toDateTimeString(),
            (string) $this->group('queue')['failed_newest']['value'],
            'the most recent failure is not the newest row in the table');
    }

    /** Nothing failed, so there is no most recent failure — said, not dated. */
    public function test_no_failures_withholds_the_most_recent_one(): void
    {
        $this->onDatabaseQueue();

        $row = $this->group('queue')['failed_newest'];

        $this->assertNull($row['value']);
        $this->assertNotNull($row['reason']);
    }

    /**
     * WHICH QUEUES HOLD FAILURES, grouped by the engine.
     *
     * Asserted with two queues and uneven counts, so a collector that returned the first queue
     * name, or the whole table's total against every queue, fails.
     */
    public function test_the_failed_queues_are_named_with_their_own_counts(): void
    {
        $this->onDatabaseQueue();

        $this->failJob('mail', now());
        $this->failJob('mail', now());
        $this->failJob('webhooks', now());

        $value = (string) $this->group('integrations')['failed_queues']['value'];

        $this->assertStringContainsString('mail (2)', $value);
        $this->assertStringContainsString('webhooks (1)', $value);
    }

    /**
     * A DEPLOYMENT THAT RECORDS NO FAILURES CANNOT BE SAID TO HAVE HAD NONE.
     *
     * `QUEUE_FAILED_DRIVER=null` is a real configuration. The rows in the table are inserted first,
     * so a collector that read the table regardless of the configured driver would answer 1 and
     * fail: the refusal has to come from the configuration.
     */
    public function test_a_deployment_that_records_no_failures_withholds_the_figure(): void
    {
        $this->onDatabaseQueue();
        config(['queue.failed.driver' => 'null']);

        $this->failJob('default', now());

        $rows = $this->group('queue');

        $this->assertNull($rows['failed_total']['value'],
            'a failure history was reported on a deployment that records none');
        $this->assertNotNull($rows['failed_total']['reason']);
    }

    /*
     * =============================================================================================
     * THE DATABASE
     * =============================================================================================
     */

    /** The engine, its version and its name are the live connection's, read here a second way. */
    public function test_the_engine_is_the_live_connections_own(): void
    {
        $rows = $this->group('database');

        $this->assertSame(DB::connection()->getDriverName(), $rows['driver']['value'],
            'the engine named on the screen is not the one the queries go to');
        $this->assertSame(DB::connection()->getDatabaseName(), $rows['database']['value']);
        $this->assertSame(
            (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
            $rows['server_version']['value']
        );
    }

    /** The table count is the catalogue's, measured by adding a table to it. */
    public function test_the_table_count_rises_when_a_table_is_created(): void
    {
        $before = (int) str_replace(',', '', $this->group('database')['tables']['value']);

        Schema::create('ops_probe_fixture', function ($table) {
            $table->id();
        });

        $after = (int) str_replace(',', '', $this->group('database')['tables']['value']);

        Schema::drop('ops_probe_fixture');

        $restored = (int) str_replace(',', '', $this->group('database')['tables']['value']);

        $this->assertSame($before + 1, $after,
            'a table was created and the count did not rise by one, so it is not counting the catalogue');
        $this->assertSame($before, $restored,
            'the table was dropped and the count did not fall, so it is not counting the catalogue');
    }

    /**
     * THE SIZE IS THE DATABASE'S OWN ACCOUNTING, measured by putting something in it.
     *
     * A megabyte of rows, which is well over SQLite's default 4 KB page, so the page count has to
     * move. The assertion is strictly greater — no figure is predicted, because predicting one
     * would be restating the formula.
     */
    public function test_the_database_size_grows_when_data_is_written(): void
    {
        $before = $this->sizeBytes();

        $this->assertNotNull($before, 'the size was refused on a driver that can answer it (sqlite/mysql/mariadb)');

        Schema::create('ops_probe_bulk', function ($table) {
            $table->id();
            $table->text('payload');
        });

        $rows = [];

        for ($i = 0; $i < 400; $i++) {
            $rows[] = ['payload' => str_repeat('x', 4096)];
        }

        DB::table('ops_probe_bulk')->insert($rows);

        $after = $this->sizeBytes();

        Schema::drop('ops_probe_bulk');

        $this->assertGreaterThan($before, $after,
            'about 1.6 MB of rows were written and the reported size did not grow, so it is not '
            . 'reading the database');
    }

    /** The database answered, and that is asserted as the reading rather than assumed. */
    public function test_the_reachability_row_reports_the_connection_that_really_answered(): void
    {
        $row = $this->group('database')['reachable'];

        $this->assertSame(__('Yes'), $row['value']);
        $this->assertNull($row['reason']);
    }

    /**
     * A DATABASE THAT REFUSES IS REPORTED AS REFUSING, WITH NO DRIVER MESSAGE ATTACHED.
     *
     * The second half is the control that matters: a failed connection message can carry the
     * connection string and a connection string can carry a password, so the sentence may name the
     * SQLSTATE and the exception class and must not carry the driver's own text.
     */
    public function test_a_database_that_refuses_is_reported_without_quoting_the_driver(): void
    {
        $probe = new class extends SaasOperationsProbe
        {
            public function databaseReachability(?string $connection = null): array
            {
                return [
                    'answered' => false,
                    'sqlstate' => 'HY000',
                    'exception' => \PDOException::class,
                ];
            }
        };

        $row = $this->rowsOf(SaasPlatformOperations::measure($probe)['database'])['reachable'];

        $this->assertSame(__('No'), $row['value']);
        $this->assertStringContainsString('HY000', (string) $row['reason']);
        $this->assertStringContainsString('PDOException', (string) $row['reason']);
    }

    /** A driver with no portable connection count is refused by name, not reported as zero. */
    public function test_a_driver_with_no_connection_count_is_refused_by_name(): void
    {
        $row = $this->group('database')['connections_in_use'];

        if (in_array(DB::connection()->getDriverName(), SaasOperationsProbe::CONNECTION_COUNT_DRIVERS, true)) {
            $this->assertNotNull($row['value'],
                'this engine publishes a connection count and none was reported');

            return;
        }

        $this->assertNull($row['value'],
            'a connection count was produced on an engine that publishes none, so it is not a measurement');
        $this->assertStringContainsString(DB::connection()->getDriverName(), (string) $row['reason'],
            'the refusal does not name the engine that could not answer');
    }

    /*
     * =============================================================================================
     * CACHE AND SESSION STORES
     * =============================================================================================
     */

    /** A per-process cache keeps nothing in a table, and that is said rather than counted as zero. */
    public function test_a_cache_that_keeps_nothing_in_a_table_withholds_the_row_count(): void
    {
        config(['cache.default' => 'array', 'cache.stores.array.driver' => 'array']);

        $rows = $this->group('stores');

        $this->assertNull($rows['cache_rows']['value']);
        $this->assertStringContainsString('array', (string) $rows['cache_rows']['reason']);
    }

    /** On a database-backed store the figure is the table's, measured by adding to it. */
    public function test_a_database_backed_cache_reports_the_rows_there_really_are(): void
    {
        config([
            'cache.default' => 'database',
            'cache.stores.database.driver' => 'database',
            'cache.stores.database.table' => 'cache',
        ]);

        $before = (int) str_replace(',', '', (string) $this->group('stores')['cache_rows']['value']);

        DB::table('cache')->insert([
            'key' => 'ops-probe-fixture',
            'value' => 'x',
            'expiration' => time() + 600,
        ]);

        $after = (int) str_replace(',', '', (string) $this->group('stores')['cache_rows']['value']);

        $this->assertSame($before + 1, $after,
            'a cache row was written and the figure did not follow it');
    }

    /*
     * =============================================================================================
     * INTEGRATIONS
     * =============================================================================================
     */

    /**
     * GATEWAY ENABLEMENT IS COUNTED PER STORE, from the rows that really say `on`.
     *
     * Three stores, two switched on and one off, so a collector counting rows rather than
     * enabled rows answers 3 and fails.
     */
    public function test_a_gateways_enabled_store_count_is_the_stores_that_really_switched_it_on(): void
    {
        $this->enableGateway('stripe', 11, ['stripe_publishable_key' => 'pk', 'stripe_secret_key' => 'sk']);
        $this->enableGateway('stripe', 12, ['stripe_publishable_key' => 'pk', 'stripe_secret_key' => 'sk']);
        $this->setting('is_stripe_enabled', 'off', 13);

        $stripe = $this->gatewayDetail('stripe');

        $this->assertNotNull($stripe, 'stripe was switched on by two stores and is not in the table');
        $this->assertSame(2, $stripe['enabled_stores']);
        $this->assertSame(2, $stripe['complete_stores']);
        $this->assertSame(0, $stripe['incomplete_stores']);
    }

    /**
     * THE FIGURE THIS SCREEN EXISTS FOR: switched on with a required credential missing.
     *
     * The same store, the same gateway, one blanked credential. `complete` falls and `incomplete`
     * appears — so a collector that equated "switched on" with "configured" fails, and that
     * equation is exactly the state `PaymentSettingController`'s own comment says existed in the
     * data before its validation ever ran.
     */
    public function test_a_gateway_switched_on_without_its_required_credential_is_counted_as_incomplete(): void
    {
        $this->enableGateway('stripe', 21, ['stripe_publishable_key' => 'pk', 'stripe_secret_key' => 'sk']);
        $this->enableGateway('stripe', 22, ['stripe_publishable_key' => 'pk', 'stripe_secret_key' => '']);

        $stripe = $this->gatewayDetail('stripe');

        $this->assertSame(2, $stripe['enabled_stores']);
        $this->assertSame(1, $stripe['complete_stores'],
            'a store with a blank required credential is being counted as configured');
        $this->assertSame(1, $stripe['incomplete_stores']);

        $summary = $this->rowsOf(SaasPlatformOperations::measure()['gateways']['rows']);

        $this->assertSame('1', $summary['incomplete']['value'],
            'the summary does not count the gateway that is switched on and incomplete');
    }

    /** A gateway needing no credential is complete the moment it is switched on. */
    public function test_a_gateway_with_no_required_credential_is_complete_when_it_is_switched_on(): void
    {
        $this->setting('is_cod_enabled', 'on', 31);

        $cod = $this->gatewayDetail('cod');

        $this->assertSame(1, $cod['enabled_stores']);
        $this->assertSame(1, $cod['complete_stores']);
        $this->assertSame(0, $cod['required']);
    }

    /**
     * AN ON/OFF ROW HOLDING NEITHER VALUE IS COUNTED, because the product disagrees with itself
     * about it: the writer stores only `on` or `off`, and most readers test the row for emptiness,
     * so `1` or `yes` is read as switched ON there and as off here.
     */
    public function test_an_on_off_row_holding_neither_value_is_counted_rather_than_smoothed_over(): void
    {
        $this->setting('is_stripe_enabled', '1', 41);
        $this->setting('is_paypal_enabled', 'yes', 42);
        $this->setting('is_razorpay_enabled', 'off', 43);

        $summary = $this->rowsOf(SaasPlatformOperations::measure()['gateways']['rows']);

        $this->assertSame('2', $summary['ambiguous']['value'],
            'rows holding neither on nor off are not being counted, so a disagreement the product '
            . 'really has is invisible');
    }

    /** The registry population is counted from the code, and it is not empty. */
    public function test_the_gateway_population_is_counted_from_the_registry(): void
    {
        $summary = $this->rowsOf(SaasPlatformOperations::measure()['gateways']['rows']);

        $declared = count((new \App\Http\Controllers\PaymentSettingController())->getPaymentGateways());

        $this->assertGreaterThan(1, $declared);
        $this->assertSame(number_format($declared), $summary['registry']['value']);
    }

    /** The OTP integration count is the table's, measured by adding to it. */
    public function test_the_sms_integration_counts_are_the_tables_own(): void
    {
        DB::table('otp_integrations')->insert([
            'store_id' => 51, 'base_url' => 'https://example.test', 'api_secret' => 'x',
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('otp_integrations')->insert([
            'store_id' => 52, 'base_url' => 'https://example.test', 'api_secret' => 'x',
            'is_active' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->group('integrations');

        $this->assertSame('2', $rows['otp_configured']['value']);
        $this->assertSame('1', $rows['otp_active']['value'],
            'the active count is counting rows that are switched off');
    }

    /** A transport with no host is named as sending nothing, rather than shown as configured. */
    public function test_a_mail_transport_with_no_host_says_so(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => '']);

        $row = $this->group('integrations')['mail_host'];

        $this->assertNull($row['value']);
        $this->assertNotNull($row['reason']);
    }

    /*
     * =============================================================================================
     * THE CONTRACT THE WHOLE SCREEN RESTS ON
     * =============================================================================================
     */

    /**
     * EVERY ROW CARRIES A PERIOD AND A SOURCE, and every withheld figure carries a reason.
     *
     * A figure with no period is the commonest way a dashboard misleads, and a figure with no
     * source cannot be checked by hand. Driven over EVERY group so a new row cannot be added
     * without them.
     */
    public function test_every_row_names_its_own_measurement_source_and_period(): void
    {
        $measured = SaasPlatformOperations::measure();

        $groups = ['database', 'queue', 'stores', 'integrations'];
        $seen = 0;

        foreach ($groups as $group) {
            foreach ($measured[$group] as $row) {
                $seen++;

                $this->assertNotSame('', trim($row['period']), $group . '.' . $row['key'] . ' has no period');
                $this->assertNotSame('', trim($row['source']), $group . '.' . $row['key'] . ' has no source');
                $this->assertNotSame('', trim($row['hint']), $group . '.' . $row['key'] . ' has no hint');

                if ($row['value'] === null) {
                    $this->assertNotNull($row['reason'],
                        $group . '.' . $row['key'] . ' was withheld and does not say why, so it '
                        . 'renders as a blank an operator cannot interpret');
                } else {
                    $this->assertNull($row['reason'],
                        $group . '.' . $row['key'] . ' carries both a figure and a refusal');
                }
            }
        }

        foreach ($measured['gateways']['rows'] as $row) {
            $seen++;
            $this->assertNotSame('', trim($row['period']), 'gateways.' . $row['key'] . ' has no period');
            $this->assertNotSame('', trim($row['source']), 'gateways.' . $row['key'] . ' has no source');
        }

        $this->assertGreaterThan(20, $seen, 'the screen produced almost nothing, so this proved almost nothing');
    }

    /**
     * THE ROW SHAPE IS IDENTICAL TO THE MACHINE SCREEN'S, so the two operations producers cannot
     * drift apart and leave one of them without a period or a source.
     */
    public function test_the_row_shape_is_the_same_as_the_machine_measurements(): void
    {
        $mine = array_keys(SaasPlatformOperations::measure()['database'][0]);
        $theirs = array_keys(SaasPlatformResources::measure()['processor'][0]);

        sort($mine);
        sort($theirs);

        $this->assertSame($theirs, $mine,
            'the two operations screens no longer agree about what a measured row carries');
    }

    /**
     * NO VERDICT ANYWHERE. No threshold, no traffic light, no "healthy", no severity.
     *
     * `OPS-003` — incidents, alerts and diagnosis — is where a threshold belongs, and WHAT the
     * threshold is and WHO is notified is an owner decision that has not been taken. A judgement
     * added here would put a number in the product that is then quoted back as the platform's
     * policy, so the absence is asserted rather than left to be read off today's code.
     */
    public function test_no_measurement_carries_a_verdict(): void
    {
        $measured = SaasPlatformOperations::measure();

        $forbidden = ['status', 'state', 'severity', 'verdict', 'health', 'healthy', 'ok', 'level', 'threshold', 'alert'];

        foreach (['database', 'queue', 'stores', 'integrations'] as $group) {
            foreach ($measured[$group] as $row) {
                foreach (array_keys($row) as $field) {
                    $this->assertNotContains($field, $forbidden,
                        $group . '.' . $row['key'] . ' carries a "' . $field . '" field. This screen '
                        . 'states figures and judges none: a threshold is an operational policy '
                        . 'nobody has set.');
                }
            }
        }
    }

    /**
     * NOTHING ON THIS SCREEN IS CACHED. A cached queue depth is read as the current one, which is
     * the exact failure the figure exists to catch.
     *
     * COMMENT-STRIPPED, because this file and both producers discuss caching at length and a
     * census that read comments would be broken by the prose explaining the rule.
     */
    public function test_the_measurements_are_taken_now_and_not_served_from_a_cache(): void
    {
        foreach ([self::PRODUCER, self::PROBE] as $file) {
            $code = $this->sourceWithoutComments($file);

            foreach (['Cache::', 'cache()->', 'rememberForever', 'Illuminate\Support\Facades\Cache'] as $token) {
                $this->assertStringNotContainsString($token, $code,
                    $file . ' caches a measurement. A cached queue depth or disk figure is read as '
                    . 'the current one, which is the failure these figures exist to catch.');
            }
        }
    }

    /**
     * A DEPLOYMENT THAT HAS RENAMED ONE OF THESE TABLES GETS THE FIGURE WITHHELD, NOT A COUNT FROM
     * THE WRONG TABLE.
     *
     * The table names are also configuration keys, and this class compares them rather than using
     * them — a table identifier is interpolated into the SQL rather than bound, so reading whatever
     * the environment names is both a trust decision nobody made and a site this repository's
     * by-table raw-query census cannot classify. The honest consequence is a refusal, and that
     * refusal is the thing asserted here.
     *
     * THE ROWS ARE IN THE TABLE FIRST, so a reader that ignored the configured name would answer
     * `2` and fail rather than being saved by an empty table.
     */
    public function test_a_renamed_operational_table_withholds_the_figure_instead_of_counting_the_wrong_one(): void
    {
        $this->onDatabaseQueue();

        $this->queueJob('default');
        $this->queueJob('default');
        $this->failJob('default', now());

        config([
            'queue.connections.database.table' => 'jobs_renamed_by_this_deployment',
            'queue.failed.table' => 'failed_jobs_renamed_by_this_deployment',
        ]);

        $rows = $this->group('queue');

        foreach (['pending', 'reserved', 'oldest_pending', 'failed_total', 'failed_window'] as $key) {
            $this->assertNull($rows[$key]['value'],
                $key . ' was counted from the table this class knows about while the deployment '
                . 'has the queue in a differently named one');
            $this->assertNotNull($rows[$key]['reason'], $key . ' was withheld without saying why');
        }

        /*
         * AND THE SENTENCE MUST BE THE RIGHT ONE, which is the half this case was missing when it
         * was first written. The failure rows used a single refusal that blamed the configured
         * DRIVER -- true when a deployment records no failures at all, and simply FALSE here, where
         * the driver is fine and only the table name differs. A reason that exists and is wrong is
         * worse than no reason, so the assertion is on the words rather than on their presence.
         */
        $this->assertStringContainsString('differently named table', (string) $rows['failed_total']['reason'],
            'the failure refusal blames the configured driver for a renamed table');
        $this->assertStringNotContainsString('does not record failed jobs in the database',
            (string) $rows['failed_total']['reason'],
            'the failure refusal says this deployment records no failures, which is not what is '
            . 'wrong: it records them in a table this screen does not read');
    }

    /** The same contract for the cache and session stores, which are configured the same way. */
    public function test_a_renamed_cache_or_session_table_withholds_the_row_count(): void
    {
        config([
            'cache.default' => 'database',
            'cache.stores.database.driver' => 'database',
            'cache.stores.database.table' => 'cache_renamed_by_this_deployment',
            'session.driver' => 'database',
            'session.table' => 'sessions_renamed_by_this_deployment',
        ]);

        DB::table('cache')->insert(['key' => 'ops-probe', 'value' => 'x', 'expiration' => time() + 600]);

        $rows = $this->group('stores');

        $this->assertNull($rows['cache_rows']['value']);
        $this->assertNotNull($rows['cache_rows']['reason']);
        $this->assertNull($rows['session_rows']['value']);
        $this->assertNotNull($rows['session_rows']['reason']);
    }

    /**
     * A STORE CONFIGURED TO USE THE DATABASE WHOSE TABLE IS NOT THERE SAYS SO, AND DOES NOT SAY
     * "THIS DRIVER KEEPS NOTHING IN A TABLE".
     *
     * THIS IS THE SHIPPED STATE OF THE SESSION STORE IN THIS REPOSITORY, measured 2026-11-26 and
     * not hypothetical: `.env.example` ships `SESSION_DRIVER=database`, `config/session.php`
     * defaults to `database`, and `database/migrations/` contains NO migration that creates a
     * `sessions` table — while `cache` and `jobs` both have one (`0001_01_01_000001`,
     * `0001_01_01_000002`). A deployment taking the shipped defaults therefore has a session store
     * pointed at a table that does not exist, and the sentence an operator must not be given for
     * that is the one meant for a per-process driver.
     *
     * The two sentences are asserted to DIFFER, which is the whole point: a single `null` with one
     * reason would make an outage indistinguishable from a configuration nothing is wrong with.
     */
    public function test_a_database_backed_store_whose_table_is_absent_says_that_rather_than_blaming_the_driver(): void
    {
        config(['session.driver' => 'database', 'session.table' => 'sessions']);

        $this->assertFalse(Schema::hasTable('sessions'),
            'premise: this repository now creates a sessions table, so this case no longer '
            . 'exercises the absent-table branch. Re-point it at a table that really is absent.');

        $absent = $this->group('stores')['session_rows'];

        $this->assertNull($absent['value']);
        $this->assertStringContainsString('sessions', (string) $absent['reason']);
        $this->assertStringContainsString('cannot work', (string) $absent['reason'],
            'the screen blames the driver for a missing table, which is the opposite of what is wrong');

        config(['session.driver' => 'array']);

        $notDatabase = $this->group('stores')['session_rows'];

        $this->assertNull($notDatabase['value']);
        $this->assertNotSame($absent['reason'], $notDatabase['reason'],
            'a store that cannot work and a store that keeps nothing in a table are given the same '
            . 'sentence, so an operator cannot tell an outage from a configuration');
    }

    /**
     * EVERY RAW QUERY IN THE PROBE NAMES ITS TABLE AS A LITERAL, AND NONE OF THEM IS BUILDER-RAW.
     *
     * Two properties, both of them about this repository's own censuses rather than about style,
     * and both asserted as the SAFE SET rather than as a list of bad spellings — `CLAUDE.md`
     * section 3, after a dangerous-spelling census in this very file was walked through by a
     * mutant.
     *
     *   1. `RawQueryTenancyInventoryTest` classifies raw queries BY TABLE, keyed on a quoted
     *      literal in the source. `DB::table($variable)` is a site it cannot classify at all, and
     *      an unclassifiable raw site in a shared-database multi-tenant product is precisely what
     *      that census exists to prevent. So every `DB::table(` here must be followed by a quote.
     *
     *   2. `BuilderRawQueryVisibilityTest` measures the blind spot of the main census:
     *      `selectRaw`, `whereRaw`, `DB::raw` and their siblings are INVISIBLE to it. An earlier
     *      draft of this probe had three such statements — two `GROUP BY` aggregates over
     *      `settings` and one over `failed_jobs` — and all three were replaced with queries that
     *      ask the same questions in the builder's own vocabulary.
     */
    public function test_every_raw_query_in_the_probe_names_a_literal_table_and_none_is_builder_raw(): void
    {
        $code = $this->sourceWithoutComments(self::PROBE);

        preg_match_all('/DB::table\(\s*(.)/', $code, $matches, PREG_OFFSET_CAPTURE);

        $this->assertNotEmpty($matches[1], 'premise: no DB::table() call was found in the probe at all');

        foreach ($matches[1] as $match) {
            $this->assertContains($match[0], ["'", '"'], sprintf(
                'a DB::table() call in %s takes a table name that is not a quoted literal (it '
                . "starts with `%s`). This repository's raw-query census classifies every raw "
                . 'query by table, keyed on a literal, so such a site cannot be classified at all '
                . '-- and a table identifier is interpolated into the SQL rather than bound.',
                self::PROBE,
                $match[0]
            ));
        }

        foreach ([
            'selectRaw', 'whereRaw', 'orWhereRaw', 'havingRaw', 'orHavingRaw', 'orderByRaw',
            'groupByRaw', 'fromRaw', 'joinRaw', 'whereIntegerInRaw', 'DB::raw',
        ] as $builderRaw) {
            $this->assertStringNotContainsString($builderRaw, $code, sprintf(
                '%s contains `%s`, which is invisible to this repository\'s main raw-query census '
                . 'by construction. Every question this probe asks has a builder form.',
                self::PROBE,
                $builderRaw
            ));
        }
    }

    /**
     * NO INTEGRATION READING EVER SELECTS A `settings` VALUE — ASSERTED AGAINST THE SQL THAT WAS
     * REALLY ISSUED, NOT AGAINST THE SOURCE THAT WOULD ISSUE IT.
     *
     * `settings` is where every payment gateway's live secret is stored — `stripe_secret_key`,
     * `paytabs_server_key`, `PAYMERA_USERNAME` and a hundred more (`GatewaySecretRedactor` is the
     * inventory). A reader that fetched rows and tested emptiness in PHP would hold every
     * merchant's keys in this request's memory, so presence is asked of the engine and only `name`
     * and `store_id` come back.
     *
     * THIS CASE WAS WRITTEN AS A SOURCE CENSUS FIRST, AND A MUTANT WALKED STRAIGHT THROUGH IT.
     * The census forbade `select('name', 'value'`, `select('value'`, `pluck('value'` and
     * `addSelect('value'`; the mutation that broke the guarantee was
     * `select('name', 'store_id', 'value')`, which is in none of those spellings and SURVIVED.
     * That is `CLAUDE.md` section 3 exactly — "a list of dangerous spellings is always short by
     * the one nobody thought of" — and the remedy it prescribes is to enumerate the SAFE set.
     *
     * The safe set is easiest to state about the SQL: a query against `settings` may project
     * anything except the `value` column, and may not project `*` either, because `*` includes it
     * and `DB::table('settings')->get()` compiles to exactly that. So the queries are captured as
     * they execute and their select lists are read. No spelling in PHP can evade this — a raw
     * select, a `pluck`, a `get()` with no projection and a column added three refactors from now
     * all arrive here as SQL.
     */
    public function test_no_integration_reading_ever_selects_a_settings_value(): void
    {
        $sql = [];

        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        // Rows for the reader to find, so the queries are not optimised away by an empty table and
        // so this case cannot pass by nothing having run.
        $this->enableGateway('stripe', 61, ['stripe_publishable_key' => 'pk', 'stripe_secret_key' => 'SUPER-SECRET']);

        SaasPlatformOperations::measure();

        $touchingSettings = array_values(array_filter(
            $sql,
            static fn (string $q): bool => str_contains($q, 'settings')
        ));

        $this->assertNotSame([], $touchingSettings,
            'no query against the settings table was issued at all, so this case proved nothing '
            . 'about what such a query selects');

        foreach ($touchingSettings as $query) {
            // Everything between the first `select` and the first ` from ` is the projection.
            if (preg_match('/^\s*select\s+(.*?)\s+from\s/is', $query, $matches) !== 1) {
                continue;
            }

            $projection = $matches[1];

            $this->assertStringNotContainsString('value', strtolower($projection), sprintf(
                "a query against the settings table projects the value column:\n  %s\n"
                . 'That table holds every merchant\'s live gateway credentials. Presence is a '
                . 'question for the engine, so the projection may name only the columns this '
                . 'screen prints.',
                $query
            ));

            /*
             * `count(*)` IS NOT A DISCLOSURE AND IS THE ONLY WILDCARD ALLOWED THROUGH. It projects
             * a number of rows and no column at all, and it is how three of the readings here are
             * taken. Removed by exact spelling rather than by a looser pattern, so a real `*` — or
             * a `count(*), value` — still fails the line below.
             */
            $projection = str_ireplace(['count(*)', 'count( * )'], 'count_rows', $projection);

            $this->assertStringNotContainsString('*', $projection, sprintf(
                "a query against the settings table projects every column:\n  %s\n"
                . 'A wildcard includes the value column, which holds every merchant\'s live '
                . 'gateway credentials.',
                $query
            ));
        }
    }

    /*
     * =============================================================================================
     * fixtures
     * =============================================================================================
     */

    /**
     * THE BYTES, NOT THE SPELLING. The presenter rounds to "1.6 MB", and two readings a few
     * kilobytes apart round to the same words — so a growth assertion taken off the rendered value
     * would pass whether or not the figure moved.
     */
    private function sizeBytes(): ?int
    {
        return (new SaasOperationsProbe())->databaseSizeBytes();
    }

    private function queueJob(string $queue, ?int $createdAt = null, ?int $availableAt = null): int
    {
        return (int) DB::table('jobs')->insertGetId([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt ?? time(),
            'created_at' => $createdAt ?? time(),
        ]);
    }

    private function failJob(string $queue, \DateTimeInterface $at): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => '{}',
            'exception' => 'fixture',
            'failed_at' => $at,
        ]);
    }

    private function setting(string $name, string $value, int $storeId): void
    {
        DB::table('settings')->insert([
            'name' => $name,
            'value' => $value,
            'created_by' => '1',
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, string> $credentials */
    private function enableGateway(string $id, int $storeId, array $credentials): void
    {
        $this->setting('is_' . $id . '_enabled', 'on', $storeId);

        foreach ($credentials as $name => $value) {
            $this->setting($name, $value, $storeId);
        }
    }

    /** @return array<string, mixed>|null */
    private function gatewayDetail(string $id): ?array
    {
        foreach (SaasPlatformOperations::measure()['gateways']['detail'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }
}
