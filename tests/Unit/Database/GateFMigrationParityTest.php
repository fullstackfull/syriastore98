<?php

namespace Tests\Unit\Database;

use App\Support\Database\GateFProductionSchema;
use App\Support\Database\GateFSchemaGuard;
use PHPUnit\Framework\TestCase;

/**
 * EACH GATE F MIGRATION STILL DECLARES WHAT THE MEASUREMENT SAYS.
 *
 * WHAT THIS CATCHES, AND WHAT IT DOES NOT. The 15 migrations were written FROM
 * `GateFSchemaGuard::blueprintFor()`, so this is not an independent re-derivation and does not
 * pretend to be. It catches a HAND EDIT: a money scale widened, a unique key deleted, a foreign key
 * renamed or an enum loosened directly in a migration file. Every one of those has happened in this
 * project's drafts, and every one would otherwise go unnoticed until a fresh environment had the
 * wrong schema -- and then silently, because the migration is a `hasTable()` no-op against
 * production.
 *
 * THE INDEPENDENT CHECK IS ELSEWHERE, and the two are complementary rather than redundant:
 *
 *   - `tests/Support/Security/bin/verify-gate-f-migrations-on-mysql.php` runs the migrations on a
 *     real MariaDB and compares the resulting `SHOW CREATE TABLE` against the measurement. That
 *     answers "does the schema this produces match production", by execution.
 *   - this test answers "does the migration still SAY what the measurement says", by text, in
 *     every environment including CI on SQLite where the first question cannot be asked.
 *
 * A PURE FILESYSTEM TEST: no application, no database, no connection.
 */
class GateFMigrationParityTest extends TestCase
{
    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    /** The one discoverable root migration that creates `$table`, or null. */
    private function migrationFor(string $table): ?string
    {
        $pattern = '/Schema::\s*create(?:IfNotExists)?\s*\(\s*[\'"]' . preg_quote($table, '/') . '[\'"]/i';

        foreach (glob($this->basePath() . '/database/migrations/*.php') ?: [] as $file) {
            if (preg_match($pattern, (string) file_get_contents($file))) {
                return $file;
            }
        }

        return null;
    }

    public function test_the_scan_is_not_vacuous(): void
    {
        $this->assertGreaterThan(
            100,
            count(glob($this->basePath() . '/database/migrations/*.php') ?: []),
            'The migration glob found almost nothing, so every assertion below would pass while '
            . 'checking nothing.'
        );

        $this->assertCount(
            15,
            GateFProductionSchema::tableNames(),
            'GATE F covers 15 tables. A different count means the measured set has changed and '
            . 'this test is no longer checking what it claims to.'
        );
    }

