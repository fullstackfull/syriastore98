<?php

namespace Tests\Feature\Commerce;

use App\Services\OrderFinancialService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

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
 * WHY THERE IS NO END-TO-END CASE HERE, which is a limit worth stating rather than hiding.
 * `store_order_financials` and `store_wallets` are GATE F tables: live code reads and writes
 * them, and NO migration in this repository creates them
 * (database/migrations/_gate_f_pending/README.md). They do not exist in the test schema, so
 * createForOrder() and syncFromOrder() cannot be exercised against a real row here, and
 * creating the tables to make that possible is the GATE F workstream, not this fix. What is
 * testable is the decision every one of those writes is derived FROM, and the census below is
 * the tripwire that would have caught the defect in the first place.
 */
class PaymentStatusNormalizationTest extends TestCase
{
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
     * The scope limit, asserted rather than described: while these tables are GATE F, no test
     * in this file can prove what landed in a financial row. If this ever fails, GATE F has
     * cleared and an end-to-end case belongs here.
     */
    public function test_the_financial_tables_are_still_absent_so_this_files_scope_is_honest(): void
    {
        foreach (['store_order_financials', 'store_wallets'] as $table) {
            $this->assertFalse(\Schema::hasTable($table),
                "{$table} now exists -- GATE F has cleared, so add the end-to-end case this file "
                . 'could not have: a succeeded order must produce paid_amount = the total and a paid_at.');
        }
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
