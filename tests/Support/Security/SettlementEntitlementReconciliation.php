<?php

namespace Tests\Support\Security;

use Illuminate\Database\ConnectionInterface;

/**
 * P4-F128 -- THE SETTLEMENT-TO-ENTITLEMENT RECONCILIATION. READ ONLY.
 * =================================================================================================
 *
 * Owner decision on P4-F128: keep settlement and entitlement DISTINCT, and add reconciliation. Those
 * two halves are not in tension. Settlement (`orders.payment_status`, what the CUSTOMER paid) and
 * entitlement (`store_order_financials`, `store_wallets`, what the MERCHANT is owed) are deliberately
 * independent records, and this class does not make one follow the other. It answers a narrower
 * question: WHERE THEY ARE SUPPOSED TO AGREE, DO THEY.
 *
 * WHY THIS DID NOT EXIST. Measured before writing it: `app/Console/Commands/` holds no reconciliation
 * command, and no query anywhere in `app/` joins `orders` to `store_order_financials`. Three
 * reconciliation artefacts exist and NONE crosses this axis --
 *
 *   - `StoreFinanceController::unreconciledCaptures()` reconciles gateway captures against ORDERS,
 *     and reads `order_gateway_references`, whose migration is dev/test-only and which has ZERO
 *     writers in `app/` (GatewayReference::claim/claimState/bind are called only from tests). In
 *     production it therefore returns an empty list with `record_available = false`. The one surface
 *     in this codebase named "reconciliation" reconciles nothing where it matters most.
 *   - `docs/finance/P4-F032-WALLET-RECONCILIATION-PROCEDURE.md` covers COVERAGE (orders with no
 *     financial row) and one wallet-vs-its-own-ledger drift query.
 *   - `GateFReconciliation` item R1 reconciles a wallet against its OWN transaction ledger.
 *
 * The first two legs below are new ground. Leg 4 is a CORRECTION of an existing check, not a
 * re-derivation of it -- see walletBalanceReconstruction().
 *
 * THIS CLASS RESOLVES NOTHING, by construction. There is no INSERT, UPDATE or DELETE in it. A
 * divergence between settlement and entitlement is a question about what really happened to money;
 * writing a number in to make two columns agree would manufacture the answer. The safety model is the
 * absence of a write statement, not a flag.
 *
 * WHY IT LIVES UNDER tests/, like its sibling GateFReconciliation: it is operational reporting, not
 * application behaviour. Nothing in the request path calls it. `Tests\` is in composer's
 * autoload-dev, which is enough for the CLI wrapper and for the suite that proves it bites both ways.
 *
 * WHY IT TAKES A CONNECTION: so the same code runs against a restored copy of production (via
 * `bin/report-settlement-entitlement.php`) AND against synthetic fixtures in the suite. A report that
 * has only ever run against the data it was written from is indistinguishable from one that hardcodes
 * its answers.
 *
 * Full account, including the three divergence generators measured in the code:
 * `docs/finance/P4-F128-SETTLEMENT-ENTITLEMENT-RECONCILIATION.md`
 */
class SettlementEntitlementReconciliation
{
    /** Money tolerance, matching GateFReconciliation so the two reports cannot disagree on rounding. */
    private const EPSILON = 0.005;

    /** Rows carried per item. The TOTAL is always reported separately -- a truncated list never reads as the whole. */
    private const SAMPLE_LIMIT = 200;

    /**
     * The entitlement states in which settlement is deliberately UNCONSTRAINED.
     *
     * This is the P4-F124 decision made executable. A cancelled or refunded order keeps the
     * customer's actual settlement -- cancellation must NOT rewrite a captured payment as Unpaid --
     * so for those two states a disagreement is the CORRECT state and leg 2 must exempt it rather
     * than report it. A reconciliation that flagged them would train its reader to ignore it.
     */
    private const SETTLEMENT_UNCONSTRAINED = ['cancelled', 'refunded'];

    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * @return array<int, array{code: string, title: string, leg: int, detail: array<string, mixed>}>
     *         One entry per divergence class PRESENT. An empty array means the two sides reconcile.
     */
    public function items(): array
    {
        return array_values(array_filter([
            $this->tenantAndWalletControl(),
            $this->statusDivergence(),
            $this->ledgerVersusEntitlement(),
            $this->financialInternalConsistency(),
            $this->walletBalanceReconstruction(),
        ]));
    }

