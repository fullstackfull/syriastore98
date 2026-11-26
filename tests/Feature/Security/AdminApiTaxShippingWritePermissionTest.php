<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-142 / P4-F075 — THE SIX TAX AND SHIPPING WRITERS IN THE ADMIN API HAD NO PERMISSION CHECK.
 * =================================================================================================
 *
 * `AddShipping` (`:2207`), `UpdateShipping` (`:2238`), `DeleteShipping` (`:2276`), `AddTax`
 * (`:2307`), `UpdateTax` (`:2336`) and `DeleteTax` (`:2380`). Each resolves TENANCY —
 * `resolveOwnedStore()` plus a `store_id` predicate on the per-row lookup — and then asks nothing
 * about who inside the store may act, so any authenticated member of the tenant holding no tax or
 * shipping permission could create, rename or delete the store's tax and shipping classes.
 *
 * -------------------------------------------------------------------------------------------------
 * WHERE THE MONEY ACTUALLY IS, MEASURED RATHER THAN ASSUMED — AND THE BRIEF'S FRAMING CORRECTED.
 *
 * These six were handed to me as "price-affecting", and the two tables they write are not: `taxes`
 * holds `name` and `store_id` ONLY (`2023_12_22_060417_create_taxes_table.php`) and `shippings`
 * holds `name`, `description`, `slug` and `store_id` (`2023_12_19_054936`). Neither carries a rate,
 * an amount or any other money column. Saying otherwise would have let this suite claim a money
 * assertion it could not make.
 *
 * **THE PRICE IS ONE TABLE AWAY, THROUGH A CASCADE, AND THAT IS WORSE.**
 * `tax_methods.tax_id` is `->references('id')->on('taxes')->onDelete('cascade')`
 * (`2023_12_25_040519_create_tax_methods_table.php:27`), and `tax_methods.tax_rate` is the
 * percentage a basket is actually taxed at. So `DeleteTax` does not merely remove a label: it
 * destroys every rate row hanging off that class, BELOW the application — no model event, no log
 * line, nothing to roll back. That is the same cascade shape already recorded in this codebase for
 * `categories` → `products` (P1-INV-12), and it is what
 * `test_a_refused_tax_deletion_destroys_no_tax_rate` asserts. `test_premise_the_tax_rate_cascade_is
 * _real_on_this_engine` proves the cascade fires here first, so that assertion cannot pass against
 * a cascade that never runs.
 *
 * `shippings` has no cascade at all — grep over `database/migrations` finds no foreign key
 * referencing it — so the shipping half is asserted as what it is: a commercial SETTING a
 * merchant's staff could rewrite or delete with nothing granted, pinned by row reads rather than by
 * a money claim.
 *
 * -------------------------------------------------------------------------------------------------
 * THE NAMES ARE EACH WEB TWIN'S OWN, PER HANDLER.
 *
 *   `Shipping` is the shipping CLASS model, and its web twin is `ShippingController` — `store()`
 *   gates on `Create Shipping Class` (`:229`), `update()` on `Edit Shipping Class`
 *   (`self::PERMISSION_EDIT`, `:287`) and `destroy()` on `Delete Shipping Class` (`:332`).
 *
 *   `Tax` is the tax CLASS model, and its web twin is `TaxController` — `store()` gates on
 *   `Create Tax` (`:50`), `update()` on `Edit Tax` (`:172`) and `destroy()` on `Delete Tax`
 *   (`:218`).
 *
 * All six, plus `Manage Tax` and `Manage Shipping Class`, are seeded by `Utility.php` in the
 * merchant block. No name is invented here.
 *
 * `Manage Tax` / `Manage Shipping Class` ARE ALSO ACCEPTED, for the reason the coupon row records
 * in full: those are the names the web SCREENS gate on (`TaxController::index` `:21`,
 * `ShippingController::index` `:186`), the Flutter app has no separate read screen for either, and
 * a merchant who ticked one box for a staff member can run that screen's writes in the app today.
 * Refusing them would be a NEW refusal of a live capability, which this row is not authorised to
 * take. Pinned by `test_staff_holding_the_screens_manage_name_still_write`, so narrowing it later
 * is a visible change to a named test.
 *
 * -------------------------------------------------------------------------------------------------
 * THE OPERATOR IS ADMITTED, AND THE WEB REFUSES THEM ON ALL SIX — RECORDED, NOT RESOLVED.
 *
 * `ShippingController::shippingWriteRefusal()` refuses the platform operator BY NAME, consistent with
 * the coupon precedent (F157, owner 2026-10-28). **And `TaxController` refuses them too** — :50,
 * :172 and :218 are bare `isAbleTo('Create|Edit|Delete Tax')` with no operator limb anywhere in that
 * file, and `isAbleTo()` has no super-admin bypass. Corrected by the independent review, which also
 * drew the distinction that matters: shipping's refusal is a DECISION somebody took, tax's is a
 * BY-PRODUCT of that shape. So the divergence covers six web handlers, not three. The API keeps admitting them, for the same
 * reason the coupon row gives: they can write through these routes today, and withdrawing a live
 * capability is a product decision that must not ride on a permission fix. Pinned by
 * `test_the_platform_operator_still_writes_PENDING_AN_OWNER_DECISION`.
 *
 * THE REFUSAL IS AN EXPLICIT 403: every other refusal in these six is `$this->error()`, which is
 * HTTP **200** with `{status: 0}`. 401 is never used — the shipped Flutter app force-clears its
 * session on one.
 */
class AdminApiTaxShippingWritePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A rate a basket would actually be taxed at, distinctive enough not to collide. */
    private const TAX_RATE = '13.75';

    private const ORIGINAL_TAX_NAME = 'GAP142-ORIGINAL-TAX-CLASS';

    private const ORIGINAL_SHIPPING_NAME = 'GAP142-ORIGINAL-SHIPPING-CLASS';

    private const ATTACKER_NAME = 'RENAMED-BY-UNPERMITTED-STAFF';

    /** @return array{0:User,1:Store,2:int,3:int,4:int} merchant, store, taxId, taxMethodId, shippingId */
    private function tenantWithTaxAndShipping(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $taxId = (int) DB::table('taxes')->insertGetId([
            'name' => self::ORIGINAL_TAX_NAME,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /* THE RATE ROW. This is the money that `DeleteTax` destroys through the cascade, and the
           only reason the tax half of this suite can make a money assertion at all. */
        $taxMethodId = (int) DB::table('tax_methods')->insertGetId([
            'name' => 'GAP142-RATE',
            'tax_rate' => self::TAX_RATE,
            'tax_id' => $taxId,
            'priority' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => self::ORIGINAL_SHIPPING_NAME,
            'description' => 'fixture description',
            'slug' => $store->slug,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [User::find($merchant->id), $store, $taxId, $taxMethodId, $shippingId];
    }

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    private function as(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        );
    }

    // ───────────────────────────────────────────────────────────────────── the six calls

    private function addTax(User $u, Store $s, string $name)
    {
        return $this->as($u)->postJson('/api/admin/addtax', ['store_id' => $s->id, 'name' => $name]);
    }

    private function updateTax(User $u, Store $s, int $taxId)
    {
        return $this->as($u)->postJson('/api/admin/updatetax', [
            'store_id' => $s->id, 'tax_id' => $taxId, 'name' => self::ATTACKER_NAME,
        ]);
    }

    private function deleteTax(User $u, Store $s, int $taxId)
    {
        return $this->as($u)->deleteJson('/api/admin/deletetax', ['store_id' => $s->id, 'tax_id' => $taxId]);
    }

    private function addShipping(User $u, Store $s, string $name)
    {
        return $this->as($u)->postJson('/api/admin/addshipping', [
            'store_id' => $s->id, 'name' => $name, 'description' => 'added by ' . $u->id,
        ]);
    }

    private function updateShipping(User $u, Store $s, int $shippingId)
    {
        return $this->as($u)->postJson('/api/admin/updateshipping', [
            'store_id' => $s->id, 'shipping_id' => $shippingId, 'name' => self::ATTACKER_NAME,
        ]);
    }

    private function deleteShipping(User $u, Store $s, int $shippingId)
    {
        return $this->as($u)->deleteJson('/api/admin/deleteshipping', [
            'store_id' => $s->id, 'shipping_id' => $shippingId,
        ]);
    }

    // ───────────────────────────────────────────────────────────── reads, through the table

    /**
     * `Tax` and `Shipping` are both `Cachable` and neither is in `config/tenancy.php`'s
     * `scoped_models`, so a plain `Tax::find()` would serve a pre-write row from the array cache
     * for the rest of the test. The query builder is the only read that is a statement about the
     * table.
     */
    private function taxName(int $taxId): ?string
    {
        $row = DB::table('taxes')->where('id', $taxId)->first();

        return $row === null ? null : (string) $row->name;
    }

    private function shippingName(int $shippingId): ?string
    {
        $row = DB::table('shippings')->where('id', $shippingId)->first();

        return $row === null ? null : (string) $row->name;
    }

    /**
     * The live tax RATE, as a number.
     *
     * NUMERIC AND NOT A STRING, measured: SQLite's affinity hands `13.75` back differently from
     * MySQL's column type, and the coupon sibling of this suite failed its tenancy case against a
     * working control for exactly that reason on its first run.
     */
    private function rate(int $taxMethodId): ?float
    {
        $row = DB::table('tax_methods')->where('id', $taxMethodId)->first();

        return $row === null ? null : (float) $row->tax_rate;
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE PREMISE

    /**
     * THE CASCADE IS REAL ON THIS ENGINE, so "the rate survived a refusal" is not a statement about
     * a cascade that never fires.
     *
     * Asserted by driving a LEGITIMATE deletion as the store's owner and watching the rate row go.
     * Without this the money assertion below would pass against a `DeleteTax` that leaves
     * `tax_methods` alone — and on SQLite foreign keys are only enforced when the pragma is on, so
     * this is engine-specific and must be measured rather than read off the migration.
     */
    public function test_premise_the_tax_rate_cascade_is_real_on_this_engine(): void
    {
        foreach (['taxes', 'tax_methods', 'shippings'] as $table) {
            $this->assertTrue(Schema::hasTable($table),
                $table . ' is absent from the test schema, so every assertion about it is vacuous');
        }

        [$merchant, $store, $taxId, $taxMethodId] = $this->tenantWithTaxAndShipping();

        $this->assertEqualsWithDelta((float) self::TAX_RATE, $this->rate($taxMethodId), 0.001,
            'fixture: the rate row was not written, so nothing below is about money');

        $this->assertSame(200, $this->deleteTax($merchant, $store, $taxId)->status(),
            'the owner could not delete their own tax class, so this premise proves nothing');

        $this->assertNull($this->taxName($taxId), 'the tax class itself was not deleted');
        $this->assertNull($this->rate($taxMethodId),
            'DELETING A TAX CLASS LEFT ITS RATE ROW BEHIND on this engine, so '
            . 'test_a_refused_tax_deletion_destroys_no_tax_rate cannot tell a protected rate from '
            . 'a cascade that never fires. tax_methods.tax_id is declared ON DELETE CASCADE '
            . '(2023_12_25_040519:27); if SQLite is not enforcing it here, that assertion must be '
            . 'moved to tests/Support/Security/bin/ against a real MySQL instead of left vacuous.');
    }

    // ════════════════════════════════════════════════════════════════════════════════ THE DEFECT

    /** THE MONEY CASE: a refused tax deletion must not have destroyed the rate through the cascade. */
    public function test_a_refused_tax_deletion_destroys_no_tax_rate(): void
    {
        [$merchant, $store, $taxId, $taxMethodId] = $this->tenantWithTaxAndShipping();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->deleteTax($staff, $store, $taxId);

        $this->assertSame(403, $response->status(),
            'a staff member holding no tax permission deleted a tax class -- and with it, through '
            . 'tax_methods.tax_id ON DELETE CASCADE, every rate hanging off it. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertNotNull($this->taxName($taxId), 'the refused call still deleted the tax class');
        $this->assertEqualsWithDelta((float) self::TAX_RATE, $this->rate($taxMethodId), 0.001,
            'THE REFUSED CALL STILL DESTROYED THE TAX RATE. The cascade runs below the '
            . 'application: no model event, no log line, nothing to roll back.');
    }

    /*
     * ONE CASE PER HANDLER, AND NOT ONE CASE PER RESOURCE. The first draft of this suite folded the
     * three shipping writes into a single case and the two non-delete tax writes into another, and
     * the mutation sweep showed why that is not good enough: removing `AddShipping`'s gate,
     * `UpdateShipping`'s gate and `DeleteShipping`'s gate each killed the SAME test name, so a
     * failure told the reader "shipping is ungated" without saying which door. Every mutation was
     * caught, but the diagnosis was lost. Split, each gate is named by the case that dies.
     */
    public function test_a_staff_member_without_a_tax_permission_cannot_rename_a_tax_class(): void
    {
        [$merchant, $store, $taxId] = $this->tenantWithTaxAndShipping();
        $staff = $this->staffIn($merchant, $store);

        $this->assertSame(403, $this->updateTax($staff, $store, $taxId)->status(),
            'UpdateTax: a staff member holding no tax permission renamed a tax class');
        $this->assertSame(self::ORIGINAL_TAX_NAME, $this->taxName($taxId),
            'the refused call still wrote taxes.name');
    }

    public function test_a_staff_member_without_a_tax_permission_cannot_mint_a_tax_class(): void
    {
        [$merchant, $store] = $this->tenantWithTaxAndShipping();
        $staff = $this->staffIn($merchant, $store);

        $this->assertSame(403, $this->addTax($staff, $store, 'MINTED-TAX')->status(),
            'AddTax: a staff member holding no tax permission minted a tax class');
        $this->assertSame(0, DB::table('taxes')->where('name', 'MINTED-TAX')->count(),
            'the refused call still inserted the tax class');
    }

    public function test_a_staff_member_without_a_shipping_permission_cannot_rename_a_shipping_class(): void
    {
        [$merchant, $store, , , $shippingId] = $this->tenantWithTaxAndShipping();
        $staff = $this->staffIn($merchant, $store);

        $this->assertSame(403, $this->updateShipping($staff, $store, $shippingId)->status(),
            'UpdateShipping: a staff member holding no shipping permission renamed a shipping class');
        $this->assertSame(self::ORIGINAL_SHIPPING_NAME, $this->shippingName($shippingId),
            'the refused call still wrote shippings.name');
    }

    public function test_a_staff_member_without_a_shipping_permission_cannot_delete_a_shipping_class(): void
    {
        [$merchant, $store, , , $shippingId] = $this->tenantWithTaxAndShipping();
        $staff = $this->staffIn($merchant, $store);

        $this->assertSame(403, $this->deleteShipping($staff, $store, $shippingId)->status(),
            'DeleteShipping: a staff member holding no shipping permission deleted a shipping class');
        $this->assertNotNull($this->shippingName($shippingId),
            'the refused call still deleted the shipping class');
    }

    public function test_a_staff_member_without_a_shipping_permission_cannot_mint_a_shipping_class(): void
    {
        [$merchant, $store] = $this->tenantWithTaxAndShipping();
        $staff = $this->staffIn($merchant, $store);

        $this->assertSame(403, $this->addShipping($staff, $store, 'MINTED-SHIPPING')->status(),
            'AddShipping: a staff member holding no shipping permission minted a shipping class');
        $this->assertSame(0, DB::table('shippings')->where('name', 'MINTED-SHIPPING')->count(),
            'the refused call still inserted the shipping class');
    }

    /** 403, NEVER 401, on all six. */
    public function test_none_of_the_six_refusals_is_a_401(): void
    {
        [$merchant, $store, $taxId, , $shippingId] = $this->tenantWithTaxAndShipping();
        $staff = $this->staffIn($merchant, $store);

        foreach ([
            'addtax' => $this->addTax($staff, $store, 'NOT-A-401-TAX'),
            'updatetax' => $this->updateTax($staff, $store, $taxId),
            'deletetax' => $this->deleteTax($staff, $store, $taxId),
            'addshipping' => $this->addShipping($staff, $store, 'NOT-A-401-SHIPPING'),
            'updateshipping' => $this->updateShipping($staff, $store, $shippingId),
            'deleteshipping' => $this->deleteShipping($staff, $store, $shippingId),
        ] as $route => $response) {
            $this->assertNotSame(401, $response->status(),
                $route . ': a 401 logs the merchant out of the whole app');
            $this->assertSame(403, $response->status(),
                $route . ': the refusal is not an explicit 403. ApiResponser::error() answers HTTP '
                . '200 with {status: 0}, which no client distinguishes from success');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE CONTROLS

    /** The store's own owner still writes all six, holding no tax or shipping permission row. */
    public function test_the_store_owner_still_writes_all_six(): void
    {
        [$merchant, $store, $taxId, , $shippingId] = $this->tenantWithTaxAndShipping();

        $this->assertSame(200, $this->addTax($merchant, $store, 'OWNER-TAX')->status(), 'addtax refused the owner');
        $this->assertSame(200, $this->updateTax($merchant, $store, $taxId)->status(), 'updatetax refused the owner');
        $this->assertSame(200, $this->addShipping($merchant, $store, 'OWNER-SHIPPING')->status(), 'addshipping refused the owner');
        $this->assertSame(200, $this->updateShipping($merchant, $store, $shippingId)->status(), 'updateshipping refused the owner');
        $this->assertSame(200, $this->deleteTax($merchant, $store, $taxId)->status(), 'deletetax refused the owner');
        $this->assertSame(200, $this->deleteShipping($merchant, $store, $shippingId)->status(), 'deleteshipping refused the owner');

        /* AND THE WRITES HAPPENED, so a gate that admitted the owner and then did nothing cannot
           pass as a control. */
        $this->assertSame(1, DB::table('taxes')->where('name', 'OWNER-TAX')->count());
        $this->assertSame(1, DB::table('shippings')->where('name', 'OWNER-SHIPPING')->count());
        $this->assertNull($this->taxName($taxId));
        $this->assertNull($this->shippingName($shippingId));
    }

    /** Staff holding each web twin's own name still write the matching route. */
    public function test_staff_holding_the_web_twins_name_still_write(): void
    {
        [$merchant, $store, $taxId, , $shippingId] = $this->tenantWithTaxAndShipping();

        $this->assertSame(200, $this->addTax($this->staffIn($merchant, $store, 'Create Tax'), $store, 'CREATE-TAX-STAFF')->status(),
            '`Create Tax` is what TaxController::store() names, and it was refused on addtax');

        $this->assertSame(200, $this->updateTax($this->staffIn($merchant, $store, 'Edit Tax'), $store, $taxId)->status(),
            '`Edit Tax` is what TaxController::update() names, and it was refused on updatetax');
        $this->assertSame(self::ATTACKER_NAME, $this->taxName($taxId),
            'the permitted editor got a 200 but the name did not change, so the gate is '
            . 'short-circuiting the handler rather than admitting it');

        $this->assertSame(200, $this->addShipping($this->staffIn($merchant, $store, 'Create Shipping Class'), $store, 'CREATE-SHIP-STAFF')->status(),
            '`Create Shipping Class` is what ShippingController::store() names, and it was refused on addshipping');

        $this->assertSame(200, $this->updateShipping($this->staffIn($merchant, $store, 'Edit Shipping Class'), $store, $shippingId)->status(),
            '`Edit Shipping Class` is what ShippingController::update() names, and it was refused on updateshipping');
        $this->assertSame(self::ATTACKER_NAME, $this->shippingName($shippingId));

        $this->assertSame(200, $this->deleteTax($this->staffIn($merchant, $store, 'Delete Tax'), $store, $taxId)->status(),
            '`Delete Tax` is what TaxController::destroy() names, and it was refused on deletetax');
        $this->assertSame(200, $this->deleteShipping($this->staffIn($merchant, $store, 'Delete Shipping Class'), $store, $shippingId)->status(),
            '`Delete Shipping Class` is what ShippingController::destroy() names, and it was refused on deleteshipping');
    }

    /**
     * And the names the web SCREENS use — see the class docblock on why they are admitted to the
     * writes and what changes if the owner decides otherwise.
     */
    public function test_staff_holding_the_screens_manage_name_still_write(): void
    {
        [$merchant, $store, $taxId, , $shippingId] = $this->tenantWithTaxAndShipping();

        /*
         * EVERY 200 IS PAIRED WITH THE ROW IT CLAIMS TO HAVE WRITTEN. The independent review asked
         * for this and it is right: these six handlers refuse everything except the gate through
         * `$this->error()`, which is HTTP **200** with `{status: 0}`. A status-only assertion
         * therefore cannot tell "admitted" from "refused", and this case -- which pins the
         * deliberately-wider `Manage X` grant, one of the two contested decisions in this slice --
         * would still have passed if somebody narrowed the gate AND converted its refusal to
         * `error()`. The row is the only witness that the handler ran.
         */
        $taxStaff = $this->staffIn($merchant, $store, 'Manage Tax');

        $this->assertSame(200, $this->addTax($taxStaff, $store, 'MANAGE-TAX-STAFF')->status());
        $this->assertNotNull(DB::table('taxes')->where('name', 'MANAGE-TAX-STAFF')->first(),
            'the holder of `Manage Tax` got a 200 and no tax class was minted, so the gate is '
            . 'short-circuiting the handler rather than admitting it');

        $this->assertSame(200, $this->updateTax($taxStaff, $store, $taxId)->status());
        $this->assertSame(self::ATTACKER_NAME, $this->taxName($taxId),
            'the holder of `Manage Tax` got a 200 and the name did not change');

        $this->assertSame(200, $this->deleteTax($taxStaff, $store, $taxId)->status());
        $this->assertNull($this->taxName($taxId),
            'the holder of `Manage Tax` got a 200 and the tax class survived');

        $shipStaff = $this->staffIn($merchant, $store, 'Manage Shipping Class');

        $this->assertSame(200, $this->addShipping($shipStaff, $store, 'MANAGE-SHIP-STAFF')->status());
        $this->assertNotNull(DB::table('shippings')->where('name', 'MANAGE-SHIP-STAFF')->first(),
            'the holder of `Manage Shipping Class` got a 200 and no shipping class was minted');

        $this->assertSame(200, $this->updateShipping($shipStaff, $store, $shippingId)->status());
        $this->assertSame(self::ATTACKER_NAME, $this->shippingName($shippingId),
            'the holder of `Manage Shipping Class` got a 200 and the name did not change');

        $this->assertSame(200, $this->deleteShipping($shipStaff, $store, $shippingId)->status());
        $this->assertNull($this->shippingName($shippingId),
            'the holder of `Manage Shipping Class` got a 200 and the shipping class survived');
    }

    /**
     * The platform operator still writes. RECORDED DIVERGENCE: `ShippingController` refuses them
     * every shipping-class write by name (consistent with coupon F157, owner 2026-10-28).
     */
    public function test_the_platform_operator_still_writes_PENDING_AN_OWNER_DECISION(): void
    {
        [, $store, $taxId, , $shippingId] = $this->tenantWithTaxAndShipping();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertSame(200, $this->updateTax($operator, $store, $taxId)->status(),
            'the platform operator was refused on updatetax. `isAbleTo()` has no super-admin '
            . 'bypass, which is why the gate goes through MerchantPermissions.');
        $this->assertSame(200, $this->updateShipping($operator, $store, $shippingId)->status(),
            'the platform operator was refused on updateshipping. If that refusal is INTENDED it is '
            . 'the owner\'s F157/F263 decision reaching the API, which is a product change and not '
            . 'a side effect of closing a permission hole.');
    }

    // ══════════════════════════════════════════════════════════════════════ TENANCY IS UNCHANGED

    /**
     * A member of another tenant holding `Edit Tax` and `Delete Tax` in their OWN store is still
     * refused, by `resolveOwnedStore()` and long before any permission question.
     *
     * ASSERTED ON `status` IN THE ENVELOPE. These handlers refuse a foreign store with
     * `$this->error(['message' => 'Store not found!'])`, which is HTTP **200** with `{status: 0}` —
     * so an `assertNotSame(200, ...)` here would fail against a working control.
     */
    public function test_another_tenants_member_is_still_refused_and_destroys_no_rate(): void
    {
        [, $store, $taxId, $taxMethodId, $shippingId] = $this->tenantWithTaxAndShipping();

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);
        $outsiderMerchant->current_store = $outsiderStore->id;
        $outsiderMerchant->save();
        $outsider = $this->staffIn(User::find($outsiderMerchant->id), $outsiderStore, 'Delete Tax');
        $this->grantPermission($outsider, 'Delete Shipping Class');
        $outsider = User::find($outsider->id);

        $taxDeletion = $this->deleteTax($outsider, $store, $taxId);
        $this->assertSame(0, $taxDeletion->json('status'),
            'a member of another tenant was answered with a success envelope for this store\'s tax '
            . 'class while holding `Delete Tax` in their OWN store');
        $this->assertNotNull($this->taxName($taxId), 'the cross-tenant call deleted another tenant\'s tax class');
        $this->assertEqualsWithDelta((float) self::TAX_RATE, $this->rate($taxMethodId), 0.001,
            'the cross-tenant call destroyed another tenant\'s tax RATE through the cascade');

        $shippingDeletion = $this->deleteShipping($outsider, $store, $shippingId);
        $this->assertSame(0, $shippingDeletion->json('status'));
        $this->assertNotNull($this->shippingName($shippingId),
            'the cross-tenant call deleted another tenant\'s shipping class');
    }
}
