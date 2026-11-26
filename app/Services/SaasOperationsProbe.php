<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OPS-001 — WHERE THE DATABASE, QUEUE AND INTEGRATION NUMBERS COME FROM, AND NOTHING ELSE.
 * =================================================================================================
 *
 * The sibling of `SaasResourceProbe`, with the same single job and the same contract: ask the
 * running system, return either its own figure or `null`, and never format, label, translate,
 * judge or round anything. `SaasPlatformOperations` does the presenting.
 *
 * `null` MEANS "THIS DEPLOYMENT WOULD NOT TELL US", NEVER ZERO. The distinction does more work here
 * than it does on the machine screen, because three of the four subjects are DRIVER-DEPENDENT: a
 * Redis queue has a depth that no SQL query can see, and reporting 0 pending jobs because the
 * `jobs` table is empty on a deployment whose queue is Redis is a measurement that is wrong by
 * however long the backlog is. So every driver-specific reader states which driver it can answer
 * for and refuses the rest.
 *
 * WHY THE RECOVERED REQUIREMENT NEEDED THIS AT ALL. `OPS-001` is "مراقبة الخوادم وقواعد البيانات
 * والطوابير والتكاملات" — monitor servers, databases, queues and integrations. The SERVERS half
 * shipped as `GAP-195`/`OPS-002` (`SaasResourceProbe`, `/platform/resources`), and that screen says
 * on its own face that the other three are not measured anywhere. This class is those three.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * NO CREDENTIAL VALUE EVER LEAVES THIS CLASS, AND THE SQL IS WHAT ENFORCES IT
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The integration readings are taken off the `settings` table, which is also where every payment
 * gateway's live secret key is stored — `stripe_secret_key`, `paytabs_server_key`,
 * `PAYMERA_USERNAME` and 100-odd more (`GatewaySecretRedactor` is the inventory). A reader that
 * fetched rows and tested emptiness in PHP would hold every merchant's keys in memory and one
 * `dd()` away from a screen.
 *
 * So the queries never SELECT a `value`. Presence is asked of the engine
 * (`whereNotNull('value')->where('value', '!=', '')`) and only `name` and `store_id` come back; the
 * enable flags are counted with `where('value', 'on')`, which is a literal in the predicate and not
 * a column in the result. `PlatformOperationsMeasurementTest::
 * test_no_integration_reading_ever_selects_a_settings_value` reads this file to hold that.
 *
 * NOT `final`, for the one reason `SaasResourceProbe` is not: the tests substitute a subclass to
 * reach states this container cannot be put into — a database that refuses, a driver nobody has
 * installed. Nothing in the application subclasses it.
 *
 * THIS CLASS IS NOT AN AUTHORIZATION BOUNDARY. Every figure spans every tenant, so each caller's
 * own gate is the whole control. Its one caller is `SuperAdminOperationsController`, behind
 * `PlatformOperatorOnly` on the route.
 */
class SaasOperationsProbe
{
    /**
     * Drivers whose on-disk size this class can really ask for.
     *
     * Named rather than guessed because the query is different for each and there is no generic
     * one: MySQL and MariaDB answer from `information_schema`, SQLite from two pragmas. A
     * deployment on PostgreSQL or SQL Server gets `null` and a sentence, not a plausible figure
     * from the wrong catalogue.
     */
    public const SIZE_DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    /** Drivers whose server-side connection count this class can ask for. */
    public const CONNECTION_COUNT_DRIVERS = ['mysql', 'mariadb'];

    /** The one queue driver whose backlog is a SQL question. */
    public const QUEUE_DRIVER_DATABASE = 'database';

    /** The queue driver that means there is no queue: every job runs inside the request. */
    public const QUEUE_DRIVER_SYNC = 'sync';

