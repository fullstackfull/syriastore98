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
 * P1-SEC-90 — THE ROW DOORS OF THE VERIFIED P1-SEC-76 BATCH, EXECUTED RATHER THAN ARGUED.
 * =============================================================================================
 *
 * `RouteCensusGateTest` and `ModuleAdministrativeRouteGuardTest` are provider-driven over
 * `ModuleRouteManifest`, so the new keys are exercised by their anonymous-caller and
 * rule-shape cases the moment they exist — 833 tests became 1,034 across the two rounds of this
 * batch. (This sentence said "49 new keys" when the block held 52, and an independent review
 * caught it; round 2 then made it 57. A count written into a comment is a claim the file has to
 * keep in step BY HAND, so this one no longer names the key count at all.) That proves the rules are
 * WELL FORMED. It does not prove the doors were OPEN, and those are different claims.
 *
 * This file drives the four that matter most with a real cross-tenant principal:
 *
 *   - `DELETE donationhistory/{donationhistory}` — the worst of the batch. `destroy($id)` is
 *     `CustomerDonation::find($id)` then `->delete()`, with no permission check, no store
 *     predicate, and no SoftDeletes on `customer_donations`, so a donor's name, e-mail, address
 *     and amount are destroyed permanently by a caller from another tenant.
 *   - BOTH product-enquiry spellings, because the two live resources on ONE controller name their
 *     parameter DIFFERENTLY (`{product_enquiry}` against `{productenquiry}`), and a rule copied
 *     from one to the other reads a null id and refuses THE RIGHTFUL OWNER. The owner's own
 *     request is asserted to still succeed, which is the half a refusal-only test cannot see.
 *   - `GET preordersetting/get-product-variants/{id}`, where the parameter is a PRODUCT id despite
 *     the URI, on the one model CLAUDE.md records as scoped for nobody.
 *   - `DELETE join_us/{join_u}`, whose table carries neither `store_id` nor `created_by`, so the
 *     `operator` rule there is compelled by the schema rather than chosen.
 */
final class ModuleRowOwnershipBatchTwoTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @param list<string> $modules */
    private function enableModules(array $modules): void
    {
        foreach ($modules as $module) {
            AddOnManager::create([
                'module' => $module, 'name' => $module,
                'monthly_price' => 0, 'yearly_price' => 0,
                'is_enable' => 1, 'package_name' => strtolower($module),
            ]);
        }

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, $modules);

        foreach ($modules as $module) {
            foreach (glob(base_path('packages/workdo/' . $module . '/src/database/migrations/*.php')) ?: [] as $file) {
                /*
                 * A module's own migrations are NOT all independent of the base schema: LandingPage
                 * ships an unguarded `Schema::create('marketplace_page_settings')` for a table the
                 * app already has, so the loop aborts on it and leaves the rest unrun. Swallowing
                 * is safe here ONLY because every case below opens with a `Schema::hasTable()`
                 * premise assertion for the table it actually needs — that assertion, not this
                 * loop, is what stops a missing table passing a test for the wrong reason.
                 */
                try {
                    (require $file)->up();
                } catch (\Throwable) {
                    continue;
                }
            }
        }
    }

    /** @param list<string> $modules */
    private function entitle(User $owner, array $modules): void
    {
        foreach ($modules as $module) {
            DB::table('user_active_modules')->insert([
                'user_id' => $owner->id, 'module' => $module,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->app->forgetInstance('lynomia.activated_modules.owner.' . $owner->id);

        foreach ($modules as $module) {
            $this->assertContains($module, ActivatedModule($owner->id),
                'PREMISE: without the entitlement PlanModuleCheck refuses every request below, so '
                . 'a refusal would say nothing about ownership.');
        }
    }

    /**
     * @param list<string> $modules
     * @return array{0: User, 1: Store, 2: Store} a merchant on store A, and a foreign store B
     */
    private function arrange(array $modules): array
    {
        $this->enableModules($modules);

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $merchantA->current_store = $storeA->id;
        $merchantA->save();

        $merchantA = User::find($merchantA->id);
        $this->entitle($merchantA, $modules);

        return [User::find($merchantA->id), $storeA, $storeB];
    }

    private function rowExists(string $table, int $id): bool
    {
        return DB::table($table)->where('id', $id)->exists();
    }

    private function product(Store $store, string $name): int
    {
        // `products` has no `sku` and no `quantity`; `track_stock` and `created_by` have no default.
        return (int) DB::table('products')->insertGetId([
            'name' => $name, 'track_stock' => 0, 'price' => 10, 'product_stock' => 5,
            'variant_product' => 0, 'store_id' => $store->id, 'created_by' => (int) $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_merchant_cannot_delete_another_tenants_donation_receipt(): void
    {
        [$merchant, $storeA, $storeB] = $this->arrange(['Donation']);

        $this->assertTrue(Schema::hasTable('customer_donations'),
            'PREMISE: the module table is absent, so every assertion below would pass for the '
            . 'wrong reason.');

        $row = static fn (Store $s, string $donor): int => (int) DB::table('customer_donations')->insertGetId([
            'donation_id' => '1', 'customer_id' => '1', 'name' => $donor,
            'email' => strtolower($donor) . '@example.test', 'address' => '12 ' . $donor . ' Street',
            'amount' => '250', 'store_id' => $s->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $mine = $row($storeA, 'Mine');
        $theirs = $row($storeB, 'Theirs');

        $this->actingAs($merchant)->delete('/donationhistory/' . $theirs);

        $this->assertTrue($this->rowExists('customer_donations', $theirs),
            'Another tenant\'s donation receipt was DESTROYED by id — donor name, e-mail, address '
            . 'and amount — by `CustomerDonation::find($id)->delete()` with no permission check and '
            . 'no store predicate. `customer_donations` has no SoftDeletes, so it is not '
            . 'recoverable.');

        // POSITIVE CONTROL: the caller can still delete their OWN row, so the refusal above is a
        // tenant decision and not a rule that refuses everybody.
        $this->actingAs($merchant)->delete('/donationhistory/' . $mine);

        $this->assertFalse($this->rowExists('customer_donations', $mine),
            'The rule refuses the row\'s RIGHTFUL OWNER too, so the door is broken rather than '
            . 'guarded and the refusal above proves nothing.');
    }

    /**
     * BOTH SPELLINGS, AND THE OWNER'S OWN REQUEST. The twin resources name their parameter
     * differently, so one rule string cannot serve both; a copied one reads a null id, fails
     * closed for everybody, and looks exactly like a working control.
     */
    public function test_neither_product_enquiry_spelling_leaks_another_tenants_enquiry(): void
    {
        [$merchant, $storeA, $storeB] = $this->arrange(['ProductEnquiry']);

        $this->assertTrue(Schema::hasTable('product_enquiries'),
            'PREMISE: the module table is absent, so every assertion below would pass for the '
            . 'wrong reason.');

        $enquiry = fn (Store $s, string $who): int => (int) DB::table('product_enquiries')->insertGetId([
            'first_name' => $who, 'last_name' => 'Shopper',
            'email' => strtolower($who) . '@example.test', 'contact' => '100',
            'comment' => $who . ' asked about a product',
            'product_id' => $this->product($s, $who . ' Product'),
            'store_id' => $s->id, 'created_by' => (int) $s->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mine = $enquiry($storeA, 'Mine');
        $theirs = $enquiry($storeB, 'Theirs');

        foreach (['product-enquiry', 'productenquiry'] as $uri) {
            $foreign = $this->actingAs($merchant)->get('/' . $uri . '/' . $theirs . '/edit');

            $this->assertNotSame(200, $foreign->getStatusCode(),
                "GET /{$uri}/{id}/edit answered for another tenant's enquiry — the shopper's name, "
                . 'e-mail, contact number and question. `edit()` is `ProductEnquiry::find($id)` '
                . 'with no permission check and no store predicate.');

            $own = $this->actingAs($merchant)->get('/' . $uri . '/' . $mine . '/edit');

            $this->assertSame(200, $own->getStatusCode(),
                "GET /{$uri}/{id}/edit refuses the row's RIGHTFUL OWNER. That is what a rule naming "
                . 'the wrong parameter spelling does: the guard reads a null id and fails closed '
                . 'for everybody, while the manifest looks complete. The two resources on this one '
                . 'controller spell the parameter `product_enquiry` and `productenquiry`.');
        }
    }

    public function test_the_preorder_variant_reader_does_not_answer_for_another_tenants_product(): void
    {
        [$merchant, $storeA, $storeB] = $this->arrange(['PreOrder']);

        $mine = $this->product($storeA, 'Mine');
        $theirs = $this->product($storeB, 'Theirs');

        $foreign = $this->actingAs($merchant)->get('/preordersetting/get-product-variants/' . $theirs);

        $this->assertNotSame(200, $foreign->getStatusCode(),
            'The variant reader answered for another tenant\'s product. Its `{id}` is a PRODUCT id '
            . 'despite the URI, and `Product` is in no `scoped_models` list, so nothing else in '
            . 'the request would have stopped it.');

        $own = $this->actingAs($merchant)->get('/preordersetting/get-product-variants/' . $mine);

        $this->assertSame(200, $own->getStatusCode(),
            'The reader refuses the caller\'s OWN product, so the rule names the wrong table or '
            . 'the wrong parameter rather than guarding the door.');
    }

    /**
     * THE TWO SUBMENU READS WERE IN NO LEAD. Their write verbs were already classified in this
     * manifest; `create($id)` and `edit($id, $parentid)` were not, and both resolve
     * `SideMenuOption::find()` with no store predicate.
     */
    public function test_a_merchant_cannot_read_another_tenants_sidebar_menu_option(): void
    {
        [$merchant, $storeA, $storeB] = $this->arrange(['SidebarCustomization']);

        $this->assertTrue(Schema::hasTable('side_menus_option'),
            'PREMISE: the module table is absent, so every assertion below would pass for the '
            . 'wrong reason.');

        $menu = static fn (Store $s, string $name): int => (int) DB::table('side_menus_option')->insertGetId([
            'title' => $name, 'menu_type' => 'menu', 'type' => 'custom', 'name' => $name,
            'icon' => 'ti ti-' . strtolower($name), 'link_type' => 'external',
            'link' => 'https://' . strtolower($name) . '.example.test', 'position' => 0,
            'order' => 0, 'window_type' => '_self', 'is_enable' => 'on',
            'store_id' => $s->id, 'created_by' => (int) $s->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mine = $menu($storeA, 'MineMenu');
        $theirs = $menu($storeB, 'TheirsMenu');

        // `create($id)` prints the row's `name` into a hidden input.
        $foreignCreate = $this->actingAs($merchant)->get('/submenus/' . $theirs);

        $this->assertStringNotContainsString('TheirsMenu', (string) $foreignCreate->getContent(),
            'The submenu CREATE screen printed another tenant\'s menu option name. `create($id)` is '
            . '`SideMenuOption::find($id)` with no store predicate, and the view puts that `name` '
            . 'in a hidden `parent_menu` input.');

        // `edit($id, $parentid)` renders the row's link, icon and window type.
        $foreignEdit = $this->actingAs($merchant)->get('/submenus-edit/' . $theirs . '/' . $mine);

        $this->assertStringNotContainsString('theirsmenu.example.test', (string) $foreignEdit->getContent(),
            'The submenu EDIT screen rendered another tenant\'s menu option — its link, icon, '
            . 'menu type and window type. The rule must judge `{id}`, which is the row the view '
            . 'actually renders.');

        // POSITIVE CONTROL on both, so neither refusal is a rule that refuses everybody.
        $ownCreate = $this->actingAs($merchant)->get('/submenus/' . $mine);

        $this->assertSame(200, $ownCreate->getStatusCode(),
            'The submenu CREATE screen refuses the caller\'s OWN menu option.');

        $ownEdit = $this->actingAs($merchant)->get('/submenus-edit/' . $mine . '/' . $mine);

        $this->assertSame(200, $ownEdit->getStatusCode(),
            'The submenu EDIT screen refuses the caller\'s OWN menu option.');
    }

    /**
     * ALSO IN NO LEAD, and a WRITE. `storeNotificationLang()` creates or rewrites the translation
     * of the notification template named in the URL, with no check of any kind. The templates are
     * the platform's -- `index()` is `Notification::get()` with no predicate and `notifications`
     * has no `store_id` -- so a merchant editing one changes what every store's notifications say.
     */
    public function test_a_merchant_cannot_rewrite_a_platform_notification_template_translation(): void
    {
        [$merchant] = $this->arrange(['SMS']);

        $this->assertTrue(Schema::hasTable('notification_template_langs'),
            'PREMISE: the module table is absent, so the assertion below would pass for the wrong '
            . 'reason.');

        $this->actingAs($merchant)->post('/notification-template/1', [
            'lang' => 'en', 'module' => 'SMS', 'content' => 'Rewritten by another tenant',
            'variables' => '{}',
        ]);

        $this->assertFalse(
            DB::table('notification_template_langs')->where('parent_id', 1)->exists(),
            'A merchant wrote the translation of a PLATFORM notification template. '
            . '`storeNotificationLang()` takes `parent_id` straight from the URL and saves, with '
            . 'no permission check and no ownership check, and the table it writes has no '
            . '`store_id` of its own to scope by.');
    }

    /**
     * THE DOOR THE FIRST PASS MISSED, AND THE WORST OF THE SET.
     *
     * `Route::resource('join_us', ...)` registers `POST join_us` -> `JoinUsController::store()`,
     * which has NO authorization of any kind, inside a group whose only gate is
     * `['auth','web','xss','setlocate','verified']`. Registration is open in this product, so the
     * principal is "any verified account", not "a merchant".
     *
     * What it writes is not a tenant's row. `LandingPageSetting::updateOrCreate(['name' => $key])`
     * keys on NAME alone, and `landing_page_settings` is `id`, `name` (unique), `value`,
     * `timestamps` -- no `store_id`, no `created_by` -- so the three values are the PLATFORM'S
     * single copy, and they are printed on the public site through `{!! !!}` in three layouts
     * (landingpage, marketplace, custompage). The group's `xss` middleware does not make that
     * safe: its script strip is commented out and `htmlspecialchars_decode()` runs BEFORE its four
     * substrings are removed.
     *
     * The sibling `index()` on the same controller DOES check `type == 'super admin'`. Read gated,
     * write not -- the shape P1-SEC-85 recorded once already.
     */
    public function test_a_merchant_cannot_rewrite_the_platform_landing_page_text(): void
    {
        [$merchant] = $this->arrange(['LandingPage']);

        $this->assertTrue(Schema::hasTable('landing_page_settings'),
            'PREMISE: the module table is absent, so the assertions below would pass for the wrong '
            . 'reason.');

        $before = DB::table('landing_page_settings')
            ->whereIn('name', ['joinus_status', 'joinus_heading', 'joinus_description'])
            ->pluck('value', 'name')->all();

        $this->actingAs($merchant)->post('/join_us', [
            'joinus_status' => 'on',
            'joinus_heading' => 'DEFACED BY ANOTHER TENANT',
            'joinus_description' => '<script>stealTheSession()</script>',
        ]);

        $after = DB::table('landing_page_settings')
            ->whereIn('name', ['joinus_status', 'joinus_heading', 'joinus_description'])
            ->pluck('value', 'name')->all();

        $this->assertSame($before, $after,
            'A merchant rewrote the PLATFORM\'s public landing-page text. `store()` has no '
            . 'authorization check, the row is keyed on `name` alone with no tenant column, and '
            . 'the value is rendered through `{!! !!}` on three public layouts -- so this is '
            . 'defacement of the platform\'s own site and a stored-markup sink in one request.');

        $this->assertFalse(
            DB::table('landing_page_settings')->where('value', 'DEFACED BY ANOTHER TENANT')->exists(),
            'the heading was written under some other name'
        );

        /*
         * THE POSITIVE CONTROL: `operator` means the operator, so the operator must still be able
         * to write. Without this, a rule that silently takes the platform owner's own landing-page
         * editor away from them would look identical to a working control.
         *
         * AND A MUTATION THAT SURVIVES CORRECTLY, recorded because the reason is not obvious and
         * my first explanation of it was WRONG. Pointing this key at
         * `store-row:landing_page_settings:join_u` -- a table with no `store_id` and a parameter
         * this route does not have -- survives both halves of this case, and NOT because the test
         * is weak. `ModuleAdministrativeRouteGuard:117` is
         *
         *     if ($rule === 'auth' || $this->isOperator($user)) { return $next($request); }
         *
         * so the operator is admitted BEFORE any rule is evaluated. For a tenantless table that
         * makes `store-row:` and `operator` the same function: operator in, everyone else closed.
         * I had assumed `ownsStoreRow()` would refuse the operator too; it never reaches it.
         *
         * THEY ARE NOT SYNONYMS IN GENERAL, which is why the key says `operator`. If
         * `landing_page_settings` ever gained a `store_id`, `store-row:` would begin ADMITTING any
         * merchant whose store matched the row, while `operator` would go on refusing them. The
         * equivalence is a property of this table today, not of the vocabulary.
         */
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post('/join_us', [
            'joinus_status' => 'on',
            'joinus_heading' => 'Written by the platform owner',
            'joinus_description' => 'Join our community',
        ]);

        $this->assertSame(
            'Written by the platform owner',
            DB::table('landing_page_settings')->where('name', 'joinus_heading')->value('value'),
            'The OPERATOR can no longer edit the platform landing page either, so this rule is a '
            . 'denial of service dressed as a control -- the exact failure a merchant-only test '
            . 'cannot see.'
        );
    }

    /**
     * ROUND 2 -- THE RESOURCE'S OWN ROW VERBS, on the controller round 1 guarded ONE route of.
     *
     * `destroy($id)` checks `isAbleTo('Delete Pre Order Setting')` and then resolves
     * `PreOrderSetting::find($id)` with no store predicate, so the permission answers "may this
     * merchant delete pre-order settings" and nothing answers "whose". `update()` is worse still:
     * it assigns `store_id = getCurrentStore()` before saving, which does not merely write another
     * tenant's row but moves it into the caller's store.
     *
     * THE PERMISSION IS GRANTED HERE ON PURPOSE. Without it the handler refuses on the permission
     * and the case passes whatever the manifest says -- the commonest way an authorization test
     * passes for the wrong reason in this codebase.
     */
    public function test_a_merchant_cannot_delete_another_tenants_preorder_setting(): void
    {
        [$merchant, $storeA, $storeB] = $this->arrange(['PreOrder']);

        $this->assertTrue(Schema::hasTable('pre_order_settings'),
            'PREMISE: the module table is absent, so every assertion below would pass for the '
            . 'wrong reason.');

        $this->grant($merchant, ['Delete Pre Order Setting']);

        $setting = static fn (Store $s, int $qty): int => (int) DB::table('pre_order_settings')->insertGetId([
            'product_id' => '1', 'variant_id' => '0', 'available_date' => '2027-01-01',
            'qty' => $qty, 'status' => 1,
            'store_id' => $s->id, 'created_by' => (int) $s->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mine = $setting($storeA, 11);
        $theirs = $setting($storeB, 99);

        $this->actingAs($merchant)->delete('/preordersetting/' . $theirs);

        $this->assertTrue($this->rowExists('pre_order_settings', $theirs),
            'Another tenant\'s pre-order setting was deleted by id. The handler checks the '
            . 'permission and then resolves the row with no store predicate, so the permission '
            . 'decides WHETHER and nothing decides WHOSE.');

        // POSITIVE CONTROL: the rule must not be a denial of service for the row's owner.
        $this->actingAs($merchant)->delete('/preordersetting/' . $mine);

        $this->assertFalse($this->rowExists('pre_order_settings', $mine),
            'The rule refuses the row\'s RIGHTFUL OWNER, so the refusal above says nothing about '
            . 'ownership -- it is the fail-closed-for-everybody shape this file exists to catch.');
    }

    /** Grant real Laratrust permissions, because `isAbleTo()` refuses a user with no role. */
    private function grant(User $merchant, array $permissions): void
    {
        $role = \App\Models\Role::create([
            'name' => 'batch-two-' . $merchant->id, 'display_name' => 'Batch two',
            'created_by' => $merchant->id, 'store_id' => $merchant->current_store,
        ]);

        foreach ($permissions as $name) {
            $role->givePermission(\App\Models\Permission::firstOrCreate(
                ['name' => $name], ['display_name' => $name],
            ));
        }

        $merchant->addRole($role);
        $merchant->flushCache();

        foreach ($permissions as $name) {
            $this->assertTrue(User::find($merchant->id)->isAbleTo($name),
                "PREMISE: the principal does not hold '{$name}', so the handler refuses on the "
                . 'permission and the manifest rule is never reached.');
        }
    }

    /** `join_us` carries neither `store_id` nor `created_by`: these rows are the platform's. */
    public function test_a_merchant_cannot_delete_a_platform_join_us_row(): void
    {
        [$merchant] = $this->arrange(['LandingPage']);

        $this->assertTrue(Schema::hasTable('join_us'),
            'PREMISE: the module table is absent, so the assertion below would pass for the wrong '
            . 'reason.');

        $id = (int) DB::table('join_us')->insertGetId([
            'email' => 'signup@example.test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($merchant)->delete('/join_us/' . $id);

        $this->assertTrue($this->rowExists('join_us', $id),
            'A merchant deleted a platform-level newsletter signup. `destroy()` is '
            . '`JoinUs::find($id)->delete()` with no check of any kind, and `join_us` has no '
            . '`store_id` and no `created_by`, so no merchant has any claim to the row — the only '
            . 'correct audience is the operator.');
    }
}
