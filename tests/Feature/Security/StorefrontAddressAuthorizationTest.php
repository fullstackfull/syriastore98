<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryBoy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11 — the four storefront DELIVERY-ADDRESS handlers: add_address, update_address,
 * change_address and delete_address.
 *
 * WHY THESE ARE GROUPED AND WHY THEY MATTER MORE THAN A READ. An order ships to the customer's
 * default address, so write access to this table is control of a shipping destination: plant an
 * address, mark it default, and the victim's next order is delivered to the attacker. Each of the
 * four reached it a different way.
 *
 *   add_address     -- the guard-class trap, writing. The else branch took
 *                      `$customer_id = $request->customer_id`, so a merchant's or driver's token
 *                      created an address on an arbitrary account. The demotion keyed on
 *                      `$request->customer_id` on BOTH branches, so even a legitimate shopper
 *                      demoted the defaults of whichever account they named.
 *   update_address  -- two attacker-chosen ids, never compared, and it then WROTE
 *                      `customer_id = $request->customer_id` onto the row, re-parenting it. So you
 *                      could overwrite a victim's address, or move your own onto their account.
 *                      `$store` was resolved and never read -- not even an empty() gate.
 *   change_address  -- keyed the 'main' address on `$request->customer_id`, and CREATED one when
 *                      none existed, so it wrote or created any customer's main address.
 *   delete_address  -- read no principal id at all: no customer_id, no Customer::find, no
 *                      getStore. `address_id` alone deleted any address row on the platform.
 *
 * delete_address is the one member with no posted customer id to ignore, so its fix is an
 * ownership predicate rather than a derived target, and its refusal reuses its own
 * 'Address not found.' message at HTTP 200 (the order_detail precedent) so that "not yours" is
 * indistinguishable from "no such row" and the endpoint cannot enumerate address ids.
 */
class StorefrontAddressAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'addr-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function driverFor(User $merchant, $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver',
            'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
    }

    private function addressFor($store, Customer $customer, string $title, array $attributes = []): DeliveryAddress
    {
        return DeliveryAddress::forceCreate(array_merge([
            'customer_id' => $customer->id,
            'full_name' => 'Owner ' . $customer->id,
            'title' => $title,
            'address' => 'ORIGINAL-STREET',
            'postcode' => 11111,
            'default_address' => 1,
            'store_id' => $store->id,
        ], $attributes));
    }

    private function callApi($store, string $path, array $payload, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->postJson('/api/' . $store->slug . '/' . $path, $payload);
    }

    /**
     * A valid, complete address payload, so a refusal is never mistaken for a validation error or
     * a crash.
     *
     * `city` is an ID, not a name: the handler assigns it to `delivery_addresses.city_id`, which
     * carries a real foreign key to `cities`
     * (2023_12_18_062620_create_delivery_addresses_table.php:32). Sending a city NAME makes every
     * write 500 with a constraint violation -- which is how the first run of this file "passed"
     * all of its denial tests while every legitimate journey crashed. A denial measured against a
     * handler that fails for everyone proves nothing, so the ids are created here explicitly.
     */
    private function payload(array $overrides = []): array
    {
        $countryId = DB::table('countries')->value('id');
        if ($countryId === null) {
            $countryId = DB::table('countries')->insertGetId(['name' => 'Testland']);
        }

        $stateId = DB::table('states')->where('country_id', $countryId)->value('id');
        if ($stateId === null) {
            $stateId = DB::table('states')->insertGetId(['name' => 'Teststate', 'country_id' => $countryId]);
        }

        $cityId = DB::table('cities')->where('state_id', $stateId)->value('id');
        if ($cityId === null) {
            $cityId = DB::table('cities')->insertGetId(['name' => 'Testcity', 'state_id' => $stateId]);
        }

        return array_merge([
            'title' => 'ATTACKER-TITLE',
            'address' => 'ATTACKER-STREET',
            'country' => $countryId,
            'state' => $stateId,
            'city' => $cityId,
            'postcode' => 99999,
            'default_address' => 1,
        ], $overrides);
    }

    // ===================================================================================
    // delete_address — the IDOR delete
    // ===================================================================================

    public function test_a_merchant_token_cannot_delete_an_address(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'd1');
        $address = $this->addressFor($store, $victim, 'main');

        $response = $this->callApi($store, 'delete-address', ['address_id' => $address->id],
            $merchant->createToken('t')->plainTextToken);

        $this->assertNotNull(DeliveryAddress::find($address->id),
            "A merchant's token deleted a shopper's address.");
        $this->assertSame(0, $response->json('status'));
        $this->assertSame(200, $response->getStatusCode(),
            'The refusal changed the HTTP status, which a Flutter client reads as a forced logout.');
    }

    public function test_a_driver_token_cannot_delete_an_address(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'd2');
        $driver = $this->driverFor($merchant, $store);
        $address = $this->addressFor($store, $victim, 'main');

        $this->callApi($store, 'delete-address', ['address_id' => $address->id],
            $driver->createToken('t')->plainTextToken);

        $this->assertNotNull(DeliveryAddress::find($address->id),
            "A driver's token deleted a shopper's address.");
    }

    public function test_one_shopper_cannot_delete_anothers_address(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'd3-victim');
        $attacker = $this->customerIn($store, 'd3-attacker');
        $address = $this->addressFor($store, $victim, 'main');

        $this->callApi($store, 'delete-address', ['address_id' => $address->id],
            $attacker->createToken('t')->plainTextToken);

        $this->assertNotNull(DeliveryAddress::find($address->id),
            "One shopper deleted another's address by naming its id.");
    }

    public function test_a_shopper_cannot_delete_an_address_in_another_store(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $victim = $this->customerIn($storeA, 'd4-victim');
        $attacker = $this->customerIn($storeB, 'd4-attacker');
        $address = $this->addressFor($storeA, $victim, 'main');

        $this->callApi($storeB, 'delete-address', ['address_id' => $address->id],
            $attacker->createToken('t')->plainTextToken);

        $this->assertNotNull(DeliveryAddress::find($address->id),
            "A shopper of store B deleted store A's address.");
    }

    public function test_a_shopper_can_still_delete_their_own_address(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd5');
        $address = $this->addressFor($store, $customer, 'main');

        $this->callApi($store, 'delete-address', ['address_id' => $address->id],
            $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertNull(DeliveryAddress::find($address->id),
            'A shopper can no longer delete their own address.');
    }

    // ===================================================================================
    // update_address — overwrite and re-parent
    // ===================================================================================

    public function test_a_merchant_token_cannot_overwrite_an_address(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'u1');
        $address = $this->addressFor($store, $victim, 'home');

        $this->callApi($store, 'update-address',
            $this->payload(['address_id' => $address->id, 'customer_id' => $victim->id]),
            $merchant->createToken('t')->plainTextToken);

        $address->refresh();
        $this->assertSame('ORIGINAL-STREET', $address->address,
            "A merchant's token overwrote a shopper's address.");
    }

    public function test_one_shopper_cannot_overwrite_anothers_address(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'u2-victim');
        $attacker = $this->customerIn($store, 'u2-attacker');
        $address = $this->addressFor($store, $victim, 'home');

        $this->callApi($store, 'update-address',
            $this->payload(['address_id' => $address->id, 'customer_id' => $victim->id]),
            $attacker->createToken('t')->plainTextToken);

        $address->refresh();
        $this->assertSame('ORIGINAL-STREET', $address->address,
            "One shopper overwrote another's address.");
        $this->assertSame((int) $victim->id, (int) $address->customer_id,
            "The address was re-parented away from its owner.");
    }

    /**
     * The re-parenting limb specifically: moving the CALLER's OWN address onto a victim's account.
     * The handler wrote `customer_id = $request->customer_id` onto the row it had just found by a
     * separate id, so the two ids did not have to agree.
     */
    public function test_a_shopper_cannot_move_their_own_address_onto_another_account(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'u3-victim');
        $attacker = $this->customerIn($store, 'u3-attacker');
        $attackerAddress = $this->addressFor($store, $attacker, 'home');
        $victimAddress = $this->addressFor($store, $victim, 'main');

        $this->callApi($store, 'update-address',
            $this->payload(['address_id' => $attackerAddress->id, 'customer_id' => $victim->id]),
            $attacker->createToken('t')->plainTextToken);

        $attackerAddress->refresh();
        $this->assertSame((int) $attacker->id, (int) $attackerAddress->customer_id,
            "A shopper re-parented their own address onto another account, so that account's next "
            . 'order would ship to them.');

        $victimAddress->refresh();
        $this->assertSame(1, (int) $victimAddress->default_address,
            "The victim's own default address was demoted.");
    }

    public function test_a_shopper_can_still_update_their_own_address(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'u4');
        $address = $this->addressFor($store, $customer, 'home');

        $this->callApi($store, 'update-address',
            $this->payload(['address_id' => $address->id, 'customer_id' => $customer->id, 'address' => 'NEW-STREET']),
            $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $address->refresh();
        $this->assertSame('NEW-STREET', $address->address,
            'A shopper can no longer update their own address.');
        $this->assertSame((int) $customer->id, (int) $address->customer_id);
    }

    /** Their own second address is still demoted when they set a new default. */
    public function test_setting_a_default_still_demotes_the_callers_other_addresses(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'u5');
        $first = $this->addressFor($store, $customer, 'home', ['default_address' => 1]);
        $second = $this->addressFor($store, $customer, 'work', ['default_address' => 0]);

        $this->callApi($store, 'update-address',
            $this->payload(['address_id' => $second->id, 'customer_id' => $customer->id, 'default_address' => 1]),
            $customer->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 1);

        $this->assertSame(1, (int) $second->refresh()->default_address);
        $this->assertSame(0, (int) $first->refresh()->default_address,
            'The previous default was not demoted, so the account now has two defaults.');
    }

    // ===================================================================================
    // change_address — writes or CREATES the 'main' address
    // ===================================================================================

    public function test_a_merchant_token_cannot_write_a_shoppers_main_address(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'c1');

        $this->callApi($store, 'change-address',
            $this->payload(['customer_id' => $victim->id]),
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, DeliveryAddress::where('customer_id', $victim->id)->count(),
            "A merchant's token created a main address on a shopper's account.");
    }

    public function test_one_shopper_cannot_overwrite_anothers_main_address(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'c2-victim');
        $attacker = $this->customerIn($store, 'c2-attacker');
        $address = $this->addressFor($store, $victim, 'main');

        $this->callApi($store, 'change-address',
            $this->payload(['customer_id' => $victim->id]),
            $attacker->createToken('t')->plainTextToken);

        $address->refresh();
        $this->assertSame('ORIGINAL-STREET', $address->address,
            "One shopper overwrote another's main address, redirecting their deliveries.");
    }

    public function test_a_shopper_can_still_set_their_own_main_address(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'c3');

        $this->callApi($store, 'change-address',
            $this->payload(['customer_id' => $customer->id, 'address' => 'MY-OWN-STREET']),
            $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $main = DeliveryAddress::where('customer_id', $customer->id)->where('title', 'main')->first();
        $this->assertNotNull($main, 'A shopper can no longer create their own main address.');
        $this->assertSame('MY-OWN-STREET', $main->address);
    }

    /**
     * The internal user_detail() call was made without $slug, so it resolved no store and the
     * nested `data` was an error payload rather than the profile. Passing the slug makes it work.
     * Asserted because it is a behaviour change in this commit, even though it is a repair.
     */
    public function test_change_address_returns_the_profile_in_its_data(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'c4');

        $response = $this->callApi($store, 'change-address',
            $this->payload(['customer_id' => $customer->id, 'address' => 'PROFILE-STREET']),
            $customer->createToken('t')->plainTextToken);

        $response->assertJsonPath('status', 1);
        $this->assertSame($customer->email, $response->json('data.data.email'),
            "change-address did not return the caller's profile in its nested data.");
    }

    // ===================================================================================
    // add_address — the guard-class trap, writing
    // ===================================================================================

    public function test_a_merchant_token_cannot_add_an_address_to_a_shoppers_account(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a1');
        $existing = $this->addressFor($store, $victim, 'main');

        $this->callApi($store, 'add-address',
            $this->payload(['customer_id' => $victim->id]),
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(1, DeliveryAddress::where('customer_id', $victim->id)->count(),
            "A merchant's token planted a delivery address on a shopper's account.");
        $this->assertSame(1, (int) $existing->refresh()->default_address,
            "The shopper's own default address was demoted.");
    }

    public function test_a_driver_token_cannot_add_an_address_to_a_shoppers_account(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a2');
        $driver = $this->driverFor($merchant, $store);

        $this->callApi($store, 'add-address',
            $this->payload(['customer_id' => $victim->id]),
            $driver->createToken('t')->plainTextToken);

        $this->assertSame(0, DeliveryAddress::where('customer_id', $victim->id)->count(),
            "A driver's token planted a delivery address on a shopper's account.");
    }

    /**
     * The demotion keyed on `$request->customer_id` on BOTH branches, so this was reachable by an
     * ordinary shopper with a valid token and not only by a wrong-class principal.
     */
    public function test_adding_an_address_does_not_demote_another_accounts_defaults(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a3-victim');
        $attacker = $this->customerIn($store, 'a3-attacker');
        $victimDefault = $this->addressFor($store, $victim, 'main', ['default_address' => 1]);

        $this->callApi($store, 'add-address',
            $this->payload(['customer_id' => $victim->id]),
            $attacker->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 1);

        $this->assertSame(1, (int) $victimDefault->refresh()->default_address,
            "A shopper's new address demoted ANOTHER account's default address.");
        $this->assertSame(1, DeliveryAddress::where('customer_id', $attacker->id)->count(),
            "The address was not created on the caller's own account.");
    }

    public function test_a_shopper_can_still_add_their_own_address(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'a4');

        $this->callApi($store, 'add-address',
            $this->payload(['address' => 'MY-NEW-STREET']),
            $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $created = DeliveryAddress::where('customer_id', $customer->id)->first();
        $this->assertNotNull($created, 'A shopper can no longer add their own address.');
        $this->assertSame('MY-NEW-STREET', $created->address);
        $this->assertSame((int) $store->id, (int) $created->store_id);
    }

    /**
     * add_address required `customer_id` only on the branch this fix deleted, so a client that
     * still sends it must not be rejected -- and the value must be inert.
     */
    public function test_add_address_ignores_a_posted_customer_id(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'a5');
        $other = $this->customerIn($store, 'a5-other');

        $this->callApi($store, 'add-address',
            $this->payload(['customer_id' => $other->id, 'address' => 'INERT-STREET']),
            $customer->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 1);

        $this->assertSame(1, DeliveryAddress::where('customer_id', $customer->id)->count(),
            "The address did not land on the caller's own account.");
        $this->assertSame(0, DeliveryAddress::where('customer_id', $other->id)->count(),
            'The posted customer_id still steered the write.');
    }

    public function test_an_anonymous_caller_is_refused_on_every_address_endpoint(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'z1');
        $address = $this->addressFor($store, $customer, 'main');

        // A premise check on the middleware, not on these handlers: custom.auth refuses a missing
        // bearer token before any of them runs. Recorded because every denial above assumes it.
        foreach (['add-address', 'update-address', 'change-address', 'delete-address'] as $path) {
            $response = $this->callApi($store, $path,
                $this->payload(['customer_id' => $customer->id, 'address_id' => $address->id]), null);
            $this->assertNotSame(1, $response->json('status'), "$path served an anonymous caller.");
        }

        $this->assertNotNull(DeliveryAddress::find($address->id));
        $this->assertSame('ORIGINAL-STREET', $address->refresh()->address);
    }
}
