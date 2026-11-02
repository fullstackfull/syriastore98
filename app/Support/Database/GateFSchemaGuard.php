<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GATE F -- PROVE A LIVE TABLE REALLY MATCHES THE MEASURED PRODUCTION STRUCTURE.
 *
 * WHY THIS EXISTS, AND WHY `Schema::hasTable()` IS NOT ENOUGH. Every GATE F migration is guarded,
 * because all 15 tables already exist in production with live data and the migration must be a
 * no-op there. But a `hasTable()` guard answers only "is there something by this name", and that
 * is the exact shape of the trap `_gate_f_pending/README.md` warns about: if a table with the
 * WRONG types is already present, the guard returns early, `migrate:status` then reports the
 * migration as "Ran", and the wrong schema becomes permanent AND invisible -- the migration
 * history has become a false record. A `hasTable()` check is therefore proof that a `CREATE` would
 * fail, not proof that skipping it is safe.
 *
 * WHAT THIS CLASS DOES INSTEAD. When the table exists, it compares the live structure against
 * `GateFProductionSchema`'s measured DDL and, on any incompatibility, THROWS -- which stops
 * `migrate` before the migration is recorded, changes nothing, and prints the exact field-level
 * mismatch. Stopping is the safe outcome: nothing is altered, nothing is dropped, and nobody is
 * told a money table is managed when it is not.
 *
 * HOW THE COMPARISON AVOIDS FALSE ALARMS. Both sides -- the expected DDL and the live
 * `SHOW CREATE TABLE` -- go through the SAME parser, so the diff is structural rather than
 * textual. Four classes of difference are deliberately normalised away, each because it carries no
 * storage, constraint or behavioural meaning:
 *
 *   1. **Integer display widths.** The measurement was taken on MariaDB 11.4.9, which omits them
 *      (`bigint unsigned`); MariaDB 10.x and MySQL 5.7 print them (`bigint(20) unsigned`). The
 *      width is deprecated metadata and constrains nothing, so it is stripped on both sides.
 *   2. **Inherited charset and collation.** `SHOW CREATE TABLE` prints a column's charset and
 *      collation only when they differ from the table's. The dump this was measured from printed
 *      them on every column. So an absent column collation is RESOLVED to the table's before
 *      comparing -- which keeps `utf8mb4_bin` on the JSON columns a real difference while making
 *      an inherited `utf8mb4_general_ci` a non-difference.
 *   3. **Default-literal spelling.** `'0.00'` and `0.00` are the same default; so are
 *      `CURRENT_TIMESTAMP` and MariaDB 10.x's `current_timestamp()`.
 *   4. **The `AUTO_INCREMENT=` table counter.** It is a row counter, not structure.
 *
 * Two further differences are reported but NOT treated as incompatibility, and the report says so
 * rather than hiding them:
 *
 *   - **Column `COMMENT` text.** `delivery_earnings` carries Arabic comments in production which
 *      reached `docs/GATE-F-VERIFIED-SCHEMA.md` as `?????` through the dump's encoding. Inventing
 *      Arabic text to match would be worse than omitting it, and a comment enforces nothing.
 *   - **`CHECK` constraint names.** MariaDB auto-names `json_valid` checks `<table>_chk_N` by
 *      declaration order, so the NUMBER is an artefact of ordering. The set of checked
 *      EXPRESSIONS is compared; the names are not.
 *
 * ON A NON-MySQL DRIVER. The test suite runs on SQLite, where type affinity means a deep
 * comparison would be theatre -- SQLite accepts `decimal(15,2)` and stores whatever it likes. So on
 * any driver other than `mysql`/`mariadb` the guard compares the COLUMN NAME SET only and records,
 * in `notes()`, that the deep comparison was not possible. It never claims to have verified types
 * on a driver that cannot hold them.
 */
final class GateFSchemaGuard
{
    /** Drivers whose `SHOW CREATE TABLE` the parser understands. */
    private const DEEP_DRIVERS = ['mysql', 'mariadb'];

