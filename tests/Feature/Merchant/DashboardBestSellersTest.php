<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-02-9 -- the dashboard's best-selling block, with rows in it.
 * =============================================================================================
 *
 * THE BLOCK WAS BUILT AND HAD NEVER BEEN SEEN WITH DATA. It renders, it is store-scoped, it is
 * gated on `Manage Products`, it has an empty state and an honest footer -- and every screenshot
 * and browser check of it showed the EMPTY state, because the development fixture left
 * `orders.product_id` at its default of `'0'`. Nothing matched, the card looked perfectly
 * healthy, and no test in the repository mentioned `topSellingProducts` at all. Found by driving
 * the dashboard and reading the card rather than counting tables.
 *
 * `orders.product_id` IS WHAT THE BLOCK READS, not `product_json`. `HomeController` plucks that
 * column, splits each value on commas, counts the ids and takes the top five. A fixture that
 * fills `product_json` -- which is the field that looks like the right one -- produces an empty
 * block.
 *
 * AND THE INTERSECTION IS THE TENANT CONTROL. The ids come from `(clone $orderQuery)`, which is
 * store-scoped, and are then intersected with `(clone $productQuery)`, which is store-scoped
 * too. That second half is load-bearing: `Product` is not in `config/tenancy.php`'s
 * `scoped_models` and registers no `StoreScope`, so `Product::whereIn('id', $ids)` on its own
 * would reach every tenant's catalogue. A foreign order id cannot get in through the first
 * half, but an id that merely COLLIDES with a foreign product's id could -- which is the case
 * below.
 *
 * THE PERMISSION IS `Manage Products`, PLURAL. The order permission next to it in the same view
 * is `Manage Order`, SINGULAR, and the two spellings are not a style choice -- they are the names
 * the rows actually carry (`database/seed-p2dev.php`, `CompanyMenuListener`). Granting
 * `Manage Product` in a fixture creates a brand-new permission row that no code reads, so the
 * block never renders and every "is absent" assertion below passes for free. That happened on the
 * first run of this file and the premise assertion in each case is what caught it.
 */
class DashboardBestSellersTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * JUST THE BEST-SELLING TABLE, not the whole page.
     *
     * The dashboard renders OTHER product blocks -- latest products, low stock -- so asserting
     * "this product is absent" against the full body is wrong about the page even when it is
     * right about the block. Measured: the first version of this file failed on a product that
     * appears in no order and is listed under "latest products" a few hundred lines away.
     *
     * Anchored on the table's own CAPTION, which is unique to it and carries no apostrophe to
     * be escaped. If the caption is ever reworded this returns '' and every assertion below
     * fails loudly rather than passing against an empty string -- which the premise check in
     * each case is there to catch.
     */
    private function bestSellingTable(string $body): string
    {
        $start = strpos($body, 'Products appearing most often');

        if ($start === false) {
            return '';
        }

        $end = strpos($body, '</table>', $start);

        return $end === false ? '' : substr($body, $start, $end - $start);
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

    public function test_the_block_lists_the_products_the_orders_actually_contain(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $this->grantPermission($merchant, 'Manage Order');

        $popular = $this->makeProduct($store->id, $merchant->id, 'Popular Scarf');
        $quiet = $this->makeProduct($store->id, $merchant->id, 'Quiet Scarf');
        $unsold = $this->makeProduct($store->id, $merchant->id, 'Unsold Scarf');

        // `product_id` is a COMMA-SEPARATED string, which is what the controller splits.
        $this->makeOrder($store, ['product_order_id' => 'ORD-A', 'product_id' => (string) $popular->id]);
        $this->makeOrder($store, [
            'product_order_id' => 'ORD-B',
            'product_id' => $popular->id . ',' . $quiet->id,
        ]);

        $response = $this->actingAs($merchant)->get('/dashboard');

        $this->assertNotSame(404, $response->getStatusCode(), 'the dashboard must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the dashboard must not 500');
        $response->assertOk();

        $table = $this->bestSellingTable((string) $response->getContent());

        // PREMISE: the table was found at all. An empty string here would satisfy the
        // "is absent" assertion below for free.
        $this->assertNotSame('', $table,
            'the best-selling table is not on the page, or its caption was reworded');

        $this->assertStringContainsString('Popular Scarf', $table,
            'the best-selling block is empty even though two orders contain this product -- '
            . 'which is exactly how it shipped, because the fixture filled product_json '
            . 'instead of product_id');
        $this->assertStringContainsString('Quiet Scarf', $table);

        /*
         * PREMISE AND POINT IN ONE: a product no order contains is absent FROM THIS TABLE.
         * Without it the assertions above would pass against a block that simply listed every
         * product, which is a different feature wearing the same heading. Scoped to the table,
         * because the dashboard has other product blocks and this product legitimately appears
         * in one of them.
         */
        $this->assertStringNotContainsString('Unsold Scarf', $table,
            'the block listed a product that appears in no order, so it is not a best-seller '
            . 'list at all');
    }

    /**
     * ANOTHER TENANT'S PRODUCT DOES NOT APPEAR, even when its id is one this store's orders
     * name.
     *
     * The ids come from store-scoped orders, so a FOREIGN order cannot contribute one. The
     * hazard is the second half: `Product` carries no scope, so intersecting those ids with an
     * unscoped product query would admit whatever product happens to hold that id. Here store
     * B's product is given the id store A's order names.
     */
    public function test_a_foreign_product_sharing_an_id_cannot_appear(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');
        $this->grantPermission($merchantA, 'Manage Order');

        $foreign = $this->makeProduct($storeB->id, $merchantB->id, 'Beta Secret Scarf');

        // Store A's order names an id that belongs to store B's product.
        $this->makeOrder($storeA, [
            'product_order_id' => 'ORD-COLLIDE',
            'product_id' => (string) $foreign->id,
        ]);

        // PREMISE: the foreign product really holds that id, and `Product` really is unscoped,
        // so an unfiltered `whereIn` would return it.
        $this->assertSame(
            $foreign->id,
            (int) \DB::table('products')->where('name', 'Beta Secret Scarf')->value('id'),
            'premise: the collision is set up'
        );

        $body = (string) $this->actingAs($merchantA)->get('/dashboard')->getContent();

        // The WHOLE page here, deliberately: another tenant's product must not appear anywhere
        // on it, not merely outside one table.
        $this->assertStringNotContainsString('Beta Secret Scarf', $body,
            "the dashboard listed another tenant's product because an order of this store "
            . 'named its id -- the product query must be store-scoped, not just the order one');
    }

    /**
     * A STAFF MEMBER WITHOUT `Manage Products` DOES NOT GET THE BLOCK.
     *
     * `/dashboard` carries no permission middleware, so the two blocks are gated separately in
     * the view. Driven with `Manage Order` granted, so this measures the PRODUCT permission
     * rather than "a staff member with nothing sees nothing".
     */
    public function test_a_staff_member_without_the_product_permission_does_not_get_the_block(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Order');

        $product = $this->makeProduct($store->id, $owner->id, 'Sensitive Scarf');
        $this->makeOrder($store, [
            'product_order_id' => 'ORD-S',
            'product_id' => (string) $product->id,
        ]);

        // PREMISE: the owner DOES get the block, so the absence below is the permission and
        // not the data.
        $this->grantPermission($owner, 'Manage Products');

        $this->assertStringContainsString('Sensitive Scarf',
            $this->bestSellingTable((string) $this->actingAs($owner)->get('/dashboard')->getContent()),
            'premise: the block renders for a caller who holds the permission');

        // Scoped to the table again: the staff member may hold OTHER product-adjacent
        // permissions in future, and this case is about this block.
        $this->assertSame('',
            $this->bestSellingTable((string) $this->actingAs($staff)->get('/dashboard')->getContent()),
            'a staff member holding no product permission was shown the best-selling products');
    }

    /**
     * With no orders at all the block renders ITS OWN empty state rather than 500ing.
     *
     * `assertSee('ss-empty')` alone would be vacuous here: the Latest-orders card beside it
     * emits the same class, and so do other cards on the page. So this asserts the card is on
     * the page, that its own empty title is, and that the table is not -- three facts that
     * together can only hold for this block.
     */
    public function test_with_no_orders_the_block_renders_its_empty_state(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $this->makeProduct($store->id, $merchant->id, 'Unsold Scarf');

        $response = $this->actingAs($merchant)->get('/dashboard');

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringContainsString('Best selling products', $body,
            'premise: the card itself is on the page, so the empty state below is the block\'s');
        $this->assertStringContainsString('No sales yet', $body,
            'the block has no orders to rank and did not say so');
        $this->assertSame('', $this->bestSellingTable($body),
            'the block rendered a table of best-sellers with no orders in the store at all');
    }
}
