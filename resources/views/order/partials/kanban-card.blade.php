{{--
    P2-04-7 — one order on the board.

    THE STATUS CONTROL POSTS TO `order.status.change`, the SAME route the detail page uses. That
    handler reads the id from the route SEGMENT (not the body — a posted `id` used to win over the
    url and act on another order), checks `OrderPolicy::allow('updateStatus')` operator-first then
    `sameStore`, and its cancel limb is a money write `OrderObserver` forwards to
    `OrderFinancialService`. A board with its own writer would have to re-earn all of that, so it
    does not have one.

    THE MOVES OFFERED ARE THE ONES THE WRITER ACCEPTS — `confirmed`, `picked_up`, `shipped`,
    `delivered` — and cancel/return are deliberately NOT here: they are irreversible and
    money-bearing, and they belong on the detail page where the merchant sees the whole order
    before acting. A drag that could cancel an order is a drag nobody should be able to make by
    accident.
--}}
{{--
    `<div>` RATHER THAN `<p>` FOR THE META LINES, and that is not a style preference. The host's
    dark stylesheet carries `.dash-sidebar .dash-link, …, p { color: #808191 !important }` — EVERY
    paragraph in the dark admin — which measured 3.42:1 on this card's surface and which no
    component CSS can override, `!important` being the point. Raising the DS selector's
    specificity was tried and measured: it loses, as it must. A `<div>` is not matched by that
    rule, so the card's own colour applies. The theme-wide defect is recorded as F152 rather than
    worked around silently.
--}}
<article class="ss-board__card">
    <a class="ss-board__card-id"
       href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($ssOrder->id)) }}">
        #{{ $ssOrder->product_order_id }}
    </a>

    <div class="ss-board__card-meta">
        {{ $ssOrder->CustomerData->name ?? __('Walk-in Customer') }}
    </div>

    <div class="ss-board__card-meta">
        {{ order_currency_format_with_sym($ssOrder->final_price ?? 0, $ssOrder->id) }}
        @if ($ssOrder->payment_status)
            <span class="ss-badge {{ $ssOrder->payment_status === 'Paid' ? 'ss-badge--success' : '' }}">{{ __($ssOrder->payment_status) }}</span>
        @endif
    </div>

    @php($ssSource = \App\Models\Order::orderSourceLabel($ssOrder->order_source))
    @if ($ssSource !== null)
        <div class="ss-board__card-meta"><span class="ss-badge">{{ $ssSource }}</span></div>
    @endif

    @permission('Manage Order')
        @php($ssOffer = collect($ssMoves)->reject(fn ($label, $value) => $label === ($ssLabels[$ssOrder->delivered_status] ?? null)))
        @if ($ssOffer->isNotEmpty())
            <form method="POST" action="{{ route('order.status.change', $ssOrder->id) }}"
                  class="ss-board__card-move">
                @csrf
                <input type="hidden" name="id" value="{{ $ssOrder->id }}">
                <label class="ss-label ss-visually-hidden" for="ss-move-{{ $ssOrder->id }}">
                    {{ __('Move order :ref to', ['ref' => $ssOrder->product_order_id]) }}
                </label>
                <select class="ss-select ss-select--sm" id="ss-move-{{ $ssOrder->id }}" name="order_status">
                    <option value="">{{ __('Move to…') }}</option>
                    @foreach ($ssOffer as $ssValue => $ssLabel)
                        <option value="{{ $ssValue }}">{{ $ssLabel }}</option>
                    @endforeach
                </select>
                <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">{{ __('Move') }}</button>
            </form>
        @endif
    @endpermission
</article>
