{{--
    P2-04-5 -- the order quick preview, rendered into the side panel.

    READ-ONLY, DELIBERATELY. ORD-001 forbids editing an order's products or
    quantities after creation, and a panel is the wrong surface for the two
    changes that ARE allowed (delivery assignment and internal notes) because
    both need the context the full page gives. So this previews and then
    LINKS to the page; it never posts.

    WHAT IT WILL NOT DO. `final_price` is the order's value, not revenue and
    not profit (PRD-001), so it is labelled "Order value" and nothing here
    adds figures across orders. Status is presented as THREE SEPARATE
    READINGS -- fulfilment, payment, and order type -- because
    `orders.delivered_status` is one integer carrying all three concepts
    (ledger F0: 0-6 are fulfilment, 7 is Partially Paid, 8 is Pre Order) and
    collapsing them into one badge is how the field misleads. The full-page
    order view presents them together; this surfaces the distinction.

    `$order` is `Order::order_detail()`'s array. `$orderModel` is the row, used
    only for the fields the array does not carry.
--}}
@php
    use App\Http\Controllers\HomeController;

    $fulfilment = (int) ($order['order_status'] ?? 0);

    // The fulfilment reading, and NOTHING ELSE. 7 and 8 are not fulfilment
    // states, so they report the fulfilment position as unknown rather than
    // borrowing a label that would be wrong.
    $fulfilmentLabels = [
        HomeController::ORDER_STATUS_PENDING   => ['label' => __('Pending'),   'tone' => 'warning'],
        HomeController::ORDER_STATUS_CONFIRMED => ['label' => __('Confirmed'), 'tone' => 'info'],
        HomeController::ORDER_STATUS_PICKED_UP => ['label' => __('Picked up'), 'tone' => 'info'],
        HomeController::ORDER_STATUS_SHIPPED   => ['label' => __('Shipped'),   'tone' => 'info'],
        HomeController::ORDER_STATUS_DELIVERED => ['label' => __('Delivered'), 'tone' => 'success'],
        HomeController::ORDER_STATUS_CANCEL    => ['label' => __('Cancelled'), 'tone' => 'danger'],
        HomeController::ORDER_STATUS_RETURN    => ['label' => __('Returned'),  'tone' => 'neutral'],
    ];

    $fulfilmentBadge = $fulfilmentLabels[$fulfilment] ?? null;

    $paymentStatus = (string) ($order['payment_status'] ?? '');
    $paymentTone = match (strtolower($paymentStatus)) {
        'paid', 'succeeded' => 'success',
        'unpaid', 'pending' => 'warning',
        'failed', 'cancelled' => 'danger',
        default => 'neutral',
    };

    $products = is_array($order['product'] ?? null) ? $order['product'] : [];
@endphp