    /*
     * =============================================================================================
     * THE FOUR TABLES THIS CLASS READS BY NAME, AS LITERALS — AND WHY NOT FROM CONFIGURATION
     * =============================================================================================
     *
     * Every one of these names is also a configuration key: `DB_QUEUE_TABLE`, `queue.failed.table`,
     * `DB_CACHE_TABLE`, `SESSION_TABLE`. The obvious reader is `DB::table(config(...))`, and it is
     * wrong here for two separate reasons:
     *
     *   1. IT IS A TABLE IDENTIFIER TAKEN FROM OUTSIDE THE CODE. A table name is not a bound
     *      parameter — it is interpolated into the SQL — so `DB::table($fromConfig)` reads whatever
     *      the environment names, including a table this screen has no business counting. Nothing
     *      in this codebase validates those keys, and an operations screen is the wrong place to
     *      start trusting them.
     *
     *   2. IT DEFEATS THIS REPOSITORY'S OWN RAW-QUERY CENSUS. `RawQueryTenancyInventoryTest`
     *      classifies every raw query BY TABLE, keyed on a literal in the source
     *      (`LITERAL_TABLE_PATTERN`), so `DB::table($variable)` is a site it cannot classify at
     *      all — and an unclassifiable raw site in a shared-database multi-tenant product is
     *      exactly what that census exists to prevent. Writing the names as literals keeps every
     *      statement here visible to it.
     *
     * SO THE CONFIGURED NAME IS COMPARED, NOT USED. A deployment that has renamed one of these
     * tables gets the figure WITHHELD with a sentence naming what it configured — which is the
     * honest answer, because this class genuinely cannot measure a table it does not know about,
     * and a count from the wrong table would be a plausible wrong number.
     */

    public const JOBS_TABLE = 'jobs';

    public const FAILED_JOBS_TABLE = 'failed_jobs';

    public const CACHE_TABLE = 'cache';

    public const SESSIONS_TABLE = 'sessions';

    /*
     * =============================================================================================
     * THE DATABASE
     * =============================================================================================
     */

    /** The connection name this application is configured to use. Configuration, not a reading. */
    public function databaseConnectionName(): string
    {
        return (string) config('database.default');
    }

