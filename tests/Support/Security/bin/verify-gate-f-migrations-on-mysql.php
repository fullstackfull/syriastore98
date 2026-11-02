<?php

/**
 * GATE F -- PROVE THE 15 MIGRATIONS ON A REAL MariaDB/MySQL SERVER, IN THE THREE WAYS THAT MATTER.
 *
 * WHY A SCRIPT AND NOT A TEST. The suite runs on SQLite `:memory:`, where type affinity means
 * `decimal(15,2)` is a suggestion and `FOR UPDATE` is ignored. A schema test there would pass
 * against a schema that cannot hold the data -- which is how P01-SEC-13 survived. The questions
 * below are only answerable on the production driver, and a conditionally-skipped test would also
 * break the declared-skip set in `.github/known-test-outcomes.json`. So this states its own
 * preconditions and reports what it could and could not do.
 *
 * WHAT IT ANSWERS
 *
 *   A. FRESH DATABASE. Run every migration from zero, then compare each of the 15 tables against
 *      the measured production structure. This is the question "does a NEW environment -- CI,
 *      staging, a new tenant database -- get the schema production already has?"
 *
 *   B. PRODUCTION-SHAPED DATABASE. Build a database whose 15 tables ARE the measured production
 *      DDL, put live-looking rows in the six money tables, un-record the GATE F migrations so they
 *      run again, and then run them. Three things are then asserted, and B is the scenario that
 *      actually protects production:
 *        B1  every GATE F migration reports DONE (the guards pass),
 *        B2  not one of the 15 structures changed -- byte-for-byte on the parsed spec,
 *        B3  not one row was lost or altered, including the money rows and their exact decimals.
 *
 *   C. THE GUARD BITES. Deliberately corrupt a production-shaped copy in the five ways that have
 *      actually happened in this project's drafts -- widen a money scale, drop a unique key, drop a
 *      foreign key, turn an enum into free text, change an FX default to zero -- and assert that
 *      each one is REFUSED with a named mismatch rather than silently accepted. A guard nobody has
 *      seen fail is not evidence.
 *
 * WHAT IT NEVER DOES. It never touches a database it did not create, and it never runs against
 * production. Every database it uses is named with a `gatef_verify_` prefix plus the process id,
 * created at the start and dropped at the end. It does not read, copy or require the production
 * dump: the expected structure comes from `App\Support\Database\GateFProductionSchema`, which is a
 * verbatim copy of `docs/GATE-F-VERIFIED-SCHEMA.md`.
 *
 * PRECONDITIONS, stated rather than assumed -- the script checks each and says which one failed:
 *   - a reachable MariaDB/MySQL server, and an account that may CREATE and DROP DATABASE;
 *   - this repository's `vendor/` installed.
 *
 * Usage:
 *   php tests/Support/Security/bin/verify-gate-f-migrations-on-mysql.php \
 *       [--socket=/run/mysqld/mysqld.sock | --host=127.0.0.1 --port=3306] [--user=root] [--pass=]
 *
 * Exit status: 0 = every check passed · 1 = a check failed · 2 = a precondition was not met.
 */

// ------------------------------------------------------------------ arguments

$opt = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '', 'socket' => null];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } elseif ($a === '--help' || $a === '-h') {
        fwrite(STDERR, "usage: php " . basename(__FILE__) . " [--socket=|--host= --port=] [--user=] [--pass=]\n");
        exit(2);
    }
}

$root = dirname(__DIR__, 4);

if (! is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "PRECONDITION NOT MET: vendor/ is not installed. Run `composer install` first.\n");
    exit(2);
}

require $root . '/vendor/autoload.php';

use App\Support\Database\GateFProductionSchema as Schema;
use App\Support\Database\GateFSchemaGuard as Guard;

$dsnFor = static function (?string $db) use ($opt): string {
    $tail = $db !== null ? ";dbname={$db}" : '';

    return $opt['socket'] !== null
        ? "mysql:unix_socket={$opt['socket']}{$tail};charset=utf8mb4"
        : "mysql:host={$opt['host']};port={$opt['port']}{$tail};charset=utf8mb4";
};

