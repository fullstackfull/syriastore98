<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\User;
use App\Support\ModuleRouteManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER PLAN `PRD-002` (GAP-183) — THE DECLARED OWNERSHIP RULE ON THE ONE SURFACE THAT COMES
 * CLOSEST TO AN ATTRIBUTE TEMPLATE HAD NO BEHAVIOURAL TEST.
 * ==============================================================================================
 *
 * Plan text read: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:255`,
 * section 04-A — «خصائص وقوالب حسب نوع النشاط.» / *attributes and templates by business type*.
 *
 * WHAT THE ASSESSMENT FOUND, in one line each, because it is why this file exists and nothing more:
 * the ATTRIBUTE half of PRD-002 is present in three per-store mechanisms, and the closest thing to
 * a reusable TEMPLATE among them is `packages/workdo/AdditionalCustomFields` — a store-level set of
 * field definitions (`label`, `type`, `options`) applied to that store's products. The "by business
 * type" half has no representation anywhere, and the plan never names a business type, so that half
 * is an owner decision and nothing is built for it here.
 *
 * ----------------------------------------------------------------------------------------------
 * THE GAP THIS CLOSES IS A TEST GAP, NOT A DEFECT, AND SAYING SO IS THE POINT.
 *
 * `Workdo\AdditionalCustomFields\app\Http\Controllers\SettingController` resolves the row by bare id
 * in three handlers with **no permission check and no store predicate**:
 *
 *     edit($id)             AdditionalCustomFields::findOrFail($id)
 *     update(Request, $id)  AdditionalCustomFields::findOrFail($id)  then ->update([...])
 *     destroy($id)          AdditionalCustomFields::findOrFail($id)  then ->delete()
 *
 * and the model uses only `HasFactory` + `Cachable` — no `BelongsToStore` — and is absent from
 * `config/tenancy.php`'s `scoped_models`. That is the `BundleProduct` shape exactly.
 *
 * **It is already closed**, by three rules in `App\Support\ModuleRouteManifest::ADMINISTRATIVE`:
 *
 *     GET    additionalcustomfields/edit/{id}     => store-row:additional_custom_fields:id
 *     PUT    additionalcustomfields/update/{id}   => store-row:additional_custom_fields:id
 *     DELETE additionalcustomfields/destroy/{id}  => store-row:additional_custom_fields:id
 *
 * What was missing is evidence: `RouteCensusGateTest` asserts the KEYS still name real routes, and
 * nothing drove them. A declared rule with no behavioural test is the shape CLAUDE.md records as
 * the most expensive kind of green — and the specific risk here is trap 3: `PlanModuleCheck` refuses
 * an unentitled merchant before the rule is ever asked, so a careless test would measure the plan
 * gate and report the ownership rule as working. Every case below asserts the entitlement premise.
 *
 * This file therefore adds no guard. It makes an existing one falsifiable, on the surface a
 * master-plan requirement names.
 */
class AdditionalCustomFieldOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'AdditionalCustomFields',
            'name' => 'AdditionalCustomFields',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'additional-custom-fields',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['AdditionalCustomFields']);

        foreach (glob(base_path('packages/workdo/AdditionalCustomFields/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('additional_custom_fields'),
            'premise: the module migrations ran');
        $this->assertNotNull(\Illuminate\Support\Facades\Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/additionalcustomfields/destroy/1', 'DELETE')
        ), 'premise: the module routes are registered');
    }

    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id, 'module' => 'AdditionalCustomFields',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * TRAP 3, AND IT IS THE WHOLE RISK IN THIS FILE. `PlanModuleCheck` refuses an unentitled
         * non-operator before the manifest rule is asked, so without this premise every "the row
         * did not change" assertion below would be measuring the PLAN GATE and reporting the
         * ownership rule as working.
         */
        $this->assertContains('AdditionalCustomFields', ActivatedModule($owner->id),
            'the entitlement fixture did not take, so PlanModuleCheck refuses before the ownership '
            . 'rule is reached and every assertion below would hold either way');
    }

    private function field(int $storeId, string $label): int
    {
        return (int) DB::table('additional_custom_fields')->insertGetId([
            'store_id' => $storeId,
            'label' => $label,
            'type' => 'Text',
            'options' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stored(int $id): ?object
    {
        return DB::table('additional_custom_fields')->where('id', $id)->first();
    }

    /** @return array{attacker:User,victimField:int,ownField:int} */
    private function twoTenants(): array
    {
        /* Burn store id 1: `getCurrentStore()` answers the literal 1 for an operator with an empty
           `current_store`, so a store under test that IS store 1 collapses distinct numbers. */
        $decoy = $this->makeMerchant();
        $this->makeStore($decoy);

        [$victim, $victimStore] = $this->makeTenant();
        [$attacker, $attackerStore] = $this->makeTenant();

        $attacker = User::find($attacker->id);
        $this->entitle($attacker);

        $this->assertNotSame((int) $victimStore->id, (int) $attackerStore->id,
            'fixture: both tenants resolved to one store, so nothing cross-tenant is driven');

        return [
            'attacker' => $attacker,
            'victimField' => $this->field((int) $victimStore->id, 'Victim fabric'),
            'ownField' => $this->field((int) $attackerStore->id, 'My fabric'),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE PREMISES

    /** The model is not scoped, so the global scope cannot be what refuses. */
    public function test_the_model_is_not_scoped_so_the_scope_cannot_be_the_check(): void
    {
        $this->enableModule();

        $class = \Workdo\AdditionalCustomFields\app\Models\AdditionalCustomFields::class;

        $this->assertNotContains($class, config('tenancy.scoped_models'),
            'the model has been added to scoped_models; re-measure before relying on the manifest '
            . 'rule, because null_tenant => "open" still adds no predicate when no tenant resolves');
        $this->assertStringNotContainsString('store_id', $class::query()->toSql(),
            'the model now carries a store_id predicate, so the premise has changed');
    }

    /** The three rules really are declared, and they really are `store-row:`. */
    public function test_the_manifest_declares_the_three_rules(): void
    {
        foreach ([
            'GET additionalcustomfields/edit/{id}',
            'PUT additionalcustomfields/update/{id}',
            'DELETE additionalcustomfields/destroy/{id}',
        ] as $key) {
            $this->assertSame(
                'store-row:additional_custom_fields:id',
                ModuleRouteManifest::ADMINISTRATIVE[$key] ?? null,
                "the manifest no longer carries `{$key}` as a store-row rule, so the cases below "
                . 'measure something else'
            );
        }
    }

    /** And the vendored handlers really do take the row by bare id with no check of their own. */
    public function test_the_vendored_handlers_ask_nothing(): void
    {
        $source = $this->sourceWithoutComments(
            'packages/workdo/AdditionalCustomFields/src/app/Http/Controllers/SettingController.php'
        );

        $this->assertSame(3, substr_count($source, 'AdditionalCustomFieldsModel::findOrFail($id)'),
            'the number of bare-id row lookups in the vendored controller has changed from 3; '
            . 're-read it before trusting this file\'s case list');
        $this->assertStringNotContainsString('isAbleTo', $source,
            'the vendored controller now checks a permission. That is an improvement, but the '
            . 'manifest rule is ownership-only and this file\'s framing needs re-reading.');
    }

    // ══════════════════════════════════════════════════════════════════════ THE RULE REALLY BITES

    /** DELETE — another tenant's field definition survives. */
    public function test_another_tenants_custom_field_cannot_be_deleted(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();

        $response = $this->actingAs($f['attacker'])
            ->delete('/additionalcustomfields/destroy/' . $f['victimField']);

        $this->assertNotSame(404, $response->status(), 'the route does not exist, so the row '
            . 'assertion would be satisfied by a 404');
        $this->assertNotNull($this->stored($f['victimField']),
            'ANOTHER TENANT\'S CUSTOM FIELD DEFINITION WAS DELETED. The handler is '
            . '`findOrFail($id)->delete()` with no permission and no store predicate, and the '
            . 'model is in no scoped_models list — PRD-002 surface, GAP-183.');
    }

    /** PUT — and it cannot be relabelled or retyped. */
    public function test_another_tenants_custom_field_cannot_be_rewritten(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();
        $before = $this->stored($f['victimField']);

        $this->actingAs($f['attacker'])->put('/additionalcustomfields/update/' . $f['victimField'], [
            'name' => 'HIJACKED',
            'type' => 'select',
            'options' => ['one', 'two'],
        ]);

        $after = $this->stored($f['victimField']);
        $this->assertSame((string) $before->label, (string) $after->label,
            'another tenant\'s field definition was relabelled');
        $this->assertSame((string) $before->type, (string) $after->type,
            'another tenant\'s field definition was retyped');
    }

    /** GET …/edit — and its definition is not served, which is the read half. */
    public function test_another_tenants_custom_field_edit_form_is_not_served(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();

        $response = $this->actingAs($f['attacker'])
            ->get('/additionalcustomfields/edit/' . $f['victimField']);

        $this->assertNotSame(404, $response->status(), 'the edit route does not exist');
        $this->assertStringNotContainsString('Victim fabric', (string) $response->getContent(),
            'another tenant\'s field label reached the response body. `edit()` checks no '
            . 'permission and no owner — PRD-002 surface, GAP-183.');
    }

    // ══════════════════════════════════════════════════════════════════ THE CONTROLS: NO LOCKOUT

    /** The merchant's OWN field definition is still deletable — the lockout half. */
    public function test_a_merchant_can_still_delete_their_own_custom_field(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();

        $response = $this->actingAs($f['attacker'])
            ->delete('/additionalcustomfields/destroy/' . $f['ownField']);

        $this->assertNotSame(403, $response->status(),
            'the rule refused a merchant their OWN field definition — a lockout, not a tightening');
        $this->assertNull($this->stored($f['ownField']),
            'the merchant\'s own field definition was not deleted, so the rule is refusing '
            . 'everything rather than refusing a foreign row');
    }

    /** And still editable, in the same run, so "refuses everything" is distinguishable. */
    public function test_a_merchant_can_still_open_their_own_custom_field(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();

        $response = $this->actingAs($f['attacker'])
            ->get('/additionalcustomfields/edit/' . $f['ownField']);

        $this->assertNotSame(403, $response->status(),
            'the rule refused a merchant their own field definition\'s edit form');
    }
}
