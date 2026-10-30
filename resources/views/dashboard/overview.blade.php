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
    /* P2-02-9: one order status -> one label and one tone.
     *
     * The integers are HomeController's own constants (P2-04-3), and the
     * labels are the SAME strings the status breakdown below uses, so the
     * latest-orders table and the chips cannot disagree about what a 4 is.
     *
     * 7 and 8 are deliberately NOT given fulfilment-looking tones. They are
     * not fulfilment states at all -- 7 is Partially Paid, a PAYMENT state,
     * and 8 is Pre Order, an ORDER TYPE -- all three living in one integer
     * column is ledger finding F0. The breakdown names them separately for
     * the same reason.
     *
     * An unknown value falls through to its own number rather than to
     * "Pending": inventing a familiar label for a value nobody recognises is
     * how a dashboard reports a state that is not happening.
     */
    $ssOrderLabels = [
        \App\Http\Controllers\HomeController::ORDER_STATUS_PENDING        => __('Pending'),
        \App\Http\Controllers\HomeController::ORDER_STATUS_DELIVERED      => __('Order Delivered'),
        \App\Http\Controllers\HomeController::ORDER_STATUS_CANCEL         => __('Cancel Order'),
        \App\Http\Controllers\HomeController::ORDER_STATUS_RETURN         => __('Order Return'),
        \App\Http\Controllers\HomeController::ORDER_STATUS_CONFIRMED      => __('Confirmed Order'),
        \App\Http\Controllers\HomeController::ORDER_STATUS_PICKED_UP      => __('Picked Up'),
        \App\Http\Controllers\HomeController::ORDER_STATUS_SHIPPED        => __('Order Shipped'),
        \App\Http\Controllers\HomeController::ORDER_STATUS_PARTIALLY_PAID => __('Partially Paid'),
        \App\Http\Controllers\HomeController::ORDER_STATUS_PRE_ORDER      => __('Pre Order'),
    ];
    $ssOrderLabel = fn (int $s) => $ssOrderLabels[$s] ?? __('Status :code', ['code' => $s]);

    /* THE TONES USED TO BE A SECOND ARRAY RIGHT HERE, and the order list had a third answer
       again in its own markup. The two disagreed about four of the nine statuses, so one order
       changed colour between this card and the list a merchant opens from it. One place now:
       App\Support\OrderStatusTone. The LABELS stay local, because the two surfaces word them
       differently on purpose -- "Order Delivered" here, "Delivered : 11 Oct 2026" there. */
    $ssOrderTone  = fn (int $s) => \App\Support\OrderStatusTone::for($s);

    /*
     * F223. OPERATOR-FIRST, because `isAbleTo()` ALONE REFUSES THE PLATFORM OPERATOR.
     *
     * This read `auth()->check() && auth()->user()->isAbleTo('Manage Order')`, so the operator --
     * whose status is a STRING on `users.type` and who has no Laratrust role granting that name --
     * saw no orders section on the dashboard at all. Every other order surface in this application
     * tests the operator first (`OrderController::mayUseOrderScreen`, `OrderDocumentAccessGuard`,
     * the `finance` gate in AuthServiceProvider), so this one blade was out of step with the rule
     * the rest of the code follows.
     *
     * It failed in the SAFE direction -- the operator was shown LESS, never more -- which is why
     * nothing broke and nobody noticed.
     */
    $ssMayOrders    = auth()->check() && (
        strtolower(trim((string) auth()->user()->type)) === 'super admin'
        || auth()->user()->isAbleTo('Manage Order')
    );
    /*
     * `Manage Products`, PLURAL. This read `Manage Product`, SINGULAR -- a permission that
     * EXISTS NOWHERE: not in Utility's seeded `$companyNewPermission`, not in the live
     * permissions table, not in any module seeder. A permission with no row can be held by no
     * role, so this evaluated FALSE for every principal including the tenant owner, and the
     * dashboard silently hid the out-of-stock link, the top-products list and the product
     * onboarding step from everybody.
     *
     * Invisible to every previous check because the preview harness supplies `$GRANTS` directly
     * rather than asking Laratrust, so the name was never resolved against a real permission
     * set. Found by driving /dashboard in the running application. The ratchet that stops it
     * coming back is Tests\Unit\Dashboard\DashboardPermissionNamesTest.
     */
    $ssMayProducts  = auth()->check() && auth()->user()->isAbleTo('Manage Products');
    $ssMayCustomers = auth()->check() && auth()->user()->isAbleTo('Manage Customer');
@endphp

