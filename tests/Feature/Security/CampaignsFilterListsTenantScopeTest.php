<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-69 (b) — THE CAMPAIGNS SCREEN'S OWN STORE AND PRODUCT LISTS, WHICH THE LIST FIX DID NOT TOUCH.
 * =============================================================================================
 *
 * Independent review of the list fix pointed out that scoping the DataTable scopes the ROWS and
 * nothing else on the endpoint. Three surfaces in the same vendored controller build a list of
 * stores, products or users with the bare `created_by = Auth::user()->creatorId()` predicate and no
 * merchant-root test — the P1-SEC-66/67 shape:
 *
 *   index()        $catList / $productList   the filter bar's Store and Product dropdowns
 *   create()       $store                    the create form's Store dropdown
 *   productData()  $product                  `POST campaigns-product`, the products of a chosen store
 *
 * For a merchant owner and their staff `creatorId()` is the tenant root and the predicate is right.
 * For a principal ROOTED AT THE OPERATOR it is the OPERATOR's id, so each of the three answers with
 * the OPERATOR's stores and products. `Store` and `Product` add no global scope of their own
 * (CLAUDE.md §1), so that predicate is the whole control.
 *
 * AND ONE OF THE REVIEW'S FINDINGS IS REFUTED HERE, WHICH IS WHY THE FIRST TWO CASES EXIST. The
 * review reported `$userList = User::get()` in `index()` as "every platform user's name and id
 * served to any entitled merchant or staff member". The query is indeed unscoped, but
 * `index.blade.php:35` wraps that `<select>` in `@if (Auth::user()->type == 'super admin')`, so for
 * everybody else the list is fetched and never printed. Measured both ways below rather than
 * argued, and pinned: if that `@if` ever goes, the first case fails.
 */
final class CampaignsFilterListsTenantScopeTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    private const LIST = '/campaigns/campaigns';
    private const CREATE = '/campaigns/campaigns/create';
    private const PRODUCTS = '/campaigns/campaigns-product';

    private function enable(): void
    {
        $failed = $this->enableWorkdoModules(['Campaigns']);

        $this->assertNotEmpty(
            array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), 'Workdo\\Campaigns\\')),
            'PREMISE: Campaigns is not loaded, so this test would reach nothing. Failed migrations: ' . implode('; ', $failed)
        );
    }

    /** The plan gate reads the EMPLOYER's activations for any type that is not literally `admin`. */
    private function entitle(User $user): void
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'campaigns-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'modules' => 'Campaigns', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user->plan_id = $planId;
        $user->save();

        foreach (array_unique(array_filter([(int) $user->id, (int) $user->created_by])) as $id) {
            if (! DB::table('user_active_modules')->where('user_id', $id)->where('module', 'Campaigns')->exists()) {
                DB::table('user_active_modules')->insert([
                    'user_id' => $id, 'module' => 'Campaigns',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            app()->forgetInstance("lynomia.activated_modules.owner.{$id}");
        }
    }

    private function productFor(User $owner, $store, string $name): int
    {
        return (int) \App\Models\Product::forceCreate([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . $this->uniqueFixtureRef(),
            'price' => 10, 'sale_price' => 10, 'product_stock' => 1, 'track_stock' => 1, 'variant_product' => 0,
            'store_id' => $store->id, 'created_by' => $owner->id,
        ])->id;
    }

    /**
     * An operator who owns a store and a product, a merchant tenant, and the principal under test.
     *
     * @return array{0: User, 1: \App\Models\Store, 2: User, 3: \App\Models\Store}
     */
    private function platform(): array
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator, ['name' => 'OPERATOR PLATFORM STORE']);
        $this->productFor($operator, $operatorStore, 'OPERATOR SECRET PRODUCT');

        [$merchant, $store] = $this->makeTenant();

        return [$operator, $operatorStore, $merchant, $store];
    }

    /** A merchant who has changed their own `type` to a role name: `creatorId()` is now the operator's. */
    private function selfDemoted(User $merchant, User $operator): User
    {
        $merchant->type = 'manager';
        $merchant->save();

        $principal = $merchant->fresh();

        $this->grantPermission($principal, 'Manage Campaigns');
        $this->entitle($principal);

        $this->assertSame((int) $operator->id, (int) $principal->fresh()->creatorId(),
            "PREMISE: this principal's `creatorId()` is not the operator's id, so the case is an "
            . 'ordinary merchant under another name and measures nothing.');

        return User::find($principal->id);
    }

    private function shellFor(User $user, string $uri = self::LIST): string
    {
        $response = $this->actingAs($user)->get($uri);

        $this->assertSame(200, $response->getStatusCode(),
            'PREMISE: the screen did not render for this principal, so nothing below is measuring '
            . 'what it shows. Body: ' . substr(strip_tags((string) $response->getContent()), 0, 300));

        return (string) $response->getContent();
    }

    // ---------------------------------------------------------------------------------------
    //  The review's `$userList` finding, measured in both directions
    // ---------------------------------------------------------------------------------------

    public function test_the_filter_bar_does_not_print_a_user_list_for_a_merchant(): void
    {
        [$operator, , $merchant, $store] = $this->platform();

        [$rival] = $this->makeTenant();
        $rival->name = 'RIVAL MERCHANT NAME';
        $rival->save();

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $body = $this->shellFor($merchant->fresh());

        $this->assertStringNotContainsString('RIVAL MERCHANT NAME', $body,
            'The campaigns filter bar printed another tenant\'s user name. `index()` builds its '
            . '`$userList` from `User::get()` with no predicate, so only the view\'s '
            . '`@if (type == \'super admin\')` keeps it off a merchant\'s screen.');
        $this->assertStringNotContainsString('id="user"', $body,
            'The User filter select is rendered for a merchant, so the unscoped `$userList` is now '
            . 'on their screen — which is the review finding this case was written to pin.');
    }

    public function test_the_filter_bar_does_print_the_user_list_for_the_operator(): void
    {
        [$operator] = $this->platform();

        [$rival] = $this->makeTenant();
        $rival->name = 'RIVAL MERCHANT NAME';
        $rival->save();

        $body = $this->shellFor(User::find($operator->id));

        $this->assertStringContainsString('id="user"', $body,
            'POSITIVE CONTROL: the User filter is not rendered for the operator either, so the '
            . 'case above would pass however the list were built.');
        $this->assertStringContainsString('RIVAL MERCHANT NAME', $body,
            'POSITIVE CONTROL: the operator\'s own platform-wide user list is empty, so the case '
            . 'above proves nothing about scoping.');
    }

    // ---------------------------------------------------------------------------------------
    //  The store and product lists, which ARE printed for everybody
    // ---------------------------------------------------------------------------------------

    public function test_the_filter_bar_does_not_offer_the_operators_store_to_an_operator_rooted_principal(): void
    {
        [$operator, , $merchant] = $this->platform();

        $principal = $this->selfDemoted($merchant, $operator);

        $body = $this->shellFor($principal);

        $this->assertStringNotContainsString('OPERATOR PLATFORM STORE', $body,
            'The Store filter offered the PLATFORM OPERATOR\'s store to a principal whose tenant '
            . 'root is the operator. `index()` scopes it with `created_by = creatorId()`, which for '
            . 'such a principal is the operator\'s id.');
        $this->assertStringNotContainsString('OPERATOR SECRET PRODUCT', $body,
            'The Product filter offered the PLATFORM OPERATOR\'s product name to a principal whose '
            . 'tenant root is the operator.');
    }

    public function test_the_create_form_does_not_offer_the_operators_store_to_an_operator_rooted_principal(): void
    {
        [$operator, , $merchant] = $this->platform();

        $principal = $this->selfDemoted($merchant, $operator);

        $body = $this->shellFor($principal, self::CREATE);

        $this->assertStringNotContainsString('OPERATOR PLATFORM STORE', $body,
            'The create form offered the PLATFORM OPERATOR\'s store to a principal whose tenant '
            . 'root is the operator — and a campaign created from it is written with '
            . '`created_by = creatorId()`, i.e. into the operator\'s own bucket.');
    }

    public function test_the_product_endpoint_does_not_answer_with_the_operators_products(): void
    {
        [$operator, $operatorStore, $merchant] = $this->platform();

        $principal = $this->selfDemoted($merchant, $operator);

        $response = $this->actingAs($principal)->post(self::PRODUCTS, ['store_id' => $operatorStore->id]);

        $this->assertStringNotContainsString('OPERATOR SECRET PRODUCT', (string) $response->getContent(),
            'The product endpoint named the PLATFORM OPERATOR\'s product to a principal whose '
            . 'tenant root is the operator, for a store that is the operator\'s.');

        /*
         * AND THE MECHANISM IS A REFUSAL, NOT AN EMPTY ANSWER -- asserted, because the two are
         * indistinguishable from the body alone and only one of them survives a change to the
         * handler. This endpoint renders no view, so the composer that scopes the two screens
         * cannot reach it; the control is the route rule `tenant-store:store_id`. Before it, this
         * same request answered 200 with the operator's product name in the JSON.
         */
        $this->assertContains($response->getStatusCode(), [302, 403],
            'The product endpoint ADMITTED a principal whose tenant root is the operator, asking '
            . 'about a store that is not theirs. Whatever it answered with, the route rule is not '
            . 'refusing the request: ' . $response->getStatusCode());
    }

    // ---------------------------------------------------------------------------------------
    //  An ordinary merchant: unchanged, and the positive controls that prove it
    // ---------------------------------------------------------------------------------------

    public function test_a_merchant_still_sees_their_own_store_and_product_in_the_filter_bar(): void
    {
        [, , $merchant, $store] = $this->platform();

        $store->name = 'MY OWN STORE';
        $store->save();
        $this->productFor($merchant, $store, 'MY OWN PRODUCT');

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $body = $this->shellFor($merchant->fresh());

        $this->assertStringContainsString('MY OWN STORE', $body,
            'POSITIVE CONTROL: a merchant cannot see their own store in the Store filter, so a '
            . 'change that emptied every list would pass every refusal here.');
        $this->assertStringContainsString('MY OWN PRODUCT', $body,
            'POSITIVE CONTROL: a merchant cannot see their own product in the Product filter.');
    }

    public function test_a_merchant_still_sees_their_own_store_in_the_create_form(): void
    {
        [, , $merchant, $store] = $this->platform();

        $store->name = 'MY OWN STORE';
        $store->save();

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $this->assertStringContainsString('MY OWN STORE', $this->shellFor($merchant->fresh(), self::CREATE),
            'POSITIVE CONTROL: a merchant cannot see their own store in the create form.');
    }

    public function test_the_product_endpoint_still_answers_a_merchant_with_their_own_products(): void
    {
        [, , $merchant, $store] = $this->platform();

        $this->productFor($merchant, $store, 'MY OWN PRODUCT');

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $response = $this->actingAs($merchant->fresh())->post(self::PRODUCTS, ['store_id' => $store->id]);

        $this->assertSame(200, $response->getStatusCode(),
            'PREMISE: the product endpoint did not answer for a merchant. Body: '
            . substr(strip_tags((string) $response->getContent()), 0, 300));
        $this->assertStringContainsString('MY OWN PRODUCT', (string) $response->getContent(),
            'POSITIVE CONTROL: the product endpoint does not answer a merchant with their own '
            . 'products, so the refusal above proves nothing.');
    }

    // ---------------------------------------------------------------------------------------
    //  The composer's own four keys, because one of them cannot be seen through the screen
    // ---------------------------------------------------------------------------------------

    /**
     * `userList` IS NOT OBSERVABLE THROUGH THE ROUTE, so it is asserted here.
     *
     * `index.blade.php` prints that `<select>` only for a `super admin`, which means a change
     * widening the list back to the vendored `User::get()` would leave every HTTP case in this file
     * passing. The same is true in reverse: the two cases at the top of this file pin the TEMPLATE's
     * behaviour, not the list's. This drives the composer's own method, which is the code path the
     * view uses.
     */
    public function test_the_composer_gives_a_merchant_only_their_own_tenants_users(): void
    {
        [$operator, , $merchant, $store] = $this->platform();

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        [$rival] = $this->makeTenant();

        $lists = (new \App\View\Composers\CampaignsTenantFilterScope)->listsFor($merchant->fresh());

        $this->assertNotNull($lists, 'PREMISE: the composer left a merchant\'s lists untouched.');

        $ids = array_keys($lists['userList']->all());

        $this->assertContains((int) $merchant->id, $ids, 'A merchant is missing from their own tenant\'s user filter.');
        $this->assertContains((int) $staff->id, $ids, 'A merchant\'s own staff member is missing from their user filter.');
        $this->assertNotContains((int) $rival->id, $ids, 'Another tenant\'s owner is named in a merchant\'s user filter.');
        $this->assertNotContains((int) $operator->id, $ids, 'The platform operator is named in a merchant\'s user filter.');
    }

    /** The operator's lists are the module's own, so the composer must not replace them at all. */
    public function test_the_composer_leaves_the_operators_lists_alone(): void
    {
        [$operator] = $this->platform();

        $this->assertNull((new \App\View\Composers\CampaignsTenantFilterScope)->listsFor(User::find($operator->id)),
            'The composer replaced the OPERATOR\'s platform-wide lists. Campaigns is a paid '
            . 'placement the operator prices and approves, and the module gives them the unscoped '
            . 'lists deliberately; this fix narrows what a MERCHANT sees.');
    }

    /** No principal, and a principal with no merchant tenant root, both get nothing. */
    public function test_the_composer_fails_closed_with_no_tenant_root(): void
    {
        [$operator, , $merchant] = $this->platform();

        foreach ([null, $this->selfDemoted($merchant, $operator)] as $principal) {
            $lists = (new \App\View\Composers\CampaignsTenantFilterScope)->listsFor($principal);

            $label = $principal === null ? 'no principal' : 'a principal rooted at the operator';

            $this->assertNotNull($lists, "PREMISE: the composer left the lists untouched for {$label}.");

            // Each list keeps its vendored placeholder and carries nothing else.
            $this->assertSame([0 => 'Select store'], $lists['catList']->all(), "catList is not empty for {$label}.");
            $this->assertSame([0 => 'Select store'], $lists['store']->all(), "store is not empty for {$label}.");
            $this->assertSame([0 => 'Select product'], $lists['productList']->all(), "productList is not empty for {$label}.");
            $this->assertSame([0 => 'Select User'], $lists['userList']->all(), "userList is not empty for {$label}.");
        }
    }

    public function test_a_merchant_is_not_offered_another_merchants_store_or_product(): void
    {
        [, , $merchant, $store] = $this->platform();

        [$rival, $rivalStore] = $this->makeTenant();
        $rivalStore->name = 'RIVAL SECRET STORE';
        $rivalStore->save();
        $this->productFor($rival, $rivalStore, 'RIVAL SECRET PRODUCT');

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $body = $this->shellFor($merchant->fresh());

        $this->assertStringNotContainsString('RIVAL SECRET STORE', $body);
        $this->assertStringNotContainsString('RIVAL SECRET PRODUCT', $body);

        $response = $this->actingAs($merchant->fresh())->post(self::PRODUCTS, ['store_id' => $rivalStore->id]);

        $this->assertStringNotContainsString('RIVAL SECRET PRODUCT', (string) $response->getContent(),
            'The product endpoint named another merchant\'s product for that merchant\'s store id. '
            . 'The `store_id` is caller-supplied and carries no ownership check of its own.');

        /*
         * AND THE REQUEST IS REFUSED, NOT ANSWERED EMPTY -- WRITTEN BECAUSE A MUTATION SURVIVED.
         * `tenantOwnsStore()` returning `true` outright passed every other case in this file: for a
         * principal rooted at the operator the rule refuses one line earlier (a null tenant root),
         * and for an ordinary merchant the handler's own `created_by = creatorId()` predicate
         * filters the other tenant's products out anyway, so the body looks the same. The ONLY
         * observable difference is whether the endpoint admitted a request about a store that is
         * not the caller's -- which is the thing the rule is for, and the thing that still holds if
         * the handler's own predicate is ever loosened.
         */
        $this->assertContains($response->getStatusCode(), [302, 403],
            'The product endpoint ADMITTED a merchant asking about ANOTHER MERCHANT\'s store. It '
            . 'answered ' . $response->getStatusCode() . '. Today the handler\'s own predicate '
            . 'makes that answer empty; the route rule is what makes the question refusable.');
    }
}
