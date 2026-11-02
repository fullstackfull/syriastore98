<?php

namespace Tests\Unit\Database;

use App\Support\Database\GateFProductionSchema;
use App\Support\Database\GateFSchemaGuard;
use App\Support\Database\GateFSchemaMismatch;
use PHPUnit\Framework\TestCase;

/**
 * THE STRUCTURAL GUARD BITES -- PROVED IN CI, NOT ONLY ON A MySQL SERVER.
 *
 * `tests/Support/Security/bin/verify-gate-f-migrations-on-mysql.php` proves this against a real
 * MariaDB, and that is the stronger evidence. But it needs a server, so it does not run in CI --
 * which would leave the guard's own behaviour unverified in the one place every change passes
 * through.
 *
 * So this drives the guard's parser and comparison over DDL STRINGS, in memory, with no database at
 * all. Each case takes the measured production DDL, corrupts it the way this project's own drafts
 * did, and asserts the comparison reports it by name. A guard nobody has watched fail is not
 * evidence.
 *
 * ON THE NORMALISATIONS. Four differences are deliberately invisible to the comparison -- integer
 * display widths, inherited charset/collation, default-literal spelling, and the AUTO_INCREMENT
 * counter -- because each is a formatting or server-version artefact rather than structure. Those
 * are asserted too, in the other direction: a DDL that differs ONLY in those ways must compare
 * EQUAL, or the guard would cry wolf on every MariaDB 10.x server and get suppressed.
 */
class GateFSchemaGuardTest extends TestCase
{
    /** The guard's own comparison, driven directly: the public entry points need a connection. */
    private function differences(string $expectedDdl, string $actualDdl): array
    {
        $compare = new \ReflectionMethod(GateFSchemaGuard::class, 'compareDeep');
        $compare->setAccessible(true);

        return $compare->invoke(
            null,
            GateFSchemaGuard::parse($expectedDdl),
            GateFSchemaGuard::parse($actualDdl)
        );
    }

    public function test_the_measured_ddl_compares_equal_to_itself(): void
    {
        foreach (GateFProductionSchema::tableNames() as $table) {
            $ddl = GateFProductionSchema::ddlFor($table);

            $this->assertSame(
                [],
                $this->differences($ddl, $ddl),
                "`{$table}` does not compare equal to itself, so the parser is losing or inventing "
                . 'something and every comparison built on it is unreliable.'
            );
        }
    }

    /**
     * The four normalisations, each on the real measured DDL.
     *
     * The measurement came from MariaDB 11.4.9. A 10.x server renders the SAME structure with
     * integer display widths, `current_timestamp()` instead of `CURRENT_TIMESTAMP`, inherited
     * column collations omitted, and a different AUTO_INCREMENT counter. All four must be
     * invisible.
     */
    public function test_server_version_formatting_is_not_reported_as_a_difference(): void
    {
        $ddl = GateFProductionSchema::ddlFor('audit_logs');

        $asMariaDb10x = str_replace(
            [
                'bigint unsigned',
                'DEFAULT CURRENT_TIMESTAMP',
                'ON UPDATE CURRENT_TIMESTAMP',
                'AUTO_INCREMENT=30',
            ],
            [
                'bigint(20) unsigned',
                'DEFAULT current_timestamp()',
                'ON UPDATE current_timestamp()',
                'AUTO_INCREMENT=9999',
            ],
            $ddl
        );

        $this->assertNotSame($ddl, $asMariaDb10x, 'The rewrite did nothing, so this proves nothing.');

        $this->assertSame(
            [],
            $this->differences($ddl, $asMariaDb10x),
            'A MariaDB 10.x rendering of the SAME structure is reported as different. The guard '
            . 'would then fail on every 10.x server and be suppressed, which is how a real '
            . 'mismatch gets through later.'
        );
    }

    /** An inherited column collation, which `SHOW CREATE TABLE` omits, must still compare equal. */
    public function test_an_omitted_inherited_collation_is_not_a_difference(): void
    {
        $ddl = GateFProductionSchema::ddlFor('store_wallets');

        // The table is utf8mb4_general_ci, so a server omits the matching column collation.
        $omitted = str_replace(' COLLATE utf8mb4_general_ci DEFAULT NULL', ' DEFAULT NULL', $ddl);

        $this->assertNotSame($ddl, $omitted, 'The rewrite did nothing, so this proves nothing.');
        $this->assertSame([], $this->differences($ddl, $omitted));
    }

