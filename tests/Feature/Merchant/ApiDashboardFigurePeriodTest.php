<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-07-9 / WS K7 — the period the Flutter tiles CLAIM is the period the API actually computes.
 * =================================================================================================
 *
 * The app's dashboard tiles now state their window, mirroring the web dashboard's established
 * pattern (`<x-ss.stat :period="__('all time')">`, resources/views/dashboard/overview.blade.php).
 * Those labels are HARDCODED in the Flutter widget, because the API does not describe its own
 * windows — so they are a claim about this handler's queries, made in another repository, which
 * nothing would catch going stale.
 *
 * This is that catch. Narrow `Api\DashboardController::dashboard()`'s counts to a month, a week or
 * "since the plan started" and these fail, rather than the app quietly captioning a 30-day figure
 * "all time" for the rest of its life.
 *
 * IT ALSO PINS THE MISLABEL THE APP FIXED. `$DashboardData['sales']` is
 * `Order::where('delivered_status', 1)->count()` — a COUNT of delivered orders, with no money
 * anywhere in it. The tile said "Total Sales" over it, which a merchant reads as revenue; it now
 * says "Total Delivered". `test_the_sales_figure_is_a_count_of_delivered_orders_not_money` is why
 * that relabel is right, and it fails if the figure is ever changed into an amount — at which
 * point the app's label has to change back.
 */
class ApiDashboardFigurePeriodTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Store} */
    private function merchantWithStore(): array
    {
        $merchant = $this->makeMerchant(['password' => bcrypt('password')]);
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $merchant = User::find($merchant->id);

        // The dashboard withholds order and product data without these, which would make every
        // figure below absent for the wrong reason.
        foreach (['Manage Order', 'Manage Products', 'Manage Coupon'] as $permission) {
            $this->grantPermission(User::find($merchant->id), $permission);
        }

        return [User::find($merchant->id), $store];
    }

    private function dashboardFor(User $user): array
    {
        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/dashboard');

        $this->assertSame(200, $response->getStatusCode(),
            'premise: the dashboard must have answered. Body: '
            . substr((string) $response->getContent(), 0, 300));

        return $response->json()['data'];
    }

    /**
     * "ALL TIME" FOR THE ORDER COUNT. An order from two years ago is counted, so the label is
     * true. A window of any kind here would make the app's caption a lie.
     */
    public function test_the_order_figure_really_covers_all_time(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $this->makeOrder($store, [
            'order_date' => now()->subYears(2),
            'created_at' => now()->subYears(2),
        ]);
        $this->makeOrder($store, ['order_date' => now(), 'created_at' => now()]);

        $data = $this->dashboardFor($merchant);

        $this->assertSame(2, (int) $data['order'],
            'the order figure is captioned "all time" in the app but does not count an order '
            . 'from two years ago');
    }

    /** "ALL TIME" FOR THE DELIVERED COUNT, measured the same way. */
    public function test_the_delivered_figure_really_covers_all_time(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $this->makeOrder($store, [
            'delivered_status' => 1,
            'order_date' => now()->subYears(2),
            'created_at' => now()->subYears(2),
        ]);
        $this->makeOrder($store, ['delivered_status' => 1, 'created_at' => now()]);
        $this->makeOrder($store, ['delivered_status' => 0, 'created_at' => now()]);

        $data = $this->dashboardFor($merchant);

        $this->assertSame(2, (int) $data['sales'],
            'the delivered figure is captioned "all time" but does not count a delivery from '
            . 'two years ago');
    }

    /**
     * THE FIGURE IS A COUNT, NOT MONEY, which is the whole reason the app's "Total Sales" label
     * was wrong. Three delivered orders of 5000 each must read 3, not 15000.
     */
    public function test_the_sales_figure_is_a_count_of_delivered_orders_not_money(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        for ($i = 0; $i < 3; $i++) {
            $this->makeOrder($store, ['delivered_status' => 1, 'final_price' => 5000]);
        }

        $data = $this->dashboardFor($merchant);

        $this->assertSame(3, (int) $data['sales'],
            'the `sales` figure is a COUNT of delivered orders; if it has become an amount the '
            . "app's \"Total Delivered\" label must change back to a money label");
        $this->assertNotSame(15000, (int) $data['sales']);
    }

    /**
     * "IN THIS STORE" FOR THE PRODUCT COUNT — the web's wording for the same figure, and the
     * tenancy claim behind it. `Product` is NOT in `config/tenancy.php`'s `scoped_models`, so it
     * adds NO global predicate for any principal: the `where('store_id')` in the handler is the
     * only thing keeping another tenant's catalogue out of this number.
     */
    public function test_the_product_figure_counts_only_this_store(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $other = $this->makeMerchant();
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);

        $this->productIn($store, 'mine');
        $this->productIn($otherStore, 'theirs-1');
        $this->productIn($otherStore, 'theirs-2');

        $data = $this->dashboardFor($merchant);

        $this->assertSame(1, (int) $data['products'],
            'the product figure is captioned "in this store" but counted another tenant\'s '
            . 'catalogue -- Product adds no global store predicate, so only the handler\'s own '
            . 'where() stands between these two stores');
    }

    private function productIn(Store $store, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name, 'slug' => $name . '-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'product_stock' => 1, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }
}
