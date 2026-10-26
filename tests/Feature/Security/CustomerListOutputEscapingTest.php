<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F98 — the customer list printed shopper-chosen strings into the merchant's admin origin, raw.
 * =============================================================================================
 *
 * `CustomerDataTable::rawColumns()` lists `first_name` and `email`, so yajra does NOT escape
 * them. The `first_name` cell is built by string concatenation —
 * `'…">' . $customer->first_name . ' ' . $customer->last_name . '</span><br></a>' . $customer->mobile`
 * — and `email` has no `editColumn` at all, so the raw model value is emitted.
 *
 * EVERY ONE OF THOSE FOUR VALUES IS WRITTEN BY A SHOPPER. `CustomerLoginController::register`
 * validates `first_name` as exactly `['required','string','max:255']` and nothing more, so a
 * shopper of this merchant's own store chooses the string that this cell prints into the
 * merchant's admin page.
 *
 * THE XSS MIDDLEWARE DOES NOT COVER IT, and that is the part worth knowing rather than assuming.
 * `app/Http/Middleware/XSS.php` runs `htmlspecialchars_decode()` and THEN strips only the four
 * strings `&lt;`, `&gt;`, `javascript` and `alert`. The decode turns an encoded `&lt;` into a raw
 * `<` before the strip looks for `&lt;`, and a raw `<` was never a target — so
 * `<img src=x onerror=…>` passes through whole as long as it avoids two words. A denylist of four
 * strings is not an escaping layer and nothing downstream supplies one.
 *
 * THIS IS THE SAME DEFECT, IN THE SAME SHAPE, THAT `OrderDataTable` ALREADY FIXED.
 * `OrderDataTable:63-90` carries the fix and the reasoning in full — `e()` on both shopper-written
 * values, with `<br>` left raw because it is that file's own markup. `CustomerDataTable` had **no
 * escaping call of any kind**. So this is not a new class of defect; it is the one surface the
 * earlier sweep did not reach.
 *
 * THE PAYLOAD AVOIDS `javascript` AND `alert` ON PURPOSE. A payload containing either would be
 * mangled by the middleware's denylist and the test would pass for the wrong reason — it would be
 * measuring the denylist, not the escaping. `onerror=1` is the shape that gets through.
 */
