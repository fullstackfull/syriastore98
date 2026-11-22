<?php

namespace App\Support\Finance;

/**
 * P5-R4 / GAP-149 -- THE ONE DEFINITION OF WHAT `store_order_financials.payment_status` MEANS TO A
 * MERCHANT, because the finance screen had two and could represent only four of the six states the
 * engine writes.
 * =============================================================================================
 *
 * THE SIX STATES ARE MEASURED, NOT INFERRED. `App\Support\Database\GateFProductionSchema` carries
 * the production DDL verbatim, and the column is
 *
 *     enum('unpaid','pending','paid','partially_paid','refunded','cancelled') NOT NULL DEFAULT 'unpaid'
 *
 * and `OrderFinancialService::normalizePaymentStatus()` -- first-party, and the only writer of this
 * column on the insert path -- returns every one of them: a gateway's 'pending' or 'processing'
 * becomes `pending`, its 'partial' becomes `partially_paid`. `syncFromOrder()`'s third arm writes
 * whichever of them the order's status normalises to.
 *
 * WHAT THE SCREEN DID WITH THE TWO IT DID NOT KNOW. `finance/index.blade.php` branched on `paid`,
 * `refunded` and `cancelled` and sent everything else to a DANGER-red "Not Received", so:
 *
 *   - a `partially_paid` row -- money the shopper really handed over, which
 *     `CollectedCustomerPayments` counts as COLLECTED the moment `paid_amount` is above zero --
 *     read to the merchant as money that had not arrived at all;
 *   - a `pending` row -- a capture in flight -- read the same, as a settled negative;
 *   - and in the EDITABLE cell neither `<option>` carried `selected`, so the browser rendered the
 *     first one and the merchant was shown "Not Received" as the row's current state while any
 *     click submitted `unpaid`. That is the identical defect P5-1 fixed for `refunded`, still live
 *     for two more states.
 *
 * WHY THIS IS A CLASS AND NOT A WIDER BLADE CHAIN. The view already held the state vocabulary
 * TWICE -- once as `<option>` labels and once as badge labels -- joined by a two-way ternary that
 * could not grow past two states without repeating itself. One definition cannot drift from
 * itself, and a pure class is testable and mutable without rendering a page.
 *
 * SELECTABILITY IS PART OF THE DEFINITION, and it is the half that protects money. Only `paid` and
 * `unpaid` may be CHOSEN by a merchant; the other four are written by the engine
 * (`OrderEntitlementReversal` for the two reversals, the gateway normaliser for the other two).
 * Typing `refunded` would fabricate a reversal; typing `partially_paid` would claim a partial
 * capture the application was never told the AMOUNT of. `StoreFinanceController`'s validator says
 * the same thing independently (`in:paid,unpaid`) and is deliberately NOT widened here: the markup
 * bound is only as good as the browser honouring `disabled`.
 */
final class EntitlementStateLabel
{
    /**
     * state => [design-system tone, English phrase].
     *
     * The phrases for the two new states are `OrderStatusContract`'s own phrases for the same two
     * words, so one state has one name across the web screen and the admin API, and both already
     * exist in `resources/lang/ar.json` ('Pending' => 'معلق', 'Partially Paid' => 'مدفوع جزئياً').
     * No Arabic is invented here.
     *
     * Every tone is one of the five `FinanceStatusBadgeContrastTest` measures at WCAG AA in both
     * light and dark mode; `neutral` is the DS default and is otherwise unused on this screen,
     * which is why `pending` -- a state where nothing has happened yet -- takes it.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const STATES = [
        'paid' => ['success', 'Received'],
        'partially_paid' => ['warning', 'Partially Paid'],
        'pending' => ['neutral', 'Pending'],
        'refunded' => ['info', 'Refunded'],
        'cancelled' => ['warning', 'Cancelled'],
        'unpaid' => ['danger', 'Not Received'],
    ];

    /** The two a merchant may choose. Everything else is the engine's to write. */
    private const SELECTABLE = ['paid', 'unpaid'];

    /** @return list<string> the six states, in the order the screen presents them */
    public static function states(): array
    {
        return array_keys(self::STATES);
    }

    /** @return list<string> */
    public static function selectable(): array
    {
        return self::SELECTABLE;
    }

    public static function isSelectable(?string $state): bool
    {
        return in_array(self::normalise($state), self::SELECTABLE, true);
    }

    /** True for a state this class knows, which is every state the column can hold. */
    public static function isKnown(?string $state): bool
    {
        return array_key_exists(self::normalise($state), self::STATES);
    }

    /**
     * A DS tone name for the badge.
     *
     * An unknown value is impossible through the enum and through the normaliser, so it means the
     * row was written by something neither of those: `neutral`, because claiming any of the five
     * meanings would be a guess. An EMPTY value is the one unknown that gets `danger`, because a
     * blank financial state is "no money recorded", which is what `unpaid` means.
     */
    public static function tone(?string $state): string
    {
        $state = self::normalise($state);

        return self::STATES[$state][0] ?? ($state === '' ? 'danger' : 'neutral');
    }

    /**
     * The translated phrase for the badge and for the disabled `<option>`.
     *
     * An unknown non-empty value is shown AS ITSELF rather than labelled with a meaning it may not
     * have -- fail-visible, so a merchant and whoever they ask can see what is really in the row.
     * Blade escapes it. An empty value falls back to "Not Received" rather than rendering an empty
     * pill, which `FinanceStatusBadgeContrastTest::test_no_rendered_finance_pill_is_empty` refuses
     * and which would tell the merchant nothing at all.
     */
    public static function label(?string $state): string
    {
        $state = self::normalise($state);

        if (isset(self::STATES[$state])) {
            return __(self::STATES[$state][1]);
        }

        return $state === '' ? __(self::STATES['unpaid'][1]) : $state;
    }

    private static function normalise(?string $state): string
    {
        return trim((string) $state);
    }
}
