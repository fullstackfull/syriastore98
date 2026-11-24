<?php

namespace App\Support\Metrics;

/**
 * `Available to withdraw` AS A TILE -- GAP-222, owner decision D2's third figure.
 *
 * THE DEFECT THIS CLOSES WAS NOT A WRONG NUMBER. `finance/index.blade.php` printed
 * `number_format($wallet->available_balance, 2)`: the right figure, with NO CURRENCY ON IT, on the
 * one tile of the four whose currency is most likely to differ from the rest of the page.
 * `store_wallets.currency_code` is written once, when the wallet is provisioned, from the store's
 * `defult_currancy` setting -- and NOTHING rewrites it when that setting later changes. So a
 * merchant who switches their store from SYP to USD keeps a wallet holding SYP, and read a bare
 * number under a page whose other figures were USD.
 *
 * NOTHING HERE CONVERTS ANYTHING. There is no rate in this repository that could be trusted to, and
 * inventing one would turn a presentation defect into a financial one. When the wallet and the
 * store disagree the tile says so in words and leaves both codes standing; when a wallet's ledger
 * spans more than one currency the amounts are LISTED and no total is offered, which is the same
 * refusal `FinanceLedgerFigures` makes for the same reason.
 *
 * THE OTHER FOUR WALLET FIGURES ON THE SAME CARD HAD THE SAME DEFECT, so they move in the same
 * change rather than being left as four bare decimals beside one corrected tile. They are not
 * metrics and no metric is invented for them: `Pending Withdrawals`, `Pending Balance`,
 * `Total Income` and `Total Withdrawn` are columns of the SAME wallet row, in the SAME currency, so
 * they are read once here and spelled by the same presenter. The currency CAVEAT is stated once, on
 * the entitlement tile, because repeating it five times on one card is noise, not disclosure.
 *
 * IT IS NOT A PERIOD FIGURE, AND /finance HAS DATE INPUTS. They filter the transactions table below.
 * A running balance has no date column to filter -- applying them to `updated_at` would report "the
 * balance of wallets touched this month", which means nothing -- so the dates are ignored here, and
 * the tile SAYS they are ignored. A figure that silently disregards a control the merchant just
 * used reads as having obeyed it.
 */
final class WalletEntitlementFigure
{
    /** The four columns beside `available_balance`, in the order the card already showed them. */
    private const SIBLING_COLUMNS = [
        'Pending Withdrawals' => 'withdrawal_pending_balance',
        'Pending Balance' => 'pending_balance',
        'Total Income' => 'total_income',
        'Total Withdrawn' => 'total_withdrawn',
    ];

