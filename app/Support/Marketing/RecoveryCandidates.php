<?php

namespace App\Support\Marketing;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * MKT-007 — DETECTION: which baskets and which unpaid orders are worth recovering.
 *
 * THE REQUIREMENT IS "RECOVER CARTS AND INCOMPLETE PAYMENTS, WITHOUT DUPLICATION", and detection
 * was the absent half. The duplicate guard at the SEND step already exists (`NudgeLedger`, keyed on
 * the logical action with no clock in it); what nobody did was LOOK. Recovery was one merchant
 * click per cart row, so a basket abandoned on a Friday was recovered only if somebody remembered
 * to open a screen.
 *
 * THE AGE IS THE MERCHANT'S AND THERE IS NO SHIPPED NUMBER. "How long before a basket counts as
 * abandoned" is a marketing judgement -- two hours is defensible, so is two days -- and this
 * project does not invent those. `configuredFor()` returns an empty array for a store that has set
 * nothing, and the sweep then detects nothing for it. That is the shipped state, and it is the same
 * rule ANA-009 is built on.
 *
 * THIS CLASS SENDS NOTHING AND KNOWS NOTHING ABOUT SENDING. It answers "what is there", and the
 * command that calls it records the answer. Who may be messaged is `MarketingSendGate`'s question
 * and the consent work's (enforcement OFF by owner decision); how often is `NudgeThrottle`'s (no
 * cadence configured). Putting any of that here would spread the send decision across three files.
 *
 * `partially_paid` IS NOT AN INCOMPLETE PAYMENT FOR THIS PURPOSE, and the choice is stated rather
 * than hidden. `store_order_financials.payment_status` is an enum of six values; money HAS arrived
 * for a partially-paid order, so chasing it is a different conversation with a different message
 * ("you still owe X" rather than "you did not pay"). Including it would send the wrong one.
 */
final class RecoveryCandidates
{
    public const KIND_BASKET = 'abandoned_basket';

    public const KIND_PAYMENT = 'incomplete_payment';

    /**
     * The two kinds, and the setting each reads.
     *
     * Each entry is a separate merchant decision: a store may chase baskets and not unpaid orders,
     * or the reverse, and the ages are not the same question.
     */
    public const KINDS = [
        self::KIND_BASKET => [
            'setting' => 'recovery_basket_age_hours',
            'subject_type' => 'cart',
            'label' => 'Basket left behind',
        ],
        self::KIND_PAYMENT => [
            'setting' => 'recovery_payment_age_hours',
            'subject_type' => 'order',
            'label' => 'Payment not completed',
        ],
    ];

    /** The statuses that mean "the customer has paid nothing". See the class docblock. */
    public const UNPAID_STATUSES = ['unpaid', 'pending'];

    /**
     * The ages this store's merchant has set, in hours.
     *
     * @return array<string, int> kind => hours
     */
    public static function configuredFor(int $storeId): array
    {
        $rows = Setting::where('store_id', $storeId)
            ->whereIn('name', array_column(self::KINDS, 'setting'))
            ->pluck('value', 'name')
            ->all();

        $configured = [];

        foreach (self::KINDS as $kind => $spec) {
            $hours = self::positiveInt($rows[$spec['setting']] ?? null);

            if ($hours !== null) {
                $configured[$kind] = $hours;
            }
        }

        return $configured;
    }

    /**
     * The candidates in one store, for the kinds its merchant has configured.
     *
     * @return array<int, array{kind: string, subject_type: string, subject_id: int, customer_id: ?int}>
     */
    public static function forStore(int $storeId, ?int $now = null): array
    {
        $configured = self::configuredFor($storeId);

        if ($configured === []) {
            return [];
        }

        $clock = CarbonImmutable::createFromTimestamp($now ?? time());
        $candidates = [];

        foreach ($configured as $kind => $hours) {
            $before = $clock->subHours($hours);

            $candidates = array_merge($candidates, match ($kind) {
                self::KIND_BASKET => self::baskets($storeId, $before),
                self::KIND_PAYMENT => self::payments($storeId, $before),
                default => [],
            });
        }

        return $candidates;
    }

