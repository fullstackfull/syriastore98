<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\Newsletter;
use App\Models\PlanOrder;
use App\Models\PlanRequest;
use App\Models\ProductQuestion;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Security\DetectsActiveMarkup;
use Tests\Support\Security\MinimalRows;
use Tests\Support\Security\ReadsDataTableCells;

/**
 * P1-SEC-80 -- text a SHOPPER, a VISITOR or a MERCHANT chooses, printed raw into somebody else's list.
 * ====================================================================================================
 *
 * P1-SEC-79 read one door (the support tickets) and recorded the class as leads. This file executes the next ones, through the real routes, the DOM as the oracle
 * (tests/Support/Security/DetectsActiveMarkup) and a premise per surface that feeds the oracle the payload printed raw and shows that it is found.
 *
 * WHY `email` IS NOT A SAFE COLUMN. Laravel's `email` rule accepts a QUOTED local part: `"<img src=x onerror=1>"@example.com` passes `email`, `email:rfc` and `email:rfc,dns`
 * (measured on this codebase's Laravel 11.57), so "the column is validated as an e-mail address" does not make it markup-free. The newsletter's own rule is weaker still: it has NO
 * `email` rule, only `required` and `unique`.
 */
class PanelListStoredXssTest extends SecurityTestCase
{
    use DetectsActiveMarkup;
    use MinimalRows;
    use ReadsDataTableCells;
    use RefreshDatabase;

    /** Carries the marker the oracle looks for in a script, and avoids `javascript` and `alert`, which the opt-in XSS middleware would mangle (the test would then measure the denylist). */
    private const PAYLOAD = '<img src=x onerror=XSS-1>';

    public function test_the_oracle_finds_the_payload_when_it_is_printed_raw(): void
    {
        $this->assertNotSame([], $this->activeMarkupIn('<td>' . self::PAYLOAD . '</td>'), 'premise: the oracle must see the payload raw, or every other case here proves nothing');
        $this->assertSame([], $this->activeMarkupIn('<td>' . e(self::PAYLOAD) . '</td>'), 'premise: and must not see it escaped');
    }

    /** A VISITOR with no account: `POST /newsletter?<slug>` (the storefront footer form) stores any string, and the merchant's subscriber list printed it raw. */
    public function test_a_visitors_newsletter_address_is_not_printed_raw_on_the_merchants_list(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Newsletter');

        $this->post('/newsletter?' . $store->slug, ['email' => self::PAYLOAD])->assertRedirect();

        $this->assertSame(1, Newsletter::withoutGlobalScopes()->where('store_id', $store->id)->where('email', self::PAYLOAD)->count(),
            'premise: the visitor\'s string must really be stored as typed, by the real route');

        $envelope = $this->listEnvelope($merchant, '/newsletter');
        $this->assertSame(1, (int) $envelope['recordsTotal'], 'premise: the merchant sees the row');

        $this->assertSame([], $this->activeMarkupIn($this->cellsOf($envelope)), 'the subscriber list printed a visitor\'s string as markup');
    }

    // ------------------------------------------------------------------ fixtures

    /** A shopper of the store, with the name a shopper chooses: registration validates `first_name` as `required|string|max:255` and nothing more (F98). */
    private function shopper(Store $store, array $overrides = []): Customer
    {
        return Customer::forceCreate(array_merge([
            'first_name' => 'Plain',
            'last_name' => 'Shopper',
            'email' => 'plain-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '555000111',
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id,
            'status' => 1,
            'register_type' => 'email',
            'regiester_date' => now(),
        ], $overrides));
    }

    /** the premise every list case shares: the row is visible to the reader, so an empty answer cannot pass for "nothing active" */
    private function assertListHoldsTheRowAndNothingActive(array $envelope, string $what): void
    {
        $this->assertGreaterThanOrEqual(1, (int) $envelope['recordsTotal'], 'premise: the reader must see the row, or an empty list passes for the wrong reason');
        $this->assertStringContainsString('XSS-1', $this->cellsOf($envelope), 'premise: the list must print the stored string in some form (raw before the fix, escaped after it), or a pass means nothing');
        $this->assertSame([], $this->activeMarkupIn($this->cellsOf($envelope)), $what);
    }

    // ------------------------------------------------------------------ the shopper's name, on the merchant's lists