<div class="ss-scope">

    {{-- P2-06 GETTING STARTED, NOW SHIPPED — the RTL hold is settled.

         THE HOLD, AND WHAT IT WAS. The component painted each step's mark TWICE
         under `dir="rtl"` in the preview harness: once in its own box and once
         at the far inline-end of the row. The investigation that recorded this
         exhausted what the harness could answer — one mark in the DOM, ONE
         client rect at the correct position, no box-shadow, transform, filter,
         reflection or pseudo-element, and hiding the mark removed BOTH circles
         — and named the remaining candidate as the harness's own stand-in RTL
         layout, which could only be ruled out in the real application. The
         Composer blocker meant it could not be.

         SETTLED IN THE RUNNING APPLICATION. Driven at /dashboard through a real
         login, a real session and the host's own RTL path (`SITE_RTL = on`, so
         the server serves style-rtl.css / rtl-style-dark.css), at desktop and
         mobile in light and dark: EXACTLY ONE mark per row, in all eight
         combinations, counted from the painted geometry rather than from the
         DOM. The duplication was the harness, not the component — which is what
         the third implementation (an inline SVG, after an icon font whose
         ::before computed empty and a CSS-drawn border that painted twice)
         already suspected.

         The guard against it coming back is
         docs/development/phase-2/tools/shoot-dashboard-live.cjs, which counts
         painted marks per row in the real application and fails on anything but
         one.

         NO NEW QUERIES. Every step is answered from data `handleRegularUser()`
         already put in scope, which is the component's own rule: a checklist
         that costs five COUNT(*) on every dashboard load to tell an established
         merchant something they finished a year ago is a bad trade. --}}
    @php
        $ssSetupSteps = [
            [
                'label' => __('Add your first product'),
                'done'  => (int) ($totalproduct ?? 0) > 0,
                'href'  => route('product.index'),
                'can'   => $ssMayProducts,
            ],
            [
                'label' => __('Name your store and set its details'),
                // `store` is the row itself, so this asks whether the merchant has
                // moved past the name the installer generated.
                'done'  => ! empty($store?->name) && ! empty($store?->email),
                'href'  => route('app-setting.index'),
                'can'   => auth()->check() && auth()->user()->isAbleTo('Manage Store Setting'),
            ],
            [
                'label' => __('Receive your first order'),
                'done'  => (int) ($totle_order ?? 0) > 0,
                'href'  => route('order.index'),
                'can'   => $ssMayOrders,
            ],
            [
                'label' => __('Welcome your first customer'),
                'done'  => (int) ($totle_customers ?? 0) > 0,
                'href'  => route('customer.index'),
                'can'   => $ssMayCustomers,
            ],
        ];

        // IT DISAPPEARS WHEN IT IS DONE. The caller owns that decision, per the
        // component's docblock: an onboarding panel that stays forever stops
        // being onboarding and becomes furniture. Only steps this viewer may act
        // on are counted, so a staff member is not held back by a step they
        // cannot reach.
        $ssVisibleSteps = array_filter($ssSetupSteps, fn ($s) => $s['can'] === true);
        $ssSetupComplete = $ssVisibleSteps !== []
            && count(array_filter($ssVisibleSteps, fn ($s) => $s['done'])) === count($ssVisibleSteps);
    @endphp

    @unless ($ssSetupComplete)
        <x-ss.card :title="__('Getting started')" class="mb-4">
            <x-ss.setup-checklist :steps="$ssSetupSteps" />
        </x-ss.card>
    @endunless


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

    {{-- P2-02-8: the weekly orders series.

         GATED ON `Manage Order`, the same permission as every other order
         figure on this page (P2-02-10). /dashboard carries no permission
         middleware, so a staff member whose role grants only, say, Manage Blog
         reaches this view; order volume per day is order data and is not
         theirs. Omitted entirely rather than zeroed -- the data exists and
         this viewer may not see it, and those are different statements.

         The series is $chartData, which HomeController::handleRegularUser()
         has been putting in this view's scope all along (line 323) while NO
         view rendered it. That is ledger F6: the same variable was being built
         from PlanOrder for merchant staff, platform-wide and unscoped, and was
         harmless only because nothing drew it. P2-02-12 fixed the branch
         first, for exactly this moment -- rendering it is what would have
         turned that latent defect live. --}}
    {{-- The card renders even when the series is empty, and that is the
         opposite of how "Needs attention" behaves two regions up. The
         difference is what absence MEANS. An absent attention block says
         "nothing needs attention", which is self-evident. An absent chart says
         nothing at all -- the merchant cannot tell a quiet week from a widget
         that failed to load. So an empty series gets an explicit empty state,
         and only the permission gate removes the card. --}}
    @if ($ssMayOrders && isset($chartData))
        <x-ss.card :title="__('Orders per day')" class="mt-4">
            <x-ss.bar-chart :labels="$chartData['label']"
                            :values="$chartData['data']"
                            :title="__('Orders per day')"
                            :value-label="__('Orders')"
                            :period="__('last 7 days')" />

            <x-slot:footer>
                <p class="ss-stat__period">
                    {{-- Says what is counted and what is not. An order placed
                         and cancelled the same day is still an order placed,
                         and a chart that quietly excluded it would disagree
                         with the status breakdown above. --}}
                    {{ __('Orders placed each day, last 7 days. Counts every order by the day it was created, whatever its status since.') }}
                </p>
            </x-slot:footer>
        </x-ss.card>
    @endif

    {{-- P2-02-9: the two "what just happened / what sells" lists.

         BOTH DATASETS WERE ALREADY IN THIS VIEW'S SCOPE and rendered by
         nothing: handleRegularUser() has been passing $new_orders and
         $topSellingProducts all along. Neither needed a controller change,
         and checking that was the point — a new query here would have been a
         new chance to get a tenant predicate wrong.

         Both are scoped AT SOURCE, verified rather than assumed:
           $orderQuery   = Order::where('store_id', getCurrentStore())    :198
           $productQuery = Product::where(...)->where('store_id', ...)    :197
         $topSellingProducts is doubly covered — the ids come from
         (clone $orderQuery) and are then intersected with (clone $productQuery).
         That matters because Product registers NO StoreScope and is not in
         config/tenancy.php's scoped_models, so an unscoped Product query here
         would have reached every tenant's catalogue.

         Gated separately: orders on `Manage Order`, products on `Manage Products`
         -- SINGULAR and PLURAL, and both spellings are the real row names, not a
         typo in one of them (see the note on $ssMayProducts above).
         /dashboard carries no permission middleware, so a staff member with
         only one of the two must see only that one. --}}
    @if ($ssMayOrders || $ssMayProducts)
        <div class="ss-row mt-4">
            @if ($ssMayOrders)
                <x-ss.card :title="__('Latest orders')">
                    @if (!empty($new_orders) && count($new_orders))
                        <div class="ss-table-wrap">
                            {{-- `data-ss-list` IS THE VERIFICATION HOOK, and it exists because the
                                 caption cannot be one: it is translated, and in English "The four
                                 most recent orders" contains the word a check for the best-selling
                                 table's own caption would match first. A browser check keyed on a
                                 caption fragment read THIS table and reported it as that one, in
                                 four of eight configurations, and passed. --}}
                            <table class="ss-table" data-ss-list="latest-orders">
                                <caption class="ss-visually-hidden">{{ __('The four most recent orders') }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Order') }}</th>
                                        <th scope="col">{{ __('Status') }}</th>
                                        <th scope="col" class="ss-table__num">{{ __('Order value') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($new_orders as $ssOrder)
                                        <tr>
                                            <th scope="row">
                                                {{-- product_order_id is the merchant-facing reference;
                                                     the primary key is not shown because it is not what
                                                     a merchant reads back to a customer. --}}
                                                <span class="ss-ident">#{{ $ssOrder->product_order_id }}</span>
                                            </th>
                                            <td>
                                                <x-ss.status-badge
                                                    :tone="$ssOrderTone((int) $ssOrder->delivered_status)"
                                                    :label="$ssOrderLabel((int) $ssOrder->delivered_status)" />
                                            </td>
                                            <td class="ss-table__num ss-num">
                                                {{ currency_format_with_sym($ssOrder->final_price) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <x-slot:footer>
                            <p class="ss-stat__period">
                                {{-- The same qualification the order-value stat carries. Phase 01's
                                     P1-PAY-04 established Delivered is not Paid. --}}
                                {{ __('Gross order value, not cash collected.') }}
                            </p>
                        </x-slot:footer>
                    @else
                        <x-ss.empty :title="__('No orders yet')" />
                    @endif
                </x-ss.card>
            @endif

            @if ($ssMayProducts)
                <x-ss.card :title="__('Best selling products')">
                    @if (!empty($topSellingProducts) && count($topSellingProducts))
                        <div class="ss-table-wrap">
                            <table class="ss-table" data-ss-list="best-selling">
                                <caption class="ss-visually-hidden">{{ __('Products appearing most often in this store\'s orders') }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Product') }}</th>
                                        <th scope="col" class="ss-table__num">{{ __('Price') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topSellingProducts as $ssProduct)
                                        <tr>
                                            <th scope="row"><span class="ss-truncate">{{ $ssProduct->name }}</span></th>
                                            <td class="ss-table__num ss-num">
                                                {{ currency_format_with_sym($ssProduct->price) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <x-slot:footer>
                            <p class="ss-stat__period">
                                {{-- Ranked by HOW MANY ORDERS NAME THE PRODUCT (HomeController:219-234
                                     groups order rows by product_id and counts them), not by quantity
                                     sold and not by value. Saying "best selling" without saying by what
                                     measure is the kind of claim PRD-001 exists to stop. --}}
                                {{ __('Ranked by how many orders include the product, all time.') }}
                            </p>
                        </x-slot:footer>
                    @else
                        <x-ss.empty :title="__('No sales yet')" />
                    @endif
                </x-ss.card>
            @endif
        </div>
    @endif

</div>
