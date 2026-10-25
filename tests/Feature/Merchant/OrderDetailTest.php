<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-6 -- the order detail page's correctness defects, pinned BEFORE the design-system pass.
 * =============================================================================================
 *
 * FOUR THINGS THE PAGE GETS WRONG, all found by reading it while planning the restyle and none
 * of them visible to any test that existed. They are pinned first so the restyle has something
 * it cannot quietly break, and because three of them are wrong independently of how the page
 * looks.
 *
 * THE PAGE IS REACHED THROUGH `OrderDocumentAccessGuard`, which requires `Show Order`
 * (`OrderDocumentAccessGuard::PERMISSION`) and the order's `store_id` matching the caller's
 * tenant. `OrderController::order_view()` then checks `Show Order` again. Every fixture below
 * grants it, or the assertions would all hold against a redirect.
 */
class OrderDetailTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function viewUrl(int $orderId): string
    {
        return '/order-view/' . Crypt::encrypt($orderId);
    }

    /**
     * An order whose `product_json` names one product, which is what the items table loops over.
     * `product_price` is what `order_detail()` reports as the subtotal.
     */
    private function orderNaming(int $storeId, Product $product, array $attributes = []): Order
    {
        $store = \App\Models\Store::find($storeId);

        return $this->makeOrder($store, array_merge([
            'product_order_id' => 'ORD-' . uniqid(),
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'name' => $product->name,
                'qty' => 1,
                'price' => 10.0,
                'total_orignal_price' => 10.0,
                'final_price' => 10.0,
            ]]),
            'product_price' => 10.0,
            'product_id' => (string) $product->id,
        ], $attributes));
    }

    private function makeProduct(int $storeId, int $createdBy, string $name, array $attributes = []): Product
    {
        return Product::forceCreate(array_merge([
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
        ], $attributes));
    }

    /** The page, with the premise checks that stop every assertion below passing on a redirect. */
    private function detailHtml(\App\Models\User $user, int $orderId): string
    {
        $response = $this->actingAs($user)->get($this->viewUrl($orderId));

        $this->assertNotSame(404, $response->getStatusCode(), 'the order detail route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the order detail page must not 500');
        $this->assertNotSame(403, $response->getStatusCode(),
            'the guard refused this caller, so nothing below is about the page');
        $this->assertNotSame(302, $response->getStatusCode(),
            'the handler redirected -- usually a missing permission, and every "is absent" '
            . 'assertion below would pass against the redirect body');
        $response->assertOk();

        return (string) $response->getContent();
    }

    // =============================================================================================
    // 1. A note is not attributed to whoever is reading the page
    // =============================================================================================

    /**
     * EVERY NOTE CLAIMED THE VIEWER AS ITS AUTHOR.
     *
     * All five note branches ended `{{ $note->created_at->format(...) }}{{ __(' by ') }}{{
     * Auth::user()->name }}`. That is the name of the account rendering the page, not of whoever
     * wrote the note -- so the same note reads "by Amina" to Amina and "by Khalid" to Khalid.
     *
     * AND THERE IS NO HONEST NAME TO PRINT INSTEAD. `order_notes` has no author column: one
     * migration (`2024_01_26_100237_create_order_notes_table.php`), no later one, nothing in
     * `database/migrations/_gate_f_pending/`, nothing in any module. `customer_id` is a foreign
     * key to `customers`, i.e. the shopper, while these notes are written by staff. So the fix
     * is to stop claiming an author, and adding a `created_by` column is a migration and
     * therefore an owner decision rather than part of a design-system pass.
     *
     * DRIVEN WITH A SECOND STAFF MEMBER, so the failure is unambiguous: the note exists, someone
     * who certainly did not write it opens the page, and their name must not be beside it.
     */
    public function test_a_note_is_not_attributed_to_the_person_reading_the_page(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Show Order');
        $this->grantPermission($owner, 'Manage Order Note');

        $reader = $this->makeStaff($owner, ['name' => 'Zzz Readerson']);
        $this->grantPermission($reader, 'Show Order');
        $this->grantPermission($reader, 'Manage Order Note');

        $product = $this->makeProduct($store->id, $owner->id, 'Detail Scarf');
        $order = $this->orderNaming($store->id, $product);

        OrderNote::forceCreate([
            'order_id' => $order->id,
            'customer_id' => null,
            'notes' => 'A note nobody named Readerson wrote',
            'note_type' => 'private_note',
            'status' => '',
            'store_id' => $store->id,
        ]);

        $body = $this->detailHtml($reader, $order->id);

        // PREMISE: the note is on the page at all. Without this the assertion below is satisfied
        // by a notes panel that did not render -- which is exactly what happened in the dev
        // fixture for a different reason (the panel is gated on `Manage Order Note`).
        $this->assertStringContainsString('A note nobody named Readerson wrote', $body,
            'premise: the note rendered, so the attribution below was actually reachable');

        /*
         * SCOPED TO THE NOTES PANEL, and the first version of this was not. The layout's header
         * greets the signed-in user by name once per page, so asserting against the whole body
         * said "the viewer's name is on the page" -- true, correct, and nothing to do with the
         * notes. Measured: exactly one occurrence, from the header.
         */
        $panel = $this->notesPanel($body);

        $this->assertNotSame('', $panel, 'premise: the notes panel was found');
        $this->assertStringContainsString('A note nobody named Readerson wrote', $panel,
            'premise: the note is inside the panel this assertion looks at');

        $this->assertStringNotContainsString('Zzz Readerson', $panel,
            'the order note is attributed to the person READING the page. order_notes has no '
            . 'author column, so no name can honestly be shown here.');
    }

    /**
     * The order-notes panel, anchored on its own heading.
     *
     * Not the whole page: the layout greets the signed-in user by name in the header, so a
     * body-wide search for that name answers a different question entirely.
     */
    private function notesPanel(string $body): string
    {
        $start = strpos($body, 'Order Notes');

        if ($start === false) {
            return '';
        }

        // To the note-entry form, which closes the list.
        $end = strpos($body, 'choice_form', $start);

        return $end === false ? substr($body, $start) : substr($body, $start, $end - $start);
    }

    // =============================================================================================
    // 2. The items table renders as many cells as it has headers
    // =============================================================================================

    /**
     * A DELETED PRODUCT MUST NOT SHIFT THE TOTAL COLUMN.
     *
     * The header adds `<th>Downloadable Product</th>` when the order is Delivered. The body
     * added the matching `<td>` only when `($product || $variant)` was ALSO true. A delivered
     * order naming a product that has since been deleted therefore produced three cells against
     * four headers, and the Total slid underneath "Downloadable Product".
     *
     * The fixture deletes the product AFTER the order names it, which is the real sequence
     * rather than a contrived one -- an order keeps its `product_json` when a product goes.
     */
    public function test_a_deleted_product_does_not_shift_the_total_column(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Show Order');

        $product = $this->makeProduct($store->id, $owner->id, 'Soon Deleted Scarf');

        $order = $this->orderNaming($store->id, $product, [
            'delivered_status' => 1,   // Delivered -- the status that adds the fourth header
        ]);

        // The product goes; the order's own copy of it does not.
        DB::table('products')->where('id', $product->id)->delete();

        $body = $this->detailHtml($owner, $order->id);

        $table = $this->itemsTable($body);

        $this->assertNotSame('', $table, 'premise: the items table is on the page');

        $headers = preg_match_all('/<th\b/i', $table);
        $this->assertGreaterThan(0, $headers, 'premise: the items table has headers');

        preg_match_all('#<tbody.*?</tbody>#is', $table, $bodies);
        $this->assertNotEmpty($bodies[0], 'premise: the items table has a body');

        preg_match_all('#<tr\b.*?</tr>#is', $bodies[0][0], $rows);
        $this->assertNotEmpty($rows[0], 'premise: the items table has at least one row');

        foreach ($rows[0] as $i => $row) {
            $cells = preg_match_all('/<td\b/i', $row);
            $this->assertSame($headers, $cells,
                "items row {$i} has {$cells} cells against {$headers} headers, so every column "
                . 'after the gap is displayed under the wrong heading. A deleted product is the '
                . 'case: the header asks for a Downloadable Product column and the row omits it.');
        }
    }

    /** The items table, found by the heading the view gives it rather than by position. */
    private function itemsTable(string $body): string
    {
        $start = strpos($body, 'Items from Order');

        if ($start === false) {
            return '';
        }

        $tableStart = strpos($body, '<table', $start);
        $end = $tableStart === false ? false : strpos($body, '</table>', $tableStart);

        return $end === false ? '' : substr($body, $tableStart, $end - $tableStart);
    }

    // =============================================================================================
    // 3. The line-item lookup does not reach another tenant's catalogue
    // =============================================================================================

    /**
     * A FOREIGN PRODUCT'S DOWNLOAD IS NOT OFFERED.
     *
     * The loop did `Product::where('id', $item['product_id'])->first()` with no store predicate.
     * `Product` registers no `StoreScope` and is absent from `config/tenancy.php`'s
     * `scoped_models`, so that returned whichever product held the id. The only field read from
     * it is `downloadable_product`, which the view turns into a download link -- so an order
     * whose `product_json` names a foreign id offered the foreign store's file.
     *
     * `product_json` is written at checkout from the cart, and S19 established that a cart can
     * carry a foreign product id, so this is not purely theoretical.
     */
    public function test_a_foreign_products_download_is_not_offered(): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();

        $this->grantPermission($ownerA, 'Show Order');

        /*
         * THE PATH MUST BE STORE B'S OWN. `StoredPathPolicy::product()` runs on `Product::saving`
         * and refuses a `downloadable_product` whose first folder is not the product's store id
         * (or a sibling store with the same owner) -- so `uploads/beta-secret-file.zip` throws
         * `The file path is not accepted.` from the FIXTURE, before the page is ever requested.
         * That policy is a real containment and it is worth saying what it does and does not do:
         * it keeps store B's file under store B's folder, and it says nothing about whether
         * store A's order detail page is allowed to LINK to it.
         */
        $foreign = $this->makeProduct($storeB->id, $ownerB->id, 'Beta Secret Scarf', [
            'downloadable_product' => 'uploads/' . $storeB->id . '/beta-secret-file.zip',
        ]);

        // Store A's order names store B's product id.
        $order = $this->orderNaming($storeA->id, $foreign, ['delivered_status' => 1]);

        // PREMISE: the foreign product really holds that id and really has a file, so an
        // unfiltered lookup would return it.
        $this->assertSame(
            'uploads/' . $storeB->id . '/beta-secret-file.zip',
            DB::table('products')->where('id', $foreign->id)->value('downloadable_product'),
            'premise: the collision is set up'
        );

        $body = $this->detailHtml($ownerA, $order->id);

        $this->assertStringNotContainsString('beta-secret-file.zip', $body,
            "the order detail page offered another tenant's downloadable file because this "
            . "store's order named its product id -- the lookup must carry a store predicate, "
            . 'since Product has no scope of its own');
    }

    // =============================================================================================
    // 4. Links that open a new window cannot reach back
    // =============================================================================================

    /**
     * EVERY `target="_blank"` CARRIES `rel="noopener"`.
     *
     * There were four and none had a `rel` at all. A page opened this way gets a handle on the
     * opener through `window.opener` and can navigate it; the WhatsApp links also leak the
     * admin url as a referrer.
     *
     * ASSERTED ON THE RENDERED HTML, not on the file, so a link added through a component or a
     * stack is counted too, and COUNTED rather than merely "at least one", so a new bare
     * `_blank` cannot slip in beside the fixed ones.
     */
    public function test_every_new_window_link_is_opener_safe(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Show Order');

        $product = $this->makeProduct($store->id, $owner->id, 'Detail Scarf');
        $order = $this->orderNaming($store->id, $product);

        $body = $this->detailHtml($owner, $order->id);

        $blank = preg_match_all('/target=("|\')_blank\1/i', $body);

        // PREMISE: there are such links on this page. If the page stops opening new windows
        // entirely this test should be deleted, not left passing against zero.
        $this->assertGreaterThan(0, $blank,
            'premise: the order detail page opens at least one new window');

        $safe = preg_match_all('/rel=("|\')[^"\']*noopener[^"\']*\1/i', $body);

        $this->assertSame($blank, $safe,
            "{$blank} links open a new window and {$safe} carry rel=noopener. A page opened "
            . 'from one of the others can navigate this one through window.opener.');
    }
}
