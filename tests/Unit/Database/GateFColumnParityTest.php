<?php

namespace Tests\Unit\Database;

use App\Support\Database\GateFProductionSchema;
use PHPUnit\Framework\TestCase;

/**
 * THE THREE COLUMNS THIS REPOSITORY WAS WRONG ABOUT.
 *
 * `GateFSchemaGuard` compares a whole `CREATE TABLE` against a measured one, and `products` and
 * `delivery_boys` are not GATE F tables -- they have had migrations since 2023 and most of their
 * columns are none of this work's business. So the three columns GATE F adds to them get their own
 * check here, derived from `GateFProductionSchema::COLUMN_DDL`, which is a verbatim copy of
 * `docs/GATE-F-VERIFIED-SCHEMA.md` section 2.
 *
 * WHY THESE THREE DESERVE A TEST OF THEIR OWN. Each was the subject of a CONFIDENT FALSE CLAIM in
 * this repository, inferred from the migrations because no backup had been restored:
 *
 *   | the claim, as it was written                                            | what the dump showed |
 *   |-------------------------------------------------------------------------|----------------------|
 *   | "`delivery_boys` lacks `commission_percentage` and `is_commission_enabled` … so those handlers 500 for everyone" | both exist, across 2 rows, both with a non-zero percentage and one with the flag set |
 *   | "`products.cost_price` does not exist"                                   | it exists, `decimal(15,2) NOT NULL DEFAULT 0.00`, populated on all 45 products |
 *
 * The mechanism behind the error is worth more than the error: the repository's migrations are a
 * complete description of what THIS CODEBASE creates, and were read as a complete description of
 * what PRODUCTION has. For a schema partly applied by hand those are different questions, and no
 * amount of reading the repository answers the second.
 *
 * A PURE FILESYSTEM TEST: no application, no database, no connection.
 */
class GateFColumnParityTest extends TestCase
{
    private const COMMISSION_MIGRATION = 'database/migrations/2026_11_02_000500_add_commission_columns_to_delivery_boys_table.php';

    private const COST_PRICE_MIGRATION = 'database/migrations/2026_10_26_000100_add_cost_price_to_products_table.php';

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The measured column lines, parsed from `COLUMN_DDL`.
     *
     * Each line is `<table>.<column> <type> <NULL|NOT NULL> DEFAULT <value>`.
     *
     * @return array<string, array{type:string,nullable:bool,default:string}>
     */
    private function measuredColumns(): array
    {
        $out = [];

        foreach (preg_split('/\R/', GateFProductionSchema::COLUMN_DDL) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $this->assertSame(
                1,
                preg_match('/^(\S+)\s+(.+?)\s+(NOT NULL|NULL)\s+DEFAULT\s+(\S+)$/', $line, $m),
                "Could not parse the measured column line: {$line}"
            );

            $out[$m[1]] = ['type' => $m[2], 'nullable' => $m[3] === 'NULL', 'default' => $m[4]];
        }

        return $out;
    }

    public function test_the_measurement_names_exactly_the_three_columns(): void
    {
        $this->assertSame(
            [
                'delivery_boys.commission_percentage',
                'delivery_boys.is_commission_enabled',
                'products.cost_price',
            ],
            array_keys($this->measuredColumns()),
            'The measured column set has changed. These three are the ones this repository stated '
            . 'were absent and the restored dump found present, so the set is a correction and not '
            . 'a convenience.'
        );
    }

    /**
     * `commission_percentage` is `decimal(5,2)`, NULLABLE, DEFAULT 0.00.
     *
     * Nullable WITH a non-null default is unusual and is reproduced exactly, because production is
     * the authority and a handler may already distinguish "never set" from "set to zero".
     */
    public function test_the_commission_percentage_column_matches_the_measurement(): void
    {
        $measured = $this->measuredColumns()['delivery_boys.commission_percentage'];

        $this->assertSame('decimal(5,2)', $measured['type']);
        $this->assertTrue($measured['nullable'], 'The measurement says NULL.');
        $this->assertSame('0.00', $measured['default']);

        $source = $this->read(self::COMMISSION_MIGRATION);

        $this->assertStringContainsString(
            "\$table->decimal('commission_percentage', 5, 2)->nullable()->default(0.00);",
            $source,
            'The migration does not declare commission_percentage as the measured '
            . 'decimal(5,2) NULL DEFAULT 0.00. A narrower scale truncates a percentage; a wider '
            . 'one disagrees with production.'
        );
    }