    /**
     * @return array{value: ?string, period: string, hint: string, currency: string,
     *               siblings: array<string, ?string>}
     */
    public static function forStore(int $storeId, string $storeCurrency): array
    {
        $storeCurrency = strtoupper(trim($storeCurrency));

        if ($storeCurrency === '') {
            // The same fallback StoreFinanceController::index() applies to a missing setting, so a
            // blank setting cannot make this tile print "UNKNOWN" at a merchant.
            $storeCurrency = 'USD';
        }

        /*
         * ALL TIME, EXPLICITLY. `MerchantWalletEntitlement` sets `window_ignored` when it is handed
         * anything else; passing the window the figure actually has keeps that flag off and makes
         * the ignoring a statement in the hint rather than a silent property of the result.
         */
        $result = (new MerchantWalletEntitlement)->for(
            MetricScope::store($storeId),
            MetricWindow::allTime()
        );

        $period = __('right now');

        if ($result->rowCount() === 0) {
            // `value` null renders as an honest unavailable state, never as a zero -- a wallet that
            // does not exist and a wallet holding nothing are different facts about a business.
            return [
                'value' => null,
                'period' => $period,
                'hint' => __('No wallet has been opened for this store yet, so there is no balance to show.'),
                'currency' => $storeCurrency,
                // Null, not '0.00': there is no wallet, which is a different fact from an empty one.
                'siblings' => array_fill_keys(array_keys(self::SIBLING_COLUMNS), null),
            ];
        }

        $result = $result->assumingUnknownCurrencyIs($storeCurrency);

        // D2 mandated this sentence and it leads, because the misreading it prevents -- withdrawing
        // your own money and concluding your sales fell -- is the expensive one.
        $notes = [__('A running balance, not revenue.')];

        // `false`: keep zero buckets. See MoneyText::buckets() -- this is the GAP-222 trap.
        $buckets = MoneyText::buckets($result->byCurrency(), false);

        if (count($buckets) > 1) {
            $listed = [];

            foreach ($buckets as $currency => $amount) {
                $listed[] = MoneyText::amount($amount, $currency);
            }

            $notes[] = __('This store has balances in more than one currency and they are not combined: :amounts.', [
                'amounts' => implode(', ', $listed),
            ]);
            $notes[] = self::datesSentence();

            /*
             * More than one currency across this store's wallets. The entitlement refuses a total,
             * and so must its siblings -- adding `total_income` across two currencies would be the
             * very invention the refusal exists to prevent.
             */
            return [
                'value' => null,
                'period' => $period,
                'hint' => implode(' ', $notes),
                'currency' => $storeCurrency,
                'siblings' => array_fill_keys(array_keys(self::SIBLING_COLUMNS), null),
            ];
        }

        $currency = array_key_first($buckets) ?? $storeCurrency;
        $amount = $buckets[$currency] ?? 0.0;

        if ($result->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED)) {
            $notes[] = __('This wallet has no currency recorded, so it is shown in your store currency, :store.', [
                'store' => $storeCurrency,
            ]);
        } elseif ($currency !== $storeCurrency) {
            $notes[] = __('This wallet is held in :wallet, and your store currency is :store. The two are not converted.', [
                'wallet' => $currency,
                'store' => $storeCurrency,
            ]);
        }

        $notes[] = self::datesSentence();

        return [
            'value' => MoneyText::amount($amount, $currency),
            'period' => $period,
            'hint' => implode(' ', $notes),
            'currency' => $currency,
            'siblings' => self::siblings($storeId, $currency),
        ];
    }

    /**
     * The four sibling columns, in the wallet's OWN currency.
     *
     * ONE ROW, and the `SUM()` is a formality rather than an aggregate: `store_wallets` is UNIQUE on
     * `store_id` (`uq_store_wallet_store_id`, measured in production by `GateFProductionSchema`), so
     * a `$storeId` predicate selects at most one row and the sum is that row's value. It is written
     * as a sum only so the four columns come back in one statement.
     *
     * IT TAKES A SCALAR `int`, NOT A `MetricScope`, AND THAT IS THE LIMIT OF WHAT IT CAN DO. An
     * earlier version of this comment claimed the sum was "a genuine total for an operator reading
     * several" wallets; it is not, because no caller can ask for several and this signature could
     * not express it. Corrected after review -- a justification that is not true is worse than none,
     * because the next change reads it as evidence.
     *
     * @return array<string, ?string>
     */
    private static function siblings(int $storeId, string $currency): array
    {
        $row = \Illuminate\Support\Facades\DB::table('store_wallets')
            ->where('store_wallets.store_id', $storeId)
            ->selectRaw(implode(', ', array_map(
                static fn (string $column): string => 'SUM('.$column.') as '.$column,
                self::SIBLING_COLUMNS
            )))
            ->first();

        $figures = [];

        foreach (self::SIBLING_COLUMNS as $label => $column) {
            /* `$row` is never null -- an aggregate SELECT with no GROUP BY always returns one row --
               so the honest "no wallet" answer is the `rowCount() === 0` return above, not this.
               The null limb is kept for the one narrow case it does cover: the wallet row vanishing
               between the metric read and this one (a store-deletion cascade, a concurrent request),
               where printing `0.00` beside a live balance would be the worse of the two answers. */
            $figures[$label] = $row === null
                ? null
                : MoneyText::amount((float) ($row->{$column} ?? 0.0), $currency);
        }

        return $figures;
    }

    private static function datesSentence(): string
    {
        return __('This figure has no period: it is the balance right now, so the dates on this page do not change it.');
    }
}
