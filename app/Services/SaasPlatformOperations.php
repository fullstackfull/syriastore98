<?php

namespace App\Services;

use App\Http\Controllers\PaymentSettingController;
use App\Payments\RetiredGateways;
use Carbon\CarbonImmutable;

/**
 * OPS-001 — "مراقبة الخوادم وقواعد البيانات والطوابير والتكاملات" / MONITOR SERVERS, DATABASES,
 * QUEUES AND INTEGRATIONS.
 * =================================================================================================
 *
 * The SERVERS half of that sentence shipped as `GAP-195`/`OPS-002`: `SaasPlatformResources` and
 * `/platform/resources`, which measures load, memory and disk and says on its own face that
 * "Queue depth, database size and integration reachability are not measured here". This class is
 * the other three subjects, and that sentence is updated in the same change — a stated gap that
 * has been filled and still says it is a gap is worse than no sentence, because an operator reads
 * it and stops looking.
 *
 * THE ROW SHAPE IS `SaasPlatformResources`' EXACTLY, DELIBERATELY AND NOT BY COINCIDENCE: `value`,
 * `period`, `source`, `hint`, `reason`. Two operations screens that disagree about what a figure
 * carries is how one of them ends up without a period or without a source, and the period is the
 * single commonest thing an operations dashboard omits. `PlatformOperationsMeasurementTest::
 * test_every_row_names_its_own_measurement_source_and_period` holds it, and the two producers'
 * shapes are asserted identical so they cannot drift apart.
 *
 *   value    the figure, already spelled for the page — or NULL, which means "this deployment
 *            would not tell us" and must never be rendered as a zero.
 *   period   what span the figure covers.
 *   source   the query, function or configuration key that produced it, so an operator can
 *            re-derive it by hand.
 *   hint     what the figure means, including its known limits.
 *   reason   present only when `value` is null: the sentence that says what was refused.
 *
 * NO VERDICTS, SAME AS ITS SIBLING. No threshold, no traffic light, no "healthy", no colour. A
 * queue depth of 4,000 may be a disaster or a nightly import; 85% of a disk may be fine. `OPS-003`
 * — incidents, alerts and diagnosis — is where a threshold belongs, and WHAT the threshold is and
 * WHO is notified is an owner decision that has not been taken. Inventing one here would put a
 * number in the product that is then quoted back as the platform's policy.
 * `test_no_measurement_carries_a_verdict` fails on one.
 *
 * NO CACHE, EVER. A cached queue depth is read as the current one, which is the exact failure the
 * figure exists to catch. This file is grepped for a cache facade call by its test, and that
 * sentence is written without the token the census looks for because `CLAUDE.md` records eight
 * occasions on which a census was satisfied — or broken — by prose about the thing it measures.
 *
 * NOT AN AUTHORIZATION BOUNDARY. Every figure spans every tenant, so each caller's own gate is the
 * whole control. Its one caller is `SuperAdminOperationsController`, behind `PlatformOperatorOnly`.
 */
final class SaasPlatformOperations
{
    /**
     * @return array{
     *     context: array{measured_at: CarbonImmutable, failure_window_hours: int},
     *     database: list<array<string, mixed>>,
     *     queue: list<array<string, mixed>>,
     *     stores: list<array<string, mixed>>,
     *     integrations: list<array<string, mixed>>,
     *     gateways: array{rows: list<array<string, mixed>>, detail: list<array<string, mixed>>}
     * }
     */
    public static function measure(?SaasOperationsProbe $probe = null, int $failureWindowHours = 24): array
    {
        $probe = $probe ?? new SaasOperationsProbe();

        return [
            'context' => [
                'measured_at' => CarbonImmutable::now(),
                'failure_window_hours' => $failureWindowHours,
            ],
            'database' => self::database($probe),
            'queue' => self::queue($probe, $failureWindowHours),
            'stores' => self::stores($probe),
            'integrations' => self::integrations($probe, $failureWindowHours),
            'gateways' => self::gateways($probe),
        ];
    }

