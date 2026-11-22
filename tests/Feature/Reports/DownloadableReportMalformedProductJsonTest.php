<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ONE ORDER WITH NO `product_json` ERRORS THE WHOLE DOWNLOADABLE REPORT.
 * =============================================================================================
 *
 * Found 2026-11-22 while building the period filter for this screen: the fixture could not draw a
 * single row.
 *
 * `SalesDownloadableProductDataTable` formats two of its columns by
 * `foreach (json_decode($order->product_json, true) as $product)`. `orders.product_json` is
 * NULLABLE, and `json_decode(null, true)` is `null`, so `foreach` is handed null and throws
 * `foreach() argument must be of type array|object, null given`.
 *
 * THE BLAST RADIUS IS THE WHOLE TABLE, NOT THE ROW. yajra formats inside the draw, so the
 * exception is caught at the top and the response becomes
 * `{"recordsTotal":2,"recordsFiltered":0,"data":[],"error":"..."}` — every row gone, a count that
 * contradicts the empty list, and a raw PHP message where the merchant's orders should be. One
 * malformed order hides all the healthy ones.
 *
 * BOTH FORMATTERS HAVE IT, and the author clearly anticipated the neighbouring case: a
 * `if (! is_array($product)) continue;` already guards a malformed ELEMENT inside the loop. What
 * is missing is the guard on the array itself.
 *
 * A row with no products is not an error state, so it formats as empty rather than being dropped:
 * the order exists and the merchant should see it.
 */
class DownloadableReportMalformedProductJsonTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/order-downloadable-reports';

    private const AJAX = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ];

    private function merchantWhoMayRead(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order Reports');
        Cache::forget('admin_details');

        return [$merchant, $store];
    }

    private function order($store, string $ref, ?string $productJson): void
    {
        DB::table('orders')->insert([
            'product_order_id' => $ref,
            'store_id' => $store->id,
            'customer_id' => 0,
            'is_guest' => 1,
            'delivered_status' => 0,
            'final_price' => 10.00,
            'product_json' => $productJson,
            'order_date' => '2026-02-10 10:00:00',
            'created_at' => '2026-02-10 10:00:00',
            'updated_at' => '2026-02-10 10:00:00',
        ]);
    }

    private function draw($merchant): string
    {
        $query = ['draw' => 1, 'start' => 0, 'length' => 50];

        foreach (['order_id', 'product_name', 'customer', 'attachment', 'created_at'] as $i => $name) {
            $query["columns[{$i}][data]"] = $name;
            $query["columns[{$i}][name]"] = $name;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'desc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        $response = $this->actingAs($merchant->fresh())
            ->withHeaders(self::AJAX)
            ->get(self::URI . '?' . http_build_query($query));

        $response->assertOk();

        return (string) $response->getContent();
    }

    /**
     * @dataProvider malformedJson
     */
    public function test_an_order_without_usable_product_json_does_not_error_the_table(string $case, ?string $json): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $this->order($store, 'DL-HEALTHY', '[]');
        $this->order($store, 'DL-MALFORMED', $json);

        $rows = $this->draw($merchant);

        $this->assertStringNotContainsString('"error"', $rows,
            "An order whose product_json is {$case} errored the draw, so one malformed row "
            . "replaces every healthy one with a raw PHP message.");

        $this->assertStringContainsString('DL-HEALTHY', $rows,
            "A healthy order disappeared because another order's product_json is {$case}.");

        $this->assertStringContainsString('DL-MALFORMED', $rows,
            'The order with no usable product list was dropped from the table. It exists and the '
            . 'merchant should see it; it simply has nothing to list.');
    }

    /** @return array<string, array{string, ?string}> */
    public static function malformedJson(): array
    {
        return [
            'null' => ['null', null],
            'empty string' => ['an empty string', ''],
            'not json' => ['not JSON at all', 'not json'],
            'a json scalar' => ['a JSON scalar', '42'],
        ];
    }
}
