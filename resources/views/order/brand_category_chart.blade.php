{{--
    The Top Category / Top Brand "By Sales" body, replaced into
    `#category-tab-data` / `#brand-tab-data` by the dashboard's AJAX.

    ---------------------------------------------------------------------------
    [2026-11-01] THE `/100` IS GONE, AND SO IS THE FAN-OUT IT COMPENSATED FOR (F242, owner-approved).

    EVERYTHING THIS BLOCK USED TO SAY WAS TRUE OF A QUERY THAT NO LONGER EXISTS, and it is kept in
    outline because the reasoning is the record of why the divisor was defended twice before it was
    removed correctly.

    WS C once removed `/100` on the reasoning that `total_sale` is `SUM(orders.final_price)` and
    `final_price` is a major-unit decimal. That reasoning was right about the column and wrong about
    the query: the twelve queries in `HomeController::top_brand_category_chart()` cross-joined a
    generated series `numbers.n` of 1..100, so a single-product order joined 100 times and its price
    was summed 100 times. A real MariaDB said so, and removing the divisor then multiplied every
    figure on both cards by a hundred. So it went back.

    WHAT THE DIVISOR COULD NEVER FIX, and what F242 recorded as still wrong: the fan-out is NOT
    UNIFORM. Nothing bounded `n` to the element count, so for an order of `k` products the first
    `k-1` matched once each and the last matched `101-k` times. `/100` was therefore exact only for
    `k = 1`, and under-reported every earlier category of a multi-product order a hundredfold. And
    separately, summing an ORDER total credited each matched category the whole order, shipping and
    tax included, so a two-category order was counted twice over.

    BOTH ARE FIXED AT THE SOURCE. `App\Support\TopSellersByRevenue` sums each order LINE's own
    revenue from `orders.product_json` and attributes it to that line's product's category or brand.
    No cross join, so no fan-out; no divisor, arbitrary or otherwise; correct for 1, 2, 3 and more
    products; explicit store and half-open date predicates. It runs on SQLite as well as MySQL, so
    these figures are testable in the ordinary Feature suite for the first time — the old query
    could not execute there at all, which is why the only coverage was a MariaDB script and why the
    browser harness rendered these cards empty.

    SO `total_sale` IS NOW PRODUCT REVENUE IN MAJOR UNITS, printed as-is. It is deliberately NOT the
    sum of order totals: shipping, tax and coupon discounts are order-level and belong to no
    category, so these figures do not add up to the dashboard's order-value tile and should not.
    ---------------------------------------------------------------------------

    WS C DID FIX THE WRAPPER'S FALSE ARIA. It carried an `id` and an
    `aria-labelledby` interpolated from `$tab_name` — the request's own `tabId` —
    so after the first load the id was `#week-category-order-home`, an id
    beginning with `#`, and the `aria-labelledby` named an element that has never
    existed. Bootstrap does not toggle this element either: the five tabs now name
    `#category-tab-data`, the container this markup is injected INTO, and that
    container carries `role="tabpanel"`. A second nested tabpanel with a broken
    label is worse than none.
--}}
@if (isset($top_sales) && count($top_sales) > 0)
    @foreach ($top_sales as $sale)
        <div class="cat-item d-flex flex-wrap align-items-center gap-3 justify-contant-between">
            <div class="cat-img rounded-1 border overflow-hidden border border-primary border-2 rounded">
                <img src="{{ asset($sale->sale_image_path ?? '#') }}" alt="product-img">
            </div>
            <div class="cat-tab-text ss-rank d-flex align-items-start gap-2">
                {{-- THE NAME WAS BIGGER AND QUIETER THAN THE NUMBER IT LABELS: measured
                     18px/400 muted against 16px/600 for the figure, so the row read
                     upside down. `text-muted` goes rather than being overridden, which
                     is what this project does with a host utility a later sheet owns. --}}
                <p class="ss-rank__name">{{ $sale->sale_name }}</p>
                {{-- ANA-TOP. TWO CURRENCIES ARE NO LONGER ADDED TOGETHER.

                     `TopSellersByRevenue` used to return one bare float per row, summed across
                     every order regardless of `orders.currency_code`, and this line printed it
                     under the store's single symbol. For a store that changed currency that was
                     arithmetic on incommensurable units presented as a fact -- and nothing on the
                     card said so. The figure is now denominated in the store's OWN currency, any
                     other currency is listed beside it rather than folded in, and no conversion
                     is performed because no conversion basis has been approved.

                     `null`, NOT 0.00, when a row earned nothing in the store's currency: a zero
                     would rank it against rows that genuinely earned nothing, and that is the
                     under-report that replaces an over-report. --}}
                <span class="cat-price ss-rank__value">
                    @if (($sale->total_sale ?? null) === null)
                        {{ __('Not available') }}
                    @else
                        {{-- `?? getCurrentStore()` AND THAT IS SAFE HERE, UNLIKE THE CUSTOMER
                             TIMELINE. GAP-156 fixed a screen that formatted with
                             `getCurrentStore()` while summing from another store -- but that route
                             deliberately admits the operator to ANY store's customer, so the two
                             could differ. This card is only ever the viewer's OWN dashboard: the
                             controller computes with `getCurrentStore()` and passes the same value
                             down, so the default cannot disagree with the sum. It exists so a
                             caller that renders this partial without the variable degrades to the
                             right store rather than throwing. --}}
                        @if (($sale->currency_is_configured ?? true))
                            {{ currency_format_with_sym($sale->total_sale, $top_sales_store ?? getCurrentStore()) }}
                        @else
                            {{-- THE CODE, NOT THE STORE'S SYMBOL, and the review of this slice is
                                 why. `currency_format_with_sym()` prints
                                 `settings.defult_currancy_symbol`, a DIFFERENT row from the
                                 `defult_currancy` this figure is denominated in. A store that
                                 configured neither gets the symbol default `$` while the amount is
                                 denominated in whichever currency its orders actually used -- so
                                 the figure would read `$100.00` while its own caveat, built from
                                 the real code, said `100.00 EUR`. One line disagreeing with itself
                                 about the unit. Labelling with the code cannot do that. --}}
                            {{ \App\Support\Metrics\MoneyText::amount($sale->total_sale, $sale->currency ?? '') }}
                        @endif
                    @endif
                </span>
                @if (($sale->caveat ?? '') !== '')
                    {{-- VISIBLE, not a `title` attribute. `StatHintIsVisibleTest`'s premise is that
                         a caveat nobody can read is not a disclosure, and a tooltip is invisible to
                         touch and to a screen reader that is not hovering. --}}
                    {{-- `ss-stat__hint`, THE DESIGN SYSTEM'S OWN VISIBLE-CAVEAT CLASS. The first
                         draft invented `ss-rank__caveat` (no rule anywhere in
                         public/css/store-syria-ds.css) plus Bootstrap's `text-body-secondary`,
                         which appears nowhere else in resources/views and nowhere in public/ --
                         so the muting was inert if the vendored Bootstrap predates 5.3. This class
                         is already contrast-checked in both themes and `StatHintIsVisibleTest`
                         pins that it stays visible. --}}
                    <span class="ss-stat__hint d-block w-100">{{ $sale->caveat }}</span>
                @endif
            </div>
        </div>
    @endforeach