    /**
     * THE DATABASE: what it is, where it is, how big it is, and whether it answers.
     *
     * @return list<array<string, mixed>>
     */
    private static function database(SaasOperationsProbe $probe): array
    {
        /*
         * EVERY FIGURE IS TAKEN ONCE. Two calls to the same reader would be two readings, and a
         * value row and its own unavailability sentence disagreeing about whether the figure
         * exists is the sort of thing nobody ever reproduces.
         */
        $driver = $probe->databaseDriver();
        $version = $probe->databaseServerVersion();
        $name = $probe->databaseName();
        $reachability = $probe->databaseReachability();
        $size = $probe->databaseSizeBytes();
        $tables = $probe->databaseTableCount();
        $connections = $probe->databaseConnectionsInUse();

        $sizeRefused = __('This deployment\'s database driver (:driver) publishes no size this screen can ask for. Only MySQL, MariaDB and SQLite do, and a figure read from the wrong catalogue would be a guess.', [
            'driver' => $driver ?? __('unknown'),
        ]);

        return [
            self::row(
                'connection',
                __('Connection'),
                $probe->databaseConnectionName(),
                __('now'),
                __('The connection name this application is configured to use. Several connections are declared in the shipped configuration and only this one is used; the others are the framework\'s own templates and are not probed, because connecting to a host nobody configured would report a failure that means nothing.'),
                'config(\'database.default\')',
                null,
            ),
            self::row(
                'driver',
                __('Engine'),
                $driver,
                __('now'),
                __('Asked of the live connection rather than read out of configuration: the two disagree whenever a deployment sets a database URL or switches connection at runtime, and the one that matters is the one the queries go to.'),
                'Connection::getDriverName()',
                $driver === null ? __('The database connection could not be resolved, so its engine is unknown.') : null,
            ),
            self::row(
                'server_version',
                __('Engine version'),
                $version,
                __('now'),
                __('The engine\'s own version string.'),
                'PDO::ATTR_SERVER_VERSION',
                $version === null ? __('The driver did not publish a server version on this deployment.') : null,
            ),
            self::row(
                'database',
                __('Database'),
                $name,
                __('now'),
                __('The schema the connection is attached to, or the file path for a file-backed engine.'),
                'Connection::getDatabaseName()',
                $name === null ? __('The connection reported no database name.') : null,
            ),
            self::row(
                'reachable',
                __('The database answered'),
                $reachability['answered'] ? __('Yes') : __('No'),
                __('at the moment this page was built'),
                __('A trivial query was issued and either returned or raised. The driver\'s own message is deliberately not shown: a failed connection message can carry the connection string, and a connection string can carry a password. The code below is the part an operator can act on, and the full message is in the application log.'),
                'select 1',
                $reachability['answered']
                    ? null
                    : __('The database refused the query (:code, raised by :class).', [
                        'code' => $reachability['sqlstate'] ?? __('no SQLSTATE'),
                        'class' => $reachability['exception'] ?? __('an unknown error'),
                    ]),
            ),
            self::row(
                'tables',
                __('Tables'),
                $tables === null ? null : number_format($tables),
                __('now'),
                __('Counted from the engine\'s own catalogue, excluding the catalogue itself. This is the figure that shows a half-migrated deployment: far fewer tables than this repository\'s migrations create means migrations have not all run.'),
                $driver === 'sqlite' ? 'sqlite_master' : 'information_schema.tables',
                $tables === null
                    ? __('This deployment\'s database driver (:driver) has no catalogue this screen knows how to count. Only MySQL, MariaDB and SQLite do.', ['driver' => $driver ?? __('unknown')])
                    : null,
            ),
            self::row(
                'size',
                __('Size'),
                $size === null ? null : self::bytes($size),
                __('now'),
                __('Data plus indexes, as the engine accounts for them. On MySQL and MariaDB this is the engine\'s own estimate and is approximate for InnoDB tables — stated rather than corrected, because a correction would be an invention. On SQLite it is pages times page size and is exact.'),
                $driver === 'sqlite' ? 'pragma page_count * pragma page_size' : 'information_schema.tables (data_length + index_length)',
                $size === null ? $sizeRefused : null,
            ),
            self::row(
                'connections_in_use',
                __('Client connections open'),
                $connections === null ? null : number_format($connections),
                __('now'),
                __('Every client connected to the engine, not just this one. It is the figure that shows a connection limit about to be reached, and there is no portable equivalent, so only MySQL and MariaDB can answer it.'),
                'SHOW STATUS LIKE \'Threads_connected\'',
                $connections === null
                    ? __('Only MySQL and MariaDB publish a connection count this screen can ask for; this deployment runs :driver.', ['driver' => $driver ?? __('an unknown engine')])
                    : null,
            ),
        ];
    }

