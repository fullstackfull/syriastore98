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
        <button type="submit" class="ss-btn ss-btn--primary">{{ __('Apply filters') }}</button>

        @if (request()->query('ss_status') !== null || request()->query('ss_payment') !== null
            || request()->query('ss_from') !== null || request()->query('ss_to') !== null)
            {{-- A link, not `type="reset"`: reset restores the form's initial values, which on a
                 filtered page are the filters themselves. Clearing means the unfiltered url. --}}
            <a class="ss-btn ss-btn--ghost" href="{{ route('order.index') }}">{{ __('Clear filters') }}</a>
        @endif
    </div>
</form>