    /** @var list<string> set by the last comparison; things checked or deliberately not checked */
    private static array $notes = [];

    /**
     * Refuse to continue unless the live table is structurally compatible with production.
     *
     * Call this from a migration's `up()` when `Schema::hasTable()` is true, INSTEAD of returning
     * early on the name alone.
     *
     * @throws GateFSchemaMismatch when the live structure contradicts the measured one
     */
    public static function assertCompatible(string $table, ?string $connection = null): void
    {
        $differences = self::differences($table, $connection);

        if ($differences === []) {
            return;
        }

        throw new GateFSchemaMismatch($table, $differences, self::$notes);
    }

    /**
     * Every incompatibility between the live `$table` and the measured production structure.
     *
     * @return list<string> empty when compatible
     */
    public static function differences(string $table, ?string $connection = null): array
    {
        self::$notes = [];

        $expected = self::parse(GateFProductionSchema::ddlFor($table));
        $driver   = DB::connection($connection)->getDriverName();

        if (! Schema::connection($connection)->hasTable($table)) {
            return ["the table does not exist on the '{$driver}' connection, so there is nothing to compare"];
        }

        if (! in_array($driver, self::DEEP_DRIVERS, true)) {
            self::$notes[] = "driver is '{$driver}', not mysql/mariadb: COLUMN NAMES were compared and "
                . 'types, nullability, defaults, keys and foreign keys were NOT -- a deep comparison '
                . 'on a driver with type affinity would prove nothing';

            return self::compareColumnNames($expected, $table, $connection);
        }

        $row  = DB::connection($connection)->selectOne("SHOW CREATE TABLE `{$table}`");
        $live = self::parse(((array) $row)['Create Table'] ?? ((array) $row)['Create View'] ?? '');

        self::$notes[] = 'deep structural comparison: columns (type, nullability, default, '
            . 'auto-increment, on-update, charset, collation), primary key, unique keys, plain '
            . 'indexes, foreign keys with their ON DELETE/ON UPDATE rules, engine, table charset '
            . 'and table collation';
        self::$notes[] = 'NOT compared, each for a stated reason: integer display widths, '
            . 'AUTO_INCREMENT counter, column COMMENT text, CHECK constraint names (their '
            . 'expressions ARE compared)';

        return self::compareDeep($expected, $live);
    }

    /** @return list<string> */
    public static function notes(): array
    {
        return self::$notes;
    }

    // ------------------------------------------------------------------ comparison

    /**
     * @param  array<string,mixed>  $expected
     * @return list<string>
     */
    private static function compareColumnNames(array $expected, string $table, ?string $connection): array
    {
        $live     = Schema::connection($connection)->getColumnListing($table);
        $expectedCols = array_keys($expected['columns']);

        $out = [];
        foreach (array_diff($expectedCols, $live) as $missing) {
            $out[] = "column `{$missing}` is MISSING from the live table";
        }
        foreach (array_diff($live, $expectedCols) as $extra) {
            $out[] = "column `{$extra}` exists on the live table but not in the measured production structure";
        }

        return array_values($out);
    }

