<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * STORED XSS IN THE MERCHANT ADMIN, from shopper-written registration data.  P2.5-PKG-I
 * =================================================================================================
 *
 * `AbandonCartDataTable` and `WishlistDataTable` both declare
 *
 *     ->rawColumns(['customer_id','email','product','action'])
 *
 * which tells yajra NOT to escape those columns, and both then return shopper-written values into
 * two of them:
 *
 *     ->editColumn('customer_id', fn ($row) => $row->UserData->first_name.' '.$row->UserData->last_name)
 *     ->editColumn('email',       fn ($row) => $row->UserData->email)
 *
 * So a shopper who registers with a first name of `<img src=x onerror=...>` gets it EXECUTED in
 * the merchant's admin the moment the merchant opens the abandoned-carts or abandoned-wishlists
 * report. The shopper chooses the payload; the merchant's session runs it.
 *
 * THIS IS NOT A NEW CLASS OF DEFECT. It is the one `CustomerListOutputEscapingTest` and
 * `OrderListCellEscapingTest` already fixed on their own tables, and these two were missed. The
 * repair is the one those used: `e()` applied to EACH VALUE, never to the finished string.
 *
 * THE PAYLOAD CARRIES NO `javascript` AND NO `alert`, deliberately, and that is inherited reasoning
 * rather than mine: `app/Http/Middleware/XSS.php` strips those two words from every `web` input, so
 * a payload containing them would be neutered on the way IN and this test would pass without the
 * output ever being escaped. A word blacklist is not an output-encoding control, and the needle is
 * chosen so it cannot be mistaken for one.
 *
 * BOTH HALVES ARE ASSERTED EVERYWHERE: the raw tag must be ABSENT (the security property) and the
 * escaped form must be PRESENT (so the absence cannot pass because the column stopped rendering).
 */
class AbandonedListOutputEscapingTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Deliberately free of `javascript` and `alert` — see the class docblock. */
    private const PAYLOAD = '<img src=x onerror=1>';

    private const AJAX = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ];

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

    /**
     * A merchant who may open BOTH reports, a shopper carrying `$overrides`, and one cart row and
     * one wishlist row belonging to that shopper.
     *
     * @return array{0:\App\Models\User,1:\App\Models\Customer}
     */
    private function arrange(array $overrides = []): array
    {
        [$merchant, $store] = $this->makeTenant();
        foreach (['Manage Cart', 'Manage Wishlist'] as $permission) {
            $this->grantPermission($merchant, $permission);
        }

        $customer = Customer::forceCreate(array_merge([
            'first_name' => 'Plain',
            'last_name' => 'Shopper',
            'email' => 'plain-' . uniqid() . '@example.test',
            'mobile' => '555000111',
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id,
            'status' => 1,
            'regiester_date' => now(),
        ], $overrides));

        // A REAL category: `products.category_id` carries a foreign key, so the 0 an earlier
        // draft used is refused by SQLite rather than silently stored.
        $category = Category::forceCreate([
            'name' => 'Escaping Probe Cat',
            'slug' => 'escaping-probe-cat-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ]);

        $product = Product::forceCreate([
            'name' => 'Escaping Probe Product',
            'slug' => 'escaping-probe-' . uniqid(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0, 'category_id' => $category->id,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'qty' => 1, 'price' => 10, 'store_id' => $store->id,
        ]);

        Wishlist::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'status' => 1, 'store_id' => $store->id,
        ]);

        return [$merchant, $customer];
    }

    /** The row's cell values as the browser receives them — decoded, not the json_encode blob. */
    private function cells(\App\Models\User $merchant, string $path): string
    {
        $response = $this->actingAs($merchant)
            ->withHeaders(self::AJAX)
            ->getJson($path . '?' . http_build_query(
                $this->dataTableQuery(['customer_id', 'email', 'product', 'action'])
            ));

        $response->assertOk();
        $payload = $response->json();

        // PREMISE. The HTML branch returns a view, not this envelope, so without `draw` the drive
        // reached the wrong leg and every assertion below would be measuring nothing.
        $this->assertArrayHasKey('draw', $payload, "{$path}: yajra served the HTML leg, not AJAX");
        $this->assertArrayHasKey('data', $payload, "{$path}: no data key");
        $this->assertGreaterThan(0, (int) ($payload['recordsTotal'] ?? 0),
            "premise: the merchant must actually see a row on {$path}");

        $row = $payload['data'][0] ?? [];

        return implode("\n", array_map(
            static fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v),
            $row
        ));
    }

    /**
     * Three shopper-written fields reaching the cell by two different routes: concatenation
     * (`first_name`, `last_name` into the `customer_id` column) and a lone value (`email`).
     *
     * @return array<string,array{0:string}>
     */
    public static function shopperWrittenFields(): array
    {
        return [
            'first_name' => ['first_name'],
            'last_name' => ['last_name'],
            'email' => ['email'],
        ];
    }

    /** @return array<string,array{0:string}> */
    public static function abandonedReports(): array
    {
        return [
            'abandoned carts' => ['/abandon-carts-handled'],
            'abandoned wishlists' => ['/wishlist'],
        ];
    }

    /**
     * @dataProvider shopperWrittenFields
     */
    public function test_a_shopper_chosen_string_is_escaped_on_the_abandoned_cart_report(string $field): void
    {
        $this->assertEscaped($field, '/abandon-carts-handled');
    }

    /**
     * @dataProvider shopperWrittenFields
     */
    public function test_a_shopper_chosen_string_is_escaped_on_the_abandoned_wishlist_report(string $field): void
    {
        $this->assertEscaped($field, '/wishlist');
    }

    private function assertEscaped(string $field, string $path): void
    {
        // `email` must stay a plausible address; the payload rides in the local part, which is
        // where a shopper's own input goes.
        $value = $field === 'email' ? self::PAYLOAD . '@example.test' : self::PAYLOAD;

        [$merchant] = $this->arrange([$field => $value]);

        $cells = $this->cells($merchant, $path);

        $this->assertStringNotContainsString(
            '<img',
            $cells,
            "{$path} / {$field}: a shopper-written `<img` tag reached the merchant's page "
            . 'unescaped. Apply e() to each value, as CustomerDataTable:69-70 does.'
        );
        $this->assertStringContainsString(
            '&lt;img',
            $cells,
            "{$path} / {$field}: the escaped form is absent too, so the value is not being "
            . 'rendered at all and the assertion above passed for the wrong reason.'
        );
    }

    /**
     * The tables' own markup must survive. Escaping the finished string rather than each value
     * would double-escape the partials these columns render, and the merchant would meet visible
     * tag soup. That is the obvious wrong fix, so it is pinned.
     *
     * @dataProvider abandonedReports
     */
    public function test_the_reports_own_markup_is_still_rendered_as_markup(string $path): void
    {
        [$merchant] = $this->arrange();

        $cells = $this->cells($merchant, $path);

        $this->assertStringContainsString('Plain Shopper', $cells,
            "{$path}: the shopper's name is no longer rendered at all");
        $this->assertMatchesRegularExpression('/<(a|button|form|i|span)\b/', $cells,
            "{$path}: the row's own controls were escaped away — the fix was applied to the "
            . 'concatenated string instead of to each shopper-written value');
    }

    /**
     * A STATIC RATCHET over both `rawColumns()` lists, covering the column somebody adds next.
     * Every entry must have a closure in its own file, so its escaping is a visible decision
     * rather than an inherited default.
     *
     * @dataProvider rawColumnTables
     */
    public function test_every_raw_column_has_a_closure_that_decides_its_escaping(string $table): void
    {
        $source = (string) file_get_contents(app_path('DataTables/' . $table . '.php'));
        $this->assertNotSame('', $source, "{$table}: source unreadable");

        $this->assertSame(
            1,
            preg_match('/->rawColumns\(\[(.*?)\]\)/s', $source, $m),
            "{$table}: no rawColumns() call found — if it was removed, remove this case too"
        );

        preg_match_all("/'([a-z_]+)'/", $m[1], $names);

        foreach ($names[1] as $column) {
            $this->assertMatchesRegularExpression(
                "/->(edit|add)Column\(\s*'" . preg_quote($column, '/') . "'/",
                $source,
                "{$table}: '{$column}' is in rawColumns() with no editColumn/addColumn closure, so "
                . 'the model value is emitted raw and unescaped. That is exactly how `email` '
                . 'shipped unescaped on the customer list.'
            );
        }
    }

    /** @return array<string,array{0:string}> */
    public static function rawColumnTables(): array
    {
        return [
            'AbandonCartDataTable' => ['AbandonCartDataTable'],
            'WishlistDataTable' => ['WishlistDataTable'],
        ];
    }
}
