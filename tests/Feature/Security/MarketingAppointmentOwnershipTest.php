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
 * P1-SEC-87 — THE APPOINTMENT ROWS, THE SAME SHAPE AS THE CONTACTS P1-SEC-84 CLOSED.
 *
 * Found by following P1-SEC-84's own "still unexamined" note: `appointments/delete/{id}` and
 * `appointment-note-store/{id?}` sit in the SAME Marketing route group as the contact doors and
 * looked like the same pattern. They are.
 *
 * `AppointmentController` (vendored, not edited):
 *   destroy($id)               'Delete Appointment' PERMISSION only, then find($id)->delete()
 *   note_store($id)            'Edit Appointment' only, then writes `status` and `note`
 *   add_note($id)              where('id',$id)->first()
 *   getAppointmentDetails($id) NO CHECK OF ANY KIND -- find($id), then renders the appointment
 *                              modal: a customer's name, contact details and appointment time
 *
 * The module knows these rows are tenant-scoped: `index()` and `getCalenderAllData()` both read
 * `where('store_id', $id)->where('created_by', auth()->user()->creatorId())`. The row handlers
 * just do not apply it, and nothing else does -- `appointment_deatails` (the typo is the vendored
 * migration's) carries `store_id` and `created_by`, `AppointmentDeatail` is absent from
 * `config/tenancy.php` `scoped_models`, and none of these routes was in the manifest.
 */
final class MarketingAppointmentOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function enableMarketing(): void
    {
        AddOnManager::create([
            'module' => 'Marketing', 'name' => 'Marketing',
            'monthly_price' => 0, 'yearly_price' => 0,
            'is_enable' => 1, 'package_name' => 'marketing',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['Marketing']);

        foreach (glob(base_path('packages/workdo/Marketing/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('appointment_deatails'),
            'PREMISE: the module table is absent, so every assertion below would pass for the '
            . 'wrong reason.');
    }

    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id, 'module' => 'Marketing',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app->forgetInstance('lynomia.activated_modules.owner.' . $owner->id);

        $this->assertContains('Marketing', ActivatedModule($owner->id),
            'PREMISE: without the entitlement PlanModuleCheck refuses every request below.');
    }

    private function appointment(Store $store, string $name): int
    {
        $id = (int) DB::table('appointment_deatails')->insertGetId([
            'store_id' => $store->id,
            'name' => $name,
            'email' => strtolower($name) . '@example.test',
            'phone' => '0912345678',
            'created_by' => (int) $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (class_exists(\Workdo\Marketing\app\Models\AppointmentDeatail::class)) {
            (new \Workdo\Marketing\app\Models\AppointmentDeatail)->flushCache();
        }

        return $id;
    }

    private function rowExists(int $id): bool
    {
        return DB::table('appointment_deatails')->where('id', $id)->exists();
    }

    private function arrange(): array
    {
        $this->enableMarketing();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $merchantA->current_store = $storeA->id;
        $merchantA->save();
        $merchantA = User::find($merchantA->id);

        foreach (['Delete Appointment', 'Edit Appointment', 'Manage Appointment'] as $p) {
            $this->grantPermission($merchantA, $p);
        }

        $this->entitle($merchantA);

        return [User::find($merchantA->id), $storeA, $storeB];
    }

    public function test_a_merchant_cannot_delete_another_tenants_appointment(): void
    {
        [$a, $storeA, $storeB] = $this->arrange();

        $theirs = $this->appointment($storeB, 'Rival');
        $mine = $this->appointment($storeA, 'Mine');

        $this->actingAs($a)->post('appointments/delete/' . $theirs);

        $this->assertTrue($this->rowExists($theirs),
            "A merchant deleted ANOTHER TENANT's appointment by id. The handler checks the "
            . "'Delete Appointment' permission and then calls find(\$id) with no tenant predicate.");

        // POSITIVE CONTROL: their OWN row must still delete, or the refusal proves nothing.
        $this->actingAs($a)->post('appointments/delete/' . $mine);

        $this->assertFalse($this->rowExists($mine),
            'A merchant can no longer delete their OWN appointment, so the refusal above says '
            . 'nothing about ownership.');
    }

    /** The worst of the set: no permission check either, and it renders personal data. */
    public function test_a_merchant_cannot_read_another_tenants_appointment_detail(): void
    {
        [$a, , $storeB] = $this->arrange();

        $theirs = $this->appointment($storeB, 'Rival');

        $response = $this->actingAs($a)->get('get-appointment-detail/' . $theirs);

        $this->assertStringNotContainsString('Rival', (string) $response->getContent(),
            "Another tenant's appointment detail was rendered. `getAppointmentDetails()` carries "
            . 'no authorization check of any kind -- not even a permission -- and the view shows '
            . "the customer's name, contact details and appointment time.");
    }

    public function test_a_merchant_cannot_write_a_note_onto_another_tenants_appointment(): void
    {
        [$a, , $storeB] = $this->arrange();

        $theirs = $this->appointment($storeB, 'Rival');

        $this->actingAs($a)->post('appointment-note-store/' . $theirs, [
            'status' => 'done', 'note' => 'planted',
        ]);

        $this->assertNotSame('planted',
            (string) DB::table('appointment_deatails')->where('id', $theirs)->value('note'),
            "A merchant wrote a note onto ANOTHER TENANT's appointment row.");
    }
}