    /** The driver the connection really resolved to, asked of the connection and not of config. */
    public function databaseDriver(): ?string
    {
        try {
            return $this->connection()->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The database the connection is actually attached to — a schema name, or SQLite's file path.
     *
     * Asked of the connection rather than read out of `config('database.connections.…')`, because
     * the two disagree whenever a deployment sets `DB_URL` or switches connection at runtime, and
     * the one that matters is the one the queries are going to.
     */
    public function databaseName(): ?string
    {
        try {
            $name = $this->connection()->getDatabaseName();
        } catch (Throwable) {
            return null;
        }

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** The engine's own version string, from the driver. */
    public function databaseServerVersion(): ?string
    {
        try {
            $version = $this->connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return null;
        }

        return is_scalar($version) && (string) $version !== '' ? (string) $version : null;
    }

    /**
     * WHETHER THE DATABASE ANSWERS AT ALL, and the SQLSTATE when it does not.
     *
     * A connectivity reading is nearly tautological for the default connection on a page that only
     * rendered because the session store answered — and it is kept anyway, because the alternative
     * is a monitoring screen with no reachability row on it at all, and because the queue and cache
     * connections below are NOT the one that served this request and can genuinely be down while
     * this page draws.
     *
     * THE DRIVER'S MESSAGE IS DELIBERATELY NOT RETURNED. A failed PDO connection message can carry
     * the DSN, and a DSN can carry a password; this screen is the wrong place to find that out. The
     * SQLSTATE is the diagnostic an operator can act on, the exception class says which layer
     * refused, and the full message is in the application log where it already was.
     *
     * @return array{answered: bool, sqlstate: string|null, exception: string|null}
     */
    public function databaseReachability(?string $connection = null): array
    {
        try {
            $this->connection($connection)->select('select 1');

            return ['answered' => true, 'sqlstate' => null, 'exception' => null];
        } catch (Throwable $e) {
            $sqlstate = null;

            if ($e instanceof \PDOException && isset($e->errorInfo[0]) && is_string($e->errorInfo[0])) {
                $sqlstate = $e->errorInfo[0];
            }

            return [
                'answered' => false,
                'sqlstate' => $sqlstate,
                'exception' => $e::class,
            ];
        }
    }

    /**
     * How many tables the database holds.
     *
     * A count of tables is a real figure about the installation and it is the one number on this
     * screen that an operator can check against this repository's own migration set — 100-odd
     * tables where 15 are expected is a half-migrated deployment, which is `GATE F`'s whole
     * subject.
     *
     * SQLite's `sqlite_master` excludes its own `sqlite_%` internal objects, and MySQL's
     * `information_schema` is restricted to the attached schema, so neither count includes the
     * catalogue it was read from.
     */
    public function databaseTableCount(): ?int
    {
        $driver = $this->databaseDriver();

        try {
            if ($driver === 'sqlite') {
                $row = $this->connection()->selectOne(
                    "select count(*) as c from sqlite_master where type = 'table' and name not like 'sqlite_%'"
                );

                return $row === null ? null : (int) $row->c;
            }

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $row = $this->connection()->selectOne(
                    'select count(*) as c from information_schema.tables where table_schema = ?',
                    [$this->databaseName()]
                );

                return $row === null ? null : (int) $row->c;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * The bytes the database occupies, as the engine accounts for them.
     *
     * MySQL's `data_length + index_length` is the engine's own estimate and is approximate for
     * InnoDB — that is a property of the figure and is stated on the screen rather than corrected
     * here, because "corrected" would mean invented. SQLite's `page_count * page_size` is exact.
     */
    public function databaseSizeBytes(): ?int
    {
        $driver = $this->databaseDriver();

        try {
            if ($driver === 'sqlite') {
                $pages = $this->connection()->selectOne('pragma page_count');
                $size = $this->connection()->selectOne('pragma page_size');

                if ($pages === null || $size === null) {
                    return null;
                }

                $pages = (int) collect((array) $pages)->first();
                $size = (int) collect((array) $size)->first();

                return $pages > 0 && $size > 0 ? $pages * $size : null;
            }

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $row = $this->connection()->selectOne(
                    'select sum(data_length + index_length) as b from information_schema.tables where table_schema = ?',
                    [$this->databaseName()]
                );

                if ($row === null || $row->b === null) {
                    return null;
                }

                return (int) $row->b;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * How many client connections the engine currently has open — all of them, not this one.
     *
     * `Threads_connected` is the figure that tells an operator whether `max_connections` is about
     * to refuse a request, and there is no portable equivalent, so every other driver gets null.
     */
    public function databaseConnectionsInUse(): ?int
    {
        if (! in_array($this->databaseDriver(), self::CONNECTION_COUNT_DRIVERS, true)) {
            return null;
        }

        try {
            $row = $this->connection()->selectOne("show status like 'Threads_connected'");
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $fields = array_values((array) $row);

        return isset($fields[1]) && is_numeric($fields[1]) ? (int) $fields[1] : null;
    }

    /*
     * =============================================================================================
     * THE QUEUES
     * =============================================================================================
     */

    /** The queue connection this application is configured to use. */
    public function queueConnectionName(): string
    {
        return (string) config('queue.default');
    }

    /** That connection's driver, or null when the connection is not configured at all. */
    public function queueDriver(): ?string
    {
        $driver = config('queue.connections.' . $this->queueConnectionName() . '.driver');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /** The table the database queue driver keeps its jobs in. */
    public function queueTable(): string
    {
        $table = config('queue.connections.' . $this->queueConnectionName() . '.table');

        return is_string($table) && $table !== '' ? $table : 'jobs';
    }

    /**
     * THE BACKLOG: what is waiting, what a worker has taken, and how old the oldest wait is.
     *
     * Null for every driver but `database`, and that refusal is the measurement on a Redis or SQS
     * deployment: this class cannot see that backlog, and an empty `jobs` table there means the
     * table is unused, not that nothing is queued. Null too when the deployment has renamed the
     * table — see the table constants above.
     *
     * RESERVED IS COUNTED SEPARATELY FROM PENDING, because they fail differently. Pending is
     * throughput — work waiting for a worker. Reserved and NOT shrinking is a worker that died
     * holding jobs, which is the commonest queue outage and is invisible in a single total.
     *
     * `available_at` IS NOT USED AS "WAITING SINCE". A delayed job (`later()`) has an
     * `available_at` in the future and has not been waiting at all; `created_at` is when the job
     * was handed over, which is the age an operator means.
     *
     * NO PER-QUEUE BREAKDOWN, DELIBERATELY. An earlier draft produced one and nothing displayed
     * it. A figure nothing shows is a figure nobody checks, and here it would also have been the
     * one aggregate on this screen needing a `GROUP BY` over a table that can hold six figures of
     * rows. The failure breakdown below is different: it IS displayed, and `failed_jobs` is
     * bounded by how often an operator clears it.
     *
     * @return array{pending: int, reserved: int, oldest_pending_created_at: int|null}|null
     */
    public function queuedJobs(): ?array
    {
        if ($this->queueDriver() !== self::QUEUE_DRIVER_DATABASE || $this->queueTable() !== self::JOBS_TABLE) {
            return null;
        }

        if (! $this->hasTable(self::JOBS_TABLE)) {
            return null;
        }

        try {
            $pending = (int) DB::table('jobs')->whereNull('reserved_at')->count();
            $reserved = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();
            $oldest = DB::table('jobs')->whereNull('reserved_at')->min('created_at');
        } catch (Throwable) {
            return null;
        }

        return [
            'pending' => $pending,
            'reserved' => $reserved,
            'oldest_pending_created_at' => $oldest === null ? null : (int) $oldest,
        ];
    }

    /**
     * The table the configured failed-job driver writes to, when it writes to one at all — and
     * when it is the one this class knows how to read.
     */
    public function failedJobsTable(): ?string
    {
        return $this->failedJobsState() === self::STORE_MEASURED ? self::FAILED_JOBS_TABLE : null;
    }

    /**
     * WHY THE FAILURE HISTORY IS OR IS NOT AVAILABLE, in the same four values the cache and session
     * stores use — and this method exists because the sentence was WRONG without it.
     *
     * `failedJobsTable()` answers null for two different reasons: the deployment records no
     * failures in the database at all, or it records them in a table this class does not read. The
     * presenter's one sentence said "the configured failed-job driver is :driver", which is true of
     * the first and simply false of the second — a renamed table has nothing wrong with its driver.
     * Caught by writing the renamed-table test and reading the reason it produced.
     */
    public function failedJobsState(): string
    {
        $driver = (string) config('queue.failed.driver');

        if (! str_starts_with($driver, 'database')) {
            return self::STORE_NOT_DATABASE;
        }

        $table = config('queue.failed.table');
        $table = is_string($table) && $table !== '' ? $table : self::FAILED_JOBS_TABLE;

        if ($table !== self::FAILED_JOBS_TABLE) {
            return self::STORE_TABLE_RENAMED;
        }

        return $this->hasTable(self::FAILED_JOBS_TABLE) ? self::STORE_MEASURED : self::STORE_TABLE_ABSENT;
    }

    /**
     * BACKGROUND WORK THAT FAILED — the only failure this product records anywhere.
     *
     * This is the reading `OPS-001`'s "integrations" half rests on, and the honesty of the whole
     * integrations group turns on saying so: no payment gateway, mail transport or SMS provider in
     * this codebase writes a failure row of its own. `PaymentVerificationService::
     * recordVerification()` puts its record in the SESSION, which is gone at the end of the
     * request; everything else logs and moves on. So "which integrations have recently failed" is
     * answerable only for the integration work that happens in a QUEUED job, and the screen says
     * that rather than implying the list is complete.
     *
     * THE WINDOW IS A PARAMETER AND THE CALLER MUST STATE IT. A failure count with no window is
     * the commonest way an operations page misleads: 400 failures could be last night or could be
     * since the platform launched.
     *
     * @return array{total: int, since: int, newest_failed_at: string|null, per_queue: array<string, int>}|null
     */
    public function failedJobs(int $sinceHours = 24): ?array
    {
        if ($this->failedJobsState() !== self::STORE_MEASURED) {
            return null;
        }

        try {
            $total = (int) DB::table('failed_jobs')->count();

            $since = (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHours($sinceHours))
                ->count();

            $newest = DB::table('failed_jobs')->max('failed_at');

            /*
             * THE BREAKDOWN WITHOUT A `GROUP BY`, and the two queries are the point.
             *
             * `selectRaw('queue, count(*)')` would be a builder-raw statement, which
             * `BuilderRawQueryVisibilityTest` records as INVISIBLE to this repository's main
             * raw-query census — the blind spot that census exists to measure. The distinct list
             * of queue names is tiny (a handful on any deployment), so asking for it and then
             * counting each one is the same answer with no raw SQL in it at all.
             */
            $queues = DB::table('failed_jobs')->distinct()->pluck('queue');

            $breakdown = [];

            foreach ($queues as $queue) {
                $breakdown[(string) $queue] = (int) DB::table('failed_jobs')
                    ->where('queue', $queue)
                    ->count();
            }
        } catch (Throwable) {
            return null;
        }

        ksort($breakdown);

        return [
            'total' => $total,
            'since' => $since,
            'newest_failed_at' => $newest === null ? null : (string) $newest,
            'per_queue' => $breakdown,
        ];
    }

    /*
     * =============================================================================================
     * THE OTHER TWO DATABASE-BACKED STORES
     * =============================================================================================
     */

    /** The cache store this application is configured to use. */
    public function cacheStoreName(): string
    {
        return (string) config('cache.default');
    }

    public function cacheDriver(): ?string
    {
        $driver = config('cache.stores.' . $this->cacheStoreName() . '.driver');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    public function sessionDriver(): ?string
    {
        $driver = config('session.driver');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /**
     * HOW MANY ROWS THE DATABASE-BACKED CACHE IS HOLDING.
     *
     * Only for the `database` driver, and null otherwise — a Redis cache's key count is not a SQL
     * question and an empty `cache` table on a Redis deployment is not an empty cache. Null too
     * when the deployment has renamed the table; see the table constants above for why the name is
     * compared rather than used.
     *
     * NOT A HEALTH FIGURE, AND THE SCREEN SAYS SO. A large `cache` table is normal. The figure
     * exists because those rows are on the same disk as the orders, which is a thing an operator
     * reads a trend for — and this one reading judges nothing.
     */
    public function cacheRowCount(): ?int
    {
        if ($this->cacheState() !== self::STORE_MEASURED) {
            return null;
        }

        try {
            return (int) DB::table('cache')->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * WHY A DATABASE-BACKED STORE'S ROW COUNT IS OR IS NOT AVAILABLE — and the fourth value is the
     * one this method exists for.
     *
     * A plain `null` would collapse four different situations into one sentence, and one of them is
     * an OUTAGE rather than an absence of interest:
     *
     *   `not_database`   the driver keeps nothing in a table. Expected, and nothing is wrong.
     *   `renamed`        this deployment has named the table something this class does not read.
     *   `table_absent`   **the store is configured to use the database and the table is not
     *                    there.** That is not a measurement this screen cannot take; it is a store
     *                    that cannot work.
     *   `measured`       the figure is available.
     *
     * AND `table_absent` IS NOT HYPOTHETICAL IN THIS REPOSITORY. Measured 2026-11-26: `.env.example`
     * ships `SESSION_DRIVER=database`, `config/session.php` defaults to `database`, and
     * `database/migrations/` contains NO migration that creates a `sessions` table — `grep -rln
     * "create('sessions'" database/migrations/` is empty, while `cache` and `jobs` both have one
     * (`0001_01_01_000001`, `0001_01_01_000002`). So a deployment taking the shipped defaults has a
     * session store pointed at a table that does not exist. Reporting that as "this driver keeps
     * nothing in a table" would be the exact opposite of what is wrong.
     */
    public const STORE_MEASURED = 'measured';

    public const STORE_NOT_DATABASE = 'not_database';

    public const STORE_TABLE_RENAMED = 'renamed';

    public const STORE_TABLE_ABSENT = 'table_absent';

    public function cacheState(): string
    {
        if ($this->cacheDriver() !== 'database') {
            return self::STORE_NOT_DATABASE;
        }

        $configured = config('cache.stores.' . $this->cacheStoreName() . '.table');

        if ((is_string($configured) && $configured !== '' ? $configured : self::CACHE_TABLE) !== self::CACHE_TABLE) {
            return self::STORE_TABLE_RENAMED;
        }

        return $this->hasTable(self::CACHE_TABLE) ? self::STORE_MEASURED : self::STORE_TABLE_ABSENT;
    }

    public function sessionState(): string
    {
        if ($this->sessionDriver() !== 'database') {
            return self::STORE_NOT_DATABASE;
        }

        $configured = config('session.table');

        if ((is_string($configured) && $configured !== '' ? $configured : self::SESSIONS_TABLE) !== self::SESSIONS_TABLE) {
            return self::STORE_TABLE_RENAMED;
        }

        return $this->hasTable(self::SESSIONS_TABLE) ? self::STORE_MEASURED : self::STORE_TABLE_ABSENT;
    }

    /** Whether a table is there, without letting a refusing connection become an exception. */
    protected function hasTable(string $table): bool
    {
        try {
            return $this->connection()->getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * HOW MANY ROWS THE DATABASE-BACKED SESSION STORE IS HOLDING.
     *
     * The twin of `cacheRowCount()`, and not a count of people signed in: a row survives until the
     * framework's own sweep removes it, and one person using two browsers has two rows. It is worth
     * a row of its own because a `sessions` table that stops growing is a session store that
     * stopped accepting writes, which signs every principal on the platform out.
     */
    public function sessionRowCount(): ?int
    {
        if ($this->sessionState() !== self::STORE_MEASURED) {
            return null;
        }

        try {
            return (int) DB::table('sessions')->count();
        } catch (Throwable) {
            return null;
        }
    }

    /*
     * =============================================================================================
     * THE INTEGRATIONS
     * =============================================================================================
     */

    /**
     * PAYMENT-GATEWAY ENABLEMENT ACROSS EVERY TENANT, measured and holding no credential.
     *
     * Per gateway id: how many stores have switched it on, and how many of those have every field
     * its own registry entry marks `required_fields`. The difference is the figure worth having —
     * a gateway switched on with a credential missing is a checkout that fails at the provider,
     * and `PaymentSettingController::processGateway()`'s own comment records that the validation
     * "had never run" before `F142`, so rows in exactly that state are expected to exist.
     *
     * THE ENABLE PREDICATE IS `value = 'on'` BECAUSE THAT IS WHAT THE WRITER WRITES.
     * `processGateway()` assigns the literal `'on'` or `'off'` and nothing else. But several
     * READERS use `!empty($row)` instead — `Setting::paymentList` does it for 49 of its 52
     * entries — so a row holding anything other than those two literals is read as ENABLED by
     * those call sites and as disabled here. That disagreement is a real defect waiting in the
     * data, so it is counted rather than smoothed over: see `ambiguous`.
     *
     * @param  list<array{id: string, fields: list<string>, required_fields: list<string>}> $gateways
     * @return array{gateways: array<string, array{enabled_stores: int, complete_stores: int, required: int}>, ambiguous_rows: int}|null
     */
    public function paymentGatewayEnablement(array $gateways): ?array
    {
        if (! $this->hasTable('settings')) {
            return null;
        }

        try {
            $enableNames = [];
            $requiredNames = [];

            foreach ($gateways as $gateway) {
                $enableNames['is_' . $gateway['id'] . '_enabled'] = $gateway['id'];

                foreach ($gateway['required_fields'] as $field) {
                    $requiredNames[$field][] = $gateway['id'];
                }
            }

            /*
             * NO `value` IN ANY SELECT LIST. `'on'`, `'off'` and `''` are literals in the
             * PREDICATE, so the engine answers the presence question and the secrets stay in the
             * engine.
             *
             * AND NO AGGREGATE EITHER. An earlier draft asked the engine for
             * `count(distinct store_id) ... group by name`, which is a builder-raw statement —
             * invisible to this repository's main raw-query census by construction
             * (`BuilderRawQueryVisibilityTest` measures exactly that blind spot). The (name,
             * store_id) pairs are a bounded set, one row per gateway a store has switched on, and
             * counting them in PHP gives the same two figures from ONE query instead of three.
             */
            $enabledPairs = DB::table('settings')
                ->select('name', 'store_id')
                ->whereIn('name', array_keys($enableNames))
                ->where('value', 'on')
                ->get();

            /*
             * THE AMBIGUOUS ROWS AS ONE TOTAL. Only the total is shown, so only the total is asked
             * for; a per-gateway breakdown nothing displays would be a figure nobody checks.
             */
            $ambiguousTotal = (int) DB::table('settings')
                ->whereIn('name', array_keys($enableNames))
                ->whereNotNull('value')
                ->whereNotIn('value', ['on', 'off'])
                ->count();

            $presentPairs = $requiredNames === [] ? collect() : DB::table('settings')
                ->select('name', 'store_id')
                ->whereIn('name', array_keys($requiredNames))
                ->whereNotNull('value')
                ->where('value', '!=', '')
                ->get();
        } catch (Throwable) {
            return null;
        }

        $measured = [];

        foreach ($gateways as $gateway) {
            $measured[$gateway['id']] = [
                'enabled_stores' => 0,
                'complete_stores' => 0,
                'required' => count($gateway['required_fields']),
            ];
        }

        // store id => the set of required field names that store has a non-empty value for
        $present = [];

        foreach ($presentPairs as $row) {
            $present[(int) $row->store_id][(string) $row->name] = true;
        }

        // gateway id => the stores that switched it on
        $on = [];

        foreach ($enabledPairs as $row) {
            $id = $enableNames[(string) $row->name] ?? null;

            if ($id !== null) {
                $on[$id][] = (int) $row->store_id;
            }
        }

        foreach ($measured as $id => $figures) {
            $measured[$id]['enabled_stores'] = count(array_unique($on[$id] ?? []));
        }

        foreach ($gateways as $gateway) {
            $required = $gateway['required_fields'];
            $stores = $on[$gateway['id']] ?? [];

            if ($required === []) {
                // Nothing is required, so every store that switched it on is complete by
                // definition. COD and bank transfer are the real members of this set.
                $measured[$gateway['id']]['complete_stores'] = count(array_unique($stores));

                continue;
            }

            $complete = 0;

            foreach (array_unique($stores) as $storeId) {
                $has = $present[$storeId] ?? [];

                foreach ($required as $field) {
                    if (! isset($has[$field])) {
                        continue 2;
                    }
                }

                $complete++;
            }

            $measured[$gateway['id']]['complete_stores'] = $complete;
        }

        return ['gateways' => $measured, 'ambiguous_rows' => $ambiguousTotal];
    }

    /**
     * THE SMS / WHATSAPP OTP INTEGRATION, counted and never read.
     *
     * `otp_integrations.api_secret` is an `encrypted` cast, so an Eloquent read would decrypt every
     * merchant's provider secret into this request's memory to answer "how many are active". The
     * count is asked of the engine instead.
     *
     * @return array{configured: int, active: int}|null
     */
    public function otpIntegrations(): ?array
    {
        if (! $this->hasTable('otp_integrations')) {
            return null;
        }

        try {
            return [
                'configured' => (int) DB::table('otp_integrations')->count(),
                'active' => (int) DB::table('otp_integrations')->where('is_active', true)->count(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * THE MAIL TRANSPORT: which one is configured, and whether it has a host at all.
     *
     * The host NAME is configuration an operator already owns and is printed; no username,
     * password or API key is read, returned or printed. `smtp` with no host is a platform that
     * sends nothing, which is the one thing this reading exists to make visible.
     *
     * @return array{mailer: string|null, transport: string|null, host: string|null}
     */
    public function mailTransport(): array
    {
        $mailer = config('mail.default');
        $mailer = is_string($mailer) && $mailer !== '' ? $mailer : null;

        $transport = $mailer === null ? null : config('mail.mailers.' . $mailer . '.transport');
        $host = $mailer === null ? null : config('mail.mailers.' . $mailer . '.host');

        return [
            'mailer' => $mailer,
            'transport' => is_string($transport) && $transport !== '' ? $transport : null,
            'host' => is_string($host) && $host !== '' ? $host : null,
        ];
    }

    /** The default connection, or a named one. Separated so the reachability reader can take either. */
    protected function connection(?string $name = null): ConnectionInterface
    {
        return $name === null ? DB::connection() : DB::connection($name);
    }
}