<div class="ss-scope ss-order-preview">

    <dl class="ss-order-preview__meta">
        <div>
            <dt>{{ __('Order') }}</dt>
            <dd>{{ $order['order_id'] ?? '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('Placed') }}</dt>
            <dd>
                @if (!empty($orderModel->order_date))
                    <time datetime="{{ \Carbon\Carbon::parse($orderModel->order_date)->toDateString() }}">
                        {{ \Carbon\Carbon::parse($orderModel->order_date)->isoFormat('D MMM YYYY') }}
                    </time>
                @else
                    {{ __('Not available') }}
                @endif
            </dd>
        </div>
        <div>
            <dt>{{ __('Customer') }}</dt>
            <dd>
                {{-- A guest order has customer_id 0, so "Guest" is the truth, not a missing name. --}}
                @if (!empty($order['is_guest']))
                    {{ __('Guest') }}
                @else
                    {{ $order['billing_informations']['name'] ?? __('Not available') }}
                @endif
            </dd>
        </div>
    </dl>

    {{--
        THREE READINGS, NOT ONE BADGE. See the header note: one column carries
        three concepts, and the panel says which is which rather than hiding it.
    --}}
    <section class="ss-order-preview__statuses" aria-labelledby="ss-order-preview-status">
        <h3 id="ss-order-preview-status" class="ss-order-preview__h">{{ __('Status') }}</h3>

        <dl class="ss-order-preview__statusgrid">
            <div>
                <dt>{{ __('Fulfilment') }}</dt>
                <dd>
                    @if ($fulfilmentBadge)
                        <x-ss.status-badge :tone="$fulfilmentBadge['tone']" :label="$fulfilmentBadge['label']" />
                    @else
                        {{-- 7 (Partially Paid) and 8 (Pre Order) live in the same column but are
                             not fulfilment states, so this says so instead of guessing. --}}
                        <x-ss.status-badge tone="neutral" :label="__('Not recorded')" />
                        <x-ss.help>{{ __('This order carries a payment or order-type value in the fulfilment field.') }}</x-ss.help>
                    @endif
                </dd>
            </div>

            <div>
                <dt>{{ __('Payment') }}</dt>
                <dd>
                    <x-ss.status-badge :tone="$paymentTone" :label="$paymentStatus !== '' ? __($paymentStatus) : __('Not recorded')" />
                </dd>
            </div>

            <div>
                <dt>{{ __('Payment method') }}</dt>
                <dd>{{ !empty($order['paymnet_type']) ? __($order['paymnet_type']) : __('Not recorded') }}</dd>
            </div>

            <div>
                <dt>{{ __('Shipping method') }}</dt>
                <dd>{{ !empty($order['shipping_method']) ? $order['shipping_method'] : __('Not recorded') }}</dd>
            </div>

            @if ($fulfilment === \App\Http\Controllers\HomeController::ORDER_STATUS_PARTIALLY_PAID)
                <div>
                    <dt>{{ __('Recorded as') }}</dt>
                    <dd><x-ss.status-badge tone="warning" :label="__('Partially paid')" /></dd>
                </div>
            @endif

            @if ($fulfilment === \App\Http\Controllers\HomeController::ORDER_STATUS_PRE_ORDER)
                <div>
                    <dt>{{ __('Recorded as') }}</dt>
                    <dd><x-ss.status-badge tone="info" :label="__('Pre-order')" /></dd>
                </div>
            @endif
        </dl>
    </section>

    <section class="ss-order-preview__items" aria-labelledby="ss-order-preview-items">
        <h3 id="ss-order-preview-items" class="ss-order-preview__h">
            {{ __('Items') }}
            <span class="ss-order-preview__count">{{ count($products) }}</span>
        </h3>

        @if (empty($products))
            <x-ss.empty :title="__('No line items recorded')"
                        :body="__('This order has no product rows stored against it.')" />
        @else
            {{-- A real table, because this is tabular data and a screen reader
                 user needs the row/column relationship to read a quantity
                 against its product. --}}
            <table class="ss-table ss-order-preview__table">
                <caption class="ss-visually-hidden">{{ __('Items in this order') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('Product') }}</th>
                        <th scope="col" class="ss-table__num">{{ __('Qty') }}</th>
                        <th scope="col" class="ss-table__num">{{ __('Line total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($products as $item)
                        <tr>
                            <th scope="row">
                                {{ $item['name'] ?? __('Unnamed product') }}
                                @if (!empty($item['variant_name']))
                                    <span class="ss-order-preview__variant">{{ $item['variant_name'] }}</span>
                                @endif
                            </th>
                            <td class="ss-table__num">{{ $item['qty'] ?? 1 }}</td>
                            <td class="ss-table__num">
                                {{ ($order['currency_symbol'] ?? '') . ($item['total_orignal_price'] ?? '—') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="ss-order-preview__totals" aria-labelledby="ss-order-preview-totals">
        <h3 id="ss-order-preview-totals" class="ss-order-preview__h">{{ __('Totals') }}</h3>

        <dl class="ss-order-preview__totalgrid">
            <div>
                <dt>{{ __('Subtotal') }}</dt>
                <dd>{{ $order['sub_total_formatted'] ?? (($order['currency_symbol'] ?? '') . ($order['sub_total'] ?? '—')) }}</dd>
            </div>
            @if (!empty($order['coupon_price']))
                <div>
                    <dt>{{ __('Discount') }}</dt>
                    <dd>−{{ ($order['currency_symbol'] ?? '') . $order['coupon_price'] }}</dd>
                </div>
            @endif
            @if (!empty($order['tax_price']))
                <div>
                    <dt>{{ __('Tax') }}</dt>
                    <dd>{{ $order['tax_price_formatted'] ?? (($order['currency_symbol'] ?? '') . $order['tax_price']) }}</dd>
                </div>
            @endif
            @if (!empty($order['delivered_charge']))
                <div>
                    <dt>{{ __('Delivery') }}</dt>
                    <dd>{{ ($order['currency_symbol'] ?? '') . $order['delivered_charge'] }}</dd>
                </div>
            @endif
            <div class="ss-order-preview__total">
                {{-- "Order value", NOT revenue and NOT profit. PRD-001: this is
                     what the order was worth when placed; it says nothing about
                     what was collected or settled. --}}
                <dt>{{ __('Order value') }}</dt>
                <dd>{{ $order['final_price_formatted'] ?? (($order['currency_symbol'] ?? '') . ($order['final_price'] ?? '—')) }}</dd>
            </div>
        </dl>

        <x-ss.help>{{ __('Order value is what this order was worth when placed. It is not revenue and not profit.') }}</x-ss.help>
    </section>

    <div class="ss-order-preview__actions">
        {{--
            The panel LINKS to the page rather than acting. `order.view` is
            guarded by OrderDocumentAccessGuard on its own uri, so a caller who
            reached this preview is not automatically admitted there -- the
            guard answers again on that request.

            Crypt::encrypt here because that is what the EXISTING route expects;
            it is an encoding on a uri whose authorization is the guard's, not
            the ciphertext's.
        --}}
        <a class="ss-btn ss-btn--primary"
           href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order['id'])) }}">
            {{ __('Open full order') }}
        </a>
    </div>
</div>
