<?php

namespace Tests\Feature\Merchant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F19 / P2-04-4 -- the order list does not invent a date it does not have.
 * =============================================================================================
 *
 * THE DEFECT. `OrderDataTable`'s status column renders
 * `__('Delivered') . ' : ' . Utility::dateFormat($item->delivery_date)`, and `dateFormat()` is
 *
 *     return date($settings, strtotime($date));
 *
 * `strtotime(null)` and `strtotime('')` both return FALSE, and `date($fmt, false)` is
 * `date($fmt, 0)` -- the Unix epoch. So an order marked Delivered whose `delivery_date` was never
 * written shows **"Delivered : Jan 1, 1970"**: a fabricated date presented exactly like a real
 * one, in the same type and colour as the eight rows around it that are real. That is worse than
 * a blank, because a merchant reading it has no way to tell which of the two they are looking at.
 * Seen in the live screenshots of the order list, which is how it was found.
 *
 * WHY THE FIX IS LOCAL AND NOT IN `Utility::dateFormat()`. The null-date behaviour is wrong
 * everywhere, but that helper has **64 call sites, 28 of them inside vendored
 * `packages/workdo/` modules** (measured by grep). Changing it alters output on screens across
 * seventy modules with no regression evidence available for any of them, which is a sweep with
 * an owner decision behind it -- not a line in a list-redesign commit. The finding stays recorded;
 * the surface P2-04 owns is fixed here.
 *
 * WHAT THIS ASSERTS, AND WHY IT IS THE AJAX LEG. The HTML leg returns a scaffold with no rows in
 * it, so the rendered status cell exists only in the DataTables JSON envelope. yajra picks its
 * leg on `request()->ajax() && request()->wantsJson()`, so both headers are set -- with only one
 * of them the drive silently takes the HTML branch and every assertion below would be reading an
 * empty table.
 *
 * AND IT ASSERTS THE RENDERED CELLS, NOT THE WHOLE PAYLOAD. A DataTables row carries every model
 * attribute, appended accessors included -- the first version of this file asserted on the
 * encoded row and failed on `return_date`, which is NOT stored data but
 * `Order::getReturnDateAttribute()`, `date('Y-m-d', strtotime($this->order_date))`: the same
 * epoch, from the same cause, in an accessor that `$appends` puts on EVERY order serialisation
 * including the Flutter API's. That one is recorded as a finding and deliberately NOT changed
 * here, because `return_date` going from `"1970-01-01"` to null is a public-response change on a
 * path the shipped app reads, and the case needs an `order_date` that no production create path
 * leaves null. The claim this file makes is about what the merchant READS in the list, so it
 * reads the two cells the list renders.
 */
class OrderStatusDateTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The two headers yajra's render() requires together before it will serve JSON. */
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

    /**
     * The two CELLS the list renders a date into, concatenated, out of the real AJAX leg -- plus
     * the order identifier so each case can assert its own row is the one being read.
     *
     * Only `delivered_status` and `order_date` are returned. Those are the two `editColumn()`
     * closures that format a date; every other key in the row is a raw attribute or an appended
     * accessor, and asserting across those measures the model rather than the list.
     */
    private function cells($merchant): string
    {
        $response = $this->actingAs($merchant)
            ->withHeaders(self::AJAX)
            ->getJson('/order?' . http_build_query($this->dataTableQuery(['order_id', 'price'])));

        $this->assertNotSame(404, $response->getStatusCode(), 'the order route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the order list must not 500');

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('draw', $payload, 'premise: yajra served the AJAX leg');
        $this->assertNotSame(0, (int) ($payload['recordsTotal'] ?? 0),
            'premise: the table returned a row, or "no epoch date" holds for free');

        $out = '';

        foreach ($payload['data'] ?? [] as $row) {
            // PREMISE per row: the two cells exist. A renamed column would otherwise make every
            // "does not contain 1970" assertion below pass against an empty string.
            $this->assertArrayHasKey('delivered_status', $row, 'premise: the status cell is rendered');
            $this->assertArrayHasKey('order_date', $row, 'premise: the date cell is rendered');

            $out .= ($row['product_order_id'] ?? '')
                . ' | ' . $row['delivered_status']
                . ' | ' . $row['order_date'];
        }

        return $out;
    }

    /**
     * THE DEFECT ITSELF. An order marked Delivered with no `delivery_date`.
     *
     * The assertion is on `1970`, not on the formatted string, because `site_date_format` is a
     * setting -- the epoch renders as "Jan 1, 1970", "01-01-1970" or "1970-01-01" depending on
     * it, and the year is the one part every format carries.
     */
    public function test_a_delivered_order_with_no_delivery_date_shows_no_epoch_date(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $this->makeOrder($store, [
            'product_order_id' => 'ORD-NODATE-1',
            'delivered_status' => 1,          // Delivered
            'delivery_date' => null,          // ... and nothing ever wrote the date
        ]);

        $body = $this->cells($merchant);

        // PREMISE: the row really is in the payload, so what follows is about its status cell.
        $this->assertStringContainsString('ORD-NODATE-1', $body, 'premise: the order is listed');

        $this->assertStringNotContainsString('1970', $body,
            'the order list rendered the Unix epoch as a delivery date -- a fabricated date '
            . 'presented exactly like a real one (F19)');
    }

    /**
     * AND THE LABEL SURVIVES. Suppressing the date must not suppress the STATUS: a merchant still
     * needs to know the order is marked Delivered. A fix that blanked the whole cell would pass
     * the test above and lose the information the column exists for.
     */
    public function test_the_status_label_is_still_shown_when_the_date_is_missing(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $this->makeOrder($store, [
            'product_order_id' => 'ORD-NODATE-2',
            'delivered_status' => 1,
            'delivery_date' => null,
        ]);

        $body = $this->cells($merchant);

        $this->assertStringContainsString('Delivered', $body,
            'the status label was lost along with the date');
    }

    /**
     * A REAL DATE IS STILL RENDERED, so the fix did not simply stop showing dates. This is the
     * case that makes the two above mean something -- without it, `return ''` would satisfy both.
     */
    public function test_a_real_delivery_date_is_still_rendered(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $this->makeOrder($store, [
            'product_order_id' => 'ORD-WITHDATE-1',
            'delivered_status' => 1,
            'delivery_date' => '2026-03-17',
        ]);

        $body = $this->cells($merchant);

        $this->assertStringContainsString('ORD-WITHDATE-1', $body, 'premise: the order is listed');
        $this->assertStringContainsString('2026', $body,
            'a real delivery date is no longer shown -- the fix went too far');
        $this->assertStringNotContainsString('1970', $body);
    }

    /**
     * THE PENDING CASE, which is the common one and uses a DIFFERENT column.
     *
     * Status 0 renders `order_date`, not a fulfilment date. `orders.order_date` is written by
     * every create path there is, so this is less likely to be null -- but the column is
     * nullable, and the same epoch would appear. Driven because "the status column never shows
     * 1970" is the claim, not "status 1 does not".
     */
    public function test_a_pending_order_with_no_order_date_shows_no_epoch_date(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $order = $this->makeOrder($store, ['product_order_id' => 'ORD-NODATE-3']);

        /*
         * `order_date` is set by the fixture (and by `Order::create`'s own defaults), so it is
         * cleared afterwards. Raw, then the model cache is flushed: `Order` is `Cachable`, and an
         * Eloquent read after a raw write serves the PRE-WRITE row -- a measured false reading in
         * this project twice over.
         */
        \DB::table('orders')->where('id', $order->id)->update(['order_date' => null]);
        (new \App\Models\Order)->flushCache();

        // PREMISE: the column really is null now, or this measures nothing.
        $this->assertNull(\DB::table('orders')->where('id', $order->id)->value('order_date'),
            'premise: order_date must actually be null');

        $body = $this->cells($merchant);

        $this->assertStringContainsString('ORD-NODATE-3', $body, 'premise: the order is listed');
        $this->assertStringNotContainsString('1970', $body,
            'a pending order with no order_date rendered the Unix epoch');
    }
}
