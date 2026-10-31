<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Services\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Commerce\OrderWriteCensus;

/**
 * F237 — PHASE TWO OF THE TWO-PHASE STOCK ATTRIBUTION WAS NEVER WIRED.
 *
 * `d73fbe12` landed "E13 slice 2 — two-phase attribution" and the design is right: because
 * `AppliesCartStockChanges::decrementStockOrFail()` runs before `new Order()` exists, a movement
 * recorded there has no order id to carry, so it carries a correlation token and **the caller
 * attributes it once the order exists**. The trait's own docblock says exactly that
 * (`AppliesCartStockChanges.php:41`).
 *
 * **No caller ever did.** `StockMovement::attributeToOrder()` had zero production callers: the only
 * mentions under `app/` were its own definition and that docblock. So every placement row kept
 * `order_id = NULL` for good, `movedOutFor()` — which predicates on `order_id` in **both** its main
 * query and its `$observed` fallback — answered `UNKNOWN` for every real order, and because
 * `UNKNOWN` means *fall through and restore*, the E13-C cancellation guard added in `4b917079`
 * **never refused anything on a real code path**.
 *
 * **WHERE THIS BIT, STATED PRECISELY.** In production the `stock_movements` migration is
 * deliberately not run (D-15 condition 9 — development and test only), so `available()` is false
 * and the guard answers `UNKNOWN` there *by design*. The defect is that the mechanism did not work
 * **even in development and test, where the table does exist**. It was unexercised by any real code
 * path anywhere, which is the thing that makes a mechanism untrustworthy rather than merely unused.
 *
 * ## Why the 55 existing tests passed over it
 *
 * They call `attributeToOrder()` **by hand** (`StockMovementEvidenceTest.php:402`, `:432`, `:435`),
 * so they exercise an attributed state that no request could produce. Each half was proven in
 * isolation — the trait threads a token (`:560`, a source census) and attribution is idempotent when
 * invoked (`:412`) — and **nothing connected them**. That is this repository's documented failure
 * mode: *the assertion satisfied by something adjacent to what it meant to measure.* The docblock's
 * "until then the row is honestly UNATTRIBUTED" reads as a transient state; it was permanent.
 *
 * So the two tests this file opens with are the two the owner asked for first, and in this order:
 * a **production-path** regression that drives a real route end to end, and a **method-scoped**
 * census. Both were confirmed RED against the unfixed tree before the fix was written.
 *
 * ## What is deliberately NOT changed
 *
 * `movedOutFor()`'s legacy semantics stay exactly as they were: an order the record never observed
 * still reads `UNKNOWN`, not `NOT_MOVED`, and `test_an_order_placed_before_the_record_existed_still_reads_unknown`
 * pins that in this file too, because attribution is precisely the change that could have broken it.
 * No payment behaviour changes, no refusal is added or removed, and an attribution failure can never
 * fail a checkout that has already taken money — it is logged loudly and the row is left
 * unattributed, which is the honest record and the state `unattributed()` already surfaces.
 */
