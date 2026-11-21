<?php

/**
 * P1-SEC-78 -- the write-side rule for the sign-in image rows against MySQL's collation, verified on a server.
 *
 * Precondition (this script states its own, and does nothing without a server): a MySQL or MariaDB you control, reachable by FRESH_MYSQL_* (or the default
 * socket). It creates and drops its own database and refuses a name without 'fresh' or 'verify'. NEVER point it at the development or production server.
 *
 *   FRESH_MYSQL_DATABASE=signinname_writer_verify FRESH_MYSQL_SOCKET=/path/to/mysqld.sock php tests/Support/Security/bin/verify-setting-name-writer-on-mysql.php
 *
 * TWO COLLATIONS, BECAUSE THEY CAN DIFFER (the review of the first form). The vendored lookup compares under the COLUMN's collation; a question asked from PHP with only parameters
 * is asked under the CONNECTION's. Production is documented as MariaDB 11.4.9 with a mixed estate (`audit_logs` is utf8mb4_general_ci, the application default utf8mb4_unicode_ci), so
 * the script takes both: FRESH_COLUMN_COLLATION (the `settings.name` column and the probe table; default utf8mb4_unicode_ci) and FRESH_CONNECTION_COLLATION (default
 * utf8mb4_unicode_ci). Run it with them DIFFERENT -- tests/Support/Security/bin/run-mysql-verifications.sh runs it four times -- and it is red against a rule that asks the connection.
 *
 * THE FINDING (the skeptics of P1-SEC-70). StoredPathPolicy::setting(), the `saving` hook of the Setting model, held the value of a row named `*_sign_in_image` to the
 * upload-path rule -- because two vendored handlers `File::delete(base_path($row->value))` on the row they find by NAME -- and decided "is this one of those rows" with
 * `preg_match('/_sign_in_image$/', strtolower(trim($name)))`, in PHP, on the name AS POSTED. The handlers' lookups compare in SQL, under utf8mb4_unicode_ci, and the column keeps
 * the name AS STORED: so a spelling PHP does not see (an accent in the suffix, a trailing zero-width or no-break space, a full-width letter, a name the 255-character column
 * cuts, a name that ends at an invalid UTF-8 byte) reaches the table, is found by the lookup, and its value was never judged. SQLite compares bytes, so no Feature test can
 * show the fold itself; tests/Feature/Security/SettingNameAtTheWriterTest gives its connection a stand-in `lower()`. THIS script is the real engine.
 *
 * WHAT IT ESTABLISHES, by execution, on the server it runs against (one server, one collation, measured -- a different one has to be measured again):
 *   1. which spellings the collation folds onto the two sign-in names, and which the column cuts onto them (printed, and the ones the finding rests on asserted);
 *   2. that the OLD rule (reproduced here, as the code was) never judged the value of any spelling in the suffix-folded and cut classes;
 *   3. that the hook, through the model, now refuses a poisoned value under every one of them, on create and on rename, and that the lookup the vendored handler runs
 *      finds no planted row afterwards;
 *   4. the controls: the same spellings with a path in an upload folder are saved, a non-sign-in accented name is saved, a name the column cuts onto a non-sign-in name is saved;
 *   5. a DIFFERENTIAL over about two hundred thousand candidate names -- every byte appended, prepended and inserted at two interior positions of each name; every code point from U+0080 to U+FFFF appended; a
 *      long list of letter lookalikes substituted at every position of both names -- asking the ENGINE which of them a vendored lookup would find once stored (ground truth,
 *      through a table with the column's own definition, so the cut and the collation are the real ones) and then asking the MODEL whether a poisoned value under that name
 *      is refused. Soundness is: every name the engine finds is refused. The count of the names the hook let through is the headline, and it is the same script that
 *      printed a non-zero number on the code before this change;
 *   6. the owner's read-only query in docs/security/P01-SECURITY-WORK-QUEUE.md (P1-SEC-70 f, item 4), extracted from the document as written and executed, lists the planted
 *      rows of every kind and flags exactly them.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'signinname_writer_verify';
$columnCollation = getenv('FRESH_COLUMN_COLLATION') ?: 'utf8mb4_unicode_ci';
$connectionCollation = getenv('FRESH_CONNECTION_COLLATION') ?: 'utf8mb4_unicode_ci';

foreach ([$columnCollation, $connectionCollation] as $collation) {
    if (preg_match('/\\Autf8mb4_[a-z0-9_]+\\z/', $collation) !== 1) {
        fwrite(STDERR, "REFUSING: [{$collation}] is not a utf8mb4 collation name.\n");
        exit(2);
    }
}

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
    'collation' => $connectionCollation,    // config/database.php's default is utf8mb4_unicode_ci; production's is not known
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

echo "P1-SEC-78 -- the sign-in image write rule under the collation -- verified on a server\n" . str_repeat('-', 78) . "\n";

const POISON = '../../.env';
const PLAIN = ['facebook_sign_in_image', 'linkedin_sign_in_image'];

try {
    // the model and the hook use the DEFAULT connection, as they do in production
    config(['database.default' => 'snv']);
    DB::purge('snv');
    DB::setDefaultConnection('snv');
    $db = DB::connection('snv');
    $server = $db->select('SELECT VERSION() AS v, @@collation_connection AS c')[0];
    echo "  server {$server->v}; connection collation = {$server->c}\n";

    // exactly the shape of database/migrations/2023_12_11_100148_create_settings_table.php (no unique key)
    $db->statement('CREATE TABLE settings (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, '
        . 'value LONGTEXT NULL, created_by VARCHAR(255) NOT NULL, store_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, KEY (store_id)) '
        . "DEFAULT CHARSET utf8mb4 COLLATE {$columnCollation}");
    // the same column, to ask the engine what a stored name is equal to without touching `settings`
    $db->statement("CREATE TABLE name_probe (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) DEFAULT CHARSET utf8mb4 COLLATE {$columnCollation}");
    $column = $db->selectOne("SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'name'", [$database]);
    echo "  settings.name collation = {$column->c}\n";
    // the first form of the rule (before the review) has no nameColumn(): the script still runs against it, which is how the divergence was measured
    $read = method_exists(App\Services\StoredPathPolicy::class, 'nameColumn') ? App\Services\StoredPathPolicy::nameColumn() : null;
    if ($read === null) {
        echo "  the rule under test does not read the live column: it asks under the connection's collation\n\n";
    } else {
        echo "  what the rule reads from the engine: collation = " . ($read['collation'] ?? 'none') . ", width = {$read['length']}\n\n";
        $out('the rule read the live column (its collation and width), not the connection\'s', $read['collation'] === $column->c && $read['length'] === 255, json_encode($read));
    }

    $label = fn (string $name): string => json_encode($name, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: bin2hex($name);
    $short = fn (string $name): string => mb_strlen($label($name)) > 60 ? mb_substr($label($name), 0, 57) . '...' : $label($name);

    /** The old rule, as the code was before P1-SEC-78: the name AS POSTED, in PHP. */
    $oldRuleJudges = fn (string $name): bool => preg_match('/_sign_in_image$/', strtolower(trim($name))) === 1;

    /** Would a vendored lookup (`where name = 'facebook_sign_in_image'` / `'linkedin_sign_in_image'`) find a row stored under this posted name? Ground truth: store it, ask. */
    $found = function (array $names) use ($db): array {
        $db->statement('DELETE FROM name_probe');
        $rows = [];
        foreach ($names as $i => $name) {
            $rows[] = ['id' => $i + 1, 'name' => $name];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table('name_probe')->insert($chunk);   // non-strict: the column cuts at 255 characters and at the first invalid byte, as production's does
        }
        $ids = $db->table('name_probe')->whereRaw("name = 'facebook_sign_in_image' or name = 'linkedin_sign_in_image'")->pluck('id')->all();

        return array_flip(array_map(fn ($id) => (int) $id - 1, $ids));
    };

    $createPoisoned = function (string $name) use ($db): bool {
        try {
            Setting::create(['name' => $name, 'value' => POISON, 'store_id' => 1, 'created_by' => '1']);

            return true;   // written
        } catch (ValidationException $e) {
            if (! array_key_exists('value', $e->errors())) {
                throw new RuntimeException('A refusal that is not the path rule\'s: ' . json_encode($e->errors()));
            }

            return false;
        }
    };
    $wipe = fn () => $db->statement('DELETE FROM settings');

    // ------------------------------------------------------------------------------------------------------------------------------------------
    echo "  1. which spellings does this server store as, or fold onto, a sign-in name?\n";
    $classes = [
        'an accent in the suffix (é)'               => ["facebook_sign_in_imag\u{00E9}", true],
        'a trailing zero-width space'               => ["facebook_sign_in_image\u{200B}", true],
        'a trailing no-break space'                 => ["facebook_sign_in_image\u{00A0}", true],
        'a full-width i in the suffix'              => ["facebook_sign_\u{FF49}n_image", true],
        'a trailing combining acute accent'         => ["facebook_sign_in_image\u{0301}", true],
        'a trailing DEL'                            => ["facebook_sign_in_image\x7F", true],
        'a trailing NUL'                            => ["facebook_sign_in_image\x00", true],
        'an invalid UTF-8 byte, then more'          => ["facebook_sign_in_image\xFFx", null],   // MySQL 8.0 stores `facebook_sign_in_image` (verify-host-claims-on-mysql.php); MariaDB stores a `?` and the rest
        'a name padded past the column'             => ['facebook_sign_in_image' . str_repeat(' ', 300) . 'x', true],
        'the Kelvin sign in the PREFIX'             => ["lin\u{212A}edin_sign_in_image", true],
        'an interior control byte in the PREFIX'    => ["facebook\x01_sign_in_image", true],
        'upper case'                                => ['FACEBOOK_SIGN_IN_IMAGE', true],
        'a soft hyphen (not folded on MariaDB)'     => ["facebook_sign_in_image\u{00AD}", null],
        'a trailing tab'                            => ["facebook_sign_in_image\t", null],
        'a prefix that is not a provider, no fold'  => ["thème_color", false],
        'an interior no-break space, then x'        => ["facebook_sign_in_image\u{00A0}x", false],
    ];
    $names = array_map(fn ($c) => $c[0], array_values($classes));
    $hit = $found($names);
    $i = 0;
    $engineSays = [];
    foreach ($classes as $what => [$name, $expected]) {
        $engineSays[$what] = isset($hit[$i]);
        printf("         %-44s %-34s %s\n", $what, $short($name), $engineSays[$what] ? 'FOUND by a vendored lookup' : 'not found');
        $i++;
    }
    // the expectations below were measured on utf8mb4_unicode_ci (the project's collation); under any other column collation the table above is the measurement and nothing is asserted from it
    $measuredFor = $columnCollation === 'utf8mb4_unicode_ci';
    foreach ($classes as $what => [$name, $expected]) {
        if ($expected !== null && $measuredFor) {
            $out("premise: {$what} " . ($expected ? 'IS' : 'is NOT') . ' found by the lookup', $engineSays[$what] === $expected);
        }
    }
    // The one class whose behaviour is the SERVER'S: a MySQL 8.0 column ends the stored name at the first byte that is not valid UTF-8 (measured on 8.0.46 by
    // verify-host-claims-on-mysql.php), a MariaDB one keeps the rest and puts a `?` there. The rule models the first (it is the conservative one: on MariaDB it judges
    // a value it need not); the differential below measures whichever server this is.
    $serverCuts = $engineSays['an invalid UTF-8 byte, then more'];
    echo '         this server ' . ($serverCuts ? 'CUTS a name at an invalid UTF-8 byte (as MySQL 8.0 does)' : "does NOT cut at an invalid UTF-8 byte (as MariaDB: it stores a '?')") . "\n";

    // ------------------------------------------------------------------------------------------------------------------------------------------
    echo "\n  2. what did the OLD rule judge?\n";
    $blind = [];
    foreach ($classes as $what => [$name, $expected]) {
        if ($engineSays[$what] && ! $oldRuleJudges($name)) {
            $blind[] = $what;
        }
    }
    foreach ($blind as $what) {
        echo "         the old rule never judged the value of: {$what}\n";
    }
    $out('the old rule was blind to the suffix-folded and the cut spellings (the finding)', count($blind) >= ($measuredFor ? 6 : 0), count($blind) . ' of the spellings the engine finds were never judged');

    // ------------------------------------------------------------------------------------------------------------------------------------------
    echo "\n  3. through the model: is a poisoned value refused under each of them?\n";
    $wipe();
    $writtenBy = [];
    foreach ($classes as $what => [$name, $expected]) {
        if ($engineSays[$what]) {
            $writtenBy[$what] = $createPoisoned($name);
        }
    }
    $planted = array_keys(array_filter($writtenBy));
    foreach ($planted as $what) {
        echo "         WRITTEN with a poisoned value: {$what}\n";
    }
    $out('no spelling the engine finds was written with a poisoned value', $planted === [], count($planted) . ' were');
    $lookup = $db->selectOne("select count(*) as c from settings where name = 'facebook_sign_in_image' or name = 'linkedin_sign_in_image'");
    $out("the lookup a vendored handler runs finds no planted row", (int) $lookup->c === 0, "{$lookup->c} rows");

    // rename: the writer's second argument carries the name, and the engine finds the existing row by the fold
    $wipe();
    $legit = Setting::create(['name' => 'facebook_sign_in_image', 'value' => 'uploads/1/sign-in.png', 'store_id' => 1, 'created_by' => '1']);
    $renamed = [];
    foreach ($classes as $what => [$name, $expected]) {
        if (! $engineSays[$what]) {
            continue;
        }
        // exactly the call /seo-setting makes: the posted name is the match key AND is written again by the second argument
        try {
            Setting::updateOrCreate(['name' => $name, 'store_id' => 1], ['value' => POISON, 'name' => $name, 'store_id' => 1, 'created_by' => '1']);
            $renamed[] = $what;
            $db->table('settings')->where('id', $legit->id)->update(['name' => 'facebook_sign_in_image', 'value' => 'uploads/1/sign-in.png']);
            $db->table('settings')->where('id', '<>', $legit->id)->delete();
            (new Setting())->flushCache();
        } catch (ValidationException $e) {
            if (! array_key_exists('value', $e->errors())) {
                throw new RuntimeException('A refusal that is not the path rule\'s: ' . json_encode($e->errors()));
            }
        }
    }
    (new Setting())->flushCache();
    $out('no spelling the engine finds could rename the honest row and take a poisoned value', $renamed === [], implode('; ', $renamed));
    $row = $db->table('settings')->where('id', $legit->id)->first();
    $out('the honest row is untouched', $row->name === 'facebook_sign_in_image' && $row->value === 'uploads/1/sign-in.png');

    // ------------------------------------------------------------------------------------------------------------------------------------------
    echo "\n  4. controls\n";
    $wipe();
    $controlsFailed = [];
    foreach ($classes as $what => [$name, $expected]) {
        if (! $engineSays[$what]) {
            continue;
        }
        try {
            Setting::create(['name' => $name, 'value' => 'uploads/1/own.png', 'store_id' => 1, 'created_by' => '1']);
        } catch (ValidationException $e) {
            $controlsFailed[] = $what;
        }
    }
    $out('every one of those spellings is still saved with a path in an upload folder', $controlsFailed === [], implode('; ', $controlsFailed));
    $wipe();
    $other = [];
    foreach (["th\u{00E8}me_color", "facebook_sign_in_image\u{00A0}x", str_repeat('n', 300), 'Some Setting Name', 'facebook_sign_in_image_extra'] as $name) {
        try {
            Setting::create(['name' => $name, 'value' => POISON, 'store_id' => 1, 'created_by' => '1']);
        } catch (ValidationException $e) {
            $other[] = $short($name);
        }
    }
    $out('names that are no sign-in name are saved with any value, as before', $other === [], implode('; ', $other));
    $longest = (int) $db->selectOne('select max(char_length(name)) as m from settings')->m;
    $out('the column cut the long ordinary name to 255 characters and the hook did not mind', $longest === 255, "longest stored name = {$longest}");

    // ------------------------------------------------------------------------------------------------------------------------------------------
    echo "\n  5. differential: is every candidate name the ENGINE finds refused by the hook?\n";
    $candidates = [];
    $ascii = array_map('chr', range(0x00, 0xFF));
    foreach (PLAIN as $plain) {
        $cut = strpos($plain, '_');
        foreach ($ascii as $byte) {
            $candidates[] = $plain . $byte;
            $candidates[] = $byte . $plain;
            $candidates[] = substr($plain, 0, $cut) . $byte . substr($plain, $cut);
            $candidates[] = substr($plain, 0, $cut + 5) . $byte . substr($plain, $cut + 5);
        }
        for ($cp = 0x80; $cp <= 0xFFFF; $cp++) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                continue;
            }
            $candidates[] = $plain . mb_chr($cp, 'UTF-8');
        }
    }
    // lookalikes substituted at every position: Latin-1, Latin Extended A/B and Additional, letterlike symbols, full-width letters, the mathematical alphanumerics
    $lookalikes = [];
    foreach ([[0xC0, 0x24F], [0x1E00, 0x1EFF], [0x2100, 0x214F], [0xFF21, 0xFF3A], [0xFF41, 0xFF5A], [0x1D400, 0x1D7FF]] as [$from, $to]) {
        for ($cp = $from; $cp <= $to; $cp++) {
            $lookalikes[] = mb_chr($cp, 'UTF-8');
        }
    }
    foreach (PLAIN as $plain) {
        foreach (str_split($plain) as $position => $letter) {
            if (! ctype_alpha($letter)) {
                continue;
            }
            foreach ($lookalikes as $glyph) {
                $candidates[] = substr($plain, 0, $position) . $glyph . substr($plain, $position + 1);
            }
        }
    }
    $candidates = array_values(array_unique($candidates));
    $started = microtime(true);
    $foundAll = [];
    foreach (array_chunk($candidates, 20000, true) as $offset => $chunk) {
        $chunk = array_values($chunk);
        foreach ($found($chunk) as $index => $_) {
            $foundAll[] = $chunk[$index];
        }
    }
    printf("         %d candidate names; the engine finds %d of them once stored (%.0fs)\n", count($candidates), count($foundAll), microtime(true) - $started);

    $started = microtime(true);
    $letThrough = [];
    $refused = 0;
    foreach ($foundAll as $name) {
        $wipe();
        if ($createPoisoned($name)) {
            $letThrough[] = $name;
        } else {
            $refused++;
        }
    }
    $wipe();
    printf("         of the %d the engine finds, the hook refused %d and let %d through (%.0fs)\n", count($foundAll), $refused, count($letThrough), microtime(true) - $started);
    foreach (array_slice($letThrough, 0, 8) as $name) {
        echo "           let through: {$short($name)}  (hex " . substr(bin2hex($name), 0, 80) . ")\n";
    }
    $out('no name the engine finds was let through with a poisoned value', $letThrough === [], count($letThrough) . ' were');
    // a binary column folds nothing: it finds only the names themselves, and that is a measurement, not a vacuous probe
    $out('the probe was not vacuous: the engine found a real number of the candidates', count($foundAll) > (str_ends_with($columnCollation, '_bin') ? 0 : 1000), count($foundAll) . ' found');

    // ------------------------------------------------------------------------------------------------------------------------------------------
    echo "\n  6. the owner's query in the work queue, extracted as written and executed\n";
    $doc = file_get_contents(__DIR__ . '/../../../../docs/security/P01-SECURITY-WORK-QUEUE.md');
    $queryFound = preg_match("/`(SELECT id, name, HEX\\(name\\) AS name_hex, store_id, created_by, value FROM settings WHERE name = 'facebook_sign_in_image' OR name = 'linkedin_sign_in_image' ORDER BY id;)`/", $doc, $m) === 1;
    $out('the query is in the document', $queryFound);
    if ($queryFound && ! in_array($columnCollation, ['utf8mb4_unicode_ci', 'utf8mb4_uca1400_ai_ci'], true)) {
        echo "         skipped: the query in the document is written for the UCA collation the project names, and this column is {$columnCollation}\n";
    } elseif ($queryFound) {
        $wipe();
        $seed = fn (string $name, string $value, int $store) => $db->table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => '1', 'store_id' => $store]);
        $seed('facebook_sign_in_image', 'uploads/1/honest.png', 1);                          // honest
        $seed("facebook_sign_in_imag\u{00E9}", '../../.env', 2);                             // planted under a folded spelling
        $seed("facebook_sign_in_image\u{200B}", 'uploads/1/logo.png', 2);                    // planted, naming another tenant's file
        if ($serverCuts) {
            $seed("facebook_sign_in_image\xFFx", '../../.env', 3);                           // planted, stored cut: byte-identical to the honest name
        }
        $seed('linkedin_sign_in_image', 'uploads/4/honest.png', 4);                          // honest
        $seed('linkedin_sign_in_image' . str_repeat(' ', 300) . 'x', 'config/app.php', 5);   // planted, cut to the name plus padding
        $seed('metakeyword', 'k', 5);                                                        // unrelated
        $rows = $db->select($m[1]);
        $plainHex = ['66616365626F6F6B5F7369676E5F696E5F696D616765', '6C696E6B6564696E5F7369676E5F696E5F696D616765'];
        $flagged = [];
        foreach ($rows as $row) {
            $nameOk = in_array(strtoupper($row->name_hex), $plainHex, true);
            $valueOk = str_starts_with((string) $row->value, 'uploads/' . $row->store_id . '/') && ! str_contains((string) $row->value, '..');
            if (! $nameOk || ! $valueOk) {
                $kind = $nameOk ? 'plain-name' : (strlen($row->name) > 40 ? 'padded' : 'folded');
                $flagged[] = $row->store_id . ':' . $kind;
            }
        }
        $planted = $serverCuts ? 4 : 3;
        $out('the query lists every sign-in row and not the unrelated one', count($rows) === $planted + 2, count($rows) . ' rows');
        $out("the two tests the document describes flag exactly the {$planted} planted rows and not the two honest ones", count($flagged) === $planted && ! in_array('1:plain-name', $flagged, true) && ! in_array('4:plain-name', $flagged, true), implode(', ', $flagged));
    }
} catch (Throwable $e) {
    $failed = true;
    echo "\n  [FAIL] the script died: " . get_class($e) . ': ' . $e->getMessage() . "\n         at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    try {
        DB::connection('snv_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable $e) {
    }
}

echo "\n" . ($failed ? 'FAILED' : 'every check held') . "\n";
exit($failed ? 1 : 0);
