{{--
    P2-02 — merchant dashboard overview.

    Replaces the row of eight equally-weighted count tiles with two regions
    that answer different questions:

      "Needs attention"  what the merchant has to DO today. The one region on
                         this page with an accent bar, and absent entirely when
                         there is nothing in it.
      The stat grid      what the shop HAS. Boxless, on the page background,
                         with every figure carrying its own period.

    Why not both as cards: if every region is a card then nothing has
    emphasis, and the thing a merchant opens this page for is the first
    region, not the fourth.

    HONESTY, and these are not stylistic choices:

    - `$total_revenues` is sum(final_price). It is labelled ORDER VALUE and
      qualified "gross". It is not revenue, not cash collected and not profit.
      Phase 01's P1-PAY-04 established that Delivered is not Paid, and
      HANDOFF §13c records that an order's stored components cannot always
      reproduce its own final_price -- so the persisted total is shown and
      NOTHING is derived from its parts.
    - There is no profit or margin figure, because the codebase computes none.
    - Every figure states its period. A count with no period is the commonest
      way a dashboard misleads, which is why <x-ss.stat> has no default for it.
    - The nine `*Per` percentages the controller computes are deliberately NOT
      shown. calculatePercentageToday() measures today against the ALL-TIME
      total, so for any store with history it is a large negative number
      rather than a day-over-day change. They are unused by every view in the
      repository today and this screen does not resurrect them.

    Variables come from HomeController::handleRegularUser() via @include's
    shared scope.
--}}

@php
    $ssPendingOrders = (int) ($pending_order ?? 0);
    $ssOutOfStock    = (int) ($out_of_stock_products ?? 0);
    $ssReturnOrders  = (int) ($return_order ?? 0);
    $ssNeedsAttention = $ssPendingOrders > 0 || $ssOutOfStock > 0 || $ssReturnOrders > 0;

    /* Entitlement for what this partial shows, resolved once.
     *
     * `/dashboard` carries ['auth','xss','setlocate','verified'] and NO
     * permission middleware (routes/web.php:400-402), so every principal on
     * the `web` guard in this tenant reaches this view -- including a staff
     * member whose role grants, say, only Manage Blog. Anything shown here is
     * therefore shown to all of them unless it gates itself.
     *
     * Laratrust's @permission resolves through hasPermission(), NOT through
     * Laravel's Gate, so it is unaffected by this project's
     * permissions_as_gates => false. (@can would be, and would be silently
     * false -- do not swap these for @can.) The platform operator does not
     * reach this file at all: HomeController::index() sends `super admin` to
     * superadmin.dashboard, so the operator-first ordering the rest of the
     * codebase needs does not apply here.
     */
    $ssMayOrders    = auth()->check() && auth()->user()->isAbleTo('Manage Order');
    $ssMayProducts  = auth()->check() && auth()->user()->isAbleTo('Manage Product');
    $ssMayCustomers = auth()->check() && auth()->user()->isAbleTo('Manage Customer');
@endphp