    /**
     * THE QUEUES: what is waiting, what a worker has taken and not finished, and what failed.
     *
     * THE `sync` CASE IS A MEASUREMENT, NOT A MISSING ONE, and it is the single most important
     * honesty point on this screen. A deployment configured `sync` has no queue at all: every job
     * runs inside the web request that dispatched it. Reporting "0 pending" there is true and
     * useless; reporting "not queued" is the fact an operator needs, because it means a slow
     * notification is a slow checkout.
     *
     * @return list<array<string, mixed>>
     */
    private static function queue(SaasOperationsProbe $probe, int $failureWindowHours): array
    {
        $driver = $probe->queueDriver();
        $jobs = $probe->queuedJobs();
        $failed = $probe->failedJobs($failureWindowHours);

        $inline = $driver === SaasOperationsProbe::QUEUE_DRIVER_SYNC;

        $depthRefused = $inline
            ? __('This deployment runs jobs inline (the sync driver), so there is no queue and no depth to report. A job that would have been queued is instead run inside the request that dispatched it.')
            : __('A :driver queue\'s backlog is not a database question, so this screen cannot see it. An empty jobs table on such a deployment means the table is unused, not that nothing is waiting.', [
                'driver' => $driver ?? __('non-database'),
            ]);

        $oldest = $jobs === null || $jobs['oldest_pending_created_at'] === null
            ? null
            : CarbonImmutable::createFromTimestamp($jobs['oldest_pending_created_at']);

        $rows = [
            self::row(
                'connection',
                __('Queue connection'),
                $probe->queueConnectionName(),
                __('now'),
                __('The queue connection this application is configured to use.'),
                'config(\'queue.default\')',
                null,
            ),
            self::row(
                'driver',
                __('Queue driver'),
                $driver === null ? null : ($inline ? __('sync — jobs run inline, there is no queue') : $driver),
                __('now'),
                __('Which driver that connection resolves to. It decides what the rest of this group can measure at all: only a database-backed queue has a backlog a query can count.'),
                'config(\'queue.connections.*.driver\')',
                $driver === null ? __('The configured queue connection declares no driver.') : null,
            ),
            self::row(
                'pending',
                __('Jobs waiting'),
                $jobs === null ? null : number_format($jobs['pending']),
                __('now'),
                __('Jobs handed over and not yet taken by a worker. A delayed job is included: it is waiting, it is simply not due.'),
                $probe->queueTable() . ' where reserved_at is null',
                $jobs === null ? $depthRefused : null,
            ),
            self::row(
                'reserved',
                __('Jobs held by a worker'),
                $jobs === null ? null : number_format($jobs['reserved']),
                __('now'),
                __('Jobs a worker has taken and not finished. Counted apart from the waiting jobs because the two fail differently: waiting is throughput, while held-and-not-shrinking is a worker that died holding work — the commonest queue outage, and invisible inside a single total.'),
                $probe->queueTable() . ' where reserved_at is not null',
                $jobs === null ? $depthRefused : null,
            ),
            self::row(
                'oldest_pending',
                __('The oldest waiting job was handed over'),
                $oldest === null ? null : $oldest->toDateTimeString(),
                __('now'),
                __('When the oldest job still waiting was dispatched — not when it becomes due. A delayed job has a due time in the future and has not been waiting at all, so the dispatch time is the age an operator means. Nothing judges this: whether an hour is a backlog depends on what the job is.'),
                $probe->queueTable() . ' min(created_at)',
                $oldest === null
                    ? ($jobs === null ? $depthRefused : __('No job is waiting, so there is no oldest one.'))
                    : null,
            ),
        ];

        $failureRefused = __('This deployment does not record failed jobs in the database (the configured failed-job driver is :driver), so no failure history can be read here.', [
            'driver' => (string) config('queue.failed.driver'),
        ]);

        $rows[] = self::row(
            'failed_window',
            __('Background jobs that failed'),
            $failed === null ? null : number_format($failed['since']),
            __('the last :hours hours', ['hours' => $failureWindowHours]),
            __('Jobs that exhausted their attempts and were recorded as failed. This is the only failure history this product keeps anywhere, and it covers queued work only.'),
            ($probe->failedJobsTable() ?? 'failed_jobs') . ' where failed_at >= now - ' . $failureWindowHours . 'h',
            $failed === null ? $failureRefused : null,
        );

        $rows[] = self::row(
            'failed_total',
            __('Background jobs that failed, in total'),
            $failed === null ? null : number_format($failed['total']),
            __('since the failure table was last cleared'),
            __('Every failure still in the table. The period is deliberately not "all time": the table is cleared by an operator command, so its oldest row is whenever that last happened and this figure cannot claim to reach further back.'),
            ($probe->failedJobsTable() ?? 'failed_jobs') . ' count(*)',
            $failed === null ? $failureRefused : null,
        );

        $rows[] = self::row(
            'failed_newest',
            __('The most recent failure'),
            $failed === null ? null : $failed['newest_failed_at'],
            __('now'),
            __('When the newest recorded failure happened.'),
            ($probe->failedJobsTable() ?? 'failed_jobs') . ' max(failed_at)',
            $failed === null
                ? $failureRefused
                : ($failed['newest_failed_at'] === null ? __('No failure is recorded.') : null),
        );

        return $rows;
    }