@else
    {{-- AN EMPTY STATE THAT SAYS WHAT IS MISSING AND WHEN IT WILL FILL.

         It used to be the two words "No Data Found", centred in a card measured at 490px tall,
         with no icon and no guidance -- and identical on both cards, because this partial had no
         way to tell Top Category from Top Brand. `$type` now comes from the controller, so each
         card names its own dimension.

         `<x-ss.empty>` is the design system's component for this and is what the notifications,
         customer-timeline and catalogue screens already use, so the merchant meets one empty
         state across the admin rather than a different improvisation per card. --}}
    @php
        $ssDimension = ($type ?? '') === 'category' ? 'category' : (($type ?? '') === 'brand' ? 'brand' : '');
    @endphp
    @if ($ssDimension === 'category')
        <x-ss.empty icon="chart-bar"
                    :title="__('No category sales yet')"
                    :body="__('Once orders include products that belong to a category, the categories that sell most appear here.')" />
    @elseif ($ssDimension === 'brand')
        <x-ss.empty icon="tag"
                    :title="__('No brand sales yet')"
                    :body="__('Once orders include products that carry a brand, the brands that sell most appear here.')" />
    @else
        <x-ss.empty icon="chart-bar"
                    :title="__('No sales yet')"
                    :body="__('Top sellers appear here once orders start coming in.')" />
    @endif
@endif
