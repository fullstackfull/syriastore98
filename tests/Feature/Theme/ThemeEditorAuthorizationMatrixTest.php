<?php

namespace Tests\Feature\Theme;

use App\Facades\ModuleFacade as Module;
use App\Models\Addon;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-2 — THE APPROVED AUTHORIZATION BOUNDARY, DRIVEN OVER HTTP FOR EVERY PRINCIPAL.
 * =================================================================================================
 *
 * OWNER DECISION, 2026-11-15. A live theme write is permitted to exactly three principals:
 *
 *     the verified tenant OWNER            (`stores.created_by` === this user's own id)
 *     a SAME-TENANT user holding `Manage Themes`
 *     the platform operator, naming a store EXPLICITLY (never store 1 by fallback)
 *
 * and two constraints are stated as absolutes, so each has a test here that fails if it is ever
 * relaxed:
 *
 *   "A global permission alone must NEVER authorize access to another tenant."
 *       `Manage Themes` is granted to the shared GLOBAL `admin` role by `Utility::addNewData()`,
 *       so two merchants in different tenants hold the SAME row. Testing it before the tenant
 *       predicate would be a cross-tenant hole rather than an entitlement.
 *       -> test_a_permission_holder_from_another_tenant_cannot_write
 *
 *   "Do not infer tenant ownership from `type=admin` alone."
 *       Every merchant on the platform is type-`admin`. Reading that as ownership would make each
 *       of them the owner of every store.
 *       -> test_another_tenants_merchant_cannot_write  (same type, different tenant)
 *
 * -------------------------------------------------------------------------------------------------
 * EVERY ASSERTION IS ON THE STORED ROW, NOT ON THE STATUS CODE, and that is the owner's explicit
 * requirement to verify real handler execution. On this surface a refusal and a success BOTH answer
 * `302`: the middleware refuses with `redirect()->back()` and the controller succeeds with a
 * redirect too. A test that asserted a status would pass in both directions. What distinguishes
 * them is whether `theme_customizes` moved.
 *
 * The reads are asserted the same way -- on rendered CONTENT -- because `layouts/app.blade.php`
 * `@yield`s and renders perfectly good chrome at 200 for a view that produces nothing.
 */
class ThemeEditorAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const BEFORE = 'M4-2-BASELINE-VALUE';

    private User $operator;
    private User $owner;
    private User $entitledStaff;
    private User $plainStaff;
    private User $otherMerchant;
    private User $otherEntitled;
    private User $demoted;
    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        /* Created FIRST so the operator is user 1 -- the id every merchant carries as `created_by`,
         * and therefore the root a demoted merchant collapses to. */
        $this->operator = User::forceCreate([
            'name' => 'Matrix Op', 'email' => 'mx-op@example.test', 'password' => bcrypt('x'),
            'type' => 'super admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->owner = User::forceCreate([
            'name' => 'Matrix Owner', 'email' => 'mx-owner@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Matrix Store', 'email' => 'mx-store@example.test', 'slug' => 'mx-store',
            'theme_id' => 'stylique', 'created_by' => $this->owner->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->owner->current_store = $this->store->id;
        $this->owner->save();

        $this->entitledStaff = $this->makeStaff('mx-staff-ok@example.test', $this->owner, $this->store);
        $this->plainStaff    = $this->makeStaff('mx-staff-no@example.test', $this->owner, $this->store);

        $this->otherMerchant = User::forceCreate([
            'name' => 'Matrix Other', 'email' => 'mx-other@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->otherStore = Store::create([
            'name' => 'Other Store', 'email' => 'mx-other-store@example.test',
            'slug' => 'mx-other-store', 'theme_id' => 'stylique',
            'created_by' => $this->otherMerchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->otherMerchant->current_store = $this->otherStore->id;
        $this->otherMerchant->save();

        /* Same tenant as nobody here, but HOLDS the global permission -- the cross-tenant probe. */
        $this->otherEntitled = $this->otherMerchant;

        /* A merchant who demoted their own `type` through the product: creatorId() drops to 1, and
         * user 1 is the OPERATOR, who is not a merchant tenant root. */
        $this->demoted = User::forceCreate([
            'name' => 'Matrix Demoted', 'email' => 'mx-demoted@example.test',
            'password' => bcrypt('x'), 'type' => 'staff', 'created_by' => 1,
            'email_verified_at' => now(),
        ]);
        $this->demoted->current_store = $this->store->id;
        $this->demoted->save();

        $this->operator->current_store = null;
        $this->operator->save();

        ThemeCustomize::create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::BEFORE,
        ]);

        foreach (['stylique', 'greentic', 'techzonix'] as $theme) {
            Addon::create(['theme_id' => $theme, 'status' => '1']);
        }

        /*
         * TWO IDENTITY PREMISES, PINNED RATHER THAN ASSUMED — raised by independent review.
         *
         * `test_a_demoted_merchant_cannot_write` only means "the root collapsed to the OPERATOR"
         * if the operator is user 1, and `test_the_operator_without_an_explicit_store_writes_nothing`
         * only discriminates the `getCurrentStore()` fallback if the store under test IS store 1.
         * Both hold today — no migration inserts a `users` or `stores` row and `TestCase` runs no
         * seeders — but they held by accident of ordering, and an added fixture upstream would have
         * turned two security assertions into assertions about nothing, silently.
         */
        $this->assertSame(1, (int) $this->operator->id,
            'PREMISE: the operator must be user 1, since a demoted merchant\'s creatorId() '
            . 'collapses to created_by = 1 and the test asserts that root is not a merchant.');
        $this->assertSame(1, (int) $this->store->id,
            'PREMISE: the store under test must be store 1, or the operator-fallback row proves '
            . 'nothing about getCurrentStore() returning the literal 1.');

        $this->letTheOperatorReachTheController();
    }

    private function makeStaff(string $email, User $employer, Store $store): User
    {
        $u = User::forceCreate([
            'name' => 'Staff', 'email' => $email, 'password' => bcrypt('x'),
            'type' => 'staff', 'created_by' => $employer->id, 'email_verified_at' => now(),
        ]);
        $u->current_store = $store->id;
        $u->save();

        return $u;
    }

    private function grant(User $user): User
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'Manage Themes'], ['display_name' => 'Manage Themes']
        );
        $role = Role::forceCreate([
            'name' => 'mx-role-' . $user->id, 'display_name' => 'MX', 'guard_name' => 'web',
            'created_by' => $user->creatorId(), 'store_id' => $user->current_store,
        ]);
        $role->givePermission($permission);
        $user->addRole($role);
        $user->flushCache();

        return $user->fresh();
    }

    private function letTheOperatorReachTheController(): void
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = collect(File::glob(database_path('migrations/*.php')))
            ->map(fn ($path) => File::name($path));

        foreach (Module::allModules() as $module) {
            foreach ([
                "packages/workdo/{$module->name}/src/Database/Migrations",
                "packages/workdo/{$module->name}/src/database/migrations",
            ] as $dir) {
                if (File::exists($dir)) {
                    $files = $files->merge(
                        collect(File::glob("{$dir}/*.php"))->map(fn ($path) => File::name($path))
                    );
                }
            }
        }

        $batch = 1 + (int) DB::table('migrations')->max('batch');
        foreach (array_diff($files->unique()->all(), $ran) as $name) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
        }
    }

    /** The row as the storefront would read it. `withoutGlobalScopes()` because the answer must be
     *  about the TABLE, not about whichever tenant happened to resolve. */
    private function liveValue(?Store $store = null): ?string
    {
        $store ??= $this->store;

        return ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->where('theme_id', 'stylique')
            ->where('name', 'footer_description')
            ->value('value');
    }

    private function attemptWrite(?User $user, string $value): void
    {
        $request = $user ? $this->actingAs($user) : $this;
        $request->post('/theme-customize/stylique/update', ['footer_description' => $value]);
    }

    /* ======================================================================= PERMITTED (3 rows) */

    #[Test]
    public function test_the_verified_tenant_owner_can_write(): void
    {
        $this->attemptWrite($this->owner, 'BY OWNER');

        $this->assertSame('BY OWNER', $this->liveValue(),
            'The store owner could not write their own live storefront. The boundary must not '
            . 'close the surface it is meant to narrow.');
    }

    #[Test]
    public function test_same_tenant_staff_holding_manage_themes_can_write(): void
    {
        $staff = $this->grant($this->entitledStaff);

        $this->assertTrue($staff->isAbleTo('Manage Themes'), 'PREMISE: the grant did not take.');

        $this->attemptWrite($staff, 'BY ENTITLED STAFF');

        $this->assertSame('BY ENTITLED STAFF', $this->liveValue(),
            'A same-tenant staff member holding `Manage Themes` was refused. Delegation within a '
            . 'tenant is explicitly permitted by the decision.');
    }

    #[Test]
    public function test_the_operator_naming_a_store_explicitly_can_write(): void
    {
        $this->operator->current_store = $this->store->id;
        $this->operator->save();

        $this->attemptWrite($this->operator->fresh(), 'BY OPERATOR');

        $this->assertSame('BY OPERATOR', $this->liveValue(),
            'The platform operator, naming a store explicitly, was refused. Operator-first is the '
            . 'established policy and support must retain access.');
    }

    /* ========================================================================= REFUSED (5 rows) */

    #[Test]
    public function test_zero_permission_staff_cannot_write(): void
    {
        $this->attemptWrite($this->plainStaff, 'BY PLAIN STAFF');

        $this->assertSame(self::BEFORE, $this->liveValue(),
            'A staff user with no role and no permission changed the live storefront. This is the '
            . 'inversion the owner directed be closed.');
    }

    /**
     * THE CONSTRAINT "a global permission alone must NEVER authorize another tenant", as a test.
     * This principal HOLDS `Manage Themes` and is refused because the store is not theirs.
     */
    #[Test]
    public function test_a_permission_holder_from_another_tenant_cannot_write(): void
    {
        $other = $this->grant($this->otherEntitled);

        $this->assertTrue($other->isAbleTo('Manage Themes'),
            'PREMISE: this probe is only meaningful if the foreign principal HOLDS the permission.');

        $this->attemptWrite($other, 'BY FOREIGN PERMISSION HOLDER');

        $this->assertSame(self::BEFORE, $this->liveValue(),
            'A `Manage Themes` holder from ANOTHER tenant wrote this store. The permission is '
            . 'granted on a shared global role, so it must never be reached before the tenant '
            . 'predicate.');

        /*
         * AND IT LANDED ON THEIR OWN STORE, WHICH IS CORRECT. The first version of this test
         * asserted the opposite and failed, because I had the route's shape wrong: the URL names
         * the THEME (`stylique`), never the store. The store comes from the gate, so a foreign
         * merchant posting here writes THEIR OWN store's stylique settings -- which they are
         * entitled to do. Asserting otherwise would have demanded a cross-tenant refusal from a
         * request that never crossed a tenant.
         *
         * The assertion that carries this test is the one above: THIS store did not move.
         */
        $this->assertSame('BY FOREIGN PERMISSION HOLDER', $this->liveValue($this->otherStore),
            'The foreign merchant\'s write did not land on their own store either, so the refusal '
            . 'above may be a total lockout rather than a tenant boundary.');
    }

    /**
     * THE CONSTRAINT "do not infer tenant ownership from type=admin alone". This principal is
     * type-`admin`, exactly like the owner, and owns a different store.
     */
    #[Test]
    public function test_another_tenants_merchant_cannot_write(): void
    {
        $this->assertSame('admin', $this->otherMerchant->type,
            'PREMISE: this probe only tests the type-inference constraint if the principal really '
            . 'is type-admin.');

        $this->attemptWrite($this->otherMerchant, 'BY OTHER MERCHANT');

        $this->assertSame(self::BEFORE, $this->liveValue(),
            'Another tenant\'s merchant wrote this store. Being type-`admin` is not ownership.');
    }

    #[Test]
    public function test_a_demoted_merchant_cannot_write(): void
    {
        $this->attemptWrite($this->demoted, 'BY DEMOTED');

        $this->assertSame(self::BEFORE, $this->liveValue(),
            'A user whose tenant root collapsed to 1 wrote this store. `tenantRootIsAMerchant()` '
            . 'must refuse a root that is the operator rather than a merchant.');
    }

    #[Test]
    public function test_the_operator_without_an_explicit_store_writes_nothing(): void
    {
        $this->assertNull($this->operator->current_store,
            'PREMISE: an empty current_store is the state that makes getCurrentStore() return 1.');

        $this->attemptWrite($this->operator, 'BY UNSCOPED OPERATOR');

        $this->assertSame(self::BEFORE, $this->liveValue(),
            'The operator, naming no store, wrote a merchant\'s storefront. Theme operations must '
            . 'never default to store 1.');
    }

    #[Test]
    public function test_an_unauthenticated_caller_writes_nothing(): void
    {
        $this->attemptWrite(null, 'BY ANONYMOUS');

        $this->assertSame(self::BEFORE, $this->liveValue());
    }

    /* ========================================== the constraint the HTTP matrix structurally cannot see */

    /**
     * THE GATE, CALLED DIRECTLY WITH A FOREIGN STORE ID — AND THIS TEST EXISTS BECAUSE THE TWELVE
     * HTTP TESTS AROUND IT COULD NOT CATCH THE DEFECT IT CATCHES.
     *
     * MEASURED. Hoisting the global permission above the tenant predicate --
     *
     *     if ($user->isAbleTo('Manage Themes')) { return true; }   // before resolveStore()
     *
     * -- which is precisely the cross-tenant hole the owner's decision names, left ALL TWELVE HTTP
     * tests in this file passing. Not because the boundary held, but because none of these routes
     * lets a caller NAME a store: the URL carries the theme, and the store always comes from the
     * caller's own `current_store`. A foreign permission holder posting to this store's theme
     * writes THEIR OWN store, so the victim's row never moves whichever way the gate is written.
     *
     * So the HTTP surface cannot reach the hole TODAY, and would the moment any route accepts a
     * store identifier -- an operator naming a target store, or a publish endpoint carrying one,
     * both of which M4 goes on to add. The constraint is a property of the GATE, and this is where
     * it has to be asserted.
     *
     * The shape generalises: an authorization rule tested only through the routes that currently
     * exist is tested against today's call sites, not against the rule.
     */
    #[Test]
    public function test_a_global_permission_never_authorizes_another_tenants_store(): void
    {
        $foreign = $this->grant($this->otherMerchant);

        $this->assertTrue($foreign->isAbleTo('Manage Themes'),
            'PREMISE: this probe is only meaningful if the foreign principal HOLDS the permission.');

        $gate = new \App\Support\Theme\ThemeEditorGate();

        $this->assertFalse(
            $gate->allowsManagement($this->store->id, $foreign),
            'A holder of the GLOBAL `Manage Themes` role was admitted to another tenant\'s store '
            . 'by naming its id directly. `Manage Themes` is granted on a shared global role, so '
            . 'it must never be reached before resolveStore() has applied the tenant predicate. '
            . 'No HTTP route exposes this today; the first one that accepts a store id would.'
        );

        /* The same principal, their own store: proves the refusal above is a tenant boundary and
         * not a lockout. */
        $this->assertTrue(
            (new \App\Support\Theme\ThemeEditorGate())
                ->allowsManagement($this->otherStore->id, $foreign),
            'The foreign principal was refused their OWN store too, so the assertion above may be '
            . 'proving nothing.'
        );
    }

    /**
     * The same question for the owner and for zero-permission staff, named directly, so the gate's
     * rule is pinned independently of which routes happen to exist.
     */
    #[Test]
    public function test_the_gate_rule_holds_when_a_store_is_named_directly(): void
    {
        $gate = fn () => new \App\Support\Theme\ThemeEditorGate();

        $this->assertTrue($gate()->allowsManagement($this->store->id, $this->owner),
            'The verified owner was refused their own store.');

        $this->assertFalse($gate()->allowsManagement($this->store->id, $this->plainStaff),
            'Zero-permission staff were admitted to a live write by naming the store id.');

        $this->assertFalse($gate()->allowsManagement($this->store->id, $this->demoted),
            'A demoted merchant, whose tenant root collapses to the operator, was admitted.');

        $this->assertFalse($gate()->allowsManagement($this->otherStore->id, $this->owner),
            'This store\'s owner was admitted to ANOTHER tenant\'s store.');
    }

    /* ================================================== the READ side, same boundary, same rows */

    /**
     * THE INVERSION IS GONE IN BOTH DIRECTIONS. Before M4-2 the owner of a store who did not hold
     * `Manage Themes` was refused the editor they own, while zero-permission staff could write it.
     * Asserted on CONTENT, because the admin layout renders chrome at 200 for an empty view.
     */
    #[Test]
    public function test_the_owner_can_open_the_editor_without_holding_the_permission(): void
    {
        $response = $this->actingAs($this->owner)->get('/theme-customize/stylique/page/footer');

        $this->assertFalse($this->owner->isAbleTo('Manage Themes'),
            'PREMISE: this asserts owner access WITHOUT the permission; a grant would hide the bug.');

        $response->assertOk();
        $this->assertStringContainsString(self::BEFORE, (string) $response->getContent(),
            'The store owner could not see their own stored settings. The GET side used to require '
            . '`Manage Themes` and refuse them.');
    }

    /**
     * ASSERTS THE REFUSAL, NOT MERELY THE ABSENCE OF THE VALUE — strengthened after independent
     * review. The first version checked only that the sentinel was not in the body, which a 302, a
     * 404, a 500 or an empty response all satisfy: the content-side form of "the row did not change
     * because the endpoint is broken for everyone".
     */
    #[Test]
    public function test_zero_permission_staff_cannot_open_the_editor(): void
    {
        $response = $this->actingAs($this->plainStaff)->get('/theme-customize/stylique/page/footer');

        $response->assertRedirect();

        $this->assertStringNotContainsString(self::BEFORE, (string) $response->getContent(),
            'Zero-permission staff were served the tenant\'s stored theme settings.');
    }

    /**
     * THE FOREIGN MERCHANT IS NOT LOCKED OUT, THEY ARE SCOPED — which is a different claim, and the
     * one worth asserting. They open their OWN editor successfully and see their OWN value; what
     * they must never see is this store's. A refusal assertion here would have been wrong: they are
     * entitled to the editor, just not to this tenant's data.
     */
    #[Test]
    public function test_another_tenants_merchant_sees_only_their_own_store(): void
    {
        $response = $this->actingAs($this->otherMerchant)
            ->get('/theme-customize/stylique/page/footer');

        $response->assertOk();

        $this->assertStringNotContainsString(self::BEFORE, (string) $response->getContent(),
            'Another tenant\'s merchant was served this store\'s stored settings.');
    }
}