class CustomerListOutputEscapingTest extends SecurityTestCase
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

    /** @return array{0:\App\Models\User,1:\App\Models\Customer} */
    private function arrange(array $overrides = []): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Customer');

        $customer = Customer::forceCreate(array_merge([
            'first_name' => 'Plain',
            'last_name' => 'Shopper',
            'email' => 'plain-' . uniqid() . '@example.test',
            'mobile' => '555000111',
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id,
            'status' => 1,
            'register_type' => 'email',
            'regiester_date' => now(),
        ], $overrides));

        return [$merchant, $customer];
    }

    private function listPayload(\App\Models\User $merchant): array
    {
        $response = $this->actingAs($merchant)
            ->withHeaders(self::AJAX)
            ->getJson('/customer?' . http_build_query(
                $this->dataTableQuery(['first_name', 'email', 'orders', 'total_spend'])
            ));

        $response->assertOk();
        $payload = $response->json();

        // PREMISE. The HTML branch returns a view, not this envelope, so without `draw` the drive
        // reached the wrong leg and every assertion below would be measuring nothing.
        $this->assertArrayHasKey('draw', $payload, 'yajra served the HTML leg, not the AJAX leg');
        $this->assertArrayHasKey('data', $payload);
        $this->assertSame(1, (int) $payload['recordsTotal'],
            'premise: the merchant must actually see their own customer row');

        return $payload;
    }

    /**
     * The row's cell values as the browser will receive them — DECODED, not the json_encode blob.
     *
     * Asserting against `json_encode($payload)` cost a real false reading: that function escapes
     * `"` as `\"`, so a needle of `<a class="text-primary"` never matched a cell that really did
     * contain the anchor, and the control case failed against correct output. The cells are what
     * the DataTable renders, so the cells are what this file measures.
     */
    private function cells(array $payload): string
    {
        $row = $payload['data'][0] ?? [];

        return implode("\n", array_map(
            static fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v),
            $row
        ));
    }

    /**
     * The four shopper-written fields, one case each, because they reach the cell by three
     * different routes: concatenation (`first_name`, `last_name`, `mobile`) and a bare
     * `rawColumns` entry with no `editColumn` at all (`email`).
     *
     * @return array<string,array{0:string}>
     */
    public static function shopperWrittenFields(): array
    {
        return [
            'first_name' => ['first_name'],
            'last_name' => ['last_name'],
            'mobile' => ['mobile'],
            'email' => ['email'],
        ];
    }

    /**
     * @dataProvider shopperWrittenFields
     */
    public function test_a_shopper_chosen_string_is_escaped_before_it_reaches_the_merchants_page(
        string $field
    ): void {
        // `email` must stay a plausible address or the fixture is rejected elsewhere; the payload
        // rides in the local part, which is where a shopper's own input goes.
        $value = $field === 'email'
            ? self::PAYLOAD . '@example.test'
            : self::PAYLOAD;

        [$merchant] = $this->arrange([$field => $value]);

        $cells = $this->cells($this->listPayload($merchant));

        // BOTH HALVES ARE ASSERTED. Absence of the raw tag is the security property; presence of
        // the escaped form is what stops that absence passing for the wrong reason, which it would
        // if the column simply stopped rendering the value.
        $this->assertStringNotContainsString(
            '<img',
            $cells,
            "{$field}: a shopper-written `<img` tag reached the merchant's page unescaped. "
            . 'See OrderDataTable:63-90 for the same fix in the same shape.'
        );
        $this->assertStringContainsString(
            '&lt;img',
            $cells,
            "{$field}: the escaped form is absent too, so the value is not being rendered at all "
            . 'and the assertion above passed for the wrong reason.'
        );
    }

    /**
     * The merchant's own markup must survive. Escaping a whole cell with `e()` applied to the
     * concatenated string — rather than to each value — would double-escape the `<a>` and `<br>`
     * this file writes itself, and the list would render visible tag soup. That is the obvious
     * wrong fix, so it is pinned.
     */
    public function test_the_tables_own_markup_is_still_rendered_as_markup(): void
    {
        [$merchant] = $this->arrange();

        $cells = $this->cells($this->listPayload($merchant));

        $this->assertStringContainsString('<a class="text-primary"',
            $cells, 'the cell\'s own anchor was escaped away — the fix was applied to the whole '
            . 'concatenated string instead of to each shopper-written value');
        $this->assertStringContainsString('Plain Shopper', $cells, 'the name is no longer rendered');
    }

    /**
     * A STATIC RATCHET over `rawColumns()`. The driven cases above cover the four fields that
     * exist today; this covers the next one somebody adds. Every entry in `rawColumns()` must
     * either be a column whose closure is in this file (so its escaping is visible) or be absent
     * — a bare entry with no `editColumn`, which is what `email` was, emits the model value raw.
     */
    public function test_every_raw_column_has_a_closure_that_decides_its_escaping(): void
    {
        $source = file_get_contents(app_path('DataTables/CustomerDataTable.php'));
        $this->assertNotFalse($source);

        preg_match('/->rawColumns\(\[(.*?)\]\)/s', $source, $m);
        $this->assertNotEmpty($m, 'premise: rawColumns() was not found at all');

        preg_match_all("/'([a-z_]+)'/", $m[1], $cols);
        $rawColumns = $cols[1];
        $this->assertNotEmpty($rawColumns, 'premise: no raw column names parsed');

        $unhandled = [];
        foreach ($rawColumns as $column) {
            $hasClosure = str_contains($source, "editColumn('{$column}'")
                || str_contains($source, "addColumn('{$column}'");

            if (! $hasClosure) {
                $unhandled[] = $column;
            }
        }

        $this->assertSame([], $unhandled,
            "These columns are in `rawColumns()` with no closure, so yajra emits the model value "
            . "unescaped. If the column holds shopper-written text that is an XSS; if it holds "
            . "server-computed text it does not belong in `rawColumns()` at all:\n  "
            . implode("\n  ", $unhandled));
    }
}
