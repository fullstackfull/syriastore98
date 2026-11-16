<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F034 — MERCHANT-SIDE CANCELLATION NEVER CREDITS STOCK BACK.
 * =================================================================================================
 *
 * THE DEFECT, in one sentence: of the cancel doors that write `orders.delivered_status = 2`,
 * exactly ONE gives the goods back to the shelf — `OrderController::status_cancel()`, the
 * storefront endpoint a CUSTOMER uses. Every merchant-facing door writes the same two columns
 * (`cancel_date`, `delivered_status = 2`) through the same shared writer and then stops.
 *
 * So: a merchant cancels a pending, undispatched order from their own order screen. The order is
 * cancelled, the payment is walked back to `Unpaid`, the buyer gets a Status Change e-mail — and
 * the three units that order took off the shelf are never counted back in. The catalogue now
 * under-reports stock by the quantity of every order a merchant has ever cancelled, permanently,
 * and silently: nothing in the response, the order record or the stock record says the credit was
 * skipped. The merchant's own remedy — raise the figure by hand — is indistinguishable from
 * inventory fraud in an audit.
 *
 * THE DOORS, RE-LOCATED AGAINST THE CODE AS IT STANDS (2026-11-16, feature/phase-04-commerce).
 * Every line number in the finding that reached me had moved; these are measured.
 *
 *   DOOR                                                        ROUTE                       RESTOCKS
 *   OrderController::order_status_change()          :1733        web.php:1602                NO  <-- this file
 *   Api\DashboardController::updateStatus()         :347  (case 2 at :544)
 *                                                                api.php:208 (api/admin/...)  NO  <-- this file
 *   Api\ApiController::order_status_change()        :8027        api.php:145                 NO
 *   Api\DeliverySyriaOrderController::updateStatus():17   (case 2 at :200)
 *                                                                api.php:71                  NO
 *   Api\DashboardController::orderCancel()          :5671        api.php:166 AND api.php:380 NO
 *   LotteryPrizes\LotteryOrderController::order_status_change()   :125
 *                                                                LotteryPrizes web.php:56    NO
 *     (module door: delegates to the same core `Order::order_status_change()` cancel arm)
 *   App\Services\OrderReplacement::cancelAndReplace()            web.php:1612                NO, DELIBERATELY
 *   OrderController::status_cancel()                :2077        customer.php:174            YES
 *
 * THE NINTH PATH IS CORRECT AND IS EXCLUDED FROM THE FIX. `OrderReplacement::cancelAndReplace()`
 * cancels an order and reissues it with the same lines; the goods never returned to the shelf, so
 * the pair must net -N and not 0. `OrderReplacementTest::
 * test_the_pair_moves_the_stock_once_and_the_lines_say_why` (:185-224, the net-stock assertion at
 * :199-205) pins exactly that, and
 * `test_the_replacement_door_must_not_be_dragged_into_the_fix` below pins it a second time inside
 * this file, so whoever implements the fix sees both ends of the constraint in one place. A fix
 * that restocks on "any cancel" reds that test, and reds it for a reason that is a real defect:
 * it would bill the shelf twice for one set of goods.
 *
 * AND THE OTHER END OF THAT CONSTRAINT IS A RED CASE, NOT A GREEN ONE.
 * `test_cancelling_a_replacement_order_must_not_invent_the_carried_stock` cancels the REPLACEMENT
 * and must find the figure unchanged. It is RED at the current code AND IT IS MEANT TO BE: a
 * replacement's lines are stamped `Order::LINE_STOCK_CARRIED`, nothing in production reads that
 * stamp (`lineStockCarriedFromOriginal()` has exactly one call site in the repository and it is an
 * assertion in `OrderReplacementTest`), and `movedOutFor()` on the replacement's own `order_id`
 * answers UNKNOWN — which every restore loop here treats as RESTORE. So the P4-F034 fix must skip a
 * carried line BEFORE it consults the movement record, and that case is the only thing in the
 * repository that would catch it if it does not. Do not delete it or relax it to make a run green.
 *
 * TWO CASES IN THIS FILE ARE GREEN TODAY FOR A REASON THAT IS NOT EVIDENCE, and each says so in its
 * own docblock: `test_a_line_the_record_says_never_moved_is_not_invented_by_a_merchant_cancel` and
 * `test_the_replacement_door_must_not_be_dragged_into_the_fix` both pass simply because nothing in
 * this codebase restocks on a merchant cancel at all. They are guards on a future fix, and they
 * only begin to measure anything once the defect cases above go green. Nobody may cite their
 * greenness as proof that the NOT_MOVED branch or the replacement exclusion is handled today.
 *
 * WHAT THE RESTORING DOOR DOES THAT THE OTHERS DO NOT — the shape of the missing code, so the fix
 * is a move and not an invention. `status_cancel()` (OrderController:2077, restore loop :2200-2360), inside
 * `if ($responce['status'] == 'success')` and inside one `DB::transaction`:
 *
 *   1. reads the lines through `Order::order_detail()`, which normalises `qty`/`quantity`;
 *   2. resolves each `product_id` WITH an `where('store_id', $order->store_id)` predicate,
 *      because `Product` registers no `StoreScope` and is absent from `config/tenancy.php`'s
 *      `scoped_models`, so a bare `Product::find()` reaches every tenant's catalogue;
 *   3. asks `StockMovement::movedOutFor()` and honours its three answers — MOVED restore,
 *      NOT_MOVED skip, UNKNOWN restore and log (D-15: do not infer stock movement from status);
 *   4. credits through `ProductStockService::restoreProductStock()` / `restoreVariantStock()`,
 *      which `increment()` rather than read-modify-write and flush the `Cachable` model cache;
 *   5. stamps `Order::LINE_STOCK_RESTORED` on each credited line via
 *      `markCancelledLinesRestored()` (OrderController:2381), so a return approved later on the
 *      same order cannot credit the same goods a second time.
 *
 * WHY THESE TESTS ARE SHAPED AS THEY ARE
 * --------------------------------------
 * "The stock did not come back" is the easiest assertion in this codebase to pass for the wrong
 * reason, and this file is built against the four ways it has actually happened here:
 *
 *   a 404       — wrong route, or a posted `id` that loses to the `{id}` segment;
 *   a 403       — `isAbleTo()` has NO super-admin bypass and `Manage Order` is seeded by nothing
 *                 on a from-source database, so an unprivileged fixture is refused before the
 *                 handler runs and "stock unchanged" is then vacuously true;
 *   an empty basket — `product_json` the loop cannot read is skipped with a log line, so a
 *                 malformed fixture measures nothing;
 *   NOT_MOVED   — a fix that consults `movedOutFor()` SKIPS a line the record says never took
 *                 stock out. A fixture with no `stock_movements` row reads UNKNOWN (restores);
 *                 one with an out-movement for a DIFFERENT line reads NOT_MOVED (skips). Both are
 *                 correct behaviour, and a test seeded into the second state would stay red after
 *                 a perfect fix.
 *
 * So every defect assertion is preceded by premises that measure the cancel really landed, the
 * lines really are readable, the record really says MOVED, and — the strongest control — that the
 * BYTE-IDENTICAL fixture IS credited when the same order is cancelled through the storefront door
 * instead. That last one is what separates "this door is broken" from "this fixture is broken".
 *
 * `ApiResponser::error()` returns HTTP 200 with `{status: 0}`, so no test here treats a 200 as
 * evidence of anything; the stock figure and the order columns are the evidence.
 *
 * Product is `Cachable`, so every stock read goes through Eloquent (which the production restore
 * path flushes) and `test_premise_the_stock_reads_in_this_file_are_not_stale` asserts Eloquent and
 * the raw row agree, so a stale cached read cannot make this file lie in either direction.
 *
 * @see \App\Http\Controllers\OrderController::order_status_change()
 * @see \App\Http\Controllers\OrderController::status_cancel()
 * @see \App\Http\Controllers\Api\DashboardController::updateStatus()
 * @see \App\Services\OrderReplacement::cancelAndReplace()
 */
class MerchantCancelRestockTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** `orders.delivered_status`: 0 placed, 2 cancelled (app/Models/Order.php:702-709). */
    private const DELIVERED_PLACED = 0;
    private const DELIVERED_CANCELLED = 2;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = $this->makeMerchant();
        $this->store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $this->store->id;
        $merchant->save();

        /*
         * `Manage Order` through a real Laratrust role, not a stub. `permissions_as_gates` is
         * false in this application, so `$user->can()` is always false and `OrderPolicy::allow()`
         * reaches `isAbleTo()`. Without the grant the merchant is refused at the policy and every
         * stock assertion below passes while measuring the refusal instead of the restock.
         */
        $this->grantPermission(User::find($merchant->id), 'Manage Order');

        $this->merchant = User::find($merchant->id);
    }

    // =============================================================================================
    //  PREMISES — the fixture reaches the code under test
    // =============================================================================================

    /** The route under test is the merchant door, and it is the handler the finding names. */
    public function test_premise_the_merchant_door_routes_to_the_handler_under_test(): void
    {
        $route = app('router')->getRoutes()->getByName('order.status.change');

        $this->assertNotNull($route, 'the merchant cancel route is gone, so every POST below is a 404');
        $this->assertSame('order-status-change/{id}', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertSame(
            OrderController::class . '@order_status_change',
            $route->getActionName(),
            'the merchant door no longer resolves to the handler this file measures'
        );
    }

    /**
     * THE CANCEL ITSELF LANDS. Not a 403, not a 404, not a 500 — the two columns the shared writer
     * owns really change, which is what makes "and the stock did not come back" a measurement.
     */
    public function test_premise_the_merchant_cancel_is_authorised_and_actually_writes(): void
    {
        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)]);
        $this->recordOutMovement($order, $product, 3);

        $response = $this->cancelAsMerchant($order);

        $this->assertNotSame(403, $response->status(), 'the merchant was refused by the policy, so '
            . 'no stock assertion in this file would be measuring the restock. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertNotSame(404, $response->status(), 'the route or the order id did not resolve');
        $this->assertNotSame(500, $response->status(), 'the handler threw. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $response->assertOk();
        $response->assertJson(['status' => 'success']);

        $fresh = $this->orderRow($order->id);
        $this->assertSame(self::DELIVERED_CANCELLED, (int) $fresh->delivered_status);
        $this->assertNotNull($fresh->cancel_date, 'the cancel arm did not run');
        $this->assertSame('Unpaid', (string) $fresh->payment_status,
            'the cancel arm did not reach its payment write, so the success branch did not run');
    }

    /**
     * THE BASKET IS NOT EMPTY. A restore loop reads its lines through `Order::order_detail()`,
     * which normalises `qty`/`quantity`; a line it cannot read is skipped with a log line and a
     * fixture in that shape proves nothing at all.
     */
    public function test_premise_the_order_carries_one_readable_line_a_restore_loop_could_credit(): void
    {
        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)]);

        $detail = Order::order_detail($order->id);

        $this->assertIsArray($detail, 'order_detail() refused this order');
        $this->assertArrayHasKey('product', $detail);
        $this->assertCount(1, $detail['product'], 'the fixture line is not readable by the loop');
        $this->assertSame((int) $product->id, (int) $detail['product'][0]['product_id']);
        $this->assertSame(3, (int) $detail['product'][0]['qty'],
            'the quantity a restore loop would credit is not 3, so the expected figure below is wrong');
    }

    /**
     * THE RECORD SAYS THE LINE TOOK STOCK OUT. `movedOutFor()` answers MOVED here, so a fix that
     * consults it (as the restoring door does) must RESTORE rather than skip. A fixture reading
     * NOT_MOVED would stay red after a correct fix — the one way this file could lie.
     */
    public function test_premise_the_stock_record_says_this_line_moved_stock_out(): void
    {
        $this->assertTrue(Schema::hasTable('stock_movements'),
            'without the record every line reads UNKNOWN; the MOVED premise below cannot be set');
        $this->assertTrue(StockMovement::available());

        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)]);
        $this->recordOutMovement($order, $product, 3);

        $this->assertSame(
            StockMovement::MOVED,
            StockMovement::movedOutFor($this->store->id, $order->id, $product->id, null),
            'the record does not say these goods left the shelf, so a restore-consulting fix would '
            . 'correctly SKIP this line and the defect assertion would be unfalsifiable'
        );
    }

    /**
     * THE STRONGEST PREMISE: THE SAME FIXTURE IS CREDITED BY THE DOOR THAT WORKS.
     *
     * Same product, same quantity, same `product_json`, same store, same stock-movement evidence —
     * cancelled through `OrderController::status_cancel()` instead of the merchant door. Stock goes
     * 4 -> 7 and the line is stamped. So when the merchant door leaves it at 4, the difference is
     * the DOOR and not the fixture, the schema, the cache or the quantity.
     */
    public function test_premise_the_identical_fixture_is_restored_by_the_storefront_door(): void
    {
        $customer = $this->shopper();
        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)], ['customer_id' => $customer->id, 'is_guest' => 0]);
        $this->recordOutMovement($order, $product, 3);

        Auth::guard('customers')->login($customer);
        $this->assertTrue(Auth::guard('customers')->check(),
            'status_cancel aborts 403 without a customer, so this control would measure nothing');

        $request = Request::create('/' . $this->store->slug . '/status-cancel', 'POST', [
            'order_id' => $order->id,
            'order_status' => 'cancel',
        ]);
        $request->setLaravelSession(app('session.store'));

        $payload = json_decode(
            (string) (new OrderController())->status_cancel($request, $this->store->slug)->getContent(),
            true
        );

        $this->assertSame('success', $payload['status'] ?? null,
            'the control door refused the fixture: ' . json_encode($payload));
        $this->assertSame(self::DELIVERED_CANCELLED, (int) $this->orderRow($order->id)->delivered_status);

        $this->assertSame(7, $this->stockOf($product),
            'THE CONTROL ITSELF IS BROKEN. The storefront door is the one door that restocks, and '
            . 'with this fixture it did not. Fix the fixture before reading anything else in this file.');

        $lines = json_decode((string) $this->orderRow($order->id)->product_json, true);
        $this->assertTrue(Order::lineStockAlreadyRestored($lines[0] ?? []),
            'the control door did not stamp LINE_STOCK_RESTORED, so the marker assertions below '
            . 'are measuring the wrong contract');

        Auth::guard('customers')->logout();
    }

    /** A `Cachable` read cannot make this file lie: Eloquent and the raw row must agree. */
    public function test_premise_the_stock_reads_in_this_file_are_not_stale(): void
    {
        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)]);
        $this->recordOutMovement($order, $product, 3);

        $this->cancelAsMerchant($order);

        $this->assertSame(
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            $this->stockOf($product),
            'Product is Cachable and the Eloquent read disagrees with the row, so every stock '
            . 'figure in this file is unsafe in both directions'
        );
    }

    // =============================================================================================
    //  THE DEFECT — door 1, the merchant web order screen
    // =============================================================================================

    /**
     * THE CORE FAILING CASE.
     *
     * A merchant cancels a pending, undispatched order of 3 units through their own order screen.
     * The stock that order took must come back. It does not.
     */
    public function test_a_merchant_cancelling_a_pending_order_gets_the_stock_back(): void
    {
        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)]);
        $this->recordOutMovement($order, $product, 3);

        $this->cancelAsMerchant($order)->assertOk();

        /* Premise inline, so a refusal can never be mistaken for the defect. */
        $this->assertSame(self::DELIVERED_CANCELLED, (int) $this->orderRow($order->id)->delivered_status,
            'premise: the cancel did not land, so the figure below measures nothing');

        $this->assertSame(7, $this->stockOf($product),
            'A merchant cancelled a pending, undispatched order and its 3 units never came back to '
            . 'the shelf. The storefront customer door credits this exact fixture (see the premise '
            . 'above); OrderController::order_status_change() has no restore path at all.');
    }

    /** The variant branch is missing too — and it is the branch most catalogues actually use. */
    public function test_a_merchant_cancelling_a_variant_line_gets_the_variant_stock_back(): void
    {
        $product = $this->productIn(0, ['variant_product' => 1]);
        $variant = $this->variantIn($product, 5);
        $order = $this->orderWith([$this->line($product, 2, $variant->id)]);
        $this->recordOutMovement($order, $product, 2, $variant->id);

        $this->cancelAsMerchant($order)->assertOk();

        $this->assertSame(self::DELIVERED_CANCELLED, (int) $this->orderRow($order->id)->delivered_status,
            'premise: the cancel did not land');

        $this->assertSame(7, $this->variantStockOf($variant),
            'a cancelled variant line never returned its 2 units to product_variants.stock');
    }

    /** Every line of a multi-line cancellation, not just the first. */
    public function test_every_line_of_a_merchant_cancellation_comes_back(): void
    {
        $first = $this->productIn(1);
        $second = $this->productIn(10);
        $order = $this->orderWith([$this->line($first, 2), $this->line($second, 5)]);
        $this->recordOutMovement($order, $first, 2);
        $this->recordOutMovement($order, $second, 5);

        $this->cancelAsMerchant($order)->assertOk();

        $this->assertSame(self::DELIVERED_CANCELLED, (int) $this->orderRow($order->id)->delivered_status,
            'premise: the cancel did not land');

        /* BOTH figures in ONE assertion, deliberately. Asserted one at a time, PHPUnit stops at
           line 1 and line 2 is never measured -- and "line 2 was also skipped" is exactly the
           half-restore `movedOutFor()`'s own docblock records as a real defect it once produced
           (the first line's recorded restore made the second read NOT_MOVED). The failure message
           has to show both. */
        $this->assertSame(
            ['line_1' => 3, 'line_2' => 15],
            ['line_1' => $this->stockOf($first), 'line_2' => $this->stockOf($second)],
            'a multi-line merchant cancellation did not credit its lines'
        );
    }

    /**
     * AND THE MARKER, which is not decoration: `Order::product_return()` and the merchant return
     * loop both read `LINE_STOCK_RESTORED` before crediting. A cancel that restores WITHOUT
     * stamping lets a return approved later on the same order credit the same goods a second time
     * — the double-credit that `markCancelledLinesRestored()` exists to prevent on the one door
     * that already restores.
     *
     * THE FIGURE IS ASSERTED BESIDE THE MARKER, and the two together are the contract rather than
     * either alone. Added after review: with the marker assertion by itself, a fix that stamps
     * `LINE_STOCK_RESTORED` and credits nothing passed this case — and that is the exact shape
     * `markCancelledLinesRestored()`'s own docblock forbids (OrderController.php:2370-2379: it
     * stamps only the lines a cancellation *really did credit*, because a skipped line must NOT be
     * stamped). An unconditional stamp is worse than no stamp: it is a false claim that the goods
     * came back, and it is read as such by every return and refund surface.
     */
    public function test_a_merchant_cancellation_stamps_the_line_it_credited(): void
    {
        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)]);
        $this->recordOutMovement($order, $product, 3);

        $this->cancelAsMerchant($order)->assertOk();

        $this->assertSame(self::DELIVERED_CANCELLED, (int) $this->orderRow($order->id)->delivered_status,
            'premise: the cancel did not land');

        $this->assertSame(7, $this->stockOf($product),
            'the stamp is only honest about a credit that happened. The goods did not come back, so '
            . 'a LINE_STOCK_RESTORED marker here would be a false claim that blocks the return and '
            . 'refund doors from ever crediting them (markCancelledLinesRestored(), '
            . 'OrderController.php:2370-2379, stamps ONLY the lines it really credited)');

        $lines = json_decode((string) $this->orderRow($order->id)->product_json, true);
        $this->assertIsArray($lines);
        $this->assertTrue(Order::lineStockAlreadyRestored($lines[0] ?? []),
            'the merchant cancellation left no LINE_STOCK_RESTORED marker, so a return approved '
            . 'later on this order would credit the same goods again');
    }

    /**
     * REPLAY SAFETY, as a condition on the fix rather than as a defect. The shared writer refuses a
     * second cancel on `delivered_status != 0`, so a restore placed inside the success branch — the
     * shape the storefront door uses — cannot run twice. A fix that credits before reading the
     * writer's answer turns one order id into an unbounded inventory-inflation primitive, which is
     * precisely the defect that was closed on `status_cancel` last phase.
     */
    public function test_replaying_a_merchant_cancellation_credits_nothing_extra(): void
    {
        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)]);
        $this->recordOutMovement($order, $product, 3);

        $this->cancelAsMerchant($order)->assertOk();
        $afterFirst = $this->stockOf($product);

        $second = $this->cancelAsMerchant($order);

        $this->assertSame(false, $this->payload($second)['status'] ?? null,
            'premise: the writer no longer refuses a second cancel, so this measures nothing. Body: '
            . substr((string) $second->getContent(), 0, 200));
        $this->assertSame($afterFirst, $this->stockOf($product),
            'a replayed cancellation changed the stock figure');
        $this->assertSame(7, $afterFirst,
            'the first cancellation still did not credit the goods (the defect); asserted here too '
            . 'so this case cannot go green by crediting nothing twice');
    }

    /**
     * THE GUARD A FIX MUST KEEP. D-15: *"Do not infer stock movement from order status, payment
     * status, a guessed flag or the existence of an order."* The record here says this order was
     * observed and this line never took stock out, so a cancellation must credit NOTHING —
     * restoring would invent inventory that never left. GREEN today, and it must stay green.
     *
     * AND ITS GREENNESS TODAY IS NOT EVIDENCE OF ANYTHING. Read honestly: this case passes at the
     * current code because `order_status_change()` restocks on NO path whatever, for any line, in
     * any state — not because it consults `movedOutFor()` and correctly skips this one. The same
     * green would be produced by a handler that had never heard of the stock record. So this is a
     * GUARD against a future fix, not a measurement of the present one, and it only starts carrying
     * evidence once the defect cases above go green: at that point a green here means the fix
     * really does honour NOT_MOVED, and a red here means it restores unconditionally. Until then,
     * nobody may cite it as proof that the NOT_MOVED branch is handled.
     */
    public function test_a_line_the_record_says_never_moved_is_not_invented_by_a_merchant_cancel(): void
    {
        $neverMoved = $this->productIn(4);
        $other = $this->productIn(9);
        $order = $this->orderWith([$this->line($neverMoved, 3)]);

        /* The order IS observed — an out-movement for a different product — which is what makes
           this line NOT_MOVED rather than UNKNOWN. */
        $this->recordOutMovement($order, $other, 1);

        $this->assertSame(
            StockMovement::NOT_MOVED,
            StockMovement::movedOutFor($this->store->id, $order->id, $neverMoved->id, null),
            'premise: the record does not read NOT_MOVED for this line, so this case is not the '
            . 'guard it claims to be'
        );

        $this->cancelAsMerchant($order)->assertOk();

        $this->assertSame(4, $this->stockOf($neverMoved),
            'a cancellation INVENTED stock for a line the record says never took any out');
    }

    // =============================================================================================
    //  THE DEFECT — door 2, the Flutter merchant app's own update-status endpoint
    // =============================================================================================

    /**
     * THE DOOR THE FINDING FIRST MISSED. `api/admin/orders/update-status` is what the shipped
     * admin Flutter app posts when a merchant moves an order from the order list, the order detail
     * screen or a customer's detail screen. Its `case 2:` sets `cancel_date` and nothing else, so
     * the same goods are lost through the app as through the web screen.
     *
     * Driven over HTTP with a Sanctum token because that is what the app sends: `custom.auth` logs
     * a `User` tokenable into the `web` guard, which is what `getCurrentStore()` and
     * `OrderPolicy::sameStore()` read.
     */
    public function test_a_merchant_cancelling_through_the_flutter_app_gets_the_stock_back(): void
    {
        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)]);
        $this->recordOutMovement($order, $product, 3);

        $response = $this->cancelThroughTheFlutterApp($order);

        $this->assertNotSame(403, $response->status(),
            'premise: the app door refused the merchant, so the figure below measures the refusal. '
            . 'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertNotSame(404, $response->status(),
            'premise: the order did not resolve by product_order_id within the current store');
        $this->assertSame(self::DELIVERED_CANCELLED, (int) $this->orderRow($order->id)->delivered_status,
            'premise: the app door did not actually cancel the order');

        $this->assertSame(7, $this->stockOf($product),
            'the Flutter merchant app cancelled a pending order and its 3 units never came back. '
            . 'Api\\DashboardController::updateStatus() case 2 writes cancel_date and no stock call.');

        /*
         * AND THE SAME MARKER THE WEB DOOR IS HELD TO. Added after review: this case asserted the
         * figure alone, so nothing required `case 2`'s restore to stamp `LINE_STOCK_RESTORED` — and
         * an UNSTAMPED Flutter cancel is a double-credit primitive, not a cosmetic gap.
         * `OrderController::restoreReturnedStock()` consults exactly one thing before crediting a
         * line, `Order::lineStockAlreadyRestored($line)` at OrderController.php:1519. So a merchant
         * who cancels in the app and then approves a return on the same order credits the same
         * goods twice, and the stamp is the only thing in the system that would have stopped it.
         */
        $lines = json_decode((string) $this->orderRow($order->id)->product_json, true);
        $this->assertIsArray($lines);
        $this->assertTrue(Order::lineStockAlreadyRestored($lines[0] ?? []),
            'the Flutter app cancellation credited the goods and left no LINE_STOCK_RESTORED '
            . 'marker. OrderController.php:1519 is the ONLY guard between this order and a second '
            . 'credit through restoreReturnedStock(), so a return approved later on the same order '
            . 'gives the same 3 units back again.');
    }

    /**
     * REPLAY SAFETY ON THE FLUTTER DOOR, WHICH IS NOT THE WEB DOOR'S REPLAY SAFETY.
     *
     * `test_replaying_a_merchant_cancellation_credits_nothing_extra` above is free of this question
     * because `Order::order_status_change()` refuses a second cancel outright: it returns `error`
     * on `delivered_status != 0` (app/Models/Order.php:702-706), so a restore placed inside that
     * handler's success branch cannot run twice however it is written.
     *
     * `Api\DashboardController::updateStatus()` HAS NO SUCH REFUSAL. Its only protection against a
     * repeated POST is a no-change short-circuit (DashboardController.php:507-517):
     *
     *     $oldDeliveredStatus === $newDeliveredStatus
     *         && $oldPaymentStatus === $newPaymentStatus
     *         && ! $request->filled('note')
     *
     * Every one of those three has to hold. A second POST of `delivered_status=2` CARRYING A NOTE —
     * which is what the shipped app sends whenever the merchant types one, and what a retry of that
     * same request sends — fails the third condition, re-enters `DB::transaction` (:520) and re-runs
     * `case 2:` (:544-547) on an order that is already cancelled. A `payment_status` in the body
     * does the same through the second condition.
     *
     * So a restore added inside `case 2:` without a gate on the OLD status credits the goods again
     * on every replay. One order id plus a note field is then an unbounded inventory-inflation
     * primitive, which is the defect that was closed on `status_cancel()` last phase.
     *
     * WHAT ACTUALLY HOLDS TODAY, MEASURED 2026-11-16 rather than assumed, because "it is green" and
     * "it is guarded" are different claims. The second POST is answered
     * `HTTP 200 {"success":false,"message":"Only a pending order can be cancelled."}`, from the new
     * transition guard at DashboardController.php:536 — `$newDeliveredStatus === 2 &&
     * $oldDeliveredStatus !== 0` — which sits OUTSIDE the transaction and before the switch. So the
     * replay is refused before `case 2:` is re-entered, and `case 2:`'s restore call (:589) needs no
     * gate of its own. Three things follow, and all three are why this case stays:
     *
     *   1. the protection is the TRANSITION guard, not the no-change short-circuit. Anyone who later
     *      widens that guard — "let a Confirmed order be cancelled from the app too" is an easy and
     *      plausible request — re-opens the replay in the same change, and this case is the only
     *      thing in the suite that would say so;
     *   2. it is refused with `success: false` at HTTP 200, not 401 and not 500. CLAUDE.md records
     *      that the shipped Flutter app forces a logout on any 401, so the refusal's SHAPE is part
     *      of the contract and not an implementation detail;
     *   3. the premise below still earns its place: it distinguishes this refusal from the
     *      no-change short-circuit, which would mean nothing was re-examined at all.
     *
     * ASSERTED WITH `assertSame` AND NOT `assertGreaterThan`: "the stock went up" is true of both
     * the fix and the inflation, and the figure has to be exactly 7.
     */
    public function test_replaying_the_flutter_cancellation_with_a_note_credits_nothing_extra(): void
    {
        $product = $this->productIn(4);
        $order = $this->orderWith([$this->line($product, 3)]);
        $this->recordOutMovement($order, $product, 3);

        $this->cancelThroughTheFlutterApp($order);

        $this->assertSame(self::DELIVERED_CANCELLED, (int) $this->orderRow($order->id)->delivered_status,
            'premise: the first app cancel did not land, so the replay below measures nothing');

        $afterFirst = $this->stockOf($product);

        /*
         * THE SECOND POST CARRIES A NOTE, deliberately. Without it the short-circuit at
         * DashboardController.php:507-517 answers "Order already has the requested statuses." and
         * the switch is never re-entered — so this case would be green against an unguarded
         * restore and would prove the opposite of what it claims. The premise below measures that
         * the short-circuit really was bypassed.
         */
        $second = $this->cancelThroughTheFlutterApp($order, ['note' => 'customer rang again']);

        $this->assertStringNotContainsString(
            'Order already has the requested statuses.',
            (string) $second->getContent(),
            'premise: the no-change short-circuit swallowed the replay, so the restore path was '
            . 'never re-entered and this case is not testing replay safety. The `note` is what is '
            . 'supposed to defeat that short-circuit (DashboardController.php:510). Body: '
            . substr((string) $second->getContent(), 0, 300)
        );

        $this->assertSame($afterFirst, $this->stockOf($product),
            'a REPLAYED Flutter cancellation changed the stock figure, so `case 2:` re-ran on an '
            . 'already-cancelled order. Two things stop that today and one of them has gone: the '
            . 'transition guard at DashboardController.php:536 '
            . '($newDeliveredStatus === 2 && $oldDeliveredStatus !== 0), which refuses the second '
            . 'POST before the switch, and the trait\'s per-line LINE_STOCK_RESTORED marker. If the '
            . 'transition guard has been widened — "let a Confirmed order be cancelled from the app '
            . 'too" — then `case 2:` needs a gate of its own on $oldDeliveredStatus, which the '
            . 'handler already has in scope (:487-489).');

        $this->assertSame(7, $afterFirst,
            'the first app cancellation still did not credit the goods (the defect); asserted here '
            . 'too so this case cannot go green by crediting nothing twice');
    }

    // =============================================================================================
    //  THE EXCLUSION — the ninth path is correct and a fix must not touch it
    // =============================================================================================

    /**
     * `OrderReplacement::cancelAndReplace()` IS A MERCHANT CANCEL THAT MUST NOT RESTOCK.
     *
     * It cancels an order and reissues it with identical lines, so the goods never came back to the
     * shelf and the pair must net -N. Already pinned by `OrderReplacementTest::
     * test_the_pair_moves_the_stock_once_and_the_lines_say_why`; repeated here so the constraint is
     * visible beside the tests that demand the opposite everywhere else. A fix implemented as "any
     * cancel restores" reds this.
     *
     * AND, LIKE THE NOT_MOVED GUARD ABOVE, ITS GREENNESS TODAY CARRIES NO EVIDENCE. It passes at
     * the current code for the trivial reason that nothing in this codebase restocks on a merchant
     * cancel at all — `cancelAndReplace()` delegates its cancel to the same
     * `Order::order_status_change()` the defect cases drive, and that handler has no restore path on
     * any branch. So a green here is not proof that the exclusion is respected; it is the absence of
     * the thing it excludes. It becomes a real measurement the moment the restore lands, and that is
     * when it matters: a fix written as "the cancel arm restores" reds it, and reds it for a genuine
     * defect. Its companion is
     * `test_cancelling_a_replacement_order_must_not_invent_the_carried_stock` below, which is RED on
     * purpose and pins the other end of the same constraint.
     */
    public function test_the_replacement_door_must_not_be_dragged_into_the_fix(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'replaced_order_id'),
            'premise: the replacement path is unavailable, so this exclusion guard measures nothing');
        $this->assertTrue(\App\Services\OrderReplacement::available());

        $product = $this->productIn(50);
        $order = $this->orderWith([$this->line($product, 2)], ['payment_status' => 'Unpaid']);
        $this->recordOutMovement($order, $product, 2);

        $this->actingAs($this->merchant);
        $before = $this->stockOf($product);

        [$state, $replacement] = \App\Services\OrderReplacement::cancelAndReplace(
            $order,
            $this->merchant
        );

        $this->assertSame(\App\Services\OrderReplacement::REPLACED, $state,
            'premise: the replacement was refused, so nothing about stock was exercised');
        $this->assertNotNull($replacement);
        $this->assertSame(self::DELIVERED_CANCELLED, (int) $this->orderRow($order->id)->delivered_status,
            'premise: the original was not cancelled, so this is not a cancel door');

        $this->assertSame($before, $this->stockOf($product),
            'cancel-and-replace restocked. The reissue carries the same goods, so crediting the '
            . 'cancel nets 0 and bills the shelf twice for one set of goods. This door is '
            . 'DELIBERATELY excluded from P4-F034 and must stay excluded.');
    }

    /**
     * AND THE OTHER END OF THE SAME CONSTRAINT: CANCELLING THE REPLACEMENT ITSELF.
     *
     * RED UNTIL THE PRODUCTION FIX HONOURS `LINE_STOCK_CARRIED`. THIS IS INTENDED. Do not "fix" it
     * by deleting it or by relaxing the assertion — it is the one case that pins the inflation the
     * replacement feature creates, and it is red because nothing in production reads the marker
     * yet.
     *
     * `cancelAndReplace()` is well protected going IN. It refuses outright unless every line's
     * out-movement is PROVEN (`stockEvidence()`, OrderReplacement.php:215, refusing
     * `REFUSED_STOCK_EVIDENCE_UNKNOWN` / `REFUSED_STOCK_NOT_MOVED`), it restocks nothing itself,
     * and the case above pins that. It is wide open coming OUT, and all three facts are measured:
     *
     *   1. the replacement's lines are stamped `Order::LINE_STOCK_CARRIED`
     *      (OrderReplacement.php:466-472) — "this line's stock was never taken on THIS order";
     *   2. NOTHING IN PRODUCTION READS THAT STAMP. `Order::lineStockCarriedFromOriginal()`
     *      (app/Models/Order.php:899) has exactly one call site in the whole repository, and it is
     *      an assertion in `OrderReplacementTest` (:209). The restore path consults
     *      `lineStockAlreadyRestored()` instead (OrderController.php:1519, :2417), which is a
     *      DIFFERENT marker on purpose — `LINE_STOCK_RESTORED` records that goods came back,
     *      `LINE_STOCK_CARRIED` records that they never left, and `Order::LINE_STOCK_CARRIED`'s own
     *      docblock (:877-892) forbids collapsing the two;
     *   3. `movedOutFor()` asked about the REPLACEMENT's `order_id` finds no movement rows at all
     *      and no `out` row for that order, so it answers UNKNOWN (StockMovement.php:197-203) — and
     *      UNKNOWN means RESTORE in every restore loop in this codebase, because D-15 forbids
     *      inferring movement from status.
     *
     * Put together: a merchant cancels a replacement order, and a P4-F034 restore loop credits
     * goods that never left the shelf, on exactly the order the replacement feature creates. The
     * original's cancel correctly credited nothing (the case above), so the pair goes from -N to 0
     * and the catalogue gains N units out of nothing.
     *
     * The gate the fix needs is one line: skip a line for which
     * `Order::lineStockCarriedFromOriginal($line)` is true, BEFORE consulting `movedOutFor()`,
     * because the marker is a statement about this order and the movement record is a statement
     * about a different one.
     */
    public function test_cancelling_a_replacement_order_must_not_invent_the_carried_stock(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'replaced_order_id'),
            'premise: the replacement path is unavailable, so this case measures nothing');
        $this->assertTrue(\App\Services\OrderReplacement::available());

        $product = $this->productIn(50);
        $original = $this->orderWith([$this->line($product, 2)], ['payment_status' => 'Unpaid']);
        $this->recordOutMovement($original, $product, 2);

        $this->actingAs($this->merchant);

        [$state, $replacement] = \App\Services\OrderReplacement::cancelAndReplace(
            $original,
            $this->merchant
        );

        $this->assertSame(\App\Services\OrderReplacement::REPLACED, $state,
            'premise: the replacement was refused, so there is no replacement order to cancel');
        $this->assertNotNull($replacement);

        /* PREMISE 1 — the replacement really is a reissue of carried lines. Without the stamp there
           is nothing for the fix to honour and this case is about a different order shape. */
        $replacementLines = json_decode((string) $this->orderRow($replacement->id)->product_json, true);
        $this->assertIsArray($replacementLines);
        $this->assertTrue(Order::lineStockCarriedFromOriginal($replacementLines[0] ?? []),
            'premise: the replacement line carries no LINE_STOCK_CARRIED stamp '
            . '(OrderReplacement.php:466-472), so the marker this case is about is absent');

        /* PREMISE 2 — and the stock record cannot save the fix. Asked about the REPLACEMENT's own
           order_id it answers UNKNOWN, which every restore loop here treats as RESTORE. This is why
           the marker, and not `movedOutFor()`, has to be the thing that stops the credit. */
        $this->assertSame(
            StockMovement::UNKNOWN,
            StockMovement::movedOutFor($this->store->id, $replacement->id, $product->id, null),
            'premise: the movement record answers for the replacement order after all, so the '
            . 'marker is not the only protection and this case overstates the risk'
        );

        /* PREMISE 3 — the replacement is cancellable at all: `Order::order_status_change()` refuses
           cancel unless `delivered_status == 0` (app/Models/Order.php:702-706), and
           `cancelAndReplace()` creates it at 0 (OrderReplacement.php:516). */
        $this->assertSame(self::DELIVERED_PLACED, (int) $this->orderRow($replacement->id)->delivered_status,
            'premise: the replacement is not pending, so the merchant cancel door would refuse it');

        $before = $this->stockOf($product);

        $this->cancelAsMerchant($replacement)->assertOk();

        $this->assertSame(self::DELIVERED_CANCELLED, (int) $this->orderRow($replacement->id)->delivered_status,
            'premise: the replacement was not cancelled, so the figure below measures a refusal');

        $this->assertSame($before, $this->stockOf($product),
            'CANCELLING A REPLACEMENT ORDER INVENTED STOCK. Its lines are stamped '
            . 'LINE_STOCK_CARRIED — the goods were taken by the ORIGINAL and never came back — so '
            . 'crediting this cancel adds units that never left the shelf. Nothing in production '
            . 'reads that stamp (Order::lineStockCarriedFromOriginal() has one call site and it is '
            . 'a test), and movedOutFor() on the replacement\'s own order_id answers UNKNOWN, which '
            . 'a restore loop treats as RESTORE. The restore path must skip a carried line before '
            . 'it consults the movement record.');
    }

    // =============================================================================================
    //  Fixtures
    // =============================================================================================

    /** The shipped merchant caller is the order detail page's jQuery POST, which expects JSON. */
    private function cancelAsMerchant(Order $order)
    {
        return $this->actingAs($this->merchant)->post(
            route('order.status.change', $order->id),
            ['delivered' => 'cancel'],
            ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*']
        );
    }

    /**
     * The shipped admin Flutter app's own cancel: `POST api/admin/orders/update-status`.
     *
     * A Sanctum bearer token because that is what the app sends — `custom.auth` logs a `User`
     * tokenable into the `web` guard, which is what `getCurrentStore()` and `OrderPolicy::
     * sameStore()` read. The order is addressed by `product_order_id`, as the app addresses it.
     *
     * `$extra` is merged over the body so a caller can add the fields that defeat the handler's
     * no-change short-circuit (`note`, `payment_status` — DashboardController.php:507-517).
     *
     * @param array<string, mixed> $extra
     */
    private function cancelThroughTheFlutterApp(Order $order, array $extra = [])
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $this->merchant->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/orders/update-status', array_merge([
            'product_order_id' => $order->product_order_id,
            'delivered_status' => self::DELIVERED_CANCELLED,
        ], $extra));
    }

    /** @return array<string, mixed> */
    private function payload($response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function productIn(int $stock, array $overrides = []): Product
    {
        return Product::forceCreate(array_merge([
            'name' => 'f034-' . uniqid('', false),
            'slug' => 'f034-' . uniqid('', false),
            'price' => 100,
            'sale_price' => 100,
            'product_stock' => $stock,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'status' => 1,
            'store_id' => $this->store->id,
            'created_by' => $this->store->created_by,
        ], $overrides));
    }

    private function variantIn(Product $product, int $stock): ProductVariant
    {
        /* `product_variants` declares `variant` and `sku`, NOT `name` -- the hand-built schema in
           OrderCancelRestockTest uses `name`, which does not exist in the real migration
           (2024_01_01_104858_create_product_variants_table.php). Measured: a `name` key here is a
           QueryException, which would have made the variant case red for the wrong reason. */
        return ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'v-' . uniqid('', false),
            'sku' => 'sku-' . uniqid('', false),
            'price' => 100,
            'stock' => $stock,
            'stock_order_status' => 'not_allow',
            'store_id' => $this->store->id,
        ]);
    }

    /** @return array<string, mixed> one `product_json` line */
    private function line(Product $product, int $qty, int $variantId = 0): array
    {
        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'qty' => $qty,
            'price' => 100,
            'variant_id' => $variantId,
        ];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function orderWith(array $lines, array $overrides = []): Order
    {
        return $this->makeOrder($this->store, array_merge([
            'product_order_id' => 'F034-' . strtoupper(uniqid('', false)),
            'product_json' => json_encode($lines),
            'product_id' => implode(',', array_column($lines, 'product_id')),
            'product_price' => 100,
            'final_price' => 100,
            'payment_status' => 'Paid',
            'delivered_status' => self::DELIVERED_PLACED,
        ], $overrides));
    }

    /**
     * Make the order's out-movement provable, so `movedOutFor()` reads MOVED.
     *
     * `variant_id` is coerced to NULL for a product-only line: `movedOutFor()` matches one with
     * `whereNull('variant_id')`, so a literal 0 records a row the lookup can never find and the
     * line would read UNKNOWN while looking seeded.
     */
    private function recordOutMovement(Order $order, Product $product, int $qty, ?int $variantId = null): void
    {
        StockMovement::record([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variantId ?: null,
            'direction' => StockMovement::OUT,
            'quantity' => $qty,
            'applied' => true,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);
    }

    private function shopper(): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'F034',
            'last_name' => 'Shopper',
            'email' => 'f034-' . uniqid('', false) . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $this->store->id,
            'status' => 1,
            'register_type' => 'email',
        ]);
    }

    /** `Order` carries no StoreScope, but withoutGlobalScopes() states the intent explicitly. */
    private function orderRow(int $orderId): Order
    {
        $order = Order::withoutGlobalScopes()->find($orderId);
        $this->assertNotNull($order, 'the order row vanished');

        return $order;
    }

    /** Through Eloquent: the restore path flushes the Cachable cache, a raw read would not see it. */
    private function stockOf(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    private function variantStockOf(ProductVariant $variant): int
    {
        return (int) ProductVariant::withoutGlobalScopes()->find($variant->id)->stock;
    }
}
