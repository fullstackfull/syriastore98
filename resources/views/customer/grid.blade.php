@extends('layouts.app')

@section('page-title')
{{ __('Customer') }}
@endsection

@section('breadcrumb')
<li aria-current="page" class="ss-breadcrumb__item">{{ __('Customer') }}</li>
@endsection

@section('action-button')
<div class="text-end">
    @permission('Manage Customer')
    <a href="#" class="btn btn-sm btn-primary btn-icon csv" title="{{ __('Export') }}" data-bs-toggle="tooltip"
        data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Export') }}" aria-label="{{ __('Export') }}">
        <i class="ti ti-file-export"></i>
    </a>
    @endpermission
    <a href="{{ route('customer.index') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('List View') }}" class="btn btn-sm btn-primary btn-icon " aria-label="{{ __('List View') }}">
        <i class="ti ti-list"></i>
    </a>
</div>
@endsection

@section('content')
<div class="row">
    <div class="col-md-12">
            {{-- P2.5-PKG-G SLICE 1. Card wrapper and the dead `multiCollapseExample1` id removed;
                 `.ss-filterbar` carries the surface. Same change as the list view beside it, so
                 the two screens that filter the same customers finally look like one product. --}}
                    {{--
                        P2.5-R-07 -- A PLAIN GET FORM, the same repair and the same approved
                        pattern as the list's P2.5-R-02.

                        WHAT WAS HERE, and it had NEVER worked by any path:

                          * An `<a id="apply-button">` whose handler GET-ed
                            `customer.filter.data` and wrote the answer into
                            `$('#service-filter-data')`. THAT ELEMENT DOES NOT EXIST IN THIS VIEW
                            -- the only two occurrences in the file are the two JS writes
                            themselves. jQuery's `.html()` on an empty set is a silent no-op.
                          * Adding the container would not have helped either: the endpoint
                            renders `customer/filter.blade.php`, which emits `<tr>` TABLE ROWS --
                            the LIST's fragment. Table rows injected into a `div` are discarded by
                            the parser, and a row is not a card in any case.
                          * And there was no server-side path to fall back on:
                            `CustomerController::customer_grid_view()` ignored every filter
                            parameter and only paginated.

                        So, unlike the list, this one needed BOTH halves: a form, and a handler
                        that reads it. The handler now calls the same
                        `CustomerListFilter::apply()` the list uses, so the two screens cannot
                        disagree about what "Less Than" means -- which they did, in three places,
                        before P2.5-R-06.

                        THE ADDRESS BECOMES BOOKMARKABLE, and pagination keeps the filter: the
                        paginator is built with `withQueryString()`, so page 2 of a filtered grid
                        is still filtered.
                    --}}
                    <form method="GET" action="{{ route('customer.grid') }}" id="frm_submit"
                          class="ss-scope ss-filterbar"
                          role="search" aria-label="{{ __('Filter customers') }}">

                        {{-- SCALAR-COERCED FIRST, and this is not defensive decoration:
                             `?field_name[]=x` makes `query('field_name')` an ARRAY, and `__()` throws a
                             TypeError on one -- a 500 where the page should simply apply no filter.
                             CustomerGridFilterTest's three array-valued cases exist for exactly that,
                             and they caught this the first time it was written. --}}
                        @php
                            $ssScalar = static fn ($v) => is_scalar($v) ? trim((string) $v) : '';
                            $ssFieldName = $ssScalar(request()->query('field_name'));
                            $ssOperator = $ssScalar(request()->query('selected_name'));
                            $ssValue = $ssScalar(request()->query('text_field'));
                            $ssFiltered = $ssFieldName !== '';
                        @endphp

                        <x-ss.filter-summary
                            :filters="$ssFiltered
                                ? [trim(__($ssFieldName) . ' ' . __($ssOperator) . ' ' . $ssValue)]
                                : []" />

                        <x-ss.filter-disclosure />

                        <div class="ss-filterbar__field">
                            <label class="ss-label" for="customer_field">{{ __('Field') }}</label>
                            {{-- By hand, so the option TEXT translates while the submitted VALUE
                                 stays the English key `CustomerListFilter::apply()` matches on. --}}
                            <select class="ss-select" id="customer_field" name="field_name">
                                <option value="">{{ __('Select Customer Fields') }}</option>
                                @foreach ($customer_field as $ssFieldKey => $ssFieldLabel)
                                    <option value="{{ $ssFieldKey }}" @selected($ssFieldName === (string) $ssFieldKey)>{{ __($ssFieldLabel) }}</option>
                                @endforeach
                            </select>
                        </div>
                        {{-- The two hidden `select_value` / `text_value` inputs that used to sit
                             in these boxes are gone. They were written to by the change handler
                             and read by nothing: the AJAX built its payload from the visible
                             controls. Inside a real form they would now be submitted as two more
                             empty parameters. --}}
                        <div class="ss-filterbar__field select-container @if (! $ssFiltered) d-none @endif">
                            <label class="ss-label" for="selected_name">{{ __('Condition') }}</label>
                            <div id="select-container"></div>
                        </div>

                        <div class="ss-filterbar__field text-field @if (! $ssFiltered) d-none @endif">
                            <label class="ss-label" for="text_field">{{ __('Value') }}</label>
                            <div id="text-field"></div>
                        </div>

                        <p class="ss-filterbar__hint ss-field__hint" id="ss-customer-filter-hint"></p>

                        <div class="ss-filterbar__actions">
                            {{-- A real submit control: the old `<a>` could not be activated by
                                 Enter or the space bar. The `ti-search` glyph is dropped for the
                                 same reason as on the list. --}}
                            <button type="submit" class="ss-btn ss-btn--primary" id="applyfilter">{{ __('Apply filters') }}</button>

                            {{-- Only when something IS filtering. A LINK, not `type="reset"`:
                                 reset restores the form's initial values, which on a filtered
                                 page are the filters themselves. --}}
                            @if ($ssFiltered)
                                <a href="{{ route('customer.grid') }}" class="ss-btn ss-btn--ghost" id="clearfilter">{{ __('Clear filters') }}</a>
                            @endif
                        </div>
                    </form>
    </div>
