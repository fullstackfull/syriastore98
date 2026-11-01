<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Product;
use App\Services\OrderReplacement;
use App\Services\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * E13 REQUIREMENT D — THE SEVEN TESTS THE DESIGN NAMES, plus the one it does not.
 * ==============================================================================
 *
 * `E13-CANCEL-AND-REPLACEMENT-DESIGN.md` §7 lists what must be proved: the pair's net stock
 * movement is `-N` and not `-2N`; exactly one financial reversal; the original's lines and totals
 * byte-identical afterwards; a replayed call produces no second replacement; a cross-tenant
 * original is refused; and the `UNIQUE (order_id)` constraint exists in the test schema first.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * [2026-11-01] THIS BLOCK USED TO SAY THE OPERATION WAS UNREACHABLE. IT IS NOT, AND LEAVING THAT
 * SENTENCE HERE WOULD REPEAT THE EXACT MISTAKE E13 HAS ALREADY PRODUCED ONCE.
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * What stood here said *"There is no route. That is deliberate — design §6's Q1 is unanswered"*.
 * **D-32 (owner, 2026-10-31) answered Q1** with the narrowest of the design's three readings —
 * identical products, identical quantities, identical prices — which is what makes Q2 (a
 * partially-paid original) and Q3 (original prices or today's) *fall away*, so opening the door
 * invents no refund, settlement or stock rule. Different-item replacement is Phase 04.
 *
 * `POST order-replace/{id}` exists, `OrderController::order_replace()` handles it, and the
 * merchant reaches it from `order/view.blade.php`. The last six cases in this file drive that
 * route; the ones before them call the service directly, because a direct call isolates the
 * operation's own behaviour from the middleware stack.
 *
 * **Why the correction is recorded rather than quietly made:** a stale cell in
 * `PHASE-02-TASK-LEDGER.md` saying this workflow did not exist is what led me to report to the
 * owner that E13 had no replacement engine. The owner corrected me. A docblock that says
 * "unreachable" about a reachable, merchant-facing, money-adjacent operation is the same defect
 * one file over, and it would be read by whoever next decides whether this path is safe.
 */
class OrderReplacementTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * THE ACTOR MUST BE AUTHENTICATED, and discovering that corrected a test of mine that was
     * passing for the wrong reason.
     *
     * `OrderPolicy::sameStore()` resolves the caller's store through `getCurrentStore()`, which
     * reads `auth()->user()->current_store` — so calling the service with no authenticated user
     * refuses EVERYTHING. My first version did exactly that: six cases failed as expected, and the
     * cross-tenant case **passed while measuring nothing**, because the intruder was refused for
     * being unauthenticated rather than for being an intruder.
     *
     * `actingAs()` is also the faithful shape: this is a merchant-initiated operation and in
     * production it runs inside an authenticated request.
     */
    private function actor($merchant): \App\Models\User
    {
        $actor = \App\Models\User::find($merchant->id);
        $this->grantPermission($actor, 'Manage Order');
        $this->actingAs($actor);

        return $actor->fresh();
    }

    private function productIn($store, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => 'e13d-' . uniqid(), 'slug' => 'e13d-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100, 'sale_price' => 100, 'stock_status' => 'in_stock',
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function orderIn($store, Product $product, int $qty = 2, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'product_order_id' => 'ORD-' . strtoupper(uniqid()),
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $qty,
                'price' => 100,
                'variant_id' => 0,
            ]]),
            'product_id' => (string) $product->id,
            'product_price' => 200,
            'coupon_price' => 0,
            'delivery_price' => 0,
            'tax_price' => 0,
            'final_price' => 200,
            'payment_type' => 'cod',
            /*
             * D-15 CONDITION 6 — THE FIXTURE IS UNPAID, AND IT USED TO BE `paid`.
             *
             * Every success case in this file was written against a `paid` order, and every one of
             * them passed — because the service had no payment test at all. When the gate landed,
             * ten of these cases went red at once. That is the measurement that says the old green
             * was green against an unsafe path, so the fixture moves rather than the gate.
             */
            'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ], $overrides));
    }

    /**
     * D-15 CONDITIONS 4 AND 5 — MAKE THE ORIGINAL'S OUT-MOVEMENT PROVABLE.
     *
     * Without this the service reads `unknown` for every line and refuses, which is correct: an
     * order nothing observed is an order whose stock consumption cannot be claimed. So a test that
     * wants the SAFE path has to supply the evidence the safe path requires, and that is the point
     * of the helper being explicit rather than folded into `orderIn()`.
     *
     * `variant_id` is coerced to NULL rather than 0, because `movedOutFor()` matches a
     * product-only line with `whereNull('variant_id')` while `record()` would store a literal 0 —
     * so a 0 here records a row the lookup can never find, and the test would read `unknown` while
     * looking like it had seeded evidence.
     */
    private function proveStockMovedOut(Order $order): void
    {
        foreach ((array) json_decode((string) $order->product_json, true) as $line) {
            StockMovement::record([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'product_id' => $line['product_id'] ?? null,
                'variant_id' => ($line['variant_id'] ?? null) ?: null,
                'direction' => StockMovement::OUT,
                'quantity' => (int) ($line['quantity'] ?? 1),
                'applied' => true,
                'reason' => StockMovement::REASON_ORDER_PLACE,
            ]);
        }
    }

    public function test_premise_the_additive_column_is_installed_in_this_suite(): void
    {
        /* Without it every case below would be answered by REFUSED_COLUMN_ABSENT and would pass
           for the wrong reason — the operation would never run at all. */
        $this->assertTrue(Schema::hasColumn('orders', 'replaced_order_id'));
        $this->assertTrue(OrderReplacement::available());
    }

    public function test_a_replacement_is_created_and_linked_to_its_original(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product);
        $this->proveStockMovedOut($original);

        [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);

        $this->assertSame(OrderReplacement::REPLACED, $state, 'the replacement was refused');
        $this->assertNotNull($replacement);
        $this->assertSame((int) $original->id, (int) $replacement->replaced_order_id, 'the link was not written');
        $this->assertSame((int) $store->id, (int) $replacement->store_id, 'the replacement went to the wrong store');

        /* The original is cancelled -- that is the point of the operation. */
        $this->assertSame(2, (int) $original->fresh()->delivered_status, 'the original was not cancelled');

        /* And the replacement starts owing: the original's payment was reversed by step 2, so
           carrying `paid` across would assert a settlement nobody made (design Q2). */
        $this->assertSame('Unpaid', $replacement->payment_status);
        $this->assertSame(0, (int) $replacement->delivered_status);
    }

    /**
     * THE STOCK CONDITION. The pair's net movement is -N, not -2N.
     *
     * The original already took the stock. Nothing came back to the shelf, so nothing goes out
     * again — the goods are the same goods. Asserted as a figure rather than as the absence of a
     * call, because "no stock path ran" is satisfied by an operation that did nothing at all.
     */
    public function test_the_pair_moves_the_stock_once_and_the_lines_say_why(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store, 50);
        $original = $this->orderIn($store, $product, 2);
        $this->proveStockMovedOut($original);

        $stockBefore = (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;

        [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);
        $this->assertSame(OrderReplacement::REPLACED, $state);

        $this->assertSame(
            $stockBefore,
            (int) Product::withoutGlobalScopes()->find($product->id)->product_stock,
            'the replacement moved stock. The original already took it and nothing was returned, '
            . 'so the pair must move it exactly once — this is -2N, which bills the shelf twice '
            . 'for one set of goods.'
        );

        $lines = json_decode((string) $replacement->product_json, true);
        $this->assertIsArray($lines);
        $this->assertTrue(
            Order::lineStockCarriedFromOriginal($lines[0]),
            'the replacement\'s line is not stamped as carried, so any stock path reading it will '
            . 'take the stock a second time'
        );

        /* AND IT IS NOT STAMPED AS RESTORED, which is a different claim. `LINE_STOCK_RESTORED`
           records that goods came BACK, and the refund surface reads it as evidence; a reissued
           line must not look like a returned one. */
        $this->assertFalse(
            Order::lineStockAlreadyRestored($lines[0]),
            'the replacement\'s line claims stock was RESTORED. Nothing came back — conflating the '
            . 'two would make a reissue look like a return to the refund surface, which is the '
            . 'physical/financial separation D-15 condition 3 exists to keep.'
        );
    }

    /**
     * THE ORIGINAL'S ECONOMIC VALUES ARE BYTE-IDENTICAL AFTERWARDS.
     *
     * The cancelled order is the audit record of what was cancelled. Its lines, prices and totals
     * are compared as raw column values, not as casts, because a silent reformat is exactly the
     * "silently change economic values" the owner's condition forbids.
     */
    public function test_the_original_keeps_its_lines_and_totals_untouched(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product);
        $this->proveStockMovedOut($original);

        $before = DB::table('orders')->where('id', $original->id)
            ->select('product_json', 'product_price', 'coupon_price', 'delivery_price', 'tax_price', 'final_price', 'return_price')
            ->first();

        OrderReplacement::cancelAndReplace($original, $actor);

        $after = DB::table('orders')->where('id', $original->id)
            ->select('product_json', 'product_price', 'coupon_price', 'delivery_price', 'tax_price', 'final_price', 'return_price')
            ->first();

        $this->assertEquals($before, $after,
            'the replacement changed the original\'s lines or totals. The cancelled order is the '
            . 'record of what was cancelled; nothing about its economics may move.');
    }

    /** A REPLAYED CALL PRODUCES NO SECOND REPLACEMENT. */
    public function test_a_replayed_call_produces_no_second_replacement(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product);
        $this->proveStockMovedOut($original);

        OrderReplacement::cancelAndReplace($original, $actor);
        $this->assertSame(1, Order::where('replaced_order_id', $original->id)->count());

        [$state] = OrderReplacement::cancelAndReplace($original->fresh(), $actor);

        $this->assertSame(OrderReplacement::REFUSED_ALREADY_REPLACED, $state);
        $this->assertSame(
            1,
            Order::where('replaced_order_id', $original->id)->count(),
            'a second replacement was created for one original'
        );
    }

    /**
     * A DISPATCHED ORDER IS REFUSED, by the same test the cancel arm applies.
     *
     * Note what this also proves: the refusal happens BEFORE anything is written, so a dispatched
     * order is not cancelled-then-failed.
     */
    public function test_a_dispatched_original_is_refused_and_nothing_is_written(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product, 2, ['delivered_status' => 1]);
        $this->proveStockMovedOut($original);

        [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);

        $this->assertSame(OrderReplacement::REFUSED_ALREADY_DISPATCHED, $state);
        $this->assertNull($replacement);
        $this->assertSame(1, (int) $original->fresh()->delivered_status, 'a dispatched order was cancelled anyway');
        $this->assertSame(0, Order::where('replaced_order_id', $original->id)->count());
    }

    /**
     * A CROSS-TENANT ORIGINAL IS REFUSED — and this is the case `Order`'s lack of scoping makes
     * essential rather than theoretical.
     *
     * `Order` carries no `StoreScope` and is not in `config/tenancy.php`'s `scoped_models`
     * (CLAUDE.md), so `Order::find()` reaches every tenant's row and the relations on the model add
     * no predicate either. The only thing standing between a merchant and another store's order is
     * the policy, which is why step 5 runs first and why no new permission was invented for it.
     */
    public function test_a_merchant_cannot_replace_another_stores_order(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        /* Authenticated, permitted, and in their OWN store -- so the only thing that can refuse
           them is the store predicate. My first version left them unauthenticated, which refused
           them for the wrong reason and made this case prove nothing. */
        $intruder = $this->actor($merchantB);

        $product = $this->productIn($storeA);
        $original = $this->orderIn($storeA, $product);
        $this->proveStockMovedOut($original);

        [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $intruder);

        $this->assertSame(OrderReplacement::REFUSED_NOT_AUTHORISED, $state,
            'a merchant replaced an order belonging to another store. `Order` is not tenant-scoped, '
            . 'so the policy is the only thing that can refuse this.');
        $this->assertNull($replacement);
        $this->assertSame(0, (int) $original->fresh()->delivered_status, 'the intruder cancelled another store\'s order');
        $this->assertSame(0, Order::where('replaced_order_id', $original->id)->count());
    }

    /** AN ORDER WHOSE LINES CANNOT BE READ IS REFUSED, not half-processed. */
    public function test_an_order_with_unreadable_lines_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product, 2, ['product_json' => 'not json at all']);
        $this->proveStockMovedOut($original);

        [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);

        $this->assertSame(OrderReplacement::REFUSED_UNREADABLE_LINES, $state);
        $this->assertNull($replacement);
        $this->assertSame(0, (int) $original->fresh()->delivered_status,
            'an order with unreadable lines was cancelled and then could not be replaced — the '
            . 'worst available outcome');
    }

    /**
     * E13-F — AN EXCEPTION BEFORE THE COMMIT LEAVES THE ORIGINAL UNCANCELLED.
     *
     * This is the scenario the handoff lists as not run, and it is the one that matters most about
     * step 4: the whole operation is in one transaction so that a failure leaves neither a
     * cancelled order without a replacement nor the reverse. The dangerous half is the first —
     * `order_status_change()` cancels the original and the Observer reverses the money, so if the
     * replacement then fails and the cancel stands, the customer has lost their order and gained
     * nothing.
     *
     * Driven by making the replacement's own insert throw, through Eloquent's `creating` event.
     * That is the narrowest injection available: it fires after the cancel has happened and inside
     * the transaction, which is exactly the window under test, and it needs no mock of the service
     * itself.
     */
    public function test_an_exception_before_the_commit_rolls_the_cancellation_back(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product);
        $this->proveStockMovedOut($original);

        $this->assertSame(0, (int) $original->fresh()->delivered_status, 'premise: the original starts live');

        /* Fire only for the REPLACEMENT -- the original already exists, and the cancel is an
           update rather than a create, so `creating` cannot catch it by accident. */
        Order::creating(function (Order $order) {
            if (! empty($order->replaced_order_id)) {
                throw new \RuntimeException('e13f: injected replacement-persistence failure');
            }

            return true;
        });

        try {
            [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);
        } finally {
            /* Model event listeners are process-global; leaving this one armed would make every
               later test in this process fail for a reason that has nothing to do with it. */
            Order::flushEventListeners();
        }

        $this->assertNotSame(OrderReplacement::REPLACED, $state, 'the failed replacement reported success');
        $this->assertNull($replacement);

        $this->assertSame(
            0,
            (int) $original->fresh()->delivered_status,
            'THE CANCELLATION STOOD WHILE THE REPLACEMENT FAILED. The customer has lost their '
            . 'order and gained nothing, and the Observer has already reversed the money — which '
            . 'is the exact state step 4\'s single transaction exists to make impossible.'
        );

        $this->assertSame(
            0,
            Order::where('replaced_order_id', $original->id)->count(),
            'a replacement row survived the rollback'
        );
    }

    /**
     * AND THERE IS NOTHING AFTER THE COMMIT, which is why no after-commit case exists.
     *
     * The handoff lists "exception-before/after-commit" as the pair to drive. The before half is
     * above. The after half is **not reachable in this service**, and that is a property worth
     * pinning rather than a gap worth apologising for: `cancelAndReplace()` returns immediately
     * after `DB::transaction()` closes, so there is no post-commit tail that could throw and leave
     * the pair inconsistent.
     *
     * Asserted from the source, because the absence of code cannot be driven. If somebody adds a
     * notification, an event or a second write after the transaction, this fails and tells them
     * the after-commit scenario has just become real and needs its own test — which is precisely
     * the failure mode `PaymentController`'s Paymera writer already has (its post-commit tail can
     * throw after an order has committed).
     */
    public function test_nothing_runs_after_the_transaction_commits(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderReplacement.php'));

        $code = implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all($source)
        ));

        $afterCatch = substr($code, strpos($code, 'return [self::REFUSED_CANCEL_FAILED, null, $theReplacementFailed->getMessage()];'));

        /* What may follow the catch blocks: closing braces and the single success return. */
        $residue = preg_replace('/\s+/', '', $afterCatch);
        $residue = str_replace([
            'return[self::REFUSED_CANCEL_FAILED,null,$theReplacementFailed->getMessage()];',
            '}',
            'return[self::REPLACED,$replacement,\'\'];',
        ], '', $residue);

        $this->assertSame(
            '',
            $residue,
            'Something now runs after the transaction commits: ' . $residue
            . "\n\nThat makes the after-commit scenario real — a throw there leaves the original "
            . 'cancelled, the replacement written, and whatever follows half done. It needs its own '
            . 'test, the way PaymentController\'s post-commit tail does.'
        );
    }

    /**
     * ══════════════════════════════════════════════════════════════════════════════════════════
     * THE OPERATION IS NOW REACHABLE — D-32, and this block replaces the test that forbade it.
     * ══════════════════════════════════════════════════════════════════════════════════════════
     *
     * What stood here was `test_nothing_in_the_application_calls_this_operation_yet`, which swept
     * the controllers, middleware and routes for a caller and failed if it found one. Its own
     * failure message said: *"If Q1 has been answered, delete this test and record the decision."*
     *
     * **D-32 answers Q1: identical products, identical quantities, identical prices.** That is
     * the narrowest of the three readings the design offered, and the one that makes Q2 (a
     * partially-paid original) and Q3 (original prices or today's) *fall away* — so opening this
     * door invents no refund, settlement or stock rule. Different-item replacements are Phase 04.
     *
     * The cases below drive the real route. They matter because the service tests above call the
     * service directly, and a route adds three things a direct call cannot exercise: the
     * middleware stack, the id-resolution question, and the response shape the screen reads.
     */
    private function replaceViaRoute($actor, Order $original, array $body = [])
    {
        return $this->actingAs($actor)->post(route('order.replace', $original->id), $body);
    }

    public function test_the_route_replaces_the_order_and_answers_with_the_replacement(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product);
        $this->proveStockMovedOut($original);

        $response = $this->replaceViaRoute($actor, $original);

        $response->assertOk();
        $this->assertTrue($response->json('status'), 'the route refused a legitimate replacement: ' . $response->json('message'));

        $replacement = Order::where('replaced_order_id', $original->id)->first();
        $this->assertNotNull($replacement, 'no replacement was written');
        $this->assertSame((int) $replacement->id, (int) $response->json('data.replacement_order_id'));
        $this->assertSame($replacement->product_order_id, $response->json('data.replacement_order_number'));
        $this->assertSame(2, (int) $original->fresh()->delivered_status, 'the original was not cancelled');
    }

    /**
     * A MERCHANT CANNOT REPLACE ANOTHER STORE'S ORDER THROUGH THE ROUTE EITHER.
     *
     * The service test above proves the policy refuses it; this proves the ROUTE does not somehow
     * get there first. `Order` is not tenant-scoped, so there is no scope to fall back on — and a
     * 403 would be wrong here as much as a 401 would: the method answers business refusals in its
     * `{status, message}` envelope, like its sibling.
     */
    public function test_the_route_refuses_another_stores_order(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();
        $intruder = $this->actor($merchantB);

        $product = $this->productIn($storeA);
        $original = $this->orderIn($storeA, $product);
        $this->proveStockMovedOut($original);

        $response = $this->replaceViaRoute($intruder, $original);

        $response->assertOk();
        $this->assertFalse($response->json('status'), 'an intruder replaced another store\'s order through the route');
        $this->assertSame(0, (int) $original->fresh()->delivered_status, 'the intruder cancelled another store\'s order');
        $this->assertSame(0, Order::where('replaced_order_id', $original->id)->count());
    }

    /**
     * AN ANONYMOUS CALLER IS SENT TO SIGN IN, NOT GIVEN A BUSINESS REFUSAL.
     *
     * The route sits in the `['auth', 'xss', 'setlocate', 'verified']` group, so this is the
     * middleware's answer rather than the handler's — and the handler must never be reached. If it
     * were, `Auth::user()` would be null and the service's own `! $actor` limb would refuse, which
     * is the right answer by the wrong route.
     */
    public function test_an_anonymous_caller_never_reaches_the_handler(): void
    {
        [, $store] = $this->makeTenant();
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product);
        $this->proveStockMovedOut($original);

        $response = $this->post(route('order.replace', $original->id));

        $this->assertTrue(
            $response->isRedirect(),
            'an anonymous POST was answered by the handler rather than by the auth middleware '
            . '(HTTP ' . $response->getStatusCode() . ')'
        );
        $this->assertSame(0, Order::where('replaced_order_id', $original->id)->count());
    }

    /**
     * THE ID COMES FROM THE URL, NOT THE BODY — measured on the sibling route to be exploitable.
     *
     * `Request::__get` reads `all()` BEFORE the route parameters, so `$request->id` would let a
     * posted `id` win over the URL segment. `order_status_change` documents that a POST to
     * `/order-status-change/<own id>` carrying `id=<victim id>` acted on the victim. This handler
     * reads `$request->route('id')` for that reason, and this case is what holds it there.
     *
     * The caller owns BOTH orders here, so the only thing being measured is WHICH one was acted
     * on — not an authorisation refusal, which would mask the answer.
     */
    public function test_a_posted_id_cannot_override_the_url_segment(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store, 100);

        $named = $this->orderIn($store, $product);
        $this->proveStockMovedOut($named);
        $other = $this->orderIn($store, $product);
        $this->proveStockMovedOut($other);

        $this->replaceViaRoute($actor, $named, ['id' => $other->id]);

        $this->assertSame(
            1,
            Order::where('replaced_order_id', $named->id)->count(),
            'the order named in the URL was not the one replaced'
        );
        $this->assertSame(
            0,
            Order::where('replaced_order_id', $other->id)->count(),
            'A POSTED `id` OVERRODE THE URL SEGMENT. That is the exact defect measured on '
            . 'order-status-change: the handler acted on an order the URL did not name.'
        );
        $this->assertSame(0, (int) $other->fresh()->delivered_status, 'the unnamed order was cancelled');
    }

    /** Each named refusal reaches the merchant as its own sentence, not "something was wrong". */
    public function test_a_replayed_route_call_says_so_specifically(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product);
        $this->proveStockMovedOut($original);

        $this->replaceViaRoute($actor, $original);
        $response = $this->replaceViaRoute($actor, $original->fresh());

        $response->assertOk();
        $this->assertFalse($response->json('status'));
        $this->assertStringContainsString(
            'already been replaced',
            (string) $response->json('message'),
            'a replay was answered with a generic message, which sends the merchant to support '
            . 'instead of telling them what happened'
        );
        $this->assertSame(1, Order::where('replaced_order_id', $original->id)->count());
    }

    /**
     * AND THE SERVICE'S INTERNAL REASON NEVER REACHES THE MERCHANT.
     *
     * The named outcomes are translated into sentences; `$reason` names internal columns and
     * states (`replaced_order_id`, `already_dispatched`) and belongs in the operator log only —
     * the same division `GuardsPlanActivation` makes between what an operator needs and what a
     * response may carry.
     */
    public function test_the_refusal_discloses_no_internal_state(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();
        $intruder = $this->actor($merchantB);
        $product = $this->productIn($storeA);
        $original = $this->orderIn($storeA, $product);
        $this->proveStockMovedOut($original);

        $body = (string) $this->replaceViaRoute($intruder, $original)->getContent();

        foreach (['replaced_order_id', 'not_authorised', 'already_dispatched', 'updateStatus'] as $internal) {
            $this->assertStringNotContainsString(
                $internal,
                $body,
                'the refusal names the internal token `' . $internal . '` to the merchant'
            );
        }
    }

    /**
     * D-32's CONDITION ITSELF: IDENTICAL PRODUCTS, IDENTICAL QUANTITIES, IDENTICAL PRICES.
     *
     * This is the clause the owner's answer to Q1 consists of, and nothing in this file asserted
     * it. `test_the_original_keeps_its_lines_and_totals_untouched` proves the ORIGINAL is not
     * rewritten; it says nothing about what the REPLACEMENT carries. So an implementation that
     * dropped a line, rounded a price or re-read the catalogue would have passed every case here.
     *
     * **THE CATALOGUE IS MOVED BEFORE THE REPLACEMENT RUNS, and that is the whole design of this
     * test.** With the order's prices equal to the product's — which is how every other case in
     * this file is set up — copying and recomputing give the SAME ANSWER, so the test would pass
     * against an implementation that consulted the catalogue. That is Q3 (original prices or
     * today's) returning through the back door: D-32 only disposes of Q3 because nothing here
     * reads the catalogue, and a test that cannot tell the two apart does not hold that open.
     *
     * Two lines with different quantities and different unit prices, so a dropped line, a swapped
     * quantity and a reused unit price each bite separately.
     */
    public function test_the_replacement_carries_the_original_products_quantities_and_prices(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);

        $a = $this->productIn($store);
        $b = $this->productIn($store);

        $lines = [
            ['product_id' => $a->id, 'name' => $a->name, 'quantity' => 2, 'price' => 100, 'variant_id' => 0],
            ['product_id' => $b->id, 'name' => $b->name, 'quantity' => 3, 'price' => 250, 'variant_id' => 0],
        ];

        $original = $this->orderIn($store, $a, 2, [
            'product_json' => json_encode($lines),
            'product_id' => $a->id . ',' . $b->id,
            'product_price' => 950,
            'coupon_price' => 25,
            'delivery_price' => 40,
            'tax_price' => 60,
            'final_price' => 1025,
        ]);
        $this->proveStockMovedOut($original);

        /* THE CATALOGUE MOVES. Through Eloquent rather than `DB::table()`, because 79 of the 95
           models here are `Cachable` and a raw write leaves Eloquent reads serving the pre-write
           row for the rest of the test. */
        $a->update(['price' => 777, 'sale_price' => 777]);
        $b->update(['price' => 888, 'sale_price' => 888]);

        $this->assertSame(777, (int) $a->fresh()->price, 'premise: the catalogue price really moved');
        $this->assertSame(888, (int) $b->fresh()->price, 'premise: the catalogue price really moved');

        [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);

        $this->assertSame(OrderReplacement::REPLACED, $state, 'the replacement was refused');
        $this->assertNotNull($replacement);

        $carried = json_decode((string) $replacement->fresh()->product_json, true);

        $this->assertIsArray($carried);
        $this->assertCount(
            count($lines),
            $carried,
            'the replacement carries a different number of lines than the original -- a line was dropped or duplicated'
        );

        foreach ($lines as $i => $expected) {
            $this->assertSame(
                (int) $expected['product_id'],
                (int) $carried[$i]['product_id'],
                "line {$i}: the replacement names a different product than the original"
            );
            $this->assertSame(
                (int) $expected['quantity'],
                (int) $carried[$i]['quantity'],
                "line {$i}: the quantity changed, which D-32 forbids"
            );
            $this->assertEquals(
                $expected['price'],
                $carried[$i]['price'],
                "line {$i}: the unit price changed -- if it now equals the catalogue's "
                . 'new price, the implementation is recomputing rather than copying, which is Q3 '
                . 'returning through the back door'
            );
        }

        /* The ONLY permitted difference in a line is the stock stamp, which is what tells the
           cancellation arm that this line's stock was already accounted for on the original. A
           whitelist rather than a spot-check, so a future key added to the copy has to come here
           and be declared. */
        foreach ($carried as $i => $line) {
            $extra = array_diff(array_keys($line), array_keys($lines[$i]));

            $this->assertSame(
                [Order::LINE_STOCK_CARRIED],
                array_values($extra),
                "line {$i}: the replacement's line differs from the original by more than the "
                . 'stock-carried stamp'
            );
            $this->assertSame(1, (int) $line[Order::LINE_STOCK_CARRIED], "line {$i}: the stamp is not set");
        }

        /* EVERY ECONOMIC TOTAL IS COPIED, NOT RECOMPUTED. Compared against the original's own
           stored values rather than against the literals above, so the assertion stays true if
           the fixture changes. */
        $before = DB::table('orders')->where('id', $original->id)
            ->select('product_price', 'coupon_price', 'delivery_price', 'tax_price', 'final_price',
                'currency_code', 'currency_symbol', 'payment_type', 'customer_id', 'is_guest')
            ->first();

        $after = DB::table('orders')->where('id', $replacement->id)
            ->select('product_price', 'coupon_price', 'delivery_price', 'tax_price', 'final_price',
                'currency_code', 'currency_symbol', 'payment_type', 'customer_id', 'is_guest')
            ->first();

        foreach ((array) $before as $column => $value) {
            $this->assertEquals(
                $value,
                ((array) $after)[$column],
                "`{$column}` was not carried across unchanged; a replacement that recomputes a "
                . 'money column is inventing a pricing rule the owner did not approve'
            );
        }

        /* And the two things that MUST differ, pinned here so this test cannot be read as
           "the replacement is a byte copy of the original". */
        $this->assertSame('Unpaid', $replacement->payment_status, 'the replacement must start owing');
        $this->assertSame(
            (int) $original->id,
            (int) $replacement->replaced_order_id,
            'the replacement is not linked to its original'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════════════════════
    // D-15's FINANCIAL AND STOCK PROOF CONDITIONS
    //
    // The owner's directive named a discrepancy between this service and D-15: the identical-lines
    // rule in D-32 does NOT waive the financial or stock proof requirements. Every case below was
    // absent, and the service passed its whole suite without them.
    // ══════════════════════════════════════════════════════════════════════════════════════════

    /**
     * D-15 CONDITION 6 — A COLLECTED PAYMENT IS REFUSED, because no provable refund path exists.
     *
     * This is the case that mattered most and was missing entirely: before the gate, a `Paid` order
     * was cancelled and reissued, the shopper's money was neither returned nor accounted for, and
     * the suite was green.
     *
     * `cod` is used deliberately. A cash-on-delivery order marked paid means the MERCHANT holds the
     * shopper's cash; returning it is a real-world act this system cannot observe, so treating it as
     * "provably correct" would be the same inference condition 3 forbids.
     */
    public function test_a_collected_payment_is_refused_and_nothing_is_written(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product, 2, ['payment_status' => 'Paid']);
        $this->proveStockMovedOut($original);

        [$state, $replacement, $reason] = OrderReplacement::cancelAndReplace($original, $actor);

        $this->assertSame(OrderReplacement::REFUSED_PAYMENT_COLLECTED, $state, $reason);
        $this->assertNull($replacement);
        $this->assertSame(0, (int) $original->fresh()->delivered_status,
            'the original was cancelled despite the refusal, so a refusal is not leaving the order alone');
        $this->assertSame(0, Order::where('replaced_order_id', $original->id)->count());
    }

    /**
     * THE SAME REFUSAL FOR EVERY CASING THE PLATFORM WRITES, because `payment_status` is free text
     * written by 70 vendored modules and the gate is an allowlist of UNCOLLECTED states.
     *
     * The provider shapes (`succeeded`, `success`, `Approved`) are the ones that would slip through
     * a denylist of `['paid']`, which is what makes the direction of the list load-bearing rather
     * than stylistic.
     */
    public function test_every_collected_spelling_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);

        foreach (['Paid', 'paid', 'succeeded', 'success', 'Succeeded', 'Approved', 'settled'] as $status) {
            $original = $this->orderIn($store, $product, 2, ['payment_status' => $status]);
            $this->proveStockMovedOut($original);

            [$state] = OrderReplacement::cancelAndReplace($original, $actor);

            $this->assertSame(OrderReplacement::REFUSED_PAYMENT_COLLECTED, $state,
                "payment_status \"{$status}\" was treated as uncollected");
        }
    }

    /** D-15 CONDITION 6 — a partial payment has no approved settlement rule, so it is refused. */
    public function test_a_partially_paid_order_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);

        foreach (['Partially Paid', 'partially paid', 'PARTIAL'] as $status) {
            $original = $this->orderIn($store, $product, 2, ['payment_status' => $status]);
            $this->proveStockMovedOut($original);

            [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);

            $this->assertSame(OrderReplacement::REFUSED_PAYMENT_PARTIAL, $state,
                "payment_status \"{$status}\" was not recognised as partial");
            $this->assertNull($replacement);
        }
    }

    /**
     * D-15 CONDITION 3 — REVERSING THE MERCHANT'S WALLET IS NOT A REFUND TO THE CUSTOMER, and this
     * test exists because the cancellation DOES reverse the merchant's balance.
     *
     * So there is a real temptation to read that reversal as "the money went back" and let a
     * collected order through. The assertion is that the platform has no customer-refund mechanism
     * at all — `hasGatewayRefundDriver()` is false for every provider — and that the service's
     * refusal is keyed on that rather than on the wallet event which happens regardless.
     */
    public function test_a_merchant_wallet_reversal_is_never_read_as_a_customer_refund(): void
    {
        $refunds = app(\App\Services\RefundIntegrityService::class);

        foreach ([null, 'cod', 'stripe', 'paypal', 'paytm', 'razorpay', 'bank_transfer'] as $type) {
            $this->assertFalse($refunds->hasGatewayRefundDriver($type),
                "a refund driver appeared for \"{$type}\"; if that is real, D-15 condition 6 now has "
                . 'a satisfiable case and this test plus the service gate must be revisited together');
        }

        /* And the gate is keyed on exactly that, for a collected order of every provider. */
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);

        foreach (['cod', 'stripe', 'paypal'] as $type) {
            $original = $this->orderIn($store, $product, 2, [
                'payment_status' => 'Paid',
                'payment_type' => $type,
            ]);
            $this->proveStockMovedOut($original);

            $this->assertSame(OrderReplacement::REFUSED_PAYMENT_COLLECTED,
                OrderReplacement::paymentEligibility($original->fresh()),
                "a collected {$type} order was treated as refundable");
        }
    }

    /**
     * D-15 CONDITION 4 — AN ORDER NOTHING OBSERVED IS REFUSED. Silence is not evidence.
     *
     * No movement rows at all is what an order written before `stock_movements` existed looks like,
     * and what an order from a writer not yet enrolled looks like. `movedOutFor()` answers
     * `unknown` for it, and the service must refuse rather than stamp `LINE_STOCK_CARRIED`.
     */
    public function test_an_order_with_no_stock_evidence_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product);   // deliberately NOT proved

        $this->assertSame(StockMovement::UNKNOWN,
            StockMovement::movedOutFor($store->id, $original->id, $product->id),
            'premise: this order has no observable movement');

        [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);

        $this->assertSame(OrderReplacement::REFUSED_STOCK_EVIDENCE_UNKNOWN, $state);
        $this->assertNull($replacement);
        $this->assertSame(0, (int) $original->fresh()->delivered_status, 'the original was cancelled anyway');
    }

    /**
     * D-15 CONDITION 5 — AN ORDER WHOSE DECREMENT PROVABLY DID NOT HAPPEN IS REFUSED, so the
     * "stock already decremented" marker is never written on it.
     *
     * Two real shapes produce `not_moved`, and both are driven: a REFUSED decrement, recorded
     * `direction = out, applied = false`; and a line taken and then given back, which nets to zero.
     * Stamping the marker in either case would be a false claim AND would suppress the restore the
     * cancellation should perform — so stock would go missing on paper.
     */
    public function test_an_order_whose_stock_provably_did_not_move_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);

        // Shape 1: the decrement was refused.
        $p1 = $this->productIn($store);
        $refused = $this->orderIn($store, $p1);
        StockMovement::record([
            'store_id' => $store->id, 'order_id' => $refused->id, 'product_id' => $p1->id,
            'direction' => StockMovement::OUT, 'quantity' => 2, 'applied' => false,
            'outcome' => 'refused', 'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        $this->assertSame(StockMovement::NOT_MOVED,
            StockMovement::movedOutFor($store->id, $refused->id, $p1->id), 'premise: refused decrement');

        [$state] = OrderReplacement::cancelAndReplace($refused, $actor);
        $this->assertSame(OrderReplacement::REFUSED_STOCK_NOT_MOVED, $state,
            'a line whose decrement was refused was carried as though it had consumed stock');

        // Shape 2: taken out and already given back, netting to zero.
        $p2 = $this->productIn($store);
        $returned = $this->orderIn($store, $p2);
        $this->proveStockMovedOut($returned);
        StockMovement::record([
            'store_id' => $store->id, 'order_id' => $returned->id, 'product_id' => $p2->id,
            'direction' => StockMovement::IN, 'quantity' => 2, 'applied' => true,
            'reason' => StockMovement::REASON_ORDER_CANCEL,
        ]);

        $this->assertSame(StockMovement::NOT_MOVED,
            StockMovement::movedOutFor($store->id, $returned->id, $p2->id), 'premise: already restored');

        [$state2] = OrderReplacement::cancelAndReplace($returned, $actor);
        $this->assertSame(OrderReplacement::REFUSED_STOCK_NOT_MOVED, $state2,
            'a line already given back was carried as though it were still out');
    }

    /**
     * ALL-OR-NOTHING: ONE UNPROVEN LINE REFUSES THE WHOLE ORDER.
     *
     * A replacement carrying the marker on some lines and not others would need a rule for what the
     * unmarked lines do about stock, and no such rule is approved. This drives a two-line order
     * where only the first line is proven — the shape a partial enrolment produces.
     */
    public function test_one_unproven_line_refuses_the_whole_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $a = $this->productIn($store);
        $b = $this->productIn($store);

        $original = $this->orderIn($store, $a, 2, [
            'product_json' => json_encode([
                ['product_id' => $a->id, 'name' => $a->name, 'quantity' => 2, 'price' => 100, 'variant_id' => 0],
                ['product_id' => $b->id, 'name' => $b->name, 'quantity' => 1, 'price' => 100, 'variant_id' => 0],
            ]),
        ]);

        // Only line A is observed.
        StockMovement::record([
            'store_id' => $store->id, 'order_id' => $original->id, 'product_id' => $a->id,
            'direction' => StockMovement::OUT, 'quantity' => 2, 'applied' => true,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        $this->assertSame(StockMovement::MOVED,
            StockMovement::movedOutFor($store->id, $original->id, $a->id), 'premise: line A is proven');

        /*
         * LINE B READS `not_moved`, NOT `unknown`, AND THE DISTINCTION IS THE RECORD BEING PRECISE
         * RATHER THAN THE TEST BEING WRONG — my first expectation here was `unknown`.
         *
         * `movedOutFor()` falls back to "did we observe this ORDER taking anything out at all". Line
         * A's row makes the answer yes, so line B's own absence of rows is positive evidence that
         * line B took nothing out, rather than silence. That is the correct reading and it is the
         * stricter of the two: `not_moved` is a statement, `unknown` is an absence.
         */
        $this->assertSame(StockMovement::NOT_MOVED,
            StockMovement::movedOutFor($store->id, $original->id, $b->id),
            'premise: line B is observed-and-unmoved rather than unobserved');

        [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);

        $this->assertSame(OrderReplacement::REFUSED_STOCK_NOT_MOVED, $state,
            'a half-proven order produced a replacement, so some lines carried an unproven claim');
        $this->assertNull($replacement);
    }

    /**
     * D-15 CONDITION 8 — "ONE REPLACEMENT PER ORIGINAL" IS A DATABASE INVARIANT, NOT A SELECT.
     *
     * The service's in-transaction `exists()` re-check is an early answer: two transactions can both
     * pass it before either inserts, because a read of a NON-unique index takes no gap lock under
     * MySQL's default isolation. The guarantee has to live in the schema, so this asserts the unique
     * index exists AND that a direct second insert is refused by the database itself — which is the
     * only way to show the invariant without two real connections.
     */
    public function test_the_database_itself_refuses_a_second_replacement_of_one_original(): void
    {
        $names = array_map(
            fn ($i) => $i['name'] ?? null,
            \Illuminate\Support\Facades\Schema::getIndexes('orders')
        );

        $this->assertContains('orders_replaced_order_id_unique', $names,
            'the unique index is absent, so "one replacement per original" rests on a SELECT that '
            . 'two concurrent writers can both pass');

        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);
        $original = $this->orderIn($store, $product);
        $this->proveStockMovedOut($original);

        [$state, $replacement] = OrderReplacement::cancelAndReplace($original, $actor);
        $this->assertSame(OrderReplacement::REPLACED, $state, 'premise: the first replacement happened');

        /* A second row claiming the same original, inserted directly — bypassing every PHP guard,
           which is the point: the database must be the thing that says no. */
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('orders')->insert([
            'product_order_id' => 'R2-' . strtoupper(uniqid()),
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => $original->product_json,
            'product_id' => (string) $product->id,
            'product_price' => 200, 'coupon_price' => 0, 'delivery_price' => 0,
            'tax_price' => 0, 'final_price' => 200,
            'payment_type' => 'cod', 'payment_status' => 'Unpaid', 'delivered_status' => 0,
            'store_id' => $store->id, 'created_by' => $store->created_by,
            'replaced_order_id' => $original->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * THE BUTTON AND THE SERVICE CANNOT DISAGREE, which is the directive's "hidden or disabled for
     * ineligible orders" requirement expressed as one predicate rather than two.
     *
     * `eligibility()` is what `OrderController::order_view()` calls. Driven across every refusal so
     * a future condition added to the service reaches the button for free.
     */
    public function test_eligibility_answers_for_every_refusal_the_service_makes(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $actor = $this->actor($merchant);
        $product = $this->productIn($store);

        $eligible = $this->orderIn($store, $product);
        $this->proveStockMovedOut($eligible);
        $this->assertSame(OrderReplacement::REPLACED, OrderReplacement::eligibility($eligible->fresh()));

        $unobserved = $this->orderIn($store, $product);
        $this->assertSame(OrderReplacement::REFUSED_STOCK_EVIDENCE_UNKNOWN,
            OrderReplacement::eligibility($unobserved->fresh()));

        $collected = $this->orderIn($store, $product, 2, ['payment_status' => 'Paid']);
        $this->proveStockMovedOut($collected);
        $this->assertSame(OrderReplacement::REFUSED_PAYMENT_COLLECTED,
            OrderReplacement::eligibility($collected->fresh()));

        $partial = $this->orderIn($store, $product, 2, ['payment_status' => 'Partially Paid']);
        $this->proveStockMovedOut($partial);
        $this->assertSame(OrderReplacement::REFUSED_PAYMENT_PARTIAL,
            OrderReplacement::eligibility($partial->fresh()));

        $dispatched = $this->orderIn($store, $product, 2, ['delivered_status' => 1]);
        $this->proveStockMovedOut($dispatched);
        $this->assertSame(OrderReplacement::REFUSED_ALREADY_DISPATCHED,
            OrderReplacement::eligibility($dispatched->fresh()));

        $unreadable = $this->orderIn($store, $product, 2, ['product_json' => 'not json at all']);
        $this->assertSame(OrderReplacement::REFUSED_UNREADABLE_LINES,
            OrderReplacement::eligibility($unreadable->fresh()));

        /* And an order already replaced answers already_replaced rather than anything else. */
        [$state] = OrderReplacement::cancelAndReplace($eligible->fresh(), $actor);
        $this->assertSame(OrderReplacement::REPLACED, $state, 'premise');
        $this->assertSame(OrderReplacement::REFUSED_ALREADY_REPLACED,
            OrderReplacement::eligibility($eligible->fresh()));
    }
}
