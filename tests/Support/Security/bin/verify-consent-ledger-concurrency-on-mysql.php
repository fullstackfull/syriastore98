<?php

/*
 * D8-1 INDEPENDENT REVIEW, FINDINGS F1 AND F2 -- THE CONSENT LEDGER UNDER CONCURRENCY, DRIVEN ON A REAL SERVER.
 *
 * WHAT WAS CLAIMED. A read-only review of `ConsentLedger::record()` said that it INSERTs the event (which is when the
 * event gets its id) BEFORE it locks the projection row, so the order in which two overlapping requests for one contact
 * fold into the projection (lock order) is independent of the order `rebuildState()` folds them (id order):
 *
 *   F1  a withdrawal can be silently lost in the table the send gate reads. The log says "granted, then withdrawn"; the
 *       projection says `granted`. It is the unsafe direction, and nothing ever compares the two.
 *   F2  two FIRST-EVER events for one contact: at REPEATABLE READ (the server default, and nothing in config/database.php
 *       changes it) the locking read of an ABSENT unique key takes a gap lock, so both writers pass the SELECT and then
 *       deadlock on the INSERT. The catch for a duplicate-key error is never reached and `DB::transaction()` retries
 *       nothing, so one request throws and its event is rolled back.
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST. SQLite compiles `lockForUpdate()` to nothing and `:memory:` is one connection, so
 * neither race is observable in the suite. The repository's own convention (see
 * verify-cart-reconciliation-concurrency-on-mysql.php) is that a concurrency claim is MEASURED on a real server with
 * more than one connection. This script uses several REAL PROCESSES, each with its own connection, running the REAL
 * `ConsentLedger` -- not a re-implementation of its SQL -- and forces the interleaving with a `DB::listen` hook instead of
 * hoping for it.
 *
 * THE SCENARIOS
 *   S1  contact is `granted`. Process A records a grant and is PAUSED immediately after its event INSERT. Process B then
 *       records a withdrawal. If B can finish while A is paused, A's event has the LOWER id and folds LAST.
 *   S2  a contact with no projection row. Two processes each pause at a barrier after their event INSERT / lock, until
 *       the other is there too (or three seconds pass), and then both continue. This is the F2 window, made deterministic.
 *   S3  a stress run: several processes, each applying a seeded random sequence of events to the SAME contact, with
 *       no hooks. Statistical, not deterministic -- it is here to catch what the two forced cases did not think of.
 *
 * THE INVARIANT, checked after each scenario and independent of how the code is written: the projection row the gate reads
 * equals the state obtained by folding the log in id order, and every request that was accepted left an event.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and refuses a name that does not
 * say so.
 *
 *     CONSENT_MYSQL_SOCKET=/run/mysqld/mysqld.sock php tests/Support/Security/bin/verify-consent-ledger-concurrency-on-mysql.php
 *
 * NEVER point CONSENT_MYSQL_DATABASE at a development or production server. Exit code 0 means every check held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Marketing\ConsentLedger;
use App\Support\Marketing\Channel;
use App\Support\Marketing\ConsentEvent;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ContactAddress;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

const STORE = 1;

$database = getenv('CONSENT_MYSQL_DATABASE') ?: 'consent_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('CONSENT_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) { $socket = $candidate; break; }
    }
}

$base = [
    'driver' => 'mysql',
    'host' => getenv('CONSENT_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('CONSENT_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('CONSENT_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('CONSENT_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false, // the production setting, per config/database.php and the other verification scripts
];

$boot = $base;
$boot['database'] = null;
config(['database.connections.consent_boot' => $boot, 'database.connections.consent_main' => $base, 'database.default' => 'consent_main']);

/* ------------------------------------------------------------------------------------------------------------------
 * CHILD MODE: one process, one connection, one ledger call (or a short seeded sequence).
 * ---------------------------------------------------------------------------------------------------------------- */
$child = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--child=')) { $child = substr($arg, 8); }
}

if ($child !== null) {
    exit(runChild($child, $argv));
}

function option(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) { return substr($arg, strlen($name) + 3); }
    }

    return $default;
}

