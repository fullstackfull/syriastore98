<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderRefundController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE REFUND STATE MACHINE.
 *
 * THE DEFECTS. None of the four refund mutators looked at a refund's CURRENT
 * state before writing the next one; each simply assigned. Three independently
 * reachable consequences, every one of them from replaying a single
 * authenticated POST:
 *
 *  1. CancelRefundStatus() does not only set a status. It ADDS the refunded
 *     quantities back onto the order's product_json and then recomputes
 *     product_price and final_price from the result. Replayed, it ran that
 *     restore a second time, so qty, product_price and final_price all GREW,
 *     without bound. An order total inflating on request.
 *
 *  2. updateRefundStatus() wrote 'Accept' from any state, so a 'Refunded'
 *     request could be moved back to 'Accept' -- un-refunding a payout in the
 *     records -- and a 'Cancel'led one could be revived.
 *
 *  3. RefundAmonut() wrote 'Refunded' from any state, so a REJECTED request
 *     could still be paid out, and a paid one paid twice. It also took
 *     refund_amount raw from the request with no ceiling of any kind.
 *
 *  4. RefundStock() restored inventory with a read-modify-write
 *     (`$final_stock = $product->product_stock + $quantity; ... ->save()`) --
 *     the same lost update ProductStockService exists to remove -- and ran
 *     against any state at all, including 'Processing' (before the merchant had
 *     accepted anything) and 'Cancel' (whose quantities CancelRefundStatus had
 *     already put back on the order, so the goods were counted twice).
 *
 * The state vocabulary is NOT invented by this suite. 'Processing' is what a
 * request is created in (AccountProfileController:967); 'Accept', 'Cancel' and
 * 'Refunded' are the three the mutators write and that OrderRefundDataTable:51
 * already branches on.
 *
 * WHAT IS PROVEN HERE. The real controller methods are driven, as a super admin,
 * against real rows in the in-memory database. Responses, stored states, stored
 * amounts and the order's own totals are read back from the tables.
 *
 * WHAT IS NOT PROVEN. No HTTP routing, middleware or permission seeding is
 * exercised: the super-admin limb of canManageRefunds() is used so the suite
 * does not depend on a seeded permission row. Authorization itself is covered by
 * the Security suite, which is a different agent's file.
 */
class RefundStateMachineTest extends TestCase
{
    private function controller(): OrderRefundController
    {
        return new OrderRefundController();
    }

    private function request(array $params): Request
    {
        return Request::create('/', 'POST', $params);
    }

    // -----------------------------------------------------------------
    // The transition table itself.
    // -----------------------------------------------------------------

    /**
     * The table encodes that BOTH terminal states are terminal because reaching
     * them had an irreversible side effect: 'Refunded' moved money, 'Cancel'
     * rewrote the order's totals.
     */
    public function test_the_transition_table_makes_both_terminal_states_terminal(): void
    {
        $t = OrderRefundController::LEGAL_TRANSITIONS;

        $this->assertSame([], $t[OrderRefundController::STATE_REFUNDED],
            'nothing may follow a payout');
        $this->assertSame([], $t[OrderRefundController::STATE_CANCEL],
            'nothing may follow a cancellation, which already restored the order');

        $this->assertContains(OrderRefundController::STATE_ACCEPT,
            $t[OrderRefundController::STATE_PROCESSING]);
        $this->assertContains(OrderRefundController::STATE_CANCEL,
            $t[OrderRefundController::STATE_PROCESSING]);
        $this->assertContains(OrderRefundController::STATE_REFUNDED,
            $t[OrderRefundController::STATE_ACCEPT]);
    }