    /**
     * THE TWO OTHER DATABASE-BACKED STORES, which are operational dependencies and not data.
     *
     * The shipped configuration puts the cache and the session store in the same database as the
     * orders — `CACHE_STORE=database`, `SESSION_DRIVER=database` — so their rows are on the same
     * disk the size figure above measures, and a session store that stops working signs the whole
     * platform out. The test suite runs them on `array`, which is why both rows have to be able to
     * say "this driver keeps nothing in a table".
     *
     * @return list<array<string, mixed>>
     */
    private static function stores(SaasOperationsProbe $probe): array
    {
        $cacheDriver = $probe->cacheDriver();
        $sessionDriver = $probe->sessionDriver();

        $cacheTable = (string) (config('cache.stores.' . $probe->cacheStoreName() . '.table') ?: 'cache');
        $sessionTable = (string) (config('session.table') ?: 'sessions');

        $cacheRows = $cacheDriver === 'database' ? $probe->tableRowCount($cacheTable) : null;
        $sessionRows = $sessionDriver === 'database' ? $probe->tableRowCount($sessionTable) : null;

        return [
            self::row(
                'cache_driver',
                __('Cache store'),
                $cacheDriver === null ? null : $probe->cacheStoreName() . ' (' . $cacheDriver . ')',
                __('now'),
                __('Which cache this deployment uses. It matters operationally rather than decoratively: a cache in the database shares the database\'s disk and connections, and a cache that is per-process keeps nothing between requests.'),
                'config(\'cache.default\')',
                $cacheDriver === null ? __('The configured cache store declares no driver.') : null,
            ),
            self::row(
                'cache_rows',
                __('Cache entries stored'),
                $cacheRows === null ? null : number_format($cacheRows),
                __('now'),
                __('Rows in the cache table, including entries that have expired and have not yet been overwritten. Nothing here judges the figure: a large cache table is ordinary.'),
                $cacheTable . ' count(*)',
                $cacheRows === null
                    ? __('This cache driver (:driver) keeps nothing in a database table, so there is no row count to take.', ['driver' => $cacheDriver ?? __('unknown')])
                    : null,
            ),
            self::row(
                'session_driver',
                __('Session store'),
                $sessionDriver,
                __('now'),
                __('Where signed-in sessions are kept. If this store stops accepting writes, every principal on the platform is signed out.'),
                'config(\'session.driver\')',
                $sessionDriver === null ? __('No session driver is configured.') : null,
            ),
            self::row(
                'session_rows',
                __('Sessions stored'),
                $sessionRows === null ? null : number_format($sessionRows),
                __('now'),
                __('Rows in the session table. This is not a count of people signed in: a row survives until the framework\'s own sweep removes it, and one person using two browsers has two rows.'),
                $sessionTable . ' count(*)',
                $sessionRows === null
                    ? __('This session driver (:driver) keeps nothing in a database table, so there is no row count to take.', ['driver' => $sessionDriver ?? __('unknown')])
                    : null,
            ),
        ];
    }