<div class="ss-scope">

    @if ($ssNeedsAttention)
        <x-ss.attention :title="__('Needs attention')"
                        :severity="$ssOutOfStock > 0 ? 'warning' : 'brand'"
                        class="mb-4">

            @if ($ssPendingOrders > 0)
                <x-ss.attention-item
                    :count="$ssPendingOrders"
                    :text="trans_choice('order is waiting to be confirmed|orders are waiting to be confirmed', $ssPendingOrders)"
                    :href="$ssMayOrders ? route('order.index') : null"
                    :action="$ssMayOrders ? __('Review orders') : null" />
            @endif

            @if ($ssOutOfStock > 0)
                <x-ss.attention-item
                    :count="$ssOutOfStock"
                    :text="trans_choice('product is at or below the stock threshold|products are at or below the stock threshold', $ssOutOfStock)"
                    :href="$ssMayProducts ? route('product.index') : null"
                    :action="$ssMayProducts ? __('Review stock') : null" />
            @endif

            @if ($ssReturnOrders > 0)
                <x-ss.attention-item
                    :count="$ssReturnOrders"
                    :text="trans_choice('order has been returned|orders have been returned', $ssReturnOrders)"
                    :href="$ssMayOrders ? route('order.index') : null"
                    :action="$ssMayOrders ? __('Review returns') : null" />
            @endif
        </x-ss.attention>
    @endif

    <x-ss.stats :label="__('Store totals')">
        <x-ss.stat :label="__('Orders')"
                   :value="number_format((int) ($totle_order ?? 0))"
                   :period="__('all time')"
                   :href="$ssMayOrders ? route('order.index') : null" />

        {{-- Gated on Manage Order, and that is not decoration.

             This figure was NOT on the merchant dashboard before Phase 02:
             $total_revenues sat in HomeController's compact() with no
             consumer. Rendering it here without a gate would newly show
             store-wide gross order value to every authenticated member of the
             tenant, including staff with no order permission at all. The gate
             uses the same permission name the order screen itself checks
             (OrderController::mayUseOrderScreen('Manage Order')), so the two
             agree about who may see order money.

             Omitted entirely rather than shown as zero or "unavailable": the
             data exists, the viewer may not see it, and those are different
             statements. --}}
        @if ($ssMayOrders)
            <x-ss.stat :label="__('Order value')"
                       :value="currency_format_with_sym($total_revenues ?? 0, getCurrentStore())"
                       :period="__('all time, gross')"
                       :hint="__('The total value of orders placed. This is not payments received, and not profit.')" />
        @endif

        <x-ss.stat :label="__('Customers')"
                   :value="number_format((int) ($totle_customers ?? 0))"
                   :period="__('all time')"
                   :href="$ssMayCustomers ? route('customer.index') : null" />

        <x-ss.stat :label="__('Products')"
                   :value="number_format((int) ($totalproduct ?? 0))"
                   :period="__('in this store')"
                   :href="$ssMayProducts ? route('product.index') : null" />
    </x-ss.stats>

    {{-- Order status, as a status breakdown rather than six more tiles. Each
         one is a link into the filtered list, because a count the merchant
         cannot open is a count they cannot act on. --}}
    {{-- P2-04: the breakdown PARTITIONS the total, and says so.

         `orders.delivered_status` is one integer column carrying nine values,
         and two of them are not fulfilment states at all: 7 is Partially Paid
         (a payment state) and 8 is Pre Order (an order type). The dashboard
         used to show six of the nine, leaving picked-up, partially-paid and
         pre-order in no bucket -- six counts beside a total they could not add
         up to, with nothing on screen to say so.

         All nine are shown now, plus `other` for any value outside 0-8, and
         the footer states the sum so a merchant can see it reconcile. The two
         that are not fulfilment states are labelled as what they are rather
         than quietly listed among the rest. --}}
    @php
        $ssStatusHref = $ssMayOrders ? route('order.index') : null;

        $ssFulfilment = [
            ['label' => __('Pending'),         'count' => $ssPendingOrders],
            ['label' => __('Confirmed Order'), 'count' => (int) ($confirmed_order ?? 0)],
            ['label' => __('Picked Up'),       'count' => (int) ($picked_up_order ?? 0)],
            ['label' => __('Order Shipped'),   'count' => (int) ($shipped_order ?? 0)],
            ['label' => __('Order Delivered'), 'count' => (int) ($delivered_order ?? 0)],
            ['label' => __('Order Return'),    'count' => $ssReturnOrders],
            ['label' => __('Cancel Order'),    'count' => (int) ($cancel_order ?? 0)],
        ];

        $ssNotFulfilment = [
            ['label' => __('Partially Paid'), 'count' => (int) ($partially_paid_order ?? 0)],
            ['label' => __('Pre Order'),      'count' => (int) ($pre_order_order ?? 0)],
        ];

        $ssOther = (int) ($other_status_order ?? 0);
        $ssStatusSum = array_sum(array_column($ssFulfilment, 'count'))
            + array_sum(array_column($ssNotFulfilment, 'count'))
            + $ssOther;
    @endphp

    <x-ss.card :title="__('Orders by status')"
               :note="$ssMayOrders ? __('All time. Select a status to open that list.') : __('All time.')"
               class="mb-4">
        <div class="ss-chips">
            @foreach ($ssFulfilment as $ssRow)
                <x-ss.chip :label="$ssRow['label']"
                           :count="number_format($ssRow['count'])"
                           :href="$ssStatusHref"
                           :interactive="false" />
            @endforeach
        </div>

        @if ($ssNotFulfilment[0]['count'] > 0 || $ssNotFulfilment[1]['count'] > 0 || $ssOther > 0)
            <p class="ss-section__note" style="margin-block-start: var(--ss-space-4);">
                {{ __('Recorded in the same field, but not fulfilment states:') }}
            </p>
            <div class="ss-chips">
                @foreach ($ssNotFulfilment as $ssRow)
                    @if ($ssRow['count'] > 0)
                        <x-ss.chip :label="$ssRow['label']"
                                   :count="number_format($ssRow['count'])"
                                   :href="$ssStatusHref"
                                   :interactive="false" />
                    @endif
                @endforeach
                @if ($ssOther > 0)
                    <x-ss.chip :label="__('Other')" :count="number_format($ssOther)" :interactive="false" />
                @endif
            </div>
        @endif

        <x-slot:footer>
            <p class="ss-stat__period" data-ss-status-sum="{{ $ssStatusSum }}">
                {{ __('These add up to :sum of :total orders.', ['sum' => number_format($ssStatusSum), 'total' => number_format((int) ($totle_order ?? 0))]) }}
            </p>
        </x-slot:footer>
    </x-ss.card>

</div>
