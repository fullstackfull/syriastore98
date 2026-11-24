<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-84 — A MARKETING CONTACT ROW IS READ, REWRITTEN AND DELETED BY ID, FOR ANY TENANT.
 *
 * `Workdo\Marketing\...\ContactController` (vendored, not edited):
 *   destroy($id)     -- `isAbleTo('Delete Marketing Contact')` then `MarketingContact::find($id)->delete()`
 *   edit($id)        -- NO check at all; `where('id',$id)->first()` and renders the row
 *   update($id)      -- validates the FIELDS, then `where('id',$id)->first()` and overwrites them
 *   note_store($id)  -- `isAbleTo('Edit Marketing Contact')` then writes `status` and `note`
 *
 * Not one of them carries a tenant predicate, and nothing else supplies one:
 *
 *   - `MarketingContact` is ABSENT from `config/tenancy.php` `scoped_models`, and the model
 *     declares only `HasFactory, Cachable` -- no `BelongsToStore`, no `StoreScope` -- so
 *     `find($id)` reaches every tenant's rows. (Checked, because `getGlobalScopes()` is not the
 *     same question as "is it scoped".)
 *   - the only manifest entries for Marketing contacts were the six STOREFRONT verbs of
 *     `marketing/contacts/store/{slug}`, the public submit form. None of the administrative
 *     id-bearing routes was classified, so `administrativeRule()` returned null and
 *     `ModuleAdministrativeRouteGuard` passed through before it read the user.
 *
 * The routes DO carry `['auth', 'web', 'PlanModuleCheck:Marketing']`, so authentication and plan
 * entitlement are present. What was missing is ownership, which is why the fix is a manifest rule
 * and not a middleware addition.
 *
 * The module's own code knows contacts are store-scoped: `MarketingContact::cardContactData()`
 * reads `where('store_id', getCurrentStore())`. That is why `store-row:marketing_contacts:id` is
 * the right rule and not merely a convenient one -- it compares the row's `store_id` to the
 * caller's `current_store`, which is the same question.
 */
