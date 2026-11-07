<?php

namespace Tests\Feature\Merchant;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-R-07 — the customer GRID's filter had never worked, by any path.
 * =================================================================================================
 *
 * A POST-CLOSURE PHASE 02 DEFECT, repaired under owner decision D-40 and the owner's task #88
 * instruction. Distinct from the list's P2.5-R-02: that one needed only a `<form>`, because the
 * server half already existed. THIS one had no server half at all.
 *
 * THREE INDEPENDENT FAULTS, each sufficient on its own:
 *
 *   1. `CustomerController::customer_grid_view()` read NONE of `field_name`, `selected_name` or
 *      `text_field`. It was `Customer::where('store_id', ...)->paginate(12)` and nothing else, so
 *      there was no server-side filtering to reach.
 *
 *   2. The Apply control was an `<a id="apply-button">` whose handler GET-ed
 *      `customer.filter.data` and wrote the response into `$('#service-filter-data')`. **That
 *      element does not exist in this view** — the only two occurrences in the whole file were the
 *      two JS writes themselves. jQuery's `.html()` on an empty set is a silent no-op, so the
 *      request went out, came back, and nothing changed.
 *
 *   3. Even with the container added it could not have worked: `CustomFilterData()` renders
 *      `customer/filter.blade.php`, which emits `<tr>` TABLE ROWS — the LIST's fragment. Table
 *      rows written into a `div` are discarded by the HTML parser, and a table row is not a card.
 *
 * A CORRECTION TO THE RECORDED FINDING. `PHASE-2.5-SURFACE-INVENTORY.md` records
 * `customer-list-apply-filter-dead` as "0 requests on click". That was measured on the OLD LIST
 * view and is no longer true of either screen: the list was repaired by P2.5-R-02, and this grid
 * DOES fire exactly one request on click — it just never mattered. Fault 2 above is the accurate
 * statement for this surface.
 *
 * THE REPAIR is the list's already-approved P2.5-R-02 pattern — a plain GET form submitting to
 * this same screen — plus the server half it was missing, which calls the SAME
 * `CustomerListFilter::apply()` the list's DataTable calls. One semantics, two screens.
 *
 * A TRAP THIS FILE AVOIDS ON PURPOSE, recorded so the next reader does not fall into it:
 * the page echoes the submitted `text_field` back into the restoration script, so filtering on
 * `Name Includes 'Bilal'` puts the string "Bilal" into the rendered HTML regardless of whether
 * any customer matched. Every presence/absence assertion below therefore uses a NUMERIC filter,
 * whose threshold is a number and cannot collide with a customer's name.
 */
class CustomerGridFilterTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Customer');
        $this->merchant = User::find($this->merchant->id);

        $otherMerchant = $this->makeMerchant();
        $this->otherStore = $this->makeStore($otherMerchant);
    }

    private function customerWithOrders(Store $store, string $first, int $count, float $each): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => $first,
            'last_name' => 'Tester',
            'email' => strtolower($first) . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'status' => 1,
            'regiester_date' => now(),
        ]);

        for ($i = 0; $i < $count; $i++) {
            $this->makeOrder($store, [
                'customer_id' => $customer->id,
                'is_guest' => 0,
                'final_price' => $each,
            ]);
        }

        return $customer;
    }

    /** Same fixture as the list's operator test, so the two screens are compared on one dataset. */
    private function seedCustomers(): void
    {
        $this->customerWithOrders($this->store, 'Amal', 1, 100);
        $this->customerWithOrders($this->store, 'Bilal', 2, 150);
        $this->customerWithOrders($this->store, 'Carla', 5, 100);
        $this->customerWithOrders($this->store, 'Dalia', 3, 200);
        $this->customerWithOrders($this->store, 'Eyad', 0, 0);

        $this->customerWithOrders($this->otherStore, 'Zahra', 2, 150);
    }

    private function grid(array $query = []): string
    {
        $url = '/customer-grid' . ($query ? '?' . http_build_query($query) : '');
        $response = $this->actingAs($this->merchant)->get($url);

        $this->assertSame(200, $response->status(),
            "premise: {$url} answered {$response->status()} rather than rendering the grid");

        return $response->getContent();
    }

    /** Which of the fixture's customers the grid actually shows. */
    private function shown(array $query = []): array
    {
        $html = $this->grid($query);

        $found = [];
        foreach (['Amal', 'Bilal', 'Carla', 'Dalia', 'Eyad', 'Zahra'] as $name) {
            if (str_contains($html, $name . ' Tester')) {
                $found[] = $name;
            }
        }
        sort($found);

        return $found;
    }

    // =============================================================================================
    //  PREMISE — the grid renders the whole store when nothing is filtered
    // =============================================================================================

    public function test_premise_the_unfiltered_grid_shows_every_customer_in_this_store(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Bilal', 'Carla', 'Dalia', 'Eyad'], $this->shown(),
            'premise: the unfiltered grid must show this store and only this store, or every '
            . 'narrowing assertion below is measuring the wrong baseline');
    }

    // =============================================================================================
    //  FAULT 1 — the handler ignored every filter parameter
    // =============================================================================================

    public function test_the_grid_narrows_on_order_count(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Carla', 'Dalia'], $this->shown([
            'field_name' => 'No. of Orders', 'selected_name' => 'More Than', 'text_field' => '2',
        ]), 'customer_grid_view() still ignores the filter parameters and paginates the whole store');
    }

    public function test_the_grid_narrows_on_total_spend(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Eyad'], $this->shown([
            'field_name' => 'Total Spend', 'selected_name' => 'Less Than', 'text_field' => '300',
        ]), 'the grid does not honour Total Spend');
    }

    public function test_the_grid_narrows_on_aov(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Bilal'], $this->shown([
            'field_name' => 'AOV', 'selected_name' => 'Equal', 'text_field' => '150',
        ]), 'the grid does not honour AOV — and if it returns four names it has inherited the '
          . 'assignment-not-comparison fault that P2.5-R-06 repaired');
    }

    public function test_the_grid_narrows_on_name(): void
    {
        $this->seedCustomers();

        // `shown()` is not used here: the submitted value is echoed into the page, so presence of
        // the string proves nothing. The CARD markup is what is asserted instead.
        $html = $this->grid([
            'field_name' => 'Name', 'selected_name' => 'Includes', 'text_field' => 'Carla',
        ]);

        $this->assertStringContainsString('Carla Tester', $html, 'the matching customer is absent');
        $this->assertStringNotContainsString('Amal Tester', $html,
            'a non-matching customer is still on the grid, so Name is not being honoured');
        $this->assertStringNotContainsString('Dalia Tester', $html,
            'a non-matching customer is still on the grid');
    }

    /**
     * THE LIST AND THE GRID MUST AGREE. Not a stylistic preference: before P2.5-R-06 the two
     * copies of the filter had drifted in three places, and the grid's copy was the one nothing
     * exercised.
     */
    public function test_the_grid_and_the_list_answer_identically_for_every_numeric_operator(): void
    {
        $this->seedCustomers();

        $cases = [
            ['AOV', 'Equal', '150'],
            ['AOV', 'Less Than', '150'],
            ['AOV', 'More Than', '150'],
            ['No. of Orders', 'Equal', '3'],
            ['No. of Orders', 'Less Than', '3'],
            ['No. of Orders', 'More Than', '2'],
            ['Total Spend', 'Equal', '300'],
            ['Total Spend', 'Less Than', '300'],
            ['Total Spend', 'More Than', '300'],
        ];

        foreach ($cases as [$field, $operator, $value]) {
            // actingAs before driving the DataTable directly: its tenant predicate comes from
            // getCurrentStore(), which reads the authenticated user's current_store.
            $this->actingAs($this->merchant);

            $request = \Illuminate\Http\Request::create('/customer', 'GET', [
                'field_name' => $field, 'selected_name' => $operator, 'text_field' => $value,
            ]);
            $this->app->instance('request', $request);
            $listNames = (new \App\DataTables\CustomerDataTable())
                ->query(new Customer(), $request)->pluck('first_name')->all();
            sort($listNames);

            $gridNames = $this->shown([
                'field_name' => $field, 'selected_name' => $operator, 'text_field' => $value,
            ]);

            $this->assertSame($listNames, $gridNames,
                "the list and the grid disagree about '{$field} {$operator} {$value}'");
        }
    }

    // =============================================================================================
    //  FAULT 2 — the dead AJAX, and the container that never existed
    // =============================================================================================

    public function test_the_filter_controls_sit_inside_a_get_form_aimed_at_the_grid(): void
    {
        $html = $this->grid();

        $this->assertMatchesRegularExpression('/<form[^>]+method="GET"[^>]*>/i', $html,
            'the grid filter controls are still loose in the page, so there is nothing to submit');
        $this->assertMatchesRegularExpression('/<form[^>]+action="[^"]*\/customer-grid"[^>]*>/i', $html,
            'the grid filter form does not submit back to the grid');
    }

    public function test_apply_is_a_real_submit_control_and_not_an_anchor(): void
    {
        $html = $this->grid();

        $this->assertMatchesRegularExpression('/<button[^>]+type="submit"/i', $html,
            'Apply is still an <a>, which cannot be activated by Enter or the space bar either');
        $this->assertStringNotContainsString('id="apply-button"', $html,
            'the old anchor id is still present, so the dead click handler may still be bound');
    }

    public function test_the_dead_ajax_into_a_nonexistent_element_is_removed(): void
    {
        $html = $this->grid();

        $this->assertStringNotContainsString('service-filter-data', $html,
            'the AJAX block writing into an element this view does not contain is still shipped; '
            . 'a click would both submit the form and fire a request whose answer is discarded');
        $this->assertStringNotContainsString('customer-filter-data', $html,
            'the grid still references the list-fragment endpoint, which answers with <tr> rows');
    }

    public function test_the_three_control_names_are_the_ones_the_filter_reads(): void
    {
        $html = $this->grid();

        $this->assertStringContainsString('name="field_name"', $html,
            'the field select no longer carries the name customer_grid_view() reads');
        $this->assertStringContainsString('name="selected_name"', $html,
            'the injected operator select no longer carries the name customer_grid_view() reads');
        $this->assertStringContainsString('name="text_field"', $html,
            'the injected value input no longer carries the name customer_grid_view() reads');
    }

    /**
     * The two hidden inputs were written to by the field-change handler and read by nothing — the
     * AJAX built its payload from the visible controls. Inside a real form they would be submitted
     * as two more empty parameters on every apply.
     */
    public function test_the_vestigial_hidden_inputs_are_gone(): void
    {
        $html = $this->grid();

        $this->assertStringNotContainsString('name="select_value"', $html,
            'the vestigial select_value input is still inside the form');
        $this->assertStringNotContainsString('name="text_value"', $html,
            'the vestigial text_value input is still inside the form');
    }

    public function test_reset_points_at_the_unfiltered_grid_rather_than_nowhere(): void
    {
        $html = $this->grid([
            'field_name' => 'No. of Orders', 'selected_name' => 'More Than', 'text_field' => '2',
        ]);

        $this->assertMatchesRegularExpression('/id="clearfilter"/', $html, 'Reset is missing entirely');

        // ASSERTED ON THE CONTROL, not on the page. The first draft of this test checked the whole
        // document for `href="#!"` and failed -- not because Reset was wrong, but because that dead
        // -link pattern appears in a dozen other views and reaches this page through an include.
        // A page-wide absence assertion was measuring somebody else's markup.
        $this->assertMatchesRegularExpression(
            '/<a\s+href="[^"]*\/customer-grid"[^>]*id="clearfilter"/',
            $html,
            'Reset does not point at the unfiltered grid. It was `href="#!"`, which goes nowhere, '
            . 'and a filter you cannot leave is its own defect');

        $this->assertDoesNotMatchRegularExpression(
            '/<a\s+href="#!"[^>]*id="clearfilter"/', $html,
            'the Reset control still carries the dead href="#!"');
    }

    // =============================================================================================
    //  THE OTHER HALF OF A GET FILTER — the merchant can see and keep what is in force
    // =============================================================================================

    public function test_the_submitted_field_is_still_selected_on_the_filtered_page(): void
    {
        $this->seedCustomers();

        $html = $this->grid([
            'field_name' => 'Total Spend', 'selected_name' => 'More Than', 'text_field' => '300',
        ]);

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="Total Spend"[^>]+selected/i', $html,
            'the field select does not show the filter that is in force, so the merchant sees a '
            . 'narrowed grid with no indication of why');
    }

    public function test_the_submitted_operator_and_value_are_replayed_into_the_controls(): void
    {
        $this->seedCustomers();

        $html = $this->grid([
            'field_name' => 'Total Spend', 'selected_name' => 'More Than', 'text_field' => '300',
        ]);

        $this->assertStringContainsString('"More Than"', $html,
            'the operator is not replayed, so the merchant cannot edit the filter — only rebuild it');
        $this->assertStringContainsString('restoreSubmittedFilter', $html,
            'the restoration block is absent');
    }

    /**
     * Without `withQueryString()` page 2 of a filtered grid drops the filter and shows the
     * unfiltered remainder — a working filter that appears to lose its mind on the second page.
     */
    public function test_pagination_keeps_the_filter(): void
    {
        // 15 customers with exactly one order each: more than the 12 per page, all matching.
        for ($i = 1; $i <= 15; $i++) {
            $this->customerWithOrders($this->store, 'Paged' . $i, 1, 50);
        }

        $html = $this->grid([
            'field_name' => 'No. of Orders', 'selected_name' => 'Equal', 'text_field' => '1',
        ]);

        $this->assertMatchesRegularExpression('/href="[^"]*page=2[^"]*"/', $html,
            'premise: 15 matching customers at 12 per page must produce a second page link');
        $this->assertMatchesRegularExpression(
            '/href="[^"]*(field_name=No\.?\+?%?2?0?of|field_name=No)[^"]*page=2|href="[^"]*page=2[^"]*field_name=/',
            $html,
            'the pagination links drop the filter, so page 2 of a filtered grid is unfiltered');
    }

    // =============================================================================================
    //  TENANCY AND PERMISSION — unchanged by this repair, and asserted because it touches a query
    // =============================================================================================

    public function test_no_grid_filter_reaches_another_stores_customer(): void
    {
        $this->seedCustomers();

        $cases = [
            ['AOV', 'Equal', '150', 'Bilal'],
            ['No. of Orders', 'Equal', '2', 'Bilal'],
            ['No. of Orders', 'More Than', '1', 'Bilal'],
            ['No. of Orders', 'Less Than', '3', 'Bilal'],
            ['Total Spend', 'Equal', '300', 'Bilal'],
            ['Total Spend', 'More Than', '200', 'Bilal'],
            ['Total Spend', 'Less Than', '400', 'Bilal'],
        ];

        foreach ($cases as [$field, $operator, $value, $mustBePresent]) {
            $names = $this->shown([
                'field_name' => $field, 'selected_name' => $operator, 'text_field' => $value,
            ]);

            // POSITIVE CONTROL FIRST. Store B's Zahra carries Bilal's exact profile, so if Bilal
            // is missing the grid returned nothing and the absence below proves nothing at all.
            $this->assertContains($mustBePresent, $names,
                "positive control: '{$field} {$operator} {$value}' must return this store's "
                . "{$mustBePresent}, or the cross-tenant assertion that follows is vacuous");

            $this->assertNotContains('Zahra', $names,
                "the grid's '{$field} {$operator} {$value}' reached store B's customer");
        }
    }

    public function test_an_unfiltered_grid_does_not_reach_another_stores_customer(): void
    {
        $this->seedCustomers();

        $names = $this->shown();

        $this->assertContains('Amal', $names, 'positive control: this store renders');
        $this->assertNotContains('Zahra', $names, "the unfiltered grid reached store B's customer");
    }

    /**
     * The permission is unchanged by this repair — `Manage Customer`, as before. Asserted because
     * the method's body changed and an accidental widening is exactly what a review looks for.
     */
    public function test_a_principal_without_manage_customer_is_still_refused(): void
    {
        $this->seedCustomers();

        $staff = $this->makeStaff($this->merchant);
        $staff->current_store = $this->store->id;
        $staff->save();

        $response = $this->actingAs(User::find($staff->id))->get('/customer-grid?' . http_build_query([
            'field_name' => 'No. of Orders', 'selected_name' => 'More Than', 'text_field' => '0',
        ]));

        $this->assertNotSame(200, $response->status(),
            'a staff user holding no customer permission reached the filtered grid');
    }
}
