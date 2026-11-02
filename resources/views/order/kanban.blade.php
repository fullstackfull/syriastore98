@extends('layouts.app')

@section('page-title', __('Order board'))

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('order.index') }}">{{ __('Order') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Order board') }}</li>
@endsection

@section('content')
    {{--
        P2-04-7 — THE ORDER BOARD.

        A PRESENTATION OF THE EXISTING ORDERS. Every status control below posts to
        `order.status.change`, the same route the detail page uses; there is no second writer, and
        nothing here touches stock or payment.

        THE COLUMNS ARE THE FULFILMENT STAGES. Statuses 7 (Partially Paid, a PAYMENT state) and 8
        (Pre Order, an ORDER TYPE) are NOT stages — ledger F0 — so they are grouped below the
        board and labelled as what they are. Their orders stay reachable; they are simply not
        presented as a delivery stage that does not exist.
    --}}
    @php
        use App\Http\Controllers\HomeController;
        use App\Support\OrderStatusTone;

        $ssLabels = [
            HomeController::ORDER_STATUS_PENDING   => __('Pending'),
            HomeController::ORDER_STATUS_CONFIRMED => __('Confirmed'),
            HomeController::ORDER_STATUS_PICKED_UP => __('Picked Up'),
            HomeController::ORDER_STATUS_SHIPPED   => __('Shipped'),
            HomeController::ORDER_STATUS_DELIVERED => __('Delivered'),
            HomeController::ORDER_STATUS_CANCEL    => __('Cancel'),
            HomeController::ORDER_STATUS_RETURN    => __('Return'),
            HomeController::ORDER_STATUS_PARTIALLY_PAID => __('Partially Paid'),
            HomeController::ORDER_STATUS_PRE_ORDER      => __('Pre Order'),
        ];

        // The transitions the EXISTING writer accepts, keyed by the value it expects. Not a new
        // vocabulary: `Order::order_status_change()` branches on exactly these strings.
        $ssMoves = [
            'confirmed' => __('Confirmed'),
            'picked_up' => __('Picked Up'),
            'shipped'   => __('Shipped'),
            'delivered' => __('Delivered'),
        ];
    @endphp

    <div class="ss-scope">
        @include('order.partials.filters')

        <div class="ss-card">
            <div class="ss-card__header">
                <h5 class="ss-card__title">{{ __('Order board') }}</h5>
                <a class="ss-btn ss-btn--ghost ss-btn--sm" href="{{ route('order.index', request()->query()) }}">
                    {{ __('Switch to the list') }}
                </a>
            </div>

            <div class="ss-card__body ss-card__body--flush ss-board-wrap">
                @if ($ssTotal === 0)
                    {{-- An empty board says so once, rather than showing seven empty columns. --}}
                    <div class="ss-empty">
                        <p class="ss-empty__body">{{ __('No orders match these filters.') }}</p>
                    </div>
                @else
                    <div class="ss-board" role="list" aria-label="{{ __('Order board') }}">
                        @foreach ($ssPipeline as $ssStatus)
                            @php($ssOrders = $ssColumns[$ssStatus] ?? collect())
                            <section class="ss-board__col" role="listitem"
                                     aria-label="{{ $ssLabels[$ssStatus] }} ({{ $ssOrders->count() }})">
                                <header class="ss-board__head">
                                    <span class="ss-badge ss-badge--{{ OrderStatusTone::for($ssStatus) }}">{{ $ssLabels[$ssStatus] }}</span>
                                    <span class="ss-board__count">{{ $ssOrders->count() }}</span>
                                </header>

                                <div class="ss-board__cards">
                                    @forelse ($ssOrders as $ssOrder)
                                        @include('order.partials.kanban-card', ['ssOrder' => $ssOrder, 'ssMoves' => $ssMoves])
                                    @empty
                                        {{-- Per column, so a merchant can tell "nothing here" from
                                             "this column failed to render". --}}
                                        <p class="ss-board__none">{{ __('Nothing here') }}</p>
                                    @endforelse
                                </div>
                            </section>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        @php($ssAsideTotal = collect($ssAside)->sum(fn ($c) => $c->count()))

        @if ($ssAsideTotal > 0)
            {{-- NOT STAGES, AND SAID SO IN WORDS. F0: these two values share `delivered_status`
                 with the fulfilment stages but are a payment state and an order type. Presenting
                 them as board columns would assert a delivery stage that does not exist; hiding
                 them would lose the orders. --}}
            <div class="ss-card">
                <div class="ss-card__header">
                    <h5 class="ss-card__title">{{ __('Recorded in the same field, but not fulfilment stages') }}</h5>
                </div>
                <div class="ss-card__body">
                    <div class="ss-board ss-board--aside" role="list">
                        @foreach ($ssAside as $ssStatus => $ssOrders)
                            @continue($ssOrders->count() === 0)
                            <section class="ss-board__col" role="listitem"
                                     aria-label="{{ $ssLabels[$ssStatus] ?? __('Status :value', ['value' => $ssStatus]) }} ({{ $ssOrders->count() }})">
                                <header class="ss-board__head">
                                    <span class="ss-badge">{{ $ssLabels[$ssStatus] ?? __('Status :value', ['value' => $ssStatus]) }}</span>
                                    <span class="ss-board__count">{{ $ssOrders->count() }}</span>
                                </header>
                                <div class="ss-board__cards">
                                    @foreach ($ssOrders as $ssOrder)
                                        @include('order.partials.kanban-card', ['ssOrder' => $ssOrder, 'ssMoves' => $ssMoves])
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