    /**
     * Baskets older than the merchant's age whose owner has not ordered since.
     *
     * "HAS NOT ORDERED SINCE" IS THE PART THAT MAKES THIS A RECOVERY LIST rather than a list of old
     * rows. A shopper who left a basket and then placed an order has recovered themselves; chasing
     * them is at best noise and at worst a message about a basket they already bought from. The
     * predicate is on orders placed AFTER the basket was created, in this store, attributed (not
     * guest), because `orders.customer_id` defaults to 0 and a guest checkout fills it with
     * whatever it has.
     *
     * A BASKET WITH NO CUSTOMER IS SKIPPED. `carts.customer_id` is an `integer` defaulting to 0, so
     * 0 means "nobody" rather than "customer 0"; there is nobody to recover it with, and a queued
     * row naming customer 0 would be a row the send step could never act on.
     */
    private static function baskets(int $storeId, CarbonImmutable $before): array
    {
        $rows = DB::table('carts')
            ->where('carts.store_id', $storeId)
            ->where('carts.customer_id', '>', 0)
            ->where('carts.created_at', '<=', $before)
            ->whereNotExists(static function ($sub) use ($storeId): void {
                $sub->selectRaw('1')
                    ->from('orders')
                    ->join('store_order_financials', 'store_order_financials.order_id', '=', 'orders.id')
                    ->whereColumn('orders.customer_id', 'carts.customer_id')
                    ->whereColumn('orders.order_date', '>=', 'carts.created_at')
                    ->where('orders.store_id', $storeId)
                    ->where('orders.is_guest', 0)
                    /*
                     * AN UNPAID ORDER IS NOT RECOVERY, and this clause is here because the test for
                     * it failed. Without it, a shopper who abandoned a basket and then started a
                     * checkout they never paid for was treated as RECOVERED: the basket vanished
                     * from the list, which is the opposite of the truth -- that shopper is further
                     * down the funnel and more worth following up, not less.
                     *
                     * So "ordered since" means "bought since": the order's ledger row must not be in
                     * the same unpaid statuses that make an order an `incomplete_payment` candidate.
                     * The two kinds then agree with each other, and a shopper with both an
                     * abandoned basket and an unpaid checkout appears as two things to follow up
                     * rather than as neither or one.
                     */
                    ->whereNotIn('store_order_financials.payment_status', self::UNPAID_STATUSES);
            })
            ->get(['carts.id', 'carts.customer_id']);

        return $rows->map(static fn ($row): array => [
            'kind' => self::KIND_BASKET,
            'subject_type' => self::KINDS[self::KIND_BASKET]['subject_type'],
            'subject_id' => (int) $row->id,
            'customer_id' => (int) $row->customer_id,
        ])->all();
    }

    /**
     * Orders older than the merchant's age whose ledger row says nothing was paid.
     *
     * READ FROM `store_order_financials` AND NOT FROM `orders.payment_status`, because the ledger is
     * where this project's settled payment state lives: `CollectedCustomerPayments` and
     * `FinanceLedgerFigures` both read it, and a recovery list that disagreed with the finance
     * screen about who has paid would be the worst kind of wrong.
     *
     * A GUEST ORDER IS KEPT, with a null `customer_id`. It is a real incomplete payment and the
     * merchant should see it; whether anybody can be messaged about it is the send step's problem,
     * and dropping it here would hide a genuine unpaid order because of who placed it.
     */
    private static function payments(int $storeId, CarbonImmutable $before): array
    {
        $rows = DB::table('store_order_financials')
            ->join('orders', 'orders.id', '=', 'store_order_financials.order_id')
            ->where('store_order_financials.store_id', $storeId)
            // The store predicate on BOTH tables: the join does not carry it, and `orders` is not
            // in `scoped_models`, so a ledger row pointing at another store's order would otherwise
            // resolve.
            ->where('orders.store_id', $storeId)
            ->whereIn('store_order_financials.payment_status', self::UNPAID_STATUSES)
            ->where('orders.order_date', '<=', $before)
            ->get([
                'orders.id',
                'orders.customer_id',
                'orders.is_guest',
            ]);

        return $rows->map(static fn ($row): array => [
            'kind' => self::KIND_PAYMENT,
            'subject_type' => self::KINDS[self::KIND_PAYMENT]['subject_type'],
            'subject_id' => (int) $row->id,
            'customer_id' => ((int) $row->is_guest === 1 || (int) $row->customer_id <= 0)
                ? null
                : (int) $row->customer_id,
        ])->all();
    }

    /** An age in whole hours: at least 1, at most a year. */
    private static function positiveInt($value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $hours = (int) $value;

        // An upper bound as well: 100,000 hours is a typo, and the sweep would scan it.
        return ($hours >= 1 && $hours <= 8760) ? $hours : null;
    }
}
