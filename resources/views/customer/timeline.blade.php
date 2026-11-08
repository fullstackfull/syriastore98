@extends('layouts.app')

@section('page-title', __('Customer Timeline'))

@section('page-subtitle')
    {{ trim($customer_data->first_name . ' ' . $customer_data->last_name) }}
    @if (!empty($customer_data->email))
        &middot; <bdi>{{ $customer_data->email }}</bdi>
    @endif
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('customer.index') }}">{{ __('Customer') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item"> <bdi>{{ $customer_data->first_name }}</bdi> </li>
@endsection

@section('content')
    <div class="row  custom-timeline-2 ">
        {{-- P2.5-PKG-H SLICE 3 -- FOUR HAND-ROLLED CARDS BECOME TWO MEASURES AND A SUBTITLE.

             WHAT WAS HERE: four `card stats-wrapper info-card` tiles, each an `<h6>` label over an
             `<h3>` value, with `public/css/custom.css` forcing that `<h3>` to 24px.

             AND ALL FOUR WERE MALFORMED, which no plan noticed and a markup count did. Every tile
             ended `<h3 class="mb-0">VALUE <span class="text-success text-sm">` -- FOUR `<h3>`
             opened and ZERO closed, each holding an EMPTY `<span>` that also never closed. Eight
             unclosed elements and four dead size-and-colour declarations, in the first fifty lines
             of the screen. Counted directly before this edit: 4 open, 0 close.

             ONLY TWO OF THE FOUR WERE MEASURES, and that distinction is why this is not a
             four-for-four swap. `x-ss.stat` requires `period` and deliberately gives it no default,
             because "a count with no period is the commonest way a dashboard misleads". Total Spend
             and Total Orders have an honest period -- `ActivityLog::totalspend()` applies no date
             filter, so it really is all time. Customer Name and Customer Email have none: "Customer
             Name, all time" is not a thing, and passing a period to make the component accept them
             would be dressing identity up as a metric.

             SO IDENTITY MOVED TO THE PAGE HEADER, into a slot this view never used. The layout
             renders `@hasSection('page-subtitle')` as `.ss-page-header__subtitle` right under the
             h1, and the h1 here says only "Customer Timeline" -- generic. Name and e-mail there
             turn a generic title into a specific one, which is what a subtitle is for, and costs
             two cards that were pretending to be measures.

             `<bdi>` ON THE E-MAIL, not `dir="ltr"`: an address is a Latin run inside a possibly
             Arabic line, and `<bdi>` isolates it without forcing a direction on the whole line.

             AND NOT `<x-ss.page-header>`: `MerchantPageHeadingOutlineTest` fails any view that
             sets `@section('page-title')` -- this one does, at :3 -- and also renders that
             component, because the layout would then print the title twice. Measured by an
             independent check that inserted it and watched the test fail. The sections are the
             supported route; notification/index.blade.php is the shipped precedent. --}}
        <div class="col-xl-12">
            <x-ss.stats :label="__('Customer totals')">
                <x-ss.stat
                    :label="__('Total Spend')"
                    :value="currency_format_with_sym(($totalSpend['total_spend'] ?? 0), getCurrentStore())"
                    :period="__('all time')" />
                <x-ss.stat
                    :label="__('Total Orders')"
                    :value="$totalSpend['total_orders'] ?? 0"
                    :period="__('all time')"
                    {{-- THIS CUSTOMER's orders, not a global list. `customer.show` renders
                         customer/view.blade.php with `Order::where('customer_id', $id)
                         ->where('store_id', ...)`, and it gates on the same `Show Customer`
                         permission `customerTimeline()` already required to reach this page, so a
                         viewer who can read the figure can always open what it counts.

                         `route('order.index')` WAS WRITTEN HERE FIRST AND DOES NOT EXIST. The
                         repository has order.view, order.grid, order.kanban and nine more, but no
                         `order.index` -- it would have thrown RouteNotFoundException on render.
                         Caught by grepping the route files before testing, not by the renderer. --}}
                    :href="route('customer.show', $customer_data->id)"
                    :hint="__('Opens this customer\'s orders')" />
            </x-ss.stats>
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
                                        {{-- P2.5-PKG-H SLICE 6 -- THIS CARD WAS SHOWING A `users` ROW, NOT THE CUSTOMER.

                                             It read `$value->userinfo`, and `ActivityLog::userinfo()` was
                                             `hasOne(User::class, 'id', 'customer_id')`. `customer_id` refers to the
                                             CUSTOMERS table; that joined it against `users.id`. So the card rendered
                                             whichever USER happened to share the id.

                                             MEASURED on the dev fixture, by LOOKING at a capture rather than by
                                             reading code: customer #1 is Layla Haddad, and her register card printed
                                             `operator@example.test` -- user #1, the PLATFORM OPERATOR. Customer #2
                                             would print user #2, another merchant. The collision is not exotic: both
                                             id sequences start at 1, so every customer inside the `users` id range
                                             printed somebody's address and mobile number.

                                             AND FOUR OF THE FIVE FIELDS WERE ALWAYS BLANK, which is the tell that the
                                             relation was wrong from the start rather than recently broken: `users`
                                             has no `first_name`, no `last_name` and no `regiester_date`. Only `email`
                                             and `mobile` resolved -- the two that leak.

                                             `$customer_data` IS THE RIGHT SOURCE, not a repointed relation. The
                                             controller already fetched it AND ownership-checked it, so the card
                                             cannot name anyone else by construction. Repointing `userinfo` at
                                             `Customer` and rendering through it would ALSO be scoped -- `Customer` is
                                             in `scoped_models` -- and this route deliberately admits the operator to
                                             any store's customer while `getCurrentStore()` returns the literal 1 for
                                             them, so the card would have blanked on a store-7 customer instead. The
                                             relation is repaired too, for whatever reaches for it next. --}}
                                        {{-- NO ROW IS DRAWN FOR A VALUE THE CUSTOMER DOES NOT HAVE.
                                             Measured in the live capture: `Contact:-` rendered a label with NOTHING
                                             after it, because `customers.mobile` is nullable and this customer's is
                                             null. A label over an empty value reads as a broken screen rather than as
                                             absent data -- the same defect, and the same repair, as the Flutter
                                             customer card earlier in this phase. First and last name stay
                                             unconditional: a customer row cannot exist without them. --}}
                                        <ul>
                                            <li><span><b>{{ __('First Name:-') }}
                                                    </b></span><span><bdi>{{ $customer_data->first_name }}</bdi> </span></li>
                                            <li><span><b>{{ __('Last Name:-') }}
                                                    </b></span><span><bdi>{{ $customer_data->last_name }}</bdi> </span></li>
                                            @if (!empty($customer_data->email))
                                                <li><span><b>{{ __('Email:-') }}
                                                        </b></span><span><bdi>{{ $customer_data->email }}</bdi></span>
                                                </li>
                                            @endif
                                            @if (!empty($customer_data->mobile))
                                                <li><span><b>{{ __('Contact:-') }}
                                                        </b></span><span><bdi>{{ $customer_data->mobile }}</bdi></span></li>
                                            @endif
                                            @if (!empty($customer_data->regiester_date))
                                                <li><span><b>{{ __('Register Date:-') }} </b></span><span
                                                        class="end-date register-timeline"><bdi>{{ $customer_data->regiester_date }}</bdi></span>
                                                </li>
                                            @endif
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time"><bdi>{{ $value->created_at->format('d M Y h:i A') }}</bdi></span>
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
                                                    class="end-date last-login-timeline"><bdi>{{ json_decode($value->remark)->date }}</bdi></span>
                                            </li>
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time"><bdi>{{ $value->created_at->format('d M Y h:i A') }}</bdi></span>
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
                                            <li><span><b>{{ __('Product:-') }} </b></span><span class="end-date wishlist-timeline"><bdi>{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</bdi></span>
                                            </li>
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time"><bdi>{{ $value->created_at->format('d M Y h:i A') }}</bdi></span>
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
                                                    class="end-date dlt-wishlist-timeline"><bdi>{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</bdi></span>
                                            </li>
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time"><bdi>{{ $value->created_at->format('d M Y h:i A') }}</bdi></span>
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
                                                    class="end-date"><bdi>{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</bdi></span>
                                            </li>
                                            @if (json_decode($value->remark)->variant != '0')
                                                <li><span><b>{{ __('Variant:-') }} </b></span>
                                                    <span
                                                        class="end-date add-cart-timeline"><bdi>{{ App\Models\ActivityLog::variants(json_decode($value->remark)->variant, $timelineStoreId) }}</bdi></span>
                                                </li>
                                            @endif
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time"><bdi>{{ $value->created_at->format('d M Y h:i A') }}</bdi></span>
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
                                                    class="end-date"><bdi>{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</bdi></span>
                                            </li>
                                            @if (json_decode($value->remark)->variant != '0')
                                                <li><span><b>{{ __('Variant:-') }} </b></span>
                                                    <span
                                                        class="end-date dlt-cart-timeline"><bdi>{{ App\Models\ActivityLog::variants(json_decode($value->remark)->variant, $timelineStoreId) }}</bdi></span>
                                                </li>
                                            @endif
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time"><bdi>{{ $value->created_at->format('d M Y h:i A') }}</bdi></span>
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
                                                    </b></span><span><bdi>{{ json_decode($value->remark)->order_id }}</bdi> </span></li>
                                            <li><span><b>{{ __('Order Date:-') }}
                                                    </b></span><span><bdi>{{ Carbon\Carbon::parse(json_decode($value->remark)->order_date)->format('d M Y') }}</bdi>
                                                </span></li>
                                            <li><span><b>{{ __('Product:-') }} </b></span>
                                                <span
                                                    class="end-date order-timeline"><bdi>{{ App\Models\ActivityLog::products(json_decode($value->remark)->product, $timelineStoreId) }}</bdi></span>
                                            </li>
                                            <li><span><b>{{ __('Order Price:-') }}
                                                    </b></span><span><bdi>{{ json_decode($value->remark)->price }}</bdi> </span></li>
                                            <li><span><b>{{ __('Payment Type:-') }}
                                                    </b></span><span><bdi>{{ json_decode($value->remark)->payment_type }}</bdi> </span>
                                            </li>
                                        </ul>
                                        <div class="time-wrapper d-flex align-content-center">
                                            <span class="time"><bdi>{{ $value->created_at->format('d M Y h:i A') }}</bdi></span>
                                        </div>
                                    </div>
                                </div>
                            @endif
                    @endforeach
                </div>
            </div>
        </div>
        @else
            {{-- P2.5-PKG-H SLICE 4 -- THE "NEVER REGISTERED" BRANCH WAS A BARE SENTENCE AT h2.

                 THREE THINGS WERE WRONG.

                 1. THE HEADING OUTRANKED THE PAGE TITLE. It was the vendored `<h2>`, which this
                    theme takes up to 1.75rem, while `--ss-type-page` -- the h1 the layout renders
                    from `@section('page-title')` -- is 1.5rem. So a status note about one customer
                    was the largest text on the screen, above the name of the screen itself.

                 2. THE SENTENCE WAS ASSEMBLED FROM THREE FRAGMENTS:
                    `__('User') . $first_name . __('has not registered.')`. That is the i18n defect
                    this codebase has the idiom to avoid -- `status.blade.php` already uses
                    `__('Account status for :name', ['name' => ...])`. Fragment concatenation fixes
                    ENGLISH word order into every locale: an Arabic translator cannot move the name,
                    because the name is not in either string. One key with a placeholder can be
                    reordered freely, and `ar.json` carries one.

                 3. IT WAS THE ONLY EMPTY-ISH STATE ON THIS SCREEN WITH NO ICON AND NO NEXT ACTION
                    -- a dead end that states a fact and offers nothing.

                 THE ACTION IS REAL, not decoration, and that was checked in the controller rather
                 than assumed: `CustomerController::show()` falls back to matching
                 `OrderBillingDetail` on the customer's e-mail when no order carries their
                 `customer_id` (:404-414), precisely so a GUEST's orders are still reachable. So a
                 never-registered customer can genuinely have orders to look at, and this is the
                 one screen that would otherwise hide them. It gates on `Show Customer`, which the
                 viewer already needed to reach this page.

                 `ti-user-off` was checked against the bundled font before use; ti-user-question,
                 ti-user-search, ti-id-off and ti-user-circle are all ABSENT from it. --}}
            <x-ss.empty
                icon="user-off"
                :title="__(':name has not registered an account', ['name' => trim($customer_data->first_name . ' ' . $customer_data->last_name)])"
                :body="__('There is no account activity to show. They may still have ordered as a guest, in which case their orders are listed on their orders page.')">
                <x-slot:action>
                    <a href="{{ route('customer.show', $customer_data->id) }}" class="ss-btn ss-btn--secondary ss-btn--sm">
                        {{ __('View their orders') }}
                    </a>
                </x-slot:action>
            </x-ss.empty>
        @endif
    </div>
@endsection
