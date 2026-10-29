<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * F192 — THE STOCK WAS LOST FOR ANY THROW BEFORE THE TRANSACTION OPENED.
 * =============================================================================================
 *
 * D-13 put stock compensation in the order writer's transaction catch. The decrements happen
 * roughly 350 lines ABOVE `DB::beginTransaction()`, so everything in between was an uncompensated
 * window: stock down, no order, nothing given back.
 *
 * IT IS NOT A THEORETICAL WINDOW, and that is what this file drives. `$plan` is assigned only
 * inside `if ($user->type == 'admin')` from `Plan::find($user->plan_id)`, which returns **null**
 * when that admin has no plan — and `$plan->shipping_method` is then read unguarded, a few hundred
 * lines after the same value is guarded with `if ($plan && …)`. A platform admin with a null
 * `plan_id` is all it takes.
 *
 * WHY IT IS DRIVEN THROUGH A ROUTE rather than by reflection: `getProductStatus()` is itself
 * routed — `Route::any('{storeSlug?}/get-payment-status')` (`routes/customer.php:186`) — so the
 * uncompensated window is reachable by a real request, and the test can be a real request.
 *
 * WHY IT MATTERS BEYOND STOCK: on a gateway return the same window means the buyer has PAID, the
 * stock is gone, and no order exists. That is F186's root, and closing this window is what makes
 * F186's own remedy safe — releasing the writer's replay claim so a buyer can retry is only sound
 * once a failed attempt leaves nothing behind.
 */
class PreTransactionStockGiveBackTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * THE COMPENSATION IS PINNED STRUCTURALLY, AND THE REASON IT IS NOT DRIVEN IS MEASURED.
     *
     * Three attempts at a driven case, each of which taught something:
     *
     *   1. A null `$plan`. It throws at `:2803`, which is ABOVE the stock loop, so no stock is
     *      taken — and the first version of this file passed on "stock unchanged" for exactly that
     *      reason. That correction is now its own case below.
     *   2. A query-keyed injection. Measured with a `DB::listen` over a real request: between the
     *      stock `UPDATE` and `DB::beginTransaction()` the only query is the decrement's own stock
     *      re-read. The `select * from "settings"` that follows runs INSIDE the transaction — the
     *      injected throw landed there and fired the transaction's own give-back, not this one.
     *   3. Caller-supplied arithmetic. Every operand in the post-loop region is server-derived
     *      (`taxResolver()->resolve()`, `$cartlist['total_final_price']`, the coupon amount), so no
     *      posted value can raise a TypeError there. That is a good property of the code and it
     *      closes the last in-suite route to a natural throw.
     *
     * SO WHAT IS THE WINDOW WORTH? More than "defence in depth", and the review of this change
     * corrected me in that direction. The only entrance I had MEASURED is the reward-points
     * division — `$rewardSetting['reward_points'] ?? 0` used as a divisor — which needs the
     * `RewardClubPoint` module active. But three entrances in the region after a decrement are
     * **not** module-gated:
     *
     *   - any driver-level failure — a lost connection, a deadlock, a timeout — on the
     *     `User::find($store->created_by)` read, on the decrement's own stock re-read, or on
     *     hydrating the order. This is the realistic operational case and it is the main thing the
     *     compensation buys.
     *   - `$order_email = $billing['email'];` — an unguarded array access, on the
     *     notify-customer-at-threshold branch, while the neighbouring dereferences around it *are*
     *     inside `try/catch`.
     *   - `in_array('enable_low_stock', json_decode($settings['notification']))` — a TypeError if a
     *     merchant's `settings.notification` holds a JSON scalar rather than an array, because
     *     `!empty()` passes it and `in_array()` then receives a string.
     *
     * None of those three is measured, so they are stated as reachable-by-reading rather than
     * driven. What is measured is the first paragraph: no POSTED value can raise a throw here.
     */
    public function test_the_pre_transaction_window_is_compensated(): void
    {
        $source = $this->writerSource();

        $accumulator = strpos($source, '$appliedStockChanges = [];');
        $open = strpos($source, 'try {', (int) $accumulator - 2000);
        $restore = strpos($source, 'restoreStockDecrements($appliedStockChanges ?? [])');
        $rethrow = strpos($source, 'throw $ssPreTransactionFailure;');
        $begin = strpos($source, 'DB::beginTransaction();');

        foreach ([
            'the accumulator' => $accumulator,
            'a try above it' => $open,
            'the pre-transaction restore' => $restore,
            'the rethrow' => $rethrow,
            'the transaction' => $begin,
        ] as $what => $at) {
            $this->assertNotFalse($at, 'the writer no longer carries ' . $what);
        }

        $this->assertLessThan(
            $accumulator,
            $open,
            'the guarded region starts below the accumulator, so a throw before the first decrement'
            . ' is handled with an undefined accumulator'
        );
        $this->assertLessThan(
            $begin,
            $restore,
            'the pre-transaction give-back sits after the transaction opens, where the'
            . " transaction's own catch already handles it -- the two regions must not overlap"
        );
        $this->assertGreaterThan(
            $restore,
            $rethrow,
            'the failure is swallowed rather than rethrown, so the caller sees a success it did'
            . ' not get'
        );
    }

    /**
     * BOTH WRITERS IN THIS FILE — WHICH IS TWO OF ELEVEN, NOT TWO OF TWO.
     *
     * `getProductStatus()` serves the gateway returns and `whatsapp()` serves the storefront
     * intake; each has its own accumulator, its own stock loop and its own transaction, and each
     * had the same uncompensated gap.
     *
     * THE CLASS IS NOT CLOSED, and an earlier version of this docblock read as though it were.
     * The review of this change found the identical shape in **nine more** accumulator sites that
     * did not get the guard: `PaymentService.php` (accumulator `:2460`, decrements `:2499`/`:2524`/
     * `:2581`, `beginTransaction()` `:2839`, restore only in the transaction catch at `:3019`) and
     * the writers in `CinetPayController`, `PaiementProPaymentController`, `EasebuzzController`,
     * `KhaltiPaymnetController`, `NMIPayController`, `PayUPaymentController`,
     * `AuthorizeNetPaymnetController` and the two in `ApiController`. This count is the honest
     * scope of the fix, and it is asserted below so the number cannot drift unnoticed.
     */
    public function test_both_order_writers_carry_the_give_back(): void
    {
        $source = $this->writerSource();

        $this->assertSame(
            2,
            substr_count($source, 'restoreStockDecrements($appliedStockChanges ?? [])'),
            'one of the two order writers has no pre-transaction give-back. They are the same shape'
            . ' and had the same gap; fixing one is how the next reader concludes it is handled.'
        );
        $this->assertSame(
            2,
            substr_count($source, 'throw $ssPreTransactionFailure;'),
            'a pre-transaction catch swallows its failure in one of the two writers'
        );

        /*
         * EXACTLY FOUR GIVE-BACKS IN THE FILE: two pre-transaction and two inside the
         * transactions. A fifth would mean the same decrements can be returned twice, which ADDS
         * stock -- the opposite error and a harder one to notice. A bite that moved the restore
         * below the transaction while leaving the original in place passed the ordering assertion
         * above, because `strpos` finds the first occurrence; this is what closes that.
         */
        /*
         * AND THE REST OF THE CLASS, counted rather than described. Nine other accumulator sites
         * carry the same shape and none has the pre-transaction guard; if one gains it, this fails
         * and asks for the scope sentence above to be re-derived rather than left stale.
         */
        $unguarded = 0;

        foreach ([
            'app/Services/PaymentService.php',
            'app/Http/Controllers/CinetPayController.php',
            'app/Http/Controllers/PaiementProPaymentController.php',
            'app/Http/Controllers/EasebuzzController.php',
            'app/Http/Controllers/KhaltiPaymnetController.php',
            'app/Http/Controllers/NMIPayController.php',
            'app/Http/Controllers/PayUPaymentController.php',
            'app/Http/Controllers/AuthorizeNetPaymnetController.php',
            'app/Http/Controllers/Api/ApiController.php',
        ] as $relative) {
            $other = (string) file_get_contents(base_path($relative));

            if (str_contains($other, '$appliedStockChanges = [];')
                && ! str_contains($other, 'ssPreTransactionFailure')) {
                $unguarded++;
            }
        }

        $this->assertSame(
            9,
            $unguarded,
            'the number of order writers still carrying the uncompensated pre-transaction window'
            . ' has changed (expected 9, found ' . $unguarded . '). If one gained the guard, widen'
            . ' the scope sentence in this docblock; if one lost its accumulator, re-derive the'
            . ' census. Either way the class is not closed by this file alone.'
        );

        $this->assertSame(
            4,
            substr_count($source, 'restoreStockDecrements('),
            'the number of stock give-backs in this file has changed. Four is the whole set: one'
            . ' per writer before its transaction, one per writer inside it. A fifth can return the'
            . ' same decrements twice, which adds stock that was never taken.'
        );
    }

    /**
     * AND THE PLAN THROW, WHICH LOSES NO STOCK — recorded because it corrects F192's own first
     * write-up. A null `$plan` throws at `:2803`, above the stock loop, so that site is a 500 with
     * no inventory consequence. Driven so the distinction cannot rot: if a later change moves the
     * decrements above `:2803`, this case starts failing and the F192 record needs re-deriving.
     */
    public function test_the_plan_throw_happens_before_any_stock_is_taken(): void
    {
        [, $store, $product] = $this->checkoutReadyStore();

        $opening = $this->stockOf($product);

        $this->breakThePlanLookup();

        $sid = $this->guestCart($store, $product);
        Session::put('request_data', $this->storedState($store));

        $decremented = false;
        DB::listen(function ($query) use (&$decremented) {
            if (str_contains(strtolower($query->sql), 'update "products"')) {
                $decremented = true;
            }
        });

        $this->withoutExceptionHandling();

        $threw = null;

        try {
            $this->withCookie('cart', $sid)
                ->post('/' . $store->slug . '/get-payment-status', $this->storedState($store));
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw, 'the premise: a null plan still throws');
        $this->assertStringContainsString('shipping_method', $threw->getMessage(), $threw->getMessage());

        $this->assertFalse(
            $decremented,
            'the plan throw now happens AFTER a stock decrement, so it has become a stock-loss path'
            . ' too and F192 names it wrongly. Re-derive the record.'
        );
        $this->assertSame($opening, $this->stockOf($product), 'and the stock is untouched');
    }

    // ---------------------------------------------------------------- fixture

    /** Comments stripped, so a ratchet cannot be satisfied by prose about itself. */
    private function writerSource(): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(
            base_path('app/Http/Controllers/PaymentController.php')
        )) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** `Plan::find($user->plan_id)` returns null, so `$plan->shipping_method` throws. */
    private function breakThePlanLookup(): void
    {
        DB::table('users')->where('type', 'admin')->update(['plan_id' => null]);

        /* `User` is Cachable and the writer reads the admin through `Cache::remember`, so both
           layers have to be cleared or the request sees the pre-update row. */
        (new User)->flushCache();
        Cache::forget('admin_details');
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    /** @return array{0: User, 1: \App\Models\Store, 2: Product} */
    private function checkoutReadyStore(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'f192-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            ['countries', ['id' => 101, 'name' => 'Syria']],
            ['states', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101]],
            ['cities', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1]],
        ] as [$table, $row]) {
            DB::table($table)->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        $product = Product::forceCreate([
            'name' => 'f192-' . uniqid(), 'slug' => 'f192-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        return [$merchant, $store, $product];
    }

    private function guestCart($store, Product $product): string
    {
        $sid = 'f192-cookie-' . uniqid();

        Cart::forceCreate([
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'price' => 100,
            'store_id' => $store->id, 'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function storedState($store): array
    {
        return [
            'slug' => $store->slug,
            'customer_id' => '',
            'payment_type' => 'bank_transfer',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'checkout_nonce' => (string) \Illuminate\Support\Str::uuid(),
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }
}
