<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * THE IDENTIFIER LYNOMIA HOLDS FOR A STORE MUST NOT MOVE WHEN CONFIGURATION DOES.
 *
 * `StoreIdentity::of()` composed the value from `config('lynomia.installation_ref')`, which
 * falls back to the host of `APP_URL`. Lynomia PERSISTS what it is told and looks the store
 * up by it, so a domain change -- or one deploy target where the variable is unset -- moved
 * every answer at once: outbound consent decisions 404 and were retired on the first
 * attempt, the reconciliation sweep that would have noticed 404'd too, and inbound events
 * were rejected as speaking for another installation. Three silent, fail-closed outages
 * from an environment variable.
 */
class LynomiaStoreIdentityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithLynomia;

    public function test_the_identifier_is_composed_from_configuration_on_first_use(): void
    {
        [, $store] = $this->entitledTenant();
        LynomiaStoreLink::create(['store_id' => $store->id, 'owner_user_id' => 1, 'state' => 'active', 'link_mode' => 'provisioned']);
        config(['lynomia.installation_ref' => 'first.example']);

        $this->assertSame("first.example:{$store->id}", StoreIdentity::of($store->id));
    }

    public function test_it_is_remembered_so_a_later_configuration_change_cannot_move_it(): void
    {
        [, $store] = $this->entitledTenant();
        LynomiaStoreLink::create(['store_id' => $store->id, 'owner_user_id' => 1, 'state' => 'active', 'link_mode' => 'provisioned']);
        config(['lynomia.installation_ref' => 'first.example']);
        $original = StoreIdentity::of($store->id);

        // A new domain, or a deploy target where LYNOMIA_INSTALLATION_REF is unset and the
        // APP_URL fallback answers instead. The container memo is dropped -- which is what
        // a new request or a new queue job does -- so this is a genuine re-resolution
        // rather than a cached answer. `refreshApplication()` would also drop the in-memory
        // database, which is the opposite of what this example is about.
        config(['lynomia.installation_ref' => 'second.example']);
        app()->forgetScopedInstances();

        $this->assertSame($original, StoreIdentity::of($store->id));
        $this->assertSame($original, LynomiaStoreLink::where('store_id', $store->id)->value('commerce_external_store_id'));
    }

    /** Every link predates the column, so the first call after the upgrade must store what the old code produced. */
    public function test_an_existing_link_keeps_the_value_the_old_code_would_have_produced(): void
    {
        [, $store] = $this->entitledTenant();
        LynomiaStoreLink::create(['store_id' => $store->id, 'owner_user_id' => 1, 'state' => 'active', 'link_mode' => 'provisioned']);
        config(['lynomia.installation_ref' => 'already.live']);

        $this->assertNull(LynomiaStoreLink::where('store_id', $store->id)->value('commerce_external_store_id'));
        $this->assertSame("already.live:{$store->id}", StoreIdentity::of($store->id));
        $this->assertSame("already.live:{$store->id}", LynomiaStoreLink::where('store_id', $store->id)->value('commerce_external_store_id'));
    }

    /** Two stores of one installation are two identities, and neither memo answers for the other. */
    public function test_two_stores_do_not_share_an_identity(): void
    {
        [, $first] = $this->entitledTenant();
        [, $second] = $this->entitledTenant();
        LynomiaStoreLink::create(['store_id' => $first->id, 'owner_user_id' => 1, 'state' => 'active', 'link_mode' => 'provisioned']);
        LynomiaStoreLink::create(['store_id' => $second->id, 'owner_user_id' => 2, 'state' => 'active', 'link_mode' => 'provisioned']);
        config(['lynomia.installation_ref' => 'one.example']);

        $this->assertNotSame(StoreIdentity::of($first->id), StoreIdentity::of($second->id));
    }
}
