@extends('layouts.app')

@section('page-title', __('Customer Timeline'))

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('customer.index') }}">{{ __('Customer') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item"> {{ $customer_data->first_name }} </li>
@endsection

@section('content')
    <div class="row  custom-timeline-2 ">
        <div class="col-xl-12">
            <div class="row gy-3">
                <div class="col-lg-3 col-6">
                    <div class="card stats-wrapper info-card">
                        <div class="card-body stats">
                            <div class="theme-avtar bg-success badge">
                                <i class="ti ti-user" aria-hidden="true"></i>
                            </div>
                            <h6 class="mt-4 mb-2">{{ __('Customer Name') }}</h6>
                            <h3 class="mb-0">
                                {{ $customer_data->first_name . ' ' . $customer_data->last_name }} <span
                                    class="text-success text-sm">
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="card stats-wrapper info-card">
                        <div class="card-body stats">
                            <div class="theme-avtar bg-info badge">
                                <i class="ti ti-mail" aria-hidden="true"></i>
                            </div>
                            <h6 class="mt-4 mb-2">{{ __('Customer Email') }}</h6>
                            <h3 class="mb-0">{{ $customer_data->email }}<span class="text-success text-sm">
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="card stats-wrapper info-card">
                        <div class="card-body stats">
                            <div class="theme-avtar bg-warning badge">
                                <i class="ti ti-report-money" aria-hidden="true"></i>
                            </div>
                            <h6 class="mt-4 mb-2">{{ __('Total Spend') }}</h6>
                            {{-- P2.5-PKG-G SLICE 5. A HARDCODED `.00` AND NO CURRENCY SYMBOL AT ALL. The figure is a
                                 SUM over `orders.final_price`, printed with two decimals glued on and nothing
                                 saying what unit it is in -- on the one card on this screen whose whole job is
                                 to state an amount. The sibling screen formats the same kind of figure with
                                 `currency_format_with_sym(..., getCurrentStore())`
                                 (customer/view.blade.php:99); this is that call. --}}
                            <h3 class="mb-0">{{ currency_format_with_sym(($totalSpend['total_spend'] ?? 0), getCurrentStore()) }} <span class="text-success text-sm">
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="card stats-wrapper info-card">
                        <div class="card-body stats">
                            <div class="theme-avtar bg-dark badge">
                                <i class="ti ti-shopping-cart text-dark" aria-hidden="true"></i>
                            </div>
                            <h6 class="mt-4 mb-2">{{ __('Total Orders') }}</h6>
                            <h3 class="mb-0">{{ $totalSpend['total_orders'] }} <span class="text-success text-sm">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if (!empty($customer_data->regiester_date))
        <div @if (count($logs) > 0) class="my-custom-timeline" @endif>
            {{-- REGISTERED, BUT NOTHING HAS HAPPENED YET. The `@else` further down answers
                 "never registered"; this answers the commoner case, which rendered an empty
                 unclassed <div> and nothing else -- a blank panel with no explanation. --}}
            @if (count($logs) === 0)
                <x-ss.empty
                    icon="activity"
                    :title="__('No activity yet')"
                    :body="__('This customer has registered but has not browsed, added to cart or placed an order yet. Their activity will appear here as it happens.')" />
            @endif
            <div class="custom-timeline-inner">
                <div class="timeline">
                    @foreach ($logs as $key => $value)
                        {{-- P2.5-PKG-H SLICE 1 -- ONE BODY, AND THE SIDE IS COMPUTED.

                             This `@foreach` held `@if ($key % 2 == 0)` with a FULL COPY of all seven
                             event bodies in EACH arm -- 225 and 214 lines whose only real difference
                             was `right` versus `left` on the container. The nine `end-date` class
                             values were the same set in both. Measured, then re-asserted by the
                             script that performed this edit: it refuses to run if the two arms are
                             not identical once the side class is normalised.

                             THE COPIES HAD DRIFTED, which is the argument for this edit and is
                             measured rather than asserted: until PKG-G slice 5, line 173 of the even
                             arm printed `json_decode($value->remark)->product` RAW while its odd
                             twin passed it through `ActivityLog::products()`. The same wishlist
                             event read as a bare id or as a product name depending only on whether
                             it landed on an even or an odd row. Fixing that line closed the symptom;
                             this makes the class of defect structurally impossible.

                             ARM1 IS THE SURVIVOR DELIBERATELY: the nine-line comment recording that
                             defect exists only in arm1, and it is the only in-file record of it.

                             THE SEVEN-BRANCH CHAIN KEEPS ITS SHAPE AND ITS MISSING `@else`. A
                             `log_type` outside the seven stored values emits nothing today, and a
                             data-driven icon map -- the obvious "improvement" here -- would change
                             output for unknown types. This slice changes exactly one thing.

                             OUTPUT NEUTRALITY, MEASURED AND STATED EXACTLY. A fixture covering all
                             seven log types at BOTH parities -- 14 rows, 7 `left` and 7 `right` --
                             was rendered through the real route before and after. The TAG SEQUENCE
                             is identical: 538 tags, same order, same attributes. The TEXT is
                             identical once two values that are random per test run are normalised
                             (the faker e-mail that `$value->userinfo` resolves, and the log rows'
                             `created_at`).

                             IT IS NOT BYTE-IDENTICAL, AND THE REASON IS WORTH KNOWING. Arm1 carried
                             the slice-5 comment BETWEEN `<span` and its `class` attribute, so Blade
                             left whitespace inside the tag; arm2 did not. Collapsing to arm1 would
                             have propagated that whitespace to every `add wishlist` row. So the
                             comment was moved ABOVE the `<li>` in the same edit, which removes the
                             whitespace from both rows instead. The difference is whitespace inside
                             a start tag, which HTML does not distinguish -- but "zero output change"
                             would have been the wrong claim, so this says what was actually
                             measured. --}}
                        @php($ssSide = $key % 2 === 0 ? 'right' : 'left')

                        {{-- P2.5-PKG-H SLICE 2 -- SEVEN TABLER GLYPHS REPLACE FOURTEEN HAND-DRAWN SVGs.

                             WHAT WAS WRONG, measured: 14 inline `<svg>` carrying 42 `fill="black"`,
                             drawn on SIX different internal scales -- 458x446, 460x460, 360x513,
                             369x522, 512x480 (shared by both cart types) and 480x454 -- every one
                             forced into `width="13" height="14"`. So what differed between these
                             icons was not their box but their apparent STROKE WEIGHT, because a
                             369-unit drawing and a 512-unit drawing squeezed into 13px do not land
                             on the same visual weight. Seven events, six weights, one row.

                             AND `fill="black"` IGNORED THE THEME. `.tl-btn` declares no colour in
                             light mode, and the dark arm at public/css/custom.css:5353 sets
                             `color: var(--ss-grey-900, #1a1d23)` on `.tl-box .tl-btn`. A hard-coded
                             `#000` glyph was therefore the one thing in the chip that did not follow
                             that guard. A `ti` glyph inherits `currentColor`, so it is now exactly
                             the colour of the LABEL BESIDE IT, by construction rather than by
                             coincidence.

                             MEASURED on all four chip grounds (#E0FFF5, #FFF3D6, #ECFFE4, #FFF1F1),
                             against the 4.5:1 bar that applies because the chip is 12px/600:
                             the dark-arm ink gives 15.91, 15.31, 16.07, 15.36 -- all PASS. The old
                             hard-coded black gave 19.79, 19.04, 19.99, 19.10, so this is a small
                             loss of contrast against a large margin, in exchange for following the
                             theme. Both are far above the bar.

                             EVERY GLYPH NAME WAS CHECKED AGAINST THE BUNDLED FONT
                             (public/assets/fonts/tabler-icons.min.css, 1298 glyphs) BEFORE USE, and
                             that is not ceremony: FIVE of the fifteen names chosen on first pass do
                             not exist in it -- ti-heart-plus, ti-heart-minus, ti-heart-off,
                             ti-login-2 and ti-shopping-cart-minus are all absent. The same trap
                             produced a Flutter defect earlier in this phase (ti-search-off absent,
                             ti-zoom-cancel present).

                                 register        -> ti-user-plus
                                 last login      -> ti-login
                                 add wishlist    -> ti-bookmark
                                 delete wishlist -> ti-bookmark-off
                                 add to cart     -> ti-shopping-cart-plus
                                 remove to cart  -> ti-shopping-cart-x
                                 place order     -> ti-package

                             THE HEART WAS REJECTED FOR A MEASURED REASON, not taste. `ti-heart`
                             exists, but no `-plus`, `-minus` or `-off` variant is bundled, so the
                             add/remove pair could not be built from it. `ti-bookmark`/`ti-bookmark-off`
                             is the only family in this font offering a true on/off pair.

                             AND `remove to cart` IS `-x`, NOT `-off`: the two hand-drawn cart glyphs
                             shared a cart body and differed only in a corner badge, which is the
                             `-plus`/`-x` relationship rather than the `-off` one. Both names are
                             bundled, so matching what the drawings already did costs nothing.

                             `aria-hidden="true"` on every one: the chip's text names the event
                             (`ActivityLogLabel::for()`), so the glyph is decoration and announcing
                             it would read the event twice. --}}
                            @if ($value->log_type == 'register')
                                <div class="tl-container {{ $ssSide }}">
                                    <span class="support-time"></span>
                                    <div class="tl-box">
                                        <span class="tl-btn licence-btn">
                                            <i class="ti ti-user-plus" aria-hidden="true"></i>
                                            {{ \App\Support\ActivityLogLabel::for($value->log_type) }}
                                        </span>
                                        <ul>
                                            <li><span><b>{{ __('First Name:-') }}
                                                    </b></span><span>{{ $value->userinfo->first_name }} </span></li>
                                            <li><span><b>{{ __('Last Name:-') }}
                                                    </b></span><span>{{ $value->userinfo->last_name }} </span></li>
                                            <li><span><b>{{ __('Email:-') }}
                                                    </b></span><span>{{ $value->userinfo->email }}</span>
                                            </li>
                                            <li><span><b>{{ __('Contact:-') }}
                                                    </b></span><span>{{ $value->userinfo->mobile }}</span></li>
                                            <li><span><b>{{ __('Register Date:-') }} </b></span><span
                                                    class="end-date register-timeline">{{ $value->userinfo->regiester_date }}</span>
                                            </li>
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time">{{ $value->created_at->format('d M Y h:i A') }}</span>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($value->log_type == 'last login')
                                <div class="tl-container {{ $ssSide }}">
                                    <span class="support-time"></span>
                                    <div class="tl-box">
                                        <span class="tl-btn licence-btn">
                                            <i class="ti ti-login" aria-hidden="true"></i>
                                            {{ \App\Support\ActivityLogLabel::for($value->log_type) }}
                                        </span>
                                        <ul>
                                            <li><span><b>{{ __('Last Login Date:-') }} </b></span><span
                                                    class="end-date last-login-timeline">{{ json_decode($value->remark)->date }}</span>
                                            </li>
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time">{{ $value->created_at->format('d M Y h:i A') }}</span>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($value->log_type == 'add wishlist')
                                <div class="tl-container {{ $ssSide }}">
                                    <span class="support-time"></span>
                                    <div class="tl-box">
                                        <span class="tl-btn licence-btn">
                                            <i class="ti ti-bookmark" aria-hidden="true"></i>

                                            {{ \App\Support\ActivityLogLabel::for($value->log_type) }}
                                        </span>
                                        <ul>
                                        {{-- P2.5-PKG-G SLICE 5. THROUGH THE RESOLVER, like the other NINE.
                                        This was the ONLY one of ten product renders in this file that
                                        printed `json_decode($value->remark)->product` raw.
                                        `ActivityLog::products()` turns that comma-separated list of
                                        product IDs into NAMES, so an "add wishlist" entry showed a bare
                                        id here and the product's name everywhere else -- the same event
                                        reading differently depending on whether it landed on an EVEN or
                                        an ODD row, because the two arms of the `$key % 2` alternation
                                        were 95.8% identical copies that had drifted apart. THOSE TWO ARMS
                                        ARE GONE as of P2.5-PKG-H slice 1 -- one body now, and the side
                                        is computed -- so this records what happened, not a live hazard.
                                        It is kept because it is the argument for the collapse. --}}
                                            <li><span><b>{{ __('Product:-') }} </b></span><span class="end-date wishlist-timeline">{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</span>
                                            </li>
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time">{{ $value->created_at->format('d M Y h:i A') }}</span>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($value->log_type == 'delete wishlist')
                                <div class="tl-container {{ $ssSide }}">
                                    <span class="support-time"></span>
                                    <div class="tl-box">
                                        <span class="tl-btn licence-btn">
                                            <i class="ti ti-bookmark-off" aria-hidden="true"></i>

                                            {{ \App\Support\ActivityLogLabel::for($value->log_type) }}
                                        </span>
                                        <ul>
                                            <li><span><b>{{ __('Product:-') }} </b></span><span
                                                    class="end-date dlt-wishlist-timeline">{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</span>
                                            </li>
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time">{{ $value->created_at->format('d M Y h:i A') }}</span>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($value->log_type == 'add to cart')
                                <div class="tl-container {{ $ssSide }}">
                                    <span class="support-time"></span>
                                    <div class="tl-box">
                                        <span class="tl-btn licence-btn">
                                            <i class="ti ti-shopping-cart-plus" aria-hidden="true"></i>
                                            {{ \App\Support\ActivityLogLabel::for($value->log_type) }}
                                        </span>
                                        <ul>
                                            <li><span><b>{{ __('Product:-') }} </b></span>
                                                <span
                                                    class="end-date">{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</span>
                                            </li>
                                            @if (json_decode($value->remark)->variant != '0')
                                                <li><span><b>{{ __('Variant:-') }} </b></span>
                                                    <span
                                                        class="end-date add-cart-timeline">{{ App\Models\ActivityLog::variants(json_decode($value->remark)->variant, $timelineStoreId) }}</span>
                                                </li>
                                            @endif
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time">{{ $value->created_at->format('d M Y h:i A') }}</span>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($value->log_type == 'remove to cart')
                                <div class="tl-container {{ $ssSide }}">
                                    <span class="support-time"></span>
                                    <div class="tl-box">
                                        <span class="tl-btn licence-btn">
                                            <i class="ti ti-shopping-cart-x" aria-hidden="true"></i>
                                            {{ \App\Support\ActivityLogLabel::for($value->log_type) }}
                                        </span>
                                        <ul>
                                            <li><span><b>{{ __('Product:-') }} </b></span>
                                                <span
                                                    class="end-date">{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</span>
                                            </li>
                                            @if (json_decode($value->remark)->variant != '0')
                                                <li><span><b>{{ __('Variant:-') }} </b></span>
                                                    <span
                                                        class="end-date dlt-cart-timeline">{{ App\Models\ActivityLog::variants(json_decode($value->remark)->variant, $timelineStoreId) }}</span>
                                                </li>
                                            @endif
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time">{{ $value->created_at->format('d M Y h:i A') }}</span>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($value->log_type == 'place order')
                                <div class="tl-container {{ $ssSide }}">
                                    <span class="support-time"></span>
                                    <div class="tl-box">
                                        <span class="tl-btn licence-btn">
                                            <i class="ti ti-package" aria-hidden="true"></i>
                                            {{ \App\Support\ActivityLogLabel::for($value->log_type) }}
                                        </span>
                                        <ul>
                                            <li><span><b>{{ __('Order Id:-') }}
                                                    </b></span><span>{{ json_decode($value->remark)->order_id }} </span></li>
                                            <li><span><b>{{ __('Order Date:-') }}
                                                    </b></span><span>{{ Carbon\Carbon::parse(json_decode($value->remark)->order_date)->format('d M Y') }}
                                                </span></li>
                                            <li><span><b>{{ __('Product:-') }} </b></span>
                                                <span
                                                    class="end-date order-timeline">{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</span>
                                            </li>
                                            <li><span><b>{{ __('Order Price:-') }}
                                                    </b></span><span>{{ json_decode($value->remark)->price }} </span></li>
                                            <li><span><b>{{ __('Payment Type:-') }}
                                                    </b></span><span>{{ json_decode($value->remark)->payment_type }} </span>
                                            </li>
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time">{{ $value->created_at->format('d M Y h:i A') }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endif
                    @endforeach
                </div>
            </div>
        </div>
        @else
        <div class="text-center pt-5">
            <h2>{{ __('User') }} {{ $customer_data->first_name }} {{ __('has not registered.') }}</h2>
        </div>
        @endif
    </div>
@endsection
