<?php

namespace Tests\Feature\Merchant;

use App\Models\OrderNote;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F180 — EVERY STORED ORDER NOTE IS RENDERED, INCLUDING THE SHAPES THREE WRITERS PRODUCE.
 * =============================================================================================
 *
 * The order detail page used to branch on five exact `status`/`note_type` shapes with **no
 * `@else`**, so a note matching none of them rendered nothing. Three of the four `order_notes`
 * writers produce exactly such shapes:
 *
 *   `OrderController::store()`        `status => 'Order created'` — a LOWERCASE c, one character
 *                                     away from the `'Order Created'` the branch matched
 *   `Api\DashboardController`         the same lowercase shape, and
 *                                     `status => 'Customer note'` / `note_type => 'customer'`,
 *                                     which matched nothing at all
 *
 * So a note a merchant typed into the manual order form, and every note the admin API recorded,
 * was stored and never shown — the timeline silently missing entries. The original measurement
 * was **5 rendered blocks against 8 stored rows**, and this file is that measurement turned into
 * a test: all eight shapes on one order, and all eight have to appear.
 *
 * WHY NOT NORMALISE THE THREE WRITERS instead. It is the larger change, it alters what those
 * handlers store, and it cannot retrofit rows already written. The owner's condition for this
 * slice is compatibility with old records, and only the rendering fix satisfies it. No financial
 * data is touched: `timelineTone()` reads two columns and returns a CSS class.
 */
class OrderNoteVisibilityTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The eight shapes, with the three F180 ones last.
     *
     * Each `notes` string is unique and searched for individually, so a failure names the shape
     * that vanished rather than reporting a count that is merely wrong.
     */
    private const SHAPES = [
        ['status' => 'Stock Manage',        'note_type' => null,          'notes' => 'SS-F180 stock manage note'],
        ['status' => 'Order Created',       'note_type' => null,          'notes' => 'SS-F180 order created capital'],
        ['status' => 'Order status change', 'note_type' => null,          'notes' => 'SS-F180 order status change'],
        ['status' => '',                    'note_type' => 'private_note','notes' => 'SS-F180 private note'],
        ['status' => '',                    'note_type' => 'to_customer', 'notes' => 'SS-F180 note to customer'],
        // The three the page dropped. Each is written by a real handler.
        ['status' => 'Order created',       'note_type' => 'created',     'notes' => 'SS-F180 manual order form note'],
        ['status' => 'Order created',       'note_type' => 'system',      'notes' => 'SS-F180 admin api created note'],
        ['status' => 'Customer note',       'note_type' => 'customer',    'notes' => 'SS-F180 admin api customer note'],
    ];

    public function test_all_eight_stored_note_shapes_appear_on_the_page(): void
    {
        $body = $this->pageWithEveryNoteShape();

        foreach (self::SHAPES as $i => $shape) {
            $this->assertStringContainsString(
                $shape['notes'],
                $body,
                sprintf(
                    'the note stored as status=%s note_type=%s is not on the page. That is F180:'
                    . ' the merchant typed it or an API recorded it, the row exists, and the'
                    . ' timeline does not show it.',
                    var_export($shape['status'], true),
                    var_export($shape['note_type'], true)
                )
            );
        }
    }

    /**
     * THE COUNT, WHICH IS WHAT THE ORIGINAL MEASUREMENT WAS.
     *
     * `note-bg-color` is the base class on every note header, so counting it counts rendered note
     * blocks. Asserting the number as well as the strings catches a regression that renders a note
     * twice as readily as one that renders it not at all.
     */
    public function test_the_page_renders_one_block_per_stored_note(): void
    {
        $body = $this->pageWithEveryNoteShape();

        $this->assertSame(
            count(self::SHAPES),
            substr_count($body, 'note-bg-color'),
            'the page rendered a number of note blocks other than the eight rows stored. Five of'
            . ' eight was the F180 measurement; more than eight would mean a note is duplicated.'
        );
    }

    /**
     * AND THE MODEL CAN NO LONGER SAY "SKIP".
     *
     * The view's old `@if ($ssNoteTone)` is gone, but the guarantee is the return type: there is
     * no value `timelineTone()` can return that means a note is not rendered. A nullable return
     * would let the drop come back without touching the view.
     */
    public function test_the_tone_method_can_never_withhold_a_note(): void
    {
        $method = new \ReflectionMethod(OrderNote::class, 'timelineTone');

        $this->assertSame(
            'string',
            (string) $method->getReturnType(),
            'timelineTone() is nullable again, so a shape it does not recognise can be dropped by'
            . ' any caller that tests the result for null — which is how F180 happened'
        );

        foreach (self::SHAPES as $shape) {
            $note = new OrderNote();
            $note->status = $shape['status'];
            $note->note_type = $shape['note_type'];

            $this->assertIsString(
                $note->timelineTone(),
                'a stored shape produced a non-string tone'
            );
        }

        // An unrecognised shape renders with the base styling and no tint.
        $unknown = new OrderNote();
        $unknown->status = 'Something A Future Writer Invents';
        $unknown->note_type = 'brand-new';

        $this->assertSame(
            '',
            $unknown->timelineTone(),
            'an unrecognised shape must take the base note styling, not a tint that was never'
            . ' contrast-checked and not an empty render'
        );
    }

    /** The view must not reintroduce a conditional around the note block. */
    public function test_the_note_loop_has_no_conditional_around_it(): void
    {
        $source = file_get_contents(resource_path('views/order/view.blade.php'));

        $this->assertStringNotContainsString(
            '@if ($ssNoteTone)',
            $source,
            'the note loop guards on the tone again, so a shape with no tint is dropped — F180'
        );
    }

    private function pageWithEveryNoteShape(): string
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Show Order');
        $this->grantPermission($owner, 'Manage Order Note');

        $product = $this->makeProduct($store->id, $owner->id, 'F180 Scarf');
        $order = $this->orderNaming($store->id, $product);

        foreach (self::SHAPES as $shape) {
            OrderNote::forceCreate([
                'order_id' => $order->id,
                'customer_id' => null,
                'notes' => $shape['notes'],
                'note_type' => $shape['note_type'],
                'status' => $shape['status'],
                'store_id' => $store->id,
            ]);
        }

        $response = $this->actingAs($owner)->get('/order-view/' . Crypt::encrypt($order->id));
        $response->assertOk();

        return $response->getContent();
    }

    /** Copied from OrderDetailTest: the page loops over `product_json`. */
    private function orderNaming(int $storeId, Product $product, array $attributes = []): \App\Models\Order
    {
        return $this->makeOrder(Store::find($storeId), array_merge([
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
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }
}