    /**
     * Every measured table has exactly one migration, and that migration declares every column,
     * key and foreign key the measurement names, with the measured types.
     */
    public function test_every_gate_f_migration_declares_the_measured_structure(): void
    {
        $problems = [];

        foreach (GateFProductionSchema::tableNames() as $table) {
            $file = $this->migrationFor($table);

            if ($file === null) {
                $problems[] = "{$table}: no discoverable root migration creates it";

                continue;
            }

            $relative  = str_replace($this->basePath() . '/', '', $file);
            $source    = (string) file_get_contents($file);
            $blueprint = GateFSchemaGuard::blueprintFor(GateFProductionSchema::ddlFor($table));

            foreach ($blueprint['columns'] as $statement) {
                if (! str_contains($source, $statement)) {
                    $problems[] = "{$relative} does not declare: {$statement}";
                }
            }

            foreach ($blueprint['keys'] as $statement) {
                if (! str_contains($source, $statement)) {
                    $problems[] = "{$relative} does not declare: {$statement}"
                        . (str_starts_with($statement, '$table->unique')
                            ? '   <-- a UNIQUE KEY is an integrity guarantee, not an optimisation'
                            : '');
                }
            }

            foreach ($blueprint['foreign'] as $statement) {
                if (! str_contains($source, $statement)) {
                    $problems[] = "{$relative} does not declare: {$statement}"
                        . '   <-- the ON DELETE rule is what stops a deleted parent leaving orphan rows';
                }
            }

            foreach (['charset' => $blueprint['charset'], 'collation' => $blueprint['collation']] as $property => $value) {
                if ($value !== null && ! str_contains($source, "\$table->{$property}")) {
                    $problems[] = "{$relative} never sets \$table->{$property}, so the application "
                        . "default is substituted for the measured '{$value}'";
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            "A GATE F migration has drifted from the measured production structure:\n  "
            . implode("\n  ", $problems)
            . "\n\nTHE MEASUREMENT IS THE AUTHORITY -- docs/GATE-F-VERIFIED-SCHEMA.md section 2, "
            . 'carried verbatim by App\\Support\\Database\\GateFProductionSchema. Do not edit a '
            . 'migration to improve on production: widening a live money column is a separate, '
            . 'deliberate change with its own rounding consequences for the rows already stored.'
        );
    }

    /**
     * The derivation itself must not be vacuous: a blueprint with no unique keys and no foreign
     * keys would make the assertion above pass trivially.
     *
     * The totals are the document's own: 3 unique keys and 12 foreign keys across the six money
     * tables, 8 and 16 across all fifteen.
     */
    public function test_the_derived_blueprints_carry_the_measured_key_counts(): void
    {
        $uniqueAll = $foreignAll = $uniqueMoney = $foreignMoney = 0;

        foreach (GateFProductionSchema::tableNames() as $table) {
            $blueprint = GateFSchemaGuard::blueprintFor(GateFProductionSchema::ddlFor($table));

            $unique  = count(array_filter($blueprint['keys'], static fn ($s) => str_starts_with($s, '$table->unique')));
            $foreign = count($blueprint['foreign']);

            $uniqueAll  += $unique;
            $foreignAll += $foreign;

            if (GateFProductionSchema::isMoneyTable($table)) {
                $uniqueMoney  += $unique;
                $foreignMoney += $foreign;
            }
        }

        $this->assertSame(
            [3, 12],
            [$uniqueMoney, $foreignMoney],
            'The six money tables must derive 3 unique keys and 12 foreign keys, which is what '
            . 'docs/GATE-F-VERIFIED-SCHEMA.md section 3 measured. The original drafts declared '
            . 'NONE of either, on any of the six.'
        );

        $this->assertSame(
            [8, 16],
            [$uniqueAll, $foreignAll],
            'Across all fifteen tables the measurement has 8 unique keys and 16 foreign keys.'
        );
    }

    /**
     * The three unique keys that turn an asserted property into an enforced one must be present by
     * name, because code and future migrations refer to these names.
     */
    public function test_the_three_integrity_unique_keys_are_declared_by_name(): void
    {
        $expected = [
            'store_order_financials' => 'uq_store_order_financial_order',
            'store_wallets'          => 'uq_store_wallet_store_id',
            'currency_rates'         => 'uq_currency_rates_store_code',
        ];

        foreach ($expected as $table => $keyName) {
            $file = $this->migrationFor($table);

            $this->assertNotNull($file, "No migration creates `{$table}`.");
            $this->assertStringContainsString(
                "'{$keyName}'",
                (string) file_get_contents($file),
                "`{$keyName}` is missing from the migration for `{$table}`. "
                . match ($keyName) {
                    'uq_store_order_financial_order' => 'It is what makes "one financial row per '
                        . 'order" -- i.e. NO DUPLICATE FINANCIAL ENTRIES -- an enforced guarantee '
                        . 'rather than an observation about today\'s rows.',
                    'uq_store_wallet_store_id' => 'It is what makes "one wallet per store" true.',
                    default => 'It is what makes "one rate per currency per store" true.',
                }
            );
        }
    }
}
