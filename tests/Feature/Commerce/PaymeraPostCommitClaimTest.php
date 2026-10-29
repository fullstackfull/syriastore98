<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F96 — THE CLAIM MUST SURVIVE A THROW THAT HAPPENED *AFTER* THE ORDER COMMITTED.
 * =============================================================================================
 *
 * THE DEFECT THIS COVERS WAS IN THE REMEDY, NOT IN THE ORIGINAL FINDING, and it was found by the
 * mandated independent review of the F96 change rather than by a test. The first version of the
 * guard released its duplicate-write claim in a `catch (\Throwable)` unconditionally, with a
 * comment asserting *"the write did not complete"*. That assertion is false for a whole class of
 * throws:
 *
 *   `getProductStatus()` commits at `PaymentController:3187` and then runs roughly 110 lines of
 *   **unguarded** post-commit work — the store notification, the activity log, the store owner's
 *   email (`$owner->email` is a null dereference when the owner row is missing), three order
 *   notes, an event with synchronous listeners, the affiliate transaction — and
 *   `OrderCompleteBinding::remember()` is the **last** of them.
 *
 * So a throw in that tail leaves an order written, `$pending['processed']` still false, and **no
 * in-process marker of either**: not a return value, not a session binding, not a flag. Releasing
 * the claim there hands the next replay precisely the second order F96's guard exists to prevent
 * — and the comment told the next reviewer that arm was impossible, which is worse than the code.
 *
 * The only witness to a commit is the row, so the handler asks the database. These cases drive the
 * two private methods that ask it. They are **driven, not source-asserted**: the sibling file
 * `PaymeraCallbackIdempotencyTest` pins the claim's POSITION in the source because no Feature test
 * can stage the interleave, but this question is a plain query and nothing stops a test asking it.
 *
 * WHY A SEPARATE FILE. `PaymeraCallbackIdempotencyTest` deliberately has no `RefreshDatabase` —
 * its 26 cases are cache, session and source assertions, and adding the trait there would migrate
 * a database for every one of them.
 */
class PaymeraPostCommitClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_committed_reads_as_nothing_committed(): void
    {
        $mark = $this->highWaterMark(1);

        $this->assertSame(0, $mark, 'the premise: this store has no paymera order yet');
        $this->assertFalse(
            $this->committedSince(1, $mark),
            'a throw with no order written must release the claim, or a legitimate retry is'
            . ' refused until the claim expires'
        );
    }

    public function test_an_order_written_after_the_mark_is_seen(): void
    {
        $mark = $this->highWaterMark(1);
        $this->makeOrder(['store_id' => 1, 'payment_type' => 'paymera']);

        $this->assertTrue(
            $this->committedSince(1, $mark),
            'the order committed and the throw happened in the writer\'s post-commit tail; keeping'
            . ' the claim is the only thing standing between the buyer\'s refresh and a SECOND'
            . ' order for one payment'
        );
    }

    public function test_an_order_that_predates_the_mark_is_not_mistaken_for_this_write(): void
    {
        $this->makeOrder(['store_id' => 1, 'payment_type' => 'paymera']);
        $mark = $this->highWaterMark(1);

        $this->assertGreaterThan(0, $mark, 'the premise: the earlier order moved the mark');
        $this->assertFalse(
            $this->committedSince(1, $mark),
            'an order this store already had was read as one THIS request wrote, so a failed write'
            . ' keeps its claim forever and the retry is refused'
        );
    }

    /**
     * THE TENANT QUESTION, and it is the one that would not have shown up in a single-store test.
     *
     * The mark and the test are both per store. Another merchant's Paymera order committing
     * between the two reads must not make this store's failed write look successful — that would
     * refuse a legitimate retry on one tenant because an unrelated tenant was busy.
     */
    public function test_another_stores_order_is_not_this_stores_commit(): void
    {
        $mark = $this->highWaterMark(1);
        $this->makeOrder(['store_id' => 2, 'payment_type' => 'paymera']);

        $this->assertFalse(
            $this->committedSince(1, $mark),
            'another tenant\'s order was counted as this tenant\'s commit'
        );
    }

    public function test_a_different_payment_type_is_not_this_commit(): void
    {
        $mark = $this->highWaterMark(1);
        $this->makeOrder(['store_id' => 1, 'payment_type' => 'cod']);

        $this->assertFalse(
            $this->committedSince(1, $mark),
            'a COD order placed in the same store while the paymera write was failing was counted'
            . ' as the paymera commit'
        );
    }

    /**
     * FAIL CLOSED WHEN THE QUESTION CANNOT BE ASKED.
     *
     * Without a usable store id there is no query to run, and the answer decides whether to
     * release a duplicate-write claim. This codebase's own doctrine for a partial money claim is
     * to refuse rather than tidy up — `GuardsGatewayReturn:252-255`: *"un-refusing it to keep two
     * cache entries tidy would be the wrong direction for a money control"*. The claim's bounded
     * TTL is what keeps that from stranding a payment.
     *
     * @dataProvider unusableStoreIds
     */
    public function test_an_unusable_store_id_keeps_the_claim(mixed $storeId): void
    {
        $this->assertTrue(
            $this->committedSince($storeId, 0),
            'an unanswerable question released a money control\'s claim'
        );

        /*
         * The mark is asserted in its own case rather than here. A second review pointed out that
         * `assertSame(0, $this->highWaterMark($storeId))` CANNOT FAIL on this empty table: with the
         * guard, without it, and under a degraded `whereNull('store_id')` predicate, `max('id')`
         * returns null -> 0 either way. See
         * test_an_unusable_store_id_reads_no_mark_even_when_rows_would_match_a_degraded_predicate,
         * which inserts the rows that make the question answerable.
         */
    }

    /**
     * THE MARK, WITH A PREMISE THAT CAN ACTUALLY FAIL.
     *
     * `where('store_id', null)` compiles to `whereNull('store_id')` in Laravel — the fail-open trap
     * this codebase records as its own — and `where('store_id', 0)` matches a guest-ish zero row. So
     * the rows that a degraded predicate would pick up have to EXIST for the assertion to mean
     * anything. Here they do: one paymera order per unusable store value. Remove the
     * `$storeId <= 0` guard and each of these reads a mark it has no business reading.
     */
    public function test_an_unusable_store_id_reads_no_mark_even_when_rows_would_match_a_degraded_predicate(): void
    {
        $nullStore = $this->makeOrder(['store_id' => null, 'payment_type' => 'paymera']);
        $zeroStore = $this->makeOrder(['store_id' => 0, 'payment_type' => 'paymera']);
        $negative = $this->makeOrder(['store_id' => -1, 'payment_type' => 'paymera']);

        $this->assertGreaterThan(0, $nullStore + $zeroStore + $negative, 'the premise: the rows exist');

        foreach ([null, 0, -1, '', 'not-a-store'] as $storeId) {
            $this->assertSame(
                0,
                $this->highWaterMark($storeId),
                'the mark was read for an unusable store id ' . var_export($storeId, true)
                . ' -- which on null means the predicate degraded to whereNull() and read a row'
                . ' belonging to no store at all'
            );
        }
    }

    /**
     * THE QUESTION ITSELF CAN FAIL, AND THAT MUST NOT THROW.
     *
     * Both helpers are called from inside the handler's `catch`, and the exception being handled
     * there is most plausibly a DATABASE failure — the writer's work is database work. A second
     * review established that without this the catch throws its own exception instead of
     * rethrowing: the original error is lost, `Cache::forget()` is never reached, and the critical
     * log that is the only record of a post-commit order never fires.
     *
     * Dropping the table is how the query is made to fail without a fixture that pretends to be a
     * broken connection. What it proves is the shape: a query that raises reaches the helper's own
     * catch rather than the caller's.
     */
    public function test_a_question_that_cannot_be_asked_keeps_the_claim(): void
    {
        \Illuminate\Support\Facades\Schema::drop('orders');

        $this->assertTrue(
            $this->committedSince(1, 0),
            'a failing query propagated out of the helper. Inside the handler\'s catch that'
            . ' replaces the original exception, skips the rethrow and skips the critical log.'
        );
    }

    public function test_a_mark_that_cannot_be_read_is_zero(): void
    {
        \Illuminate\Support\Facades\Schema::drop('orders');

        $this->assertSame(
            0,
            $this->highWaterMark(1),
            'a failing mark read propagated. It runs in the normal path inside the writer\'s own'
            . ' try, so it would be handled by the catch with $ssPaymeraOrdersBefore unassigned.'
        );
    }

    /**
     * THE COMMENT'S LINE NUMBERS ARE ASSERTED, BECAUSE THEY HAVE NOW ROTTED TWICE.
     *
     * `paymeraOrderCommittedSince()`'s docblock cites four lines of the order writer to establish
     * that its post-commit tail is unguarded. Both earlier versions of that comment cited lines
     * which the same change had already moved — the second review caught the first set, and the
     * fix for it was stale again by ~120 lines before it was committed. A comment that points a
     * reviewer at the wrong line is the failure mode this project treats as worse than the code
     * being wrong, so the pointers are ratcheted rather than trusted.
     */
    public function test_the_cited_anchors_still_name_what_they_claim(): void
    {
        $file = base_path('app/Http/Controllers/PaymentController.php');
        $lines = explode("\n", (string) file_get_contents($file));

        $block = (string) strstr((string) file_get_contents($file), '(Measured 2026-10-29:');
        $block = substr($block, 0, (int) strpos($block, 'Read the structure as the claim'));

        $this->assertNotSame('', $block, 'the measured-anchor block is gone from the docblock');

        preg_match_all('/`([^`]+)`[\s*]*:(\d+)/', $block, $m, PREG_SET_ORDER);

        $this->assertCount(4, $m, 'the docblock no longer cites four anchors; it cited ' . count($m));

        foreach ($m as [, $token, $line]) {
            /*
             * The trailing `()` is dropped: the docblock names the CALL (`remember()`) and the
             * source line carries its arguments (`remember($order->id)`). Dropping it leaves the
             * class-and-method, which is still specific enough that a wrong line fails.
             */
            $token = rtrim($token, '()');

            $this->assertStringContainsString(
                $token,
                $lines[((int) $line) - 1] ?? '',
                'the docblock says line ' . $line . ' is `' . $token . '`, and it is not. The'
                . ' comment points a reviewer at the wrong line, which is how two earlier versions'
                . ' of it went stale.'
            );
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableStoreIds(): array
    {
        return [
            'null' => [null],
            'zero' => [0],
            'empty string' => [''],
            'not a number' => ['not-a-store'],
            'negative' => [-1],
        ];
    }

    // ---------------------------------------------------------------- reflection

    /** Private by design; reflection keeps it that way. */
    private function committedSince(mixed $storeId, int $mark): bool
    {
        $m = new \ReflectionMethod(PaymentController::class, 'paymeraOrderCommittedSince');
        $m->setAccessible(true);

        return $m->invoke(app(PaymentController::class), $storeId, $mark);
    }

    private function highWaterMark(mixed $storeId): int
    {
        $m = new \ReflectionMethod(PaymentController::class, 'paymeraOrderHighWaterMark');
        $m->setAccessible(true);

        return $m->invoke(app(PaymentController::class), $storeId);
    }

    private function makeOrder(array $attributes = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'product_order_id' => 'ORD-' . uniqid(),
            'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 0.0,
            'payment_type' => 'cod', 'payment_status' => 'Paid',
            'store_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }
}