    /**
     * THE INTEGRATIONS, AND THE HONEST LIMIT OF WHAT "RECENTLY FAILED" CAN MEAN HERE.
     *
     * `OPS-001` asks for integrations to be monitored, and the three answerable questions are which
     * are configured, which are switched on, and which have recently failed. The first two are
     * measured below and on the gateway table. The third is the one that needs stating plainly:
     *
     * NO PAYMENT GATEWAY, MAIL TRANSPORT OR SMS PROVIDER IN THIS CODEBASE RECORDS A FAILURE.
     * Measured — `PaymentVerificationService::recordVerification()` writes its record into the
     * SESSION, which is gone at the end of the request; every other integration path logs a line
     * and continues. So the only durable failure record in the product is `failed_jobs`, which
     * covers integration work that happens inside a QUEUED job and nothing else. A screen that
     * showed a failure count without saying that would be read as a complete list.
     *
     * @return list<array<string, mixed>>
     */
    private static function integrations(SaasOperationsProbe $probe, int $failureWindowHours): array
    {
        $mail = $probe->mailTransport();
        $otp = $probe->otpIntegrations();
        $failed = $probe->failedJobs($failureWindowHours);

        $rows = [
            self::row(
                'mail_transport',
                __('Mail transport'),
                $mail['transport'] === null ? null : ($mail['mailer'] . ' (' . $mail['transport'] . ')'),
                __('now'),
                __('How this deployment sends mail. No user name, password or API key is read or shown; only which transport is selected.'),
                'config(\'mail.default\')',
                $mail['transport'] === null ? __('No mail transport is configured.') : null,
            ),
            self::row(
                'mail_host',
                __('Mail host'),
                $mail['host'],
                __('now'),
                __('The host the transport is pointed at. A host-based transport with no host configured is a platform that sends nothing, and that is the one thing this row exists to make visible. Nothing here contacts the host: reachability would need an outbound connection from the web request, which is a separate decision.'),
                'config(\'mail.mailers.*.host\')',
                $mail['host'] === null
                    ? __('The selected transport has no host configured. For a host-based transport that means no mail can be sent; for an API-based or log transport it is expected.')
                    : null,
            ),
            self::row(
                'otp_configured',
                __('Stores with an SMS or WhatsApp integration'),
                $otp === null ? null : number_format($otp['configured']),
                __('now'),
                __('Rows in the OTP integration table, one per store that has configured a provider. The provider secret is encrypted at rest and is never read to answer this: the count is asked of the database.'),
                'otp_integrations count(*)',
                $otp === null ? __('The OTP integration table is not present on this deployment.') : null,
            ),
            self::row(
                'otp_active',
                __('…of those, switched on'),
                $otp === null ? null : number_format($otp['active']),
                __('now'),
                __('Configured and marked active. The difference from the row above is a provider a merchant set up and then turned off.'),
                'otp_integrations where is_active',
                $otp === null ? __('The OTP integration table is not present on this deployment.') : null,
            ),
        ];

        $perQueue = $failed === null ? [] : $failed['per_queue'];

        $rows[] = self::row(
            'failed_queues',
            __('Queues with recorded failures'),
            $failed === null ? null : ($perQueue === [] ? __('None') : implode(', ', array_map(
                static fn (string $queue, int $count): string => $queue . ' (' . number_format($count) . ')',
                array_keys($perQueue),
                array_values($perQueue),
            ))),
            __('since the failure table was last cleared'),
            __('Which queues hold failed jobs, and how many each holds. This is the whole of the integration failure history this product keeps: no gateway, transport or provider writes a failure of its own, so an integration that fails outside a queued job leaves nothing here to see.'),
            ($probe->failedJobsTable() ?? 'failed_jobs') . ' group by queue',
            $failed === null
                ? __('This deployment does not record failed jobs in the database, so no failure history can be read here.')
                : null,
        );

        return $rows;
    }

