<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * THE STORE'S REFUND-POLICY TOGGLES, READ SERVER-SIDE. GAP-140 / P04-P6-04 / P4-F086.
 * =============================================================================================
 *
 * WHAT WAS WRONG. `order_refund_settings` holds three per-store switches a merchant sets on the
 * Settings screen's Refund tab, and before this class EVERY ONE OF THEM GATED MARKUP AND NOTHING
 * ELSE. Measured at HEAD: the complete set of `OrderRefundSetting` references outside its own
 * model was four files, and in none of them did a value decide anything a writer does --
 * `OrderRefundController::show()` and `AccountProfileController::order_refund()` each handed the
 * array to a view, `OrderRefundController::updateStatus()` is the writer of the rows themselves,
 * and `OrderDocumentAccessGuard` merely names the table in a comment.
 *
 * So the server restored stock for a merchant who had switched restocking off, and stored
 * attachments for a merchant who had switched attachments off. That is worse than an absent
 * feature: the product showed each merchant a control, and the control did nothing.
 *
 * ABSENT IS OFF, AND THAT IS A MEASUREMENT RATHER THAN A CHOICE. Nothing seeds this table --
 * `is_active` defaults to 0, no seeder writes it, and `Utility::addNewData()` does not know the
 * table exists. A row appears only when somebody saves the Refund tab. The settings screen
 * renders an absent row as an OFF switch (`(int) ($order_refunds['manage_stock'] ?? 0) === 1`),
 * and so do both consuming views, so "never configured" and "configured off" are the SAME claim
 * to the merchant. A null-permissive reading here would contradict the screen.
 *
 * THE READ IS RAW, for two reasons. `OrderRefundSetting` is one of the 79 `Cachable` models, so an
 * Eloquent read can serve a row from before the merchant's own save -- and under `CACHE_STORE=array`
 * it would live for the rest of the request. And `DB::table()` in a service outside `app/Models`,
 * `app/Models/Concerns`, `app/Scopes` and `app/Helper/helper.php` is outside
 * `ResidualTenancyExposureTest`'s raw-query inventory, so it does not put a row in a census of
 * SCOPE BYPASSES -- which this is not: the predicate is written here, explicitly, and
 * `OrderRefundSetting` carries no global scope to bypass in the first place.
 *
 * DUPLICATE ROWS ARE POSSIBLE AND THE LAST ONE WINS, which matches the screen exactly.
 * `updateStatus()` writes one row per (store_id, user_id, name), so two users of one store
 * produce two rows per name; both consuming views read `where('store_id', ...)
 * ->pluck('is_active', 'name')` with NO user predicate, and an array key written twice keeps the
 * value written last. This reproduces that answer deterministically by ordering on `id`, rather
 * than inheriting whatever order an engine happens to return. The duplicate-row shape itself is a
 * pre-existing wrinkle of the writer and is reported rather than changed here: a gate is not the
 * place to decide which of two merchants' choices is the store's.
 *
 * `shiping_deduct` IS DELIBERATELY NOT A CONSTANT HERE. It is the third toggle, and enforcing it
 * would change a refund AMOUNT rather than a permission or a stock movement: it decides whether
 * the delivery charge is folded into the figure refunded, and that figure reaches
 * `OrderRefundController::RefundAmonut()` as ONE client-typed number with no breakdown, so the
 * server cannot tell a total that already includes shipping from one that does not. Any
 * enforcement would therefore have to recompute the refund, which is an owner decision. Reported
 * under GAP-140 instead, and pinned by
 * `RefundPolicyEnforcementTest::test_the_shipping_deduction_toggle_is_still_read_by_no_php_writer`.
 */
class RefundPolicy
{
    /**
     * Does this store allow the refund screen's "Manage Stock" action to credit the goods back?
     *
     * Gates `OrderRefundController::RefundStock()`, which is what the button at
     * `resources/views/order/refund_details.blade.php:224` posts to.
     */
    public const MANAGE_STOCK = 'manage_stock';

    /**
     * Does this store accept files with a shopper's refund request?
     *
     * Gates the attachment block of `AccountProfileController::order_refund_request()`, which is
     * what the file input at `resources/views/front_end/pages/refund_order.blade.php:156` posts
     * to.
     */
    public const ATTACHMENT = 'attachment';

    /**
     * Is the named toggle switched on for this store?
     *
     * Default-deny: an unresolvable store, an absent row, a non-numeric value and an explicit 0
     * all answer false, which is what the three screens already show.
     */
    public static function allows(string $name, mixed $storeId): bool
    {
        if (empty($storeId) || $name === '') {
            return false;
        }

        $value = DB::table('order_refund_settings')
            ->where('store_id', $storeId)
            ->where('name', $name)
            ->orderBy('id')
            ->pluck('is_active')
            ->last();

        return is_numeric($value) && (int) $value === 1;
    }
}
