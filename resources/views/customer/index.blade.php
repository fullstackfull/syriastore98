@extends('layouts.app')

@section('page-title')
    {{ __('Customer') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Customer') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        {{-- تم إزالة @permission مؤقتاً للتجربة --}}
        <a href="{{ route('customer.create') }}" 
           class="btn btn-sm btn-primary btn-icon" 
           data-bs-toggle="tooltip" 
           data-bs-placement="top" 
           title="{{ __('Add Customer') }}" aria-label="{{ __('Add Customer') }}">
            <i class="ti ti-plus"></i>
        </a>

        <a href="#" class="btn btn-sm btn-primary btn-icon csv" 
           title="{{ __('Export') }}" 
           data-bs-toggle="tooltip"
           data-bs-placement="top" aria-label="{{ __('Export') }}">
            <i class="ti ti-file-export"></i>
        </a>

        <a href="{{ route('customer.grid') }}" 
           data-bs-toggle="tooltip" 
           data-bs-original-title="{{ __('Grid View') }}" 
           class="btn btn-sm btn-primary btn-icon" aria-label="{{ __('Grid View') }}">
            <i class="ti ti-layout-grid"></i>
        </a>
    </div>
@endsection
@section('content')
    <div class="row">

        <div class="col-md-12">
            {{-- P2.5-PKG-G SLICE 1. The `.card`/`.card-body` wrapper and the dead
                 `id="multiCollapseExample1"` are gone. That id is a leftover from the Bootstrap
                 collapse docs -- measured: it appears at index:41, grid:28 and order/view:84 and
                 NOTHING in resources/views/ or public/js/ toggles any of them. `.ss-filterbar`
                 (store-syria-ds.css:4172-4182) supplies the surface, border, radius, padding and
                 margin-block-end the card was there for, so the card was redundant as well as
                 untokenised. --}}
                        {{--
                            P2.5-R-02 -- A PLAIN GET FORM, which is all this filter ever needed.

                            `CustomerDataTable::query()` already implements every one of these
                            five filters server-side, reading `field_name`, `selected_name` and
                            `text_field` off the request -- and the three controls below were
                            already named exactly that. The only missing piece was a <form> to
                            submit them; there is even a dead `$('#frm_submit').on('submit')`
                            handler further down this file waiting for one.

                            What was here instead: an <a id="applyfilter"> with no handler (the
                            script bound `#apply-button`), and behind that an AJAX block writing
                            its answer into `#service-filter-data`, an element that exists only
                            inside the vendored RewardClubPoint module. Measured in a browser:
                            zero requests on click, and nothing on the page changed.

                            This is the same pattern as the product list
                            (product/partials/filters.blade.php) and it works for the same
                            measured reason: yajra's `minifiedAjax()` points the table's ajax at
                            the CURRENT url, so the filter rides the initial render AND every
                            later draw, and the filtered list is a bookmarkable address.
                        --}}
                        <form method="GET" action="{{ route('customer.index') }}" id="frm_submit"
                              class="ss-scope ss-filterbar"
                              role="search" aria-label="{{ __('Filter customers') }}">

                            {{-- WHAT IS NARROWING THE LIST, SAID OUT LOUD. The component's own
                                 docblock names the failure it exists to prevent: a merchant
                                 returning to four rows out of nine hundred and concluding their
                                 customers are gone. On this screen the only evidence of a filter
                                 was the three controls themselves -- and they are repopulated
                                 ASYNCHRONOUSLY (the restore script waits on `ajaxComplete`), so a
                                 filtered page rendered with two EMPTY controls above a narrowed
                                 list.

                                 BUILT INLINE, not through `TableFilterMemory::describe()`: that
                                 service only recognises `ss_`-prefixed control names, and this
                                 screen's three keys are `field_name`, `selected_name` and
                                 `text_field`, which `CustomerDataTable::query()` reads and the
                                 ledger requires be kept byte-identical. --}}
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

                            {{-- Below 576px the fields, hint and actions collapse behind this and
                                 the summary above stays visible. CSS-only; see the component. --}}
                            <x-ss.filter-disclosure />

                            <div class="ss-filterbar__field">
                                <label class="ss-label" for="customer_field">{{ __('Field') }}</label>
                                {{-- BY HAND, NOT `Form::select`, so the OPTION TEXT can be
                                     translated while the submitted VALUE stays the English key.
                                     `Customer::customer_field()` returns a literal English map and
                                     `CustomerListFilter::apply()` matches on those exact strings
                                     (its LAST_ACTIVE_FIELDS / NUMERIC_FIELDS constants), so
                                     translating the model would break the filter. --}}
                                <select class="ss-select" id="customer_field" name="field_name">
                                    <option value="">{{ __('Select Customer Fields') }}</option>
                                    @foreach ($customer_field as $ssFieldKey => $ssFieldLabel)
                                        <option value="{{ $ssFieldKey }}" @selected($ssFieldName === (string) $ssFieldKey)>{{ __($ssFieldLabel) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="ss-filterbar__field select-container @if (! $ssFiltered) d-none @endif">
                                <label class="ss-label" for="selected_name">{{ __('Condition') }}</label>
                                <div id="select-container"></div>
                            </div>

                            <div class="ss-filterbar__field text-field @if (! $ssFiltered) d-none @endif">
                                <label class="ss-label" for="text_field">{{ __('Value') }}</label>
                                <div id="text-field"></div>
                            </div>
                            {{-- THE HINT THE HANDLER ALREADY SENDS AND THE PAGE USED TO THROW
                                 AWAY. `CustomFilter()` returns a `message` describing what the
                                 value control expects; nothing rendered it. It takes its own row
                                 so a long sentence does not stretch the field it belongs to. --}}
                            <p class="ss-filterbar__hint ss-field__hint" id="ss-customer-filter-hint"></p>

                            <div class="ss-filterbar__actions">
                                {{-- A real submit control. The old <a> could not be activated by
                                     Enter or the space bar either, so this also gives the filter a
                                     keyboard path. The `ti-search` glyph is dropped: this page
                                     also renders DataTables' own search box, and two magnifiers
                                     doing different things is worse than none. --}}
                                <button type="submit" class="ss-btn ss-btn--primary" id="applyfilter">{{ __('Apply filters') }}</button>

                                {{-- ONLY WHEN SOMETHING IS ON. The product bar records this as a
                                     measured defect: a Clear button that is always present is the
                                     honest signal that something is filtering, turned into noise.
                                     A LINK to the unfiltered address, not `type="reset"`, because
                                     reset restores the form's INITIAL values -- which on a
                                     filtered page are the filters themselves. --}}
                                @if ($ssFiltered)
                                    <a href="{{ route('customer.index') }}" class="ss-btn ss-btn--ghost" id="clearfilter">{{ __('Clear filters') }}</a>
                                @endif
                            </div>
                        </form>
        </div>

        <div class="col-md-12">
            <x-datatable :dataTable="$dataTable" />
        </div>

    </div>
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

                // P2.5-PKG-G SLICE 3. THE SERVER DECIDES, THE SWITCH REFLECTS.
                //
                // The browser's native click has ALREADY flipped this checkbox by the time the
                // request leaves. `customerStatus()` answers real refusals -- 403 twice and 404
                // twice, each with a translated `error` body -- and jQuery routes every one of
                // them to an `error:` callback that did not exist. So the body was never read,
                // no toast fired, and the switch sat where the merchant dragged it while
                // `customers.status` was unchanged: the control showed the OPPOSITE of the
                // database, silently.
                //
                // This is the rule the project already wrote down for itself at
                // store-syria-ds.js:1937-2012 -- "THE SERVER DECIDES, THE BUTTON REFLECTS ... a
                // refusal leaves the control showing the truth instead of a state the server
                // rejected". This switch was that rule's counter-example.
                var $switch = $(this);

                // SCOPED BUSY STATE, not the page loader. `#loader` ships
                // `style="display: none;"` (layouts/app.blade.php:749) and NOTHING in this
                // codebase ever shows it -- no ajaxStart, no .show(), no .fadeIn -- so the
                // `$('#loader').fadeOut()` these handlers called was fading out an element that
                // was never visible. A full-page curtain for a one-row toggle is the wrong
                // weight anyway.
                $switch.prop('disabled', true).attr('aria-busy', 'true');

                $.ajax({
                    // P2-05-1. POST, not GET: this route writes `customers.status`, and a
                    // state-changing GET is reachable from a third-party <img src> and by link
                    // prefetching. The handler's own permission and ownership checks were
                    // already in place (Phase 01); the verb was not. The CSRF token comes from
                    // public/js/custom.js's global $.ajaxSetup, as it does for every other POST
                    // in this codebase.
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
                            // A falsy `success` is still a write that did not happen, so the
                            // switch goes back here too -- it used to toast and stay moved.
                            $switch.prop('checked', !status);
                            show_toastr("{{ __('Error') }}", "{{ __('Something went wrong.') }}", 'error');
                        }
                    },
                    error: function(xhr) {
                        // BACK TO WHAT THE DATABASE SAYS.
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

            // EMITTED FROM BLADE so the words come from the translator rather than from a
            // hand-kept JS copy. The KEYS are the English strings the handler compares against
            // and must not change; only the text a merchant reads is translated.
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
                            // `ss-select`/`ss-input`, and an `id` so the labels rendered above
                            // actually bind to something. These two controls had NO id, NO label
                            // and NO aria-label at all -- a screen reader announced two unnamed
                            // controls, and `.form-control` in dark mode measures a 1.37:1
                            // boundary against the panel behind it.
                            var selectBox = $('<select name="selected_name" id="selected_name" class="ss-select">');

                            // THE OPERATOR WORDS ARE TRANSLATED HERE, and the VALUE stays the
                            // English key the handler matches on. `response.condition` is
                            // `Customer::$fields_status*` verbatim; eight of those words were
                            // measured absent from ar.json entirely, so they are now added there
                            // and mapped here. Anything unmapped falls through unchanged rather
                            // than rendering blank.
                            $.each(response.condition, function(index, option) {
                                var optionElement = $('<option>');
                                optionElement.val(option);
                                optionElement.text(ssFilterLabels[option] || option);
                                selectBox.append(optionElement);
                            });

                            $('#select-container').append(selectBox);

                            // THE HINT THE HANDLER ALREADY SENDS. It described what the value
                            // control expects and the page threw it away.
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
             * P2.5-R-02. The submit and click handlers that used to live here are gone with the
             * AJAX they called: the form above submits itself, and CustomerDataTable::query()
             * does the filtering. What remains is the other half of a GET filter -- showing the
             * merchant the filter that is in force.
             *
             * The operator and value controls are injected by the `change` handler above, so on
             * a filtered page they do not exist yet. This replays that injection once and then
             * puts the submitted values back, so a merchant who applied a filter and got a
             * narrowed list can SEE which filter narrowed it, and can edit it rather than
             * rebuilding it from the first select.
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