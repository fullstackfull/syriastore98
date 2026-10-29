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
        $this->assertSame(
            0,
            $this->highWaterMark($storeId),
            'and the mark must not be read from an unscoped query either'
        );
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
