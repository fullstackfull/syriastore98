<?php

namespace App\Support;

use App\Http\Controllers\HomeController;

/**
 * Which design-system tone each order status wears.
 * =================================================================================================
 *
 * ONE FACT, ONE PLACE. The dashboard's "Latest orders" card and the order list's own status column
 * answer the same question -- what colour is a Cancelled order? -- and until this class existed
 * they answered it from two unrelated tables: the dashboard from an inline `$ssOrderTones` array
 * in `dashboard/overview.blade.php`, the list from the vendored theme's `btn-soft-*` classes baked
 * into HTML strings in `OrderDataTable`. They already disagreed about FOUR of the nine statuses:
 * Shipped was `info` on the dashboard and `btn-soft-dark` in the list, Confirmed `info` there and
 * `btn-soft-warning` here, Pending `warning` there and `btn-soft-info` here, Picked Up `info`
 * there and `btn-soft-secondary` here. A merchant reading the dashboard and then opening the list
 * saw the same order change colour.
 *
 * THE LABELS ARE DELIBERATELY NOT HERE. The two surfaces word them differently on purpose -- the
 * dashboard says "Order Delivered" where the list says "Delivered : 11 Oct 2026" -- and the filter
 * bar has a third wording again. Unifying the copy is a merchant-visible change and a separate
 * decision; unifying the TONE is a design-system fact with no copy consequence. Only the second is
 * done here, and the difference is the point.
 *
 * WHY THE LIST'S OWN CLASSES HAD TO GO. Measured in the running application with WCAG alpha
 * compositing, across LTR/RTL x light/dark, the vendored button classes were below AA in 44 of 56
 * readings and two of them were not readable at all:
 *
 *     btn-soft-dark    (Shipped, Partially Paid, Pre Order)   1.12:1 in dark mode
 *     btn-soft-danger  (Cancel, Return)                       1.03:1 in dark mode
 *     btn-soft-success (Delivered)                            2.59:1 everywhere
 *     btn-soft-warning (Confirmed)                            1.68:1 in light mode
 *
 * The design system's own badge tones are measured at AA on both surfaces and in both themes, so
 * the list now renders `ss-badge` and the question "what colour is this status" has one answer.
 */
final class OrderStatusTone
{
    /**
     * Status code to design-system tone.
     *
     * `neutral` is not a fallback dressed up as a value: `Partially Paid` is a PAYMENT state and
     * `Pre Order` is an ORDER TYPE, both sharing `orders.delivered_status` with the seven
     * fulfilment stages (ledger F0). Giving them a fulfilment hue would be a claim about delivery
     * that the column does not support.
     */
    private const TONES = [
        HomeController::ORDER_STATUS_PENDING        => 'warning',
        HomeController::ORDER_STATUS_DELIVERED      => 'success',
        HomeController::ORDER_STATUS_CANCEL         => 'danger',
        HomeController::ORDER_STATUS_RETURN         => 'danger',
        HomeController::ORDER_STATUS_CONFIRMED      => 'info',
        HomeController::ORDER_STATUS_PICKED_UP      => 'info',
        HomeController::ORDER_STATUS_SHIPPED        => 'info',
        HomeController::ORDER_STATUS_PARTIALLY_PAID => 'neutral',
        HomeController::ORDER_STATUS_PRE_ORDER      => 'neutral',
    ];

    /** An unknown code is `neutral` -- no hue, so a tenth status cannot inherit a claim. */
    public static function for(int $status): string
    {
        return self::TONES[$status] ?? 'neutral';
    }

    /**
     * The badge markup for a caller that cannot use the Blade component.
     *
     * `OrderDataTable` builds its columns as HTML strings, so `<x-ss.status-badge>` is not
     * available to it. This renders the component's own markup instead of a second design: the
     * dot carries the meaning that colour alone must not (WCAG 1.4.1), and it is `aria-hidden`
     * because the text beside it already says the status.
     *
     * The markup itself lives in `DsBadge`, which the product list's stock column uses too --
     * one definition of the component for every string-building caller, so a change to the badge
     * does not have to be found in three DataTables classes.
     */
    public static function badge(int $status, string $label): string
    {
        return DsBadge::render(self::for($status), $label);
    }
}
