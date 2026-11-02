{{--
    P2-05-3 — the most-stocked report's status badge.

    It previously rendered the same `stock_order_status` gate as the low-stock tab, so its status
    column was EMPTY for every row in the dev fixture -- measured: ten rows, no badge. A
    most-stocked product is normally `in`, but not always: this report ranks by quantity and a
    store whose largest holding is still below its own threshold would legitimately show `low`
    here. Deriving the badge rather than asserting "In stock" is what makes that readable.

    It now renders the state the SHARED RULE reached — `ProductDataTable::trackedStockState()`, the
    same function the product list's badge and the list's own filter use — passed in as
    `stock_state` by `ReportController::withStockState()`. The three tabs therefore cannot disagree
    with the catalogue, which is this slice's acceptance.

    `DsBadge` rather than a raw Bootstrap pill: the tones are theme-aware tokens, so the badge is
    AA in light and dark without a per-theme override, and it is the same component the order and
    product lists use.

    THE FALLBACK ARM IS NOT DEAD CODE. A row reaching this view with no `stock_state` would be a
    row the controller built without the decorator, which is a bug rather than a state — so it
    renders a neutral badge saying the level is unknown instead of silently showing nothing, which
    is precisely how the previous version hid its own failure.
--}}
@php
    $state = $product['stock_state'] ?? null;
    $count = $product['stock'] ?? null;
    $low = $product['stock_effective_low'] ?? null;
@endphp
@if ($state === 'low')
    {!! \App\Support\DsBadge::render('warning', __('Low stock'),
        __(':count left, at or below the low-stock level of :threshold.', [
            'count' => (int) $count, 'threshold' => (int) $low,
        ])) !!}
@elseif ($state === 'out')
    {!! \App\Support\DsBadge::render('danger', __('Out of stock'),
        __('None left. :count in stock.', ['count' => (int) $count])) !!}
@elseif ($state === 'in')
    {!! \App\Support\DsBadge::render('success', __('In stock'),
        __(':count left, above the low-stock level of :threshold.', [
            'count' => (int) $count, 'threshold' => (int) $low,
        ])) !!}
@else
    {!! \App\Support\DsBadge::render('neutral', __('Level unknown'),
        __('This row reached the report without a stock level, which is a defect rather than a state.')) !!}
@endif