</div>
<div class="row g-3 mb-2">
    {{-- P2.5-PKG-G SLICE 1. `@forelse`, because this grid had NO empty arm at all: with no
         customers, or -- far more likely now that P2.5-R-07 made the filter actually work --
         with a filter that matched nothing, the merchant got a blank page. The list beside it
         has said something in that situation since P2.5-WAVE-2.

         THE SAME WORDS AS THE LIST, taken from `dataTableEmptyState('customer-table')`
         (app/Helper/helper.php:3030-3058) rather than written again here, so the two screens
         that show the same customers cannot drift into saying different things about the same
         situation. The filtered case gets its own icon and sentence for the reason that helper
         records: an empty list and a list hidden by a filter look identical and are not the
         same problem. --}}
    @forelse ($customers as $customer)
    @php
        /* P2.5-PKG-G SLICE 2 -- ONE NAME, RESOLVED ONCE.
           `first_name` and `last_name` are BOTH nullable (the customers migration) and
           `Customer::getNameAttribute()` is a bare concatenation, so a customer with neither
           rendered an empty avatar, a link whose accessible name was one space, and an
           aria-label reading "Account status for ". Three places disagreed about the same
           missing name; now they read one variable. The fallback is visible and translated,
           following the precedent in order/grid.blade.php. */
        $ssName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        $ssDisplayName = $ssName !== '' ? $ssName : __('Unnamed customer');
        $ssInitials = \Illuminate\Support\Str::upper(
            \Illuminate\Support\Str::substr($customer->first_name ?? '', 0, 1)
            . \Illuminate\Support\Str::substr($customer->last_name ?? '', 0, 1)
        );
        if ($ssInitials === '') {
            $ssInitials = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($customer->email ?? '?', 0, 1));
        }
    @endphp
    {{-- `col-lg-4` fills the gap the sequence left: it ran 1/2/2/2/3/4 with NOTHING between
         576px and 1199px, so 1024, 1152 and 1180 got two ~470px cards. `d-flex` lets the column
         stretch the card so a row of cards shares one height. --}}
    <div class="col-xxl-3 col-xl-4 col-lg-4 col-sm-6 col-12 d-flex">
        {{-- `ss-scope ss-card`, NOT `card user-card`.

             `.card` sets `--bs-card-border-width: 0px` in BOTH sheets (style.css:4993,
             style-dark.css), so the card had NO edge -- only a 10% black shadow, invisible on a
             dark page -- while `.ss-card__header`'s and `.ss-card__footer`'s own borders still
             painted: two floating hairlines and no outline around them. `.ss-card` supplies the
             surface, the border, the radius and `display:flex; flex-direction:column`, which is
             also what stops the footer floating mid-card.

             `ss-scope` is what turns on the Arabic 1.7 line-height arm; no customer view carried
             it.

             `user-card` is dropped, and that was CHECKED rather than assumed: it has no rule of
             its own anywhere, and every one of its 22 descendant selectors in style.css is a
             profile-page construct -- `.change-profile`, `.cover-img-block`, `.user-about-block`,
             `.certificated-badge`, `.wid-80`, `.hover-data`, `.label`, `.dropdown-toggle`. This
             card contains NONE of them, so the class was vestigial, inherited by copy from the
             vendored profile template. --}}
        <div class="ss-scope ss-card w-100 mb-0">
            {{--
                P2.5-PKG-C. REDESIGNED COMPOSITION, not a recolour.

                WHAT WAS HERE, and why a CSS patch was the wrong fix:

                  * THE CUSTOMER'S NAME WAS A `badge bg-primary` -- identity rendered as a status
                    pill. A badge's semantics are a status or a count; a person's name is the
                    card's primary content and belongs in its title. It also MEASURED 2.09:1 in
                    dark mode (rgb(120,148,198) on rgb(51,93,168)), below even large-text AA, so
                    the one thing a merchant scans for was the least legible thing on the card.
                  * INSIDE that blue badge sat `<a class="bg-success">` -- a second, GREEN fill
                    nested in the first. Two competing backgrounds on one element.
                  * FOUR `.text-dark` spans, i.e. literally "dark text", on a page that has a dark
                    theme. `.text-dark` appears nowhere in the design system; it is raw Bootstrap.
                  * `border border-bottom` together, so the header took a full border AND a second
                    bottom one.
                  * `user-img-wrp` -- a wrapper named for an avatar, containing no avatar.
                  * NO HIERARCHY: name, email, order count and last-active all rendered as `<b>`
                    at one size, so nothing was primary and the count -- the only number on the
                    card -- read as another label.

                WHAT IT IS NOW. Identity first (avatar + name + email), then the one figure that
                carries value, then the actions. The avatar's slot is finally filled, with initials.

                THE FIGURE USES THE DESIGN SYSTEM'S OWN `.ss-stat__value/label/period`, deliberately
                rather than a new class: they already carry tabular figures, `unicode-bidi:
                isolate` (a Latin digit run beside Arabic text reorders without it) and
                `overflow-wrap: break-word` rather than `anywhere`, all of which were measured for
                the dashboard. `.ss-stat` ITSELF is not used -- that is the page-level grid whose
                own comment says "measures get no card", and this one is in a card.

                AND THE PERIOD IS STATED, because the design system requires it: "Every cell states
                its own period. A count with no period is the commonest way a dashboard misleads."
                `Ordercount()` is lifetime, so the period is "All time" -- which is also now true
                in the sense that matters, since F-C-07 scoped it to this store.
            --}}
            <div class="ss-card__header">
                <div class="d-flex align-items-center gap-3">
                    <span class="ss-avatar ss-avatar--lg" aria-hidden="true">{{ $ssInitials }}</span>
                    <div class="ss-grow">
                        <a class="ss-card__title d-block text-truncate text-capitalize"
                           href="{{ route('customer.timeline', $customer->id) }}">{{ $ssDisplayName }}</a>
                        {{-- `<bdi>` ON THE RUN, not `dir="ltr"` on the block. The attribute flips
                             this block's inherited `text-align: start` from right to left, so in
                             Arabic the name right-aligned and the address beneath it LEFT-aligned
                             -- two halves of one identity at opposite card edges. `<bdi>` isolates
                             the Latin run without touching the block's alignment. It is not simply
                             deleted: order/grid.blade.php records the measured bidi hazard that
                             attribute was added for. --}}
                        <span class="ss-card__meta d-block text-truncate"><bdi>{{ $customer->email }}</bdi></span>
                    </div>
                </div>
            </div>
            <div class="ss-card__body">
                <div class="d-flex align-items-start justify-content-between gap-3">
                    <div class="ss-min-w-0">
                        {{-- NOT A LINK, and that is a correction made from the rendered capture.
                             As an `<a>` the figure took the link colour and overrode
                             `.ss-stat__value`'s `--ss-fg`, so the NAME and the NUMBER rendered in
                             the same blue and competed for primacy -- two bold things, which is
                             one too many. It was also a duplicate affordance: the cart action in
                             the footer below already goes to `customer.show`. Plain text restores
                             the strong neutral, and leaves the name as the single interactive
                             element on the card. --}}
                        {{-- `--incard`: `.ss-stat__value` is `--ss-text-2xl` (1.75rem, annotated
                             "stat figures" for the dashboard) while `.ss-card__title` is
                             `--ss-text-lg` (18px), so the order count rendered at 1.56x the
                             customer's NAME -- on a card whose own comment above says identity
                             comes first. The modifier keeps the tabular figures and the bidi
                             isolation and only changes the size. --}}
                        <span class="ss-stat__value ss-stat__value--incard d-block">{{ $customer->Ordercount() }}</span>
                        <span class="ss-stat__label">{{ __('Orders') }}</span>
                        <span class="ss-stat__period">{{ __('All time') }}</span>
                    </div>
                    @if ($customer && $customer->last_active)
                        @php
                            $active = \Carbon\Carbon::parse($customer->last_active);
                        @endphp
                        <div class="text-end ss-min-w-0">
                            <span class="ss-card__meta d-block">{{ __('Last active') }}</span>
                            <span class="ss-card__meta d-block">{{ $active->translatedFormat('d M Y') }}</span>
                        </div>
                    @endif
                </div>
            </div>
            <div class="ss-card__footer ss-card__footer--between">
                <div class="bottom-icons d-flex flex-wrap align-items-center justify-content-between">
                    <div class="customer-grid-btn">
                    {{--
                        P2.5-R-07 (found while opening this view, recorded separately).
                        `$activityLogEntry` IS NOT DEFINED IN THIS VIEW. `customer_grid_view()`
                        passes only `customer_field` and `customers`; the name is a leftover from
                        `CustomerDataTable`, where it is a real local. It has never thrown only
                        because `||` short-circuits and the left operand is true for every row in
                        the loop -- every `$customer` here came from the database and has an id.
                        A falsy id would have made this page fatal on an undefined variable.

                        The dead operand is removed rather than the variable supplied: nothing on
                        the card reads an activity-log entry, and `CustomerDataTable` collapsed
                        its own two byte-identical `if ($activityLogEntry)` branches for the same
                        reason in F98.
                    --}}
                    {{-- THE SAME CLASSES AND THE SAME KEYS action.blade.php ALREADY USES, so the
                         grid card and the list row offer one vocabulary rather than two.

                         `.btn` hardcodes `--bs-btn-color: #293240` BYTE-IDENTICALLY in both
                         sheets (style.css:3444, style-dark.css:3449), and the footer's ground is
                         `--ss-surface-sunken` -- so these glyphs measured 1.20:1 in dark. They
                         also drew a ~28x30px target; `.ss-btn--sm` is min-height 32px and
                         `.ss-btn--icon.ss-btn--sm` min-width 32px.

                         `btn-icon` is dropped: it is defined in no stylesheet here. --}}
                    @if ($customer && $customer->id)
                        <a href="{{ route('customer.timeline', $customer->id) }}"
                            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
                            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Show') }}"
                            aria-label="{{ __('Activity timeline for :name', ['name' => $ssDisplayName]) }}">
                            <i class="ti ti-eye" aria-hidden="true"></i>
                        </a>
                    @endif
                    @permission('Show Customer')
                        <a href="{{ route('customer.show', $customer->id) }}"
                            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--secondary"
                            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Cart') }}"
                            aria-label="{{ __('Cart of :name', ['name' => $ssDisplayName]) }}">
                            <i class="ti ti-shopping-cart" aria-hidden="true"></i>
                        </a>
                    @endpermission
                    @if (module_is_active('RewardClubPoint'))
                        @include('reward-club-point::admin.clubPointHistoryBtn', ['customerId' => $customer->id])
                    @endif
                    </div>
                    <div class="customer-grid-swich-btn">
                    @permission('Status Customer')
                        @if ($customer->regiester_date != null)
                            <div class="form-check form-switch">
                                <input class="form-check-input page-checkbox " id="{{ $customer->id }}"
                                    aria-label="{{ __('Account status for :name', ['name' => $ssDisplayName]) }}"
                                    type="checkbox" name="page_active" data-onstyle="success"
                                    data-offstyle="danger" data-toggle="toggle" data-on="off"
                                    data-off="on"
                                    @if ($customer->status == 1) checked="checked" @endif />
                            </div>
                        @endif
                    @endpermission
                    </div>
                </div>
            </div>
        </div>
    </div>
    @empty
        <div class="col-12">
            @if (request()->filled('field_name'))
                <x-ss.empty
                    icon="zoom-cancel"
                    :title="__('Nothing matches your search or filters.')"
                    :body="__('Clear them to see everything again.')">
                    <x-slot:action>
                        <a class="ss-btn ss-btn--ghost" href="{{ route('customer.grid') }}">{{ __('Clear filters') }}</a>
                    </x-slot:action>
                </x-ss.empty>
            @else
                <x-ss.empty
                    icon="users"
                    :title="__('No customers yet')"
                    :body="__('Customers appear here once someone registers or places an order.')" />
            @endif
        </div>
    @endforelse
