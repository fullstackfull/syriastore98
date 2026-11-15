<?php

namespace Tests\Feature\Theme;

use App\Facades\ModuleFacade as Module;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\ThemePublicationEvent;
use App\Models\User;
use App\Services\Theme\ThemeConfigurationService;
use App\Services\Theme\ThemePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-6 — PUBLISH AND ROLLBACK OVER HTTP, under the owner's decision of 2026-11-15.
 * =================================================================================================
 *
 * `ThemePublishingService` had NO production caller until this slice. These are the first requests
 * that reach it, so this file is where the owner's rule is proved at the HTTP boundary rather than
 * at the service: the verified tenant owner, a same-tenant holder of `Manage Themes`, or the
 * operator naming a store — and nobody else.
 *
 * EVERY ASSERTION IS ON THE STORED ROW OR AN AUDIT ROW, never on a status code. On this surface a
 * refusal and a success both answer 302, so a status assertion passes in both directions. What
 * distinguishes them is whether `theme_customizes` moved.
 */
class ThemePublishRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE    = 'M4-6-PUBLISHED-BASELINE';
    private const DRAFTED = 'M4-6-DRAFTED-BY-MERCHANT';

    private User $operator;
    private User $owner;
    private User $entitledStaff;
    private User $plainStaff;
    private User $otherMerchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::forceCreate([
            'name' => 'Pub Op', 'email' => 'pub-op@example.test', 'password' => bcrypt('x'),
            'type' => 'super admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->owner = User::forceCreate([
            'name' => 'Pub Owner', 'email' => 'pub-owner@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Pub Store', 'email' => 'pub-s@example.test', 'slug' => 'pub-store',
            'theme_id' => 'stylique', 'created_by' => $this->owner->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->owner->current_store = $this->store->id;
        $this->owner->save();

        $this->entitledStaff = $this->makeStaff('pub-staff-ok@example.test');
        $this->plainStaff    = $this->makeStaff('pub-staff-no@example.test');

        $this->otherMerchant = User::forceCreate([
            'name' => 'Pub Other', 'email' => 'pub-other@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $otherStore = Store::create([
            'name' => 'Other', 'email' => 'pub-os@example.test', 'slug' => 'pub-other-store',
            'theme_id' => 'stylique', 'created_by' => $this->otherMerchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->otherMerchant->current_store = $otherStore->id;
        $this->otherMerchant->save();

        $this->operator->current_store = null;
        $this->operator->save();

        ThemeCustomize::create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::LIVE,
        ]);

        $this->letTheOperatorReachTheController();
    }

    private function makeStaff(string $email): User
    {
        $u = User::forceCreate([
            'name' => 'Staff', 'email' => $email, 'password' => bcrypt('x'),
            'type' => 'staff', 'created_by' => $this->owner->id, 'email_verified_at' => now(),
        ]);
        $u->current_store = $this->store->id;
        $u->save();

        return $u;
    }

    private function grant(User $user): User
    {
        $p = Permission::firstOrCreate(['name' => 'Manage Themes'], ['display_name' => 'Manage Themes']);
        $role = Role::forceCreate([
            'name' => 'pub-role-' . $user->id, 'display_name' => 'Pub', 'guard_name' => 'web',
            'created_by' => $user->creatorId(), 'store_id' => $user->current_store,
        ]);
        $role->givePermission($p);
        $user->addRole($role);
        $user->flushCache();

        return $user->fresh();
    }

    private function letTheOperatorReachTheController(): void
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = collect(File::glob(database_path('migrations/*.php')))->map(fn ($p) => File::name($p));

        foreach (Module::allModules() as $module) {
            foreach ([
                "packages/workdo/{$module->name}/src/Database/Migrations",
                "packages/workdo/{$module->name}/src/database/migrations",
            ] as $dir) {
                if (File::exists($dir)) {
                    $files = $files->merge(collect(File::glob("{$dir}/*.php"))->map(fn ($p) => File::name($p)));
                }
            }
        }

        $batch = 1 + (int) DB::table('migrations')->max('batch');
        foreach (array_diff($files->unique()->all(), $ran) as $name) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
        }
    }

    private function draft(User $as, string $value): \App\Models\ThemeVersion
    {
        $d = (new ThemeConfigurationService())->saveDraft(
            $this->store->id, 'stylique', ['footer_description' => $value], 'draft', $as);

        $this->assertNotNull($d, 'PREMISE: the draft was refused.');

        return $d;
    }

    private function liveValue(): ?string
    {
        return ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->where('theme_id', 'stylique')
            ->where('name', 'footer_description')->value('value');
    }

    /* ========================================================================= PERMITTED */

    #[Test]
    public function test_the_owner_can_publish_a_draft_over_http(): void
    {
        $draft = $this->draft($this->owner, self::DRAFTED);

        $this->actingAs($this->owner)
            ->post("/theme-version/stylique/publish", ['version_id' => $draft->id]);

        $this->assertSame(self::DRAFTED, $this->liveValue(),
            'The store owner could not publish their own draft over HTTP. ThemePublishingService '
            . 'had no production caller before this slice; this is the first request that reaches '
            . 'it.');
    }

    #[Test]
    public function test_same_tenant_staff_holding_manage_themes_can_publish(): void
    {
        $staff = $this->grant($this->entitledStaff);
        $draft = $this->draft($this->owner, self::DRAFTED);

        $this->actingAs($staff)
            ->post("/theme-version/stylique/publish", ['version_id' => $draft->id]);

        $this->assertSame(self::DRAFTED, $this->liveValue(),
            'An entitled same-tenant staff member was refused. Delegation within a tenant is '
            . 'explicitly permitted by the decision.');
    }

    /* =========================================================================== REFUSED */

    #[Test]
    public function test_zero_permission_staff_cannot_publish(): void
    {
        $draft = $this->draft($this->owner, self::DRAFTED);

        $this->actingAs($this->plainStaff)
            ->post("/theme-version/stylique/publish", ['version_id' => $draft->id]);

        $this->assertSame(self::LIVE, $this->liveValue(),
            'Zero-permission staff published to the live storefront.');
    }

    #[Test]
    public function test_another_tenants_merchant_cannot_publish_this_stores_version(): void
    {
        $draft = $this->draft($this->owner, self::DRAFTED);

        $this->actingAs($this->otherMerchant)
            ->post("/theme-version/stylique/publish", ['version_id' => $draft->id]);

        $this->assertSame(self::LIVE, $this->liveValue(),
            'Another tenant published this store\'s version by naming its id.');
    }

    #[Test]
    public function test_the_operator_naming_no_store_cannot_publish(): void
    {
        $draft = $this->draft($this->owner, self::DRAFTED);

        $this->actingAs($this->operator)
            ->post("/theme-version/stylique/publish", ['version_id' => $draft->id]);

        $this->assertSame(self::LIVE, $this->liveValue(),
            'The operator, naming no store, published to a merchant\'s storefront.');
    }

    #[Test]
    public function test_an_anonymous_caller_cannot_publish(): void
    {
        $draft = $this->draft($this->owner, self::DRAFTED);

        $this->post("/theme-version/stylique/publish", ['version_id' => $draft->id]);

        $this->assertSame(self::LIVE, $this->liveValue());
    }

    /* ================================================== the optimistic check, over HTTP */

    /**
     * THE CONFLICT IS REPORTED, NOT RETRIED. The service converts a collision into a refusal and
     * does not retry, because a retry must re-read the live version first — the stale expectation
     * would simply fail again. Retrying silently would publish over whatever the other writer did.
     */
    #[Test]
    public function test_a_stale_expected_version_is_refused_and_writes_nothing(): void
    {
        $first  = $this->draft($this->owner, 'FIRST PUBLISH');
        $second = $this->draft($this->owner, 'SECOND PUBLISH');

        $this->actingAs($this->owner)
            ->post("/theme-version/stylique/publish", ['version_id' => $first->id]);

        $this->assertSame('FIRST PUBLISH', $this->liveValue(), 'PREMISE: the first publish failed.');

        /* An expectation from BEFORE the first publish — the form was open while it happened. */
        $this->actingAs($this->owner)->post("/theme-version/stylique/publish", [
            'version_id'               => $second->id,
            'expected_live_version_id' => 999999,
        ]);

        $this->assertSame('FIRST PUBLISH', $this->liveValue(),
            'A publish carrying a stale expected-live-version id overwrote the newer publish. The '
            . 'optimistic check is the only thing standing between two merchants with the editor '
            . 'open.');
    }

    /* ============================================================== the refusal audit */

    /**
     * M3-D recorded that a refused publish leaves no trace: zero AuditLog references under the
     * theme services. For an operator acting on a merchant's store, the refusal is the interesting
     * event.
     */
    #[Test]
    public function test_a_refused_publish_is_audited_with_actor_and_reason(): void
    {
        $draft = $this->draft($this->owner, self::DRAFTED);
        $before = AuditLog::query()->count();

        $this->actingAs($this->owner)->post("/theme-version/stylique/publish", [
            'version_id'               => $draft->id,
            'expected_live_version_id' => 424242,
        ]);

        $this->assertGreaterThan($before, AuditLog::query()->count(),
            'A refused publish wrote no audit row.');

        $row = AuditLog::query()->latest('id')->first();

        $this->assertSame('theme.publish.refused', $row->action);
        $this->assertSame($this->owner->id, (int) $row->user_id,
            'The audit row does not name the actor.');
        $this->assertSame($this->store->id, (int) $row->store_id,
            'The audit row is attributed to the wrong store. AuditLog::record() would have used '
            . 'getCurrentStore() here, which is the literal-store-1 fallback M4 removed.');
        $this->assertNotEmpty($row->new_values['reason'] ?? null,
            'The audit row records no reason, so it says a refusal happened and not why.');
    }

    /* ======================================================================== rollback */

    #[Test]
    public function test_the_owner_can_roll_back_over_http(): void
    {
        $baseline = (new ThemeConfigurationService())
            ->ensureBaseline($this->store->id, 'stylique', $this->owner);

        $draft = $this->draft($this->owner, self::DRAFTED);

        $this->actingAs($this->owner)
            ->post("/theme-version/stylique/publish", ['version_id' => $draft->id]);
        $this->assertSame(self::DRAFTED, $this->liveValue(), 'PREMISE: the publish failed.');

        $this->actingAs($this->owner)
            ->post("/theme-version/stylique/rollback", ['version_id' => $baseline->id]);

        $this->assertSame(self::LIVE, $this->liveValue(),
            'Rolling back over HTTP did not restore the baseline configuration.');

        $this->assertTrue(
            ThemePublicationEvent::query()
                ->where('store_id', $this->store->id)
                ->where('action', ThemePublicationEvent::ACTION_ROLLBACK)->exists(),
            'The rollback appended no publication event, so history does not record it.');
    }

    #[Test]
    public function test_zero_permission_staff_cannot_roll_back(): void
    {
        $baseline = (new ThemeConfigurationService())
            ->ensureBaseline($this->store->id, 'stylique', $this->owner);
        $draft = $this->draft($this->owner, self::DRAFTED);

        $this->actingAs($this->owner)
            ->post("/theme-version/stylique/publish", ['version_id' => $draft->id]);

        $this->actingAs($this->plainStaff)
            ->post("/theme-version/stylique/rollback", ['version_id' => $baseline->id]);

        $this->assertSame(self::DRAFTED, $this->liveValue(),
            'Zero-permission staff reverted the live storefront. Rollback is the harder action for '
            . 'a merchant to notice, which is why the owner\'s decision covers it explicitly.');
    }

    /* ========================================================================= bad input */

    #[Test]
    public function test_an_unknown_theme_and_a_missing_version_are_refused(): void
    {
        $draft = $this->draft($this->owner, self::DRAFTED);

        $this->actingAs($this->owner)
            ->post("/theme-version/no-such-theme/publish", ['version_id' => $draft->id]);
        $this->assertSame(self::LIVE, $this->liveValue(), 'An unknown theme published.');

        $this->actingAs($this->owner)->post("/theme-version/stylique/publish", []);
        $this->assertSame(self::LIVE, $this->liveValue(), 'A publish with no version id wrote.');
    }
}