    /** `is_commission_enabled` is `tinyint(1)`, NOT NULL, DEFAULT 0. */
    public function test_the_commission_flag_column_matches_the_measurement(): void
    {
        $measured = $this->measuredColumns()['delivery_boys.is_commission_enabled'];

        $this->assertSame('tinyint(1)', $measured['type']);
        $this->assertFalse($measured['nullable'], 'The measurement says NOT NULL.');
        $this->assertSame('0', $measured['default']);

        $this->assertStringContainsString(
            "\$table->boolean('is_commission_enabled')->default(false);",
            $this->read(self::COMMISSION_MIGRATION),
            'The migration does not declare is_commission_enabled as the measured tinyint(1) '
            . 'NOT NULL DEFAULT 0. A nullable flag gives driver commission a third state nothing '
            . 'handles.'
        );
    }

    /**
     * `products.cost_price` is `decimal(15,2)`, NOT NULL, DEFAULT 0.00.
     *
     * Its migration was promoted on 2026-10-26 under D-01, ahead of the rest of GATE F, because
     * this column alone was read off the production schema first -- which made its `hasColumn()`
     * guard a genuine no-op rather than a trap. It is re-checked here against the same measurement
     * the other fourteen use, so the early promotion does not sit outside the ratchet.
     */
    public function test_the_cost_price_column_matches_the_measurement(): void
    {
        $measured = $this->measuredColumns()['products.cost_price'];

        $this->assertSame('decimal(15,2)', $measured['type']);
        $this->assertFalse($measured['nullable'], 'The measurement says NOT NULL.');
        $this->assertSame('0.00', $measured['default']);

        $source = $this->read(self::COST_PRICE_MIGRATION);

        $this->assertMatchesRegularExpression(
            "/decimal\(\s*'cost_price'\s*,\s*15\s*,\s*2\s*\)/",
            $source,
            'The cost_price migration does not declare decimal(15,2). The scale is load-bearing: '
            . 'OrderFinancialService computes product_cost from this column and writes it into '
            . 'store_order_financials.product_cost, which is also decimal(15,2) -- a mismatch '
            . 'rounds every P&L row.'
        );
    }

    /**
     * Neither column migration may drop anything on rollback.
     *
     * MEASURED, NOT REASONED. Both of these files' ancestors guarded `up()` on `hasColumn()` --
     * returning early when the column already existed -- and then dropped the column in `down()`
     * BECAUSE it existed. Against a copy of production, `migrate:rollback` executed them in 30.28ms
     * and 46.82ms and destroyed all 45 `cost_price` values (summing 1170.65) and both
     * `delivery_boys` commission columns with their data, having created nothing.
     */
    public function test_neither_column_migration_drops_anything_on_rollback(): void
    {
        foreach ([self::COMMISSION_MIGRATION, self::COST_PRICE_MIGRATION] as $relative) {
            $code = $this->codeOnly($this->read($relative));

            foreach (['dropColumn', 'dropIfExists', 'Schema::drop'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    "{$relative} calls {$needle}(). Both of these columns exist in production WITH "
                    . 'LIVE DATA, and an up() that creates nothing paired with a down() that drops '
                    . 'regardless is not a rollback -- it is data loss behind a routine-looking '
                    . 'command. This exact pairing was measured destroying production data.'
                );
            }
        }
    }

    private function read(string $relative): string
    {
        $path = $this->basePath() . '/' . $relative;

        $this->assertFileExists(
            $path,
            "{$relative} is gone. A fresh environment then lacks the column, and the handler that "
            . 'reads it fails on first use.'
        );

        return (string) file_get_contents($path);
    }

    /** Blank out comments so a ratchet is not tripped by prose that explains the hazard. */
    private function codeOnly(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat(' ', strlen($token[1]))
                    : $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
