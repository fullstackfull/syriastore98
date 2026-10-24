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
    @php $ssStatusHref = $ssMayOrders ? route('order.index') : null; @endphp
    <x-ss.card :title="__('Orders by status')"
               :note="$ssMayOrders ? __('All time. Select a status to open that list.') : __('All time.')"
               class="mb-4">
        <div class="ss-chips">
            <x-ss.chip :label="__('Pending')"          :count="number_format($ssPendingOrders)"                        :href="$ssStatusHref" :interactive="false" />
            <x-ss.chip :label="__('Confirmed Order')"  :count="number_format((int) ($confirmed_order ?? 0))"           :href="$ssStatusHref" :interactive="false" />
            <x-ss.chip :label="__('Order Shipped')"    :count="number_format((int) ($shipped_order ?? 0))"             :href="$ssStatusHref" :interactive="false" />
            <x-ss.chip :label="__('Order Delivered')"  :count="number_format((int) ($delivered_order ?? 0))"           :href="$ssStatusHref" :interactive="false" />
            <x-ss.chip :label="__('Order Return')"     :count="number_format($ssReturnOrders)"                         :href="$ssStatusHref" :interactive="false" />
            <x-ss.chip :label="__('Cancel Order')"     :count="number_format((int) ($cancel_order ?? 0))"              :href="$ssStatusHref" :interactive="false" />
        </div>
    </x-ss.card>

</div>