</div>
{{-- THE TOTAL, PRINTED UNCONDITIONALLY. `layouts/global-pagination.blade.php:1` gates its
     ENTIRE body -- the "Showing x to y of z" line included -- on `hasPages()`, which is false
     for a single page. So the narrowed result of a filter, which is exactly when a merchant
     most wants to know how many matched, printed no count at all. --}}
@if ($customers->total() > 0)
    <p class="ss-scope ss-card__meta mb-2">{{ __('Showing :count customers', ['count' => $customers->total()]) }}</p>
@endif
{!! $customers->links('layouts.global-pagination') !!}
@endsection

@push('custom-script')
    @php
        // The KEYS are the English words `CustomerController::CustomFilter()` returns
        // verbatim from `Customer::\$fields_status*` and matches on; only the text a
        // merchant reads is translated. Eight of these were measured absent from
        // ar.json entirely and were added with this slice.
        $ssFilterLabels = collect(['Includes', 'Excludes', 'Less Than', 'More Than', 'Equal', 'Before', 'After'])
            ->mapWithKeys(fn ($w) => [$w => __($w)])
            ->all();
    @endphp
    <script type="text/javascript">
        $(document).ready(function() {
            $(document).on('change', '.page-checkbox', function() {
                var status = $(this).prop('checked') == true ? 1 : 0;
                var customer_id = $(this).attr('id');

                // P2.5-PKG-G SLICE 3 -- the SECOND caller, changed with the first for the same
                // F14 reason the POST verb was: a defect fixed where it was noticed and not
                // where it lives comes back on the screen nobody was looking at.
                var $switch = $(this);
                $switch.prop('disabled', true).attr('aria-busy', 'true');

                $.ajax({
                    // P2-05-1. POST, not GET -- the SECOND caller of this route.
                    // The index view was the one somebody was looking at; this grid view calls
                    // the same route and would have gone on sending a GET. That is the F14
                    // lesson in this phase's ledger, which is why the callers were censused by
                    // route NAME across every view rather than fixed where they were noticed.
                    type: "POST",
                    dataType: "json",
                    url: "{{ route('update.customer.status') }}",
                    data: {
                        'status': status,
                        'customer_id': customer_id
                    },
                    success: function(data) {
                        if (data.success) {
                            show_toastr("{{ __('Success') }}", data.success, 'success');
                        } else {
                            $switch.prop('checked', !status);
                            show_toastr("{{ __('Error') }}", "{{ __('Something went wrong.') }}", 'error');
                        }
                    },
                    error: function(xhr) {
                        // The control goes back to what the database says. See the list view
                        // for the full reasoning.
                        $switch.prop('checked', !status);
                        show_toastr(
                            "{{ __('Error') }}",
                            (xhr.responseJSON && xhr.responseJSON.error)
                                ? xhr.responseJSON.error
                                : "{{ __('Something went wrong.') }}",
                            'error'
                        );
                    },
                    complete: function() {
                        $switch.prop('disabled', false).removeAttr('aria-busy');
                    },
                });
            })
        })
    </script>
    <script src="{{ asset('js/jquery.table2excel.js') }}"></script>
    <script>
        const d = new Date();
        let seconds = d.getSeconds();
        $(document).on('click', '.csv', function() {
            $('.ignore').remove();
            $("#customer-table").table2excel({
                filename: "Customer_" + seconds
            });
            window.location.reload();
        })
    </script>

    <script>
        $(document).ready(function() {

            // Emitted from Blade so the words come from the translator. KEYS are the English
            // strings the handler compares against and must not change.
            // Built in PHP, then emitted as one JSON literal. Blade's own json directive
            // parses to the FIRST closing paren, so a multi-line argument does not
            // compile. Caught by compiling the view rather than by `php -l`, which
            // does not expand Blade directives at all.
            var ssFilterLabels = {!! json_encode($ssFilterLabels, JSON_UNESCAPED_UNICODE) !!};


            $('#customer_field').on('change', function() {
                var selectedValue = $(this).val();
                var data = {
                    customer_field: selectedValue,
                }

                // SCOPED BUSY STATE on the control that is waiting, not a full-page curtain.
                // `.ss-btn[aria-busy="true"]` already carries the spinner (store-syria-ds.css).
                $('#applyfilter').prop('disabled', true).attr('aria-busy', 'true');

                $.ajax({
                    url: '{{ route('customer.filter') }}',
                    method: 'GET',
                    data: data,
                    context: this,
                    success: function(response) {
                        // The `$('#loader').fadeOut()` that stood here faded out an element
                        // that is never shown: `#loader` ships `style="display: none;"`
                        // (layouts/app.blade.php:749) and nothing in this codebase has an
                        // ajaxStart, a .show() or a .fadeIn for it.
                        if (response.condition && response.condition.length > 0) {
                            $('.select-container').removeClass('d-none');
                            $('#select-container').empty();
                            $('.text-field').removeClass('d-none');
                            $('#text-field').empty();
                            // ss-select / ss-input and real ids, so the labels above bind and
                            // the controls are visible in dark mode. Same change as the list.
                            var selectBox = $('<select name="selected_name" id="selected_name" class="ss-select">');

                            // Translated text, English value -- the handler matches on the key.
                            $.each(response.condition, function(index, option) {
                                var optionElement = $('<option>');
                                optionElement.val(option);
                                optionElement.text(ssFilterLabels[option] || option);
                                selectBox.append(optionElement);
                            });

                            $('#select-container').append(selectBox);

                            $('#ss-customer-filter-hint').text(response.message || '');

                            var inputType = response.field_type;

                            if (inputType === 'text' || inputType === 'number' || inputType === 'email' || inputType === 'date') {
                                var inputElement = $('<input name="text_field" id="text_field" class="ss-input">');

                                inputElement.attr('type', inputType);
                                if (response.message) {
                                    inputElement.attr('aria-describedby', 'ss-customer-filter-hint');
                                }
                                $('#text-field').append(inputElement);

                            } else {
                                $('.text-field').addClass('d-none');
                            }
                        } else {
                            $('.select-container').addClass('d-none');
                            $('#select-container').empty();
                            $('.text-field').addClass('d-none');
                            $('#text-field').empty();
                            $('#ss-customer-filter-hint').text('');
                        }
                    },
                    // THE FAILURE THIS BAR COULD NOT REPORT. If this metadata request fails,
                    // the operator and value controls stay hidden for ever with nothing saying
                    // why -- the merchant picks a field and the bar simply never finishes. The
                    // message goes in the bar's own hint rather than a toast, because the
                    // failure is scoped to the bar and a page-level toast would outlive it.
                    error: function() {
                        $('#ss-customer-filter-hint').text(
                            "{{ __('Could not load the options for that field. Try again.') }}"
                        );
                    },
                    complete: function() {
                        $('#applyfilter').prop('disabled', false).removeAttr('aria-busy');
                    },
                });
            });

            /*
             * P2.5-R-07. The submit handler, the click handler and `applyFilter()` are gone with
             * the AJAX they called. The form above submits itself and
             * `CustomerController::customer_grid_view()` does the filtering, through the same
             * `CustomerListFilter::apply()` the list uses.
             *
             * WHAT WAS REMOVED, so it is not re-added by someone reading it as the mechanism:
             * `applyFilter()` GET-ed the filter-data endpoint and wrote the response into a
             * container id this view does not contain -- and that endpoint answers with the
             * LIST's `<tr>` fragment, which is not a grid in any case. Leaving the block in place
             * would mean a click both submitted the form and fired an AJAX whose answer is
             * discarded. The dead id is named in the Blade comment on the form above, which is
             * compiled away rather than shipped, so the rendered page can be asserted free of it.
             *
             * What remains is the other half of a GET filter: showing the merchant the filter
             * that is in force. The operator and value controls are injected by the `change`
             * handler above, so on a filtered page they do not exist yet. This replays that
             * injection once and then puts the submitted values back, so a merchant who applied a
             * filter and got a narrowed grid can SEE which filter narrowed it, and can edit it
             * rather than rebuilding it from the first select.
             */
            @if (request()->filled('field_name'))
                (function restoreSubmittedFilter() {
                    var operator = @json(request()->query('selected_name'));
                    var value = @json(request()->query('text_field'));

                    $(document).ajaxComplete(function onInjected(event, xhr, settings) {
                        if (! settings.url || settings.url.indexOf(@json(route('customer.filter'))) !== 0) {
                            return;
                        }
                        $(document).off('ajaxComplete', onInjected);
                        if (operator) {
                            $('#select-container select').val(operator);
                        }
                        if (value) {
                            $('#text-field input').val(value);
                        }
                    });

                    $('#customer_field').trigger('change');
                })();
            @endif
        });
    </script>
@endpush
