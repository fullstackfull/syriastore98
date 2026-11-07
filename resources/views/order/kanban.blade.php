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
        // P2.5-R-05: `pickedup`, no underscore. `Order::order_status_change()` tests
        // `$order_status == 'pickedup'`; `picked_up` matched no branch, so that one move went on
        // silently doing nothing even after the field name was repaired -- the worse failure of
        // the two, because three working options make the fourth look like the merchant's
        // mistake. OrderKanbanMoveTest sweeps every option against the writer's vocabulary rather
        // than listing four cases, so a fifth added later cannot slip past.
        /*
         * THE QUERY STRING THIS SCREEN MAY HAND ON, with the filter-memory keys REMOVED.
         *
         * `TableFilterMemory::remember()` writes the session filter memory only when `ss_apply`
         * is present, and `OrderController::index()` calls it -- while the board is reached as
         * `route('order.kanban', request()->query())`, so after an Apply the board's own query
         * string carries that key. A plain `array_merge(request()->query(), ...)` on the overflow
         * link below would therefore OVERWRITE the merchant's remembered order filters with a
         * status they never chose, which is precisely what that method's guard exists to prevent.
         * The filters themselves are kept: a "See all 500 Delivered" that silently widened an
         * Unpaid board back to every order would be a different lie.
         */
        $ssBase = \Illuminate\Support\Arr::except(request()->query(), [
            \App\Services\TableFilterMemory::APPLY,
            \App\Services\TableFilterMemory::CLEAR,
        ]);

        $ssMoves = [
            'confirmed' => __('Confirmed'),
            'pickedup'  => __('Picked Up'),
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
                            {{-- THE TRUE COUNT, not the number of cards drawn. A column is capped
                                 at OrderController::BOARD_COLUMN_CAP, and a truncation the
                                 merchant cannot feel is how a real order gets forgotten -- so the
                                 number comes from the grouped COUNT(*) over the whole filtered
                                 set and the overflow link below offers the rest. --}}
                            @php($ssCount = $ssCounts[$ssStatus] ?? 0)
                            <section class="ss-board__col" role="listitem"
                                     aria-label="{{ $ssLabels[$ssStatus] }} ({{ $ssCount }})">
                                <header class="ss-board__head">
                                    <span class="ss-badge ss-badge--{{ OrderStatusTone::for($ssStatus) }}">{{ $ssLabels[$ssStatus] }}</span>
                                    <span class="ss-board__count">{{ $ssCount }}</span>
                                    {{-- IN THE HEADER, NOT UNDER THE CARDS. `.ss-board__cards` is
                                         its own scroll container, so the only signal that the
                                         column is truncated would otherwise sit fifty cards down
                                         an internal scroller -- invisible exactly when it
                                         matters. An `<a>`, never a form: this screen has one
                                         writer and it is `order.status.change`. --}}
                                    @if ($ssCount > $ssOrders->count())
                                        <a class="ss-board__more" href="{{ route('order.index', array_merge($ssBase, ['ss_status' => $ssStatus])) }}">{{ __('See all :count in the list', ['count' => $ssCount]) }}</a>
                                    @endif
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

        {{-- The TRUE aside total, summed over the counts rather than over the capped
             collections, or a capped aside would under-report and (at the limit) hide itself. --}}
        @php($ssAsideTotal = collect($ssAside)->keys()->sum(fn ($s) => $ssCounts[(int) $s] ?? 0))

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
                            @php($ssCount = $ssCounts[(int) $ssStatus] ?? 0)
                            <section class="ss-board__col" role="listitem"
                                     aria-label="{{ $ssLabels[$ssStatus] ?? __('Status :value', ['value' => $ssStatus]) }} ({{ $ssCount }})">
                                <header class="ss-board__head">
                                    <span class="ss-badge">{{ $ssLabels[$ssStatus] ?? __('Status :value', ['value' => $ssStatus]) }}</span>
                                    <span class="ss-board__count">{{ $ssCount }}</span>
                                    @if ($ssCount > $ssOrders->count())
                                        <a class="ss-board__more" href="{{ route('order.index', array_merge($ssBase, ['ss_status' => $ssStatus])) }}">{{ __('See all :count in the list', ['count' => $ssCount]) }}</a>
                                    @endif
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