final class MarketingContactOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The module, loaded through the PRODUCTION loader. `RefreshDatabase` runs
     * `database/migrations` only, so the module's own tables have to be created here.
     */
    private function enableMarketing(): void
    {
        AddOnManager::create([
            'module' => 'Marketing',
            'name' => 'Marketing',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'marketing',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['Marketing']);

        foreach (glob(base_path('packages/workdo/Marketing/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('marketing_contacts'),
            'PREMISE: the module did not load, so every refusal below would be a 404 or a redirect '
            . 'and would prove nothing.');
    }

    /**
     * THE PLAN ENTITLEMENT, without which `PlanModuleCheck` refuses every request below and each
     * assertion passes without reaching the handler. It resolves against a `user_active_modules`
     * row keyed on the OWNER's id, and the lookup is memoised in a container `scoped()` binding,
     * so the row must exist BEFORE the request that reads it.
     */
    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id,
            'module' => 'Marketing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app->forgetInstance('lynomia.activated_modules.owner.' . $owner->id);

        $this->assertContains('Marketing', ActivatedModule($owner->id),
            'PREMISE: the entitlement fixture did not take, so PlanModuleCheck would refuse every '
            . 'request below and each assertion would pass without reaching the handler.');
    }

    /** A contact row owned by a store. Written raw, then the model cache is flushed, because `MarketingContact` is `Cachable`. */
    private function contact(Store $store, string $name): int
    {
        $id = (int) DB::table('marketing_contacts')->insertGetId([
            'store_id' => $store->id,
            'name' => $name,
            'email' => strtolower($name) . '@example.test',
            'phone' => '0912345678',
            'message' => 'original message for ' . $name,
            'created_by' => (int) $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (class_exists(\Workdo\Marketing\app\Models\MarketingContact::class)) {
            (new \Workdo\Marketing\app\Models\MarketingContact)->flushCache();
        }

        return $id;
    }

    /** Raw, so the answer is the TABLE's and not whichever tenant a global scope happened to resolve. */
    private function rowExists(int $id): bool
    {
        return DB::table('marketing_contacts')->where('id', $id)->exists();
    }

    private function rowField(int $id, string $column): ?string
    {
        $v = DB::table('marketing_contacts')->where('id', $id)->value($column);

        return $v === null ? null : (string) $v;
    }

    /** Merchant A (entitled, permitted, with a store) and merchant B's store and contact. */
    private function arrange(): array
    {
        $this->enableMarketing();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $merchantA->current_store = $storeA->id;
        $merchantA->save();
        $merchantA = User::find($merchantA->id);

        $this->grantPermission($merchantA, 'Delete Marketing Contact');
        $this->grantPermission($merchantA, 'Edit Marketing Contact');
        $this->entitle($merchantA);

        return [User::find($merchantA->id), $storeA, $storeB];
    }

    public function test_a_merchant_cannot_delete_another_tenants_contact(): void
    {
        [$merchantA, $storeA, $storeB] = $this->arrange();

        $theirs = $this->contact($storeB, 'Rival');
        $mine = $this->contact($storeA, 'Mine');

        $this->actingAs($merchantA)->post('contacts/delete/' . $theirs);

        $this->assertTrue($this->rowExists($theirs),
            "A merchant deleted ANOTHER TENANT's marketing contact by id. The handler checks the "
            . "'Delete Marketing Contact' permission and then calls MarketingContact::find(\$id) "
            . 'with no tenant predicate, and the model is not scoped.');

        // POSITIVE CONTROL: the same request against their OWN row must still work, or the
        // refusal above is just a broken fixture.
        $this->actingAs($merchantA)->post('contacts/delete/' . $mine);

        $this->assertFalse($this->rowExists($mine),
            'A merchant can no longer delete their OWN contact, so the refusal above proves '
            . 'nothing about ownership.');
    }

    /**
     * `contacts/update/{id}` IS INERT FOR EVERYONE, AND THAT IS WHY IT IS NOT A WRITE DEFECT.
     *
     * MEASURED, after a positive control refused to pass. `update()`'s signature is
     * `update(Request $request, Contacts $Contacts, $id)`, where `Contacts` is
     * `Melipayamak\Contacts` (imported at the top of the controller -- an SMS vendor class, almost
     * certainly a stray auto-import). Its constructor takes TWO REQUIRED, UNTYPED arguments
     * (`$username`, `$password` -- vendor/melipayamak/php/src/Contacts.php), so Laravel's
     * container cannot build it as a method dependency and the request throws before the handler
     * body runs. `class_exists()` is true, which is why this is not obvious: the package IS
     * installed (composer.json requires `melipayamak/php: ^1.0`). It is instantiability, not
     * existence, that fails.
     *
     * So the row is unchanged for the attacker AND for the owner. The first version of this case
     * asserted only the former and PASSED against unguarded code -- a cross-tenant refusal that
     * was really a 500. The positive control is what exposed it, and both halves are asserted
     * here so the distinction cannot be lost again.
     *
     * IT STILL GETS THE MANIFEST RULE. The guard must not depend on a vendor constructor
     * signature: if the stray import is ever removed or the parameter bound, this becomes a live
     * cross-tenant write with no further warning. The rule makes that repair safe instead of
     * dangerous.
     */
    public function test_the_update_route_writes_nothing_for_anybody_so_it_is_inert_not_exploitable(): void
    {
        [$merchantA, $storeA, $storeB] = $this->arrange();

        $theirs = $this->contact($storeB, 'Rival');
        $mine = $this->contact($storeA, 'Mine');

        $payload = [
            'name' => 'Taken Over',
            'email' => 'attacker@example.test',
            'phone' => '0999999999',
            'message' => 'rewritten',
        ];

        $this->actingAs($merchantA)->post('contacts/update/' . $theirs, $payload);
        $this->actingAs($merchantA)->post('contacts/update/' . $mine, $payload);

        $this->assertSame('Rival', $this->rowField($theirs, 'name'),
            "Another tenant's contact was rewritten through the update door.");

        $this->assertSame('Mine', $this->rowField($mine, 'name'),
            'The update door wrote to the OWNER\'s row, which means it is live after all -- so '
            . 'the cross-tenant assertion above is a real control and this case must be rewritten '
            . 'as a genuine ownership test rather than an inertness pin.');
    }

    public function test_a_merchant_cannot_read_another_tenants_contact(): void
    {
        [$merchantA, , $storeB] = $this->arrange();

        $theirs = $this->contact($storeB, 'Rival');

        $response = $this->actingAs($merchantA)->get('contacts/edit/' . $theirs);

        $this->assertStringNotContainsString('Rival', (string) $response->getContent(),
            "Another tenant's contact name was rendered on the edit screen. `edit()` carries no "
            . 'authorization check of any kind -- not even a permission.');
    }

    public function test_a_merchant_cannot_write_a_note_onto_another_tenants_contact(): void
    {
        [$merchantA, , $storeB] = $this->arrange();

        $theirs = $this->contact($storeB, 'Rival');

        $this->actingAs($merchantA)->post('contact-note-store/' . $theirs, [
            'status' => 'contacted',
            'note' => 'planted',
        ]);

        $this->assertNotSame('planted', $this->rowField($theirs, 'note'),
            "A merchant wrote a note onto ANOTHER TENANT's contact row.");
    }
}
