<?php

namespace Tests\Feature\Reports;

use App\Support\Metrics\CaveatedFigure;
use App\Support\Metrics\MetricCaveat;
use App\Support\Reports\ReportFigureNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-152 / P05-04 — THE SALES EXPORT'S DISCLOSURE COLUMN CARRIES THE LEDGER FIGURE'S CAVEATS.
 * =================================================================================================
 *
 * `SalesExportCarriesTheCollectedFigureTest` closed the first half of P5-R7-B: the file replays the
 * "Payments received" FIGURE the screen showed. This closes the half that was still open and was
 * measured from source before anything changed.
 *
 * WHAT WAS MEASURED. `ReportFigureNote::salesRowHint($tile)` composed its cell as
 * `moneyRowHint() . ' ' . __('Payments received over this report\'s period ... :value')` -- the
 * precision caveat of the nine-column row, plus the ledger figure's VALUE. It read `$tile['value']`
 * and nothing else: not `$tile['hint']`, not `$tile['caveats']`. So the file carried the ledger
 * number with NONE of the disclosures the screen prints beside the same number -- the orders with no
 * financial record, the amount assumed into the store currency, the two-currency refusal. That is
 * Phase 05's own definition of a defect: "an export which drops a caveat".
 *
 * AND THE KEYS ARE THERE TO BE READ. `ReportController::salesCollectionTile()` flattens a
 * `CaveatedFigure` with `->toArray()`, whose wire form carries `caveats` precisely so "a gate can
 * assert the mechanism GENERICALLY" (`CaveatedFigure::toArray()`'s own docblock). The export had the
 * caveat list in its hand and composed a sentence instead of deriving one from it.
 *
 * THE SESSION IS SEEDED RATHER THAN DRIVEN, for the reason the sibling file states and measures:
 * `order_reports_chart()` holds 5 `MONTH(` and 1 `YEAR(` in `selectRaw` and
 * `order_reports_chart_data()` holds 12 and 4, so both answer HTTP 500 under SQLite before any
 * arithmetic runs. The two EXPORT handlers hold zero such constructs and are independently
 * reachable, which the premise test below re-measures rather than assumes.
 */