    /** A REGISTERED SHOPPER asks a question; the merchant's question list printed the shopper's name raw (`customer_id` is a raw column). */
    public function test_a_shoppers_name_is_not_printed_raw_on_the_product_question_list(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Product Question');

        $shopper = $this->shopper($store, ['first_name' => self::PAYLOAD]);
        $this->minimalRow(ProductQuestion::class, ['store_id' => $store->id, 'customer_id' => $shopper->id, 'question' => 'Is it in stock?']);

        $this->assertListHoldsTheRowAndNothingActive($this->listEnvelope($merchant, '/product-question'), 'the question list printed a shopper\'s name as markup');
    }

    // ------------------------------------------------------------------ a merchant's name, on the OPERATOR's lists

    /** A merchant registers under any name; the operator's store list printed it raw (`name` is a raw column). */
    public function test_a_merchants_name_is_not_printed_raw_on_the_operators_store_list(): void
    {
        $operator = $this->makePlatformAdmin();
        $merchant = $this->makeMerchant(['name' => self::PAYLOAD, 'created_by' => $operator->id]);
        $this->makeStore($merchant);

        $this->assertListHoldsTheRowAndNothingActive($this->listEnvelope($operator, '/store/list'), 'the operator\'s store list printed a merchant\'s name as markup');
    }

    /** The plan-order list joins the merchant's name in as `user_name`, a raw column: the operator opens it to approve and read plan orders. */
    public function test_a_merchants_name_is_not_printed_raw_on_the_operators_plan_order_list(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->grantPermission($operator, 'Manage Plan');
        $merchant = $this->makeMerchant(['name' => self::PAYLOAD, 'created_by' => $operator->id]);
        $plan = $this->minimalRow(Plan::class, ['name' => 'Starter']);
        $this->minimalRow(PlanOrder::class, ['user_id' => $merchant->id, 'plan_id' => $plan->id, 'plan_name' => 'Starter']);

        $this->assertListHoldsTheRowAndNothingActive($this->listEnvelope($operator, '/plan'), 'the operator\'s plan order list printed a merchant\'s name as markup');
    }

    /** The plan-request list prints `$request->user->name` through a raw column. */
    public function test_a_merchants_name_is_not_printed_raw_on_the_operators_plan_request_list(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->grantPermission($operator, 'Manage Plan Request');
        $merchant = $this->makeMerchant(['name' => self::PAYLOAD, 'created_by' => $operator->id]);
        $plan = $this->minimalRow(Plan::class, ['name' => 'Starter']);
        $this->minimalRow(PlanRequest::class, ['user_id' => $merchant->id, 'plan_id' => $plan->id]);

        $this->assertListHoldsTheRowAndNothingActive($this->listEnvelope($operator, '/plan-request'), 'the operator\'s plan request list printed a merchant\'s name as markup');
    }

    // ------------------------------------------------------------------ a staff member's name, on the OWNER's list

    /** A staff member edits their own name on their profile; the owner's staff list printed it raw (`name` is a raw column). */
    public function test_a_staff_members_name_is_not_printed_raw_on_the_owners_user_list(): void
    {
        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage User');
        $this->makeStaff($owner, ['name' => self::PAYLOAD]);

        $this->assertListHoldsTheRowAndNothingActive($this->listEnvelope($owner, '/user-list'), 'the owner\'s staff list printed a staff member\'s name as markup');
    }

    /**
     * The downloadable-sales report prints the shopper's name (`customer`, raw) and the product and variant names (`product_name`, raw, joined with <br>). It is served by ReportController,
     * which CLAUDE.md records as unable to run under the suite at all -- so the DataTable class is driven directly, with the same query and the same closures the route would use.
     */
    public function test_a_shoppers_name_is_not_printed_raw_on_the_downloadable_sales_list(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $shopper = $this->shopper($store, ['first_name' => self::PAYLOAD]);
        $this->makeOrder($store, ['customer_id' => $shopper->id, 'is_guest' => 0, 'product_json' => '[]']);

        $this->actingAs($merchant);
        request()->headers->set('X-Requested-With', 'XMLHttpRequest');
        request()->merge(['draw' => 1, 'start' => 0, 'length' => 25]);

        $table = app(\App\DataTables\SalesDownloadableProductDataTable::class);
        $envelope = $table->dataTable($table->query(new \App\Models\Order()))->toJson()->getData(true);

        $this->assertListHoldsTheRowAndNothingActive($envelope, 'the downloadable-sales list printed a shopper\'s name as markup');
    }

    // ------------------------------------------------------------------ the POS panel: a shopper's details, built into HTML in the controller

