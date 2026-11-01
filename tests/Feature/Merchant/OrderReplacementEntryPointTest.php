<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderReplacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * E13 / D-32 — the replacement operation existed and no merchant could reach it.
 * =================================================================================================
 *
 * `OrderReplacement::cancelAndReplace()`, `OrderController::order_replace()`, the
 * `POST order-replace/{id}` route, the `replaced_order_id` column and `OrderReplacementTest` all
 * shipped. **No view rendered a trigger for any of it** — a grep for `order.replace` and
 * `order-replace` across `resources/views/` and `packages/workdo/` returned nothing.
 *
 * So the approved D-32 capability was complete in the engine and unreachable in the product. That
 * is the gap this closes, and it is the whole gap: the service is not rebuilt, re-wrapped or
 * duplicated.
 *
 * ------------------------------------------------------------------------------------------------
 * THE CONTROL IS GATED ON THE SAME QUESTION THE SERVICE ASKS, and that is the point rather than a
 * detail. `cancelAndReplace()` refuses with `REFUSED_NOT_AUTHORISED` unless
 * `Gate::allows('updateStatus', $order)` (OrderReplacement.php:100-102), which `OrderPolicy`
 * answers with `Manage Order`. The view asks the identical question, so the button is offered
 * exactly when the operation could proceed. Gating it on anything else would either hide a
 * permitted action or offer one that always refuses — and this project treats a control that
 * cannot work as a defect, not as harmless decoration.
 *
 * `Gate::allows(...)` AND NOT `@can(...)`: CLAUDE.md records that `permissions_as_gates => false`,
 * so the Blade directive is a trap in this codebase. `updateStatus` is a POLICY method and would
 * in fact resolve — but the explicit call mirrors the service line for line and cannot be misread
 * by the next person as the permission-name form that is always false here.
 *
 * THE OTHER THREE CONDITIONS MIRROR THE SERVICE'S OWN REFUSALS, so the merchant is not offered a
 * control that is certain to be declined:
 *   - the column must exist          (`REFUSED_COLUMN_ABSENT`)
 *   - the order must not be dispatched (`REFUSED_ALREADY_DISPATCHED`, `delivered_status !== 0`)
 *   - it must not already be replaced  (`REFUSED_ALREADY_REPLACED`)
 *
 * The service still re-checks every one of them, including inside its transaction — the view is an
 * affordance, never the control. `OrderReplacementTest` and
 * `verify-order-replacement-concurrency-on-mysql.php` own that half and are untouched here.
 *
 * WHICH LIMBS THIS FILE CAN ACTUALLY BITE, measured by mutation rather than assumed:
 *   permission        YES -- removing it fails `..._without_manage_order_...`
 *   dispatched        YES -- removing it fails `..._a_dispatched_order_...`
 *   already replaced  YES, but ONLY via `..._reopened_...`. Removing it left
 *                     `..._an_already_replaced_order_...` GREEN, because a replacement cancels
 *                     the original and the DISPATCH limb then hides the control by itself. The
 *                     re-opened case exists because a mutation showed that.
 *   available()       NO -- `setUp` skips when the column is absent, and when it is present the
 *                     call is always true, so this limb cannot be driven on this schema. It
 *                     defends an installation without the column, and the service refuses that
 *                     case first with `REFUSED_COLUMN_ABSENT`, so nothing rests on the view's
 *                     copy. Stated rather than left to look covered.
 * ------------------------------------------------------------------------------------------------
 */
class OrderReplacementEntryPointTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('orders', 'replaced_order_id')) {
            $this->markTestSkipped(
                'BLOCKED: orders.replaced_order_id is absent on this schema, so '
                . 'OrderReplacement::available() is false and the control is correctly hidden. '
                . 'Nothing below would measure the entry point.'
            );
        }

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->grantPermission(User::find($this->merchant->id), 'Show Order');

        $this->merchant = User::find($this->merchant->id);
    }

    /** A cancellable order with readable lines — the shape the service accepts. */
    private function replaceableOrder(array $overrides = []): Order
    {
        return $this->makeOrder($this->store, array_merge([
            'delivered_status' => 0,
            'payment_status' => 'Paid',
            'product_json' => json_encode([[
                'product_id' => 1, 'name' => 'Lamp', 'qty' => 1, 'quantity' => 1,
                'price' => 10, 'orignal_price' => 10, 'final_price' => 10,
                'variant_id' => null, 'variant_name' => null,
            ]]),
        ], $overrides));
    }

    /**
     * Is the CONTROL offered for this order?
     *
     * KEYED ON THE ROUTE URL, NOT ON THE CSS CLASS, and the difference is not pedantry: the
     * `.order_replace` click handler renders UNCONDITIONALLY in the page's script block, so
     * `assertStringNotContainsString('order_replace', ...)` is true of no page and failed on all
     * three absence cases the moment the handler landed. Measured, not predicted — the first
     * version of this file did exactly that.
     *
     * The route URL carries the order id and is emitted only by the control itself, so it answers
     * the question the test is actually asking.
     */
    private function controlIsOffered(string $body, Order $order): bool
    {
        return str_contains($body, 'data-url="' . route('order.replace', $order->id) . '"');
    }

    private function detailPage(Order $order, ?User $as = null): string
    {
        $response = $this->actingAs($as ?? $this->merchant)
            ->get(route('order.view', Crypt::encrypt($order->id)));

        /* THE PREMISE. A 403, a 404 or a 500 satisfies every "the control is absent" assertion
           below for free, which is how an absence test passes against a page nobody can open. */
        $this->assertSame(200, $response->getStatusCode(),
            'the order detail page did not render, so the presence or absence of the control '
            . 'below proves nothing: ' . substr((string) $response->getContent(), 0, 300));

        $body = (string) $response->getContent();

        /* AND THE MENU THE CONTROL LIVES IN MUST BE THERE. If the status menu stopped rendering
           entirely, "no replace control" would be true and meaningless. */
        $this->assertStringContainsString('order_status', $body,
            'the order status menu did not render, so this page cannot show the replace control '
            . 'either and the assertions below measure the wrong thing');

        return $body;
    }

    // =============================================================================================
    //  1. THE CONTROL EXISTS AND REACHES THE ROUTE
    // =============================================================================================

    public function test_a_merchant_who_may_change_status_is_offered_the_replacement(): void
    {
        $order = $this->replaceableOrder();
        $body = $this->detailPage($order);

        $this->assertTrue($this->controlIsOffered($body, $order),
            'the approved D-32 replacement has no entry point on the order the merchant is '
            . 'looking at, so the capability is complete in the engine and unreachable in the '
            . 'product');
    }

    public function test_the_control_targets_the_real_route(): void
    {
        $order = $this->replaceableOrder();
        $body = $this->detailPage($order);

        $this->assertStringContainsString(route('order.replace', $order->id), $body,
            'the control does not carry the order-replace route for THIS order, so it would act '
            . 'on the wrong order or on none');
    }

    /**
     * AND THE OPERATION REALLY RUNS FROM IT. An entry point that renders and then refuses is the
     * same defect in a different place, so the route the control names is driven.
     */
    public function test_posting_to_that_route_performs_the_replacement(): void
    {
        $order = $this->replaceableOrder();

        $response = $this->actingAs($this->merchant)
            ->post(route('order.replace', $order->id));

        $this->assertTrue((bool) $response->json('status'),
            'the control points at a route that refuses the merchant it is shown to: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertNotEmpty($response->json('data.replacement_order_id'));

        $this->assertSame(1,
            (int) DB::table('orders')->where('replaced_order_id', $order->id)->count(),
            'no linked replacement was written');
    }

    // =============================================================================================
    //  2. IT IS NOT OFFERED WHERE THE SERVICE WOULD REFUSE
    // =============================================================================================

    /**
     * The permission is the service's own question. Staff with another permission entirely must
     * not see a control that `REFUSED_NOT_AUTHORISED` would decline.
     */
    public function test_a_principal_without_manage_order_is_not_offered_it(): void
    {
        $order = $this->replaceableOrder();

        $staff = $this->makeStaff($this->merchant, ['current_store' => $this->store->id]);
        $this->grantPermission(User::find($staff->id), 'Show Order');
        $staff = User::find($staff->id);

        $body = $this->detailPage($order, $staff);

        $this->assertFalse($this->controlIsOffered($body, $order),
            'a principal the service would refuse with REFUSED_NOT_AUTHORISED is being offered '
            . 'the control anyway');
    }

    public function test_a_dispatched_order_is_not_offered_it(): void
    {
        $order = $this->replaceableOrder(['delivered_status' => 1]);
        $body = $this->detailPage($order);

        $this->assertFalse($this->controlIsOffered($body, $order),
            'a dispatched order offers a control the service refuses with '
            . 'REFUSED_ALREADY_DISPATCHED');
    }

    public function test_an_already_replaced_order_is_not_offered_it_again(): void
    {
        $order = $this->replaceableOrder();

        /* DRIVEN THROUGH THE ROUTE, not through the service directly. A direct
           `cancelAndReplace($order, $this->merchant)` answered `not_authorised`: the in-memory
           `User` carries Laratrust's cached role set, and the Gate reads it rather than the rows
           the fixture just granted. Posting is also how a merchant really reaches this, so the
           premise and the thing under test use the same door. */
        $first = $this->actingAs($this->merchant)->post(route('order.replace', $order->id));

        $this->assertTrue((bool) $first->json('status'),
            'premise: the first replacement did not happen, so the second below is not measuring '
            . 'a repeat: ' . substr((string) $first->getContent(), 0, 300));

        $body = $this->detailPage($order->fresh());

        $this->assertFalse($this->controlIsOffered($body, $order),
            'an order that already has a linked replacement is offering the control a second '
            . 'time, which the service refuses with REFUSED_ALREADY_REPLACED');
    }

    /**
     * AND THE ALREADY-REPLACED LIMB IS NOT REDUNDANT, which a mutation is what proved.
     *
     * Removing it from the controller left the case above GREEN: a replacement cancels the
     * original, so `delivered_status` becomes 2 and the dispatch limb hides the control by
     * itself. The limb only earns its place in the state that separates the two — an original
     * that was replaced and has since been re-opened, which a merchant can do from this very
     * status menu.
     *
     * Without it the control is offered on an order the service would refuse with
     * `REFUSED_ALREADY_REPLACED`, i.e. a button that cannot work. With it, the merchant is not
     * offered one. The status is set directly here because the route for re-opening is a separate
     * surface and this test is about the view's condition, not about that route.
     */
    public function test_a_replaced_order_that_was_reopened_is_still_not_offered_it(): void
    {
        $order = $this->replaceableOrder();

        $first = $this->actingAs($this->merchant)->post(route('order.replace', $order->id));
        $this->assertTrue((bool) $first->json('status'),
            'premise: the first replacement did not happen: '
            . substr((string) $first->getContent(), 0, 300));

        /* The merchant re-opens the cancelled original. Written through the table and the model
           cache flushed, because `Order` is Cachable and a stale read would make this pass for
           the wrong reason (CLAUDE.md). */
        DB::table('orders')->where('id', $order->id)->update(['delivered_status' => 0]);
        (new Order)->flushCache();

        $this->assertSame(0,
            (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'premise: the re-open did not take, so the dispatch limb is still what hides the '
            . 'control and this case measures nothing new');

        $body = $this->detailPage($order->fresh());

        $this->assertFalse($this->controlIsOffered($body, $order),
            'a re-opened original that already has a linked replacement is offering the control '
            . 'again. The dispatch limb no longer covers this state, so the already-replaced '
            . 'limb is the only thing between the merchant and a button the service refuses.');
    }
}
