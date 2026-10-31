<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Product;
use App\Services\OrderReplacement;
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
 * THE OPERATION IS UNREACHABLE IN PRODUCTION, AND THESE TESTS DRIVE THE SERVICE DIRECTLY
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * There is no route. That is deliberate — design §6's **Q1** (may a replacement differ from the
 * original?) is unanswered, and the identical-lines-only variant implemented here is CORRECT
 * without that answer but must not be made REACHABLE without it.
 *
 * So these tests call `OrderReplacement::cancelAndReplace()` rather than driving HTTP, and that is
 * stated rather than hidden: they prove the operation behaves as specified, **not** that any
 * merchant can perform it. F237 is the cautionary case — a mechanism recorded as DONE whose
 * production callers never existed — and the difference here is that nothing claims otherwise.
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
            'payment_status' => 'paid',
            'delivered_status' => 0,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ], $overrides));
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
        $other = $this->orderIn($store, $product);

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

        $body = (string) $this->replaceViaRoute($intruder, $original)->getContent();

        foreach (['replaced_order_id', 'not_authorised', 'already_dispatched', 'updateStatus'] as $internal) {
            $this->assertStringNotContainsString(
                $internal,
                $body,
                'the refusal names the internal token `' . $internal . '` to the merchant'
            );
        }
    }
}