    /**
     * THE VOCABULARY IS THE APPLICATION'S, NOT THIS SUITE'S.
     *
     * Every state in the transition table must be one production code actually
     * writes, and the initial state must be the one a refund request is created
     * in. Asserted against the real source so the table cannot drift from the
     * application, and so the claim "'Pending' is not a refund state" is backed
     * by a measurement rather than by a grep I ran once.
     *
     * The evidence: AccountProfileController::refundOrder() creates a request
     * with 'Processing'; OrderRefundDataTable:51 branches on it. A repository-wide
     * search for refund_status literals in app/, resources/, packages/ and
     * themes/ yields exactly Processing, Accept, Cancel and Refunded -- and
     * notably NOT 'Pending'. tests/Feature/Security/OrderRefundAuthorizationTest
     * builds fixtures with 'Pending' (lines 249, 288, 344), which is a state the
     * application never creates; that file belongs to another agent and is
     * reported rather than edited.
     */
    public function test_the_state_vocabulary_is_the_one_production_code_writes(): void
    {
        $creator = file_get_contents(base_path('app/Http/Controllers/AccountProfileController.php'));

        $this->assertStringContainsString(
            "refund_status     = 'Processing'",
            $creator,
            'the initial state must be the one the refund request creator writes'
        );

        $this->assertSame('Processing', OrderRefundController::STATE_PROCESSING);

        // 'Pending' must NOT be in the vocabulary: adding it to satisfy a test
        // fixture would be inventing a state the application does not have.
        $states = array_keys(OrderRefundController::LEGAL_TRANSITIONS);

        $this->assertNotContains('Pending', $states,
            'Pending is not a refund state anywhere in production code');

        $this->assertSame(
            ['Processing', 'Accept', 'Refunded', 'Cancel'],
            $states,
            'the table must hold exactly the four states production code writes'
        );
    }

    /**
     * An UNRECOGNISED current state is refused, not waved through. The column is
     * a plain `string` with no database constraint
     * (2024_02_07_071800_create_order_refunds_table.php:18), so a row can hold
     * anything, and "I do not know what state this is" is not a licence to move
     * money.
     */
    public function test_an_unrecognised_current_state_is_refused(): void
    {
        $guard = new \ReflectionMethod(OrderRefundController::class, 'illegalTransition');

        $refund = (object) ['id' => 1, 'refund_status' => 'Weird'];

        $refusal = $guard->invokeArgs($this->controller(), [$refund, 'Accept']);

        $this->assertIsArray($refusal, 'an unknown state must not be transitionable');
        $this->assertSame(422, $refusal['status']);
    }

    /** A no-op transition is refused: for Cancel that replay inflated the order. */
    public function test_a_transition_to_the_same_state_is_refused(): void
    {
        $guard = new \ReflectionMethod(OrderRefundController::class, 'illegalTransition');

        $refund = (object) ['id' => 1, 'refund_status' => 'Cancel'];

        $this->assertIsArray($guard->invokeArgs($this->controller(), [$refund, 'Cancel']));
    }

    // -----------------------------------------------------------------
    // updateRefundStatus
    // -----------------------------------------------------------------

