<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-69 (d) — THE CAMPAIGNS LIST RENDERED MERCHANT-WRITTEN NAMES AS RAW HTML.
 * =============================================================================================
 *
 * `Workdo\Campaigns\DataTables\AllCampaignsData::dataTable()` (vendored, not edited) ends with
 *
 *     ->rawColumns(['action', 'status', 'user', 'store', 'product'])
 *
 * while the three cells above it are
 *
 *     ->editColumn('user',    fn ($c) => ucfirst($c->users->name))
 *     ->editColumn('store',   fn ($c) => ucfirst($c->stores->name))
 *     ->editColumn('product', fn ($c) => ucfirst($c->products->name))
 *
 * -- a user's name, a store's name and a PRODUCT's name, all written by a merchant, all declared
 * raw and none escaped. This is the shape P1-SEC-75 fixed next door for the three Helpdesk lists
 * and P1-SEC-79 fixed for support-ticket text.
 *
 * WHOSE BROWSER IT REACHES IS WHAT MAKES IT WORTH FIXING. The list is scoped to the caller's own
 * tenant for every principal BUT the platform operator, whose cross-tenant view is the module's
 * own design (it prices and approves these placements). So a merchant can write a product name
 * that executes in the OPERATOR's session -- the one session that can reach every tenant -- and
 * the operator has no way to see it coming: approving a placement is exactly the action that puts
 * that name on their screen.
 *
 * AND THE SAME CELLS ARE A 500 FOR THE WHOLE LIST. `->products->name` dereferences a `hasOne` with
 * no null guard, and `campaigns.product` is a comma-separated LIST of ids (`viewCampaigns()`
 * explodes it), so a campaign naming more than one product -- or one whose product, store or user
 * row is gone -- makes `products` null and the entire draw answers 500. One such row and nobody
 * can open the screen, the operator included. Both halves are fixed in the same override.
 */
final class CampaignsListCellEscapingTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    private const URI = '/campaigns/campaigns';

    /** The payload: a name that executes if it is not escaped, and is harmless text if it is. */
    private const HOSTILE = '<img src=x onerror=alert(1)>';

    private function enable(): void
    {
        $failed = $this->enableWorkdoModules(['Campaigns']);

        $this->assertNotEmpty(
            array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), 'Workdo\\Campaigns\\')),
            'PREMISE: Campaigns is not loaded, so this test would reach nothing. Failed migrations: ' . implode('; ', $failed)
        );
    }

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
            'name' => $name, 'slug' => 'p-' . $this->uniqueFixtureRef(),
            'price' => 10, 'sale_price' => 10, 'product_stock' => 1, 'track_stock' => 1, 'variant_product' => 0,
            'store_id' => $store->id, 'created_by' => $owner->id,
        ])->id;
    }

    private function campaign(User $owner, $store, $product, string $name = 'Placement'): int
    {
        return (int) DB::table('campaigns')->insertGetId([
            'name' => $name, 'user' => $owner->id, 'store' => $store->id, 'product' => (string) $product,
            'total_days' => 7, 'start_date' => '2026-01-01', 'end_date' => '2099-01-01',
            'payment_method' => 'bank_transfer', 'order_id' => 'C-' . $this->uniqueFixtureRef(),
            'status' => 1, 'approval' => 1, 'created_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The raw body of a draw, which is what the browser parses. */
    private function drawFor(User $user): string
    {
        $response = $this->actingAs($user)->get(self::URI . '?draw=1&start=0&length=100', [
            'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
        ]);

        $this->assertSame(200, $response->getStatusCode(),
            'PREMISE: the list did not answer, so nothing below is measuring what it renders. Body: '
            . substr(strip_tags((string) $response->getContent()), 0, 300));

        return (string) $response->getContent();
    }

    /**
     * THE CROSS-TENANT CASE, AND THE REASON THIS MATTERS: the operator's list is every tenant's by
     * design, so a merchant's product name is rendered in the operator's session.
     */
    public function test_a_merchants_hostile_product_name_does_not_reach_the_operator_as_markup(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $product = $this->productFor($merchant, $store, self::HOSTILE);
        $this->campaign($merchant, $store, $product);

        $body = $this->drawFor(User::find($operator->id));

        $this->assertStringNotContainsString('onerror=alert(1)>', $body,
            'A merchant-written PRODUCT NAME reached the platform operator\'s campaigns list as '
            . 'executable markup. The vendored list declares that cell raw and never escapes it, '
            . 'and the operator\'s list is every tenant\'s by design.');

        $this->assertStringContainsString('onerror=alert(1)&gt;', $body,
            'POSITIVE CONTROL: the escaped form of the name is not in the payload either, so the '
            . 'refusal above may only mean the row was not served at all.');
    }

    public function test_a_hostile_store_name_is_escaped(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $store->name = self::HOSTILE;
        $store->save();

        $product = $this->productFor($merchant, $store, 'Ordinary product');
        $this->campaign($merchant, $store->fresh(), $product);

        $body = $this->drawFor(User::find($operator->id));

        $this->assertStringNotContainsString('onerror=alert(1)>', $body, 'A store name reached the list as markup.');
        $this->assertStringContainsString('onerror=alert(1)&gt;', $body, 'POSITIVE CONTROL: the escaped store name is absent too.');
    }

    public function test_a_hostile_user_name_is_escaped(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $merchant->name = self::HOSTILE;
        $merchant->save();

        $product = $this->productFor($merchant, $store, 'Ordinary product');
        $this->campaign($merchant->fresh(), $store, $product);

        $body = $this->drawFor(User::find($operator->id));

        $this->assertStringNotContainsString('onerror=alert(1)>', $body, 'A user name reached the list as markup.');
        $this->assertStringContainsString('onerror=alert(1)&gt;', $body, 'POSITIVE CONTROL: the escaped user name is absent too.');
    }

    /** A MERCHANT'S OWN LIST TOO, so the fix is not only about the operator's screen. */
    public function test_a_hostile_name_is_escaped_on_the_merchants_own_list(): void
    {
        $this->enable();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $product = $this->productFor($merchant, $store, self::HOSTILE);
        $this->campaign($merchant, $store, $product);

        $body = $this->drawFor($merchant->fresh());

        $this->assertStringNotContainsString('onerror=alert(1)>', $body, 'The name reached a merchant\'s own list as markup.');
        $this->assertStringContainsString('onerror=alert(1)&gt;', $body, 'POSITIVE CONTROL: the escaped name is absent too.');
    }

    // ---------------------------------------------------------------------------------------
    //  THE SAME CELLS AS A 500 FOR THE WHOLE LIST
    // ---------------------------------------------------------------------------------------

    /** `campaigns.product` is a LIST of ids, which the `hasOne` cannot resolve. */
    public function test_a_campaign_naming_several_products_does_not_break_the_list(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $first = $this->productFor($merchant, $store, 'First product');
        $second = $this->productFor($merchant, $store, 'Second product');

        $this->campaign($merchant, $store, $first . ',' . $second, 'MULTI PRODUCT PLACEMENT');

        $body = $this->drawFor(User::find($operator->id));

        $this->assertStringContainsString('MULTI PRODUCT PLACEMENT', $body,
            'A campaign naming more than one product is not served at all. `campaigns.product` '
            . 'holds a comma-separated list -- `viewCampaigns()` explodes it -- and the list cell '
            . 'resolves it through a `hasOne`, so the relation is null and the whole draw used to '
            . 'answer 500.');
    }

    /** And a related row that is simply gone. */
    public function test_a_campaign_whose_product_row_is_gone_does_not_break_the_list(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $product = $this->productFor($merchant, $store, 'Vanishing product');

        $this->campaign($merchant, $store, $product, 'ORPHANED PLACEMENT');

        DB::table('products')->where('id', $product)->delete();
        (new \App\Models\Product)->flushCache();

        $body = $this->drawFor(User::find($operator->id));

        $this->assertStringContainsString('ORPHANED PLACEMENT', $body,
            'One campaign whose product row is gone made the whole list unreadable -- for every '
            . 'principal, the operator included.');
    }

    /** A STATUS THE MODULE'S OWN MAP DOES NOT HOLD, which was an undefined key and so a 500. */
    public function test_a_campaign_with_an_unknown_status_does_not_break_the_list(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $product = $this->productFor($merchant, $store, 'Ordinary product');

        $id = $this->campaign($merchant, $store, $product, 'ODD STATUS PLACEMENT');
        DB::table('campaigns')->where('id', $id)->update(['status' => 9]);

        $body = $this->drawFor(User::find($operator->id));

        $this->assertStringContainsString('ODD STATUS PLACEMENT', $body,
            'A campaign whose `status` is outside the module\'s own four-entry map made the whole '
            . 'list unreadable: `Campaigns::$statuses[$status]` was an undefined key, which '
            . 'Laravel\'s handler promotes to a 500.');
    }

    /** THE STATUS BADGE IS MARKUP THE MODULE MEANS, so escaping the data must not escape that. */
    public function test_the_status_badge_is_still_rendered_as_markup(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $product = $this->productFor($merchant, $store, 'Ordinary product');
        $this->campaign($merchant, $store, $product);

        $body = $this->drawFor(User::find($operator->id));

        /*
         * THE NEGATIVE IS THE LOAD-BEARING HALF, and the first version of this case did not have
         * it. Dropping `status` from `rawColumns` makes yajra escape the module's own badge markup
         * -- and the escaped form still CONTAINS the string `btn-badge`, inside
         * `&lt;span class=&quot;...btn-badge...`, so a contains-assertion alone passed the mutation.
         */
        $this->assertStringContainsString('btn-badge', $body,
            'The status badge markup is missing entirely.');
        $this->assertStringNotContainsString('&lt;span class=&quot;btn', $body,
            'The status badge is no longer rendered as HTML -- it arrives escaped, so escaping the '
            . 'DATA has escaped the module\'s own markup with it.');
        $this->assertStringContainsString('Active', $body, 'The status label is missing from the badge.');
    }
}
