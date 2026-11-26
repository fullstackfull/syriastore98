<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Services\PaymentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-263 — EVERY ORDER TAKEN OVER TELEGRAM SITS UNPAID, MEASURED ON THE ORDER ROW.
 * =================================================================================================
 *
 * WHAT WAS ALREADY PINNED, AND WHAT WAS NOT. `GatewayClassificationInventoryTest
 * ::test_the_derivation_the_table_describes_is_what_the_service_does` drives
 * `PaymentVerificationService::resolveOrderPaymentStatus('telegram')` and asserts `Pending`. That
 * is the DERIVATION. The register's claim is about the PRODUCT — "every order taken over Telegram
 * sits UNPAID" — and nothing measured that: no test in this repository had ever written an order
 * through `POST {slug}/telegram` and read `orders.payment_status` back.
 *
 * SO THIS FILE MEASURES THE MONEY WHERE IT LANDS. Same store, same product, same basket, same
 * posted fields, same handler — only the route differs:
 *
 *     POST {slug}/whatsapp  -> orders.payment_status = 'Paid'
 *     POST {slug}/telegram  -> orders.payment_status = 'Pending'
 *
 * `payment_status` decides when the merchant's wallet is credited (`OrderObserver::created()` ->
 * `OrderFinancialService::createForOrder()` credits at once for a `paid` row and not otherwise), so
 * the two relays pay the merchant at different times for the same arrangement.
 *
 * WHY THE ASYMMETRY EXISTS. `PaymentVerificationService::OFFLINE_PAYMENT_TYPES` means "settles
 * off-platform by arrangement, so record Paid at once" and lists `bank_transfer`, `whatsapp`, `pos`
 * and `lottery product` — not `telegram`. `routes/customer.php` points BOTH uris at
 * `PaymentController::whatsapp`, so the two channels are one writer. The constant's own docblock
 * records that `pos` and `lottery product` were added for exactly this reason; `telegram` was
 * missed in that sweep, and the omission was invisible while every unenforced type defaulted to
 * Paid.
 *
 * AND TELEGRAM IS NOT HYPOTHETICAL — CHECKED RATHER THAN ASSUMED. `Setting::...` publishes it as a
 * merchant-enablable payment method (`$Setting_array[22]['name'] = 'telegram'`, gated on
 * `is_telegram_enabled`), `App\Payments\PaymentTypeLabel` carries its label, and
 * `resources/views/payment/telegram.blade.php` posts the checkout to `route('user.telegram')`. A
 * merchant who switches it on takes real orders down this path.
 *
 * NOTHING HERE CHANGES A FIGURE. Adding `telegram` to the constant is one list entry, and it
 * changes when a merchant's wallet is credited for every store taking Telegram orders — a money
 * rule, which CLAUDE.md §2 reserves to the owner. It is carried as `OWNER-DECISION-PACKAGE.md`
 * I15, and the opposite reading (the carve-out is meant to be narrow, so `whatsapp`'s own entry is
 * the one to re-examine) is equally coherent. **Do not read this file as a recommendation for
 * either.** When I15 is taken, INVERT the expectation that moves; do not relax it.
 *
 * EVERY ASSERTION READS THE RAW `orders` ROW, because `Order` is `Cachable` and the suite runs
 * under `CACHE_STORE=array`.
 *
 * @see \App\Services\PaymentVerificationService::OFFLINE_PAYMENT_TYPES
 * @see \App\Http\Controllers\PaymentController::whatsapp()
 */
class TelegramOrderPaymentStatusTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A merchant, a store, a plan, the settings the writer reads, and a geography. */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'tg-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore([
            'id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant, $store];
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'tg-' . uniqid(), 'slug' => 'tg-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function guestCart($store, Product $product): string
    {
        $sid = 'tg-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function billing(): array
    {
        return [
            'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'billing_user_telephone' => '123', 'billing_address' => 'x',
            'billing_postecode' => '1', 'billing_country' => '101',
            'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
            'delivery_address' => 'x', 'delivery_postcode' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];
    }

    /**
     * Place one order down `POST {slug}/<channel>` and return its raw row.
     *
     * The `payment_type` goes through the SESSION state, not the posted body, because that is what
     * the handler reads first (`Session::get('request_data')`) and what `processOrder()` really
     * writes at initiation. Driving the body alone would be testing the fallback.
     */
    private function placeOrder(string $channel, string $paymentType): ?object
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        $before = (int) DB::table('orders')->count();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => [
                'slug' => $store->slug,
                'store_id' => $store->id,
                'customer_id' => '',
                'payment_type' => $paymentType,
                'shipping_final_price' => 0,
                'tax_price' => 0,
                'cartlist_final_price' => 100.00,
                'billing_info' => $this->billing(),
            ]])
            ->post('/' . $store->slug . '/' . $channel, [
                'wts_number' => '123456789',
                'payment_type' => $paymentType,
                'billing_info' => $this->billing(),
            ]);

        $this->assertSame($before + 1, (int) DB::table('orders')->count(),
            'no order was written down /' . $channel . ', so the payment-status assertion would '
            . 'be vacuous — a null row reads as "not Paid" whatever the constant says');

        return DB::table('orders')->orderByDesc('id')->first();
    }

    /*
    |--------------------------------------------------------------------------
    | THE PREMISE: the two channels really are ONE writer
    |--------------------------------------------------------------------------
    */

    /**
     * Without this the comparison below is two handlers, not one constant, and the register's
     * argument ("the same handler, so the semantics are identical") would be unsupported.
     */
    public function test_premise_both_relay_routes_resolve_to_the_same_handler(): void
    {
        $actions = [];

        foreach (['whatsapp', 'telegram'] as $channel) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($r) => $r->uri() === '{storeSlug?}/' . $channel
                    && in_array('POST', $r->methods(), true)
            );

            $this->assertNotNull($route, 'POST {storeSlug?}/' . $channel . ' is not registered');
            $actions[$channel] = $route->getActionName();
        }

        $this->assertSame($actions['whatsapp'], $actions['telegram'],
            'the two relay routes no longer share one handler, so GAP-263\'s premise — that these '
            . 'are the same writer with identical semantics — has changed and the row must be '
            . 're-measured');
        $this->assertStringContainsString('PaymentController@whatsapp', $actions['telegram']);
    }

    /** And the constant really is the thing that separates them. */
    public function test_premise_the_constant_lists_whatsapp_and_not_telegram(): void
    {
        $this->assertContains('whatsapp', PaymentVerificationService::OFFLINE_PAYMENT_TYPES);
        $this->assertNotContains('telegram', PaymentVerificationService::OFFLINE_PAYMENT_TYPES,
            'telegram is now a settle-by-arrangement type. If decision I15 was taken, INVERT the '
            . 'status expectations in this file; do not relax them.');
    }

    /*
    |--------------------------------------------------------------------------
    | THE MEASUREMENT — one basket, two routes, two payment statuses
    |--------------------------------------------------------------------------
    */

    /**
     * THE CONTROL. Without it, "telegram is Pending" is also what a broken fixture produces.
     */
    public function test_a_whatsapp_order_is_born_paid(): void
    {
        $order = $this->placeOrder('whatsapp', 'whatsapp');

        $this->assertSame('whatsapp', (string) $order->payment_type);
        $this->assertSame('Paid', (string) $order->payment_status,
            'a WhatsApp relay order is no longer born Paid, which is the control the telegram '
            . 'case is measured against');
    }

    /**
     * THE FINDING. Same handler, same basket, same arrangement — unpaid.
     */
    public function test_a_telegram_order_is_born_pending(): void
    {
        $order = $this->placeOrder('telegram', 'telegram');

        $this->assertSame('telegram', (string) $order->payment_type,
            'the telegram route did not record telegram as the payment type, so this file is not '
            . 'measuring what GAP-263 is about');
        $this->assertSame('Pending', (string) $order->payment_status,
            'GAP-263 changed behaviour. If decision I15 was taken in favour of treating telegram '
            . 'as a relay, INVERT this to Paid and say so; do not relax it.');
    }

    /**
     * AND THE CONSEQUENCE THE ROW IS ACTUALLY ABOUT: the merchant's wallet.
     *
     * `payment_status` is not a label — `OrderFinancialService::createForOrder()` credits the store
     * wallet at once for a `paid` row and leaves it alone otherwise. So the asymmetry is a
     * difference in WHEN THE MERCHANT IS PAID, measured here in currency on one store's balance.
     */
    public function test_the_two_relays_credit_the_store_wallet_differently(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('store_wallets')) {
            $this->markTestSkipped('store_wallets is absent from the test schema');
        }

        $whatsapp = $this->placeOrder('whatsapp', 'whatsapp');
        $telegram = $this->placeOrder('telegram', 'telegram');

        $balanceFor = fn ($order) => (float) DB::table('store_wallets')
            ->where('store_id', $order->store_id)->value('available_balance');

        $this->assertEquals(100.00, $balanceFor($whatsapp),
            'the WhatsApp control did not credit the wallet, so the telegram figure below is not '
            . 'a comparison');
        $this->assertEquals(0.00, $balanceFor($telegram),
            'GAP-263 changed behaviour: the telegram relay now credits the merchant at once. If '
            . 'decision I15 was taken, INVERT this to 100.00.');
    }
}
