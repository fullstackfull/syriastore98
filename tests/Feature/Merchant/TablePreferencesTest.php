<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-06-4 -- persisted table preferences, on every merchant table.
 * =============================================================================================
 *
 * WHAT IS PERSISTED: rows per page, sort order, and column visibility. A merchant who works
 * fifty rows at a time said so once.
 *
 * WHAT IS DELIBERATELY NOT: the search term and the page number. DataTables' own `stateSave`
 * restores both, and that is the one thing that turns this feature into a support ticket -- a
 * merchant who searched "refund" last Thursday returns to four rows out of nine hundred, with
 * the box pre-filled somewhere they are not looking, and concludes their orders are gone.
 *
 * ONE CHANGE, 68 TABLES. `buildDataTable()` is what every DataTable in the application is built
 * by -- 39 in `app/DataTables/` and 29 inside vendored modules -- so the option goes there and
 * **no `packages/workdo/` file is touched** to reach the vendored twenty-nine. That reach is
 * also the risk, which is why the second half of this file re-drives real list screens: a
 * mistake here is a mistake on every list in the admin.
 *
 * THE SANITISER'S OWN BEHAVIOUR IS MEASURED BY RUNNING IT, not by matching its source. It is
 * JavaScript, so it is executed in node against a real DataTables state object --
 * `docs/development/phase-2/tools/check-table-state.cjs`, driven below. A test that grepped the
 * function body for the word "search" would pass against a function that did nothing with it,
 * which is the shape of vacuous test this project keeps catching.
 *
 * NO SCHEMA CHANGE. Per-device presentation state needs no table and this phase is not
 * authorised to add one; what is stored is a row count, a sort direction and a set of booleans,
 * with no tenant or customer data in it.
 */
class TablePreferencesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private function dataTableQuery(array $columns): array
    {
        $query = ['draw' => 1, 'start' => 0, 'length' => 10];

        foreach (array_values($columns) as $i => $name) {
            $query["columns[{$i}][data]"] = $name;
            $query["columns[{$i}][name]"] = $name;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'asc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        return $query;
    }

    private function makeProduct(int $storeId, int $createdBy, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 0,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 5,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    // =========================================================================================
    // The wiring -- present on a table built by the shared helper, which is all of them
    // =========================================================================================

    public function test_the_shared_helper_turns_state_saving_on_with_no_expiry(): void
    {
        $table = buildDataTable('fixture-table', app(\Yajra\DataTables\Html\Builder::class),
            buildDataTableColumn(['name' => ['title' => 'Name']], false));

        $options = $table->getOptions();

        $this->assertTrue($options['stateSave'] ?? false,
            'state saving is off, so no table remembers anything');

        /*
         * 0, NOT the DataTables default of 7200. That default expires the merchant's choice
         * after two hours, and `-1` is sessionStorage, which loses it when the tab closes.
         * Neither of those is a preference, so the exact value is asserted rather than its
         * presence.
         */
        $this->assertSame(0, $options['stateDuration'] ?? null,
            'stateDuration must be 0 -- localStorage with no expiry');
    }

    public function test_both_state_hooks_are_wired_and_emitted_as_real_javascript(): void
    {
        $table = buildDataTable('fixture-table', app(\Yajra\DataTables\Html\Builder::class),
            buildDataTableColumn(['name' => ['title' => 'Name']], false));

        $options = $table->getOptions();

        // BOTH directions. `stateLoadParams` is not redundant: a state written by an earlier
        // build is already in localStorage and honouring its search term would be the defect.
        $this->assertArrayHasKey('stateSaveParams', $options);
        $this->assertArrayHasKey('stateLoadParams', $options);

        /*
         * AND IT REACHES THE PAGE AS A FUNCTION, not as a quoted string. yajra decides that by
         * `Helper::isJavascript()`, which only treats a value as raw JS when it starts with
         * `function` / `$` / `$.`. A value it quotes would be `"stateSaveParams":"function …"`
         * and DataTables would ignore it silently -- the option present, the behaviour absent.
         */
        $script = (string) $table->generateScripts();

        $this->assertStringContainsString('"stateSaveParams":function', $script,
            'the hook was emitted as a STRING, so DataTables will ignore it');
        $this->assertStringContainsString('"stateLoadParams":function', $script);

        // And it calls the one implementation rather than inlining a second copy of the rule.
        $this->assertStringContainsString('StoreSyriaDS.sanitizeTableState', $script);
    }

    /**
     * THE GUARD. `buildDataTable()` also builds tables on screens outside the admin layout --
     * the theme and guest layouts do not load `store-syria-ds.js` -- and an unguarded call would
     * throw on every state save, which DataTables does not catch.
     */
    public function test_the_hook_is_guarded_against_the_script_being_absent(): void
    {
        $table = buildDataTable('fixture-table', app(\Yajra\DataTables\Html\Builder::class),
            buildDataTableColumn(['name' => ['title' => 'Name']], false));

        $this->assertStringContainsString(
            'window.StoreSyriaDS && window.StoreSyriaDS.sanitizeTableState',
            (string) $table->generateScripts(),
            'the hook calls into the design-system script without checking it is there'
        );
    }

    // =========================================================================================
    // The sanitiser's actual behaviour -- executed, not read
    // =========================================================================================

    /**
     * Runs `docs/development/phase-2/tools/check-table-state.cjs`, which loads the real
     * `public/js/store-syria-ds.js` in node and drives `sanitizeTableState` against a DataTables
     * state object. Skipped rather than failed where node is absent: the information this test
     * carries is the script's exit code, and a missing interpreter is not a defect in the code.
     */
    public function test_the_sanitiser_keeps_the_preferences_and_drops_the_search(): void
    {
        $script = base_path('docs/development/phase-2/tools/check-table-state.cjs');

        $this->assertFileExists($script, 'the behaviour check for the sanitiser is missing');

        exec('command -v node 2>/dev/null', $where, $hasNode);

        if ($hasNode !== 0) {
            $this->markTestSkipped('node is not available in this environment');
        }

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, "the sanitiser's behaviour check failed:\n" . implode("\n", $output));

        // PREMISE: the script really ran its cases rather than exiting 0 having done nothing.
        $this->assertStringContainsString('cases passed', implode("\n", $output),
            'the check script exited 0 without reporting any case -- it measured nothing');
    }

    // =========================================================================================
    // And the 68 tables still work -- the reach is the risk
    // =========================================================================================

    public function test_the_product_list_still_renders_with_state_saving_on(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $this->makeProduct($store->id, $merchant->id, 'Own Product');

        $response = $this->actingAs($merchant)->get('/product');

        $this->assertNotSame(404, $response->getStatusCode(), 'the product route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the product screen must not 500');
        $response->assertOk();

        // The option really reached THIS screen's emitted script, not only the fixture table.
        $response->assertSee('stateSave', false);
    }

    public function test_the_product_datatable_ajax_leg_still_returns_rows(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $this->makeProduct($store->id, $merchant->id, 'Own Product');

        $response = $this->actingAs($merchant)
            ->withHeaders(self::AJAX)
            ->getJson('/product?' . http_build_query($this->dataTableQuery(['name', 'price'])));

        $response->assertOk();
        $payload = $response->json();

        $this->assertArrayHasKey('draw', $payload, 'premise: yajra served the AJAX leg');
        $this->assertSame(1, (int) $payload['recordsTotal'],
            'state saving broke the AJAX leg of the product table');
    }

    public function test_the_order_list_still_renders_with_state_saving_on(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');
        $this->makeOrder($store, ['product_order_id' => 'ORD-STATE-1']);

        $response = $this->actingAs($merchant)->get('/order');

        $this->assertNotSame(404, $response->getStatusCode(), 'the order route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the order screen must not 500');
        $response->assertOk();
        $response->assertSee('stateSave', false);
    }

    /**
     * A THIRD SCREEN, and a customer one, because the two above are both tables this phase has
     * been editing. If the helper change broke a list nobody here touched, this is what says so.
     */
    public function test_the_customer_list_still_renders_with_state_saving_on(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Customer');

        $response = $this->actingAs($merchant)->get('/customer');

        $this->assertNotSame(404, $response->getStatusCode(), 'the customer route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the customer screen must not 500');
        $response->assertOk();
        $response->assertSee('stateSave', false);
    }
}