    /** True when a table exists here; the schema differs between a production restore and a fixture. */
    private function has(string $table): bool
    {
        return $this->db->getSchemaBuilder()->hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->has($table) && $this->db->getSchemaBuilder()->hasColumn($table, $column);
    }

    /**
     * The SQL translation of OrderFinancialService::normalizePaymentStatus().
     *
     * VERIFIED AGAINST THE SOURCE, not written from memory: the match() arms at
     * app/Services/OrderFinancialService.php were read and each arm is reproduced here. If that
     * method gains an arm, SettlementEntitlementReconciliationTest::test_the_sql_normalisation_matches_the_php
     * fails -- the two are held together by a test rather than by a comment asking you to remember.
     *
     * LOWER/TRIM/COALESCE are the intersection of SQLite and MySQL, so one expression serves the
     * fixture suite and a real restore.
     */
    private function normalisedSettlementSql(string $column): string
    {
        return "CASE
            WHEN LOWER(TRIM(COALESCE($column,''))) IN ('paid','success','succeeded','completed') THEN 'paid'
            WHEN LOWER(TRIM(COALESCE($column,''))) IN ('pending','processing')                   THEN 'pending'
            WHEN LOWER(TRIM(COALESCE($column,''))) IN ('partial','partially_paid')               THEN 'partially_paid'
            WHEN LOWER(TRIM(COALESCE($column,''))) IN ('refund','refunded')                      THEN 'refunded'
            WHEN LOWER(TRIM(COALESCE($column,''))) IN ('cancel','cancelled','canceled')          THEN 'cancelled'
            ELSE 'unpaid' END";
    }

    /** @param array<int, object> $rows */
    private function item(string $code, string $title, int $leg, array $rows, array $extra = []): ?array
    {
        if ($rows === []) {
            return null;
        }

        return [
            'code' => $code,
            'title' => $title,
            'leg' => $leg,
            'detail' => array_merge([
                'total' => count($rows),
                'sample' => array_slice(array_map(fn ($r) => (array) $r, $rows), 0, self::SAMPLE_LIMIT),
                'truncated' => count($rows) > self::SAMPLE_LIMIT,
            ], $extra),
        ];
    }

    /**
     * LEG 1 (D4) -- tenant and wallet control.
     *
     * The financial row must name the SAME store as its order, and a wallet that belongs to that same
     * store. A row failing this is not an accounting difference, it is a control failure: entitlement
     * recorded against the wrong tenant. Reported first for that reason.
     *
     * `o.store_id IS NULL` is included because `Order` is NOT in config/tenancy.php's `scoped_models`,
     * so nothing in the framework guarantees an order carries a store at all.
     *
     * THE JOIN IS A LEFT JOIN, AND THAT IS THE WHOLE POINT OF `o.id IS NULL`. The first draft used
     * an INNER JOIN, which silently DROPPED any financial row whose `order_id` matches no order --
     * so an orphaned entitlement, which is the most complete form of the failure this leg looks
     * for, was the one case it could never report. Found by independent review of this file.
     */
    private function tenantAndWalletControl(): ?array
    {
        if (! $this->has('store_order_financials') || ! $this->has('orders') || ! $this->has('store_wallets')) {
            return null;
        }

        $rows = $this->db->select(
            'SELECT f.id AS financial_id, f.order_id, f.store_id AS financial_store_id,
                    o.store_id AS order_store_id, f.wallet_id, w.store_id AS wallet_store_id
               FROM store_order_financials f
               LEFT JOIN orders o ON o.id = f.order_id
               LEFT JOIN store_wallets w ON w.id = f.wallet_id
              WHERE o.id IS NULL
                 OR o.store_id IS NULL
                 OR f.store_id <> o.store_id
                 OR w.id IS NULL
                 OR w.store_id <> f.store_id
              ORDER BY f.id'
        );

        return $this->item(
            'D4',
            'A financial row names a different store from its order, or a wallet belonging to another store',
            1,
            $rows,
            ['severity' => 'control failure, not an accounting difference']
        );
    }

