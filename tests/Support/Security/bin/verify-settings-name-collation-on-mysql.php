<?php

/**
 * P1-SEC-27 (review) -- a SETTING NAME that differs from a restricted one only in case, accent or trailing space is the same
 * row on MySQL, verified on MySQL.
 *
 * Precondition (this script states its own, and does nothing without a server): a MySQL you control, reachable by
 * FRESH_MYSQL_* (or the default socket). It creates and drops its own database and refuses a name without 'fresh' or 'verify'.
 * NEVER point it at the development or production server.
 *
 *   FRESH_MYSQL_DATABASE=settingname_verify php tests/Support/Security/bin/verify-settings-name-collation-on-mysql.php
 *
 * THE FINDING. The settings writers (`/customize-settings`, `/theme-settings`, ...) do
 * `Setting::updateOrCreate(['name' => $postedName, 'store_id' => S, ...], ['value' => ..., 'name' => $postedName, ...])` for EVERY
 * posted name. StoreSettingsAccess refused a restricted name (`domains`, `bank_transfer`, `mail_host`, ...) by a case-sensitive
 * string comparison. If MySQL's collation (utf8mb4_unicode_ci, the project's default, config/database.php) makes `Domains` equal
 * `domains`, the writer UPDATES the merchant's real row and RENAMES it -- while the guard saw an unrestricted name.
 *
 * WHAT IT ESTABLISHES, by execution:
 *   1. on MySQL, `Domains`, `DOMAINS`, `domains ` (trailing space) and `dómains` (accent) all match the stored `domains` row, and
 *      the updateOrCreate shape the writers use changes that row's value AND its name;
 *   2. SQLite (the Feature suite's engine) matches none of them, which is why no test in that suite can show it;
 *   3. StoreSettingsAccess now classifies every such variant as restricted (case, trailing space) or refuses it outright (a name
 *      with a non-ASCII byte -- which MySQL's collation may fold onto an ASCII one) for a user without the Settings permission.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'settingname_verify';

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

echo "P1-SEC-27 -- settings names under MySQL's collation -- verified on MySQL\n" . str_repeat('-', 78) . "\n";

$variants = ['domains', 'Domains', 'DOMAINS', 'domains ', "d\u{00F3}mains", "dom\u{00AD}ains"];

try {
    $db = DB::connection('snv');
    $server = $db->select('SELECT VERSION() AS v, @@collation_connection AS c')[0];
    echo "  MySQL {$server->v}; connection collation = {$server->c}\n\n";

    // Exactly the shape of database/migrations/2023_12_11_100148_create_settings_table.php (no unique key).
    $db->statement('CREATE TABLE settings (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, '
        . 'value LONGTEXT NULL, created_by VARCHAR(255) NOT NULL, store_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, KEY (store_id)) '
        . 'DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci');

    echo "  1. which spellings does `WHERE name = ?` treat as the stored name `domains`?\n";
    $db->insert("INSERT INTO settings (name, value, created_by, store_id) VALUES ('domains', 'merchant.example', '77', 7)");
    foreach ($variants as $v) {
        $row = $db->table('settings')->where('name', $v)->where('store_id', 7)->first();
        printf("         name = %-14s -> %s\n", json_encode($v, JSON_UNESCAPED_UNICODE), $row ? 'MATCHES the stored row' : 'no row');
    }
    foreach (['Domains', 'DOMAINS', 'domains '] as $v) {
        $out("MySQL matches " . json_encode($v) . ' to the stored `domains` row',
            $db->table('settings')->where('name', $v)->where('store_id', 7)->exists());
    }

    echo "\n  2. the writers' updateOrCreate shape, posting `Domains` for a store whose real row is `domains`\n";
    $model = (new App\Models\Setting())->setConnection('snv');
    $model->newQuery()->updateOrCreate(
        ['name' => 'Domains', 'store_id' => 7, 'created_by' => 77],
        ['value' => 'attacker.example', 'name' => 'Domains', 'store_id' => 7, 'created_by' => 77],
    );
    $rows = $db->table('settings')->where('store_id', 7)->get();
    $out('no second row was created -- the merchant row itself was rewritten', $rows->count() === 1, 'rows: ' . $rows->count());
    $row = $rows->first();
    $out('the stored value is now the attacker\'s', $row && $row->value === 'attacker.example');
    $out('the stored NAME was rewritten to the posted spelling', $row && $row->name === 'Domains', 'name = ' . ($row->name ?? 'n/a'));
    $found = $db->table('settings')->where('name', 'domains')->where('value', 'attacker.example')->exists();
    $out('the host lookup `where name = domains and value = <host>` (HomeController, helper.php) resolves the attacker host', $found);

    echo "\n  3. the fix: StoreSettingsAccess classifies every spelling\n";
    $posting = ['mail_host', 'MAIL_HOST', 'Mail_Host ', 'Domains', 'DOMAINS', 'domains ', 'Bank_Transfer', 'Is_Cod_Enabled', 'ENABLE_DOMAIN', 'Subdomain'];
    foreach ($posting as $name) {
        $out('isRestrictedName(' . json_encode($name) . ')', App\Services\StoreSettingsAccess::isRestrictedName($name));
    }
    // A staff member of tenant 77 who holds no permission. isAbleTo() is answered here so this script needs no roles tables.
    $user = new class extends App\Models\User {
        public function isAbleTo(string|array|BackedEnum $permission, mixed $team = null, bool $requireAll = false): bool
        {
            return false;
        }
    };
    $user->id = 5;
    $user->type = 'staff';
    $user->created_by = 77;
    foreach (["d\u{00F3}mains", "dom\u{00AD}ains", "bank_transf\u{00E9}r"] as $name) {
        $out('a non-ASCII name ' . json_encode($name, JSON_UNESCAPED_UNICODE) . ' is refused outright for a user without the permission',
            App\Services\StoreSettingsAccess::deniesRestrictedWrite([$name => ''], $user, 7));
    }
} finally {
    DB::connection('snv_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
}

echo "\nSQLite (the Feature suite's engine):\n";
$lite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lite->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, name TEXT, store_id INTEGER)');
$lite->exec("INSERT INTO settings (name, store_id) VALUES ('domains', 7)");
foreach (['Domains', 'DOMAINS', 'domains '] as $v) {
    $st = $lite->prepare('SELECT id FROM settings WHERE name = ? AND store_id = 7');
    $st->execute([$v]);
    $out("SQLite does NOT match " . json_encode($v) . ' -- the suite is blind to it', $st->fetch(PDO::FETCH_ASSOC) === false);
}

exit($failed ? 1 : 0);
