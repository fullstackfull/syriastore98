<?php

namespace Tests\Unit\Loyalty;

use App\Models\Customer;
use App\Support\Loyalty\LoyaltyBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `MKT-005` — READING A BALANCE THAT MAY NOT EXIST, WITHOUT THROWING AND WITHOUT LYING.
 * =================================================================================================
 *
 * `customers.total_club_point` is declared by **`packages/workdo/RewardClubPoint/src/database/
 * migrations/2024_04_17_105551_add_column_reward_club_point_to_customers_table.php`** — a VENDORED
 * module migration that only runs when the module is installed. `GAP-191`, re-measured by this
 * suite's own premise cases: host migrations declare it **nowhere**, and
 * `Schema::hasColumn('customers', 'total_club_point')` is **FALSE** here. Naming it unconditionally
 * throws `no such column`.
 *
 * Every existing first-party read of it is spelled `(float) ($customerDetail->total_club_point ?? 0)`
 * — eleven of them, measured. That works only because Eloquent returns null for an attribute the
 * row does not have, and it **collapses the two readings this class exists to keep apart**:
 *
 *   `0.0`  the customer has no points
 *   `null` nobody can see whether they do, because the substrate is not installed
 *
 * CLAUDE.md records a test that passed because `(int) null` is `0`. For a *discount* the collapse is
 * harmless — no column, no points, no discount. For a **ledger** it is not: a reconciliation that
 * read an absent column as `0` would report every customer as having drifted to zero, and a
 * movement row would claim a balance of zero that nobody ever observed.
 *
 * So {@see \App\Support\Loyalty\LoyaltyBalance::forCustomer()} returns **null**, and the two
 * directions are asserted below.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IT DOES NOT DO
 * -------------------------------------------------------------------------------------------------
 *
 * It has no writer, by design. Nothing in this slice writes a balance; see
 * {@see \App\Support\Loyalty\LoyaltyLedger}'s docblock for why that is an owner decision and not an
 * omission.
 *
 * @see \App\Support\Loyalty\LoyaltyBalance
 */
