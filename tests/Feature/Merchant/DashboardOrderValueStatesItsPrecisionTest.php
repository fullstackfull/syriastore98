<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\Metrics\CaveatedFigure;
use App\Support\Metrics\MetricCaveat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-152 / P05-04 — THE MERCHANT DASHBOARD'S HEADLINE MONEY FIGURE STATES ITS PRECISION LIMIT.
 * =================================================================================================
 *
 * THE OMISSION THE GAP-152 ROW NAMES RATHER THAN LEAVING TO BE REDISCOVERED, VERBATIM: "One more
 * omission of the same family is already measured and named: `dashboard/overview.blade.php` composes
 * the Revenue tile's hint inside an `@php` block from the `MetricResult` it is handed, and
 * `GrossMerchandiseValue`'s own `FLOAT_PRECISION` is not in it -- the merchant dashboard's headline
 * money figure states no precision limit."
 *
 * RE-MEASURED HERE BEFORE ANYTHING CHANGED. `GrossMerchandiseValue::for()` attaches
 * `MetricCaveat::FLOAT_PRECISION` unconditionally (`GrossMerchandiseValue.php:55`), with the
 * vocabulary's own reason: GMV is the one metric that cannot be aggregated on
 * `store_order_financials`' `DECIMAL(15,2)` and must come off `orders.final_price`, which is FLOAT.
 * The tile printed that figure, and the thirty-line `@php` block that composed its hint consulted
 * the result's `currencies()` and `totalIn()` and never its `caveats()`.
 *
 * WHY THE FIX IS A CARRIER AND NOT ONE MORE SENTENCE IN THE BLOCK. The block is the per-view
 * mechanism GAP-152 exists to replace: a hint composed in a view, in a different file from the
 * metric that knows what the figure leaves out. A sentence added there would be correct today and
 * forgotten by the next caveat. The figure is now a `CaveatedFigure` built where the metric is read,
 * rendered through `<x-ss.figure>`, which has no `hint` prop to forget.
 */
final class DashboardOrderValueStatesItsPrecisionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');

        $this->merchant = User::find($this->merchant->id);

        DB::table('settings')->insert([
            'store_id' => $this->store->id, 'name' => 'defult_currancy', 'value' => 'EUR',
            'created_by' => $this->store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /* Several handlers read the platform admin through Cache::remember('admin_details'), so a
           fixture built after one has run is invisible without this. */
        Cache::forget('admin_details');
    }

    private function orderIn(string $currency, float $amount): void
    {
        $order = $this->makeOrder($this->store, [
            'final_price' => $amount,
            'delivered_status' => \App\Http\Controllers\HomeController::ORDER_STATUS_DELIVERED,
        ]);

        DB::table('orders')->where('id', $order->id)->update(['currency_code' => $currency]);
        (new Order)->flushCache();
    }

    /** The dashboard as a merchant reads it: scripts and styles out, entities decoded, space collapsed. */
    private function dashboardText(): string
    {
        $response = $this->actingAs($this->merchant)->get('/dashboard');
        $response->assertStatus(200);

        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $response->getContent());
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[\x{2066}-\x{2069}]/u', '', $plain);
        $plain = $plain === null ? null : preg_replace('/\s+/u', ' ', $plain);

        /* A null from preg_replace is not an empty page, and casting it to one turns every
           assertion below into a pass -- measured on this very layout in a sibling test. */
        $this->assertNotNull($plain, 'the dashboard could not be read as UTF-8 text');

        return trim($plain);
    }

    /**
     * THE PREMISE. Without it the assertion below could pass because the metric stopped attaching
     * the caveat rather than because the tile started stating it.
     */
    public function test_the_premise_that_the_order_value_metric_attaches_the_precision_caveat(): void
    {
        $this->orderIn('EUR', 100.00);

        $result = (new \App\Support\Metrics\GrossMerchandiseValue(false))->for(
            \App\Support\Metrics\MetricScope::store((int) $this->store->id),
            \App\Support\Metrics\MetricWindow::allTime()
        );

        $this->assertTrue($result->hasCaveat(MetricCaveat::FLOAT_PRECISION),
            'GrossMerchandiseValue.php:55 attaches FLOAT_PRECISION because GMV is the one metric '
            . 'that cannot read store_order_financials DECIMAL and must come off orders.final_price, '
            . 'which is FLOAT. If that changed, this test must be re-read rather than relaxed.');
    }

    public function test_the_order_value_tile_states_the_precision_limit_its_metric_attached(): void
    {
        $this->orderIn('EUR', 100.00);

        $page = $this->dashboardText();

        $this->assertStringContainsString('100.00', $page,
            'premise: the tile shows the figure at all');

        $this->assertStringContainsString(
            MetricCaveat::message(MetricCaveat::FLOAT_PRECISION),
            $page,
            'the merchant dashboard prints its headline money figure off orders.final_price -- a '
            . 'FLOAT column -- and states no precision limit, while GrossMerchandiseValue attaches '
            . 'FLOAT_PRECISION to the very result the tile was built from.'
        );
    }

    /**
     * AND THE FOUR THINGS THE TILE ALREADY SAID SURVIVE. The cancellation exclusion and the returns
     * clause are owner-decision text that no test covered before `DashboardOrderValueCurrencyTest`;
     * a conversion that dropped them would be a regression dressed as a disclosure.
     */
    public function test_the_existing_definition_and_the_owner_clauses_survive_the_conversion(): void
    {
        $this->orderIn('EUR', 100.00);

        $page = $this->dashboardText();

        foreach ([
            'The total value of orders placed',
            'excluding orders that were cancelled',
            'Returned orders are still included',
            'This is not payments received, and not profit',
        ] as $clause) {
            $this->assertStringContainsString($clause, $page,
                'the Order value tile lost "' . $clause . '" in the carrier conversion');
        }
    }

    /**
     * THE MIXED-CURRENCY CASE, which is where a caveat list earns its keep: `MetricResult::make()`
     * attaches `MIXED_CURRENCY` from the data, and the tile's own sentence answers it. Both the
     * answer and the precision statement must be on the page.
     */
    public function test_a_mixed_currency_store_sees_both_its_own_sentence_and_the_precision_limit(): void
    {
        $this->orderIn('EUR', 100.00);
        $this->orderIn('USD', 50.00);

        $page = $this->dashboardText();

        $this->assertStringContainsString('Also ordered in another currency', $page,
            'the tile stopped saying that money in another currency is not added in');

        $this->assertStringContainsString(
            MetricCaveat::message(MetricCaveat::FLOAT_PRECISION),
            $page,
            'the precision limit went missing on a mixed-currency store'
        );
    }

    /**
     * A MERCHANT WITH NO ORDERS AT ALL. The tile has a figure of 0.00 and still has to say what the
     * figure means; a carrier whose caveats came from a null result must not take the page down.
     */
    public function test_a_store_with_no_orders_still_renders_the_tile_and_its_definition(): void
    {
        $page = $this->dashboardText();

        $this->assertStringContainsString('The total value of orders placed', $page,
            'an empty store lost the tile\'s definition entirely');
    }

    /** The carrier really reaches the view, so the renderer and not a string is what rendered it. */
    public function test_the_view_is_handed_a_carrier_rather_than_three_strings(): void
    {
        $this->orderIn('EUR', 100.00);

        $figure = $this->actingAs($this->merchant)->get('/dashboard')->viewData('ssRevenueFigure');

        $this->assertInstanceOf(CaveatedFigure::class, $figure,
            'the dashboard still composes the Order value hint as a string, so the next caveat '
            . 'GrossMerchandiseValue attaches will be forgotten in the view again');
        $this->assertTrue($figure->hasCaveat(MetricCaveat::FLOAT_PRECISION),
            'the carrier reached the view without the caveat its metric attached');
    }
}