    /**
     * LEG 2 (D1) -- status divergence, the heart of P4-F128.
     *
     * Where the entitlement state is not terminal, it must equal the normalised settlement. Three
     * generators were measured in the code and are expected to populate this on a first run:
     *
     *   1. the /finance dropdown -- StoreFinanceController::updateFinancialPaymentStatus moves the
     *      financial row and the wallet and never touches `orders`;
     *   2. OrderFinancialService::syncFromOrder's non-paid arm, which DEBITS the wallet and so
     *      resolves a divergence in the WRONG direction, silently reversing a deliberate credit;
     *   3. OrderObserver::updated's early return unless wasChanged('payment_status') -- an order that
     *      reaches a new status by a path that does not change that column syncs nothing.
     */
    private function statusDivergence(): ?array
    {
        if (! $this->has('store_order_financials') || ! $this->has('orders')) {
            return null;
        }

        $normalised = $this->normalisedSettlementSql('o.payment_status');
        $exempt = "'" . implode("','", self::SETTLEMENT_UNCONSTRAINED) . "'";

        $rows = $this->db->select(
            "SELECT o.id AS order_id, o.store_id, o.payment_status AS settlement_raw,
                    $normalised AS settlement_normalised,
                    f.id AS financial_id, f.payment_status AS entitlement_state,
                    f.customer_total, f.paid_amount, f.paid_at
               FROM orders o
               JOIN store_order_financials f ON f.order_id = o.id
              WHERE f.payment_status NOT IN ($exempt)
                AND f.payment_status <> $normalised
              ORDER BY o.id"
        );

        return $this->item(
            'D1',
            'Entitlement state disagrees with the normalised customer settlement',
            2,
            $rows,
            ['exempt_states' => self::SETTLEMENT_UNCONSTRAINED,
             'exempt_reason' => 'P4-F124: a cancelled or refunded order deliberately KEEPS the customer settlement']
        );
    }

    /**
     * LEG 3 (D2) -- the order's ledger net must equal the entitlement the financial row claims.
     *
     * READ IN THREE BUCKETS, NEVER AS ONE NUMBER. A single "divergence" count here would be wrong:
     *
     *   NOT COMPARABLE   -- more than one currency among the contributing rows, or the financial row
     *                       and its wallet disagree on currency. A currency conversion is not a
     *                       discrepancy, and summing across currencies would invent one.
     *   CONTROL FAILURE  -- a transaction tagged to this order sits on a DIFFERENT wallet.
     *   AMOUNT DIVERGENCE -- everything else: same currency, right wallet, numbers disagree.
     *
     * `t.status` is deliberately absent from the join. Every order-tagged row this codebase writes is
     * 'completed' at creation, and filtering on it is one of the two things that makes the existing
     * balance checks report structural false positives.
     */
    private function ledgerVersusEntitlement(): ?array
    {
        if (! $this->has('store_order_financials') || ! $this->has('store_wallets')
            || ! $this->has('store_wallet_transactions')) {
            return null;
        }

        $eps = self::EPSILON;

        $rows = $this->db->select(
            "SELECT f.order_id, f.store_id, f.wallet_id, f.payment_status,
                    f.currency_code AS financial_currency, w.currency_code AS wallet_currency,
                    f.paid_amount,
                    COALESCE(SUM(CASE WHEN t.direction = 'credit' THEN t.amount ELSE -t.amount END), 0) AS order_ledger_net,
                    COUNT(DISTINCT t.currency_code) AS ledger_currencies,
                    SUM(CASE WHEN t.wallet_id <> f.wallet_id THEN 1 ELSE 0 END) AS foreign_wallet_rows
               FROM store_order_financials f
               JOIN store_wallets w ON w.id = f.wallet_id
               LEFT JOIN store_wallet_transactions t
                      ON t.store_id = f.store_id
                     AND t.reference_type = 'order'
                     AND t.reference_id = f.order_id
              GROUP BY f.id, f.order_id, f.store_id, f.wallet_id, f.payment_status,
                       f.currency_code, w.currency_code, f.paid_amount
             HAVING COUNT(DISTINCT t.currency_code) > 1
                 OR SUM(CASE WHEN t.wallet_id <> f.wallet_id THEN 1 ELSE 0 END) > 0
                 OR f.currency_code <> w.currency_code
                 OR ABS(COALESCE(SUM(CASE WHEN t.direction = 'credit' THEN t.amount ELSE -t.amount END), 0)
                        - CASE WHEN f.payment_status = 'paid' THEN f.paid_amount ELSE 0 END) > $eps
              ORDER BY f.order_id"
        );

        $buckets = ['not_comparable' => 0, 'control_failure' => 0, 'amount_divergence' => 0];
        foreach ($rows as $r) {
            if ((int) $r->foreign_wallet_rows > 0) {
                $buckets['control_failure']++;
            } elseif ((int) $r->ledger_currencies > 1 || $r->financial_currency !== $r->wallet_currency) {
                $buckets['not_comparable']++;
            } else {
                $buckets['amount_divergence']++;
            }
        }

        return $this->item(
            'D2',
            "An order's wallet-ledger net does not equal the entitlement its financial row claims",
            3,
            $rows,
            ['buckets' => $buckets,
             'bucket_note' => 'not_comparable is a currency question, not a discrepancy; control_failure is a wrong-wallet row']
        );
    }