class StockAttributionTest extends SecurityTestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------------------------
    // 1. THE PRODUCTION-PATH REGRESSION. Written first, and RED before the fix.
    // ---------------------------------------------------------------------------------------

    /**
     * A real checkout, through a real route, must leave its movement owned by the order it made.
     *
     * This is the test whose absence let F237 exist. It drives `POST {storeSlug?}/whatsapp` — a live
     * storefront order writer that decrements through the trait and then persists an `Order` in the
     * same method (`PaymentController::whatsapp`, order write at `:4956`) — and then asks the record
     * the question the cancellation asks: *did this order take stock out?*
     *
     * Rows are read with `DB::table()`, never Eloquent: 79 of the 95 models are `Cachable` and under
     * `CACHE_STORE=array` a stale read lives for the rest of the test, so an Eloquent assertion here
     * could pass whether or not the fix works.
     */
    public function test_a_real_checkout_attributes_its_stock_movement_to_the_order_it_created(): void
    {
        [, $store] = $this->tenantWithStorefront();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        $this->assertTrue(
            StockMovement::available(),
            'premise: the stock_movements table is not installed in this test run, so nothing below '
            . 'could measure attribution either way'
        );

        $response = $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/whatsapp', $this->postBody($store));

        $response->assertOk();

        $orderId = (int) DB::table('orders')->where('store_id', $store->id)->value('id');

        $this->assertNotSame(
            0,
            $orderId,
            'premise: no order was written, so this request is not the one this test means to measure'
        );

        $movements = DB::table('stock_movements')
            ->where('store_id', $store->id)
            ->where('direction', StockMovement::OUT)
            ->get();

        $this->assertCount(
            1,
            $movements,
            'premise: the checkout recorded no out movement, so attribution has nothing to attach to'
        );

        $this->assertSame(
            $orderId,
            (int) $movements->first()->order_id,
            'F237: a real checkout left its stock movement UNATTRIBUTED (order_id NULL). '
            . '`AppliesCartStockChanges` mints a correlation token and records the movement against '
            . 'it, but nothing ever called `StockMovement::attributeToOrder()`, so the row never '
            . 'learns which order consumed the stock.'
        );

        $this->assertSame(
            StockMovement::MOVED,
            StockMovement::movedOutFor($store->id, $orderId, $product->id, null),
            'F237: the record cannot say that a real order took stock out. `movedOutFor()` '
            . 'predicates on order_id in both its main query AND its $observed fallback, so an '
            . 'unattributed row makes it answer UNKNOWN for every real order — and UNKNOWN means '
            . '"fall through and restore", which is why the E13-C cancellation guard never refused '
            . 'anything on a real code path.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // 2. THE METHOD-SCOPED CENSUS. Also RED before the fix, at 11 methods.
    // ---------------------------------------------------------------------------------------

    /**
     * Every method that moves stock through the trait AND persists an order must attribute.
     *
     * **Asked of the ENCLOSING METHOD, not of the file**, because a file-scoped question is exactly
     * what made F227's guard claim false: `str_contains($source, 'GuardsGatewayReturn')` credited an
     * import rather than a call, and `PayUPaymentController` was counted among the guarded while
     * never calling the guard at all.
     *
     * **It is also why a plain `grep` could not have been the census here.** `attributeToOrder` is
     * named in `AppliesCartStockChanges.php:41` — in a **docblock** — so a textual search over the
     * tree was already satisfied before a single caller existed. `OrderWriteCensus::methodBody()`
     * drops `T_COMMENT` and `T_DOC_COMMENT`, which is the only reason this question can be asked
     * honestly.
     *
     * The invariant is self-maintaining: it keys on *decrements AND writes an order*, so a tenth
     * gateway that starts moving stock is caught the day it does, and a method that persists an
     * order without moving stock (`createOrderViaApi` today) is not asked for an attribution it has
     * no token for.
     */
    public function test_every_trait_user_that_moves_stock_and_writes_an_order_attributes_its_evidence(): void
    {
        $root = base_path('app/Http/Controllers/');
        $unattributed = [];
        $withoutTransaction = [];
        $checked = 0;

        foreach (OrderWriteCensus::customerOrderSites() as $site) {
            $source = @file_get_contents($root . $site['file']);

            if ($source === false || ! str_contains($source, 'AppliesCartStockChanges')) {
                continue;
            }

            $body = $this->methodBodyWithoutComments($root . $site['file'], $site['method']);

            if ($body === null || ! str_contains($body, 'decrementStockOrFail')) {
                continue;
            }

            $checked++;

            if (! str_contains($body, 'attributeStockEvidenceTo')) {
                $unattributed[] = $site['file'] . '::' . $site['method'] . ':' . $site['line']
                    . ' (never attributes)';

                continue;
            }

            /*
             * PLACEMENT, not merely presence. An attribution that runs BEFORE the order is saved
             * names an order with no id and attributes nothing — and it would satisfy a
             * presence-only census perfectly. So the call must appear after the save within the
             * method body. This is the assertion that bites when someone hoists it.
             */
            $savedAt = strpos($body, '$order->save()');
            $attributedAt = strpos($body, 'attributeStockEvidenceTo');

            if ($savedAt !== false && $attributedAt < $savedAt) {
                $unattributed[] = $site['file'] . '::' . $site['method'] . ':' . $site['line']
                    . ' (attributes BEFORE the order is saved, so the order has no id yet)';

                continue;
            }

            /*
             * AND INSIDE THE TRANSACTION. Measured, because the claim was first made and then
             * found false: moving the call below `DB::commit()` passes every behavioural test in
             * this file, so only a source assertion can hold the placement.
             *
             * It matters for a narrow but real window. After the commit, a process that dies
             * between the commit and the attribution leaves a committed order whose movement is
             * unattributed — which degrades to UNKNOWN, so the cancellation restores, the safe
             * direction. Inside the transaction the two are atomic: either the order and its
             * evidence both land, or neither does. Strictly better, and free.
             */
            $commitAt = strpos($body, 'DB::commit()');

            if ($commitAt === false) {
                /*
                 * NO TRANSACTION ON THIS PATH, counted rather than skipped in silence.
                 *
                 * Independent review found that this check quietly passed for nine of the eleven
                 * sites because they open no transaction at all, while the docblock claimed the
                 * atomicity property for all of them. Recording them makes the real coverage
                 * visible: the atomicity requirement is satisfied where a transaction exists, and
                 * on the rest the order is already committed when the attribution runs, so a crash
                 * between the two leaves an unattributed row -> UNKNOWN -> restore, the safe
                 * direction. Adding transactions to seven gateway handlers is a larger change than
                 * F237 and is not smuggled in here.
                 */
                $withoutTransaction[] = $site['file'] . '::' . $site['method'];

                continue;
            }

            if ($attributedAt > $commitAt) {
                $unattributed[] = $site['file'] . '::' . $site['method'] . ':' . $site['line']
                    . ' (attributes AFTER DB::commit(), so the order and its evidence are no longer atomic)';
            }
        }

        /*
         * EXACTLY, not at-least. Review pointed out that a `>=` premise stops catching a silently
         * truncated body the day a twelfth site appears -- and `OrderWriteCensus::methodBody()`
         * counts only the plain-string `{` token, so an interpolated `"{$x}"` in one of these
         * methods would decrement depth without incrementing and cut the captured body short.
         * Pinning the count means a new site has to be acknowledged here rather than slipping
         * through as a skip.
         */
        $this->assertSame(
            11,
            $checked,
            'the population of decrement-and-write methods moved from the 11 measured on '
            . '065b4df5. A NEW one needs attribution and a line in this count; a REMOVED one '
            . 'wants recording. Do not relax this to >= -- that is what let a truncated method '
            . 'body pass unnoticed.'
        );

        /*
         * The nine sites that open no transaction, asserted as a SET so the docblock above cannot
         * drift from the code. These are safe (see the per-site note in the loop) but they are not
         * covered by the atomicity requirement, and that distinction is the point of listing them.
         */
        $this->assertSame(
            [
                'Api/ApiController.php::place_order',
                'Api/ApiController.php::ordersave',
                'AuthorizeNetPaymnetController.php::getOrderPaymentStatus',
                'CinetPayController.php::getProductStatus',
                'EasebuzzController.php::getProductStatus',
                'KhaltiPaymnetController.php::getOrderPaymentStatus',
                'NMIPayController.php::getNMIProductStatus',
                'PaiementProPaymentController.php::getProductStatus',
                'PayUPaymentController.php::getProductStatus',
            ],
            $withoutTransaction,
            'the set of attribution sites that run outside a transaction changed. Each one is safe '
            . '-- the order is already committed, so a crash leaves the row unattributed, which '
            . 'reads UNKNOWN and restores -- but none of them satisfies the atomicity requirement, '
            . 'and only PaymentController::getProductStatus and ::whatsapp do.'
        );

        $this->assertSame(
            [],
            $unattributed,
            "F237: these methods move stock through the trait and persist a customer Order, but never\n"
            . "attribute the movement to it. Every row they record keeps order_id NULL, so\n"
            . "movedOutFor() answers UNKNOWN for their orders and the cancellation guard cannot\n"
            . "refuse:\n  - " . implode("\n  - ", $unattributed)
        );
    }

    // ---------------------------------------------------------------------------------------
    // 3. LEGACY SEMANTICS — the thing attribution could most easily have broken.
    // ---------------------------------------------------------------------------------------

    /**
     * An order the record never observed still reads UNKNOWN, never NOT_MOVED.
     *
     * D-15 conditions 4 and 5 forbid inferring that an order consumed stock, and forbid marking one
     * as such without proof. The dangerous direction is the opposite inference: reading "no rows" as
     * "no decrement" for an order placed before this record existed, which would let a cancellation
     * skip a restore that is genuinely owed. Attribution is the change that could introduce that, so
     * it is pinned here and not only in `StockMovementEvidenceTest`.
     */
    public function test_an_order_placed_before_the_record_existed_still_reads_unknown(): void
    {
        [, $store] = $this->tenantWithStorefront();
        $product = $this->productIn($store);

        $orderId = DB::table('orders')->insertGetId([
            'product_order_id' => 'f237-legacy-' . uniqid(),
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            StockMovement::UNKNOWN,
            StockMovement::movedOutFor($store->id, $orderId, $product->id, null),
            'an order with no movement rows at all must read UNKNOWN. Reading NOT_MOVED would be '
            . 'the inference D-15 conditions 4 and 5 forbid, and would let a cancellation skip a '
            . 'restore that is genuinely owed.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // 4. STOCK MOVED BUT THE ORDER WRITE FAILED — the owner asked for this case by name.
    // ---------------------------------------------------------------------------------------

    /**
     * When the order never persists, the movement must stay UNATTRIBUTED and stay visible.
     *
     * This is the case `unattributed()` was built for and the one nothing drove: *stock moved and
     * no order owns it* — the stock-side twin of F186's captured-payment-without-order. The failure
     * is injected at the only point that reproduces it faithfully: an `Order` `creating` listener
     * that throws, so the decrement has already happened (it runs before the transaction opens) and
     * the order write is what fails.
     *
     * Two things are asserted together, and the second is the one that matters: the row must NOT be
     * attributed to anything, AND it must still be there. A design that quietly deleted the movement
     * on failure would satisfy "not attributed" while destroying the only evidence that stock moved.
     *
     * **THIS IS A CHARACTERIZATION TEST AND IT PASSES BOTH BEFORE AND AFTER THE FIX, which is
     * stated here rather than left for someone to discover.** Measured: with
     * `attributeStockEvidenceTo()` neutered to a no-op, it still passes. It must — the failure is
     * injected on `creating`, so `$order->save()` never returns and the attribution never runs, and
     * "unattributed" is then true for the trivial reason. It is kept because it pins the owner's
     * named scenario and the survival of the evidence, and the *transactional* property it cannot
     * reach is covered by the test below, which does bite.
     */
    public function test_stock_that_moved_is_left_unattributed_when_the_order_write_fails(): void
    {
        [, $store] = $this->tenantWithStorefront();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        \App\Models\Order::creating(static function () {
            throw new \RuntimeException('f237: injected order-persistence failure');
        });

        $this->withCookie('cart', $sid)->post('/' . $store->slug . '/whatsapp', $this->postBody($store));

        $this->assertSame(
            0,
            (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'premise: an order was written despite the injected failure, so this is not the '
            . 'order-persistence-failed case'
        );

        $movements = DB::table('stock_movements')
            ->where('store_id', $store->id)
            ->where('direction', StockMovement::OUT)
            ->get();

        $this->assertNotCount(
            0,
            $movements,
            'the movement record was lost when the order write failed. The row IS the evidence that '
            . 'stock moved with no order owning it; deleting it on failure would destroy exactly '
            . 'what `unattributed()` exists to surface.'
        );

        foreach ($movements as $movement) {
            $this->assertNull(
                $movement->order_id,
                'a movement was attributed to an order that does not exist. Attribution runs inside '
                . 'the order transaction precisely so a rollback leaves the row unattributed.'
            );
        }
    }

    /**
     * ATOMICITY, and this is the one that bites: a rollback AFTER the attribution must undo it.
     *
     * The test above injects its failure before the order row exists, so the attribution never
     * runs and nothing about transactions is proven. Here the failure lands on the *next* write
     * after the order — `OrderBillingDetail`, created at `PaymentController:3702`/`:5128`, i.e.
     * after `$order->save()` and after the attribution, and before `DB::commit()` at `:5264`.
     *
     * So the sequence really exercised is: stock moves and is recorded (before the transaction
     * opens, and therefore already committed) → the order is inserted → the movement is attributed
     * to it → the transaction rolls back. The movement must survive, because the stock genuinely
     * moved; the attribution must not, because the order it named no longer exists.
     *
     * **WHAT THIS DOES AND DOES NOT PROVE, measured rather than assumed.** An earlier draft of
     * this docblock claimed that an attribution placed outside the transaction would fail here.
     * That was checked and it is FALSE: moving the call below `DB::commit()` passes this test and
     * every other test in this file, because on the rollback path the attribution then simply
     * never runs and "unattributed" is true either way. So this test proves the movement survives
     * a rollback and is not left pointing at a vanished order — and the *placement* it cannot
     * reach is held by a source assertion in the census above instead. An attribution that ran on
     * a separate connection, and so survived the rollback, is what this test would catch.
     */
    public function test_a_rollback_after_the_attribution_leaves_the_movement_unattributed(): void
    {
        [, $store] = $this->tenantWithStorefront();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        \App\Models\OrderBillingDetail::creating(static function () {
            throw new \RuntimeException('f237: injected failure after the order was persisted');
        });

        $this->withCookie('cart', $sid)->post('/' . $store->slug . '/whatsapp', $this->postBody($store));

        $this->assertSame(
            0,
            (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'premise: the order survived a failure that should have rolled it back, so this is not '
            . 'the post-persistence-rollback case'
        );

        $movements = DB::table('stock_movements')
            ->where('store_id', $store->id)
            ->where('direction', StockMovement::OUT)
            ->get();

        $this->assertNotCount(0, $movements, 'the movement record did not survive the rollback');

        foreach ($movements as $movement) {
            $this->assertNull(
                $movement->order_id,
                'after the transaction rolled back, a movement is still attributed to an order id '
                . 'that no longer exists. Attribution must run INSIDE the order transaction so a '
                . 'rollback takes it with the order; placing it after the commit, or on a separate '
                . 'connection, produces exactly this orphaned pointer.'
            );
        }
    }

    // ---------------------------------------------------------------------------------------
    // 5. THE CANCELLATION GUARD, ON REAL ATTRIBUTED EVIDENCE — also asked for by name.
    // ---------------------------------------------------------------------------------------

    /**
     * The E13-C guard must now be answering from evidence a real request produced.
     *
     * Before F237 this could only ever read `UNKNOWN`, so the guard fell through on every real
     * order and its 55 tests were measuring a state produced by a hand-written
     * `attributeToOrder()` call. This drives the real route and then asks the guard's own question
     * for both a line that moved and a line that did not, because a guard that answered `MOVED` for
     * everything would be just as useless and would pass a one-sided test.
     */
    public function test_the_cancellation_guard_reads_real_attributed_evidence_after_a_real_checkout(): void
    {
        [, $store] = $this->tenantWithStorefront();
        $bought = $this->productIn($store);
        $neverBought = $this->productIn($store);
        $sid = $this->guestCart($store, $bought);

        $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/whatsapp', $this->postBody($store))
            ->assertOk();

        $orderId = (int) DB::table('orders')->where('store_id', $store->id)->value('id');
        $this->assertNotSame(0, $orderId, 'premise: no order was written');

        $this->assertSame(
            StockMovement::MOVED,
            StockMovement::movedOutFor($store->id, $orderId, $bought->id, null),
            'the guard still cannot see that the purchased line took stock out'
        );

        $this->assertSame(
            StockMovement::NOT_MOVED,
            StockMovement::movedOutFor($store->id, $orderId, $neverBought->id, null),
            'a line this order never bought reads as having moved. The order IS observed now — it '
            . 'has an out row — so its other lines must read NOT_MOVED rather than UNKNOWN, which '
            . 'is the `$observed` fallback doing its job. Answering MOVED for every line would make '
            . 'the guard useless in the opposite direction.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // 6. IDEMPOTENCY, RETRIES, TENANT ISOLATION, AND THE REFUSAL TO CALL FAILURE SUCCESS.
    //    Driven against the service and the trait directly, because a second HTTP request
    //    cannot reach the writer: the intake lock (PaymentController:4449) refuses it first,
    //    so an HTTP-only retry test would measure the lock, not the attribution.
    // ---------------------------------------------------------------------------------------

    /** A retried return must not re-point one checkout's stock at a second order. */
    public function test_a_retried_attribution_cannot_repoint_the_movements_to_another_order(): void
    {
        [, $store] = $this->tenantWithStorefront();
        $product = $this->productIn($store);

        $token = StockMovement::newToken();
        $this->recordOutMovement($store->id, $product->id, $token);

        $first = (int) DB::table('orders')->insertGetId([
            'product_order_id' => 'f237-a-' . uniqid(), 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $second = (int) DB::table('orders')->insertGetId([
            'product_order_id' => 'f237-b-' . uniqid(), 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, StockMovement::attributeToOrder($token, $store->id, $first));
        $this->assertSame(
            0,
            StockMovement::attributeToOrder($token, $store->id, $second),
            'a second order claimed stock that already belongs to the first'
        );

        $this->assertSame(
            StockMovement::ATTRIBUTED,
            StockMovement::attributionStateFor($token, $store->id, $first),
            'the rows stopped belonging to the order that actually claimed them'
        );
        $this->assertSame(
            StockMovement::NOT_ATTRIBUTED,
            StockMovement::attributionStateFor($token, $store->id, $second),
            'the second order is reported as owning stock it never claimed. This is the state that '
            . 'must NOT read as ATTRIBUTED: a zero row count there means "someone else got there '
            . 'first", not "done".'
        );
    }

    /** Attribution must not reach another tenant's movements. */
    public function test_attribution_is_store_scoped_in_both_directions(): void
    {
        [, $storeA] = $this->tenantWithStorefront();
        [, $storeB] = $this->tenantWithStorefront();
        $productB = $this->productIn($storeB);

        $token = StockMovement::newToken();
        $this->recordOutMovement($storeB->id, $productB->id, $token);

        $orderInA = (int) DB::table('orders')->insertGetId([
            'product_order_id' => 'f237-cross-' . uniqid(), 'store_id' => $storeA->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(
            0,
            StockMovement::attributeToOrder($token, $storeA->id, $orderInA),
            'store A attributed store B\'s stock movement to one of its own orders'
        );

        $this->assertSame(
            StockMovement::NOTHING_RECORDED,
            StockMovement::attributionStateFor($token, $storeA->id, $orderInA),
            'store A can see that store B\'s token exists'
        );

        $this->assertNull(
            DB::table('stock_movements')->where('store_id', $storeB->id)->value('order_id'),
            'store B\'s movement was mutated by a request in store A'
        );
    }

    /**
     * REQUIREMENT 4, DIRECTLY: a zero row count that is not success must be logged, not swallowed.
     *
     * The trait is exercised through a harness rather than a route because this state cannot be
     * produced by a request: it needs movements that exist under the token and are already owned by
     * a DIFFERENT order. That is the shape a genuine attribution failure takes, and the thing being
     * asserted is that the code refuses to call it done.
     */
    public function test_an_attribution_that_moved_nothing_is_reported_and_never_treated_as_success(): void
    {
        [, $store] = $this->tenantWithStorefront();
        $product = $this->productIn($store);

        $token = StockMovement::newToken();
        $this->recordOutMovement($store->id, $product->id, $token);

        $owner = (int) DB::table('orders')->insertGetId([
            'product_order_id' => 'f237-owner-' . uniqid(), 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StockMovement::attributeToOrder($token, $store->id, $owner);

        $stranger = new \App\Models\Order();
        $stranger->store_id = $store->id;
        $stranger->product_order_id = 'f237-stranger-' . uniqid();
        $stranger->save();

        $harness = new StockAttributionHarness();
        $harness->useToken($token);

        \Illuminate\Support\Facades\Log::spy();

        $harness->attributeTo($stranger);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(static fn ($message, $context = []) => str_contains((string) $message, 'not attributed')
                && ($context['state'] ?? null) === StockMovement::NOT_ATTRIBUTED)
            ->once();
    }

    /** A host with no token attributes nothing, and says nothing. */
    public function test_a_host_with_no_token_attributes_nothing_and_logs_nothing(): void
    {
        [, $store] = $this->tenantWithStorefront();

        $order = new \App\Models\Order();
        $order->store_id = $store->id;
        $order->product_order_id = 'f237-notoken-' . uniqid();
        $order->save();

        \Illuminate\Support\Facades\Log::spy();

        /* No useToken() call, so the host is in the state a request that never touched stock is in. */
        (new StockAttributionHarness())->attributeTo($order);

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    }

    /**
     * PRODUCTION SHAPE: with no record installed, a real checkout must say nothing at all.
     *
     * **This is the case the first version of the fix got wrong, and the case no other test in
     * this file can see.** The migration is development-and-test only, so `available()` is false
     * on every live installation — but `stockEvidence()` mints the correlation token without
     * consulting `available()`, so the "no token" early return does not fire there. The first
     * version therefore reached `attributionStateFor()`, got `UNKNOWN`, and logged it at ERROR:
     * one error-level line per order, on all eleven paths, on the money path, for a condition that
     * is deliberate.
     *
     * Every other test here runs under `RefreshDatabase`, which creates the table — so the suite
     * is structurally blind to production's actual state unless a test takes the table away. This
     * one does, which is the only way the regression is observable.
     *
     * It asserts the checkout still *works* as well as staying quiet: a guard that refused the
     * order would also produce no error log, and would be far worse than the defect.
     */
    public function test_with_no_record_installed_a_real_checkout_is_silent_and_still_succeeds(): void
    {
        [, $store] = $this->tenantWithStorefront();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        \Illuminate\Support\Facades\Schema::drop('stock_movements');

        $this->assertFalse(
            StockMovement::available(),
            'premise: the record is still installed, so this is not the production shape'
        );

        /*
         * Listening rather than Log::spy()->shouldNotHaveReceived('error'), and the reason is a
         * measured one: this path ALREADY logs an unrelated error on this fixture --
         * "Order Observer - Created Error: ... no such table: store_wallets", because
         * `store_wallets` has no migration at all (a GATE F table; it is why StoreWallet appears
         * in the manifest's declared skips). A blanket "no error was logged" assertion therefore
         * fails for a reason that has nothing to do with attribution, and would read as this
         * change's fault. So the assertion is narrowed to THIS change's own log line.
         */
        $attributionErrors = [];
        \Illuminate\Support\Facades\Log::listen(static function ($event) use (&$attributionErrors) {
            if (in_array($event->level, ['warning', 'error', 'critical', 'emergency'], true)
                && str_contains((string) $event->message, 'stock evidence')) {
                $attributionErrors[] = $event->level . ': ' . $event->message;
            }
        });

        $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/whatsapp', $this->postBody($store))
            ->assertOk();

        $this->assertSame(
            1,
            (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'the checkout stopped working when the stock-evidence record was absent. The record is '
            . 'development-and-test only, so this is the shape of EVERY live installation.'
        );

        $this->assertSame(
            [],
            $attributionErrors,
            'With no stock-evidence record installed, the attribution logged at error level. The '
            . 'migration is development-and-test only, so this is the shape of EVERY live '
            . 'installation: one error-level line per order, on eleven paths, on the money path, '
            . 'for a condition that is deliberate. `StockMovement` states the governing rule at '
            . ':137-139 -- "UNKNOWN is not a failure mode, it is the whole point".'
        );
    }

    /**
     * F2 (review finding): a PARTIAL recording failure must not be reported as success.
     *
     * The first version of the attribution asked `attributeToOrder() > 0` — *"did ANY row move"*.
     * On a two-line basket where one line recorded nothing, that returns 1, the test passes, and
     * the method returns silently while half the evidence is missing.
     *
     * The missing row is produced the way production can produce it:
     * `ProductStockService::recordMovement()` returns without writing when the decremented model
     * carries no `store_id` (`:427-429`) — which `Api/ApiController.php:8760-8764` records as a
     * live open owner decision for NULL-`store_id` variant rows. So one product in the basket is
     * given a null `store_id`, and its decrement therefore moves stock and records nothing.
     *
     * Why it matters rather than being cosmetic: `movedOutFor()`'s `$observed` fallback asks
     * whether THE ORDER took anything out, not whether THIS LINE did. Once the sibling line is
     * attributed the order is "observed", so the unrecorded line reads **NOT_MOVED** — and
     * `OrderController`'s cancellation treats NOT_MOVED as "nothing to give back" and skips the
     * restore. Stock stays consumed on a cancelled order. That is the D-15 conditions 4/5
     * direction, reached through an inference nobody made deliberately.
     *
     * The fix reports; it does not repair. The missing row cannot be reconstructed, and inventing
     * one would be manufacturing evidence.
     */
    public function test_a_partially_recorded_basket_is_reported_and_not_called_success(): void
    {
        [, $store] = $this->tenantWithStorefront();

        $recorded = $this->productIn($store);
        $unrecorded = $this->productIn($store);

        /* The decrement still applies -- only the EVIDENCE is lost, which is the whole point. */
        DB::table('products')->where('id', $unrecorded->id)->update(['store_id' => null]);

        $sid = 'f237-partial-' . uniqid();
        foreach ([$recorded, $unrecorded] as $product) {
            Cart::forceCreate([
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'price' => 100,
                'store_id' => $store->id, 'cookie_session_id' => $sid,
            ]);
        }

        $incomplete = [];
        \Illuminate\Support\Facades\Log::listen(static function ($event) use (&$incomplete) {
            if ($event->level === 'warning' && str_contains((string) $event->message, 'stock evidence incomplete')) {
                $incomplete[] = $event->context;
            }
        });

        $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/whatsapp', $this->postBody($store))
            ->assertOk();

        $orderId = (int) DB::table('orders')->where('store_id', $store->id)->value('id');
        $this->assertNotSame(0, $orderId, 'premise: no order was written');

        $this->assertSame(
            1,
            DB::table('stock_movements')->where('direction', StockMovement::OUT)->count(),
            'premise: this basket did not produce exactly one recorded and one unrecorded line, '
            . 'so it is not the partial case'
        );

        $this->assertNotCount(
            0,
            $incomplete,
            'a basket whose evidence is only half recorded was reported as a successful '
            . 'attribution. `attributeToOrder() > 0` asks "did ANY row move"; the question that '
            . 'matters is whether EVERY applied decrement ended up owned.'
        );

        $this->assertSame(2, $incomplete[0]['applied_decrements'] ?? null);
        $this->assertSame(1, $incomplete[0]['attributed_rows'] ?? null);
    }

    /**
     * F4 (review finding): the order writers that record NO evidence at all, pinned by name.
     *
     * The migration claimed that because `ProductStockService` is the only mutator of the stock
     * columns, "absent now means absent". Routing the writes through one service is necessary and
     * not sufficient: the service records only when its caller passes an evidence context, and
     * `PaymentService` — which is not a trait user, carries its own private `decrementStockOrFail()`
     * and persists its own orders — passes none. For those orders an absent record still means
     * "no decrement OR a decrement by an uninstrumented writer", which is the inference D-21
     * claimed to have retired. The migration's claim is now withdrawn rather than left standing.
     *
     * **This is a declared-exception ratchet, not an endorsement.** It is safe today — with no
     * rows at all, `movedOutFor()` answers UNKNOWN and the cancellation restores — and it stops
     * being a silent gap because wiring PaymentService, or adding a second such writer, fails
     * here and forces the migration's wording to be revisited at the same time.
     *
     * Why the controller census could not catch it: `OrderWriteCensus::sites()` walks only
     * `app/Http/Controllers`, so nothing under `app/Services` is in its population at all.
     */
    public function test_the_uninstrumented_order_writers_are_exactly_these(): void
    {
        $uninstrumented = [];

        foreach ((array) glob(base_path('app/Services/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            $writesAnOrder = preg_match('/new\s+Order\s*\(|\bOrder::(create|forceCreate|updateOrCreate)\s*\(/', $source) === 1;
            $decrements = str_contains($source, 'decrementVariantStock') || str_contains($source, 'decrementProductStock');

            if (! $writesAnOrder || ! $decrements) {
                continue;
            }

            /* Does EVERY decrement call pass an evidence argument? */
            preg_match_all('/decrement(?:Variant|Product)Stock\s*\(([^;]*?)\)\s*;/s', $source, $calls);
            $threadsEvidence = true;

            foreach ($calls[1] as $arguments) {
                if (! str_contains($arguments, 'vidence')) {
                    $threadsEvidence = false;

                    break;
                }
            }

            if (! $threadsEvidence) {
                $uninstrumented[] = basename($file);
            }
        }

        sort($uninstrumented);

        $this->assertSame(
            ['PaymentService.php'],
            $uninstrumented,
            "The set of order-writing services whose stock decrements record NO evidence changed.\n"
            . "If one was WIRED: good -- remove it here AND revisit the migration's withdrawn\n"
            . "\"absent now means absent\" paragraph, which that wiring partly restores.\n"
            . "If one was ADDED: its orders now have stock movements that cannot be seen at all,\n"
            . "so `movedOutFor()` answers UNKNOWN for them and no cancellation guard can ever\n"
            . "refuse on their evidence."
        );
    }

    private function recordOutMovement($storeId, $productId, string $token): void
    {
        StockMovement::record([
            'store_id' => $storeId,
            'product_id' => $productId,
            'variant_id' => null,
            'direction' => StockMovement::OUT,
            'quantity' => 1,
            'applied' => true,
            'reason' => StockMovement::REASON_ORDER_PLACE,
            'correlation_token' => $token,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Fixture. Shaped after WhatsappOrderConcurrencyTest, which proves this route really
    // writes an order (its test_an_ordinary_request_is_not_refused_by_the_guard).
    // ---------------------------------------------------------------------------------------

    private function methodBodyWithoutComments(string $file, string $method): ?string
    {
        $reflection = new \ReflectionClass(OrderWriteCensus::class);
        $methodBody = $reflection->getMethod('methodBody');
        $methodBody->setAccessible(true);

        return $methodBody->invoke(null, $file, $method);
    }

    private function postBody($store): array
    {
        return [
            'slug' => $store->slug,
            'customer_id' => '',
            'payment_type' => 'whatsapp',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }

    private function guestCart($store, Product $product): string
    {
        $sid = 'f237-cookie-' . uniqid();

        Cart::forceCreate([
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => 1,
            'price' => 100,
            'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'f237-' . uniqid(), 'slug' => 'f237-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenantWithStorefront(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'f237-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        /* The handler reads the platform admin through Cache::remember('admin_details', 3600), so
           without this it sees a User captured before this fixture existed. */
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            ['countries', ['id' => 101, 'name' => 'Syria']],
            ['states', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101]],
            ['cities', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1]],
        ] as [$table, $row]) {
            DB::table($table)->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        return [$merchant, $store];
    }
}

/**
 * A minimal host for the trait, so the attribution contract can be driven directly.
 *
 * Needed because two of the states that matter cannot be produced by an HTTP request: a retried
 * attribution is refused by the intake lock before it reaches the writer, and "rows exist but
 * belong to another order" is not a shape a single checkout can create. Testing the trait through
 * a harness is the same choice `StockMovementEvidenceTest` makes when it calls the service
 * directly, and it is paired here with the production-path test rather than replacing it.
 */
class StockAttributionHarness
{
    use \App\Http\Controllers\Concerns\AppliesCartStockChanges;

    /** Pretend a stock call already minted this token on this request. */
    public function useToken(string $token): void
    {
        $reflection = new \ReflectionObject($this);
        $property = $reflection->getProperty('ssStockEvidenceToken');
        $property->setAccessible(true);
        $property->setValue($this, $token);
    }

    public function attributeTo($order): void
    {
        $this->attributeStockEvidenceTo($order);
    }
}
