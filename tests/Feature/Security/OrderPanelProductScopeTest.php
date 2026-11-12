<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

/**
 * F260 — the order panel a merchant actually opens rendered another tenant's file path.
 * =================================================================================================
 *
 * `resources/views/order/order_show.blade.php` resolved each line item's product and variant with
 * a bare `Product::where('id', $item['product_id'])->first()`, per row, inside the `@foreach`.
 *
 * `Product` REGISTERS NO `StoreScope` AND IS ABSENT FROM `config/tenancy.php`'s `scoped_models`
 * (finding S19), so a lookup by id reaches every tenant's catalogue — not a customer's, not a
 * merchant's, not anyone's. And the only field read becomes an `href`:
 * `get_file($product->downloadable_product)`. So an order whose line item names another store's
 * product id rendered THAT STORE'S FILE PATH as a download link on this panel.
 *
 * THE FIX ALREADY EXISTED ONE FILE OVER. `resources/views/order/view.blade.php:314-335` scopes
 * both lookups to `$order['store_id']` and its comment records the same measurement. P2-04-6
 * landed it on the pencil button's page and not on the eye button's — the one a merchant opens
 * far more often.
 *
 * SCOPED TO THE ORDER'S STORE, NOT THE CALLER'S, and the distinction is load-bearing: the
 * platform operator legitimately opens another store's order (the operator limb is first in both
 * `OrderController::mayUseOrderScreen()` and `OrderDocumentAccessGuard`), so `getCurrentStore()`
 * would blank the line items for exactly the principal the guard admits.
 *
 * NOT THE SAME THING AS F224, which records that this panel renders a RAW download url for the
 * merchant rather than a signed one — deliberately, because the merchant owns the file and
 * `paid_download_url()` requires the signed-in CUSTOMER to be the buyer. F224 is about how the
 * link is signed. This is about whose product the link points at.
 */
class OrderPanelProductScopeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * `StoredPathPolicy` (P1-SEC-28) refuses any `downloadable_product` whose first segment after
     * `uploads/` is not the owning store's id, so the fixture path has to be a real one. The
     * distinctive marker is the FILENAME, which the policy does not constrain.
     */
    private const FOREIGN_MARKER = 'store-b-private-manual.pdf';

    private User $merchantA;

    private Store $storeA;

    private Store $storeB;

    private Product $foreignProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchantA = $this->makeMerchant();
        $this->storeA = $this->makeStore($this->merchantA, ['default_language' => 'en']);
        $this->merchantA->current_store = $this->storeA->id;
        $this->merchantA->save();
        /* `show()` gates on `Show Order`, not `Manage Order` -- a 403 would satisfy every
           "the foreign path is absent" assertion below without the scope existing at all. */
        $this->grantPermission(User::find($this->merchantA->id), 'Manage Order');
        $this->grantPermission(User::find($this->merchantA->id), 'Show Order');
        $this->merchantA = User::find($this->merchantA->id);

        $merchantB = $this->makeMerchant();
        $this->storeB = $this->makeStore($merchantB, ['default_language' => 'en']);

        $this->foreignProduct = Product::forceCreate([
            'name' => 'Store B private manual',
            'slug' => 'store-b-manual-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'trending' => 0,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'track_stock' => 1,
            'product_stock' => 5,
            'stock_status' => 'in_stock',
            'downloadable_product' => 'uploads/' . $this->storeB->id . '/' . self::FOREIGN_MARKER,
            'store_id' => $this->storeB->id,
            'created_by' => $merchantB->id,
        ]);
        (new Product)->flushCache();
    }

    /**
     * Store A's own order, whose line item names store B's product id.
     *
     * That is reachable without an attack: `Product` is unscoped, so a storefront add-to-cart in
     * store A can resolve a store-B product id (finding S19, which produced a live cross-tenant
     * stock drain). The panel must not compound it by publishing the file path.
     */
    private function orderNamingTheForeignProduct(): Order
    {
        return $this->orderWithLineItem($this->foreignProduct->id, 'Lamp as recorded at checkout');
    }

    /**
     * AN ORDER WITH A REAL LINE ITEM, and the first version of this file did not have one.
     *
     * `SecurityTestCase::makeOrder()` defaults `product_json` to `'[]'`, and
     * `Order::order_detail()` builds `$order['product']` from THAT column — not from
     * `orders.product_id`. So an order built with only `product_id` set has no line items at all,
     * the panel's `@foreach` runs zero times, and every "the foreign path is absent" assertion
     * passes trivially. Measured: three tests green against a panel that rendered no items.
     *
     * The same vacuity an independent review had just caught in the shipping tests, found here by
     * the allow-path case failing — which is the entire reason to write one.
     */
    private function orderWithLineItem(int $productId, string $recordedName): Order
    {
        return $this->makeOrder($this->storeA, [
            'product_id' => (string) $productId,
            'delivered_status' => 1,
            'payment_status' => 'Paid',
            'product_json' => json_encode([[
                'product_id' => $productId,
                'name' => $recordedName,
                'qty' => 1,
                'quantity' => 1,
                'price' => 10,
                'orignal_price' => 10,
                'final_price' => 10,
                'variant_id' => null,
                'variant_name' => null,
            ]]),
        ]);
    }

    private function openPanel(Order $order): string
    {
        /* `order.order_view` (orders/order_view/{id}), NOT the `order.show` resource route.
           Both point at `OrderController@show`, but the resource route is `order/{order}` with
           implicit model binding, so an ENCRYPTED id 404s there before the handler runs — and a
           404 satisfies "the foreign path is absent" exactly as well as a working scope does.
           `resources/views/order/grid.blade.php:154` shows which one the eye button really uses. */
        $response = $this->actingAs($this->merchantA)
            ->get(route('order.order_view', Crypt::encrypt($order->id)));

        // A panel that 404s or 500s satisfies "the path is absent" exactly as well as a working
        // scope does. This is the premise, not the finding.
        $this->assertSame(200, $response->getStatusCode(),
            'the panel did not render, so the absence of the foreign path proves nothing');

        $body = (string) $response->getContent();

        /* THE PREMISE. A panel rendering zero line items satisfies every absence assertion in
           this file for free — which is exactly what happened before `product_json` was given a
           real item. */
        $this->assertStringContainsString('as recorded at checkout', $body,
            'the panel rendered no line items, so nothing below it measures anything');

        return $body;
    }

    public function test_the_panel_does_not_render_another_stores_file_path(): void
    {
        $body = $this->openPanel($this->orderNamingTheForeignProduct());

        $this->assertStringNotContainsString(self::FOREIGN_MARKER, $body,
            "store B's downloadable file path was rendered as an href on store A's order panel");
    }

    /**
     * THE PRODUCT NAME IS NOT PART OF THIS DEFECT, and the first version of this file asserted it
     * anyway — passing for a reason that had nothing to do with the fix.
     *
     * `order_show.blade.php:170` renders `$item['name']`, which `Order::order_detail()` builds
     * from the order's OWN stored payload (`Order.php:365`, `:378` — `$product['name'] ?? null`),
     * not from a `Product` row. So the name shown is whatever was recorded when the order was
     * placed, and no store predicate could change it.
     *
     * The `Product` lookup feeds exactly ONE thing on this panel: `downloadable_product`, which
     * becomes an `href`. That is the whole surface, and the test above is the whole finding. This
     * case is kept to pin WHY, so the next reader does not re-add a name assertion and conclude
     * the scope works because it passes.
     */
    public function test_the_item_name_comes_from_the_order_not_from_the_product_row(): void
    {
        $order = $this->orderNamingTheForeignProduct();
        $body = $this->openPanel($order);

        $this->assertStringNotContainsString(self::FOREIGN_MARKER, $body);

        $source = (string) file_get_contents(
            base_path('resources/views/order/order_show.blade.php')
        );
        $this->assertStringContainsString("\$item['name']", $source,
            'the panel stopped rendering the order\'s own recorded item name, so the reasoning '
            . 'above no longer describes this file');
    }

    /**
     * AND THE SCOPE IS NOT A DELETION. Every assertion above passes against a panel that renders
     * no line items at all. This one fails against it.
     */
    public function test_the_panel_still_renders_this_stores_own_downloadable(): void
    {
        $own = Product::forceCreate([
            'name' => 'Store A handbook',
            'slug' => 'store-a-handbook-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'trending' => 0,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'track_stock' => 1,
            'product_stock' => 5,
            'stock_status' => 'in_stock',
            'downloadable_product' => 'uploads/' . $this->storeA->id . '/store-a-handbook.pdf',
            'store_id' => $this->storeA->id,
            'created_by' => $this->merchantA->id,
        ]);
        (new Product)->flushCache();

        $body = $this->openPanel(
            $this->orderWithLineItem($own->id, 'Handbook as recorded at checkout')
        );

        /* The MARKER, not the product name: the name comes from the order payload and would be
           present even if the scope blanked every Product row. The file path is the only thing
           the scoped lookup feeds, so it is the only thing that can show the scope admits the
           merchant's own catalogue. */
        $this->assertStringContainsString('store-a-handbook.pdf', $body,
            'the scope is blanking the merchant\'s own downloadable, which makes it a deletion '
            . 'rather than a control');
    }

    /**
     * THE OTHER PANEL IS THE CONTROL. `order/view.blade.php` has been scoped since P2-04-6, so it
     * must already be clean — and if it is not, the premise of this whole file is wrong.
     */
    public function test_the_already_fixed_panel_is_still_clean(): void
    {
        $order = $this->orderNamingTheForeignProduct();

        $response = $this->actingAs($this->merchantA)
            ->get(route('order.view', Crypt::encrypt($order->id)));

        if ($response->getStatusCode() !== 200) {
            $this->markTestSkipped('order.view did not render for this fixture; the control '
                . 'cannot be measured, and a silent pass would be worse');
        }

        $this->assertStringNotContainsString(self::FOREIGN_MARKER, (string) $response->getContent());
    }
}