    /**
     * PAYMENT GATEWAYS ACROSS EVERY TENANT: switched on, and complete.
     *
     * THE DIFFERENCE BETWEEN THE TWO COUNTS IS THE FIGURE WORTH HAVING. A gateway switched on with
     * a required credential missing is a checkout that fails at the provider, and
     * `PaymentSettingController::processGateway()` records in its own comment that the
     * `required_fields` validation "had never run" before `F142` — so rows in exactly that state
     * are expected to exist in any deployment with history, and nothing in the product has ever
     * shown them.
     *
     * RETIRED GATEWAYS ARE NAMED, NOT HIDDEN. `RetiredGateways` withdraws a provider by overriding
     * the READ, deliberately without writing `off` into any tenant's row — so a retired gateway can
     * still have `on` rows, and a screen that silently filtered them would hide the fact that the
     * withdrawal is an override rather than a state. The column says which.
     *
     * ONLY GATEWAYS SOMEBODY HAS ACTUALLY SWITCHED ON ARE LISTED. All 52 would be 49 rows of zero,
     * and the summary rows above the table carry the full population so nothing is concealed by the
     * filter.
     *
     * @return array{rows: list<array<string, mixed>>, detail: list<array<string, mixed>>}
     */
    private static function gateways(SaasOperationsProbe $probe): array
    {
        $registry = (new PaymentSettingController())->getPaymentGateways();
        $measured = $probe->paymentGatewayEnablement($registry);

        $refused = __('The settings table could not be read, so gateway enablement is unknown. It is the table that holds both the on/off rows and the credentials, and this screen reads only the on/off rows.');

        $detail = [];
        $enabledGateways = 0;
        $incompleteGateways = 0;
        $retiredStillOn = 0;
        $ambiguousRows = 0;

        if ($measured !== null) {
            foreach ($registry as $gateway) {
                $id = $gateway['id'];
                $figures = $measured[$id] ?? null;

                if ($figures === null) {
                    continue;
                }

                $ambiguousRows += $figures['ambiguous_rows'];

                if ($figures['enabled_stores'] === 0) {
                    continue;
                }

                $enabledGateways++;

                $incomplete = $figures['enabled_stores'] - $figures['complete_stores'];

                if ($incomplete > 0) {
                    $incompleteGateways++;
                }

                $retired = RetiredGateways::isRetired($id);

                if ($retired) {
                    $retiredStillOn++;
                }

                $detail[] = [
                    'id' => $id,
                    'enabled_stores' => $figures['enabled_stores'],
                    'complete_stores' => $figures['complete_stores'],
                    'incomplete_stores' => $incomplete,
                    'required' => $figures['required'],
                    'retired' => $retired,
                ];
            }

            usort($detail, static fn (array $a, array $b): int => $b['enabled_stores'] <=> $a['enabled_stores'] ?: strcmp($a['id'], $b['id']));
        }

        $rows = [
            self::row(
                'registry',
                __('Payment gateways this product integrates'),
                number_format(count($registry)),
                __('now'),
                __('Counted from the gateway registry in the code, not from a document. It is the population every figure below is a share of.'),
                'PaymentSettingController::getPaymentGateways()',
                null,
            ),
            self::row(
                'enabled',
                __('…switched on by at least one store'),
                $measured === null ? null : number_format($enabledGateways),
                __('now'),
                __('A gateway is switched on per store, by a settings row. This counts the gateways for which at least one store has one.'),
                'settings where name like \'is_%_enabled\' and value = \'on\'',
                $measured === null ? $refused : null,
            ),
            self::row(
                'incomplete',
                __('…switched on somewhere with a required credential missing'),
                $measured === null ? null : number_format($incompleteGateways),
                __('now'),
                __('For each gateway the registry names required fields; this counts the gateways where at least one store has switched it on without a value for all of them. Such a checkout reaches the provider and fails there. Only the PRESENCE of a value is asked of the database; no credential is read.'),
                'settings — required field present per store',
                $measured === null ? $refused : null,
            ),
            self::row(
                'retired_still_on',
                __('…withdrawn by the operator yet still switched on in a store'),
                $measured === null ? null : number_format($retiredStillOn),
                __('now'),
                __('A withdrawn gateway is overridden where it is READ, deliberately without rewriting any tenant\'s row, so its rows survive the withdrawal. Nothing is wrong with that and nothing is offered to a shopper; the count exists so the override is visible as an override rather than mistaken for a state.'),
                'RetiredGateways::isRetired() over the enabled set',
                $measured === null ? $refused : null,
            ),
            self::row(
                'ambiguous',
                __('On/off rows holding neither value'),
                $measured === null ? null : number_format($ambiguousRows),
                __('now'),
                __('The settings writer stores the literal on or off and nothing else, and this screen reads it that way. But most of the product\'s own readers test the row for emptiness instead, so a row holding anything else is read as switched ON there and as off here. Any figure above zero is that disagreement existing in the data, which is why it is counted rather than smoothed over.'),
                'settings where value not in (\'on\', \'off\')',
                $measured === null ? $refused : null,
            ),
        ];

        return ['rows' => $rows, 'detail' => $detail];
    }

    /** @return array<string, mixed> */
    private static function row(
        string $key,
        string $label,
        ?string $value,
        string $period,
        string $hint,
        string $source,
        ?string $reason,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'period' => $period,
            'hint' => $hint,
            'source' => $source,
            'reason' => $reason,
        ];
    }

    /**
     * Bytes, spelled the way the rest of this application spells a size.
     *
     * `UploadLimits::humanKb()` and NOT a second formatter, for the reason
     * `SaasPlatformResources::bytes()` gives: two size formatters is how two screens come to
     * disagree about what a gigabyte is, and this screen sits beside that one.
     */
    private static function bytes(int $bytes): string
    {
        return UploadLimits::humanKb((int) round($bytes / 1024));
    }
}