    /**
     * `PosController::create` builds the customer panel by concatenation -- name, phone, address, city, country, postcode, every one the SHOPPER's -- and `pos/create.blade.php` prints
     * the result with `{!! !!}`. The staff member at the till is the reader.
     */
    public function test_a_shoppers_details_are_not_printed_raw_in_the_pos_customer_panel(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Pos');

        $shopper = $this->shopper($store, ['first_name' => self::PAYLOAD, 'mobile' => self::PAYLOAD]);
        DeliveryAddress::unguarded(fn () => DeliveryAddress::create(array_merge($this->minimalColumnsFor(DeliveryAddress::class), [
            'id' => $shopper->id, 'store_id' => $store->id, 'customer_id' => $shopper->id, 'address' => self::PAYLOAD, 'postcode' => self::PAYLOAD, 'phone' => self::PAYLOAD, 'full_name' => self::PAYLOAD,
        ])));
        $product = $this->minimalRow(\App\Models\Product::class, ['store_id' => $store->id, 'name' => 'Till item', 'tax_id' => 0, 'product_type' => null]);

        $cart = ['row-1' => ['product_id' => $product->id, 'orignal_price' => 10, 'quantity' => 1, 'name' => 'Till item', 'total_orignal_price' => 10]];

        $response = $this->actingAs($merchant)
            ->withSession(['pos_' . $store->id => $cart])
            ->getJson('/pos/create?' . http_build_query(['vc_name' => self::PAYLOAD, 'store_id' => $store->id]));

        $response->assertOk();
        $this->assertSame('success', $response->json('status'), 'premise: the till answered with its panel, not a refusal');
        $html = (string) $response->json('html');
        $this->assertStringContainsString('XSS-1', $html, 'premise: the panel prints the shopper\'s string in some form');
        $this->assertSame([], $this->activeMarkupIn($html), 'the POS customer panel printed a shopper\'s details as markup');
    }

    /** the printed receipt builds the same customer panel a second time (`printView`), and prints the address on a line of its own */
    public function test_a_shoppers_details_are_not_printed_raw_on_the_pos_receipt(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Pos');

        $shopper = $this->shopper($store, ['first_name' => self::PAYLOAD, 'mobile' => self::PAYLOAD]);
        DeliveryAddress::unguarded(fn () => DeliveryAddress::create(array_merge($this->minimalColumnsFor(DeliveryAddress::class), [
            'id' => $shopper->id, 'store_id' => $store->id, 'customer_id' => $shopper->id, 'address' => self::PAYLOAD, 'postcode' => self::PAYLOAD, 'phone' => self::PAYLOAD, 'full_name' => self::PAYLOAD,
        ])));
        $product = $this->minimalRow(\App\Models\Product::class, ['store_id' => $store->id, 'name' => 'Till item', 'tax_id' => 0, 'product_type' => null]);
        $cart = ['row-1' => ['product_id' => $product->id, 'orignal_price' => 10, 'quantity' => 1, 'name' => 'Till item', 'total_orignal_price' => 10]];

        $response = $this->actingAs($merchant)
            ->withSession(['pos_' . $store->id => $cart, 'new_pos_id_' . $store->id => time()])
            ->getJson('/printview/pos?' . http_build_query(['vc_name' => self::PAYLOAD, 'store_id' => $store->id]));

        $response->assertOk();
        $html = (string) $response->json('html');
        $this->assertStringContainsString('XSS-1', $html, 'premise: the receipt prints the shopper\'s string in some form (and is not "Cart is empty")');
        $this->assertSame([], $this->activeMarkupIn($html), 'the POS receipt printed a shopper\'s details as markup');
    }

    /** the columns a row needs that the test is not about (the same idea as minimalRow(), for a model whose primary key the test sets) */
    private function minimalColumnsFor(string $modelClass): array
    {
        $values = [];

        foreach (\Illuminate\Support\Facades\Schema::getColumns((new $modelClass())->getTable()) as $column) {
            if ($column['auto_increment'] || $column['nullable'] || $column['default'] !== null) {
                continue;
            }

            $type = strtolower((string) $column['type_name']);
            $values[$column['name']] = match (true) {
                in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'boolean', 'bool', 'decimal', 'numeric', 'float', 'double', 'real'], true) => 1,
                in_array($type, ['datetime', 'timestamp'], true) => now()->toDateTimeString(),
                $type === 'date' => now()->toDateString(),
                default => 'fixture',
            };
        }

        return $values;
    }
}
