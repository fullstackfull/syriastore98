<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * THE ONE GATE F OBJECT THAT MUST STAY ABSENT.
 *
 * `cod_collection_events` is the only one of the nine parked drafts that does **not** correspond to
 * an object production has. `docs/GATE-F-VERIFIED-SCHEMA.md` §5 measured that: the table does not
 * exist in production, and that is **by design, under decision D-15**. It is the gate on COD
 * settlement:
 *
 *   - `CodCollectionService` guards on its own `TABLE` constant and logs `critical` when the table
 *     is missing, so no collection event can be recorded;
 *   - `PaymentVerificationService::codMayBeSettled()` therefore returns false with no event;
 *   - so a COD order cannot be settled by a collection event, because no event can exist.
 *
 * WHY THIS TEST EXISTS NOW AND NOT BEFORE. GATE F closed on 2026-11-02 and fifteen tables gained
 * migrations in one change. The obvious next step for a reader is "there is a ninth draft, promote
 * that too" -- and promoting it would **silently activate settlement behaviour** on a path whose
 * remaining Phase 01 item (`GATE-F-RECONCILIATION-ITEMS.md` R2: *17 COD orders recorded paid with
 * no collection evidence*) depends on the table being absent for its premise. Directive E §3 says
 * it in as many words: *"Do not confuse it with the original 15 tables or silently activate
 * settlement behavior."*
 *
 * So the absence is asserted rather than left to a comment. Creating this table is a DELIBERATE
 * decision with a settlement consequence, and it must fail here first so that somebody reads this
 * docblock before making it.
 *
 * A PURE FILESYSTEM TEST: no application, no database, no connection.
 */
class CodCollectionEventsStillAbsentTest extends TestCase
{
    private const TABLE = 'cod_collection_events';

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<int, string> */
    private function discoverableMigrationFiles(): array
    {
        $base = $this->basePath();

        $files = glob($base . '/database/migrations/*.php') ?: [];

        foreach (glob($base . '/packages/workdo/*/src/database/migrations/*.php') ?: [] as $packageFile) {
            $files[] = $packageFile;
        }

        return $files;
    }

    public function test_the_scan_is_not_vacuous(): void
    {
        $this->assertGreaterThan(
            100,
            count(glob($this->basePath() . '/database/migrations/*.php') ?: []),
            'The root migration glob found almost nothing, so the absence assertion below would '
            . 'pass while checking nothing at all.'
        );
        $this->assertGreaterThan(
            200,
            count($this->discoverableMigrationFiles()),
            'The combined migration scan found too few files to be the real tree.'
        );
    }

    /**
     * No discoverable migration, in either tree, may create `cod_collection_events`.
     *
     * If you are here because this test failed: creating the table turns COD settlement ON. Read
     * `docs/GATE-F-VERIFIED-SCHEMA.md` §5 and `GATE-F-RECONCILIATION-ITEMS.md` R2 first, and make
     * it an owner decision rather than a side effect of a schema change.
     */
    public function test_no_discoverable_migration_creates_the_cod_settlement_table(): void
    {
        $offenders = [];

        $pattern = '/Schema::\s*(?:connection\s*\([^)]*\)\s*->\s*)?create(?:IfNotExists)?\s*\(\s*[\'"]'
            . preg_quote(self::TABLE, '/')
            . '[\'"]/i';

        foreach ($this->discoverableMigrationFiles() as $file) {
            if (preg_match($pattern, (string) file_get_contents($file))) {
                $offenders[] = str_replace($this->basePath() . '/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "`" . self::TABLE . "` is created by:\n  " . implode("\n  ", $offenders)
            . "\n\nTHIS ACTIVATES COD SETTLEMENT. The table is absent from production BY DESIGN "
            . '(decision D-15, measured in docs/GATE-F-VERIFIED-SCHEMA.md section 5): '
            . 'CodCollectionService cannot record an event without it, '
            . 'PaymentVerificationService::codMayBeSettled() returns false, and a COD order '
            . 'therefore cannot be settled by a collection event. It is also the premise of the '
            . 'open Phase 01 item R2 -- 17 COD orders recorded paid with no collection evidence, '
            . 'which CANNOT exist while this table does not. Creating it is an owner decision with '
            . 'a settlement consequence, not a tidy-up of the GATE F set.'
        );
    }

    /**
     * Its draft stays parked and stays a draft.
     *
     * The other eight drafts are SUPERSEDED by promoted migrations. This one is not superseded --
     * it is the only draft where the draft is the authority rather than production, because there
     * is no production object to measure against. Losing it would lose the only written definition
     * of what the table would have to look like, including the detail that matters:
     * `codMayBeSettled()` compares its `amount` against `orders.final_price` with a 0.005
     * tolerance, so a scale mismatch there is a settlement that silently refuses.
     */
    public function test_its_draft_is_still_parked_and_is_still_a_draft(): void
    {
        $draft = $this->basePath()
            . '/database/migrations/_gate_f_pending/0009_draft_create_cod_collection_events_table.php.draft';

        $this->assertFileExists(
            $draft,
            'The cod_collection_events draft is gone. Unlike the other eight it is NOT superseded '
            . 'by a promoted migration -- there is no production object to measure against, so the '
            . 'draft is the only written definition of what this table would have to be.'
        );

        $this->assertStringEndsWith(
            '.php.draft',
            $draft,
            'The draft must keep the .php.draft suffix so it cannot match Laravel\'s *_*.php '
            . 'migration glob even if this directory were ever registered as a migration path.'
        );

        $source = (string) file_get_contents($draft);

        $this->assertStringContainsString(
            'decimal',
            $source,
            'The draft no longer declares a decimal amount. Its scale is load-bearing: '
            . 'codMayBeSettled() compares amount against orders.final_price with a 0.005 '
            . 'tolerance, and a scale mismatch there is a settlement that silently refuses.'
        );
    }

    /**
     * The two services that gate on the table's absence must keep doing so.
     *
     * A refactor that dropped either guard would make the absence a 500 rather than a refusal --
     * and a refusal is the designed behaviour.
     */
    public function test_both_services_still_gate_on_the_tables_existence(): void
    {
        $service = (string) file_get_contents($this->basePath() . '/app/Services/CodCollectionService.php');

        $this->assertStringContainsString(
            "const TABLE = '" . self::TABLE . "'",
            $service,
            'CodCollectionService no longer names the table in one place, so the guard and the '
            . 'writes can drift apart.'
        );

        $this->assertMatchesRegularExpression(
            '/hasTable\s*\(\s*(?:self::TABLE|[\'"]' . preg_quote(self::TABLE, '/') . '[\'"])\s*\)/',
            $service,
            'CodCollectionService no longer checks whether the table exists before writing to it. '
            . 'Without that check the absence becomes a SQL error instead of the designed refusal, '
            . 'and the critical log that records the attempt disappears.'
        );

        $verification = (string) file_get_contents($this->basePath() . '/app/Services/PaymentVerificationService.php');

        $this->assertStringContainsString(
            'codMayBeSettled',
            $verification,
            'PaymentVerificationService::codMayBeSettled() is gone. It is what makes the missing '
            . 'table a refusal to settle rather than an unchecked path.'
        );
    }
}