    /**
     * @param  array<string,mixed>  $expected
     * @param  array<string,mixed>  $live
     * @return list<string>
     */
    private static function compareDeep(array $expected, array $live): array
    {
        $out = [];

        // --- columns
        $expectedNames = array_keys($expected['columns']);
        $liveNames     = array_keys($live['columns']);

        foreach (array_diff($expectedNames, $liveNames) as $missing) {
            $out[] = "column `{$missing}` is MISSING from the live table";
        }
        foreach (array_diff($liveNames, $expectedNames) as $extra) {
            $out[] = "column `{$extra}` exists on the live table but not in the measured production structure";
        }

        foreach ($expected['columns'] as $name => $want) {
            if (! isset($live['columns'][$name])) {
                continue;                                    // already reported as missing
            }
            $got = $live['columns'][$name];

            foreach (['type', 'nullable', 'default', 'auto_increment', 'on_update_current', 'collation', 'charset'] as $field) {
                if (($want[$field] ?? null) !== ($got[$field] ?? null)) {
                    $out[] = sprintf(
                        'column `%s` %s: production has %s, the live table has %s',
                        $name,
                        $field,
                        self::show($want[$field] ?? null),
                        self::show($got[$field] ?? null)
                    );
                }
            }
        }

        if ($expectedNames !== $liveNames && array_diff($expectedNames, $liveNames) === [] && array_diff($liveNames, $expectedNames) === []) {
            $out[] = 'COLUMN ORDER differs (the same columns are present): production order is '
                . implode(', ', $expectedNames) . '; the live table has ' . implode(', ', $liveNames);
        }

        // --- primary key
        if (($expected['primary'] ?? null) !== ($live['primary'] ?? null)) {
            $out[] = 'PRIMARY KEY: production has (' . implode(', ', $expected['primary'] ?? []) . '), '
                . 'the live table has (' . implode(', ', $live['primary'] ?? []) . ')';
        }

        // --- keys, by NAME: a differently named key with the same columns is still a difference,
        //     because code and future migrations refer to these names.
        foreach (['unique' => 'UNIQUE KEY', 'index' => 'INDEX'] as $bucket => $label) {
            foreach ($expected[$bucket] as $name => $cols) {
                if (! isset($live[$bucket][$name])) {
                    $out[] = "{$label} `{$name}` (" . implode(', ', $cols) . ') is MISSING from the live table'
                        . ($bucket === 'unique' ? ' -- a unique key is an INTEGRITY GUARANTEE, not an optimisation' : '');

                    continue;
                }
                if ($live[$bucket][$name] !== $cols) {
                    $out[] = "{$label} `{$name}`: production covers (" . implode(', ', $cols) . '), '
                        . 'the live table covers (' . implode(', ', $live[$bucket][$name]) . ')';
                }
            }
            foreach ($live[$bucket] as $name => $cols) {
                if (! isset($expected[$bucket][$name])) {
                    $out[] = "{$label} `{$name}` (" . implode(', ', $cols) . ') exists on the live table '
                        . 'but not in the measured production structure';
                }
            }
        }

        // --- foreign keys
        foreach ($expected['foreign'] as $name => $want) {
            if (! isset($live['foreign'][$name])) {
                $out[] = "FOREIGN KEY `{$name}` (" . implode(', ', $want['columns']) . ') -> '
                    . $want['on'] . '(' . implode(', ', $want['references']) . ') is MISSING from the live '
                    . 'table -- the ON DELETE rule is what stops a deleted parent leaving orphan rows behind';

                continue;
            }
            $got = $live['foreign'][$name];
            foreach (['columns', 'on', 'references', 'on_delete', 'on_update'] as $field) {
                if (($want[$field] ?? null) !== ($got[$field] ?? null)) {
                    $out[] = sprintf(
                        'FOREIGN KEY `%s` %s: production has %s, the live table has %s',
                        $name,
                        $field,
                        self::show($want[$field] ?? null),
                        self::show($got[$field] ?? null)
                    );
                }
            }
        }
        foreach ($live['foreign'] as $name => $got) {
            if (! isset($expected['foreign'][$name])) {
                $out[] = "FOREIGN KEY `{$name}` exists on the live table but not in the measured production structure";
            }
        }

        // --- CHECK expressions as a set; names are an artefact of declaration order
        $wantChecks = array_values(array_map([self::class, 'normaliseCheck'], $expected['check']));
        $gotChecks  = array_values(array_map([self::class, 'normaliseCheck'], $live['check']));
        sort($wantChecks);
        sort($gotChecks);
        foreach (array_diff($wantChecks, $gotChecks) as $missing) {
            $out[] = "CHECK constraint on `{$missing}` is MISSING from the live table";
        }
        foreach (array_diff($gotChecks, $wantChecks) as $extra) {
            $out[] = "CHECK constraint on `{$extra}` exists on the live table but not in the measured production structure";
        }

        // --- table options
        foreach (['engine', 'charset', 'collation'] as $field) {
            if (($expected[$field] ?? null) !== ($live[$field] ?? null)) {
                $out[] = sprintf(
                    'table %s: production has %s, the live table has %s',
                    $field,
                    self::show($expected[$field] ?? null),
                    self::show($live[$field] ?? null)
                );
            }
        }

        return array_values($out);
    }

