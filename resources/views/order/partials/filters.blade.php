{{--
    P2-04-8 — the order list's filter bar.

    THE SAME MECHANISM AS THE PRODUCT LIST'S, and deliberately the same markup: a plain GET form
    whose parameters the table's AJAX leg inherits, because yajra sets `ajax.url` to the current
    url. No JavaScript, one form, both legs. See `product/partials/filters.blade.php` for the
    measurement that established it.

    THE STATUS OPTIONS ARE BUILT FROM `HomeController`'s CONSTANTS, not written out here.
    `orders.delivered_status` holds NINE values and two of them are not fulfilment states: 7 is
    Partially Paid, a payment state, and 8 is Pre Order, an order type, both sharing the column
    (ledger F0). Typing a list by hand is how a filter ends up offering 0–6 and quietly hiding
    those orders from the one screen a merchant uses to find them. Building it from the constants
    means a tenth status appears here on its own.

    THE TWO ARE LABELLED AS WHAT THEY ARE. The dashboard already says these two are "recorded in
    the same field, but not fulfilment states"; repeating that honesty in the filter costs one
    optgroup and stops the list implying a delivery stage that does not exist.
--}}
@php
    use App\Http\Controllers\HomeController;

    // Label per value, in the order the column's own constants declare them.
    $ssFulfilment = [
        HomeController::ORDER_STATUS_PENDING   => __('Pending'),
        HomeController::ORDER_STATUS_CONFIRMED => __('Confirmed'),
        HomeController::ORDER_STATUS_PICKED_UP => __('Picked Up'),
        HomeController::ORDER_STATUS_SHIPPED   => __('Shipped'),
        HomeController::ORDER_STATUS_DELIVERED => __('Delivered'),
        HomeController::ORDER_STATUS_CANCEL    => __('Cancel'),
        HomeController::ORDER_STATUS_RETURN    => __('Return'),
    ];

    $ssOther = [
        HomeController::ORDER_STATUS_PARTIALLY_PAID => __('Partially Paid'),
        HomeController::ORDER_STATUS_PRE_ORDER      => __('Pre Order'),
    ];

    $ssStatus = request()->query('ss_status');
@endphp

