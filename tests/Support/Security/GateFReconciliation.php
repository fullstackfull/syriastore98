<?php

namespace Tests\Support\Security;

use Illuminate\Database\ConnectionInterface;

/**
 * THE THREE GATE F RECONCILIATION ITEMS, COMPUTED. READ ONLY.
 * =================================================================================================
 *
 * Owner direction, 2026-10-24: "Preserve the historical wallet discrepancy, 17 COD orders lacking
 * collection evidence, and 89 suspicious profit records as explicit reconciliation items. Do not
 * invent collection evidence, missing costs, or corrected balances. Prepare and test any safe
 * reconciliation logic against isolated copies. Do not activate unverified balances or payouts."
 *
 * THIS CLASS RESOLVES NOTHING, and that is the design rather than an omission. It writes no
 * corrected balance, creates no collection event, fills no cost and computes no payout. There is no
 * INSERT, UPDATE or DELETE anywhere in it -- the safety model is the absence of a write statement,
 * not a flag someone can pass. Each item it reports needs a fact the business holds, and a number
 * written in to make a column agree would be a manufactured record.
 *
 * WHY IT LIVES UNDER tests/ RATHER THAN app/. It is operational reporting, not application
 * behaviour: nothing in the request path calls it, and putting it in app/ would imply otherwise.
 * `Tests\` is mapped in composer's autoload-dev, which is enough for the CLI wrapper
 * (`tests/Support/Security/bin/report-gatef-reconciliation.php`) and for the suite that proves the
 * logic bites in both directions.
 *
 * WHY IT TAKES A CONNECTION. So the same code can be driven against a restored copy of production by
 * the wrapper AND against synthetic fixtures by the suite. A reconciliation report that has only ever
 * been run against the data it was written from is indistinguishable from one that hard-codes its
 * answers; the positive control in GateFReconciliationReportTest is what rules that out.
 *
 * Full account of what each item is and what closing it needs: docs/GATE-F-RECONCILIATION-ITEMS.md
 */
class GateFReconciliation
{
    /** Tolerance for comparing money figures, in the smallest unit the columns carry. */
    private const EPSILON = 0.005;

    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * @return array<int, array{code: string, title: string, detail: array<string, mixed>}>
     *         One entry per item PRESENT. An empty array means the data reconciles.
     */
    public function items(): array
    {
        return array_values(array_filter([
            $this->walletDivergence(),
            $this->codSettledWithoutEvidence(),
            $this->allMarginProfitRows(),
        ]));
    }

    /** True when a table exists on this connection; the schema differs between a restore and a fixture. */
    private function has(string $table): bool
    {
        return $this->db->getSchemaBuilder()->hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->has($table) && $this->db->getSchemaBuilder()->hasColumn($table, $column);
    }

    /**
     * R1 -- a wallet whose stored balance cannot be reconstructed from its own ledger.
     *
     * THREE INDEPENDENT SYMPTOMS, reported separately because they have different causes and a fix
     * for one does not touch the others:
     *
     *   divergence    stored `available_balance` vs the signed sum of the ledger.
     *   currencies    more than one `currency_code` summed into a single balance column. The schema
     *                 forces this -- one wallet per store (UNIQUE on store_id), one balance column
     *                 -- so it is a design consequence, not data entry.
     *   chain breaks  a row whose `balance_before` is not the previous row's `balance_after`. Each
     *                 row can be internally correct (`after = before ± amount`) while the chain is
     *                 broken, which is exactly what production shows: 0 arithmetic violations
     *                 across 80 rows, 9 broken links, the largest jump 98.28 billion.
     *
     * THE DIRECTION COLUMN IS THE DISCRIMINATOR, not `type`. `type` holds `order_paid`,
     * `manual_deposit`, `manual_withdrawal`, `order_payment_reversed`; `direction` is
     * enum('credit','debit'). A first version of this check keyed on `type` and reported 60
     * arithmetic violations where there are none -- recorded because the wrong column produces a
     * confident, precise, wrong number.
     */
    private function walletDivergence(): ?array
    {
        if (! $this->has('store_wallets') || ! $this->has('store_wallet_transactions')) {
            return null;
        }

        $wallets = [];

        foreach ($this->db->table('store_wallets')->orderBy('id')->get() as $wallet) {
            $rows = $this->db->table('store_wallet_transactions')
                ->where('wallet_id', $wallet->id)
                ->orderBy('id')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $net = 0.0;
            $currencies = [];
            $violations = 0;
            $breaks = 0;
            $largestJump = 0.0;
            $previousAfter = null;

            foreach ($rows as $row) {
                $amount = (float) $row->amount;
                $signed = strtolower((string) $row->direction) === 'debit' ? -$amount : $amount;
                $net += $signed;

                $code = (string) ($row->currency_code ?? '');
                $currencies[$code] = ($currencies[$code] ?? 0.0) + $signed;

                $expectedAfter = (float) $row->balance_before + $signed;
                if (abs((float) $row->balance_after - $expectedAfter) > self::EPSILON) {
                    $violations++;
                }

                if ($previousAfter !== null) {
                    $jump = (float) $row->balance_before - $previousAfter;
                    if (abs($jump) > self::EPSILON) {
                        $breaks++;
                        if (abs($jump) > abs($largestJump)) {
                            $largestJump = $jump;
                        }
                    }
                }

                $previousAfter = (float) $row->balance_after;
            }

            $stored = (float) $wallet->available_balance;
            $divergence = round($net - $stored, 2);

            // A wallet is only an item if something actually disagrees. A single-currency wallet
            // whose ledger sums to its balance with an unbroken chain is reported as nothing.
            if (abs($divergence) <= self::EPSILON && count($currencies) <= 1 && $breaks === 0 && $violations === 0) {
                continue;
            }

            $wallets[] = [
                'wallet_id' => (int) $wallet->id,
                'store_id' => (int) $wallet->store_id,
                'wallet_currency' => $wallet->currency_code,
                'stored_balance' => round($stored, 2),
                'ledger_net' => round($net, 2),
                'divergence' => $divergence,
                'currencies' => array_map(fn ($v) => round($v, 2), $currencies),
                'row_level_arithmetic_violations' => $violations,
                'chain_breaks' => $breaks,
                'largest_chain_jump' => round($largestJump, 2),
                'transactions' => $rows->count(),
            ];
        }

        if ($wallets === []) {
            return null;
        }

        return [
            'code' => 'R1',
            'title' => 'A wallet balance cannot be reconstructed from its own ledger',
            'detail' => ['wallets' => $wallets],
        ];
    }