    private static function show(mixed $v): string
    {
        return match (true) {
            $v === null   => 'nothing',
            $v === true   => 'yes',
            $v === false  => 'no',
            is_array($v)  => '(' . implode(', ', $v) . ')',
            default       => "`{$v}`",
        };
    }

    private static function normaliseCheck(string $expression): string
    {
        return strtolower(preg_replace('/[`\s]+/', '', $expression));
    }

    // ------------------------------------------------------------------ blueprint derivation

    /**
     * The Laravel Blueprint statements that reproduce a measured `CREATE TABLE`.
     *
     * WHAT THIS IS FOR, STATED HONESTLY. The 15 GATE F migrations were WRITTEN from this mapping,
     * so a test that compares a migration against it is NOT an independent re-derivation and does
     * not claim to be. What it catches is a HAND EDIT: somebody widening a money scale, dropping a
     * unique key or renaming a foreign key directly in a migration file, which would then disagree
     * with `docs/GATE-F-VERIFIED-SCHEMA.md` and go unnoticed until a fresh environment had the
     * wrong schema. `tests/Unit/Database/GateFMigrationParityTest` is that check.
     *
     * The INDEPENDENT verification is `tests/Support/Security/bin/verify-gate-f-migrations-on-mysql.php`,
     * which runs the migrations on a real server and compares the resulting `SHOW CREATE TABLE`
     * against the measurement -- a different question, answered by execution rather than by text.
     *
     * @return array{charset:?string,collation:?string,columns:list<string>,keys:list<string>,foreign:list<string>}
     */
    public static function blueprintFor(string $ddl): array
    {
        $spec = self::parse($ddl);

        $jsonColumns = [];
        foreach ($spec['check'] as $expression) {
            if (preg_match('/json_valid\(`?([^`)]+)`?\)/i', $expression, $m)) {
                $jsonColumns[trim($m[1], '` ')] = true;
            }
        }

        $columns = [];
        foreach ($spec['columns'] as $name => $column) {
            $type  = $column['type'];
            $scale = null;

            if ($column['auto_increment'] && $spec['primary'] === [$name] && $type === 'bigint unsigned') {
                $columns[] = '$table->id();';

                continue;
            }

            if (isset($jsonColumns[$name])) {
                $call = sprintf("\$table->json('%s')", $name);
            } elseif ($type === 'bigint unsigned') {
                $call = sprintf("\$table->unsignedBigInteger('%s')", $name);
            } elseif ($type === 'int unsigned') {
                $call = sprintf("\$table->unsignedInteger('%s')", $name);
            } elseif ($type === 'tinyint unsigned') {
                $call = sprintf("\$table->unsignedTinyInteger('%s')", $name);
            } elseif ($type === 'tinyint') {
                $call = sprintf("\$table->boolean('%s')", $name);
            } elseif (preg_match('/^varchar\((\d+)\)$/', $type, $m)) {
                $call = sprintf("\$table->string('%s', %d)", $name, (int) $m[1]);
            } elseif ($type === 'text') {
                $call = sprintf("\$table->text('%s')", $name);
            } elseif ($type === 'longtext') {
                $call = sprintf("\$table->longText('%s')", $name);
            } elseif (preg_match('/^decimal\((\d+),(\d+)\)$/', $type, $m)) {
                $scale = (int) $m[2];
                $call  = sprintf("\$table->decimal('%s', %d, %d)", $name, (int) $m[1], $scale);
            } elseif (preg_match('/^enum\((.+)\)$/', $type, $m)) {
                preg_match_all("/'([^']*)'/", $m[1], $values);
                $call = sprintf(
                    "\$table->enum('%s', [%s])",
                    $name,
                    implode(', ', array_map(static fn ($v) => "'{$v}'", $values[1]))
                );
            } elseif ($type === 'timestamp') {
                $call = sprintf("\$table->timestamp('%s')", $name);
            } else {
                throw new \RuntimeException("GATE F: no Blueprint mapping for type `{$type}` on `{$name}`.");
            }

            if ($column['nullable']) {
                $call .= '->nullable()';
            }

            $default = $column['default'];
            if ($default === 'CURRENT_TIMESTAMP') {
                $call .= '->useCurrent()';
            } elseif ($default !== null && $default !== 'NULL') {
                $call .= is_numeric($default)
                    ? sprintf('->default(%s)', $scale !== null ? number_format((float) $default, $scale, '.', '') : $default)
                    : sprintf("->default('%s')", str_replace("'", "\\'", $default));
            }

            if ($column['on_update_current']) {
                $call .= '->useCurrentOnUpdate()';
            }

            $columns[] = $call . ';';
        }

        $keys = [];
        foreach ($spec['unique'] as $name => $cols) {
            $keys[] = sprintf("\$table->unique(%s, '%s');", self::renderColumns($cols), $name);
        }
        foreach ($spec['index'] as $name => $cols) {
            $keys[] = sprintf("\$table->index(%s, '%s');", self::renderColumns($cols), $name);
        }

        $foreign = [];
        foreach ($spec['foreign'] as $name => $fk) {
            $statement = sprintf(
                "\$table->foreign(%s, '%s')->references(%s)->on('%s')",
                self::renderColumns($fk['columns']),
                $name,
                self::renderColumns($fk['references']),
                $fk['on']
            );
            $statement .= match ($fk['on_delete']) {
                'CASCADE'  => '->cascadeOnDelete()',
                'SET NULL' => '->nullOnDelete()',
                'RESTRICT' => '->restrictOnDelete()',
                default    => '',
            };
            $statement .= $fk['on_update'] === 'CASCADE' ? '->cascadeOnUpdate()' : '';

            $foreign[] = $statement . ';';
        }

        return [
            'charset'   => $spec['charset'],
            'collation' => $spec['collation'],
            'columns'   => $columns,
            'keys'      => $keys,
            'foreign'   => $foreign,
        ];
    }

