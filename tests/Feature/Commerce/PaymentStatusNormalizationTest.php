<?php

namespace Tests\Feature\Commerce;

use App\Services\OrderFinancialService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE PAYMENT STATUS A FINANCIAL RECORD IS WRITTEN WITH.
 *
 * THE DEFECT. `OrderFinancialService::normalizePaymentStatus()` turns the free text in
 * `orders.payment_status` into one of six states, and a value it does not recognise falls
 * through to `default => 'unpaid'`. Its match arms named 'paid', 'success' and 'completed'.
 * They did not name **'succeeded'** -- which is the most common spelling in this codebase by
 * a wide margin: twenty order-write sites against four for 'success'.
 *
 * WHAT THAT COST. `createForOrder()` derives everything else from this one answer
 * (OrderFinancialService:54-65): paid_amount is the customer total when the status is 'paid'
 * and zero otherwise, remaining_amount is the difference, and paid_at is set only when paid.
 * `syncFromOrder()` then uses the same answer to decide whether to call markFinancialAsPaid(),
 * which is what credits the merchant's wallet. So an order paid through Stripe, Mercado Pago,
 * Skrill, Midtrans, PayTabs, PayTR, Paytm, Flutterwave, CoinGate, NMI, SenangPay, PayU,
 * Powertranz, PayNow or MyFatoorah was recorded as unpaid, with the full total outstanding,
 * and the merchant's wallet was never credited for it.
 *
 * WHY IT SURVIVED. There was no test of this method at all -- not one, before this file.
 *
 * THERE IS AN END-TO-END CASE NOW, AND THE WAY THIS FILE GOT ONE IS THE LESSON.
 *
 * The paragraph that stood here said -- correctly, when written -- that `store_order_financials`
 * and `store_wallets` were GATE F tables created by no migration, so `createForOrder()` could not
 * be exercised against a real row. It set a tripwire to say when that changed:
 *
 *     test_the_financial_tables_are_still_absent_so_this_files_scope_is_honest()
 *     "If this ever fails, GATE F has cleared and an end-to-end case belongs here."
 *
 * GATE F cleared on 2026-11-02. THE TRIPWIRE DID NOT FIRE, and four days later it was still
 * green. The reason is the one this project has already recorded about a sibling file
 * (FinancialSurfaceCoverageTest's own docblock): **this class does not use `RefreshDatabase`**, so
 * no migration ran and `Schema::hasTable()` was answering against an empty `:memory:` database. It
 * was false for every table in the world, which made the absence assertion pass against a schema
 * that had nothing in it at all.
 *
 * That is a test passing for the wrong reason, in the exact shape the wave-2 census was built to
 * find -- and that census missed this one, because it was keyed on the ASSERTION's shape and the
 * thing that differs here is the CLASS SETUP. Found by an independent completeness sweep.
 *
 * So the class migrates now, the tripwire is inverted, and the case its own message demanded is
 * below.
 */
class PaymentStatusNormalizationTest extends SecurityTestCase
{
    /*
     * ADDED 2026-11-02, and it is the FIX, not a convenience. Without it `Schema::hasTable()`
     * answers against an un-migrated `:memory:` database -- see the class docblock. The other
     * forty cases here are pure functions over normalizePaymentStatus() and do not need a
     * database; they are unaffected by its presence.
     */
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /**
     * Every distinct literal this codebase writes to a `payment_status` column, and which
     * model owns it. MEASURED, by the census test below, not asserted from memory.
     *
     * THE PARTITION IS THE POINT. Only the ORDER rows reach the financial normaliser. The
     * others look like they belong to it and do not: Approved, Rejected and Failed are
     * `plan_orders.payment_status` -- BankTransferController and SSLCommerzPaymentController
     * operate on PlanOrder, the SaaS plan-purchase rail, which has its own column and never
     * touches OrderFinancialService -- and 'On Hold' / 'Pending payment' belong to the
     * PartialPayments package's own models. Teaching the order normaliser those words would be
     * inventing a vocabulary this column does not use.
     */
    private const KNOWN_WRITES = [
        // value          => owning model
        'Paid'            => 'order',
        'paid'            => 'order',
        'success'         => 'order',
        'succeeded'       => 'order',
        'Succeeded'       => 'order',
        'Pending'         => 'order',
        'Unpaid'          => 'order',
        'unpaid'          => 'order',
        'pending'         => 'order',
        'Approved'        => 'plan_order',
        'Rejected'        => 'plan_order',
        'Failed'          => 'plan_order',
        'On Hold'         => 'partial_payment',
        'Pending payment' => 'partial_payment',
        '-'               => 'pre_order_history',
    ];

    private function normalize(?string $raw): string
    {
        $method = new ReflectionMethod(OrderFinancialService::class, 'normalizePaymentStatus');
        $method->setAccessible(true);

        return $method->invoke(new OrderFinancialService(), $raw);
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT
    |--------------------------------------------------------------------------
    */

    /**
     * The regression itself. Both spellings, because the gateways disagree about the capital.
     */
    #[DataProvider('succeededSpellings')]
    public function test_succeeded_is_a_successful_payment(string $raw): void
    {
        $this->assertSame('paid', $this->normalize($raw),
            "'{$raw}' is how most of this platform's gateways say the money arrived; "
            . 'recording it as anything but paid leaves the merchant uncredited');
    }

    public static function succeededSpellings(): array
    {
        return [['succeeded'], ['Succeeded'], ['SUCCEEDED'], ['  succeeded  ']];
    }

    /*
    |--------------------------------------------------------------------------
    | THE PRESERVATION HALF -- nothing else moved
    |--------------------------------------------------------------------------
    */

    /**
     * The whole mapping, stated once. A change to any arm has to come through this table,
     * which is what stops a later "while I am here" edit quietly reclassifying money.
     */
    #[DataProvider('mapping')]
    public function test_the_mapping_is_exactly_this(?string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->normalize($raw));
    }

    public static function mapping(): array
    {
        return [
            // paid -- and only these
            'paid' => ['paid', 'paid'],
            'Paid' => ['Paid', 'paid'],
            'success' => ['success', 'paid'],
            'succeeded' => ['succeeded', 'paid'],
            'completed' => ['completed', 'paid'],

            // pending
            'pending' => ['pending', 'pending'],
            'Pending' => ['Pending', 'pending'],
            'processing' => ['processing', 'pending'],

            'partial' => ['partial', 'partially_paid'],
            'partially_paid' => ['partially_paid', 'partially_paid'],

            'refund' => ['refund', 'refunded'],
            'refunded' => ['refunded', 'refunded'],

            'cancel' => ['cancel', 'cancelled'],
            'cancelled' => ['cancelled', 'cancelled'],
            'canceled' => ['canceled', 'cancelled'],

            // Explicitly unpaid.
            'unpaid' => ['unpaid', 'unpaid'],
            'Unpaid' => ['Unpaid', 'unpaid'],

            // NOT PAID, AND THEY MUST STAY THAT WAY. An authorization is not a capture; a
            // dispute, chargeback, void or expiry is money this platform must never count as
            // received. None of these is written to orders.payment_status today, and the
            // conservative default is what makes that safe rather than lucky.
            'authorized' => ['authorized', 'unpaid'],
            'authorised' => ['authorised', 'unpaid'],
            'disputed' => ['disputed', 'unpaid'],
            'chargeback' => ['chargeback', 'unpaid'],
            'voided' => ['voided', 'unpaid'],
            'expired' => ['expired', 'unpaid'],
            'failed' => ['failed', 'unpaid'],
            'rejected' => ['rejected', 'unpaid'],

            // Garbage and absence are unpaid, never paid.
            'empty' => ['', 'unpaid'],
            'null' => [null, 'unpaid'],
            'nonsense' => ['hello', 'unpaid'],
        ];
    }

    /**
     * A near-miss must not be admitted. 'succeeded' is now paid; 'unsucceeded', 'not succeeded'
     * and 'succeeded_partially' are not the same word and must not ride in on a loose match.
     */
    #[DataProvider('nearMisses')]
    public function test_a_near_miss_is_not_treated_as_payment(string $raw): void
    {
        $this->assertNotSame('paid', $this->normalize($raw));
    }

    public static function nearMisses(): array
    {
        return [['unsucceeded'], ['not succeeded'], ['succeeded_partially'], ['succeeded-later'], ['pre-paid']];
    }

    /*
    |--------------------------------------------------------------------------
    | THE TRIPWIRE -- the test that would have caught this
    |--------------------------------------------------------------------------
    */

    /**
     * THE CENSUS. Every literal written to a payment_status column anywhere in app/ or
     * packages/, measured from the source and compared against the reviewed partition above.
     *
     * This is the assertion that fails when the next payment gateway is added with a spelling
     * nobody mapped -- which is exactly how 'succeeded' got in. A new value is not assumed to
     * be a mistake and is not auto-classified: the test fails, names it, and asks a person
     * which model owns it and what it means.
     */
    public function test_the_set_of_written_payment_statuses_is_the_reviewed_one(): void
    {
        $found = $this->writtenPaymentStatuses();

        $unreviewed = array_diff($found, array_keys(self::KNOWN_WRITES));
        $this->assertSame([], array_values($unreviewed),
            "A payment_status value is written that nobody has classified: '"
            . implode("', '", $unreviewed) . "'. Decide which model owns it. If it is written to "
            . 'orders.payment_status and means the money arrived, it MUST be added to '
            . 'OrderFinancialService::normalizePaymentStatus()\'s paid arm, or the merchant will '
            . 'not be credited -- that is the defect this file exists for.');

        $gone = array_diff(array_keys(self::KNOWN_WRITES), $found);
        $this->assertSame([], array_values($gone),
            'A classified payment_status value is no longer written anywhere: ' . implode(', ', $gone)
            . '. If a provider was retired, drop it from KNOWN_WRITES in the same change.');
    }

    /**
     * EVERY ORDER-OWNED VALUE CLASSIFIES CORRECTLY, derived from the census rather than
     * restated. A value written to an order and silently landing on the default is the shape
     * of the defect, so each one is checked against what it actually means.
     */
    public function test_every_value_written_to_an_order_normalizes_to_its_real_meaning(): void
    {
        $meaning = [
            'Paid' => 'paid', 'paid' => 'paid', 'success' => 'paid', 'succeeded' => 'paid',
            'Succeeded' => 'paid', 'Pending' => 'pending', 'pending' => 'pending',
            'Unpaid' => 'unpaid', 'unpaid' => 'unpaid',
        ];

        foreach (self::KNOWN_WRITES as $value => $owner) {
            if ($owner !== 'order') {
                continue;
            }

            $this->assertArrayHasKey($value, $meaning, "order value '{$value}' has no declared meaning");
            $this->assertSame($meaning[$value], $this->normalize($value),
                "'{$value}' is written to orders.payment_status and must normalize to '{$meaning[$value]}'");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | THE LYNOMIA HALF -- the commerce API consumed by the integration
    |--------------------------------------------------------------------------
    */

    /**
     * The Lynomia commerce API keeps its OWN map (CommerceReader::PAYMENT_STATUSES) because it
     * needs 'unknown' where the financial record needs 'unpaid': telling an agent a payment is
     * unpaid is a positive claim, and the honest answer for a word nobody recognises is that
     * we do not know. The two therefore cannot be merged -- but on every value an order really
     * holds they must AGREE, or the panel and the ledger tell a merchant different stories
     * about the same order. Held here rather than by hope.
     */
    public function test_the_commerce_api_and_the_financial_record_agree_on_every_real_value(): void
    {
        $reader = new \ReflectionClass(\Workdo\LynomiaChat\app\Services\CommerceReader::class);
        $apiMap = $reader->getConstant('PAYMENT_STATUSES');

        foreach (self::KNOWN_WRITES as $value => $owner) {
            if ($owner !== 'order') {
                continue;
            }

            $api = $apiMap[strtolower(trim($value))] ?? 'unknown';
            $this->assertSame($this->normalize($value), $api,
                "the commerce API reports '{$value}' as '{$api}' while the financial record calls it "
                . "'{$this->normalize($value)}'");
        }
    }

    /**
     * THE INVERSE OF THE OLD TRIPWIRE. It asserted these tables were ABSENT and passed vacuously
     * for four days after they arrived, because the class did not migrate -- see the class
     * docblock. Both halves are fixed: the class migrates, and this asserts presence.
     */
    public function test_the_financial_tables_are_present_so_an_end_to_end_case_is_possible(): void
    {
        foreach (['store_order_financials', 'store_wallets'] as $table) {
            $this->assertTrue(
                \Schema::hasTable($table),
                "{$table} is missing. GATE F declared it on 2026-11-02 with a structure measured "
                . 'against a restored copy of production, so this means the migration has been '
                . 'reverted or renamed -- and the end-to-end case below cannot run.'
            );
        }
    }

    /**
     * THE END-TO-END CASE THE OLD TRIPWIRE'S OWN MESSAGE DEMANDED, verbatim: *"a succeeded order
     * must produce paid_amount = the total and a paid_at"*.
     *
     * It is the only test anywhere that joins the two halves this file is about -- the spelling in
     * `orders.payment_status`, and what `createForOrder()` therefore WRITES. The forty cases above
     * prove the normaliser maps 'succeeded' to 'paid'; this proves that answer reaches the row,
     * which is where the money was lost: `paid_amount` is the customer total only when the status
     * normalises to 'paid', and `paid_at` is set only then.
     */
    public function test_a_succeeded_order_is_recorded_as_paid_with_a_paid_at(): void
    {
        // THE FIXTURES ARE THE SHARED ONES, not hand-rolled. The first version of this test built
        // the `stores` row itself and was refused by `stores.theme_id` being NOT NULL -- the same
        // class of fixture guess GATE F has already falsified three times. makeTenant() and
        // makeOrder() are the proven shapes, which is why this class now extends
        // SecurityTestCase: a thin base whose only setUp is to disable Laratrust's permission
        // cache, which the forty pure cases above do not notice.
        [, $store] = $this->makeTenant();

        \App\Models\StoreWallet::create([
            'store_id' => $store->id,
            'currency_code' => 'USD',
            'available_balance' => 0,
            'pending_balance' => 0,
            'total_income' => 0,
            'total_withdrawn' => 0,
        ]);

        // 'succeeded' is the spelling this file exists for: twenty order-write sites use it, and
        // the normaliser's match arms did not name it.
        $order = $this->makeOrder($store, [
            'price' => 250,
            'final_price' => 250,
            'payment_status' => 'succeeded',
        ]);

        $financial = \Illuminate\Support\Facades\DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->first();

        $this->assertNotNull(
            $financial,
            'No financial row was written for the order. OrderObserver::created() calls '
            . 'createForOrder() inside a try/catch that only logs, so a failure here is silent in '
            . 'production -- which is why this assertion exists before the ones below.'
        );

        $this->assertSame(
            'paid',
            $financial->payment_status,
            'A \'succeeded\' order was NOT recorded as paid. normalizePaymentStatus() has stopped '
            . 'naming that spelling again, and every figure below it derives from this one answer.'
        );

        $this->assertEqualsWithDelta(
            (float) $financial->customer_total,
            (float) $financial->paid_amount,
            0.01,
            'the paid amount is not the customer total, although the status normalised to paid'
        );

        $this->assertEqualsWithDelta(
            0.0,
            (float) $financial->remaining_amount,
            0.01,
            'a fully paid order still shows an outstanding remainder'
        );

        $this->assertNotNull(
            $financial->paid_at,
            'paid_at is null on a paid record, so nothing downstream can tell WHEN it was paid'
        );
    }

    /** @return array<int,string> every distinct literal assigned to a payment_status column */
    private function writtenPaymentStatuses(): array
    {
        $values = [];

        foreach (['app', 'packages'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                // Both shapes the codebase uses: an array entry and a property assignment.
                foreach (["/'payment_status'\s*=>\s*'([^']*)'/", "/->payment_status\s*=\s*'([^']*)'/"] as $pattern) {
                    preg_match_all($pattern, $source, $matches);
                    $values = array_merge($values, $matches[1]);
                }
            }
        }

        // Validation rules ('required|in:Paid,Unpaid') are not writes.
        $values = array_filter($values, static fn ($v) => ! str_contains($v, '|') && ! str_contains($v, ':'));

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
