<?php

namespace App\Support\Metrics;

/**
 * SECTION 7 -- What the merchant may withdraw. `store_wallets.available_balance`.
 *
 * **IT IS NOT REVENUE AND MUST NEVER BE LABELLED AS SUCH IN ANY UI.** It is reduced by
 * withdrawals (`total_withdrawn`) and by reversals, and it is denominated in the WALLET'S
 * currency, which can differ from the order's. A merchant shown this figure under a heading like
 * "sales" will reasonably conclude their sales fell every time they took their own money out.
 *
 * IT IS ALSO NOT A PERIOD FIGURE. A running balance has no date column, so `datedBy()` is null
 * and the window is ignored rather than quietly applied to `updated_at` -- which would report "the
 * balance of wallets touched this month", a number that means nothing at all.
 */
final class MerchantWalletEntitlement extends Metric implements SingleFigureMetric
{
    /** Agreement tolerance for the drift check, in the smallest unit of DECIMAL(15,2). */
    public const EPSILON = 0.005;

    public function key(): string
    {
        return 'merchant_wallet_entitlement';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §7';
    }

    public function for(MetricScope $scope, MetricWindow $window): MetricResult
    {
        $aggregate = $this->sumByCurrency(
            $this->wallets($scope),
            'store_wallets.currency_code',
            'store_wallets.available_balance'
        );

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_MOVEMENT,
            null,
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            [],
            [
                'is_revenue' => false,
                'running_balance' => true,
                'window_ignored' => ! $window->isAllTime(),
            ],
        );
    }

    /**
     * THE DRIFT CHECK -- `available_balance` against the ledger rows that actually moved it.
     *
     * Section 7: "This is the drift check from `P4-F032-WALLET-RECONCILIATION-PROCEDURE.md`
     * section Q-F032-5, run as a test rather than a query."
     *
     * IT IS THE CORRECTED FORM OF THAT CHECK, NOT A COPY OF IT. `GateFReconciliation` R1 and
     * Q-F032-5 both filter on `t.status` and both include `withdrawal_paid` rows, which produces
     * structural false positives for two measured reasons:
     *
     *   - the withdrawal lifecycle DOUBLE-COUNTS. The available balance is reserved when the
     *     withdrawal is REQUESTED, so the later `withdrawal_paid` row carries
     *     `balance_after == balance_before` by design -- the handler says so in an inline comment.
     *     Summing it debits the wallet a second time;
     *   - a currency change rewrites balances with NO ledger row at all, so no sum over the ledger
     *     can reproduce the result.
     *
     * Restricting to rows that genuinely moved the balance handles the first; the second is why a
     * wallet whose ledger spans more than one currency is reported as its own fact rather than
     * folded into a difference that would be meaningless.
     *
     * @return array<int, array{wallet_id:int, store_id:int, available_balance:float,
     *                          ledger_net:float, difference:float, ledger_currencies:int}>
     */
    public function drift(MetricScope $scope): array
    {
        $rows = $this->wallets($scope)
            ->leftJoin('store_wallet_transactions', function ($join) {
                $join->on('store_wallet_transactions.wallet_id', '=', 'store_wallets.id')
                    /*
                     * THE SAME EQUALITY `UnpaidOrders` AND `CancelledOrders` ASSERT, and this join
                     * was the one place in the layer that relied on data integrity instead of a
                     * predicate -- found by review. `store_wallet_transactions` carries its OWN
                     * `store_id`, and nothing in the schema forces it to agree with the wallet's:
                     * `fk_wallet_transactions_store` and `fk_wallet_transactions_wallet` are two
                     * unrelated constraints. A row pairing store B's `store_id` with store A's
                     * `wallet_id` would otherwise be summed into A's ledger total and could flip
                     * A's wallet into or out of the drift report.
                     *
                     * No correct writer produces such a row -- every `StoreWalletTransaction`
                     * create pairs `store_id` with the wallet resolved for that store, and
                     * `store_wallets` is unique on `store_id`. But this slice's claim is "by
                     * construction, not by review", and a predicate is cheaper than the claim.
                     */
                    ->whereColumn(
                        'store_wallet_transactions.store_id',
                        '=',
                        'store_wallets.store_id'
                    )
                    ->whereColumn(
                        'store_wallet_transactions.balance_after',
                        '<>',
                        'store_wallet_transactions.balance_before'
                    );
            })
            ->groupBy(
                'store_wallets.id',
                'store_wallets.store_id',
                'store_wallets.available_balance'
            )
            ->selectRaw(
                'store_wallets.id as wallet_id, '
                . 'store_wallets.store_id as store_id, '
                . 'store_wallets.available_balance as available_balance, '
                . "COALESCE(SUM(CASE WHEN store_wallet_transactions.direction = 'credit' "
                . 'THEN store_wallet_transactions.amount '
                . 'ELSE -store_wallet_transactions.amount END), 0) as ledger_net, '
                . 'COUNT(DISTINCT store_wallet_transactions.currency_code) as ledger_currencies'
            )
            ->get();

        $drifted = [];

        foreach ($rows as $row) {
            $available = (float) $row->available_balance;
            $ledgerNet = (float) $row->ledger_net;
            $difference = round($available - $ledgerNet, 2);
            $currencies = (int) $row->ledger_currencies;

            if (abs($difference) < self::EPSILON && $currencies <= 1) {
                continue;
            }

            $drifted[] = [
                'wallet_id' => (int) $row->wallet_id,
                'store_id' => (int) $row->store_id,
                'available_balance' => $available,
                'ledger_net' => $ledgerNet,
                'difference' => $difference,
                'ledger_currencies' => $currencies,
            ];
        }

        return $drifted;
    }
}
