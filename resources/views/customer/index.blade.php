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
            <div class="mt-2 " id="multiCollapseExample1">
                <div class="card">
                    <div class="card-body">
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
                              role="search" aria-label="{{ __('Filter customers') }}">
                        <div class="row align-items-center m-0 gap-1 justify-content-end">
                            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                <div class="btn-box">
                                    <label class="visually-hidden" for="customer_field">{{ __('Select Customer Fields') }}</label>
                                    {{ Form::select('field_name', $customer_field, request()->query('field_name'), ['class' => 'form-control', 'id' => 'customer_field', 'placeholder' => __('Select Customer Fields')]) }}
                                </div>
                            </div>
                            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 select-container @if (! request()->filled('field_name')) d-none @endif">
                                <div class="btn-box" id="select-container"></div>
                            </div>
                            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 text-field @if (! request()->filled('field_name')) d-none @endif">
                                <div class="btn-box" id="text-field"></div>
                            </div>
                            <div class="m-0 p-0 col-auto">
                                <div class="row">
                                    <div class="m-0 p-0 col-auto">
                                        {{-- A real submit control. The old <a> could not be
                                             activated by Enter or the space bar either, so this
                                             also gives the filter a keyboard path. --}}
                                        <button type="submit" class="btn btn-sm btn-primary" data-bs-toggle="tooltip"
                                            title="{{ __('Apply') }}" id="applyfilter"
                                            data-original-title="{{ __('apply') }}">
                                            <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                            <span class="visually-hidden">{{ __('Apply') }}</span>
                                        </button>
                                        {{-- A LINK to the unfiltered address, not type="reset":
                                             reset restores the form's INITIAL values, which on a
                                             filtered page are the filters themselves. --}}
                                        <a href="{{ route('customer.index') }}" class="btn btn-sm btn-danger " data-bs-toggle="tooltip"
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

        <div class="col-md-12">
            <x-datatable :dataTable="$dataTable" />
        </div>

    </div>
@endsection

@push('custom-script')
    <script type="text/javascript">
        $(document).ready(function() {

            $(document).on('change', '.page-checkbox', function() {
                var status = $(this).prop('checked') == true ? 1 : 0;
                var customer_id = $(this).attr('id');

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