    /** @param list<string> $columns */
    private static function renderColumns(array $columns): string
    {
        return count($columns) === 1
            ? "'{$columns[0]}'"
            : '[' . implode(', ', array_map(static fn ($c) => "'{$c}'", $columns)) . ']';
    }

    // ------------------------------------------------------------------ the parser

    /**
     * Parse a `CREATE TABLE` statement into a comparable structure.
     *
     * The SAME method parses the measured production DDL and the live `SHOW CREATE TABLE`, which is
     * the whole reason the comparison is free of formatting noise.
     *
     * @return array{columns:array<string,array<string,mixed>>,primary:?list<string>,unique:array<string,list<string>>,index:array<string,list<string>>,foreign:array<string,array<string,mixed>>,check:array<string,string>,engine:?string,charset:?string,collation:?string}
     */
    public static function parse(string $ddl): array
    {
        $spec = [
            'columns' => [], 'primary' => null, 'unique' => [], 'index' => [],
            'foreign' => [], 'check' => [], 'engine' => null, 'charset' => null, 'collation' => null,
        ];

        foreach (preg_split('/\R/', $ddl) as $raw) {
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, 'CREATE TABLE')) {
                continue;
            }

            if (str_starts_with($line, ')')) {                       // the table-options tail
                if (preg_match('/ENGINE=(\w+)/i', $line, $m)) {
                    $spec['engine'] = $m[1];
                }
                if (preg_match('/(?:DEFAULT )?CHARSET=(\w+)/i', $line, $m)) {
                    $spec['charset'] = $m[1];
                }
                if (preg_match('/COLLATE=(\w+)/i', $line, $m)) {
                    $spec['collation'] = $m[1];
                }

                continue;
            }

