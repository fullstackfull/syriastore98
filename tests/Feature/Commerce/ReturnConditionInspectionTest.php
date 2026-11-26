<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderRefundController;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ReturnInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RET-002 — "فحص حالة المنتج قبل إعادته للبيع": the condition of a returned item decides whether
 * it goes back on sale.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS, measured rather than assumed
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `RET-002` is one of the seven `RET-*` decisions in
 * `docs/development/master-plan/DECISION-ID-REGISTER.md`. All seven appear in **neither**
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` **nor** `MASTER-PLAN-GAP-REGISTER.md` — measured by
 * grep over both on 2026-11-26 — so none of them has ever been tracked.
 *
 * Before this change the platform had no notion of a returned item's condition at all. A census
 * over `app/` and `database/migrations/` for `inspect`, `resellable`, `quarantine`, `damaged` and a
 * quoted `condition` returned, outside unrelated prose, only `FlashSale`'s rule vocabulary and
 * `Customer::$fields_status`. The one string that names an item's condition is
 * `OrderRefund::RefundReason()`'s *"The Product Was Damaged Upon Arrival."*, and `refund_reason` is
 * written by `AccountProfileController::order_refund_request()` and read by three Blade files to
 * PRINT it — nothing reads it to decide anything.
 *
 * THREE DOORS RESTOCK, and every control on them is about how many units and whether twice:
 *
 *   1. `OrderRefundController::RefundStock()`
 *   2. `Order::product_return()` → `performProductReturn()`
 *   3. `OrderController::order_return_request()` → `restoreReturnedStock()`
 *
 * So a damaged return became sellable inventory on exactly the same evidence as a pristine one.
 * Each `test_*_blocks_*` case below FAILED before `ReturnInspection` was consulted at that door,
 * and failed for the right reason: the stock went up.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE CONTROLS ARE AS IMPORTANT AS THE BLOCKS, because the gate is ADDITIVE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `blocksRestock()` answers false for a line with no verdict, so every door must behave exactly as
 * it did before for the platform as it stands today — every store has recorded zero verdicts. Each
 * door therefore has a paired case proving it still restocks when nothing is recorded, and one
 * proving a `resellable` verdict does not block either. A gate that blocked everything would pass
 * the block cases and be a stock outage.
 */
class ReturnConditionInspectionTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 11;
    private const STORE_B = 22;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        /*
         * THE PREMISE, ASSERTED RATHER THAN ASSUMED. Without the table every case here would read
         * as "the gate blocks nothing" — which is `blocksRestock()`'s own documented fail-open
         * direction, so the block cases would fail while naming the wrong cause.
         */
        $this->assertTrue(
            Schema::hasTable(ReturnInspection::TABLE),
            'the ' . ReturnInspection::TABLE . ' migration must have run, or every case here '
                . 'measures the gate\'s fail-open leg instead of the gate'
        );

        $this->loginMerchantOfStoreA();
        $this->allowsRestock();
    }

    protected function tearDown(): void
    {
        Auth::logout();
        Cache::flush();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | The vocabulary, which every door's answer is derived from
    |--------------------------------------------------------------------------
    */

    public function test_only_the_resellable_verdict_returns_goods_to_sale(): void
    {
        $this->assertSame(
            ['resellable' => true, 'damaged' => false, 'quarantined' => false],
            ReturnInspection::VERDICTS,
            'the verdict vocabulary is what every door\'s block decision is derived from'
        );

        /*
         * `quarantined` is NOT resellable, and that is the decision rather than an oversight: "I
         * have not judged these goods yet" is not a licence to sell them. Asserted separately from
         * the map above so that flipping it reads as a deliberate change.
         */
        $this->assertFalse(
            ReturnInspection::VERDICTS[ReturnInspection::VERDICT_QUARANTINED],
            'an unjudged return must not be sellable'
        );
    }

    public function test_the_stored_resellable_column_is_derived_and_not_taken_from_the_caller(): void
    {
        $service = app(ReturnInspection::class);
        $order = $this->makeOrder(self::STORE_A, [$this->line(501, 0, 2)]);

        $this->assertTrue($service->record(self::STORE_A, $order->id, 501, 0, ReturnInspection::VERDICT_DAMAGED, 7));

        $row = DB::table(ReturnInspection::TABLE)->where('order_id', $order->id)->first();

        $this->assertSame(ReturnInspection::VERDICT_DAMAGED, $row->verdict);
        $this->assertSame(
            0,
            (int) $row->resellable,
            'the boolean must come from ReturnInspection::VERDICTS, so it cannot disagree with the verdict'
        );
    }

    public function test_an_unknown_verdict_is_refused_and_stores_nothing(): void
    {
        $service = app(ReturnInspection::class);
        $order = $this->makeOrder(self::STORE_A, [$this->line(501, 0, 2)]);

        /*
         * KEYED ON THE MECHANISM, AND A MUTATION RUN IS WHY.
         *
         * A first version of this case asserted only `false` and a row count of 0. Removing the
         * vocabulary check entirely (`if (false)`) left it GREEN — because an unrecognised verdict
         * then reaches `self::VERDICTS[$verdict]`, raises an undefined-key error, and the
         * `\Throwable` catch answers `false` with no row written. Same outcome, different cause:
         * the test was measuring the catch, not the check.
         *
         * The two branches log different things and only the vocabulary branch names the known
         * verdicts, so that is what this asserts. An exception-shaped refusal now reads as the
         * failure it is.
         */
        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$logged) {
            $logged[] = [$message->level, $message->message, $message->context];
        });

        $this->assertFalse(
            $service->record(self::STORE_A, $order->id, 501, 0, 'slightly-dented', 7),
            'a verdict outside the vocabulary must be refused'
        );
        $this->assertSame(
            0,
            DB::table(ReturnInspection::TABLE)->where('order_id', $order->id)->count(),
            'a refused verdict must not leave a row whose resellable column defaults to 0 — that '
                . 'would block the line on a typo'
        );

        $refusals = array_values(array_filter(
            $logged,
            static fn ($entry) => $entry[0] === 'warning' && isset($entry[2]['known'])
        ));

        $this->assertCount(
            1,
            $refusals,
            'the refusal must come from the vocabulary check — which names the verdicts it knows — '
                . 'and not from the catch that exists for an absent table. Logged: '
                . json_encode(array_map(static fn ($e) => $e[0] . ': ' . $e[1], $logged))
        );
        $this->assertSame(
            array_keys(ReturnInspection::VERDICTS),
            $refusals[0][2]['known'],
            'and it must tell the reader which verdicts the platform does know'
        );
    }

    public function test_a_re_inspection_replaces_the_verdict_rather_than_adding_a_second_one(): void
    {
        $service = app(ReturnInspection::class);
        $order = $this->makeOrder(self::STORE_A, [$this->line(501, 0, 2)]);

        $service->record(self::STORE_A, $order->id, 501, 0, ReturnInspection::VERDICT_QUARANTINED, 7);
        $service->record(self::STORE_A, $order->id, 501, 0, ReturnInspection::VERDICT_RESELLABLE, 7);

        $this->assertSame(
            1,
            DB::table(ReturnInspection::TABLE)->where('order_id', $order->id)->count(),
            'the unique index makes one current verdict per line, so the gate never resolves a '
                . 'disagreement between two rows — the failure mode RefundPolicy documents for '
                . 'order_refund_settings'
        );
        $this->assertFalse(
            $service->blocksRestock(self::STORE_A, $order->id, 501, 0),
            'the replacement verdict is the one that decides'
        );
    }

    public function test_a_re_inspection_keeps_the_time_of_the_first_inspection(): void
    {
        $service = app(ReturnInspection::class);
        $order = $this->makeOrder(self::STORE_A, [$this->line(501, 0, 2)]);

        \Illuminate\Support\Carbon::setTestNow('2026-11-20 09:00:00');
        $service->record(self::STORE_A, $order->id, 501, 0, ReturnInspection::VERDICT_QUARANTINED, 7);

        \Illuminate\Support\Carbon::setTestNow('2026-11-24 15:30:00');
        $service->record(self::STORE_A, $order->id, 501, 0, ReturnInspection::VERDICT_RESELLABLE, 7);

        \Illuminate\Support\Carbon::setTestNow();

        $row = DB::table(ReturnInspection::TABLE)->where('order_id', $order->id)->first();

        /*
         * `record()` uses `upsert()` and names the updatable columns explicitly, with `created_at`
         * deliberately absent from that list. A first version used `updateOrInsert()`, which
         * applies ONE values array to both the insert and the update — so a second verdict rewrote
         * `created_at` and the time the goods were first examined was gone. `created_at` would then
         * have equalled `updated_at` on every row: a column that says nothing while looking as
         * though it says when the item was first inspected.
         */
        $this->assertStringStartsWith(
            '2026-11-20 09:00:00',
            (string) $row->created_at,
            'the time of the FIRST inspection must survive a re-inspection'
        );
        $this->assertStringStartsWith(
            '2026-11-24 15:30:00',
            (string) $row->updated_at,
            'and the current verdict must carry its own time'
        );
    }

    public function test_another_stores_verdict_does_not_decide_this_stores_line(): void
    {
        $service = app(ReturnInspection::class);
        $order = $this->makeOrder(self::STORE_A, [$this->line(501, 0, 2)]);

        /*
         * `order_return_inspections` is NOT in `config/tenancy.php`'s `scoped_models`, so there is
         * no global scope to inherit and every read in the service writes its own `store_id`
         * predicate. This is what asserts that predicate is really there: the same order id and
         * product id, a `damaged` verdict filed under store B, and store A's line must be
         * unaffected in BOTH directions.
         */
        $service->record(self::STORE_B, $order->id, 501, 0, ReturnInspection::VERDICT_DAMAGED, 7);

        $this->assertFalse(
            $service->blocksRestock(self::STORE_A, $order->id, 501, 0),
            'store B\'s verdict must not block store A\'s line'
        );
        $this->assertTrue(
            $service->blocksRestock(self::STORE_B, $order->id, 501, 0),
            'and the control: the verdict really was stored, for store B'
        );
    }

    public function test_an_unreadable_inspection_table_blocks_nothing(): void
    {
        $order = $this->makeOrder(self::STORE_A, [$this->line(501, 0, 2)]);
        app(ReturnInspection::class)->record(self::STORE_A, $order->id, 501, 0, ReturnInspection::VERDICT_DAMAGED, 7);

        $this->assertTrue(
            app(ReturnInspection::class)->blocksRestock(self::STORE_A, $order->id, 501, 0),
            'the control: with the table present the verdict blocks'
        );

        Schema::drop(ReturnInspection::TABLE);

        /*
         * FAIL-OPEN, AND THE OPPOSITE DIRECTION FROM `RefundPolicy::allows()` — stated in
         * `blocksRestock()`'s docblock and pinned here so it is falsifiable.
         *
         * `RefundPolicy` gates an action, so refusing it changes nothing in the world. This gate
         * BLOCKS one, so failing closed would refuse to credit stock a merchant physically holds,
         * on no evidence — a read that threw read no verdicts. Every environment that has not run
         * this slice's migration is this shape, which is why the direction matters rather than
         * being a defensive habit.
         */
        $this->assertFalse(
            app(ReturnInspection::class)->blocksRestock(self::STORE_A, $order->id, 501, 0),
            'an absent table must block nothing: a read that threw read no verdicts, and failing '
                . 'closed would stop every restock in every environment that has not migrated'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DOOR 1 — OrderRefundController::RefundStock()
    |--------------------------------------------------------------------------
    */

    public function test_door_one_restocks_when_no_condition_is_recorded(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, 0, 2)]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 2],
        ]);

        $this->callRefundStock($refund->id);

        $this->assertSame(
            6,
            $this->stockOf($product),
            'with no verdict recorded the refund screen must restock exactly as it did before '
                . 'this slice — every store on the platform is in this state'
        );
    }

    public function test_door_one_blocks_a_line_recorded_as_damaged(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, 0, 2)]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 2],
        ]);

        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, 0, ReturnInspection::VERDICT_DAMAGED, 7);

        $this->callRefundStock($refund->id);

        $this->assertSame(
            4,
            $this->stockOf($product),
            'goods a merchant inspected and recorded as damaged must not become sellable stock'
        );
    }

    public function test_door_one_still_restocks_a_line_recorded_as_resellable(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, 0, 2)]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 2],
        ]);

        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, 0, ReturnInspection::VERDICT_RESELLABLE, 7);

        $this->callRefundStock($refund->id);

        $this->assertSame(
            6,
            $this->stockOf($product),
            'an inspection that passed must put the goods back — a gate that blocked this would be '
                . 'a stock outage, not a control'
        );
    }

    public function test_door_one_blocks_a_damaged_variant_and_leaves_its_sibling_alone(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['variant_product' => 1, 'product_stock' => 0]);
        $damaged = $this->makeVariant($product->id, self::STORE_A, 3);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, $damaged->id, 1)]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 1],
        ]);

        /*
         * The verdict is filed against the VARIANT, not the product, so a second variant of the
         * same product is a different line and a different verdict. Recording the product with
         * `variant_id = 0` must NOT block this line: that is what proves the key is four columns
         * and not three.
         */
        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, 0, ReturnInspection::VERDICT_DAMAGED, 7);

        $this->callRefundStock($refund->id);

        $this->assertSame(
            4,
            $this->variantStockOf($damaged),
            'a verdict filed against the product with no variant must not decide a variant line'
        );

        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, $damaged->id, ReturnInspection::VERDICT_DAMAGED, 7);

        $second = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 1],
        ]);
        $this->callRefundStock($second->id);

        $this->assertSame(
            4,
            $this->variantStockOf($damaged),
            'and with the verdict filed against the variant the restock is blocked'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DOOR 2 — Order::product_return(), the merchant marking ONE line returned
    |--------------------------------------------------------------------------
    */

    public function test_door_two_restocks_when_no_condition_is_recorded(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, 0, 2)], ['delivered_status' => 1]);

        $result = Order::product_return([
            'product_id' => $product->id,
            'variant_id' => 0,
            'order_id' => $order->id,
        ]);

        $this->assertSame('success', $result['status'], $result['message'] ?? '');
        $this->assertSame(
            6,
            $this->stockOf($product),
            'the per-line merchant return must restock exactly as it did before this slice'
        );
    }

    public function test_door_two_blocks_a_line_recorded_as_damaged_but_still_records_the_return(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, 0, 2)], ['delivered_status' => 1]);

        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, 0, ReturnInspection::VERDICT_DAMAGED, 7);

        $result = Order::product_return([
            'product_id' => $product->id,
            'variant_id' => 0,
            'order_id' => $order->id,
        ]);

        $this->assertSame(
            4,
            $this->stockOf($product),
            'damaged goods must not become sellable stock at the per-line return door either'
        );

        /*
         * AND THE RETURN ITSELF STILL HAPPENED — RET-007. The condition gate decides whether the
         * goods go back ON SALE; it must not decide whether the return is RECORDED. Suppressing the
         * record would erase the sales history of a return that physically occurred, which is the
         * opposite decision.
         */
        $this->assertSame('success', $result['status'], $result['message'] ?? '');

        $line = json_decode((string) Order::withoutGlobalScopes()->find($order->id)->product_json, true)[0];
        $this->assertSame(1, (int) $line['return'], 'the line must still be marked returned');
    }

    public function test_door_two_still_restocks_a_line_recorded_as_resellable(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, 0, 2)], ['delivered_status' => 1]);

        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, 0, ReturnInspection::VERDICT_RESELLABLE, 7);

        Order::product_return([
            'product_id' => $product->id,
            'variant_id' => 0,
            'order_id' => $order->id,
        ]);

        $this->assertSame(6, $this->stockOf($product), 'an inspection that passed must restock');
    }

    /*
    |--------------------------------------------------------------------------
    | DOOR 3 — OrderController::order_return_request(), the whole-order approval
    |--------------------------------------------------------------------------
    */

    public function test_door_three_restocks_when_no_condition_is_recorded(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, 0, 2)]);

        $this->approveWholeOrderReturn($order);

        $this->assertSame(
            6,
            $this->stockOf($product),
            'the whole-order return approval must restock exactly as it did before this slice'
        );
    }

    public function test_door_three_blocks_a_line_recorded_as_damaged(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, 0, 2)]);

        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, 0, ReturnInspection::VERDICT_DAMAGED, 7);

        $this->approveWholeOrderReturn($order);

        $this->assertSame(
            4,
            $this->stockOf($product),
            'damaged goods must not become sellable stock at the whole-order approval door either'
        );

        /* The approval itself still stands — the return is recorded, only the shelf is withheld. */
        $this->assertSame(
            2,
            (int) Order::withoutGlobalScopes()->find($order->id)->return_status,
            'the return approval must still be recorded'
        );
    }

    public function test_door_three_blocks_only_the_damaged_line_of_a_two_line_order(): void
    {
        $damagedProduct = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $goodProduct = $this->makeProduct(self::STORE_A, ['product_stock' => 7]);
        $order = $this->makeOrder(self::STORE_A, [
            $this->line($damagedProduct->id, 0, 2),
            $this->line($goodProduct->id, 0, 3),
        ]);

        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $damagedProduct->id, 0, ReturnInspection::VERDICT_DAMAGED, 7);

        $this->approveWholeOrderReturn($order);

        $this->assertSame(4, $this->stockOf($damagedProduct), 'the damaged line is withheld');
        $this->assertSame(
            10,
            $this->stockOf($goodProduct),
            'and the line nobody judged is credited — the gate is per line, not per order'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RET-006 — the gate must not break, or be broken by, the no-double-credit rule
    |--------------------------------------------------------------------------
    */

    public function test_a_block_then_a_re_inspection_credits_the_line_exactly_once_across_both_doors(): void
    {
        /*
         * THE INTERACTION THIS SLICE COULD HAVE GOT WRONG, in both directions, and neither would
         * have shown up in the per-door cases above.
         *
         * RET-006 is *"منع تكرار رد الأموال أو تعديل المخزون"* — no duplicate refunds and no
         * duplicate stock adjustments — and the existing machinery for it is a per-line marker
         * (`Order::LINE_STOCK_RESTORED`), which `OrderReturnWorkflowTest::
         * test_the_two_endpoints_do_not_credit_the_same_line_twice` pins across the two doors.
         *
         * A blocked line must therefore be left UNMARKED:
         *
         *   - mark it and the credit is lost FOREVER. Once the item is re-inspected as resellable,
         *     every door reads the marker as "the goods are already back" and credits nothing —
         *     stock the merchant physically holds, permanently invisible. This is the `GAP-140`
         *     failure direction one door along, where a marker on a zero-quantity line made the
         *     refund door credit nothing at all.
         *   - leave it unmarked and the risk is the opposite one: two doors each crediting the same
         *     units once the block lifts.
         *
         * So this drives the whole sequence — block, re-inspect, then BOTH doors — and asserts the
         * units arrive exactly once.
         */
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [$this->line($product->id, 0, 2)], ['delivered_status' => 1]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 2],
        ]);

        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, 0, ReturnInspection::VERDICT_DAMAGED, 7);

        $this->callRefundStock($refund->id);
        $this->assertSame(4, $this->stockOf($product), 'premise: the damaged line is withheld');

        $line = json_decode((string) Order::withoutGlobalScopes()->find($order->id)->product_json, true)[0];
        $this->assertArrayNotHasKey(
            Order::LINE_STOCK_RESTORED,
            $line,
            'a withheld line must NOT be marked restored: the marker would claim the goods are '
                . 'back and suppress the credit for good once the item is re-inspected'
        );

        /* The merchant examines it again and passes it. */
        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, 0, ReturnInspection::VERDICT_RESELLABLE, 7);

        $this->callRefundStock($refund->id);
        $this->assertSame(
            6,
            $this->stockOf($product),
            'the credit withheld earlier must still be available after a passing re-inspection'
        );

        /* And now the OTHER door, on the same line. */
        Order::product_return([
            'product_id' => $product->id,
            'variant_id' => 0,
            'order_id' => $order->id,
        ]);

        $this->assertSame(
            6,
            $this->stockOf($product),
            'RET-006: the second door must credit nothing — the units arrived once, and the '
                . 'condition gate must not have disturbed the no-double-credit rule'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> one product_json line */
    private function line(int $productId, int $variantId, int $qty): array
    {
        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'qty' => $qty,
            'price' => 10,
            'final_price' => 10 * $qty,
            'name' => 'Line ' . $productId,
        ];
    }

    private function callRefundStock($refundId)
    {
        $request = Request::create('/refund-stock', 'POST', ['refundId' => $refundId]);
        $request->setLaravelSession(app('session.store'));

        return (new OrderRefundController())->RefundStock($request);
    }

    /**
     * The whole-order return approval, driven through the handler rather than the route.
     *
     * CLAUDE.md: *a handler-level check behind an `abort()`ing middleware is invisible to an HTTP
     * test*. The authorization on this door is `$this->authorize('updateStatus', $order)` INSIDE
     * the handler, and the signed-in principal here is a `super admin`, which `OrderPolicy::allow`
     * admits on its operator-first limb. Driving the handler is what makes the stock figures the
     * subject of these cases rather than a redirect.
     */
    private function approveWholeOrderReturn(Order $order)
    {
        $request = Request::create('/order-return-request', 'POST', [
            'id' => $order->id,
            'status' => 2,
        ]);
        $request->setLaravelSession(app('session.store'));

        return (new OrderController())->order_return_request($request);
    }

    private function stockOf(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    private function variantStockOf(ProductVariant $variant): int
    {
        return (int) ProductVariant::withoutGlobalScopes()->find($variant->id)->stock;
    }

    /**
     * `super admin` so `canManageRefunds()` and `OrderPolicy::allow` both pass on their
     * operator-first limb, which is seed-independent — the `Manage Order` permission row is
     * created by `Utility::addNewData()` and that seeder is not run here. The same choice
     * `RefundStockIdempotencyTest` makes, for the same reason.
     */
    private function loginMerchantOfStoreA(): void
    {
        DB::table('users')->insert([
            'id' => 7,
            'name' => 'Merchant A',
            'email' => 'merchant-a@example.test',
            'type' => \App\Policies\UserPolicy::PLATFORM_ADMIN,
            'password' => bcrypt('secret'),
            'current_store' => self::STORE_A,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Auth::login(User::withoutGlobalScopes()->find(7));

        $this->assertSame(
            self::STORE_A,
            (int) getCurrentStore(),
            'the fixture must resolve to store A, or every ownership assertion is vacuous'
        );

        DB::table('customers')->insert([
            'id' => 1,
            'first_name' => 'Return',
            'last_name' => 'Buyer',
            'email' => 'return-buyer@example.test',
            'store_id' => self::STORE_A,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function allowsRestock(): void
    {
        DB::table('order_refund_settings')->insert([
            'store_id' => self::STORE_A,
            'user_id' => 7,
            'name' => \App\Services\RefundPolicy::MANAGE_STOCK,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeProduct(int $storeId, array $attributes = []): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'name' => 'Product ' . uniqid('', false),
            'track_stock' => 1,
            'stock_order_status' => 'not_allow',
            'price' => 10,
            'product_stock' => 0,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => 7,
        ], $attributes));
        $product->save();

        return $product;
    }

    private function makeVariant(int $productId, int $storeId, int $stock): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->forceFill([
            'product_id' => $productId,
            'variant' => 'Variant ' . uniqid('', false),
            'sku' => 'sku-' . uniqid('', false),
            'price' => 10,
            'stock' => $stock,
            'stock_order_status' => 'not_allow',
            'store_id' => $storeId,
        ]);
        $variant->save();

        return $variant;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function makeOrder(int $storeId, array $lines, array $attributes = []): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'product_order_id' => 'ORD-' . uniqid('', false),
            'order_date' => now(),
            'customer_id' => 1,
            'product_json' => json_encode($lines),
            'product_id' => implode(',', array_column($lines, 'product_id')),
            'final_price' => 100,
            'product_price' => 100,
            'delivery_price' => 0,
            'tax_price' => 0,
            'coupon_price' => 0,
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            'store_id' => $storeId,
        ], $attributes));
        $order->save();

        return $order;
    }

    private function makeRefund(Order $order, int $storeId, array $refundLines, string $status = 'Accept'): OrderRefund
    {
        $refund = new OrderRefund();
        $refund->forceFill([
            'order_id' => $order->id,
            'refund_status' => $status,
            'refund_reason' => 'Other',
            'product_refund_id' => json_encode($refundLines),
            'product_refund_price' => 0,
            'refund_amount' => 0,
            'store_id' => $storeId,
        ]);
        $refund->save();

        return $refund;
    }
}
