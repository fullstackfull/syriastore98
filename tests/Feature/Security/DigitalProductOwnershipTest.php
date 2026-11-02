<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-23 -- DigitalProduct IS AuctionProduct'S TWIN, AND ITS ROUTES WERE LEFT OUT OF THE GUARD THAT CLOSED P1-INV-17.
 * =========================================================================================================================
 *
 * Found by the read-only review of the P1-SEC-28 fix, which asked "which vendored deleters can still be reached by someone who should not
 * reach them?" and read packages/workdo/DigitalProduct. Reproduced here by EXECUTION against the unfixed tree (the first three tests failed).
 *
 *   packages/workdo/DigitalProduct/src/routes/web.php
 *     :20  Route::middleware(['web'])->group(... Route::delete('digital-products/{id}/delete', [DigitalProductController::class,'file_delete']))
 *            -- NO `auth`, NO PlanModuleCheck. file_delete($id) = DigitalProductImage::find($id); File::delete(base_path($row->image_path)); $row->delete().
 *            An ANONYMOUS request deletes any tenant's product image row AND the file it names, by guessing an integer.
 *     :17  Route::resource('digitalproduct', ...) in ['auth','web','PlanModuleCheck:DigitalProduct']
 *            edit/update/destroy all do `App\Models\Product::find($id)` -- the HOST catalogue model, which carries NO effective global scope
 *            (CLAUDE.md: Product is absent from scoped_models) -- so any merchant whose plan carries the module reads, rewrites and DELETES
 *            another tenant's product, its images and the files they name. update() and destroy() check the permission and never the owner.
 *
 * It is the same code as AuctionProductController (the module was cloned), and P1-INV-17's ModuleResourceOwnershipGuard already knows how to
 * refuse that shape. The fix is to give the guard these five method+URI entries, nothing in packages/workdo.
 *
 * ORACLE: the ROW AND THE FILE after the request, read raw (DB::table / file_exists) -- Product is Cachable. The status is asserted too where
 * only the guard can produce it (403).
 */
class DigitalProductOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            @unlink($path);
        }

        foreach (glob(base_path('uploads/p1inv23-*')) ?: [] as $dir) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'DigitalProduct',
            'name' => 'Digital Product',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'digital-product',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['DigitalProduct']);

        foreach (glob(base_path('packages/workdo/DigitalProduct/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('digital_product_images'), 'Premise: the module migrations ran.');
        $this->assertNotNull(\Illuminate\Support\Facades\Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/digital-products/1/delete', 'DELETE')
        ), 'Premise: the module routes are registered.');
    }

    /** `PlanModuleCheck` refuses a non-operator whose plan lacks the module; without this the cases below never reach a handler. */
    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert(['user_id' => $owner->id, 'module' => 'DigitalProduct', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertContains('DigitalProduct', ActivatedModule($owner->id), 'The entitlement fixture did not take.');
    }

    private function product(int $storeId, int $createdBy): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'Victim product ' . uniqid(), 'slug' => 'victim-' . uniqid(), 'store_id' => $storeId, 'track_stock' => 0,
            'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: int, 1: string} the image row id and the absolute path of the file it names */
    private function image(int $productId, int $storeId): array
    {
        $dir = base_path('uploads/p1inv23-' . uniqid());
        mkdir($dir, 0777, true);
        $absolute = $dir . '/victim.png';
        file_put_contents($absolute, 'VICTIM FILE');
        $this->scratch[] = $absolute;

        $id = (int) DB::table('digital_product_images')->insertGetId([
            'product_id' => $productId, 'image_path' => str_replace(base_path() . '/', '', $absolute), 'store_id' => $storeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, $absolute];
    }

    // =========================================================================================
    //  THE ANONYMOUS ROUTE
    // =========================================================================================

    public function test_an_anonymous_request_cannot_delete_a_product_image_or_its_file(): void
    {
        $this->enableModule();
        [$victim, $victimStore] = $this->makeTenant();
        [$imageId, $file] = $this->image($this->product($victimStore->id, $victim->id), $victimStore->id);

        $response = $this->delete('/digital-products/' . $imageId . '/delete');

        $this->assertNotNull(DB::table('digital_product_images')->where('id', $imageId)->first(), 'An ANONYMOUS request deleted a product image row.');
        $this->assertFileExists($file, 'An ANONYMOUS request deleted the file the row names.');
        $this->assertSame(403, $response->status(), 'Only the guard produces this 403: the route itself has no auth.');
    }

    public function test_another_tenants_merchant_cannot_delete_a_product_image_or_its_file(): void
    {
        $this->enableModule();
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->entitle($attacker);
        [$imageId, $file] = $this->image($this->product($victimStore->id, $victim->id), $victimStore->id);

        $response = $this->actingAs($attacker->fresh())->delete('/digital-products/' . $imageId . '/delete');

        $this->assertNotNull(DB::table('digital_product_images')->where('id', $imageId)->first(), "Another tenant's image row was deleted.");
        $this->assertFileExists($file, "Another tenant's file was deleted.");
        $this->assertSame(403, $response->status());
    }

    public function test_a_merchant_can_still_delete_their_own_product_image(): void
    {
        $this->enableModule();
        [$merchant, $store] = $this->makeTenant();
        $this->entitle($merchant);
        [$imageId, $file] = $this->image($this->product($store->id, $merchant->id), $store->id);

        $response = $this->actingAs($merchant->fresh())->delete('/digital-products/' . $imageId . '/delete');

        $this->assertSame(200, $response->status(), 'Premise for the refusals above: the route works for its owner.');
        $this->assertNull(DB::table('digital_product_images')->where('id', $imageId)->first());
        $this->assertFileDoesNotExist($file);
    }

    public function test_the_platform_operator_can_still_delete_a_product_image(): void
    {
        $this->enableModule();
        [$victim, $victimStore] = $this->makeTenant();
        [$imageId] = $this->image($this->product($victimStore->id, $victim->id), $victimStore->id);

        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->delete('/digital-products/' . $imageId . '/delete')->assertOk();
        $this->assertNull(DB::table('digital_product_images')->where('id', $imageId)->first());
    }

    // =========================================================================================
    //  THE RESOURCE ROUTES
    // =========================================================================================

    public function test_a_merchant_cannot_delete_another_tenants_product_through_the_digital_resource(): void
    {
        $this->enableModule();
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Digital Product');
        $this->entitle($attacker);
        $product = $this->product($victimStore->id, $victim->id);
        [$imageId, $file] = $this->image($product, $victimStore->id);

        $response = $this->actingAs($attacker->fresh())->delete('/digitalproduct/' . $product);

        $this->assertNotNull(DB::table('products')->where('id', $product)->first(), "Another tenant's product was DELETED.");
        $this->assertNotNull(DB::table('digital_product_images')->where('id', $imageId)->first(), "Another tenant's product image row was deleted.");
        $this->assertFileExists($file, "Another tenant's file was deleted.");
        $this->assertSame(403, $response->status());
    }

    public function test_a_merchant_cannot_edit_another_tenants_product_through_the_digital_resource(): void
    {
        $this->enableModule();
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Digital Product');
        $this->entitle($attacker);
        $product = $this->product($victimStore->id, $victim->id);
        $before = (array) DB::table('products')->where('id', $product)->first();

        $response = $this->actingAs($attacker->fresh())->put('/digitalproduct/' . $product, [
            'name' => 'HIJACKED-BY-DIGITAL', 'category_id' => 1, 'status' => 1, 'variant_product' => 0,
        ]);

        // The status is the control: the handler's own NULL writes are refused by SQLite and its catch turns any failure into a 200,
        // so the row assertions alone cannot detect the missing guard (see ModuleResourceOwnershipTest for the same measurement).
        $this->assertSame(403, $response->status(), 'The guard did not refuse a cross-tenant product update.');
        $this->assertSame($before['name'], DB::table('products')->where('id', $product)->value('name'));
        $this->assertSame((int) $before['store_id'], (int) DB::table('products')->where('id', $product)->value('store_id'));
    }

    /** PATCH is a separate method+URI key from PUT in the guard's TARGETS (keyed on method, not uri), so it needs its own case. */
    public function test_a_merchant_cannot_patch_another_tenants_product_through_the_digital_resource(): void
    {
        $this->enableModule();
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Digital Product');
        $this->entitle($attacker);
        $product = $this->product($victimStore->id, $victim->id);

        $response = $this->actingAs($attacker->fresh())->patch('/digitalproduct/' . $product, [
            'name' => 'HIJACKED-BY-PATCH', 'category_id' => 1, 'status' => 1, 'variant_product' => 0,
        ]);

        $this->assertSame(403, $response->status(), 'The guard did not refuse a cross-tenant PATCH.');
        $this->assertNotSame('HIJACKED-BY-PATCH', DB::table('products')->where('id', $product)->value('name'));
    }

    /**
     * The permission limb is existence-conditional (a name with no `permissions` row cannot be required without locking everyone out), so
     * it only bites when the row exists -- created here, granted to nobody: the production state once the module's seeder has run.
     * The caller's OWN rows, so ownership cannot be what refuses.
     */
    public function test_without_the_edit_permission_a_merchant_cannot_delete_their_own_product_image(): void
    {
        $this->enableModule();
        \App\Models\Permission::firstOrCreate(['name' => 'Edit Digital Product'], ['display_name' => 'Edit Digital Product']);
        [$merchant, $store] = $this->makeTenant();
        $this->entitle($merchant);
        [$imageId, $file] = $this->image($this->product($store->id, $merchant->id), $store->id);

        $response = $this->actingAs($merchant->fresh())->delete('/digital-products/' . $imageId . '/delete');

        $this->assertSame(403, $response->status());
        $this->assertNotNull(DB::table('digital_product_images')->where('id', $imageId)->first());
        $this->assertFileExists($file);
    }

    public function test_without_the_delete_permission_a_merchant_is_refused_by_the_guard_on_their_own_product(): void
    {
        $this->enableModule();
        \App\Models\Permission::firstOrCreate(['name' => 'Delete Digital Product'], ['display_name' => 'Delete Digital Product']);
        [$merchant, $store] = $this->makeTenant();
        $this->entitle($merchant);
        $product = $this->product($store->id, $merchant->id);

        $response = $this->actingAs($merchant->fresh())->delete('/digitalproduct/' . $product);

        // 403 is the guard's (the handler's own permission check answers with a redirect), so this isolates the guard's permission entry
        $this->assertSame(403, $response->status());
        $this->assertNotNull(DB::table('products')->where('id', $product)->first());
    }

    public function test_a_merchant_cannot_read_another_tenants_product_through_the_digital_edit_form(): void
    {
        $this->enableModule();
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Digital Product');
        $this->entitle($attacker);
        $product = $this->product($victimStore->id, $victim->id);

        $response = $this->actingAs($attacker->fresh())->get('/digitalproduct/' . $product . '/edit');

        $this->assertSame(403, $response->status(), 'Another tenant\'s product was rendered into the edit form for a caller who does not own it.');
    }

    /**
     * CHANGED BY F207 / OWNER DECISION D-29.2, and the change is a correction to this case rather
     * than an accommodation of the guard.
     *
     * This case used to create a product with NO `product_type` and assert the merchant could
     * delete it through `/digitalproduct/{id}`. That journey is exactly what F207 closes, and
     * `product_type` is what decides it — read off the controllers rather than assumed:
     *
     *   `ProductController:506`   ordinary products are `Product::where('product_type', null)`
     *   `DigitalProductController:35`  its own list is `where('product_type', 'digital')`
     *   `AuctionProductController:47`  likewise `'auction'`, and :375/:623 SET it on write
     *
     * So **a null `product_type` means an ORDINARY product**, and the old fixture was an ordinary
     * product being deleted by a holder of `Delete Digital Product`. The digital DELETE door
     * resolves the HOST `products` table with no type predicate of its own, so that permission
     * reached the whole catalogue: a staff member trusted with digital goods could delete every
     * physical product in the store. The guard now requires the row to really be digital.
     *
     * Hence two cases where there was one — the legitimate journey with the type the module itself
     * writes, and the ordinary product that must now be refused.
     */
    public function test_a_merchant_can_still_delete_their_own_digital_product(): void
    {
        $this->enableModule();
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Delete Digital Product');
        $this->entitle($merchant);
        $product = $this->product($store->id, $merchant->id);

        // What DigitalProductController's own list requires, and what its create writes.
        DB::table('products')->where('id', $product)->update(['product_type' => 'digital']);
        (new \App\Models\Product())->flushCache();

        $this->actingAs($merchant->fresh())->delete('/digitalproduct/' . $product);

        $this->assertNull(
            DB::table('products')->where('id', $product)->first(),
            'Premise: the owner can still delete their own DIGITAL product. If this fails the F207 '
            . 'confinement has gone from narrowing a privilege to removing a function.'
        );
    }

    public function test_an_ordinary_product_cannot_be_deleted_through_the_digital_resource(): void
    {
        $this->enableModule();
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Delete Digital Product');
        $this->entitle($merchant);

        // No `product_type`, which is what `ProductController:506` means by an ordinary product.
        $product = $this->product($store->id, $merchant->id);

        $response = $this->actingAs($merchant->fresh())->delete('/digitalproduct/' . $product);

        $this->assertNotNull(
            DB::table('products')->where('id', $product)->first(),
            'F207 / D-29.2: a holder of `Delete Digital Product` must not be able to delete an '
            . 'ORDINARY product. The module\'s DELETE door resolves the host `products` table with '
            . 'no type predicate of its own, so without the guard this permission reaches the '
            . 'whole catalogue — every physical product in the store.'
        );

        $this->assertSame(403, $response->status(), 'and it is refused rather than silently ignored');
    }

    public function test_an_auction_product_cannot_be_deleted_through_the_digital_resource(): void
    {
        // The cross-module half of the same confinement: the two doors must not be interchangeable.
        $this->enableModule();
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Delete Digital Product');
        $this->entitle($merchant);
        $product = $this->product($store->id, $merchant->id);

        DB::table('products')->where('id', $product)->update(['product_type' => 'auction']);
        (new \App\Models\Product())->flushCache();

        $response = $this->actingAs($merchant->fresh())->delete('/digitalproduct/' . $product);

        $this->assertNotNull(
            DB::table('products')->where('id', $product)->first(),
            'a digital permission must not delete an auction product either'
        );

        $this->assertSame(403, $response->status());
    }
}
