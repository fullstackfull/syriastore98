<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-138 / `P5-FIN-6` -- BENEFIT CAPTURE VERIFICATION ON THE STOREFRONT RETURN.
 * =================================================================================================
 *
 * WHAT WAS MEASURED BEFORE ANY CODE WAS CHANGED.
 *
 *   `PaymentService:418`  `case 'benefit': return $this->processBenefit(...)`
 *   `PaymentService:1854` POSTs the charge to `https://api.tap.company/v2/charges`
 *   `PaymentService:1847` `"redirect" => ["url" => route('store.payment.status', $slug)]`
 *   `routes/customer.php:218` that route is `PaymentController::getProductStatus`
 *   `PaymentController:3376` the ONLY charge lookup there is `payment_type == 'Tap'`
 *
 * So the shopper's Benefit return landed on a handler with NO `benefit` arm, fell past every
 * gateway-finality check to `claimGatewayReturn()`, and the order was written. Settlement rested
 * entirely on the shopper's browser reaching the redirect URL -- a URL they can replay, and whose
 * only evidence of payment, `tap_id`, nothing read.
 *
 * THE STOREFRONT RAIL IS LIVE, WHICH IS WHY THIS IS IN SCOPE AT ALL. `$paymentMethod` is
 * `$request->payment_type` (`PaymentController:3184`) validated against `getValidPaymentTypes()`
 * (:2583), whose entry is the LOWER-CASE `'benefit'` -- the spelling `case 'benefit':` matches. That
 * is the opposite of the three VENDORED module rails, where the shipped form posts `'Benefit'`,
 * matches no arm and falls to a 422: intercepting those would have ENABLED a payment that errors
 * today, which GAP-138's own commit refused as a money-path change reserved to the owner. Nothing
 * here enables anything -- a verification branch can only ever refuse -- and no amount, no total and
 * no charge is touched.
 *
 * NO OUTBOUND CALL, EVER, INCLUDING FROM A MUTATION. `App\Package\Payment::getCharge()` is a raw
 * `curl_init()` (`app/Package/Payment.php:154`), so `Http::fake()` cannot see it and there is
 * nothing to intercept -- the same seam gap `GatewayCallbackDestinationTest` records for the
 * vendored `new Client()`. So the lookup is pulled into ONE overridable method on the controller,
 * `benefitChargeStatus()`, exactly as GAP-138's `SendsBenefitCharge::sendBenefitCharge()` did for
 * the POST: a `protected` method with no property, no setter and NO production container binding,
 * so the only way to replace it is to subclass. `bindRecordingController()` below installs that subclass, and it RECORDS what
 * production would have asked. Every case here asserts the recorded calls, so a leg that reached
 * for the network instead would be visible rather than silent.
 *
 * THE ONE DELIBERATE DIVERGENCE FROM THE `Tap` ARM BESIDE IT, stated because it is a behaviour
 * decision and not an oversight. The Tap arm reads `if (isset($request->tap_id))` -- no id, no
 * lookup, order written. Copying that would make this branch defeatable by dropping one query
 * parameter from a URL the shopper already controls, i.e. no verification at all, which is the gap
 * itself. A Benefit return carrying no charge id did not come from Tap, so it is REFUSED here.
 * `Tap`'s own arm is NOT changed: that is a second gateway and a different row, and widening this
 * change to it would alter behaviour for payments nobody asked me to look at.
 */
class BenefitCaptureVerificationTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const SERVER_TOTAL = 100.00;

    /** @var array<string, mixed> */
    private array $state = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // =============================================================================================
    //  The seam: a recording subclass. NOTHING here reaches a network.
    // =============================================================================================

    /**
     * Bind a `PaymentController` whose charge lookup is answered from memory, and keep the record.
     *
     * `$answer` is either an object to hand back (what `getCharge()` returns on success), `false`
     * (what it returns for a null id) or a `\Throwable` to raise (what it does on a curl error or
     * an `errors[]` body -- both measured in `app/Package/Payment.php:174,179`).
     *
     * @return object the recorder, whose `->calls` is a list of [chargeId, storeId]
     */
    private ?object $recorder = null;

    private function bindRecordingController($answer): object
    {
        /*
         * THE ANSWER IS MUTATED, NOT REBOUND, AND THAT COST A REAL FALSE READING.
         *
         * The first form of this helper called `$this->app->bind()` again for each new answer. It
         * does not take: `Illuminate\Routing\Route::getController()` MEMOISES its controller
         * instance on the Route object, and the Router -- with its routes -- survives from one test
         * request to the next inside a single test. So the SECOND request in a test went on using
         * the FIRST request's double.
         *
         * MEASURED: `test_every_refusal_records_its_reason` recorded the reason
         * "the charge is not CAPTURED" TWICE -- once for the declined charge and once where an
         * exception should have been thrown -- and `test_a_refused_charge_does_not_burn_its_own_id`
         * read 0 orders because its CAPTURED retry was still answered DECLINED. Both looked like
         * production defects and neither was: it is the memoised container binding CLAUDE.md's
         * debugging notes name as this codebase's own pollution.
         *
         * So the binding is installed ONCE and the recorder carries the answer. The closure holds
         * the recorder by handle, the memoised controller reads `$this->recorder->answer` at call
         * time, and a later answer therefore reaches an instance built before it was set.
         */
        if ($this->recorder !== null) {
            $this->recorder->answer = $answer;

            return $this->recorder;
        }

        $this->recorder = new class {
            /** @var list<array{0: mixed, 1: mixed}> */
            public array $calls = [];

            public mixed $answer = null;
        };
        $this->recorder->answer = $answer;
        $recorder = $this->recorder;

        $this->app->bind(PaymentController::class, function ($app) use ($recorder) {
            return new class($app->make(\App\Services\PaymentService::class), $recorder) extends PaymentController {
                public function __construct(
                    \App\Services\PaymentService $paymentService,
                    private object $recorder
                ) {
                    parent::__construct($paymentService);
                }

                protected function benefitChargeStatus($chargeId, $storeId)
                {
                    $this->recorder->calls[] = [$chargeId, $storeId];

                    if ($this->recorder->answer instanceof \Throwable) {
                        throw $this->recorder->answer;
                    }

                    return $this->recorder->answer;
                }
            };
        });

        return $recorder;
    }

    /** A Tap charge body, as `getCharge()` hands it back: the decoded JSON object. */
    private function charge(string $status): object
    {
        return json_decode(json_encode([
            'id' => 'chg_benefit_fixture',
            'object' => 'charge',
            'status' => $status,
            'gateway' => ['response' => ['code' => $status === 'CAPTURED' ? '00' : '05']],
        ]));
    }

    // =============================================================================================
    //  Fixture -- a real storefront checkout whose stored state names `benefit`
    // =============================================================================================

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: Customer, 3: Product} */
    private function checkout(array $stateOverrides = []): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'fin6-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
            // The key the branch reads. Present so that a refusal can never be "no key configured".
            'benefit_secret_key' => 'sk_test_not_a_real_key',
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

        $customer = Customer::forceCreate([
            'first_name' => 'Fin6', 'last_name' => 'Buyer',
            'email' => 'fin6-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'fin6-' . $this->uniqueFixtureRef(), 'slug' => 'fin6-' . $this->uniqueFixtureRef(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => self::SERVER_TOTAL, 'sale_price' => self::SERVER_TOTAL,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        DB::table('carts')->insert([
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1,
            'customer_id' => $customer->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->state = array_merge([
            'slug' => $store->slug, 'store_id' => $store->id,
            'customer_id' => $customer->id, 'payment_type' => 'benefit',
            'shipping_final_price' => 0, 'tax_price' => 0,
            'cartlist_final_price' => self::SERVER_TOTAL, 'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ], $stateOverrides);

        return [$merchant, $store, $customer, $product];
    }

    /** Drive the real route the way Tap's redirect does: a GET/POST carrying `tap_id`. */
    private function returnFromBenefit($store, Customer $customer, array $query = [])
    {
        return $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->state])
            ->post('/' . $store->slug . '/get-payment-status?' . http_build_query($query), $this->state);
    }

    private function ordersIn($store): int
    {
        return (int) DB::table('orders')->where('store_id', $store->id)->count();
    }

    // =============================================================================================
    //  P5-FIN-6 -- the refusals
    // =============================================================================================

    /**
     * A charge Tap did not capture writes no order.
     *
     * BOTH HALVES ASSERTED. A status-only assertion passes when the handler 500s for everybody, and
     * an order-count-only assertion passes when the route does not exist.
     */
    public function test_a_benefit_charge_that_is_not_captured_writes_no_order(): void
    {
        [, $store, $customer] = $this->checkout();
        $spy = $this->bindRecordingController($this->charge('DECLINED'));

        $response = $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_declined']);

        $response->assertStatus(302);
        $response->assertSessionHas('error');

        $this->assertSame(
            0,
            $this->ordersIn($store),
            'A Benefit charge Tap reports as DECLINED still produced an order, which is the defect.'
        );

        $this->assertSame(
            [['chg_declined', $store->id]],
            $spy->calls,
            'The branch must ask about the charge id the return carried, for the order\'s own store.'
        );
    }

    /**
     * A return carrying no charge id writes no order AND ASKS NO GATEWAY.
     *
     * This is the leg the `Tap` arm beside it gets wrong with `isset()`: the shopper controls this
     * URL, so a branch that waves through a missing id verifies nothing.
     */
    public function test_a_benefit_return_with_no_charge_id_writes_no_order_and_asks_no_gateway(): void
    {
        [, $store, $customer] = $this->checkout();
        $spy = $this->bindRecordingController($this->charge('CAPTURED'));

        $response = $this->returnFromBenefit($store, $customer);

        $response->assertStatus(302);
        $response->assertSessionHas('error');

        $this->assertSame(0, $this->ordersIn($store), 'A Benefit return with no charge id wrote an order.');
        $this->assertSame(
            [],
            $spy->calls,
            'Nothing may be asked of the gateway when there is no charge id to ask about.'
        );
    }

    /**
     * A NON-SCALAR charge id is refused without a lookup.
     *
     * `?tap_id[]=x` is the shape `GuardsPlanActivation:309` records on the sibling Benefit rail: it
     * satisfies `isset()`, and handing an array to a method typed for a string is a TypeError --
     * a 500 on a money path rather than a refusal.
     */
    public function test_a_non_scalar_charge_id_is_refused_without_a_lookup(): void
    {
        [, $store, $customer] = $this->checkout();
        $spy = $this->bindRecordingController($this->charge('CAPTURED'));

        $response = $this->returnFromBenefit($store, $customer, ['tap_id' => ['x']]);

        $response->assertStatus(302);
        $this->assertSame(0, $this->ordersIn($store));
        $this->assertSame([], $spy->calls, 'An array charge id must not be put to the gateway.');
    }

    /** A lookup that throws -- a curl error, or Tap answering with an `errors[]` body -- refuses. */
    public function test_a_benefit_charge_lookup_that_throws_writes_no_order(): void
    {
        [, $store, $customer] = $this->checkout();
        $spy = $this->bindRecordingController(new \Exception('Error : invalid_key '));

        $response = $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_boom']);

        $response->assertStatus(302);
        $response->assertSessionHas('error');

        $this->assertSame(0, $this->ordersIn($store), 'A failed lookup must not be read as a capture.');
        $this->assertSame([['chg_boom', $store->id]], $spy->calls);
    }

    /**
     * `getCharge()` returns a bare `false` for a null id (`app/Package/Payment.php:188`), and `false`
     * is not a capture. Driven because the branch reads a property on the answer: a non-object that
     * is not refused is either a 500 or an order written on nothing.
     */
    public function test_a_lookup_answering_false_writes_no_order(): void
    {
        [, $store, $customer] = $this->checkout();
        $this->bindRecordingController(false);

        $response = $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_false']);

        $response->assertStatus(302);
        $this->assertSame(0, $this->ordersIn($store));
    }

    // =============================================================================================
    //  THE CONTROL -- without it every assertion above holds with the door bricked shut
    // =============================================================================================

    /**
     * A CAPTURED charge still writes the order.
     *
     * THE LOAD-BEARING CASE. Every refusal above passes if the branch refuses unconditionally, which
     * would be a worse defect than the one being fixed: it would break every real Benefit payment.
     */
    public function test_a_captured_benefit_charge_still_writes_the_order(): void
    {
        [, $store, $customer] = $this->checkout();
        $spy = $this->bindRecordingController($this->charge('CAPTURED'));

        $response = $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_captured']);

        $this->assertSame(
            1,
            $this->ordersIn($store),
            'A CAPTURED Benefit charge must still produce its order; the branch is a verification, '
            . 'not a block. Status ' . $response->getStatusCode()
            . ' error ' . json_encode(session('error'))
        );

        $this->assertSame([['chg_captured', $store->id]], $spy->calls);
    }

    // =============================================================================================
    //  ROUND 2 -- one captured charge pays for ONE order (found by independent review)
    // =============================================================================================

    /**
     * A CAPTURED CHARGE ID CANNOT BE SPENT TWICE, which the first form of this branch allowed.
     *
     * `status === 'CAPTURED'` asked whether a charge was captured and nothing asked WHICH CHECKOUT
     * it paid for. `processBenefit()` sends a CONSTANT reference, so the charge is tied to no
     * basket, and `claimGatewayReturn()` keys on the checkout NONCE for this rail -- a fresh
     * checkout mints a fresh nonce, so a re-used `tap_id` was not a replay. One real 1.00 payment
     * therefore authorised unlimited further Benefit orders of any size.
     *
     * DRIVEN AS THE ATTACK, not as a unit call: two separate checkouts, the SECOND one expensive,
     * replaying the SAME charge id. The first must succeed -- otherwise this case would pass with
     * the door bricked shut -- and the second must be refused with no order written.
     */
    public function test_one_captured_charge_cannot_pay_for_a_second_order(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->bindRecordingController($this->charge('CAPTURED'));

        $first = $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_reused']);

        $this->assertSame(
            1,
            $this->ordersIn($store),
            'premise: the first return with a genuinely captured charge writes its order. '
            . 'Status ' . $first->getStatusCode() . ' error ' . json_encode(session('error'))
        );

        /* A SECOND, SEPARATE CHECKOUT: a new basket and a new stored state, so the checkout nonce
           is fresh and claimGatewayReturn() has nothing to object to. This is the attacker's second
           order, and it is the expensive one. */
        DB::table('carts')->insert([
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1,
            'customer_id' => $customer->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->state['cartlist_final_price'] = 9999.00;

        $second = $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_reused']);

        $this->assertSame(
            302,
            $second->getStatusCode(),
            'the replayed charge was not refused'
        );
        $this->assertSame(
            1,
            $this->ordersIn($store),
            'A SECOND order was written from ONE captured charge. That is the replay the charge '
            . 'claim exists to stop: the charge is bound to no basket and the nonce is fresh, so '
            . 'nothing else on this rail can see it.'
        );
    }

    /**
     * AND THE CLAIM IS NOT TAKEN BY A REFUSAL, so a shopper who completes the payment on a second
     * attempt is not refused for a charge that never paid for anything.
     *
     * The claim sits AFTER the status check deliberately. This case is what makes that ordering a
     * measurement rather than a comment: the same charge id is first seen DECLINED, then CAPTURED.
     */
    public function test_a_refused_charge_does_not_burn_its_own_id(): void
    {
        [, $store, $customer] = $this->checkout();

        $this->bindRecordingController($this->charge('DECLINED'));
        $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_second_try']);
        $this->assertSame(0, $this->ordersIn($store), 'premise: the declined attempt wrote nothing');

        /* The shopper pays, and Tap now reports the same charge CAPTURED. */
        $this->bindRecordingController($this->charge('CAPTURED'));
        $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_second_try']);

        $this->assertSame(
            1,
            $this->ordersIn($store),
            'the charge id was burnt by its own refusal, so a shopper who really paid on the '
            . 'second attempt cannot get their order'
        );
    }

    /**
     * EVERY REFUSAL SAYS WHY, in the log. Until an independent review named it the refusal was
     * SILENT -- so a curl error and a declined charge were indistinguishable, and a Benefit shopper
     * whose charge WAS captured could be refused with nothing anywhere recording it.
     *
     * The REASON is asserted and not merely the presence of a line: "a refusal asserted without its
     * reason" is the trap this project records, and a thrown lookup and a declined charge must not
     * produce the same entry.
     */
    public function test_every_refusal_records_its_reason(): void
    {
        [, $store, $customer] = $this->checkout();

        $entries = [];
        $levels = [];
        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$entries, &$levels) {
            if (str_contains((string) $message->message, 'Benefit return refused')) {
                $entries[] = $message->context['reason'] ?? '';
                $levels[] = $message->level;
            }
        });

        $this->bindRecordingController($this->charge('DECLINED'));
        $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_declined']);

        $this->bindRecordingController(new \Exception('cURL error 6: could not resolve host'));
        $this->returnFromBenefit($store, $customer, ['tap_id' => 'chg_unreachable']);

        $this->bindRecordingController($this->charge('CAPTURED'));
        $this->returnFromBenefit($store, $customer);   // no charge id at all

        $this->assertCount(3, $entries, 'each refusal must record one entry: ' . json_encode($entries));

        $this->assertStringContainsString('not CAPTURED', $entries[0], $entries[0]);
        $this->assertStringContainsString('lookup failed', $entries[1], $entries[1]);
        $this->assertStringContainsString('no usable charge id', $entries[2], $entries[2]);

        $this->assertNotSame(
            $entries[0],
            $entries[1],
            'a declined charge and an unreachable provider recorded the SAME reason, so the log '
            . 'cannot tell a refusal from an outage'
        );

        /*
         * THE LEVEL IS ASSERTED, AND A SURVIVING MUTANT IS WHY. Changing `Log::warning` to
         * `Log::debug` left this case GREEN, because `Log::listen` receives every level -- so the
         * test proved an entry existed and said nothing about whether anyone would ever see it.
         * `docs/security/P01-OBSERVABILITY-INVENTORY.md` tracks `critical` and `warning` ONLY, so a
         * quiet drop to `debug` would remove this line from the declared set and from any alerting
         * while every assertion above still held.
         */
        $this->assertSame(
            ['warning', 'warning', 'warning'],
            $levels,
            'a Benefit refusal must be logged at `warning`: the observability inventory tracks only '
            . 'critical and warning, so any other level is an entry nobody is accountable for. Got: '
            . json_encode($levels)
        );
    }

    /**
     * THE NEIGHBOURING RAIL IS UNTOUCHED. A `Tap` return does not enter the Benefit branch, so
     * nothing about the second gateway on this handler changed -- asserted rather than assumed,
     * because the two arms sit next to each other and read the same request key.
     */
    public function test_a_tap_return_does_not_enter_the_benefit_branch(): void
    {
        [, $store, $customer] = $this->checkout(['payment_type' => 'Tap']);
        $spy = $this->bindRecordingController($this->charge('CAPTURED'));

        $this->returnFromBenefit($store, $customer, []);

        $this->assertSame(
            [],
            $spy->calls,
            'A Tap return reached the Benefit charge lookup, so the two arms are not separate.'
        );
    }
}
