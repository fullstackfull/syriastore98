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
        <div class="mt-2 " id="multiCollapseExample1">
            <div class="card">
                <div class="card-body">
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
                          role="search" aria-label="{{ __('Filter customers') }}">
                    <div class="row align-items-center m-0 gap-1 justify-content-end">
                        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                            <div class="btn-box">
                                <label class="visually-hidden" for="customer_field">{{ __('Select Customer Fields') }}</label>
                                {{ Form::select('field_name', $customer_field, request()->query('field_name'), ['class' => 'form-control', 'id' => 'customer_field', 'placeholder' => __('Select Customer Fields')]) }}
                            </div>
                        </div>
                        {{-- The two hidden `select_value` / `text_value` inputs that used to sit
                             in these boxes are gone. They were written to by the change handler
                             and read by nothing: the AJAX built its payload from the visible
                             controls. Inside a real form they would now be submitted as two more
                             empty parameters. --}}
                        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 select-container @if (! request()->filled('field_name')) d-none @endif">
                            <div class="btn-box" id="select-container"></div>
                        </div>
                        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 text-field @if (! request()->filled('field_name')) d-none @endif">
                            <div class="btn-box" id="text-field"></div>
                        </div>
                        <div class="m-0 p-0 col-auto">
                            <div class="row">
                                <div class="m-0 p-0 col-auto">
                                    {{-- A real submit control. The old `<a>` could not be
                                         activated by Enter or the space bar, so this also gives
                                         the filter a keyboard path. --}}
                                    <button type="submit" class="btn btn-sm btn-primary" data-bs-toggle="tooltip"
                                        title="{{ __('Apply') }}" id="applyfilter"
                                        data-original-title="{{ __('apply') }}">
                                        <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                        <span class="visually-hidden">{{ __('Apply') }}</span>
                                    </button>
                                    {{-- A LINK to the unfiltered address, not type="reset":
                                         reset restores the form's INITIAL values, which on a
                                         filtered page are the filters themselves. The old
                                         `href="#!"` went nowhere at all. --}}
                                    <a href="{{ route('customer.grid') }}" class="btn btn-sm btn-danger " data-bs-toggle="tooltip"
                                        title="{{ __('Reset') }}" id="clearfilter"
                                        data-original-title="{{ __('Reset') }}">
                                        <span class="btn-inner--icon"><i
                                                class="ti ti-trash-off text-white-off "></i></span>
                                        <span class="visually-hidden">{{ __('Reset') }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="row row-gap-2 mb-2">
    @foreach ($customers as $customer)
    <div class="col-xxl-3 col-xl-4 col-sm-6 col-12">
        <div class="card user-card">
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
                    <span class="ss-avatar ss-avatar--lg" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($customer->first_name ?? '', 0, 1) . \Illuminate\Support\Str::substr($customer->last_name ?? '', 0, 1)) }}</span>
                    <div class="flex-grow-1 min-w-0">
                        <a class="ss-card__title d-block text-truncate text-capitalize"
                           href="{{ route('customer.timeline', $customer->id) }}">{{ $customer->first_name }} {{ $customer->last_name }}</a>
                        <span class="ss-card__meta d-block text-truncate" dir="ltr">{{ $customer->email }}</span>
                    </div>
                </div>
            </div>
            <div class="ss-card__body">
                <div class="d-flex align-items-start justify-content-between gap-3">
                    <div class="min-w-0">
                        {{-- NOT A LINK, and that is a correction made from the rendered capture.
                             As an `<a>` the figure took the link colour and overrode
                             `.ss-stat__value`'s `--ss-fg`, so the NAME and the NUMBER rendered in
                             the same blue and competed for primacy -- two bold things, which is
                             one too many. It was also a duplicate affordance: the cart action in
                             the footer below already goes to `customer.show`. Plain text restores
                             the strong neutral, and leaves the name as the single interactive
                             element on the card. --}}
                        <span class="ss-stat__value d-block">{{ $customer->Ordercount() }}</span>
                        <span class="ss-stat__label">{{ __('Orders') }}</span>
                        <span class="ss-stat__period">{{ __('All time') }}</span>
                    </div>
                    @if ($customer && $customer->last_active)
                        @php
                            $active = \Carbon\Carbon::parse($customer->last_active);
                        @endphp
                        <div class="text-end min-w-0">
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
                    @if ($customer && $customer->id)
                        <a href="{{ route('customer.timeline', $customer->id) }}"
                            class="btn btn-sm btn-icon border me-2"
                            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Show') }}">
                            <i class="ti ti-eye"></i>
                        </a>
                    @endif
                    @permission('Show Customer')
                        <a href="{{ route('customer.show', $customer->id) }}"
                            class="btn btn-sm border me-2" data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Cart') }}">
                            <i class="ti ti-shopping-cart"></i>
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
                                    aria-label="{{ __('Account status for :name', ['name' => $customer->name]) }}"
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
    @endforeach
</div>
{!! $customers->links('layouts.global-pagination') !!}
@endsection

@push('custom-script')
    <script type="text/javascript">
        $(document).ready(function() {
            $(document).on('change', '.page-checkbox', function() {
                var status = $(this).prop('checked') == true ? 1 : 0;
                var customer_id = $(this).attr('id');

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
                        $('#loader').fadeOut();
                        if (data.success) {
                            show_toastr('Success', data.success, 'success');
                        } else {
                            show_toastr('Error', "{{ __('Something went wrong.') }}", 'error');
                        }
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

            $('#customer_field').on('change', function() {
                var selectedValue = $(this).val();
                var data = {
                    customer_field: selectedValue,
                }
                $.ajax({
                    url: '{{ route('customer.filter') }}',
                    method: 'GET',
                    data: data,
                    context: this,
                    success: function(response) {
                        $('#loader').fadeOut();                       

                        if (response.condition && response.condition.length > 0) {
                            $('.select-container').removeClass('d-none');
                            $('#select-container').empty();
                            $('.text-field').removeClass('d-none');
                            $('#text-field').empty();
                            var selectBox = $('<select name="selected_name" class="form-control">');
                            var TextField = $('<input>');

                            $.each(response.condition, function(index, option) {
                                var optionElement = $('<option>');
                                optionElement.val(option);
                                optionElement.text(option);
                                selectBox.append(optionElement);
                            });

                            $('#select-container').append(selectBox);

                            var inputType = response.field_type;

                            if (inputType === 'text' || inputType === 'number' || inputType === 'email' || inputType === 'date') {
                                var inputElement = $('<input name="text_field" class="form-control">');

                                inputElement.attr('type', inputType);
                                $('#text-field').append(inputElement);

                            } else {
                                $('.text-field').addClass('d-none');
                            }
                        } else {
                            $('.select-container').addClass('d-none');
                            $('#select-container').empty();
                            $('.text-field').addClass('d-none');
                            $('#text-field').empty();
                        }
                    }
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