<form method="GET" action="{{ route('order.index') }}" data-ss-order-filters
      class="ss-scope ss-filterbar" role="search" aria-label="{{ __('Filter orders') }}">

    {{-- P2-06-5. The receipt renders whenever filters are in force, not only after a restore:
         the "we kept these" flash survives one request, so a RELOAD of a restored page would
         otherwise be the silently-narrowed screen this exists to prevent. --}}
    <x-ss.filter-summary
        :filters="\App\Services\TableFilterMemory::describe(request(), 'order', [])"
        :restored="(bool) session('ss_filters_restored')" />


    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-order-status">{{ __('Order Status') }}</label>
        <select class="ss-select" id="ss-filter-order-status" name="ss_status">
            <option value="">{{ __('Any status') }}</option>

            <optgroup label="{{ __('Fulfilment') }}">
                @foreach ($ssFulfilment as $value => $label)
                    <option value="{{ $value }}" @selected($ssStatus === (string) $value)>{{ $label }}</option>
                @endforeach
            </optgroup>

            {{-- Named for what they are, not grouped with the delivery stages. --}}
            <optgroup label="{{ __('Recorded in the same field, but not fulfilment states') }}">
                @foreach ($ssOther as $value => $label)
                    <option value="{{ $value }}" @selected($ssStatus === (string) $value)>{{ $label }}</option>
                @endforeach
            </optgroup>
        </select>
    </div>

    {{-- P2-04-13. The return-request queue, offered in the bar as well as linked from the
         "needs attention" block above the table — so a merchant can reach it deliberately and
         not only by clicking a count.

         A SELECT WITH ONE REAL OPTION, not a checkbox, for two reasons: the bar is a plain GET
         form with no JavaScript, and an unchecked checkbox submits NOTHING, so "Any" would be
         indistinguishable from "the merchant cleared it" — the distinction
         `TableFilterMemory::shouldRestore()` depends on. `orders.return_status` holds four values
         and only `1` narrows anything (see `OrderDataTable::applyFilters()`), so offering 2 and 3
         would be offering filters that return everything. --}}
    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-return">{{ __('Returns') }}</label>
        <select class="ss-select" id="ss-filter-return" name="ss_return">
            <option value="">{{ __('Any') }}</option>
            <option value="1" @selected(request()->query('ss_return') === '1')>{{ __('Awaiting a decision') }}</option>
        </select>
    </div>

    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-payment">{{ __('Payment Status') }}</label>
        <select class="ss-select" id="ss-filter-payment" name="ss_payment">
            <option value="">{{ __('Any payment status') }}</option>
            @foreach (\App\DataTables\OrderDataTable::PAYMENT_STATUSES as $ssPayment)
                <option value="{{ $ssPayment }}"
                    @selected(request()->query('ss_payment') === $ssPayment)>{{ __($ssPayment) }}</option>
            @endforeach
        </select>
    </div>

    {{-- P2-04-11. SOURCE -- which path created the order.

         The options are read from `Order::SOURCES` and labelled by `Order::orderSourceLabel()`,
         so the bar cannot offer a value `OrderDataTable::applyFilters()` refuses, and a value
         added to the vocabulary appears here without a second edit.

         THERE IS NO "Not recorded" OPTION. Orders written before the column existed carry NULL,
         and NULL is the absence of a record rather than a fourth source; an option for it would
         invite a merchant to read "we have no idea" as a provenance. The list cell says so in
         words on each such row instead. --}}
    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-source">{{ __('Source') }}</label>
        <select class="ss-select" id="ss-filter-source" name="ss_source">
            <option value="">{{ __('Any source') }}</option>
            @foreach (\App\Models\Order::SOURCES as $ssSource)
                <option value="{{ $ssSource }}"
                    @selected(request()->query('ss_source') === $ssSource)>{{ \App\Models\Order::orderSourceLabel($ssSource) }}</option>
            @endforeach
        </select>
    </div>

    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-from">{{ __('From') }}</label>
        {{-- `type="date"`, and the trade is worth stating. The VALUE it submits is always
             `Y-m-d`, which is exactly the format `OrderDataTable::parseDate()` accepts
             strictly — a text field here would mean parsing whatever a merchant typed, in
             whatever order their locale puts the parts. The cost is that the browser renders
             the placeholder and picker in ITS OWN locale, not the page's: an Arabic page shows
             `mm/dd/yyyy` if the browser is set to en-US. That is the native control's
             behaviour and not something markup or CSS can reach; a bespoke picker would be a
             component with its own keyboard, RTL and dark-mode surface to verify. Recorded
             rather than worked around. --}}
        <input class="ss-input" type="date" id="ss-filter-from" name="ss_from"
               value="{{ request()->query('ss_from') }}">
    </div>

    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-to">{{ __('To') }}</label>
        <input class="ss-input" type="date" id="ss-filter-to" name="ss_to"
               value="{{ request()->query('ss_to') }}">
    </div>

    <div class="ss-filterbar__actions">
        {{-- `name="ss_apply"`: its PRESENCE is what writes the filter memory (P2-06-5). Without it,
             any url carrying an `ss_` parameter -- a bookmark, a link in an email, a url pasted
             between colleagues -- would silently become this merchant's new default. --}}
        <button type="submit" name="ss_apply" value="1" class="ss-btn ss-btn--primary">{{ __('Apply filters') }}</button>

        {{-- NON-EMPTY, NOT MERELY PRESENT, and the old test was a real defect: a GET form
             submits every control, so Apply with every select on "Any" yields
             `?ss_status=&ss_stock=&...`, where `query('ss_status')` is `''` -- which is
             `!== null`. So an UNFILTERED list showed a "Clear filters" button, and the button
             that is meant to be the honest signal that something is on was on when nothing was.

             `?ss_clear=1` rather than the bare url, because the bare url is exactly what a
             restore re-decorates: without a distinct forget signal, Clear would hop to
             `/order`, the memory would fire, and the merchant would be returned to the
             filters they just asked to leave. --}}
        @if (\App\Services\TableFilterMemory::describe(request(), 'order', []) !== [])
            {{-- A LINK, not `type="reset"`: reset restores the form's initial values, which on a
                 filtered page are the filters themselves. --}}
            <a class="ss-btn ss-btn--ghost" href="{{ route('order.index', ['ss_clear' => 1]) }}">{{ __('Clear filters') }}</a>
        @endif
    </div>
</form>

{{--
    P2-04-9 — SAVED VIEWS. A SIBLING OF THE FILTER FORM, NEVER NESTED INSIDE IT: the bar above is
    one GET form, and a `<form>` inside a `<form>` is invalid HTML that browsers resolve by
    dropping the inner one — the save button would silently submit the filter bar instead.

    EVERY ACTION IS A POST. The neighbouring controllers have a recorded history of
    state-changing GETs (ledger F14, F30, F31), so applying and deleting both carry a token.

    WHAT A SAVED VIEW HOLDS IS ONLY FILTER VALUES. The hidden inputs below are built from
    `TableFilterMemory::vocabularyFor('order')`, so nothing outside the screen's own allowlist can
    be posted into one, and `SavedView::sanitiseFilters()` applies the same list again on write
    AND on read. Sharing stays what P2-04-8 made it: a link carrying values, never a store id,
    never a view id, never a row.
--}}
@php($ssSavedViews = $ssSavedViews ?? collect())
@php($ssVocabulary = \App\Services\TableFilterMemory::vocabularyFor('order'))
@php($ssCurrentFilters = collect($ssVocabulary)->mapWithKeys(fn ($k) => [$k => (string) request()->query($k, '')])->filter(fn ($v) => $v !== ''))

<div class="ss-scope ss-filterbar__saved">
    @if ($ssSavedViews->isNotEmpty())
        <div class="ss-chips" role="group" aria-label="{{ __('Saved views') }}">
            @foreach ($ssSavedViews as $ssView)
                <span class="ss-chip ss-chip--static">
                    <form method="POST" action="{{ route('saved-views.apply', $ssView->id) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="ss-btn ss-btn--ghost ss-btn--sm"
                                title="{{ __('Apply this saved view') }}">{{ $ssView->name }}</button>
                    </form>
                    <form method="POST" action="{{ route('saved-views.destroy', $ssView->id) }}" class="d-inline">
                        @csrf
                        @method('DELETE')
                        {{-- The shared confirmation component, as every other destructive control
                             on this screen uses (P2-01's one dialog, 124 call sites). --}}
                        <button type="button"
                                class="ss-chip__remove show_confirm"
                                data-confirm="{{ __('Are You Sure?') }}"
                                data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                aria-label="{{ __('Delete the saved view :name', ['name' => $ssView->name]) }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </button>
                    </form>
                </span>
            @endforeach
        </div>
    @endif

    {{-- OFFERED ONLY WHEN THERE IS SOMETHING TO SAVE. An empty filter set is not a view, and a
         permanently visible "save" box on an unfiltered list teaches merchants to ignore it —
         the same reasoning as P2-04-13's attention block having no empty state. --}}
    @if ($ssCurrentFilters->isNotEmpty())
        <form method="POST" action="{{ route('saved-views.store') }}" class="ss-filterbar__save">
            @csrf
            <input type="hidden" name="screen" value="order">
            @foreach ($ssCurrentFilters as $ssKey => $ssValue)
                <input type="hidden" name="filters[{{ $ssKey }}]" value="{{ $ssValue }}">
            @endforeach

            <div class="ss-filterbar__field">
                <label class="ss-label" for="ss-saved-view-name">{{ __('Save these filters as') }}</label>
                <input class="ss-input" id="ss-saved-view-name" name="name" type="text"
                       maxlength="60" required
                       placeholder="{{ __('e.g. Unpaid this week') }}">
            </div>

            <button type="submit" class="ss-btn ss-btn--secondary">{{ __('Save view') }}</button>
        </form>
    @endif
</div>