function runChild(string $role, array $argv): int
{
    $dir = option($argv, 'dir');
    $address = (string) option($argv, 'address');
    $name = (string) option($argv, 'name', $role);
    $other = (string) option($argv, 'other', '');
    $waitMs = (int) option($argv, 'wait-ms', '4000');
    $ledger = app(ConsentLedger::class);
    $out = ['role' => $role, 'name' => $name];

    // The hook fires AFTER the statement it matches has executed, and then holds this process until the other one
    // has reached the same point (or the wait runs out). Two points are used, because the two findings live at two points:
    //
    //   `done`    -> after the EVIDENCE INSERT. In the code as first written that is before the projection is locked; in a
    //                code path that locks first, it is after. The hook does not care which: it is the same statement.
    //   `barrier` -> after the LOCKING READ of the projection row. For a contact with no row yet that read takes a gap lock
    //                and finds nothing, which is the F2 window: both writers hold it and both are about to INSERT.
    $hook = function (string $mode) use ($dir, $name, $other, $waitMs) {
        DB::listen(function ($query) use ($mode, $dir, $name, $other, $waitMs) {
            $sql = strtolower(rtrim($query->sql));
            $hit = $mode === 'barrier'
                ? (str_contains($sql, 'from `marketing_contact_states`') && str_ends_with($sql, 'for update'))
                : str_starts_with($sql, 'insert into `marketing_consent_events`');
            if (!$hit) {
                return;
            }
            @touch("{$dir}/{$name}.paused");
            $deadline = microtime(true) + $waitMs / 1000;
            $watch = $mode === 'barrier' ? "{$dir}/{$other}.paused" : "{$dir}/{$other}.done";
            while (!file_exists($watch) && microtime(true) < $deadline) {
                usleep(20000);
            }
        });
    };

    try {
        switch ($role) {
            case 'pause-grant':   // S1, process A
                $hook('done');
                $event = $ledger->grant(STORE, Channel::Email, $address, 'verify_checkout');
                break;
            case 'withdraw':      // S1, process B
                $event = $ledger->withdraw(STORE, Channel::Email, $address, 'verify_unsubscribe');
                break;
            case 'barrier-grant': // S2
                $hook('barrier');
                $event = $ledger->grant(STORE, Channel::Email, $address, 'verify_barrier_' . $name);
                break;
            case 'stress':        // S3
                mt_srand((int) option($argv, 'seed', '1'));
                $applied = 0; $errors = [];
                for ($i = 0; $i < (int) option($argv, 'ops', '12'); $i++) {
                    $kind = ['grant', 'withdraw', 'suppress', 'liftSuppression'][mt_rand(0, 3)];
                    try {
                        $ledger->{$kind}(STORE, Channel::Email, $address, 'verify_stress_' . $name);
                        $applied++;
                    } catch (Throwable $e) {
                        $errors[] = classify($e);
                    }
                }
                $out += ['applied' => $applied, 'errors' => $errors];
                $event = null;
                break;
            default:
                throw new InvalidArgumentException("unknown role {$role}");
        }
        $out['ok'] = true;
        $out['event_id'] = $event?->id;
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = classify($e);
    }

    @touch("{$dir}/{$name}.done");
    echo json_encode($out), "\n";

    return 0;
}

function classify(Throwable $e): string
{
    $code = $e instanceof Illuminate\Database\QueryException ? ($e->errorInfo[1] ?? '?') : $e->getCode();

    return get_class($e) . ' [' . $code . '] ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 140);
}

/* ------------------------------------------------------------------------------------------------------------------
 * PARENT MODE: build a throwaway database, run the scenarios, check the invariant.
 * ---------------------------------------------------------------------------------------------------------------- */
echo "D8-1 REVIEW F1/F2 -- THE CONSENT LEDGER UNDER CONCURRENCY\n";
echo str_repeat('=', 78) . "\n\n";