    /**
     * A NON-inherited collation is a real difference and must still be caught.
     *
     * This is the other half of the test above: the resolution must not be so eager that
     * `utf8mb4_bin` on a JSON column becomes invisible too.
     */
    public function test_a_genuinely_different_collation_is_still_reported(): void
    {
        $ddl = GateFProductionSchema::ddlFor('audit_logs');
        $changed = str_replace('COLLATE utf8mb4_bin', 'COLLATE utf8mb4_general_ci', $ddl);

        $this->assertNotSame($ddl, $changed);

        $differences = $this->differences($ddl, $changed);

        $this->assertNotEmpty($differences, 'A changed column collation was accepted.');
        $this->assertStringContainsString('collation', implode(' ', $differences));
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:string}>
     *   table, search, replace, the fragment the report must contain
     */
    public static function corruptions(): array
    {
        return [
            'a money scale widened to the superseded decimal(18,4)' => [
                'store_order_financials',
                "`product_amount` decimal(15,2) NOT NULL DEFAULT '0.00'",
                "`product_amount` decimal(18,4) NOT NULL DEFAULT '0.0000'",
                'decimal(18,4)',
            ],
            'the unique key behind one-financial-row-per-order removed' => [
                'store_order_financials',
                "  UNIQUE KEY `uq_store_order_financial_order` (`order_id`),\n",
                '',
                'uq_store_order_financial_order',
            ],
            'the one-wallet-per-store unique key removed' => [
                'store_wallets',
                "  UNIQUE KEY `uq_store_wallet_store_id` (`store_id`),\n",
                '',
                'uq_store_wallet_store_id',
            ],
            'a foreign key removed, losing its ON DELETE rule' => [
                'store_wallet_transactions',
                "  CONSTRAINT `fk_wallet_transactions_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `store_wallets` (`id`) ON DELETE CASCADE",
                '  KEY `placeholder_after_fk_removal` (`wallet_id`)',
                'fk_wallet_transactions_wallet',
            ],
            "the ledger's credit/debit enum turned into nullable free text" => [
                'store_wallet_transactions',
                "`direction` enum('credit','debit') COLLATE utf8mb4_general_ci NOT NULL",
                '`direction` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL',
                'direction',
            ],
            'an FX multiplier defaulting to zero' => [
                'currency_rates',
                "`rate_per_usd` decimal(20,8) NOT NULL DEFAULT '1.00000000'",
                "`rate_per_usd` decimal(20,8) NOT NULL DEFAULT '0.00000000'",
                'rate_per_usd',
            ],
            'ON DELETE CASCADE weakened to no action' => [
                'store_wallets',
                'ON DELETE CASCADE',
                '',
                'on_delete',
            ],
            'a column dropped' => [
                'store_wallets',
                "  `total_withdrawn` decimal(15,2) NOT NULL DEFAULT '0.00',\n",
                '',
                'total_withdrawn',
            ],
            'a nullable money column made NOT NULL' => [
                'store_withdrawals',
                '`payment_method` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL',
                '`payment_method` varchar(100) COLLATE utf8mb4_general_ci NOT NULL',
                'nullable',
            ],
            "a json_valid CHECK removed, so the column stops being JSON" => [
                'zid_events',
                "  CONSTRAINT `zid_events_chk_1` CHECK (json_valid(`payload`))\n",
                '',
                'CHECK',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corruptions')]
    public function test_a_corruption_is_refused_and_named(
        string $table,
        string $search,
        string $replace,
        string $mustMention
    ): void {
        $expected  = GateFProductionSchema::ddlFor($table);
        $corrupted = str_replace($search, $replace, $expected);

        $this->assertNotSame(
            $expected,
            $corrupted,
            'The corruption did not apply, so this case proves nothing. The measured DDL has '
            . 'probably been reformatted; update the search string against '
            . 'docs/GATE-F-VERIFIED-SCHEMA.md.'
        );

        $differences = $this->differences($expected, $corrupted);

        $this->assertNotEmpty(
            $differences,
            "The guard ACCEPTED this corruption of `{$table}`. It does not bite, and every GATE F "
            . 'migration is relying on it to refuse an incompatible schema rather than silently '
            . 'no-op over one.'
        );

        $this->assertStringContainsString(
            $mustMention,
            implode("\n", $differences),
            'The guard reported a difference but did not name ' . $mustMention
            . ', so the report is not actionable: "incompatible" is not a fix and '
            . '"decimal(18,4) where production has decimal(15,2)" is.'
        );
    }

    /**
     * The exception says which table, how many disagreements, and -- for a money table -- why
     * accepting one silently would matter.
     */
    public function test_the_mismatch_exception_names_the_table_and_the_stakes(): void
    {
        $exception = new GateFSchemaMismatch(
            'store_order_financials',
            ['column `product_amount` type: production has `decimal(15,2)`, the live table has `decimal(18,4)`'],
            ['deep structural comparison']
        );

        $message = $exception->getMessage();

        $this->assertStringContainsString('store_order_financials', $message);
        $this->assertStringContainsString('decimal(18,4)', $message);
        $this->assertStringContainsString('MONEY-BEARING', $message);
        $this->assertStringContainsString('1 disagreement', $message);
        $this->assertStringContainsString('THE PRODUCTION STRUCTURE IS THE AUTHORITY', $message);

        $this->assertSame('store_order_financials', $exception->table);
        $this->assertCount(1, $exception->differences);
    }

    /** A non-money table gets the plainer message, and says nothing was altered. */
    public function test_a_non_money_mismatch_states_that_nothing_was_changed(): void
    {
        $message = (new GateFSchemaMismatch('zid_events', ['column `payload` is MISSING from the live table']))
            ->getMessage();

        $this->assertStringContainsString('zid_events', $message);
        $this->assertStringContainsString('was NOT recorded', $message);
        $this->assertStringNotContainsString('MONEY-BEARING', $message);
    }
}