            $line = rtrim($line, ',');

            if (preg_match('/^PRIMARY KEY \((.+)\)$/i', $line, $m)) {
                $spec['primary'] = self::backticked($m[1]);

                continue;
            }
            if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $line, $m)) {
                $spec['unique'][$m[1]] = self::backticked($m[2]);

                continue;
            }
            if (preg_match('/^KEY `([^`]+)` \((.+)\)$/i', $line, $m)) {
                $spec['index'][$m[1]] = self::backticked($m[2]);

                continue;
            }
            if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY \((.+?)\) REFERENCES `([^`]+)` \((.+?)\)(.*)$/i', $line, $m)) {
                $tail = trim($m[5]);
                $spec['foreign'][$m[1]] = [
                    'columns'    => self::backticked($m[2]),
                    'on'         => $m[3],
                    'references' => self::backticked($m[4]),
                    'on_delete'  => preg_match('/ON DELETE (CASCADE|SET NULL|RESTRICT|NO ACTION)/i', $tail, $d) ? strtoupper($d[1]) : null,
                    'on_update'  => preg_match('/ON UPDATE (CASCADE|SET NULL|RESTRICT|NO ACTION)/i', $tail, $u) ? strtoupper($u[1]) : null,
                ];

                continue;
            }
            if (preg_match('/^CONSTRAINT `([^`]+)` CHECK \((.+)\)$/i', $line, $m)) {
                $spec['check'][$m[1]] = $m[2];

                continue;
            }
            if (preg_match('/^`([^`]+)` (.+)$/', $line, $m)) {
                $name = $m[1];
                $rest = $m[2];

                // A JSON column's `json_valid` CHECK is rendered in two different places by two
                // server versions of the SAME structure. MariaDB 11.4.9 -- which the production
                // measurement came from -- emits it as its own `CONSTRAINT <table>_chk_N CHECK (...)`
                // line, handled above. MariaDB 10.11 appends it INLINE to the column:
                //
                //   `old_values` longtext ... DEFAULT NULL CHECK (json_valid(`old_values`))
                //
                // Left unhandled, the inline form lands on the end of the column's TYPE and the
                // check goes unrecorded -- which reported eight differences between a schema and
                // itself the first time this ran. Both spellings now produce the same spec.
                if (preg_match('/\s+CHECK \((.+)\)$/i', $rest, $inline)) {
                    $spec['check']['inline_' . $name] = $inline[1];
                    $rest = substr($rest, 0, -strlen($inline[0]));
                }

                $spec['columns'][$name] = self::parseColumn($rest);

                continue;
            }
        }

        // Resolve inherited charset/collation: SHOW CREATE TABLE prints a column's only when it
        // differs from the table's, while the measured dump printed every one.
        foreach ($spec['columns'] as $name => $col) {
            if (self::typeCarriesCollation($col['type'])) {
                $collation = $col['collation'] ?? $spec['collation'];
                $spec['columns'][$name]['collation'] = $collation;
                $spec['columns'][$name]['charset']   = $col['charset']
                    ?? ($collation !== null ? strtok($collation, '_') : $spec['charset']);
            } else {
                $spec['columns'][$name]['collation'] = null;
                $spec['columns'][$name]['charset']   = null;
            }
        }

        return $spec;
    }

    /** @return array<string,mixed> */
    private static function parseColumn(string $rest): array
    {
        $comment = null;
        if (preg_match("/ COMMENT '((?:[^'\\\\]|\\\\.|'')*)'$/", $rest, $m)) {
            $comment = $m[1];
            $rest    = substr($rest, 0, -strlen($m[0]));
        }

        $auto = (bool) preg_match('/\bAUTO_INCREMENT\b/i', $rest);
        $rest = preg_replace('/\s*AUTO_INCREMENT\b/i', '', $rest);

        // `current_timestamp()` is MariaDB 10.x's spelling of `CURRENT_TIMESTAMP`. The optional
        // `()` must be part of the SAME branch: an alternation that tries the bare spelling first
        // matches it, leaves the `()` behind, and the leftover lands on the end of the type -- which
        // is how `timestamp` became `timestamp()()` the first time this ran.
        $current = 'current_timestamp(?:\s*\(\s*\))?';

        $onUpdateCurrent = (bool) preg_match("/ON UPDATE {$current}/i", $rest);
        $rest = preg_replace("/\s*ON UPDATE {$current}/i", '', $rest);

        $default    = null;
        $hasDefault = false;
        if (preg_match("/ DEFAULT (NULL|{$current}|'(?:[^']|'')*'|[-\\d.]+)/i", $rest, $m)) {
            $hasDefault = true;
            $default    = self::normaliseDefault($m[1]);
            $rest       = str_replace($m[0], '', $rest);
        }

        $nullable = true;
        if (preg_match('/\bNOT NULL\b/i', $rest)) {
            $nullable = false;
            $rest     = preg_replace('/\s*NOT NULL/i', '', $rest);
        } else {
            $rest = preg_replace('/\s*(?<!NOT )\bNULL\b/i', '', $rest);
        }

        // An implicitly nullable column's default IS NULL, whether or not the DDL spells it out.
        // The measured dump writes `payment_details text COLLATE utf8mb4_general_ci,` with neither
        // NULL nor DEFAULT, while `SHOW CREATE TABLE` writes `... DEFAULT NULL` for the same
        // column. Resolving the implicit case here keeps that from reading as a difference.
        if (! $hasDefault && $nullable) {
            $default = 'NULL';
        }

        $collation = preg_match('/COLLATE (\w+)/i', $rest, $m) ? $m[1] : null;
        $charset   = preg_match('/CHARACTER SET (\w+)/i', $rest, $m) ? $m[1] : null;
        $rest      = preg_replace('/\s*(COLLATE \w+|CHARACTER SET \w+)/i', '', $rest);

        return [
            'type'              => self::normaliseType(trim($rest)),
            'nullable'          => $nullable,
            'default'           => $default,
            'auto_increment'    => $auto,
            'on_update_current' => $onUpdateCurrent,
            'collation'         => $collation,
            'charset'           => $charset,
            'comment'           => $comment,                 // recorded, deliberately not compared
        ];
    }

    /**
     * Strip integer display widths. Deprecated metadata that constrains nothing; the measurement
     * came from MariaDB 11.4.9 which omits it, and MariaDB 10.x prints it.
     */
    private static function normaliseType(string $type): string
    {
        $type = strtolower(trim($type));

        return preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type);
    }

    private static function normaliseDefault(string $default): string
    {
        $default = trim($default);

        if (strcasecmp($default, 'NULL') === 0) {
            return 'NULL';
        }
        if (preg_match('/^current_timestamp(\(\))?$/i', $default)) {
            return 'CURRENT_TIMESTAMP';
        }
        if (strlen($default) >= 2 && $default[0] === "'" && substr($default, -1) === "'") {
            $default = str_replace("''", "'", substr($default, 1, -1));
        }

        // 0.00 and '0.00' are the same default; so are 1 and '1'.
        return is_numeric($default) ? rtrim(rtrim(number_format((float) $default, 8, '.', ''), '0'), '.') ?: '0' : $default;
    }

    private static function typeCarriesCollation(string $type): bool
    {
        return (bool) preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b/i', $type);
    }

    /** @return list<string> */
    private static function backticked(string $s): array
    {
        preg_match_all('/`([^`]+)`/', $s, $m);

        return $m[1];
    }
}