try {
    DB::connection('consent_boot')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('consent_boot')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server = DB::connection('consent_main')->select('SELECT VERSION() AS v, @@innodb_lock_wait_timeout AS lw')[0];
    // MySQL 8 calls it transaction_isolation, MariaDB 10 and MySQL 5.7 call it tx_isolation. Read whichever exists.
    $isolation = collect(DB::connection('consent_main')->select("SHOW VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation')"))->first();
    echo "server: {$server->v}   isolation: " . ($isolation->Value ?? '?') . "   innodb_lock_wait_timeout: {$server->lw}s\n";
} catch (Throwable $e) {
    echo 'Could not reach MySQL: ' . $e->getMessage() . "\n";
    echo "Set CONSENT_MYSQL_SOCKET (or CONSENT_MYSQL_HOST/PORT) to a throwaway server.\n";
    exit(3);
}

$migrations = [
    'database/migrations/2026_11_18_000100_create_marketing_consent_events_table.php',
    'database/migrations/2026_11_18_000200_create_marketing_contact_states_table.php',
    'database/migrations/2026_11_18_000300_create_marketing_unsubscribe_tokens_table.php',
];
Artisan::call('migrate', ['--database' => 'consent_main', '--path' => $migrations, '--force' => true]);

$checks = 0; $failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) { $failures++; }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $what, $detail === '' ? '' : "  -- {$detail}");
}

function hashOf(string $address): string
{
    return ContactAddress::hash(STORE, Channel::Email, ContactAddress::normalise(Channel::Email, $address));
}

/** The state the evidence implies: the log folded in id order. Written here, not taken from the ledger under test. */
function replayed(string $address): string
{
    $state = ConsentState::None;
    foreach (DB::table('marketing_consent_events')->where('address_hash', hashOf($address))->orderBy('id')->get() as $row) {
        $kind = ConsentEvent::tryFrom((string) $row->event);
        $state = $kind === null ? $state : $state->after($kind);
    }

    return $state->value;
}

function projected(string $address): string
{
    $row = DB::table('marketing_contact_states')->where('address_hash', hashOf($address))->first();

    return $row === null ? 'none' : (string) $row->status;
}

function logOf(string $address): string
{
    return DB::table('marketing_consent_events')->where('address_hash', hashOf($address))->orderBy('id')->get()
        ->map(fn ($r) => "{$r->id}:{$r->event}")->implode(' ');
}

function scratch(): string
{
    $dir = sys_get_temp_dir() . '/consent-verify-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);

    return $dir;
}

function spawn(array $args): array
{
    $cmd = array_merge([PHP_BINARY, __FILE__], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return ['proc' => $proc, 'pipes' => $pipes, 'out' => '', 'err' => ''];
}

/** @return array<string,mixed> the child's JSON line, or an error record */
function finish(array &$h, int $timeoutSeconds = 120): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (true) {
        $h['out'] .= (string) stream_get_contents($h['pipes'][1]);
        $h['err'] .= (string) stream_get_contents($h['pipes'][2]);
        $status = proc_get_status($h['proc']);
        if (!$status['running']) { break; }
        if (microtime(true) > $deadline) { proc_terminate($h['proc']); return ['ok' => false, 'error' => 'timed out']; }
        usleep(30000);
    }
    $h['out'] .= (string) stream_get_contents($h['pipes'][1]);
    $h['err'] .= (string) stream_get_contents($h['pipes'][2]);
    proc_close($h['proc']);
    $lines = array_filter(explode("\n", trim($h['out'])));
    $json = $lines ? json_decode(end($lines), true) : null;

    return is_array($json) ? $json : ['ok' => false, 'error' => 'no result: ' . substr(trim($h['err'] . $h['out']), 0, 200)];
}

function waitFor(string $file, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    while (!file_exists($file) && microtime(true) < $deadline) { usleep(20000); }

    return file_exists($file);
}

/* ---- S1 ------------------------------------------------------------------------------------------------------ */
echo "S1  an unsubscribe lands between another request's event INSERT and its projection update\n";
$addr = 's1@example.com';
$ledger = app(ConsentLedger::class);
$ledger->grant(STORE, Channel::Email, $addr, 'verify_setup');
check('setup: the contact is granted', projected($addr) === 'granted');