    /**
     * LEG 3b (D3) -- the financial row must be internally consistent, independent of anything else.
     *
     * Cheap, and it catches a class the cross-table legs cannot: a row that disagrees with ITSELF.
     * `paid_at` is asserted only in the direction that is safe -- a paid row must carry one. The
     * reverse is deliberately NOT asserted, because OrderEntitlementReversal PRESERVES paid_at on a
     * reversal by design, so an unpaid-looking row legitimately keeps its timestamp.
     */
    private function financialInternalConsistency(): ?array
    {
        if (! $this->has('store_order_financials')) {
            return null;
        }

        $eps = self::EPSILON;

        $rows = $this->db->select(
            "SELECT f.id AS financial_id, f.order_id, f.store_id, f.payment_status,
                    f.customer_total, f.paid_amount, f.remaining_amount, f.paid_at
               FROM store_order_financials f
              WHERE ABS(f.paid_amount + f.remaining_amount - f.customer_total) > $eps
                 OR (f.payment_status = 'paid'
                     AND (ABS(f.paid_amount - f.customer_total) > $eps OR f.paid_at IS NULL))
                 OR (f.payment_status = 'unpaid' AND f.paid_amount <> 0)
              ORDER BY f.id"
        );

        return $this->item(
            'D3',
            'A financial row disagrees with itself on its own amounts',
            3,
            $rows,
            ['not_asserted' => 'an unpaid row MAY carry paid_at -- OrderEntitlementReversal preserves it by design']
        );
    }

    /**
     * LEG 4 (D5) -- the wallet balance must be reconstructible from its own moving ledger rows.
     *
     * THIS CORRECTS AN EXISTING CHECK RATHER THAN RE-DERIVING IT. GateFReconciliation R1 and the F032
     * procedure's Q-F032-5 both filter on `t.status` and both include `withdrawal_paid` rows. Two
     * measured reasons that produces structural false positives:
     *
     *   - the withdrawal lifecycle DOUBLE-COUNTS: the available balance is reserved when the
     *     withdrawal is REQUESTED, so the later `withdrawal_paid` row carries
     *     balance_after == balance_before BY DESIGN (the handler says so in an inline comment). Summing
     *     it debits the wallet a second time;
     *   - a currency change rewrites balances with NO ledger row at all, so no sum over the ledger can
     *     reproduce the result.
     *
     * Restricting to rows that actually MOVED the balance (`balance_after <> balance_before`) and
     * dropping the status filter handles the first. The second is why `ledger_currencies > 1` is
     * reported as its own fact rather than folded into the difference.
     */
    private function walletBalanceReconstruction(): ?array
    {
        if (! $this->has('store_wallets') || ! $this->has('store_wallet_transactions')
            || ! $this->hasColumn('store_wallet_transactions', 'balance_after')) {
            return null;
        }

        $eps = self::EPSILON;

        $rows = $this->db->select(
            "SELECT w.id AS wallet_id, w.store_id, w.currency_code, w.available_balance,
                    COALESCE(SUM(CASE WHEN t.direction = 'credit' THEN t.amount ELSE -t.amount END), 0) AS moving_rows_net,
                    COUNT(DISTINCT t.currency_code) AS ledger_currencies
               FROM store_wallets w
               LEFT JOIN store_wallet_transactions t
                      ON t.wallet_id = w.id
                     AND t.balance_after <> t.balance_before
              GROUP BY w.id, w.store_id, w.currency_code, w.available_balance
             HAVING COUNT(DISTINCT t.currency_code) > 1
                 OR ABS(w.available_balance
                        - COALESCE(SUM(CASE WHEN t.direction = 'credit' THEN t.amount ELSE -t.amount END), 0)) > $eps
              ORDER BY w.id"
        );

        return $this->item(
            'D5',
            'A wallet balance cannot be reconstructed from the ledger rows that moved it',
            4,
            $rows,
            ['corrects' => 'GateFReconciliation R1 / Q-F032-5, which filter on t.status and include '
                . 'withdrawal_paid rows whose balance_after == balance_before by design']
        );
    }
}
