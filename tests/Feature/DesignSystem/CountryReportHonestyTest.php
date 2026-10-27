<?php

namespace Tests\Feature\DesignSystem;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2-05-6 — two numbers that count different things, printed side by side.
 * =============================================================================================
 *
 * The card header read `Total Order Is : {{ $total }}` next to a per-country chart. `$total` is
 * every order the store has taken; the chart counts only orders with a billing-detail row whose
 * country resolves. So the columns sum to something at or below the printed total, with nothing
 * saying why — and a merchant reading it concludes either the chart is broken or their orders
 * have gone missing. Neither is true, and that is the worst case: a correct screen that reads
 * as a broken one.
 *
 * A SECOND, QUIETER LOSS. A country absent from the controller's hardcoded `$countryCodeMap`
 * was dropped with no `else` at all, so its orders vanished from the map and from any sum taken
 * off it. Same class as the Order Status Report's missing statuses, found the same day.
 */
class CountryReportHonestyTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Country Store', 'email' => 'country@example.test',
            'slug' => 'country-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();
    }

    /** An order, optionally with a billing country. */
    private function order(?string $country = null): int
    {
        $id = Order::create([
            'order_id' => 'C' . uniqid(),
            'store_id' => $this->store->id,
            'order_date' => now(), 'price' => 10, 'customer_id' => 0, 'is_guest' => 0,
            'delivered_status' => 1,
        ])->id;

        if ($country !== null) {
            $countryId = DB::table('countries')->where('name', $country)->value('id')
                ?? DB::table('countries')->insertGetId([
                    'name' => $country, 'created_at' => now(), 'updated_at' => now(),
                ]);

            DB::table('order_billing_details')->insert([
                'order_id' => $id, 'country' => $countryId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function page(): string
    {
        return $this->actingAs($this->merchant)
            ->get('/order-country-reports')->assertOk()->getContent();
    }

    /**
     * THE CENTRAL CASE. Three orders, two of them with a country: the screen must say both
     * numbers and say that they differ, rather than printing the larger one beside a chart that
     * sums to the smaller.
     */
    public function test_both_counts_are_named_and_the_gap_is_stated(): void
    {
        $this->order('France');
        $this->order('France');
        $this->order();            // no billing country at all

        $html = $this->page();

        $this->assertStringContainsString('Orders with a billing country', $html,
            'the per-country figure must say what it counts');
        $this->assertStringContainsString('The rest have no billing country recorded.', $html,
            'two different counts are shown and the difference is not explained');

        $this->assertStringNotContainsString('Total Order Is', $html,
            'the old unqualified label is back');
    }

    /** No gap, no explanation — the sentence must not appear when it would be false. */
    public function test_the_gap_sentence_is_absent_when_there_is_no_gap(): void
    {
        $this->order('France');

        $this->assertStringNotContainsString('The rest have no billing country recorded.',
            $this->page(),
            'the screen claims orders are missing when none are');
    }

    /**
     * A COUNTRY THE MAP CANNOT DRAW IS STILL COUNTED. `Narnia` is not in the controller's
     * hardcoded code map, so it has no ISO code and cannot be coloured — but its orders must
     * stay visible in the table and be declared, not silently dropped.
     */
    public function test_a_country_the_map_cannot_draw_is_declared_not_dropped(): void
    {
        $this->order('Narnia');
        $this->order('France');

        $html = $this->page();

        $this->assertStringContainsString('Narnia', $html,
            "a country absent from the code map vanished from the report entirely");
        $this->assertStringContainsString('orders are in countries this map cannot draw', $html,
            'the map is short by one country and does not say so');
    }

    public function test_the_numbers_are_reachable_and_the_pictures_are_not_announced(): void
    {
        $this->order('France');

        $html = $this->page();

        $this->assertStringContainsString('<div class="ss-chart" aria-hidden="true">', $html);
        $this->assertStringContainsString('<div id="map" aria-hidden="true">', $html,
            'the map is a picture of the table beside it and must not be announced as content');
        $this->assertStringContainsString('<th scope="col">Country</th>', $html);
        $this->assertStringNotContainsString("type: 'area'", $html,
            'the area chart drew countries as a continuous quantity');
    }

    /** PRD-001: the query has no date filter, so the screen says so. */
    public function test_the_report_says_it_covers_all_time(): void
    {
        $this->order('France');

        $this->assertStringContainsString('All time', $this->page());
    }

    /** Another store's orders are not in this store's country breakdown. */
    public function test_another_stores_orders_are_not_counted(): void
    {
        $other = User::factory()->create(['type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now()]);
        $otherStore = Store::create([
            'name' => 'Other', 'email' => 'other-c@example.test', 'slug' => 'other-c-' . uniqid(),
            'theme_id' => 'stylique', 'created_by' => $other->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->order('France');

        $foreign = Order::create([
            'order_id' => 'X' . uniqid(), 'store_id' => $otherStore->id,
            'order_date' => now(), 'price' => 10, 'customer_id' => 0, 'is_guest' => 0, 'delivered_status' => 1,
        ])->id;
        $germany = DB::table('countries')->insertGetId(['name' => 'Germany', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_billing_details')->insert([
            'order_id' => $foreign, 'country' => $germany, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->page();

        $this->assertStringContainsString('France', $html, 'Premise: this store\'s own country is missing.');
        $this->assertStringNotContainsString('Germany', $html,
            "another store's country is in this store's report");
    }
}