$dir = scratch();
$a = spawn(['--child=pause-grant', "--dir={$dir}", "--address={$addr}", '--name=A', '--other=B', '--wait-ms=4000']);
$paused = waitFor("{$dir}/A.paused", 20);
check('process A reached its pause (the evidence row is inserted, the transaction is open)', $paused);
$b = spawn(['--child=withdraw', "--dir={$dir}", "--address={$addr}", '--name=B']);
$rb = finish($b);
$ra = finish($a);
echo '        A: ' . json_encode($ra) . "\n        B: " . json_encode($rb) . "\n";
echo '        log (id:event): ' . logOf($addr) . "\n";
echo '        projection: ' . projected($addr) . '   replay of the log: ' . replayed($addr) . "\n";
check('both requests were accepted', ($ra['ok'] ?? false) && ($rb['ok'] ?? false));
check('THE INVARIANT: the projection the gate reads equals the log folded in id order', projected($addr) === replayed($addr),
    'projection=' . projected($addr) . ' replay=' . replayed($addr));

/* ---- S2 ------------------------------------------------------------------------------------------------------ */
echo "\nS2  two FIRST-EVER events for one contact, both inside the window\n";
$addr = 's2@example.com';
$dir = scratch();
$a = spawn(['--child=barrier-grant', "--dir={$dir}", "--address={$addr}", '--name=A', '--other=B', '--wait-ms=3000']);
$b = spawn(['--child=barrier-grant', "--dir={$dir}", "--address={$addr}", '--name=B', '--other=A', '--wait-ms=3000']);
$ra = finish($a); $rb = finish($b);
echo '        A: ' . json_encode($ra) . "\n        B: " . json_encode($rb) . "\n";
echo '        log (id:event): ' . logOf($addr) . "\n";
$rows = DB::table('marketing_contact_states')->where('address_hash', hashOf($addr))->count();
check('neither request failed', ($ra['ok'] ?? false) && ($rb['ok'] ?? false),
    implode(' | ', array_filter([$ra['error'] ?? null, $rb['error'] ?? null])));
check('exactly one projection row exists for the contact', $rows === 1, "rows={$rows}");
check('every accepted request left its event',
    DB::table('marketing_consent_events')->where('address_hash', hashOf($addr))->count() === (int) (($ra['ok'] ?? false) + ($rb['ok'] ?? false)));
check('THE INVARIANT holds', projected($addr) === replayed($addr), 'projection=' . projected($addr) . ' replay=' . replayed($addr));

/* ---- S3 ------------------------------------------------------------------------------------------------------ */
echo "\nS3  stress: six processes, seeded random events on one contact, no hooks\n";
$addr = 's3@example.com';
$dir = scratch();
$procs = [];
for ($i = 1; $i <= 6; $i++) {
    $procs[$i] = spawn(['--child=stress', "--dir={$dir}", "--address={$addr}", "--name=P{$i}", "--seed=" . (100 + $i), '--ops=12']);
}
$applied = 0; $errors = [];
foreach ($procs as $i => $h) {
    $r = finish($procs[$i]);
    $applied += (int) ($r['applied'] ?? 0);
    $errors = array_merge($errors, $r['errors'] ?? [$r['error'] ?? 'unknown']);
}
$events = DB::table('marketing_consent_events')->where('address_hash', hashOf($addr))->count();
echo "        accepted: {$applied}   events in the log: {$events}   errors surfaced: " . count($errors) . "\n";
foreach (array_slice(array_count_values($errors), 0, 4, true) as $msg => $n) { echo "          x{$n} {$msg}\n"; }
echo '        projection: ' . projected($addr) . '   replay: ' . replayed($addr) . "\n";
check('every accepted request left exactly one event', $events === $applied, "accepted={$applied} events={$events}");
check('THE INVARIANT holds after 72 concurrent events', projected($addr) === replayed($addr));
check('no request surfaced a database error', $errors === [], count($errors) . ' surfaced');

DB::connection('consent_boot')->statement("DROP DATABASE IF EXISTS `{$database}`");

echo "\n" . str_repeat('-', 78) . "\n";
echo "{$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
