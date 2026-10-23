<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;
use App\Models\AddOnManager;

/**
 * P1-ORD-02 CRITERION 2 -- "A POSTED MONEY FIELD CANNOT REACH A PERSISTED TOTAL ON ANY WRITER".
 * =================================================================================================
 *
 * WHAT WAS ALREADY KNOWN, AND WHAT THIS ADDS. Commit 569494d2 ("PartialPayments: recompute the order
 * total server-side") recorded the defect in its own words: "With that module active, the order TOTAL
 * ITSELF was read from the request". It repaired TWO sites -- PaymentService::getProductStatus() and
 * PaymentController -- through the module's own server-side `ManageDeposit()`.
 *
 * IT MISSED SIX. `AuthorizeNetPaymnetController`, `CinetPayController`, `EasebuzzController`,
 * `NMIPayController`, `PaiementProPaymentController` and `PayUPaymentController` each still carry the
 * pre-repair line verbatim:
 *
 *     $order->final_price = $requests_data['cartlist_final_price'] ?? $total_sub_price;
 *
 * `$requests_data` is the SERVER-STORED checkout state -- and that is not the same thing as a
 * server-DERIVED value. `PaymentService` stores it as `$request->except('attachment',
 * 'payment_receipt')` (:479, :680, :703, :720, :894): the whole POST BODY of the checkout request,
 * verbatim. So a key the shopper posts at initiation is in the state the gateway return reads back.
 *
 * AND IN FIVE OF THE SIX `$total_sub_price` IS NEVER ASSIGNED AT ALL -- grepped, zero assignments in
 * each file -- so the `??` limb is an undefined variable. That matters for how this is tested, not
 * only for what is broken: under Laravel's error handler an undefined variable is an ErrorException,
 * so the branch answers 500 rather than writing a wrong row, and a "the row is right" assertion would
 * be satisfied by the crash. The recorded trap, first entry: THE ENDPOINT 500s FOR EVERYONE, so the
 * row never changes and the test passes either way.
 *
 * WHY THE EXISTING COVERAGE DOES NOT REACH IT. `GatewayReturnTrustBoundaryTest::
 * test_posted_money_cannot_change_the_order_total` drives the GUEST arm, and this branch is on the
 * `elseif` after `auth('customers')->user()` -- so it is structurally unreachable from that test. And
 * no WorkDo module loads in any test run without an `add_on_managers` row, so nothing in the suite had
 * ever entered a `module_is_active('PartialPayments')` branch on these controllers.
 */
class PartialPaymentDepositSourceTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The module, enabled through the PRODUCTION loader.
     *
     * An `add_on_managers` row plus `PackageServiceProvider::loadAddons` -- following
     * ModuleOrderMutationGuardTest -- because enabling it any other way would prove something about
     * the harness rather than about the application.
     */
    private function enablePartialPayments(): void
    {
        AddOnManager::create([
            'module' => 'PartialPayments', 'name' => 'Partial Payments',
            'monthly_price' => 0, 'yearly_price' => 0, 'is_enable' => 1,
            'package_name' => 'partial-payments',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['PartialPayments']);

        $this->assertTrue(\App\Facades\ModuleFacade::has('PartialPayments'),
            'Premise: the module is registered with the loader. Without the add_on_managers row no '
            . 'WorkDo module loads in a test run at all, so the branch under test would not exist.');
    }

    /** The module's own tables, which RefreshDatabase does not create. */
    private function migrateModuleTables(): void
    {
        foreach (glob(base_path('packages/workdo/PartialPayments/src/database/migrations/*.php')) ?: [] as $file) {
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    }

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'pp-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            // module_is_active() resolves through the STORE OWNER'S PLAN on any route that carries a
            // slug (app/Helper/helper.php:1359-1381), so without this the branch is unreachable and
            // every assertion here would hold for the wrong reason.
            'modules' => 'PartialPayments',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
            'enable_partial_payment' => 'on',     // the setting the branch is gated on
            'deposit_selection' => 'pay_deposit',
            'deposit_type' => 'percent',
            'deposit_amount' => '10',
            'force_deposit' => 'on',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $store];
    }

    private function customerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Pp', 'last_name' => 'Shopper',
            'email' => 'pp-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function productIn($store, float $price = 100.00): Product
    {
        return Product::forceCreate([
            'name' => 'pp-' . uniqid(), 'slug' => 'pp-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => $price, 'sale_price' => $price,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function checkoutState($store, $customerId, array $overrides = []): array
    {
        return array_merge([
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => $customerId,
            'payment_type' => 'easebuzz',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ], $overrides);
    }

    /**
     * The six host gateway controllers that carried the pre-repair line, with the uri each answers on,
     * the session key it reads, and the POST fields its success path needs.
     *
     * THREE OF THE SIX ARE DRIVABLE END TO END HERE and three are not, which is stated rather than
     * implied. MEASURED, with the module active and an otherwise identical fixture:
     *
     *   cinetpay, easebuzz, paiementpro  302, ONE order written -- the defect, and then the fix
     *   authorizenet                     ErrorException "Undefined variable $requestNet"
     *                                    (AuthorizeNetPaymnetController.php:394), raised BEFORE the
     *                                    order write. A pre-existing crash on this path, nothing to
     *                                    do with this defect, and not fixed here.
     *   payu                             DecryptException "The payload is invalid" -- that return
     *                                    expects an encrypted payload this fixture does not mint.
     *   nmi                              302 to `/` with no order: refused upstream of the write.
     *
     * None of those three is evidence that its branch is SAFE -- only that this fixture cannot reach
     * it. The line was wrong in all six and is repaired in all six; the census below asserts that,
     * keyed on the construct rather than on which ones happen to be drivable today.
     */
    private const GATEWAYS = [
        'cinetpay'    => ['{slug}/get-payment-cinetPay', 'request_data', 'CinetPay', ['responsecode' => 0, 'status' => 'success']],
        'easebuzz'    => ['{slug}/get-payment-easebuzz', 'easebuzz_request_data', 'easebuzz', ['status' => 'success']],
        'paiementpro' => ['{slug}/get-payment-paiementpro', 'request_data', 'paiementpro', ['responsecode' => 0, 'status' => 'success']],
    ];

    public static function drivableGateways(): array
    {
        $out = [];
        foreach (self::GATEWAYS as $name => $spec) {
            $out[$name] = [$spec[0], $spec[1], $spec[2], $spec[3]];
        }

        return $out;
    }

    /**
     * A per-product deposit rule, so the module computes a FIGURE OF ITS OWN rather than echoing the
     * subtotal.
     *
     * THIS IS WHAT STOPS THE TEST PASSING FOR THE WRONG REASON. `partialPaymentDeposit()` falls back to
     * the server subtotal when `ManageDeposit()` throws -- so with no rule in place both "the module
     * priced it" and "the module blew up and we fell back" produce the same 100.00, and the assertion
     * could not tell them apart. With a 10% rule the correct answer is 10.00 and the fallback is
     * 100.00, so the two are distinguishable and the catch limb cannot masquerade as the fix.
     */
    private function depositRuleFor(Product $product, $store): void
    {
        DB::table('partial_payments')->insert([
            'product_id' => $product->id,
            'enable_deposit' => 'on',
            'force_deposit' => 'on',
            'deposit_type' => 'percent',
            'deposit_amount' => 10,
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // `Workdo\PartialPayments\app\Models\PartialPayments` is Cachable, so a raw insert leaves the
        // module's own `where('product_id', ...)` read serving the pre-write (empty) answer -- and the
        // module then takes its "no per-product rule" limb and returns the SUBTOTAL. Measured: without
        // this flush the deposit came back 100.00 instead of 10.00, which would have made the
        // module-priced case indistinguishable from the fallback. The recorded Cachable trap, in the
        // vendored half of the codebase.
        (new \Workdo\PartialPayments\app\Models\PartialPayments)->flushCache();
    }

    /**
     * THE PREMISE, MEASURED BEFORE ANYTHING IS ASSERTED ABOUT THE DEFECT.
     *
     * Four things have to be true or every assertion below holds for the wrong reason: the module is
     * registered, its tables exist, the store's plan carries it (which is how `module_is_active()`
     * resolves on a route with a slug), and the branch actually runs. The last is asserted by driving
     * the return and finding the module's OWN row written -- a row only `OrderPartialPayments()` inside
     * that branch creates.
     */
    public function test_the_partial_payment_branch_is_reached_at_all(): void
    {
        $this->enablePartialPayments();
        $this->migrateModuleTables();

        $this->assertTrue(Schema::hasTable('order_partial_payments'),
            'Premise: the module table exists, so a write that reaches it is not swallowed.');

        [, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $product = $this->productIn($store, 100.00);
        $this->depositRuleFor($product, $store);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $before = (int) DB::table('orders')->count();

        $this->actingAs($customer, 'customers')
            ->withSession(['easebuzz_request_data' => $this->checkoutState($store, $customer->id, [
                'cartlist_final_price' => 1.00, 'payment_type' => 'easebuzz',
            ])])
            ->post('/' . $store->slug . '/get-payment-easebuzz', ['status' => 'success', 'txnid' => 'pp-' . uniqid()]);

        $this->assertSame($before + 1, (int) DB::table('orders')->count(),
            'Premise: the return wrote an order. Without one, every total assertion in this file would '
            . 'be satisfied by the handler never running.');
        $this->assertGreaterThan(0, (int) DB::table('order_partial_payments')->count(),
            'Premise: the PARTIAL-PAYMENT branch is the one that ran. That table is written only inside '
            . 'it, so an order with no row here means some other branch priced the order and this file '
            . 'measures nothing.');
    }

    /**
     * THE DEFECT: A POSTED FIGURE BECAME THE ORDER TOTAL.
     *
     * The checkout is genuine -- a real authenticated shopper, a real 100.00 basket, real stored state
     * -- and the only thing the attacker arranges is one extra key in the body they were already
     * posting at initiation. MEASURED before the fix, on all three of these: `product_price = 100.00`
     * and `final_price = 1.00`.
     *
     * THE ASSERTION IS THE MODULE'S OWN FIGURE, not merely "not 1.00". A test that only refused the
     * posted value would pass against a handler that fell back to the subtotal because the module
     * threw -- see depositRuleFor(). 10.00 is the 10% rule, and it can only come from ManageDeposit().
     *
     * @dataProvider drivableGateways
     */
    public function test_a_posted_total_cannot_become_the_order_total(string $uriTemplate, string $sessionKey, string $paymentType, array $extra): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $this->enablePartialPayments();
        $this->migrateModuleTables();

        [, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $product = $this->productIn($store, 100.00);
        $this->depositRuleFor($product, $store);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $before = (int) DB::table('orders')->count();

        $response = $this->actingAs($customer, 'customers')
            ->withSession([$sessionKey => $this->checkoutState($store, $customer->id, [
                'cartlist_final_price' => 1.00, 'payment_type' => $paymentType,
            ])])
            ->post('/' . str_replace('{slug}', $store->slug, $uriTemplate),
                array_merge(['txnid' => 'pp-' . uniqid()], $extra));

        /*
         * THE MODULE ANSWERED; THIS IS NOT THE FALLBACK. `partialPaymentDeposit()` logs and returns the
         * server subtotal when `ManageDeposit()` throws, so without this the 10.00 below could in
         * principle be some other path. Asserted in the negative because the positive -- the figure --
         * is asserted directly a few lines down, and the two together leave no third explanation.
         */
        /*
         * A CLOSURE, not an argument LIST, and not `Mockery::on()` either -- `shouldNotHaveReceived()`
         * hands its second argument to `withArgs()`, which accepts only an array or a closure. The
         * first cut passed a one-element ARRAY, which is matched by ARITY: the real call is
         * two-argument (message plus context), so that expectation could never match and the `never()`
         * was satisfied vacuously -- it would have passed even if the fallback fired on every run.
         * Found by the read-only review, and it is exactly the "assertion that cannot fail" shape.
         */
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error', function ($message = null) {
            return str_contains((string) $message, 'deposit recomputation failed');
        });

        $this->assertNotSame(404, $response->getStatusCode(), 'the return route must exist');
        $this->assertSame($before + 1, (int) DB::table('orders')->count(),
            'the return wrote no order, so the total assertion below would be vacuous');

        $order = DB::table('orders')->orderByDesc('id')->first();

        $this->assertSame(100.0, round((float) $order->product_price, 2),
            "Premise: the SERVER priced the basket at 100.00, so 1.00 is the caller's figure and not a "
            . 'coincidence.');
        $this->assertNotSame(1.0, round((float) $order->final_price, 2),
            'the order total is the figure the CALLER posted. A shopper paid 1.00 for a 100.00 basket.');
        $this->assertSame(10.0, round((float) $order->final_price, 2),
            "the order total is not the module's own deposit (10% of 100.00). If it is 100.00 the module "
            . 'threw and partialPaymentDeposit() fell back, which is safe but is not this fix working.');
    }

    /**
     * EASEBUZZ CARRIED A SECOND POSTED MONEY FIELD in the same branch -- the one 569494d2's own message
     * named alongside the total. Driven separately because it is a different field and a guard that
     * catches one need not catch the other.
     */
    public function test_a_posted_shipping_price_cannot_become_the_orders_delivery_price(): void
    {
        $this->enablePartialPayments();
        $this->migrateModuleTables();

        [, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $product = $this->productIn($store, 100.00);
        $this->depositRuleFor($product, $store);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $this->actingAs($customer, 'customers')
            ->withSession(['easebuzz_request_data' => $this->checkoutState($store, $customer->id, [
                'cartlist_final_price' => 1.00, 'payment_type' => 'easebuzz',
                'shipping_price' => 9999.00,
            ])])
            ->post('/' . $store->slug . '/get-payment-easebuzz', ['status' => 'success', 'txnid' => 'pp-' . uniqid()]);

        $order = DB::table('orders')->orderByDesc('id')->first();

        $this->assertNotNull($order, 'the return wrote no order, so this assertion would be vacuous');
        $this->assertNotSame(9999.0, round((float) $order->delivery_price, 2),
            "the order carried the shipping price the CALLER posted. The store's own plan has shipping "
            . 'off, so the server figure is 0.00.');
    }

    /**
     * A file's source with every comment removed, by PHP's OWN TOKENIZER.
     *
     * NOT A REGEX, and that is a correction rather than a preference: the first cut stripped block
     * comments with a non-greedy dot-all pattern and silently ate real code -- `PaymentService.php`
     * came back as 122 lines beginning at its namespace, so the census asserted against a fragment
     * and failed on correct files. (Quoting that pattern here is not possible either: its own
     * block-comment terminator would end this docblock, which is how the slip was found.) `token_get_all()` knows what is a comment and what is a string that merely looks
     * like one, which is exactly the distinction a ratchet over source needs.
     *
     * Comments are stripped at all because each repaired file now carries a docblock QUOTING the old
     * line in order to explain it, and a census over raw source would be satisfied by that prose --
     * the recorded trap, which has fired twice in this project.
     */
    private function sourceWithoutComments(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * P6 -- THE SERVER COMPONENTS ARE MERGED IN, so the module cannot be fed the caller's figures.
     *
     * `ManageDeposit()` reads `total_sub_price` OFF THE REQUEST before falling back to its own cart
     * response (`$request['total_sub_price'] ?? $response['total_sub_price']`,
     * PartialPaymentsController:596, :606, :632 and :642). Handing it the request untouched would let the
     * posted figure back in THROUGH the module instead of through the controller -- the same defect
     * one level down, and invisible to every other test here.
     *
     * NO PER-PRODUCT DEPOSIT RULE IN THIS FIXTURE, deliberately. With one, the module prices from the
     * product's own row and `total_sub_price` never enters the answer -- so the merge could be
     * deleted and nothing would notice, which is what a removal check recorded. Without one the
     * module takes the limb that RETURNS that subtotal, so the posted 1.00 and the server's 100.00
     * are the two possible answers and only the merge chooses between them.
     */
    public function test_a_posted_subtotal_cannot_reach_the_modules_pricing(): void
    {
        $this->enablePartialPayments();
        $this->migrateModuleTables();

        [, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $product = $this->productIn($store, 100.00);

        // no depositRuleFor() -- see the docblock
        DB::table('settings')->where('store_id', $store->id)->where('name', 'deposit_selection')->delete();
        (new \App\Models\Setting)->flushCache();

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $this->actingAs($customer, 'customers')
            ->withSession(['easebuzz_request_data' => $this->checkoutState($store, $customer->id, [
                'cartlist_final_price' => 1.00,
                'payment_type' => 'easebuzz',
                'total_sub_price' => 1.00,      // the caller's, and the only figure the module would read
            ])])
            ->post('/' . $store->slug . '/get-payment-easebuzz', ['status' => 'success', 'txnid' => 'pp-' . uniqid()]);

        $order = DB::table('orders')->orderByDesc('id')->first();

        $this->assertNotNull($order, 'the return wrote no order, so this assertion would be vacuous');
        $this->assertNotSame(1.0, round((float) $order->final_price, 2),
            "the module priced the deposit from the CALLER's posted subtotal. Merging the server's "
            . 'components into the request before the call is what stops that.');
        $this->assertSame(100.0, round((float) $order->final_price, 2),
            "the deposit is not the server's own subtotal for the basket.");
    }

    /**
     * P7 -- WHEN THE MODULE THROWS, THE FALLBACK IS THE SERVER TOTAL AND NEVER THE CALLER'S.
     *
     * DRIVEN THROUGH THE SEAM, and the reason is a finding in itself. The only configuration that
     * makes `ManageDeposit()` throw from a real request -- `deposit_selection = pay_deposit` with no
     * `deposit_type` row -- CRASHES THESE HANDLERS EARLIER, at the unguarded
     * `PartialPayments::ManagePartialPayment()` call they make around line 300. MEASURED:
     * `ErrorException: Undefined array key "deposit_type" @ PartialPaymentsController.php:623`,
     * escaping before the order is written at all. That is a pre-existing crash on a vendored read,
     * not this package's defect, and it is reported rather than widened into here.
     *
     * So the catch limb is reachable in production and unreachable from a request in a test. A
     * removal check recorded a fallback of `$request->input('cartlist_final_price', ...)` as
     * SURVIVING for exactly that reason -- an untested fallback on a money path -- which is why
     * `askModuleForDeposit()` exists as a named, overridable boundary.
     */
    public function test_a_module_failure_falls_back_to_the_server_total_and_not_the_posted_one(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $subject = new class {
            use \App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit;

            protected function askModuleForDeposit($request, $slug)
            {
                throw new \RuntimeException('the module blew up');
            }

            public function resolve($request, $slug, array $components, $fallback)
            {
                return $this->partialPaymentDeposit($request, $slug, $components, $fallback);
            }
        };

        $request = \Illuminate\Http\Request::create('/', 'POST', ['cartlist_final_price' => 1.00]);

        $resolved = $subject->resolve($request, 'a-store', ['total_sub_price' => 100.0], 100.0);

        $this->assertNotSame(1.00, $resolved,
            "the module could not price the deposit and the resolver fell back to the CALLER's figure. "
            . 'A failure to price is a reason to charge the full amount, not a reason to accept '
            . 'whatever the browser asked to pay.');
        $this->assertSame(100.0, $resolved, "the fallback is not the server's own total.");

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains((string) $message, 'deposit recomputation failed'))
            ->atLeast()->once();
    }

    /**
     * P8 -- AN UNREADABLE ANSWER FROM THE MODULE IS NOT A TOTAL.
     *
     * Driven directly, because no end-to-end fixture can produce these shapes: the module always
     * answers with a `SetNumber()`-formatted figure, which is exactly why a removal check recorded the
     * `is_numeric` guard as surviving every integration test. It is a type check on a MONEY field
     * across a vendored boundary whose contract is not ours, so it is kept and asserted rather than
     * removed. `ManageDeposit()` has already been observed returning NULL in this very package.
     *
     * @dataProvider unreadableModuleAnswers
     */
    public function test_an_unreadable_module_answer_keeps_the_server_total($answer): void
    {
        $subject = new class {
            use \App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit;

            public function decide($answer, $fallback)
            {
                return $this->depositFromModuleAnswer($answer, $fallback);
            }
        };

        $this->assertSame(100.0, $subject->decide($answer, 100.0),
            'an answer the module cannot be read from became the order total');
    }

    public static function unreadableModuleAnswers(): array
    {
        return [
            'null (measured: what ManageDeposit returns when the basket is empty)' => [null],
            'a string' => ['10.00'],
            'an object' => [new \stdClass()],
            'an array with no deposit key' => [['pending_amount' => '90.00']],
            'a null deposit' => [['deposite_amount' => null]],
            'an empty deposit' => [['deposite_amount' => '']],
            'a non-numeric deposit' => [['deposite_amount' => 'N/A']],
            'a deposit that is an array' => [['deposite_amount' => ['10.00']]],
        ];
    }

    /** And a readable one IS taken, or the guard above would be satisfied by refusing everything. */
    public function test_a_readable_module_answer_is_taken(): void
    {
        $subject = new class {
            use \App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit;

            public function decide($answer, $fallback)
            {
                return $this->depositFromModuleAnswer($answer, $fallback);
            }
        };

        $this->assertSame('10.00', $subject->decide(['deposite_amount' => '10.00'], 100.0));
        $this->assertSame(0, $subject->decide(['deposite_amount' => 0], 100.0),
            'a genuine zero deposit is a figure, not an unreadable answer');
    }

    /**
     * THE REGRESSION THIS PACKAGE ITSELF INTRODUCED, and the read-only review caught.
     *
     * `AuthorizeNetPaymnetController` and `NMIPayController` gate their PartialPayments branch on a
     * BARE `module_is_active('PartialPayments')`, and the first cut of this fix copied that condition
     * to the new early block verbatim. On a route that carries a slug that helper means "THE STORE
     * OWNER'S PLAN BUNDLES THE MODULE" (app/Helper/helper.php:1359-1381) -- not "this store turned
     * partial payments on".
     *
     * For a store in that state the module does not refuse. Its per-product body is skipped
     * (PartialPaymentsController.php:562), control reaches the `else` at :660, and it returns
     * `deposite_amount = "0.00"`. MEASURED, for a plan-bundled store with no `enable_partial_payment`
     * row:
     *
     *     ManageDeposit => {"deposite_amount":"0.00","pending_amount":"0.00",...}
     *
     * `is_numeric("0.00")` is true, so the resolver accepts it, `$partialPaymentDeposit` is non-null,
     * the write site's `??` never fires, and the order persists a total of ZERO while the card is
     * charged the real amount. `OrderFinancialService::createForOrder()` then writes that zero into
     * `store_order_financials` as `paid_amount`. A money regression, introduced by the fix.
     *
     * ASSERTED AT THE MODULE BOUNDARY, not through the route, and the reason is stated rather than
     * hidden: neither affected handler is drivable end to end from this fixture -- AuthorizeNet raises
     * `Undefined variable $requestNet` (:394) and NMI redirects before the order write, both measured.
     * So this drives the two facts the regression is made of: what the module answers for that
     * configuration, and that the resolver accepts it. The condition that keeps the two apart is
     * asserted by the census below.
     */
    public function test_a_plan_that_merely_bundles_the_module_does_not_make_the_module_price_the_order(): void
    {
        $this->enablePartialPayments();
        $this->migrateModuleTables();

        [$merchant, $store] = $this->tenant();

        // THE CONFIGURATION: the plan bundles the module, the store never enabled it.
        DB::table('plans')->where('id', $merchant->plan_id)->update(['modules' => 'PartialPayments']);
        DB::table('settings')->where('store_id', $store->id)->where('name', 'enable_partial_payment')->delete();
        (new \App\Models\Setting)->flushCache();
        Cache::forget('admin_details');

        $customer = $this->customerIn($store);
        $product = $this->productIn($store, 100.00);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        \Auth::guard('customers')->login($customer);

        $answer = \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::ManageDeposit(
            \Illuminate\Http\Request::create('/', 'POST', [
                'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
                'tax_price' => 0, 'shipping_price' => 0, 'coupon_price' => 0, 'total_sub_price' => 100.0,
            ]),
            $store->slug
        );

        $this->assertSame('0.00', $answer['deposite_amount'] ?? null,
            'Premise: for a store that merely has the module in its plan, the module answers 0.00. If '
            . 'this ever changes, the guard below is still right but this test no longer measures the '
            . 'reason for it.');

        $subject = new class {
            use \App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit;

            public function decide($answer, $fallback)
            {
                return $this->depositFromModuleAnswer($answer, $fallback);
            }
        };

        $this->assertSame('0.00', $subject->decide($answer, 100.0),
            'Premise: the resolver ACCEPTS that answer -- is_numeric("0.00") is true -- so nothing '
            . 'downstream of it can stop a zero total. The condition on the call is the only control.');
    }

    /**
     * AND THE CONDITION ITSELF, asserted over both files, because the test above deliberately stops at
     * the module boundary.
     *
     * Every early `partialPaymentDeposit()` block must be gated on the store having partial payments
     * ON and a customer being signed in -- not merely on the plan bundling the module. The four
     * controllers that already carried those limbs at their write site are covered by the same
     * assertion, so a future edit cannot drop them from one file quietly.
     */
    public function test_every_deposit_resolution_is_gated_on_the_store_having_enabled_partial_payments(): void
    {
        $controllers = [
            'AuthorizeNetPaymnetController', 'CinetPayController', 'EasebuzzController',
            'NMIPayController', 'PaiementProPaymentController', 'PayUPaymentController',
        ];

        $checked = 0;

        foreach ($controllers as $name) {
            $code = $this->sourceWithoutComments(app_path('Http/Controllers/' . $name . '.php'));

            /*
             * BOTH LIMBS MUST BE IN THE CONDITION THAT GUARDS THE RESOLUTION, which is why this is one
             * regex over the whole `if (...) { $partialPaymentDeposit =` construct rather than two
             * substring checks over the file.
             *
             * The first cut asserted the customer guard with a plain assertStringContainsString, and a
             * removal check recorded dropping it from the early block as SURVIVING: the file still
             * contained the string, because the WRITE SITE carries its own copy. Keyed on spelling
             * rather than on the construct -- the trap this project has recorded four times.
             */
            $this->assertMatchesRegularExpression(
                '/module_is_active\(\s*.PartialPayments.\s*\)'
                . '(?=.{0,400}?enable_partial_payment)'
                . "(?=.{0,400}?Auth::guard\('customers'\)->user\(\))"
                . '.{0,500}?\$partialPaymentDeposit\s*=/s',
                $code,
                "{$name} resolves a partial-payment deposit without BOTH limbs on the same condition. "
                . 'module_is_active() on a slug route only means the plan bundles the module, and for a '
                . 'bundled-but-disabled store the module answers 0.00 -- which is numeric, so the '
                . "resolver accepts it and the order total becomes zero. And the module's cart read "
                . 'refuses a guest, so without the customer limb every guest checkout logs a false '
                . '"could not be priced".'
            );
            $checked++;
        }

        $this->assertSame(6, $checked, 'six controllers resolve a deposit; a different number means look.');
    }

    /**
     * NMI'S FALLBACK IS THE SERVER PAYABLE, NOT THE BARE CART SUBTOTAL.
     *
     * ASSERTED OVER THE SOURCE, and the weakness of that is stated rather than hidden: this handler
     * cannot be driven end to end from here (it redirects before the order write, measured), so a
     * removal check recorded swapping the two locals as SURVIVING every behavioural test. They are
     * NOT interchangeable -- `$total_sub_price` (:459) is the stored cart list's subtotal, while
     * `$final_sub_total_price` (:474) is `$serverPayableAmount`, documented there as the payable the
     * card was actually charged and therefore the figure the pre-change
     * `$requests_data['cartlist_final_price']` carried on this path. Using the former would quietly
     * change the amount for every store that takes the fallback.
     *
     * A source assertion is the only control available for a money value on an undrivable path. It is
     * recorded as such so nobody mistakes it for execution.
     */
    public function test_the_nmi_fallback_is_the_server_payable_and_not_the_cart_subtotal(): void
    {
        $code = $this->sourceWithoutComments(app_path('Http/Controllers/NMIPayController.php'));

        $this->assertStringContainsString('$order->final_price = $partialPaymentDeposit ?? $final_sub_total_price;', $code,
            'NMI\'s deposit fallback is not the server payable. $total_sub_price is the cart subtotal '
            . 'and a different figure; swapping them changes what every fallback order is charged.');
        $this->assertStringContainsString('$final_sub_total_price = $serverPayableAmount;', $code,
            'Premise: $final_sub_total_price in this file IS the server payable. If that assignment '
            . 'changed, the assertion above no longer means what it says.');
    }

    /**
     * WHAT A REAL SHOPPER CAN ACTUALLY PUT IN THE STORED STATE -- the calibration this package owed,
     * and it CORRECTS AN OVERCLAIM OF MY OWN.
     *
     * The first write-up of this package said "MEASURED: a posted cartlist_final_price=1.00 wrote
     * final_price=1.00". That was measured against a FABRICATED session (`withSession([...])`), which
     * is not a state a shopper can arrange, and a read-only review called it. So the question was put
     * to the real route -- `POST {slug}/process-order`, signed in as a customer, posting
     * `cartlist_final_price = 1.00` -- and the stored state inspected. Three configurations, three
     * different answers, all measured here:
     *
     *   partial payments OFF, plain          stored 100.00  -- the SERVER's figure; the posted 1.00 is gone
     *   partial payments ON,  plain          stored  10.00  -- the MODULE's deposit
     *   partial payments ON,  pending_payment stored   1.00  -- THE CALLER'S OWN FIGURE
     *
     * The first two are `PaymentController::prepareFinalRequestData()` overwriting the key with a
     * server value before `Session::put()` (:1802, :1826). So ON THOSE PATHS the handler-level repair
     * is DEFENCE IN DEPTH, not a live fix, and saying otherwise was wrong.
     *
     * THE THIRD IS LIVE, and it is the narrower path the overclaim accidentally described correctly:
     * `prepareFinalRequestData()`'s own `else` limb (:1831) writes
     * `$new_array['cartlist_final_price'] = $request->payment_amount ?? 0` -- straight from the
     * request, with nothing checking it against the amount actually outstanding. A signed-in shopper
     * of a partial-payments store posts `partial_payment_type=pending_payment&payment_amount=1.00`
     * and the server-stored checkout state carries 1.00. Before this package the six controllers
     * wrote exactly that into `orders.final_price`.
     *
     * THAT UPSTREAM LIMB IS NOT FIXED HERE, deliberately: it is in one of the two sites 569494d2
     * already "repaired", what a pending payment is ALLOWED to be is a product question, and
     * `GuardsGatewayReturn::serverPayableAmount()` reads the same key to decide what AuthorizeNet
     * charges the card. Changing it needs the owner. It is reported in
     * docs/security/P01-ORD-02-PARTIAL-PAYMENT-TOTAL-SOURCE.md section 4.
     *
     * This test exists so the calibration is EXECUTED rather than asserted in prose, and so that a
     * change to `prepareFinalRequestData()` in either direction shows up here.
     *
     * @dataProvider initiationConfigurations
     */
    public function test_what_a_real_initiation_stores_as_the_checkout_total(
        bool $partialOn,
        array $extraPosted,
        string $expectation,
        string $why
    ): void {
        $this->enablePartialPayments();
        $this->migrateModuleTables();

        [$merchant, $store] = $this->tenant();
        DB::table('plans')->where('id', $merchant->plan_id)->update(['modules' => 'PartialPayments']);

        if (! $partialOn) {
            DB::table('settings')->where('store_id', $store->id)
                ->whereIn('name', ['enable_partial_payment', 'deposit_selection', 'deposit_type', 'deposit_amount', 'force_deposit'])
                ->delete();
        }
        (new \App\Models\Setting)->flushCache();
        Cache::forget('admin_details');

        $customer = $this->customerIn($store);
        $product = $this->productIn($store, 100.00);
        if ($partialOn) {
            $this->depositRuleFor($product, $store);
        }

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $posted = array_merge([
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'payment_type' => 'easebuzz',
            'billing_info' => $this->checkoutState($store, $customer->id)['billing_info'],
            'cartlist_final_price' => 1.00,          // THE CALLER'S FIGURE, on the real route
        ], $extraPosted);

        $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order', $posted);

        $stored = session('easebuzz_request_data');

        $this->assertIsArray($stored,
            'Premise: the initiation stored a checkout state. Without one this measures nothing.');
        $this->assertSame($expectation, (string) round((float) ($stored['cartlist_final_price'] ?? -1), 2),
            $why);
    }

    public static function initiationConfigurations(): array
    {
        return [
            'partial payments off, plain checkout' => [
                false, [], '100',
                'prepareFinalRequestData() overwrites the key with the server figure before the session '
                . 'store, so a posted total does not survive. This is why the handler-level repair is '
                . 'defence in depth on this path and not a live fix.',
            ],
            'partial payments on, plain checkout' => [
                true, [], '10',
                "the module's own deposit is stored, not the posted figure.",
            ],
            'partial payments on, pending_payment' => [
                true, ['partial_payment_type' => 'pending_payment', 'payment_amount' => 1.00], '1',
                'prepareFinalRequestData():1831 writes $request->payment_amount into the stored '
                . 'cartlist_final_price with nothing checking it against the amount outstanding. If '
                . 'this ever stops being the caller\'s figure that is an UPSTREAM FIX and good news -- '
                . 'read section 4 of the acceptance document before changing this expectation.',
            ],
        ];
    }

    /**
     * THE CENSUS, and the reason it is keyed on the construct.
     *
     * Three of the six are not drivable from this fixture (see the GATEWAYS docblock for the measured
     * reason for each), so execution alone would leave half the repair unasserted and a future edit
     * could reintroduce the line in one of them silently. This asserts the property over all six
     * files: none of them may read a total out of the stored checkout state, and each must call the
     * server-side resolver.
     *
     * Keyed on `getActionName()`-style evidence -- the trait use and the method call -- rather than on
     * a line number, so an edit above the site cannot break it and a rename cannot satisfy it
     * vacuously. Comments are stripped first, because each file now carries a docblock QUOTING the old
     * line in order to explain it, and a census that greps the raw source would be satisfied by that
     * prose. That trap is recorded in this project and has fired twice.
     */
    public function test_no_host_gateway_controller_reads_an_order_total_out_of_the_posted_state(): void
    {
        $controllers = [
            'AuthorizeNetPaymnetController', 'CinetPayController', 'EasebuzzController',
            'NMIPayController', 'PaiementProPaymentController', 'PayUPaymentController',
        ];

        $checked = 0;

        foreach ($controllers as $name) {
            $path = app_path('Http/Controllers/' . $name . '.php');
            $this->assertFileExists($path, "{$name} was renamed or removed; re-read this census.");

            $code = $this->sourceWithoutComments($path);

            $this->assertStringNotContainsString("final_price = \$requests_data['cartlist_final_price']", $code,
                "{$name} reads the order TOTAL out of the stored checkout state, which PaymentService "
                . 'stores as the whole POST body. A posted figure becomes the persisted total.');
            $this->assertStringNotContainsString("delivery_price = \$requests_data['shipping_price']", $code,
                "{$name} reads the order's SHIPPING out of the stored checkout state.");

            $this->assertStringContainsString('ResolvesPartialPaymentDeposit', $code,
                "{$name} does not use the server-side deposit resolver.");
            $this->assertStringContainsString('$this->partialPaymentDeposit(', $code,
                "{$name} uses the resolver trait but never calls it, which is dead configuration that "
                . 'looks like a control.');
            $checked++;
        }

        $this->assertSame(6, $checked,
            'six host gateway controllers carried this line. A different number means one was added or '
            . 'removed -- look at it rather than changing this number.');
    }

    /**
     * AND THE TWO SITES 569494d2 ALREADY REPAIRED ARE STILL REPAIRED, so this package cannot be read as
     * having moved the defect rather than closed it. Neither had a test until now.
     */
    public function test_the_two_sites_repaired_earlier_still_derive_their_total_server_side(): void
    {
        foreach ([
            'app/Services/PaymentService.php' => 'resolvePartialPaymentDeposit',
            'app/Http/Controllers/PaymentController.php' => 'resolvePartialPaymentAmounts',
        ] as $file => $method) {
            $code = $this->sourceWithoutComments(base_path($file));

            $this->assertStringNotContainsString("final_price = \$requests_data['cartlist_final_price']", $code,
                "{$file} has regressed to reading the order total out of the posted checkout state.");
            $this->assertStringContainsString($method, $code,
                "{$file} no longer derives the partial-payment total server-side.");
        }
    }
}