    /**
     * R2 -- COD orders recorded as paid with no verified cash-collection event.
     *
     * `cod_collection_events` is absent in production by decision D15, so for those rows no event
     * CAN exist -- which is reported rather than treated as a passing check. When the table does
     * exist, an order counts as evidenced only by an event naming THAT order.
     */
    private function codSettledWithoutEvidence(): ?array
    {
        if (! $this->has('orders')) {
            return null;
        }

        $tableExists = $this->has('cod_collection_events');

        $paid = $this->db->table('orders')
            ->whereRaw('LOWER(payment_type) LIKE ?', ['%cod%'])
            ->whereRaw('LOWER(payment_status) = ?', ['paid'])
            ->get();

        if ($paid->isEmpty()) {
            return null;
        }

        $evidenced = [];

        if ($tableExists) {
            $evidenced = $this->db->table('cod_collection_events')
                ->pluck('order_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $unevidenced = [];
        $total = 0.0;
        $delivered = 0;
        $withoutFinancialRow = 0;
        $hasFinancials = $this->has('store_order_financials');

        foreach ($paid as $order) {
            if (in_array((int) $order->id, $evidenced, true)) {
                continue;
            }

            $unevidenced[] = (int) $order->id;
            $total += (float) ($order->final_price ?? 0);

            if ((int) ($order->delivered_status ?? 0) === 1) {
                $delivered++;
            }

            if ($hasFinancials) {
                $financial = $this->db->table('store_order_financials')
                    ->where('order_id', $order->id)
                    ->exists();

                if (! $financial) {
                    $withoutFinancialRow++;
                }
            }
        }

        if ($unevidenced === []) {
            return null;
        }

        return [
            'code' => 'R2',
            'title' => 'COD orders recorded as paid with no verified collection event',
            'detail' => [
                'cod_collection_events_table_exists' => $tableExists,
                'orders' => count($unevidenced),
                'total_recorded_as_collected' => round($total, 2),
                'of_which_delivered' => $delivered,
                'without_a_financial_row' => $withoutFinancialRow,
                'order_ids' => $unevidenced,
            ],
        ];
    }

    /**
     * R3 -- per-order P&L rows whose `product_cost` is zero, so `gross_profit` is the full revenue.
     *
     * AND IT DISTINGUISHES THE TWO POSSIBLE CAUSES, which is the whole value of the check:
     * recomputing each zero-cost row against the CURRENT `products.cost_price` says whether the row
     * is stale (the cost existed later -- a migration-ordering problem) or whether the cost was
     * never entered (a data problem the schema cannot even express, since `cost_price` is NOT NULL
     * DEFAULT 0.00). In production the answer was unambiguous: 0 of 89 would change.
     */
    private function allMarginProfitRows(): ?array
    {
        if (! $this->has('store_order_financials') || ! $this->has('orders') || ! $this->has('products')) {
            return null;
        }

        if (! $this->hasColumn('products', 'cost_price')) {
            return null;
        }

        $costs = $this->db->table('products')->pluck('cost_price', 'id')
            ->map(fn ($c) => (float) $c)->all();

        $rows = $this->db->table('store_order_financials as f')
            ->join('orders as o', 'o.id', '=', 'f.order_id')
            ->select('f.id', 'f.order_id', 'f.product_cost', 'f.gross_profit', 'o.product_json')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $zeroCost = 0;
        $wouldChange = 0;
        $omitted = 0.0;

        foreach ($rows as $row) {
            if (abs((float) $row->product_cost) > self::EPSILON) {
                continue;
            }

            $zeroCost++;

            $lines = json_decode((string) $row->product_json, true);

            if (! is_array($lines)) {
                continue;
            }

            $recomputed = 0.0;

            foreach ($lines as $line) {
                $id = (int) ($line['product_id'] ?? 0);
                $qty = (float) ($line['qty'] ?? $line['quantity'] ?? 0);

                if ($id === 0 || $qty <= 0 || ! array_key_exists($id, $costs)) {
                    continue;
                }

                $recomputed += $costs[$id] * $qty;
            }

            if ($recomputed > self::EPSILON) {
                $wouldChange++;
                $omitted += $recomputed;
            }
        }

        if ($zeroCost === 0) {
            return null;
        }

        return [
            'code' => 'R3',
            'title' => 'Per-order P&L rows with zero product cost, so gross profit is full revenue',
            'detail' => [
                'financial_rows' => $rows->count(),
                'with_zero_product_cost' => $zeroCost,
                'would_change_if_recomputed_today' => $wouldChange,
                'cost_omitted_by_those_rows' => round($omitted, 2),
                'cause' => $wouldChange === 0
                    ? 'costs were never entered (not a migration-ordering problem)'
                    : 'some rows are stale against current cost data -- investigate ordering',
            ],
        ];
    }
}