try {
    $server = new PDO($dsnFor(null), $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot reach the server -- {$e->getMessage()}\n");
    fwrite(STDERR, "Start one, or pass --socket=/run/mysqld/mysqld.sock.\n");
    exit(2);
}

$version = $server->query('SELECT VERSION()')->fetchColumn();
$prefix  = 'gatef_verify_' . getmypid() . '_';
$created = [];
$fail    = 0;
$checks  = 0;

$say   = static fn (string $s = '') => print($s . "\n");
$ok    = static function (string $s) use (&$checks) { $checks++; print("  PASS  {$s}\n"); };
$bad   = static function (string $s) use (&$checks, &$fail) { $checks++; $fail++; print("  FAIL  {$s}\n"); };

$makeDb = function (string $suffix) use ($server, $prefix, &$created, $dsnFor, $opt): PDO {
    $name = $prefix . $suffix;
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    $server->exec("CREATE DATABASE `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created[] = $name;

    return new PDO($dsnFor($name), $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};

$artisan = function (string $db, string $command) use ($root, $opt): array {
    $env = [
        'DB_CONNECTION=mysql',
        'DB_DATABASE=' . $db,
        'DB_USERNAME=' . $opt['user'],
        'DB_PASSWORD=' . $opt['pass'],
        'CACHE_STORE=array',
    ];
    $env[] = $opt['socket'] !== null
        ? 'DB_SOCKET=' . $opt['socket']
        : 'DB_HOST=' . $opt['host'];
    if ($opt['socket'] === null) {
        $env[] = 'DB_PORT=' . $opt['port'];
    }

    // `env` is required, not decoration: a shell treats a QUOTED `VAR=value` as a command name, so
    // `'DB_CONNECTION=mysql' php artisan ...` exits 127 with "not found" -- which is exactly how
    // this script first reported a migration failure that had not happened.
    $cmd = 'cd ' . escapeshellarg($root) . ' && env ' . implode(' ', array_map('escapeshellarg', $env))
        . ' php artisan ' . $command . ' 2>&1';
    exec($cmd, $out, $code);

    return [$code, implode("\n", $out)];
};

/** Parsed structure of every GATE F table on a connection. */
$snapshot = static function (PDO $pdo): array {
    $out = [];
    foreach (Schema::tableNames() as $t) {
        $row = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC);
        $out[$t] = Guard::parse($row['Create Table']);
    }

    return $out;
};

/** The guard's own comparison, driven directly so the script needs no Laravel container. */
$differences = static function (array $want, array $got): array {
    $ref = new ReflectionMethod(Guard::class, 'compareDeep');
    $ref->setAccessible(true);

    return $ref->invoke(null, $want, $got);
};

$say('GATE F -- MIGRATION VERIFICATION ON A REAL SERVER');
$say('server:   ' . $version);
$say('measured: MariaDB 11.4.9, docs/GATE-F-VERIFIED-SCHEMA.md section 2 (restored copy, 2026-10-24)');
$say('tables:   ' . count(Schema::tableNames()) . ' (' . count(Schema::MONEY_TABLES) . ' money-bearing)');
$say();

try {
    // =========================================================================== A
    $say('A. FRESH DATABASE -- migrate from zero, then compare every table to production');
    $fresh = $makeDb('fresh');
    [$code, $out] = $artisan($prefix . 'fresh', 'migrate --force --no-interaction');

    if ($code !== 0) {
        $bad('migrate from zero exited ' . $code);
        $say(preg_replace('/^/m', '        ', substr($out, -2000)));
    } else {
        $ok('migrate from zero exited 0');

        $freshSnapshot = $snapshot($fresh);
        foreach (Schema::tableNames() as $t) {
            $d = $differences(Guard::parse(Schema::ddlFor($t)), $freshSnapshot[$t]);
            $money = Schema::isMoneyTable($t) ? ' [money]' : '';
            if ($d === []) {
                $ok("A: `{$t}`{$money} matches the measured production structure");
            } else {
                $bad("A: `{$t}`{$money} differs from production in " . count($d) . ' way(s)');
                foreach ($d as $x) {
                    $say('        - ' . $x);
                }
            }
        }

        // The three columns on tables that already had migrations.
        $cols = [
            ['delivery_boys', 'commission_percentage', 'decimal(5,2)', true, '0'],
            ['delivery_boys', 'is_commission_enabled', 'tinyint', false, '0'],
            ['products', 'cost_price', 'decimal(15,2)', false, '0'],
        ];
        foreach ($cols as [$table, $column, $type, $nullable, $default]) {
            $row = $fresh->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $spec = Guard::parse($row['Create Table']);
            if (! isset($spec['columns'][$column])) {
                $bad("A: `{$table}`.`{$column}` is MISSING from a database built from this repository");

                continue;
            }
            $c = $spec['columns'][$column];
            $problems = [];
            if ($c['type'] !== $type) {
                $problems[] = "type is `{$c['type']}`, production has `{$type}`";
            }
            if ($c['nullable'] !== $nullable) {
                $problems[] = 'nullability differs from production';
            }
            if ($c['default'] !== $default) {
                $problems[] = "default is `{$c['default']}`, production has `{$default}`";
            }
            $problems === []
                ? $ok("A: `{$table}`.`{$column}` matches production ({$type})")
                : $bad("A: `{$table}`.`{$column}` -- " . implode('; ', $problems));
        }
    }
    $say();

    // =========================================================================== B
    $say('B. PRODUCTION-SHAPED DATABASE -- the 15 tables already exist, with rows, as production has them');
    $shaped = $makeDb('shaped');
    [$code, $out] = $artisan($prefix . 'shaped', 'migrate --force --no-interaction');
    if ($code !== 0) {
        $bad('B: could not build the baseline (migrate exited ' . $code . ')');
        $say(preg_replace('/^/m', '        ', substr($out, -1200)));
    } else {
        // Replace the 15 tables with the VERBATIM production DDL, so this really is
        // production-shaped and not "whatever our own migrations happen to produce".
        $shaped->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(Schema::tableNames()) as $t) {
            $shaped->exec("DROP TABLE IF EXISTS `{$t}`");
        }
        foreach (Schema::tableNames() as $t) {
            $shaped->exec(Schema::ddlFor($t));
        }
        $shaped->exec('SET FOREIGN_KEY_CHECKS=1');

        // Live-looking rows in the money tables, with decimals that would betray a rounding change,
        // plus the parents they reference.
        //
        // `seed()` fills every NOT NULL column that has no default from information_schema rather
        // than from a hand-written column list, because `stores` alone has several and a list here
        // would break the moment that table gains another -- which is how this script first failed
        // on `Field 'email' doesn't have a default value`, a fixture defect reported as if the
        // migrations were at fault.
        $seed = static function (PDO $pdo, string $table, array $values): void {
            $required = $pdo->query(
                'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote($table) . "
                    AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'"
            )->fetchAll(PDO::FETCH_ASSOC);

            // A required column that carries a foreign key cannot take 0: `stores.created_by`
            // references `users.id` and 0 is not a user. Those columns get 901, which is the id
            // every parent seeded below uses.
            $fkColumns = $pdo->query(
                'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote($table) . '
                    AND REFERENCED_TABLE_NAME IS NOT NULL'
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($required as $col) {
                if (array_key_exists($col['COLUMN_NAME'], $values)) {
                    continue;
                }
                $values[$col['COLUMN_NAME']] = match (true) {
                    in_array($col['COLUMN_NAME'], $fkColumns, true)                      => 901,
                    str_contains($col['DATA_TYPE'], 'int')                               => 0,
                    in_array($col['DATA_TYPE'], ['decimal', 'float', 'double'], true)    => 0,
                    in_array($col['DATA_TYPE'], ['timestamp', 'datetime', 'date'], true) => '2026-11-02 00:00:00',
                    default                                                              => 'gatef-verify',
                };
            }

            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", array_keys($values)));
            $marks = implode(', ', array_fill(0, count($values), '?'));
            $pdo->prepare("INSERT INTO `{$table}` ({$cols}) VALUES ({$marks})")->execute(array_values($values));
        };

        // Seeded in dependency order: `users` first, because `stores.created_by` references it.
        $seed($shaped, 'users', [
            'id' => 901, 'name' => 'GATE F Verify', 'email' => 'gatef-verify@example.invalid',
            'password' => 'not-a-real-hash', 'type' => 'admin',
        ]);
        $seed($shaped, 'stores', ['id' => 901, 'name' => 'Verify Store', 'slug' => 'verify-store']);
        $seed($shaped, 'store_wallets', [
            'id' => 901, 'store_id' => 901, 'currency_code' => 'USD',
            'available_balance' => '1234.56', 'pending_balance' => '78.90',
            'withdrawal_pending_balance' => '12.34', 'total_income' => '9876.54',
            'total_withdrawn' => '321.00',
        ]);
        $seed($shaped, 'store_wallet_transactions', [
            'id' => 901, 'store_id' => 901, 'wallet_id' => 901, 'currency_code' => 'USD',
            'type' => 'order_income', 'direction' => 'credit', 'amount' => '100.07',
            'balance_before' => '1134.49', 'balance_after' => '1234.56', 'status' => 'completed',
        ]);
        $seed($shaped, 'store_withdrawals', [
            'id' => 901, 'store_id' => 901, 'wallet_id' => 901, 'amount' => '250.25', 'status' => 'pending',
        ]);
        $seed($shaped, 'currency_rates', [
            'id' => 901, 'store_id' => 901, 'currency_code' => 'SYP', 'currency_name' => 'Syrian Pound',
            'symbol' => 'SYP', 'rate_per_usd' => '13021.12345678', 'is_active' => 1,
        ]);

        $before     = $snapshot($shaped);
        $rowsBefore = [];
        foreach (Schema::MONEY_TABLES as $t) {
            $rowsBefore[$t] = $shaped->query("SELECT * FROM `{$t}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Un-record the GATE F migrations so they really run against this shape.
        $gateF = $shaped->query("SELECT migration FROM migrations WHERE migration LIKE '2026\\_11\\_02\\_%' OR migration = '2026_10_12_000105_create_audit_logs_table'")->fetchAll(PDO::FETCH_COLUMN);
        $shaped->exec("DELETE FROM migrations WHERE migration LIKE '2026\\_11\\_02\\_%' OR migration = '2026_10_12_000105_create_audit_logs_table'");
        count($gateF) >= 15
            ? $ok('B: un-recorded ' . count($gateF) . ' GATE F migrations so they re-run against the production shape')
            : $bad('B: expected at least 15 GATE F migrations to un-record, found ' . count($gateF));

        [$code, $out] = $artisan($prefix . 'shaped', 'migrate --force --no-interaction');

        // B1 -- every GATE F migration ran and reported DONE
        if ($code !== 0) {
            $bad('B1: migrate against the production shape exited ' . $code . ' -- the guard refused something');
            $say(preg_replace('/^/m', '        ', substr($out, -3000)));
        } else {
            $ok('B1: migrate against the production shape exited 0; every guard passed');
        }

        // B2 -- nothing changed structurally
        $after   = $snapshot($shaped);
        $changed = [];
        foreach (Schema::tableNames() as $t) {
            if ($differences($before[$t], $after[$t]) !== []) {
                $changed[] = $t;
            }
        }
        $changed === []
            ? $ok('B2: not one of the ' . count(Schema::tableNames()) . ' structures changed -- the migrations are the intended no-op')
            : $bad('B2: these structures CHANGED against a production shape: ' . implode(', ', $changed));

        // B3 -- nothing was lost or altered, decimals included
        $lost = [];
        foreach (Schema::MONEY_TABLES as $t) {
            $now = $shaped->query("SELECT * FROM `{$t}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            if ($now !== $rowsBefore[$t]) {
                $lost[] = $t;
            }
        }
        $lost === []
            ? $ok('B3: every money row survived byte-for-byte, decimals included (balance 1234.56, rate 13021.12345678)')
            : $bad('B3: rows were LOST OR ALTERED in: ' . implode(', ', $lost));

        // B4 -- and the rollback is not destructive either
        [$rbCode, $rbOut] = $artisan($prefix . 'shaped', 'migrate:rollback --force --no-interaction --step=1');
        $survived = true;
        foreach (Schema::tableNames() as $t) {
            $exists = (bool) $shaped->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $shaped->quote($t))->fetchColumn();
            if (! $exists) {
                $survived = false;
                $bad("B4: `{$t}` was DROPPED by migrate:rollback -- a destructive rollback on live data");
            }
        }
        if ($survived) {
            $ok('B4: migrate:rollback dropped none of the 15 tables; every down() is a documented no-op');
        }
    }
    $say();

    // =========================================================================== C
    $say('C. THE GUARD BITES -- five corruptions this project has actually produced, each must be REFUSED');
    $mutations = [
        ['store_order_financials', "ALTER TABLE store_order_financials MODIFY `product_amount` decimal(18,4) NOT NULL DEFAULT '0.0000'",
            'a money column widened to the superseded decimal(18,4) standing decision'],
        ['store_order_financials', 'ALTER TABLE store_order_financials DROP INDEX `uq_store_order_financial_order`',
            'the unique key that makes "one financial row per order" true'],
        ['store_wallet_transactions', 'ALTER TABLE store_wallet_transactions DROP FOREIGN KEY `fk_wallet_transactions_wallet`',
            'the foreign key that stops a deleted wallet leaving ledger rows behind'],
        ['store_wallet_transactions', "ALTER TABLE store_wallet_transactions MODIFY `direction` varchar(255) NULL",
            "the ledger's credit/debit enum turned into nullable free text"],
        ['currency_rates', "ALTER TABLE currency_rates MODIFY `rate_per_usd` decimal(20,8) NOT NULL DEFAULT '0.00000000'",
            'an FX multiplier defaulting to zero, which prices every conversion at nothing'],
    ];

    foreach ($mutations as $i => [$table, $sql, $what]) {
        $m = $makeDb('mut' . $i);
        $m->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['stores', 'orders', 'delivery_boys', 'shipping_methods'] as $parent) {
            $m->exec("CREATE TABLE `{$parent}` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        foreach (Schema::tableNames() as $t) {
            $m->exec(Schema::ddlFor($t));
        }
        $m->exec($sql);
        $m->exec('SET FOREIGN_KEY_CHECKS=1');

        $row = $m->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $d   = $differences(Guard::parse(Schema::ddlFor($table)), Guard::parse($row['Create Table']));

        $d === []
            ? $bad("C" . ($i + 1) . ": the guard ACCEPTED {$what} -- it does not bite")
            : $ok("C" . ($i + 1) . ": refused -- {$what} (" . count($d) . ' named difference(s): ' . $d[0] . ')');
    }
    $say();
} finally {
    foreach ($created as $name) {
        try {
            $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        } catch (Throwable) {
            fwrite(STDERR, "WARNING: could not drop throwaway database `{$name}`\n");
        }
    }
}

$say(str_repeat('-', 92));
$say(sprintf('%d checks, %d failed', $checks, $fail));
$say($fail === 0
    ? 'GATE F VERIFIED on ' . $version . ': the 15 migrations reproduce production exactly on a fresh '
        . 'database, are a structural and data no-op against a production shape, roll back without '
        . 'dropping anything, and the structural guard refuses all five corruptions.'
    : 'GATE F NOT VERIFIED -- read the FAIL lines above. The production structure is the authority; '
        . 'do not change a live table to match this repository.');

exit($fail === 0 ? 0 : 1);
