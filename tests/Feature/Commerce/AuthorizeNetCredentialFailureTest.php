<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Security\SecurityTestCase;

/**
 * AUTHORIZENET SWALLOWED EVERY SETUP FAILURE AND THEN READ THE VARIABLE IT HAD FAILED TO ASSIGN.
 * =================================================================================================
 *
 * `AuthorizeNetPaymnetController::getOrderPaymentStatus()` builds the gateway request inside a
 * `try`, and its `catch (\Exception $e) {}` was EMPTY. So when anything in that block threw -- and
 * the ordinary cause is a store with no AuthorizeNet credentials, where
 * `$admin_payment_setting['authorizenet_login_id']` is an undefined array key, which PHP 8 raises and
 * Laravel promotes to an ErrorException -- the exception was discarded, `$requestNet` was never
 * assigned, and the very next line
 *
 *     $controller = new AnetController\CreateTransactionController($requestNet);
 *
 * raised `Undefined variable $requestNet`. The shopper gets an unexplained 500 on the whole gateway
 * return and NOTHING IS WRITTEN TO THE LOG, so there is no way to tell a misconfigured store from a
 * broken one.
 *
 * THE FILE'S OWN SIBLING IS THE EVIDENCE FOR WHAT THE CATCH SHOULD DO. `planPayWithauthorizenet()`
 * (:132-157) has the IDENTICAL try block -- same merchant authentication, same credit card, same
 * `CreateTransactionRequest` -- and its catch returns
 * `redirect()->route('plan.index')->with('error', ...)`. The order path is the one that was left
 * empty. This is not a design question; it is one method out of step with its twin.
 *
 * MEASURED first, with no credentials seeded:
 *     ErrorException: Undefined variable $requestNet @ AuthorizeNetPaymnetController.php:394
 *
 * FOUND WHILE DOING P1-ORD-02, where it was reported rather than fixed because it is a different
 * defect. It is fixed here on its own, and closing it also removes the reason AuthorizeNet could not
 * be driven end to end in that package.
 */
class AuthorizeNetCredentialFailureTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A store with a real checkout and NO AuthorizeNet credentials. */
    private function storeWithoutCredentials(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'an-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $n => $v) {
            DB::table('settings')->insert(['name' => $n, 'value' => $v, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        (new \App\Models\Setting)->flushCache();

        $this->assertNull(
            DB::table('settings')->where('store_id', $store->id)->where('name', 'authorizenet_login_id')->value('value'),
            'Premise: this store has no AuthorizeNet credentials, which is what makes the setup block throw.'
        );

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'N', 'email' => 'an-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $product = Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id]);

        return [$store, $customer, $product];
    }

    private function state($store, $customer): array
    {
        return [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'authorizenet', 'shipping_final_price' => 0, 'tax_price' => 0,
            'cartlist_final_price' => 100.00, 'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x', 'billing_postecode' => '1',
                'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
                'billing_company_name' => '', 'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }

    /**
     * THE DEFECT: an unexplained 500, and nothing in the log.
     *
     * Asserted as "no ErrorException escapes", not as a status code, because the handler's refusal is
     * a redirect and a redirect is also what several legitimate outcomes produce -- a status
     * assertion alone would pass for the wrong reason. `withoutExceptionHandling()` makes the
     * difference visible: before the fix this throws, after it the handler returns.
     */
    public function test_a_store_without_credentials_is_refused_rather_than_crashing(): void
    {
        [$store, $customer] = $this->storeWithoutCredentials();

        $thrown = null;

        try {
            $this->withoutExceptionHandling()
                ->actingAs($customer, 'customers')
                ->withSession(['request_data' => $this->state($store, $customer)])
                ->post('/' . $store->slug . '/authorizenet-status', [
                    'cardNumber' => '4111111111111111', 'month' => '12', 'year' => '2030', 'cvv' => '123',
                ]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown,
            'the AuthorizeNet return crashed instead of refusing: '
            . ($thrown ? get_class($thrown) . ': ' . $thrown->getMessage()
                . ' @ ' . basename($thrown->getFile()) . ':' . $thrown->getLine() : ''));
    }

    /**
     * AND THE CAUSE IS RECORDED. A swallowed exception on a card path is worse than a crash: the
     * crash at least leaves a stack trace. The refusal must say why.
     */
    public function test_the_reason_the_setup_failed_is_logged(): void
    {
        Log::spy();

        [$store, $customer] = $this->storeWithoutCredentials();

        $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->state($store, $customer)])
            ->post('/' . $store->slug . '/authorizenet-status', [
                'cardNumber' => '4111111111111111', 'month' => '12', 'year' => '2030', 'cvv' => '123',
            ]);

        /*
         * BOTH HALVES. The message says WHAT failed and the context says WHICH gateway and WHY --
         * asserting only the message would pass against a log line that never names the cause, which
         * is barely better than the empty catch this closes.
         */
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message = null, $context = null) {
                return str_contains((string) $message, 'the gateway request could not be built')
                    && is_array($context)
                    && ($context['gateway'] ?? null) === 'authorizenet'
                    && ! empty($context['reason']);
            })
            ->atLeast()->once();
    }

    /**
     * AND NO ORDER IS WRITTEN for a charge that never happened. The crash happened BEFORE the order
     * write, so this held before the fix too -- it is a preservation assertion, stated as such, and
     * it is what would catch a "fix" that fell through to the order write instead of refusing.
     */
    public function test_no_order_is_written_when_the_gateway_could_not_be_configured(): void
    {
        [$store, $customer] = $this->storeWithoutCredentials();

        $before = (int) DB::table('orders')->count();

        $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->state($store, $customer)])
            ->post('/' . $store->slug . '/authorizenet-status', [
                'cardNumber' => '4111111111111111', 'month' => '12', 'year' => '2030', 'cvv' => '123',
            ]);

        $this->assertSame($before, (int) DB::table('orders')->count(),
            'an order was written although the gateway request could not even be built.');
    }

    /**
     * THE SIBLING IS NOT REGRESSED. `planPayWithauthorizenet()` already refused correctly; this
     * records that its catch still does something, so a future edit cannot empty it the way the order
     * path was.
     */
    public function test_neither_authorizenet_setup_block_swallows_its_exception(): void
    {
        $code = (string) file_get_contents(app_path('Http/Controllers/AuthorizeNetPaymnetController.php'));

        $this->assertSame(0, preg_match_all('/catch\s*\(\s*\\\\?Exception\s+\$e\s*\)\s*\{\s*\}/', $code),
            'AuthorizeNetPaymnetController still has an EMPTY catch. Every one of them discards the '
            . 'reason a card path failed and lets the next line read a variable that was never '
            . 'assigned -- which is exactly how the order path produced an unexplained 500.');

        /*
         * AND THE ORDER PATH CATCHES `Throwable`, NOT `Exception` -- pinned over the source, with the
         * weakness of that stated.
         *
         * A removal check recorded narrowing it to `\Exception` as SURVIVING, and that is honest
         * rather than a gap in the fixture: every failure this handler's OWN DATA can produce is an
         * undefined array key, i.e. an ErrorException, which is an Exception -- so no fixture here can
         * separate the two. What `Throwable` additionally catches is an `Error`: a TypeError from one
         * of the SDK's typed setters, or `Class not found` on a deployment where the Authorize.Net
         * package is missing. Under `\Exception` either of those escapes as exactly the unexplained
         * 500 this test file exists to close.
         *
         * So the choice is kept and asserted rather than left to the next editor's judgement. A source
         * assertion is the only control available for a branch no fixture can reach; it is recorded as
         * such and not dressed up as execution.
         */
        $this->assertMatchesRegularExpression(
            '/\$requestNet->setTransactionRequest\(\$transactionRequestType\);\s*\}\s*catch\s*\(\s*\\\\Throwable\s+\$e\s*\)/s',
            $code,
            "the order path's gateway-setup catch no longer takes \\Throwable. An Error -- a typed "
            . 'setter rejecting a value, or a missing SDK class -- would then escape as the '
            . 'unexplained 500 this file closes.'
        );
    }
}
