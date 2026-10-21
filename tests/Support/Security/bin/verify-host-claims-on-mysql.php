<?php

/**
 * P1-SEC-29 (review) -- the host-claim write hook against MySQL's collation, verified on MySQL.
 *
 * Precondition (this script states its own, and does nothing without a server): a MySQL you control, reachable by
 * FRESH_MYSQL_* (or the default socket). It creates and drops its own database and refuses a name without 'fresh' or 'verify'.
 * NEVER point it at the development or production server.
 *
 *   FRESH_MYSQL_DATABASE=hostclaims_verify php tests/Support/Security/bin/verify-host-claims-on-mysql.php
 *
 * THE FINDING (a read-only review of the first P1-SEC-29 fix). HostClaims::assertFree, the `saving` hook of the Setting model,
 * decided WHICH setting a write was by comparing the posted name in PHP (`strtolower(trim($name)) === 'domains'`), and whether a flag
 * was being switched on by comparing its value in PHP (`=== 'on'`). The lookups the storefront answers a request with compare in
 * SQL, under utf8mb4_unicode_ci -- which is case-, accent- and PAD-SPACE-insensitive, and ignores some code points altogether. So
 * on MySQL `dómains` IS the stored `domains` row, `enablé_domain` IS the flag, and a flag value of `ón` IS 'on': the owner of an
 * older store could post them to /customize-settings, the writer's `updateOrCreate` would rewrite the real rows, the hook would see
 * names it did not know, and the original takeover was open again. SQLite compares bytes, so no Feature test can show it.
 *
 * WHAT IT ESTABLISHES, by execution:
 *   1. which spellings MySQL folds onto the four names and onto 'on' (printed; printable ASCII is checked exhaustively, because the
 *      fix keeps a PHP fast path for a plain ASCII name and that is sound only if ASCII folds by case alone);
 *   2. each bypass, through the writers' own updateOrCreate shape, is refused: an accented host-name, an accented flag name, an
 *      accented flag VALUE, the same names on a store that has no such row yet (an INSERT), and an accented host VALUE;
 *   3. the control is not over-broad: a free host is saved, an unchanged re-save is not a claim, and the row the lookup returns is
 *      still the victim's after every attempt.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use App\Services\HostClaims;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'hostclaims_verify';

if (! str_contains($database, 'fresh') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] contains neither 'fresh' nor 'verify'.\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            break;
        }
    }
}

$base = [
    'driver' => 'mysql',
    'host' => getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('FRESH_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('FRESH_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('FRESH_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',   // config/database.php's default, which production does not override
    'prefix' => '',
    'strict' => false,                      // production's setting
];
$bootstrap = $base;
$bootstrap['database'] = null;
config(['database.connections.snv_bootstrap' => $bootstrap]);
config(['database.connections.snv' => $base]);
config(['cache.default' => 'array']);

$failed = false;
$out = function (string $label, bool $ok, string $detail = '') use (&$failed): void {
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail === '' ? '' : "\n         " . $detail);
    $failed = $failed || ! $ok;
};

try {
    DB::connection('snv_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('snv_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "No MySQL reachable / could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

echo "P1-SEC-29 -- the host-claim hook under MySQL's collation -- verified on MySQL\n" . str_repeat('-', 78) . "\n";

try {
    // HostClaims and the Setting model use the DEFAULT connection, as they do in production.
    config(['database.default' => 'snv']);
    DB::purge('snv');
    DB::setDefaultConnection('snv');
    $db = DB::connection('snv');
    $server = $db->select('SELECT VERSION() AS v, @@collation_connection AS c')[0];
    echo "  MySQL {$server->v}; connection collation = {$server->c}\n\n";

    // Exactly the shape of database/migrations/2023_12_11_100148_create_settings_table.php (no unique key).
    $db->statement('CREATE TABLE settings (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, '
        . 'value LONGTEXT NULL, created_by VARCHAR(255) NOT NULL, store_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, KEY (store_id)) '
        . 'DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $victimHost = 'victim-shop.example.test';
    $put = function (string $name, string $value, int $store) use ($db): void {
        $db->table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => (string) $store, 'store_id' => $store]);
    };
    $flush = function (): void {
        (new Setting())->flushCache();
    };

    // Store 1 is the OLDER merchant (the attacker): lower row ids than the victim's. Store 2 is the victim and is serving $victimHost.
    $put('domains', 'older-shop.example.test', 1);
    $put('enable_domain', 'on', 1);
    $put('domains', $victimHost, 2);
    $put('enable_domain', 'on', 2);

    echo "  1. what does the collation fold onto the names and onto 'on'?\n";
    $folds = fn (string $probe, string $target): bool => (bool) $db->selectOne('SELECT (? = ?) AS eq', [$probe, $target])->eq;
    foreach (["d\u{00F3}mains", "dom\u{00AD}ains", 'DOMAINS', 'domains ', "enabl\u{00E9}_domain", "\u{00F3}n"] as $probe) {
        $target = str_starts_with($probe, 'enabl') ? 'enable_domain' : (str_contains($probe, 'n') && mb_strlen($probe) === 2 ? 'on' : 'domains');
        printf("         %-18s = %-14s -> %s\n", json_encode($probe, JSON_UNESCAPED_UNICODE), $target, $folds($probe, $target) ? 'FOLDS onto it' : 'differs');
    }
    $out("MySQL treats an accented name as the stored name", $folds("d\u{00F3}mains", 'domains'));
    $out("MySQL treats an accented flag value as 'on'", $folds("\u{00F3}n", 'on'));

    // The PHP fast path in HostClaims treats a plain printable-ASCII name as exactly its lower-cased self. That is sound only if no
    // printable ASCII character, inserted anywhere in a name, folds it onto another: measure every one, at every position.
    $targets = ['domains', 'subdomain', 'enable_domain', 'enable_subdomain', 'on'];
    $asciiFolds = [];
    $controlFolds = [];
    for ($byte = 0x00; $byte <= 0x7F; $byte++) {
        $c = chr($byte);
        foreach ($targets as $t) {
            for ($pos = 0; $pos <= strlen($t); $pos++) {
                $probe = substr($t, 0, $pos) . $c . substr($t, $pos);
                if ($byte >= 0x21 && $byte <= 0x7E) {
                    // a case variant of the target itself is expected; anything else folding is a hole in the fast path
                    if ($folds($probe, $t) && strtolower($probe) !== $t) {
                        $asciiFolds[] = json_encode($probe);
                    }
                } elseif ($folds($probe, $t)) {
                    $controlFolds[$byte] = true;
                }
            }
        }
    }
    $out('no printable ASCII character, inserted at any position of any of the five strings, folds it onto the target (the PHP fast path is sound)',
        $asciiFolds === [], implode(' ', array_slice($asciiFolds, 0, 8)));
    printf("         control bytes that DO fold (so a name holding one must go to the engine): %s\n",
        $controlFolds === [] ? 'none' : implode(' ', array_map(fn ($b) => sprintf('0x%02X', $b), array_keys($controlFolds))));

    echo "\n  2. the writers' updateOrCreate shape, posted by the OWNER of store 1 (who holds Manage Setting)\n";
    $writer = function (string $name, string $value, int $store) use ($flush): ?string {
        $flush();
        try {
            Setting::query()->updateOrCreate(
                ['name' => $name, 'store_id' => $store],
                ['value' => $value, 'name' => $name, 'store_id' => $store, 'created_by' => $store],
            );
        } catch (ValidationException $e) {
            return implode(' ', array_merge(...array_values($e->errors())));
        }
        $flush();

        return null;
    };
    $snapshot = fn (int $store) => $db->table('settings')->where('store_id', $store)->orderBy('id')->get(['id', 'name', 'value'])->map(fn ($r) => "{$r->id}:{$r->name}={$r->value}")->implode(' | ');

    // baseline: the ASCII spelling is refused (this is what P1-SEC-29 shipped)
    $before = $snapshot(1);
    $out('baseline: `domains` = the victim\'s host is refused', $writer('domains', $victimHost, 1) !== null);
    $out('baseline: ...and wrote nothing', $snapshot(1) === $before, $snapshot(1));

    foreach ([
        ['an accented host-name', "d\u{00F3}mains", $victimHost],
        ['a soft-hyphen host-name', "dom\u{00AD}ains", $victimHost],
        ['a trailing-space host-name', 'domains ', $victimHost],
        ['an upper-case host-name', 'DOMAINS', $victimHost],
        ['an accented host VALUE', 'domains', "v\u{00ED}ctim-shop.example.test"],
        ['an accented sub-domain name', "subd\u{00F3}main", $victimHost],
        ['a control byte inside the name', "dom\x01ains", $victimHost],
    ] as [$label, $name, $value]) {
        $before = $snapshot(1);
        $refusal = $writer($name, $value, 1);
        // A spelling this server does not fold is just another name: it is saved, and the assertion that matters is the last one of the
        // section (the lookup is still the victim's). A spelling it DOES fold must be refused.
        $mustRefuse = $name === 'domains' || $folds($name, 'domains') || $folds($name, 'subdomain');
        if ($mustRefuse) {
            $out("{$label} ({$name} = {$value}) is refused", $refusal !== null, $refusal === null ? 'NOT REFUSED; store 1 now: ' . $snapshot(1) : '');
            $out('   ...and store 1\'s rows are unchanged', $snapshot(1) === $before, $snapshot(1));
        } else {
            $out("{$label} ({$name} = {$value}) is not a fold on this server: saved as an ordinary name", $refusal === null);
        }
    }

    // a store that has NO such row yet: the write is an INSERT of a row whose name only the collation reads as `domains`
    $before = $snapshot(3);
    $refusal = $writer("d\u{00F3}mains", $victimHost, 3);
    $out('an accented host-name on a store with no `domains` row yet (an INSERT) is refused', $refusal !== null, $refusal === null ? 'NOT REFUSED; store 3 now: ' . $snapshot(3) : '');
    $out('   ...and wrote nothing', $snapshot(3) === $before, $snapshot(3));

    // the flag: store 4 staged the victim's host before the victim claimed it (a raw insert stands in for that earlier write)
    $put('domains', $victimHost, 4);
    $put('enable_domain', 'off', 4);
    foreach ([
        ['the flag, ASCII', 'enable_domain', 'on'],
        ['the flag with an accented value', 'enable_domain', "\u{00F3}n"],
        ['an accented flag name', "enabl\u{00E9}_domain", 'on'],
        ['an accented flag name AND value', "enabl\u{00E9}_domain", "\u{00F3}n"],
        ['an upper-case flag value', 'enable_domain', 'ON'],
    ] as [$label, $name, $value]) {
        $before = $snapshot(4);
        $refusal = $writer($name, $value, 4);
        $out("switching on with {$label} ({$name} = {$value}) is refused", $refusal !== null, $refusal === null ? 'NOT REFUSED; store 4 now: ' . $snapshot(4) : '');
        $out('   ...and the flag stays off', $snapshot(4) === $before, $snapshot(4));
    }

    echo "\n  3. what must NOT be refused\n";
    $out('a free host is saved (an accented name, which rewrites the owner\'s own row as the writers do)', $writer("d\u{00F3}mains", 'a-free-host.example.test', 5) === null && $db->table('settings')->where('store_id', 5)->count() === 1);
    $same = $db->table('settings')->where('store_id', 2)->where('name', 'domains')->first();
    $out('the victim re-saving its own unchanged host is not a claim', $writer('domains', $victimHost, 2) === null && $db->table('settings')->where('id', $same->id)->value('value') === $victimHost);
    $out('the victim re-saving its own flag as it is is not a claim', $writer('enable_domain', 'on', 2) === null);
    $out('an unrelated name is saved', $writer('theme_color', '#fff', 1) === null);
    $out('an unrelated name with an accent is saved', $writer("th\u{00E8}me_color", '#000', 1) === null);

    echo "\n  4. what the lookup now answers\n";
    $flush();
    $row = HostClaims::row('domains', $victimHost);
    $out('the host lookup still resolves the victim host to store 2', $row && (int) $row->store_id === 2, $row ? 'store ' . $row->store_id : 'no row');
    $out('heldByAnotherStore sees the victim\'s host under an accent in the value', HostClaims::heldByAnotherStore("v\u{00ED}ctim-shop.example.test", 1));
} finally {
    DB::connection('snv_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
}

exit($failed ? 1 : 0);