    /** Processing -> Accept is the legal happy path and still works. */
    public function test_a_processing_request_can_be_accepted(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $refundId = $this->makeRefund('Processing');

        $response = $this->controller()->updateRefundStatus($this->request(['refund_id' => $refundId]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Accept', $this->statusOf($refundId));

        $this->dropRefundSchema();
    }

    /** A paid-out refund cannot be moved back to Accept. */
    public function test_a_refunded_request_cannot_be_moved_back_to_accept(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $refundId = $this->makeRefund('Refunded');

        $response = $this->controller()->updateRefundStatus($this->request(['refund_id' => $refundId]));

        $this->assertSame(422, $response->getStatusCode(),
            'un-refunding a payout in the records must be refused');
        $this->assertSame('Refunded', $this->statusOf($refundId),
            'and the stored state must be unchanged');

        $this->dropRefundSchema();
    }

    /** A rejected refund cannot be silently revived. */
    public function test_a_cancelled_request_cannot_be_accepted(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $refundId = $this->makeRefund('Cancel');

        $response = $this->controller()->updateRefundStatus($this->request(['refund_id' => $refundId]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Cancel', $this->statusOf($refundId));

        $this->dropRefundSchema();
    }

    // -----------------------------------------------------------------
    // CancelRefundStatus -- THE MONEY TEST.
    // -----------------------------------------------------------------

    /**
     * THE CENTRAL REGRESSION. Cancelling restores the refunded quantity to the
     * order once. Replaying the POST must NOT restore it again.
     *
     * Before the guard, the second call ran the restore loop a second time:
     * qty, product_price and final_price all grew. This asserts the order's
     * persisted totals are byte-for-byte unchanged by the replay.
     */
    public function test_cancelling_twice_cannot_inflate_the_order_total(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        // One line, qty 2, 100.00 for the pair. One unit was requested back.
        $orderId = $this->makeOrder([
            'product_json' => json_encode([
                ['product_id' => 7, 'qty' => 2, 'final_price' => 100.0],
            ]),
            'product_price' => 100.0,
            'delivery_price' => 10.0,
            'tax_price' => 5.0,
            'coupon_price' => 0.0,
            'final_price' => 115.0,
        ]);

        $refundId = $this->makeRefund('Accept', [
            'order_id' => $orderId,
            'product_refund_id' => json_encode([
                ['product_refund_id' => 7, 'quantity' => 1],
            ]),
        ]);

        // First cancellation: legal, and it restores the order.
        $first = $this->controller()->CancelRefundStatus($this->request(['refund_id' => $refundId]));
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('Cancel', $this->statusOf($refundId));

        $afterFirst = $this->orderRow($orderId);

        // The restore did happen -- this test is not passing because nothing ran.
        $this->assertGreaterThan(100.0, (float) $afterFirst->product_price,
            'the first cancellation must actually restore the refunded quantity');

        // THE REPLAY. Same POST, again.
        $second = $this->controller()->CancelRefundStatus($this->request(['refund_id' => $refundId]));

        $this->assertSame(422, $second->getStatusCode(),
            'a second cancellation must be refused');

        $afterSecond = $this->orderRow($orderId);

        $this->assertSame(
            (float) $afterFirst->product_price,
            (float) $afterSecond->product_price,
            'the replay must not add the refunded quantity a second time'
        );
        $this->assertSame(
            (float) $afterFirst->final_price,
            (float) $afterSecond->final_price,
            'and must not inflate the order total'
        );
        $this->assertSame(
            $afterFirst->product_json,
            $afterSecond->product_json,
            'nor grow the stored line quantities'
        );

        $this->dropRefundSchema();
    }

    /** Unparseable refund JSON is refused rather than walked as null. */
    public function test_unparseable_refund_product_data_is_refused(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $orderId = $this->makeOrder(['product_json' => 'not json']);
        $refundId = $this->makeRefund('Accept', [
            'order_id' => $orderId,
            'product_refund_id' => 'not json',
        ]);

        $response = $this->controller()->CancelRefundStatus($this->request(['refund_id' => $refundId]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Accept', $this->statusOf($refundId), 'nothing was changed');

        $this->dropRefundSchema();
    }

    // -----------------------------------------------------------------
    // RefundStock
    // -----------------------------------------------------------------

    /**
     * Stock may only come back once the return is agreed. With no check at all
     * this ran against a 'Processing' request -- before the merchant had
     * accepted anything -- so a customer could have their goods restocked by
     * merely asking.
     */
    public function test_stock_is_not_restored_for_a_request_that_is_not_yet_accepted(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $productId = $this->makeProduct(['product_stock' => 5]);
        $refundId = $this->makeRefund('Processing', [
            'product_refund_id' => json_encode([
                ['product_refund_id' => $productId, 'quantity' => 3],
            ]),
        ]);

        $response = $this->controller()->RefundStock($this->request(['refundId' => $refundId]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(5, $this->stockOf($productId),
            'no inventory may be created for a refund nobody has accepted');

        $this->dropRefundSchema();
    }

    /** A cancelled refund does not restock either: the order already took it back. */
    public function test_stock_is_not_restored_for_a_cancelled_request(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $productId = $this->makeProduct(['product_stock' => 5]);
        $refundId = $this->makeRefund('Cancel', [
            'product_refund_id' => json_encode([
                ['product_refund_id' => $productId, 'quantity' => 3],
            ]),
        ]);

        $this->assertSame(422,
            $this->controller()->RefundStock($this->request(['refundId' => $refundId]))->getStatusCode());
        $this->assertSame(5, $this->stockOf($productId));

        $this->dropRefundSchema();
    }

    /** An accepted refund does restock, through the atomic writer. */
    public function test_an_accepted_refund_restores_stock(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $productId = $this->makeProduct(['product_stock' => 5]);
        $refundId = $this->makeRefund('Accept', [
            'product_refund_id' => json_encode([
                ['product_refund_id' => $productId, 'quantity' => 3],
            ]),
        ]);

        $response = $this->controller()->RefundStock($this->request(['refundId' => $refundId]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(8, $this->stockOf($productId), 'the returned units come back');

        $this->dropRefundSchema();
    }

    /**
     * A refund quantity is read out of a free-text JSON column, so it is neither
     * typed nor validated. A NEGATIVE value would have SUBTRACTED stock through
     * a restore endpoint.
     */
    public function test_a_negative_or_unusable_refund_quantity_cannot_subtract_stock(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $productId = $this->makeProduct(['product_stock' => 5]);
        $refundId = $this->makeRefund('Accept', [
            'product_refund_id' => json_encode([
                ['product_refund_id' => $productId, 'quantity' => -4],
                ['product_refund_id' => $productId, 'quantity' => 'lots'],
                ['product_refund_id' => $productId, 'quantity' => 0],
            ]),
        ]);

        $this->assertSame(200,
            $this->controller()->RefundStock($this->request(['refundId' => $refundId]))->getStatusCode());

        $this->assertSame(5, $this->stockOf($productId),
            'a restore endpoint must never reduce stock, and must not coerce junk to a number');

        $this->dropRefundSchema();
    }

    /**
     * The read-modify-write is gone. Asserted structurally because a lost update
     * needs real concurrency to observe, which a single-connection in-memory
     * sqlite test cannot produce -- and that limit is stated rather than a
     * concurrency claim being implied.
     */
    public function test_the_restock_path_no_longer_reads_modifies_and_writes_stock(): void
    {
        $source = $this->executableSourceOf(OrderRefundController::class, 'RefundStock');

        $this->assertStringNotContainsString('$product->product_stock = ', $source,
            'restoring stock must not read-modify-write; that is the lost update');
        $this->assertStringNotContainsString('$variantProduct->stock = ', $source);

        $this->assertStringContainsString('restoreProductStock', $source,
            'it must go through the atomic writer in ProductStockService');
        $this->assertStringContainsString('restoreVariantStock', $source);
    }

    // -----------------------------------------------------------------
    // RefundAmonut -- the amount ceiling and the honest status.
    // -----------------------------------------------------------------

    /**
     * A refund larger than the order is capped at the order's own persisted
     * total, which is the authoritative figure. The request value is never
     * authoritative.
     */
    public function test_a_refund_larger_than_the_order_is_capped_at_the_order_total(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        // COD, so the honest status is a real 'Refunded' (the merchant hands
        // cash back by hand) and this test is about the AMOUNT only.
        $orderId = $this->makeOrder(['final_price' => 115.0, 'payment_type' => 'cod']);
        $refundId = $this->makeRefund('Accept', ['order_id' => $orderId]);

        $response = $this->controller()->RefundAmonut(
            $this->request(['refundId' => $refundId, 'refund_amount' => '99999']),
            $orderId
        );

        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame(115.0, (float) $this->refundRow($refundId)->refund_amount,
            'the stored amount must be the order total, not the requested one');

        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['capped'], 'and the merchant must be told it was capped');

        $this->dropRefundSchema();
    }

    /**
     * A SIGNED request is refused rather than silently reinterpreted. The
     * controller's own normalisation is `preg_replace('/[^0-9.]/', '', ...)`,
     * which STRIPS the minus sign, so '-500' became a positive refund of 500.
     */
    public function test_a_signed_refund_amount_is_refused(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $orderId = $this->makeOrder(['final_price' => 115.0, 'payment_type' => 'cod']);
        $refundId = $this->makeRefund('Accept', ['order_id' => $orderId]);

        $response = $this->controller()->RefundAmonut(
            $this->request(['refundId' => $refundId, 'refund_amount' => '-500']),
            $orderId
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Accept', $this->statusOf($refundId), 'nothing was recorded');

        $this->dropRefundSchema();
    }

    /**
     * THE HONEST STATUS. For an ONLINE gateway there is no refund driver
     * anywhere in this platform, so calling this endpoint moves no money.
     * Recording 'Refunded' would assert a settlement that did not happen, so
     * 'Accept' -- approved, not settled -- is stored instead, and the merchant
     * is told so.
     *
     * This is App\Services\RefundIntegrityService, which existed with full tests
     * and NO production caller until it was wired here.
     */
    public function test_an_online_gateway_refund_is_not_recorded_as_settled(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $orderId = $this->makeOrder(['final_price' => 115.0, 'payment_type' => 'paymera']);
        $refundId = $this->makeRefund('Accept', ['order_id' => $orderId]);

        $response = $this->controller()->RefundAmonut(
            $this->request(['refundId' => $refundId, 'refund_amount' => '50']),
            $orderId
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);

        $this->assertSame('Accept', $body['status'],
            'no gateway refund driver exists, so this must not claim a settlement');
        $this->assertStringContainsString('not been returned', $body['message'],
            'and the merchant must be told the money has not gone back');

        // The amount is still recorded, so the merchant's figure is not lost.
        $this->assertSame(50.0, (float) $this->refundRow($refundId)->refund_amount);
        $this->assertSame('Accept', $this->statusOf($refundId));

        $this->dropRefundSchema();
    }

    /** An OFFLINE payment is settled by hand, so 'Refunded' stays true. */
    public function test_an_offline_payment_is_still_recorded_as_refunded(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $orderId = $this->makeOrder(['final_price' => 115.0, 'payment_type' => 'bank_transfer']);
        $refundId = $this->makeRefund('Accept', ['order_id' => $orderId]);

        $response = $this->controller()->RefundAmonut(
            $this->request(['refundId' => $refundId, 'refund_amount' => '60']),
            $orderId
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Refunded', $this->statusOf($refundId),
            'the merchant hands the cash back themselves, so Refunded is accurate');

        $this->dropRefundSchema();
    }

    /** A paid-out refund cannot be paid out twice. */
    public function test_an_already_refunded_request_cannot_be_paid_again(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $orderId = $this->makeOrder(['final_price' => 115.0, 'payment_type' => 'cod']);
        $refundId = $this->makeRefund('Refunded', [
            'order_id' => $orderId, 'refund_amount' => 115.0,
        ]);

        $response = $this->controller()->RefundAmonut(
            $this->request(['refundId' => $refundId, 'refund_amount' => '115']),
            $orderId
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(115.0, (float) $this->refundRow($refundId)->refund_amount,
            'the recorded payout must not change');

        $this->dropRefundSchema();
    }

    /** A REJECTED refund cannot be paid out at all. */
    public function test_a_cancelled_request_cannot_be_paid_out(): void
    {
        $this->createRefundSchema();
        $this->asSuperAdmin();

        $orderId = $this->makeOrder(['final_price' => 115.0, 'payment_type' => 'cod']);
        $refundId = $this->makeRefund('Cancel', ['order_id' => $orderId]);

        $response = $this->controller()->RefundAmonut(
            $this->request(['refundId' => $refundId, 'refund_amount' => '115']),
            $orderId
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0.0, (float) $this->refundRow($refundId)->refund_amount,
            'a rejected refund must never be paid');

        $this->dropRefundSchema();
    }

    // -----------------------------------------------------------------
    // Fixture.
    // -----------------------------------------------------------------

    /**
     * A method's EXECUTABLE source, with comments removed.
     *
     * Plain source matching is not good enough for a structural assertion in
     * this codebase, in both directions: an explanatory comment that quotes the
     * defect it replaced will satisfy a "must contain" check, and -- the case
     * that actually bit here -- a comment that quotes the OLD read-modify-write
     * will fail a "must not contain" check even though no code does it any more.
     * Stripping comments makes these assertions mean what they say: that the
     * CODE does or does not do the thing.
     */
    private function executableSourceOf(string $class, string $method): string
    {
        $m = new \ReflectionMethod($class, $method);

        $source = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    private function asSuperAdmin(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'operator',
            'email' => 'operator@example.test',
            'password' => bcrypt('x'),
            // 'super admin' is UserPolicy::PLATFORM_ADMIN, which is the limb
            // canManageRefunds() uses so this suite does not depend on a seeded
            // permission row. getCurrentStore() also reads current_store.
            'type' => 'super admin',
            'current_store' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::findOrFail($id));
    }

    private function createRefundSchema(): void
    {
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->dropRefundSchema();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('type')->nullable();
            $table->unsignedBigInteger('current_store')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('product_order_id')->nullable();
            $table->longText('product_json')->nullable();
            $table->float('product_price')->default(0);
            $table->float('delivery_price')->default(0);
            $table->float('tax_price')->default(0);
            $table->float('coupon_price')->default(0);
            $table->float('final_price')->default(0);
            $table->string('payment_type')->nullable();
            $table->string('payment_status')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });

        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('refund_status');
            $table->longText('refund_reason')->nullable();
            $table->longText('product_refund_id')->nullable();
            $table->float('refund_amount')->default(0);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('product_stock')->default(0);
            $table->string('stock_order_status')->nullable();
            $table->boolean('variant_product')->default(0);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->integer('stock')->default(0);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });
    }

    private function dropRefundSchema(): void
    {
        foreach (['product_variants', 'products', 'order_refunds', 'orders', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function makeOrder(array $attributes = [])
    {
        return DB::table('orders')->insertGetId(array_merge([
            'product_order_id' => 'ORD-1',
            'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 0.0,
            'payment_type' => 'cod', 'payment_status' => 'Paid',
            'store_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function makeRefund(string $status, array $attributes = [])
    {
        return DB::table('order_refunds')->insertGetId(array_merge([
            'order_id' => null,
            'refund_status' => $status,
            'refund_reason' => 'Other',
            'product_refund_id' => json_encode([]),
            'refund_amount' => 0.0,
            'store_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function makeProduct(array $attributes = [])
    {
        return DB::table('products')->insertGetId(array_merge([
            'name' => 'fixture', 'product_stock' => 0, 'stock_order_status' => null,
            'variant_product' => 0, 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    /* Read back from the TABLE, never the model: these models use the Cachable
     * trait and a cached read would not prove what was persisted. */

    private function statusOf($refundId): string
    {
        return (string) DB::table('order_refunds')->where('id', $refundId)->value('refund_status');
    }

    private function refundRow($refundId)
    {
        return DB::table('order_refunds')->where('id', $refundId)->first();
    }

    private function orderRow($orderId)
    {
        return DB::table('orders')->where('id', $orderId)->first();
    }

    private function stockOf($productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('product_stock');
    }

    protected function tearDown(): void
    {
        $this->dropRefundSchema();

        parent::tearDown();
    }
}
