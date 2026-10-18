<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\PaymentController;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11 regression guard for a break the AUTHORIZATION FIX ITSELF introduced, found by the
 * adversarial review of the change rather than by the change's own tests.
 *
 * PaymentController::addCustomerAddress() creates the delivery address for an account made at
 * checkout, and it did so by round-tripping through the storefront API:
 *
 *     $request = new Request($addressData);   // carries 'customer_id' => $customer->id
 *     $api = new ApiController();
 *     $api->add_address($request, $store->slug);   // return value DISCARDED
 *
 * That worked only because add_address had the guard-class fallback this package removed:
 * `$customer_id = $request->customer_id`. Once add_address required an authenticated Customer,
 * this call could no longer satisfy it -- PaymentController:1211-1213 calls createCustomer()
 * BEFORE auth('customers')->login(), and the guest path (1240) never logs in at all. The call
 * returned 401, the address was never written, and because the return value is thrown away
 * nothing surfaced. A shopper who registered at checkout would see an empty address on their
 * profile and lose shipping-method resolution.
 *
 * The fix writes the row directly, where the controller already holds both $customer and $store.
 * The round-trip through a session-authenticated API handler was never the right shape for an
 * internal call.
 *
 * This test exercises the private method through reflection deliberately: it is the unit that
 * broke, and driving the whole checkout would make the assertion depend on a payment gateway.
 */
class CheckoutAddressCreationTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function geography(): array
    {
        $countryId = DB::table('countries')->value('id')
            ?? DB::table('countries')->insertGetId(['name' => 'Testland']);
        $stateId = DB::table('states')->where('country_id', $countryId)->value('id')
            ?? DB::table('states')->insertGetId(['name' => 'Teststate', 'country_id' => $countryId]);
        $cityId = DB::table('cities')->where('state_id', $stateId)->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Testcity', 'state_id' => $stateId]);

        return [$countryId, $stateId, $cityId];
    }

    /**
     * The checkout form posts IDs, not names: the Blade builds
     * Form::select('billing_info[billing_country]', $country_option, $DeliveryAddress->country_id)
     * (resources/views/front_end/common/checkout/checkout_form.blade.php:247), so $country_option
     * is an id => name map. Asserted with real ids because delivery_addresses.country_id,
     * state_id and city_id all carry foreign keys.
     */
    private function billing(array $overrides = []): array
    {
        [$countryId, $stateId, $cityId] = $this->geography();

        return array_merge([
            'firstname' => 'Checkout',
            'lastname' => 'Shopper',
            'email' => 'checkout-' . uniqid() . '@example.test',
            'billing_address' => 'CHECKOUT STREET',
            'billing_country' => $countryId,
            'billing_state' => $stateId,
            'billing_city' => $cityId,
            'billing_postecode' => '22222',
            'billing_user_telephone' => '555000111',
        ], $overrides);
    }

    private function addAddressFor(Customer $customer, array $billing, $store): void
    {
        // Resolved from the container: PaymentController takes a PaymentService.
        $controller = app(PaymentController::class);
        $method = new \ReflectionMethod(PaymentController::class, 'addCustomerAddress');
        $method->setAccessible(true);
        $method->invoke($controller, $customer, $billing, $store);
    }

    private function customerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Checkout', 'last_name' => 'Shopper',
            'email' => 'co-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * THE REGRESSION. No customers session exists at this point in checkout, which is exactly the
     * condition under which the old guard-class fallback was the only thing making this work.
     */
    public function test_checkout_creates_the_delivery_address_without_a_customer_session(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store);
        $billing = $this->billing();

        $this->assertNull(auth('customers')->user(),
            'A customer session exists, so this test is not reproducing the checkout condition.');

        $this->addAddressFor($customer, $billing, $store);

        $address = DeliveryAddress::where('customer_id', $customer->id)->first();
        $this->assertNotNull($address,
            'Checkout did not create the delivery address for the new account. A shopper who '
            . 'registers at checkout gets an empty address on their profile and loses shipping '
            . 'resolution.');
        $this->assertSame('CHECKOUT STREET', $address->address);
        $this->assertSame((int) $store->id, (int) $address->store_id);
        $this->assertSame(1, (int) $address->default_address);
    }

    /** The address must land on the account checkout is creating, and no other. */
    public function test_checkout_writes_the_address_to_the_right_account(): void
    {
        [, $store] = $this->makeTenant();
        $bystander = $this->customerIn($store);
        $customer = $this->customerIn($store);

        $this->addAddressFor($customer, $this->billing(), $store);

        $this->assertSame(1, DeliveryAddress::where('customer_id', $customer->id)->count());
        $this->assertSame(0, DeliveryAddress::where('customer_id', $bystander->id)->count(),
            "Checkout's address write reached another account.");
    }

    /** Setting a new default must demote the account's own previous default, and only its own. */
    public function test_checkout_demotes_only_this_accounts_previous_default(): void
    {
        [, $store] = $this->makeTenant();
        $other = $this->customerIn($store);
        $customer = $this->customerIn($store);

        $othersDefault = DeliveryAddress::forceCreate([
            'customer_id' => $other->id, 'full_name' => 'Other', 'title' => 'main',
            'address' => 'OTHER STREET', 'default_address' => 1, 'store_id' => $store->id,
        ]);
        $ownOldDefault = DeliveryAddress::forceCreate([
            'customer_id' => $customer->id, 'full_name' => 'Own', 'title' => 'old',
            'address' => 'OLD STREET', 'default_address' => 1, 'store_id' => $store->id,
        ]);

        $this->addAddressFor($customer, $this->billing(), $store);

        $this->assertSame(0, (int) $ownOldDefault->refresh()->default_address,
            "The account's own previous default was not demoted, so it now has two.");
        $this->assertSame(1, (int) $othersDefault->refresh()->default_address,
            "Checkout demoted ANOTHER account's default address.");
    }

    /**
     * The API handler this used to call must still refuse an unauthenticated caller -- the point
     * of the fix was to stop routing an internal write through it, not to reopen it.
     */
    public function test_the_api_endpoint_it_used_to_call_still_refuses_an_anonymous_caller(): void
    {
        [, $store] = $this->makeTenant();
        [$countryId, $stateId, $cityId] = $this->geography();

        $response = $this->postJson('/api/' . $store->slug . '/add-address', [
            'customer_id' => $this->customerIn($store)->id,
            'title' => 'main', 'address' => 'X', 'country' => $countryId,
            'state' => $stateId, 'city' => $cityId, 'postcode' => '1', 'default_address' => 1,
        ]);

        $this->assertNotSame(1, $response->json('status'),
            'add-address serves an anonymous caller again.');
        $this->assertSame(0, DeliveryAddress::count());
    }
}
