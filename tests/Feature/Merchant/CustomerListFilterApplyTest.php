<?php

namespace Tests\Feature\Merchant;

use App\DataTables\CustomerDataTable;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-R-02 — the customer list's Apply button did nothing at all.
 * =================================================================================================
 *
 * A POST-CLOSURE PHASE 02 DEFECT, repaired under the owner's decision of 2026-11-03.
 *
 * WHAT A MERCHANT SAW, MEASURED IN A BROWSER BEFORE ANY CODE WAS CHANGED (the probe is
 * docs/development/phase-2.5/tools/probe-customer-apply.cjs):
 *
 *     /customer        #applyfilter present, #apply-button absent -> 0 XHR, nothing happens
 *     /customer-grid   #apply-button present                      -> 1 XHR, nothing happens
 *
 * TWO INDEPENDENT FAULTS, and the first one hid the second:
 *   1. customer/index.blade.php:64 gives the button `id="applyfilter"`; the script at :199 binds
 *      `$('#apply-button')`. The ids never matched, so the click had no handler.
 *   2. That handler would not have worked either. It writes its response into
 *      `$('#service-filter-data')`, and NO such element exists in either customer view — the only
 *      one in the codebase is a <tbody> inside the vendored RewardClubPoint module
 *      (packages/workdo/RewardClubPoint/src/resources/views/admin/clubPointTransections.blade.php:34),
 *      which is where the block was copied from. jQuery's .html() on an empty set is a silent
 *      no-op, so even a correctly-bound click changed nothing.
 *
 * WHY THE REPAIR NEEDS NO SERVER CHANGE AT ALL — the finding that made this a small fix.
 * `CustomerDataTable::query()` ALREADY implements this filter, server-side, for all five fields,
 * reading `field_name`, `selected_name` and `text_field` straight off the request. And the three
 * controls on the screen were ALREADY named exactly that: `field_name` from
 * `Form::select('field_name', ...)` at :47, and `selected_name` / `text_field` on the operator and
 * value controls the script injects at :158 and :172. Everything existed except a <form> around
 * them — there is even a dead `$('#frm_submit').on('submit')` handler at :194 waiting for one.
 *
 * So the repair is the house pattern this project already uses for the product list
 * (resources/views/product/partials/filters.blade.php): a plain GET form, no JavaScript. yajra's
 * `minifiedAjax()` points the table's ajax at the CURRENT url, so the filter rides both the
 * initial render and every later draw.
 *
 * WHAT IS **NOT** REPAIRED HERE, recorded rather than silently absorbed:
 *   - /customer-grid's Apply. `CustomerController::customer_grid_view()` is a bare
 *     `Customer::where('store_id', ...)->paginate(12)` with no filter handling, so wiring its
 *     button means WRITING server-side filtering that has never existed. That is a feature, not a
 *     repair, and the owner's decision authorises repairs. Ledger P2.5-F-07.
 *   - `CustomerController::CustomFilterData()` has no permission check at all, and its AOV branch
 *     tests `$AOV = $text_field_float` — an assignment, always truthy. Both are real; both change
 *     behaviour if touched, which the owner's decision excludes. Ledger P2.5-F-08 and F-09.
 */
class CustomerListFilterApplyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Customer');
        $this->merchant = User::find($this->merchant->id);
    }

    private function customerIn(Store $store, string $first, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => $first, 'last_name' => 'Tester',
            'email' => $email, 'password' => bcrypt('x'),
            'store_id' => $store->id, 'created_by' => $store->created_by,
            'status' => 1,
        ]);
    }

    private function html(): string
    {
        $response = $this->actingAs($this->merchant)->get('/customer');
        $this->assertSame(200, $response->status(), 'premise: the customer list rendered');

        return $response->getContent();
    }

    // =============================================================================================
    //  PREMISE — the server half already works, so the repair is only the form
    // =============================================================================================

    /**
     * If this ever fails, the repair below is wiring a form to nothing and the whole slice is
     * wrong. It is asserted directly against the DataTable's own query builder.
     */
    public function test_premise_the_datatable_query_already_filters_on_these_three_parameters(): void
    {
        $this->customerIn($this->store, 'Khalil', 'khalil@example.test');
        $this->customerIn($this->store, 'Rana', 'rana@example.test');

        $this->actingAs($this->merchant);

        $request = Request::create('/customer', 'GET', [
            'field_name' => 'Name', 'selected_name' => 'Includes', 'text_field' => 'Khalil',
        ]);
        $this->app->instance('request', $request);

        $names = (new CustomerDataTable())->query(new Customer(), $request)
            ->pluck('first_name')->all();

        $this->assertSame(['Khalil'], $names,
            'CustomerDataTable::query() no longer honours field_name/selected_name/text_field, '
            . 'so the GET form this slice adds would submit to a server that ignores it');
    }

    // =============================================================================================
    //  THE REPAIR — the controls are inside a GET form that submits to this same screen
    // =============================================================================================

    public function test_the_filter_controls_sit_inside_a_get_form_aimed_at_the_customer_list(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression(
            '/<form[^>]+method="GET"[^>]*>/i', $html,
            'the customer filter controls are still loose in the page, so there is nothing to submit');

        // The form's action is this screen, not the dead AJAX endpoint.
        $this->assertMatchesRegularExpression(
            '/<form[^>]+action="[^"]*\/customer"[^>]*>/i', $html,
            'the filter form does not post back to the customer list');
    }

    public function test_apply_is_a_real_submit_control_and_not_an_anchor(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression(
            '/<button[^>]+type="submit"[^>]*id="applyfilter"/i', $html,
            'Apply is still an <a> with no handler bound to its id');
    }

    /**
     * THE THREE NAMES ARE THE CONTRACT between this screen and CustomerDataTable::query().
     * The operator and value controls are injected by script, so their names are asserted in the
     * script source — that is where they live.
     */
    public function test_the_three_control_names_are_the_ones_the_datatable_reads(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('name="field_name"', $html,
            'the field select no longer carries the name the DataTable query reads');
        $this->assertStringContainsString('name="selected_name"', $html,
            'the injected operator select no longer carries the name the DataTable query reads');
        $this->assertStringContainsString('name="text_field"', $html,
            'the injected value input no longer carries the name the DataTable query reads');
    }

    /**
     * THE DEAD HANDLER IS GONE. Leaving it in place would mean a click both submitted the form and
     * fired an AJAX whose answer is thrown away — and the next person would read it as the
     * mechanism. Asserted by name because it is a specific block, not a general smell.
     */
    public function test_the_dead_ajax_into_a_nonexistent_element_is_removed(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('service-filter-data', $html,
            'the customer list still writes its filter results into #service-filter-data, which '
            . 'exists only in the vendored RewardClubPoint module');
    }

    // =============================================================================================
    //  THE FILTER ACTUALLY NARROWS — end to end, through the real screen
    // =============================================================================================

    public function test_applying_a_name_filter_narrows_the_list(): void
    {
        $this->customerIn($this->store, 'Khalil', 'khalil@example.test');
        $this->customerIn($this->store, 'Rana', 'rana@example.test');

        $this->actingAs($this->merchant);
        $request = Request::create('/customer', 'GET', [
            'field_name' => 'Name', 'selected_name' => 'Includes', 'text_field' => 'Rana',
        ]);
        $this->app->instance('request', $request);

        $rows = (new CustomerDataTable())->query(new Customer(), $request)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Rana', $rows->first()->first_name);
    }

    /** NEGATIVE CONTROL: a filter that matches nothing returns nothing, not everything. */
    public function test_a_filter_that_matches_nothing_returns_nothing(): void
    {
        $this->customerIn($this->store, 'Khalil', 'khalil@example.test');
        $this->customerIn($this->store, 'Rana', 'rana@example.test');

        $this->actingAs($this->merchant);
        $request = Request::create('/customer', 'GET', [
            'field_name' => 'Name', 'selected_name' => 'Includes', 'text_field' => 'zz-nobody-zz',
        ]);
        $this->app->instance('request', $request);

        $this->assertCount(0, (new CustomerDataTable())->query(new Customer(), $request)->get(),
            'an unmatched filter fell through to the unfiltered list, which is the failure mode '
            . 'that makes a filter worse than none');
    }

    /** NEGATIVE CONTROL: the tenant predicate still holds when a filter is in force. */
    public function test_another_tenants_customer_is_not_returned_even_on_a_matching_filter(): void
    {
        $other = $this->makeMerchant(['email' => 'other-' . uniqid() . '@example.test']);
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);
        $this->customerIn($otherStore, 'Khalil', 'khalil-other@example.test');
        $this->customerIn($this->store, 'Khalil', 'khalil-mine@example.test');

        $this->actingAs($this->merchant);
        $request = Request::create('/customer', 'GET', [
            'field_name' => 'Name', 'selected_name' => 'Includes', 'text_field' => 'Khalil',
        ]);
        $this->app->instance('request', $request);

        $rows = (new CustomerDataTable())->query(new Customer(), $request)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('khalil-mine@example.test', $rows->first()->email,
            'the filtered customer list crossed a tenant boundary');
    }

    // =============================================================================================
    //  NEGATIVE CONTROL — the screen's own gate is untouched
    // =============================================================================================

    public function test_a_principal_without_the_customer_permission_is_refused(): void
    {
        $outsider = $this->makeStaff($this->merchant, ['name' => 'No Customers']);
        $outsider->current_store = $this->store->id;
        $outsider->save();
        $outsider = User::find($outsider->id);

        $this->actingAs($outsider)->get('/customer')->assertRedirect();
    }
}
