<?php

namespace Tests\Unit\Database;

use App\Support\Database\GateFProductionSchema;
use PHPUnit\Framework\TestCase;

/**
 * THE DOCUMENT IS THE AUTHORITY, AND THIS IS WHAT KEEPS IT SO.
 *
 * `docs/GATE-F-VERIFIED-SCHEMA.md` section 2 is the measurement: `SHOW CREATE TABLE` for the 15
 * SCHEMA-01 tables and the three columns, generated from `information_schema` on a RESTORED COPY of
 * the production dump on 2026-10-24 (MariaDB 11.4.9). `App\Support\Database\GateFProductionSchema`
 * carries those statements verbatim, because PHP code is what a migration and a runtime guard can
 * read.
 *
 * TWO COPIES OF A MEASUREMENT IS A DRIFT HAZARD, so this test asserts they are the same, IN BOTH
 * DIRECTIONS: no table in the document missing from the class, no table in the class absent from
 * the document, and every DDL string byte-identical. An edit to either one without the other fails
 * here.
 *
 * WHY THAT MATTERS MORE THAN IT SOUNDS. The class is what the migrations are derived from and what
 * `GateFSchemaGuard` compares a live table against. If somebody "tidied" a type in the class, every
 * downstream check -- the migration parity test, the structural guard, the MySQL verification --
 * would agree with the tidied value and the production structure would be the only dissenter, with
 * nothing reading it. The document is the only link back to the measurement, so the link is
 * asserted rather than trusted.
 *
 * A PURE FILESYSTEM TEST: no application, no database, no connection.
 */
class GateFProductionSchemaFidelityTest extends TestCase
{
    private const DOC = 'docs/GATE-F-VERIFIED-SCHEMA.md';

    private const COLUMNS_HEADING = 'products.cost_price / delivery_boys commission columns';

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The document's measured blocks, keyed by the heading each one carries.
     *
     * Section 2 holds one fenced block whose entries are separated by `----- <name>` lines.
     *
     * @return array<string, string>
     */
    private function documentBlocks(): array
    {
        $path = $this->basePath() . '/' . self::DOC;

        $this->assertFileExists(
            $path,
            self::DOC . ' is gone. It is the only record of the measurement the GATE F migrations, '
            . 'the runtime guard and the MySQL verification all depend on.'
        );

        $markdown = (string) file_get_contents($path);

        $this->assertSame(
            1,
            preg_match('/```\n(----- .*?)\n```/s', $markdown, $m),
            'Could not find the fenced measured-DDL block in ' . self::DOC . ' section 2. Either '
            . 'the document was restructured or the block was removed; both need a deliberate '
            . 'decision, because this is the measurement.'
        );

        $parts = preg_split('/^----- (.+)$/m', $m[1], -1, PREG_SPLIT_DELIM_CAPTURE);
        array_shift($parts);                                  // the leading empty segment

        $blocks = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $blocks[trim($parts[$i])] = trim($parts[$i + 1]);
        }

        return $blocks;
    }

    public function test_the_document_block_scan_is_not_vacuous(): void
    {
        $blocks = $this->documentBlocks();

        // 15 tables plus the columns block. If the parse silently matched nothing, every
        // assertion below would pass while comparing empty sets.
        $this->assertCount(
            16,
            $blocks,
            'Expected 15 measured tables plus the columns block in ' . self::DOC
            . ', and the parse found ' . count($blocks) . ': ' . implode(', ', array_keys($blocks))
        );

        $this->assertArrayHasKey(self::COLUMNS_HEADING, $blocks);
    }

    public function test_every_measured_table_in_the_document_is_carried_by_the_schema_class(): void
    {
        $blocks = $this->documentBlocks();
        unset($blocks[self::COLUMNS_HEADING]);

        $missing = array_diff(array_keys($blocks), GateFProductionSchema::tableNames());

        $this->assertSame(
            [],
            array_values($missing),
            "These tables are measured in " . self::DOC . " but are NOT in GateFProductionSchema::DDL:\n  "
            . implode("\n  ", $missing)
            . "\n\nA measured table with no entry in the class has no migration derived from it and "
            . 'no guard comparing against it, so a fresh environment silently lacks it.'
        );
    }

    public function test_every_table_in_the_schema_class_is_measured_in_the_document(): void
    {
        $blocks = $this->documentBlocks();
        unset($blocks[self::COLUMNS_HEADING]);

        $invented = array_diff(GateFProductionSchema::tableNames(), array_keys($blocks));

        $this->assertSame(
            [],
            array_values($invented),
            "These tables are in GateFProductionSchema::DDL but are NOT measured in " . self::DOC . ":\n  "
            . implode("\n  ", $invented)
            . "\n\nAn entry with no measurement behind it is a GUESS, which is the one thing GATE F "
            . 'exists to forbid. Measure it on a restored copy and record it in the document first.'
        );
    }

    public function test_every_carried_ddl_is_byte_identical_to_the_document(): void
    {
        $blocks = $this->documentBlocks();

        foreach (GateFProductionSchema::tableNames() as $table) {
            $this->assertSame(
                $blocks[$table],
                GateFProductionSchema::ddlFor($table),
                "GateFProductionSchema::DDL['{$table}'] has drifted from " . self::DOC . ".\n\n"
                . "THE DOCUMENT WINS. It is the measurement; the class is a copy of it. If the "
                . 'production structure has genuinely changed, re-measure it on a RESTORED COPY, '
                . 'update the document, and let the class follow -- never the other way round. '
                . 'Altering a live money column is a separate, deliberate change with its own '
                . 'rounding consequences for the rows already stored.'
            );
        }
    }

    public function test_the_three_measured_columns_are_carried_verbatim(): void
    {
        $blocks = $this->documentBlocks();

        $this->assertSame(
            $blocks[self::COLUMNS_HEADING],
            GateFProductionSchema::COLUMN_DDL,
            'GateFProductionSchema::COLUMN_DDL has drifted from ' . self::DOC . '. These three '
            . 'columns are the ones this repository was WRONG about: it stated that '
            . '`delivery_boys` lacks both commission columns and that `products.cost_price` does '
            . 'not exist, and the restored dump refuted all three. The measured values are the '
            . 'correction, so they are not to be re-derived from the models.'
        );
    }

    public function test_the_six_money_tables_are_named_and_are_the_measured_ones(): void
    {
        $this->assertSame(
            [
                'store_wallets',
                'store_wallet_transactions',
                'store_withdrawals',
                'store_order_financials',
                'delivery_earnings',
                'currency_rates',
            ],
            GateFProductionSchema::MONEY_TABLES,
            'The money-bearing set has changed. It is the set whose decimal precision must not be '
            . 'guessed and whose migrations get the loudest failure from GateFSchemaMismatch, so a '
            . 'table leaving this list quietly downgrades how its schema is treated.'
        );

        foreach (GateFProductionSchema::MONEY_TABLES as $table) {
            $this->assertTrue(
                GateFProductionSchema::isMoneyTable($table),
                "isMoneyTable('{$table}') disagrees with MONEY_TABLES."
            );
            $this->assertContains(
                $table,
                GateFProductionSchema::tableNames(),
                "`{$table}` is listed as money-bearing but has no measured DDL."
            );
        }
    }

    public function test_an_unknown_table_is_refused_rather_than_answered_with_nothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GateFProductionSchema::ddlFor('store_wallets_archive');
    }
}