final class LoyaltyBalanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Give this schema the vendored column, so the cases that need it can run at all.
     *
     * The type is READ FROM the vendored migration rather than guessed, and the assertions make
     * that a premise rather than a hope — the same fixture `VipTierSegmentRuleTest` uses, and for
     * the same reason: `packages/workdo/` migrations only load with the module, so the module's own
     * migration cannot be run here.
     *
     * `float(15, 2)` emits a MySQL `double`; CLAUDE.md records a `FLOAT_PRECISION` caveat being
     * attached and WITHDRAWN on exactly that wording, so nothing here claims a precision defect
     * from it. The vendored migration declares `->default(0)`, which is reproduced, because
     * "absent column" and "column defaulting to 0" are the two states this class distinguishes and
     * the fixture must not blur them.
     */
    private function giveThisSchemaTheVendoredColumn(): void
    {
        $vendored = base_path(
            'packages/workdo/RewardClubPoint/src/database/migrations/'
            . '2024_04_17_105551_add_column_reward_club_point_to_customers_table.php'
        );

        $this->assertFileExists($vendored,
            'premise: the vendored migration that declares `customers.total_club_point` is still '
            . 'where this fixture read its type from. If it moved, re-read the type.');

        $this->assertStringContainsString('total_club_point', (string) file_get_contents($vendored),
            'premise: and it is still the file that declares the column.');

        if (! Schema::hasColumn('customers', 'total_club_point')) {
            Schema::table('customers', function ($table) {
                $table->float('total_club_point', 15, 2)->default(0);
            });
        }
    }

    private function customer(int $storeId, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Ledger', 'last_name' => 'Fixture',
            'email' => $email, 'store_id' => $storeId, 'status' => 1,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 1 — the substrate is absent: null, not zero
    |--------------------------------------------------------------------------
    */

    public function test_premise_the_vendored_column_is_absent_from_this_schema(): void
    {
        $this->assertFalse(
            Schema::hasColumn('customers', 'total_club_point'),
            'premise: GAP-191. If a host migration ever declares this column the whole capability '
            . 'gate stops being exercised and this file must be re-read.'
        );

        $this->assertFalse(LoyaltyBalance::available());
    }

    public function test_with_the_column_absent_the_balance_is_null_and_not_zero(): void
    {
        $this->assertFalse(Schema::hasColumn('customers', 'total_club_point'),
            'premise: this case is about the absent substrate.');

        $customer = $this->customer(7, 'absent@example.com');

        $balance = LoyaltyBalance::forCustomer(7, (int) $customer->id);

        $this->assertNull(
            $balance,
            'NULL and not 0.0. `0.0` says the customer has no points; null says nobody can see '
            . 'whether they do. A reconciliation that read the absent column as 0 would report '
            . 'every customer as drifted.'
        );

        /*
         * The distinction is only meaningful if `0.0 !== null` is actually asserted somewhere a
         * mutant can be caught by. `assertNull` alone passes for a method that returns null
         * unconditionally, which the next section's cases are what rule out.
         */
        $this->assertNotSame(0.0, $balance);
    }

    public function test_with_the_column_absent_reading_a_balance_does_not_throw(): void
    {
        $this->assertFalse(Schema::hasColumn('customers', 'total_club_point'),
            'premise: this case is about the absent substrate.');

        $customer = $this->customer(7, 'nothrow@example.com');

        /* The whole trap: a bare `where('total_club_point', ...)` here is `no such column`. */
        LoyaltyBalance::forCustomer(7, (int) $customer->id);

        $this->addToAssertionCount(1);
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — the substrate is present: the real figure
    |--------------------------------------------------------------------------
    */

    public function test_with_the_column_present_it_reads_the_stored_balance(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $this->assertTrue(LoyaltyBalance::available(),
            'premise: the fixture really added the column.');

        $customer = $this->customer(7, 'present@example.com');

        DB::table('customers')->where('id', $customer->id)->update(['total_club_point' => 125.5]);

        $this->assertSame(125.5, LoyaltyBalance::forCustomer(7, (int) $customer->id));
    }

    /**
     * A customer who genuinely has no points reads `0.0` — the OTHER half of the distinction.
     *
     * Without this case a method that returned null whenever the figure was falsy would pass every
     * assertion above, and the reconciliation would report "no history" for every customer at zero.
     */
    public function test_a_customer_with_no_points_reads_zero_and_not_null(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'zero@example.com');

        $this->assertSame(
            0.0,
            LoyaltyBalance::forCustomer(7, (int) $customer->id),
            'the vendored column defaults to 0, and a customer sitting at zero is a FACT. Only an '
            . 'absent column or an absent customer is unobservable.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3 — tenancy
    |--------------------------------------------------------------------------
    */

    /**
     * The store predicate is the reader's own, not a global scope's.
     *
     * `Customer` IS one of the eleven models whose `StoreScope` really filters — but
     * `null_tenant => 'open'` means it adds NO predicate when no tenant resolves, which is the state
     * of a console command, a queue worker and this test body. So a reader that leaned on the scope
     * would answer with another store's customer of the same id. `customers.id` values are reused
     * across tenants in a shared database.
     */
    public function test_it_does_not_read_another_stores_customer(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $theirs = $this->customer(8, 'theirs@example.com');
        DB::table('customers')->where('id', $theirs->id)->update(['total_club_point' => 999.0]);

        $this->assertNull(
            LoyaltyBalance::forCustomer(7, (int) $theirs->id),
            'asked as store 7, a store-8 customer is NOT FOUND — which is unobservable, not zero.'
        );

        $this->assertSame(
            999.0,
            LoyaltyBalance::forCustomer(8, (int) $theirs->id),
            'premise: the fixture row is really there and really carries that figure, so the null '
            . 'above is the predicate working and not the fixture failing.'
        );
    }

    public function test_an_absent_customer_is_unobservable_rather_than_zero(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $this->assertNull(LoyaltyBalance::forCustomer(7, 987654321));
    }

    /**
     * A SOFT-DELETED customer's balance is still readable, and that is deliberate.
     *
     * `Customer` uses `SoftDeletes`. A dispute about points is most likely to be raised about an
     * account that has since been closed, and the ledger rows for that customer do not disappear, so
     * a reconciliation that could not see the balance would report drift on every closed account.
     * This reads the ROW, through the raw table, rather than through a model whose default scope
     * hides it.
     */
    public function test_a_soft_deleted_customers_balance_is_still_observable(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'closed@example.com');
        DB::table('customers')->where('id', $customer->id)->update(['total_club_point' => 40.0]);

        $customer->delete();

        $this->assertNotNull(
            DB::table('customers')->where('id', $customer->id)->value('deleted_at'),
            'premise: the fixture really soft-deleted the row rather than removing it.'
        );

        $this->assertSame(40.0, LoyaltyBalance::forCustomer(7, (int) $customer->id));
    }
}