final class SalesExportCarriesTheLedgerCaveatsTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function exports(): array
    {
        return [
            'line chart' => ['order_line_chart_report', '/order-report-export'],
            'bar chart' => ['order_bar_chart_report', '/order-barchart-report-export'],
        ];
    }

    /**
     * A ledger tile as the screen really leaves it: a `CaveatedFigure` flattened exactly the way
     * `salesCollectionTile()` flattens one, so its `caveats` and its derived `hint` are the real
     * wire form and not a hand-written stand-in.
     */
    private function ledgerTile(): CaveatedFigure
    {
        return CaveatedFigure::of(
            '40.00 EUR',
            'This month',
            [MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE, MetricCaveat::CURRENCY_UNRECORDED],
            [__('Money customers actually paid, counted on the day it was paid, including orders cancelled or refunded after payment. It is not order value and not profit.')],
            [MetricCaveat::CURRENCY_UNRECORDED => __('Includes :amount from records that carry no currency, counted in your store currency.', ['amount' => '5.00 EUR'])],
        );
    }

    /** @param array<string, mixed> $collection */
    private function seedSession(string $sessionKey, int $storeId, array $collection): void
    {
        Session::put($sessionKey, [
            'monthList' => ['01-Jan'],
            'NetSaleTotal' => [100.0],
            'AverageNetSales' => [100.0],
            'GrossSaleTotal' => [110.0],
            'AverageGrossSales' => [110.0],
            'TotalOrderCount' => [1],
            'PurchasedItemTotal' => [1],
            'ShippingTotal' => [10.0],
            'CouponTotal' => [0.0],
            'collection' => $collection,
            'collection_store_id' => $storeId,
        ]);
    }

    private function plain(string $text): string
    {
        $text = (string) preg_replace('/[\x{2066}-\x{2069}]/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * THE PREMISE, in both directions: the tile really carries caveats, and the export really runs.
     * Without the first the loop below asserts nothing; without the second a 500 would satisfy it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('exports')]
    public function test_the_premise_that_the_tile_carries_caveats_and_the_export_answers(string $sessionKey, string $uri): void
    {
        [$merchant, $store] = $this->makeTenant();

        $tile = $this->ledgerTile();

        $this->assertNotSame([], $tile->caveats(),
            'the fixture tile carries no caveat, so every assertion below would pass vacuously');

        $this->seedSession($sessionKey, (int) $store->id, $tile->toArray());

        $response = $this->actingAs($merchant->fresh())->get($uri);

        $this->assertNotSame(500, $response->status(),
            $uri . ': the export answered 500, so this file proves nothing about it. These two '
            . 'handlers read only the caller\'s own session and run no dated SQL.');
        $response->assertStatus(200);

        $this->assertSame('40.00 EUR', $response->json('collection.value'),
            $uri . ': the figure itself was not replayed, so the caveat question cannot be asked');
    }

    /**
     * THE DEFECT. Every caveat the screen's tile carries is stated in the file's disclosure column.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('exports')]
    public function test_the_disclosure_column_states_every_caveat_the_ledger_tile_carries(string $sessionKey, string $uri): void
    {
        [$merchant, $store] = $this->makeTenant();

        $tile = $this->ledgerTile();

        $this->seedSession($sessionKey, (int) $store->id, $tile->toArray());

        $note = $this->plain((string) $this->payloadNote($merchant, $uri));

        foreach ($tile->caveats() as $index => $caveat) {
            $this->assertStringContainsString(
                $this->plain($tile->caveatSentences()[$index]),
                $note,
                $uri . ": the file replays the ledger figure and drops {$caveat} from its "
                . 'disclosure column, so the spreadsheet is less honest than the page it came from'
            );
        }
    }

    /**
     * AND THE ROW'S OWN PLURAL PRECISION STATEMENT SURVIVES THE ADDITION. The nine-column row still
     * says its money is held at limited precision; the ledger caveats are added, not substituted.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('exports')]
    public function test_the_rows_own_precision_statement_is_not_replaced_by_the_ledger_caveats(string $sessionKey, string $uri): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->seedSession($sessionKey, (int) $store->id, $this->ledgerTile()->toArray());

        $note = $this->plain((string) $this->payloadNote($merchant, $uri));

        $this->assertStringContainsString(
            $this->plain(MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION)),
            $note,
            $uri . ': the plural precision statement went missing from the nine-column row'
        );
    }

    /**
     * A TILE WITH NO CAVEAT KEYS -- the shape every fixture written before GAP-152 seeds, and the
     * shape a session saved before this change still holds. It must not throw and must not lose the
     * row's own statement.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('exports')]
    public function test_a_tile_seeded_without_a_caveat_list_still_gets_the_rows_statement(string $sessionKey, string $uri): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->seedSession($sessionKey, (int) $store->id, [
            'value' => '40.00 EUR',
            'period' => 'This month',
            'hint' => 'Money customers actually paid.',
        ]);

        $note = $this->plain((string) $this->payloadNote($merchant, $uri));

        $this->assertStringContainsString(
            $this->plain(MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION)),
            $note,
            $uri . ': a caveat-less tile lost the row\'s own precision statement'
        );
    }

    /** With no tile at all the cell is the row's statement alone, unchanged from before. */
    #[\PHPUnit\Framework\Attributes\DataProvider('exports')]
    public function test_with_no_ledger_tile_the_cell_is_the_rows_statement_verbatim(string $sessionKey, string $uri): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->seedSession($sessionKey, (int) $store->id, []);
        Session::put($sessionKey . '_missing', null);

        $note = (string) $this->payloadNote($merchant, $uri);

        $this->assertSame(ReportFigureNote::moneyRowHint(), $note,
            $uri . ': with no figure to disclose, the cell must say exactly what it said before');
    }

    /**
     * THE ROW'S OWN PLURAL STATEMENT SURVIVES A TILE THAT CARRIES THE SAME KEY. Not live today --
     * `CollectedCustomerPayments` attaches no caveat of its own -- so this drives the case directly
     * rather than through a fixture that cannot produce it: a tile carrying
     * `MONEY_FLOAT_PRECISION` must not have its hint substituted for the row's own sentence, which
     * is what `array_unique` over the merged keys would otherwise do.
     */
    public function test_a_tile_carrying_the_rows_own_caveat_does_not_replace_the_rows_sentence(): void
    {
        /*
         * THE TILE ANSWERS THE KEY IN ITS OWN WORDS, and that is what makes this case able to
         * fail. A FIRST VERSION built the tile with the caveat UNANSWERED -- so its own `hint()`
         * already contained the generic plural sentence, and substituting that hint for the row's
         * sentence still left the sentence on the page. The mutation removing the guard SURVIVED
         * with a perfect score. That is the tenancy skill's ninth trap in terms: "the test cannot
         * tell the two readings apart". With the tile answering the key itself, the generic
         * sentence is in the cell only if the ROW's own caveat still has its own sentence.
         */
        $tile = CaveatedFigure::of(
            '40.00 EUR',
            'This month',
            [MetricCaveat::MONEY_FLOAT_PRECISION],
            [__('Money customers actually paid, counted on the day it was paid, including orders cancelled or refunded after payment. It is not order value and not profit.')],
            [MetricCaveat::MONEY_FLOAT_PRECISION => __('Payments received over this report\'s period, from the payment ledger: :value. This is one total for the whole period, not a figure for each row.', ['value' => '40.00 EUR'])],
        );

        $this->assertStringNotContainsString(
            $this->plain(MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION)),
            $this->plain($tile->hint()),
            'premise: the fixture tile must NOT already contain the generic plural sentence, or '
            . 'this case cannot tell a substituted sentence from a surviving one'
        );

        $note = $this->plain(ReportFigureNote::salesRowHint($tile->toArray()));

        $this->assertStringContainsString(
            $this->plain(MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION)),
            $note,
            'the nine-column row lost its own plural precision statement to a tile that happened '
            . 'to carry the same caveat key'
        );
    }

    private function payloadNote($merchant, string $uri): ?string
    {
        $response = $this->actingAs($merchant->fresh())->get($uri);

        $response->assertStatus(200);

        return $response->json('figureNote');
    }
}
