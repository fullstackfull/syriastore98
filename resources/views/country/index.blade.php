@extends('layouts.app')

@section('page-title', __('Country Settings'))

@section('action-button')

@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Country Settings') }}</li>
@endsection
@push('css')
    @include('layouts.includes.datatable-css')
    @if (app()->getLocale() == 'ar' || app()->getLocale() == 'he')
    <style>
        div.dataTables_wrapper div.dataTables_paginate ul.pagination {
            gap: 0;
            flex-wrap: wrap;

        }

        div.dataTables_wrapper div.dataTables_paginate ul.pagination li {
            width: auto;
            height: auto;
        }
        div.table-responsive>div.dataTables_wrapper>div.row>div[class^="col-"]:first-child{
            padding-right: 0;
        }
        div.table-responsive>div.dataTables_wrapper>div.row>div[class^="col-"]:last-child{
            padding-left: 0;
        }

        @media only screen and (max-width: 767px) {
            div.dataTables_wrapper div.dataTables_paginate ul.pagination {
                margin: 10px 0 0 0;
                justify-content: center;
            }

            .ecom-data-table div.dataTables_wrapper div.dataTables_length {
                text-align: right;
                margin-bottom: 15px;
            }

            .ecom-data-table div.dt-buttons{
                width: 100%;
            }
            div.dataTables_wrapper div.dataTables_filter input{
                margin-right:0;
            }

            .ecom-data-table .data-tab-btn {
                justify-content: flex-start !important;
                padding-right: 0 !important;
                margin-bottom: 10px;

            }
        }

        @media only screen and (max-width: 575px) {
            .card .card-header .state-set {
                margin-bottom: 10px;
            }
        }
    </style>
    @else
    <style>
        div.dataTables_wrapper div.dataTables_paginate ul.pagination {
            gap: 0;
            flex-wrap: wrap;

        }

        div.dataTables_wrapper div.dataTables_paginate ul.pagination li {
            width: auto;
            height: auto;
        }

        @media only screen and (max-width: 767px) {
            div.dataTables_wrapper div.dataTables_paginate ul.pagination {
                margin: 10px 0 0 0;
                justify-content: center;
            }

            .ecom-data-table div.dataTables_wrapper div.dataTables_length {
                text-align: left;
                margin-bottom: 15px;
            }

            .ecom-data-table div.dt-buttons {
                width: 100%;
            }
            div.dataTables_wrapper div.dataTables_filter input{
                margin-left:0;
            }

            .ecom-data-table .data-tab-btn {
                justify-content: flex-start !important;
                padding-left: 0 !important;
                margin-bottom: 10px;

            }
        }

        @media only screen and (max-width: 575px) {
            .card .card-header .state-set {
                margin-bottom: 10px;
            }
        }
    </style>

    @endif
@endpush
@section('content')

    <div class="row">
        <div class="col-xl-3">
            <div class="card sticky-top " style="top:60px">
                <div class="list-group list-group-flush addon-set-tab" id="useradd-sidenav">
                    <ul class="nav nav-pills flex-column w-100 gap-1" id="pills-tab" role="tablist">
                        <li class="nav-item " role="presentation">
                            <a href="#Country_Setting"
                                class="nav-link @if (isset($country_active_tab) && $country_active_tab == 'pills-country-tab') active @endif list-group-item list-group-item-action border-0 rounded-1 text-center f-w-600"
                                id="pills-country-tab" data-bs-toggle="pill" data-bs-target="#pills-country" type="button"
                                role="tab" aria-controls="pills-country" aria-selected="true">
                                {{ __('Country Settings') }}
                            </a>

                        </li>
                        <li class="nav-item " role="presentation">
                            <a href="#State_Setting"
                                class="nav-link @if (isset($country_active_tab) && $country_active_tab == 'pills-state-tab') active @endif list-group-item list-group-item-action border-0 rounded-1 text-center f-w-600"
                                id="pills-state-tab" data-bs-toggle="pill" data-bs-target="#pills-state" type="button"
                                role="tab" aria-controls="pills-state" aria-selected="true">
                                {{ __('State Settings') }}
                            </a>

                        </li>
                        <li class="nav-item " role="presentation">
                            <a href="#City_Setting"
                                class="nav-link @if (isset($country_active_tab) && $country_active_tab == 'pills-city-tab') active @endif list-group-item list-group-item-action border-0 rounded-1 text-center f-w-600"
                                id="pills-city-tab" data-bs-toggle="pill" data-bs-target="#pills-city" type="button"
                                role="tab" aria-controls="pills-city" aria-selected="true">
                                {{ __('City Settings') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-xl-9">
            <div class="tab-content" id="pills-tabContent">
                <div class="tab-pane fade @if (isset($country_active_tab) && $country_active_tab == 'pills-country-tab') show active @endif" id="pills-country"
                    role="tabpanel" aria-labelledby="pills-country-tab">
                    <div id="Country_Setting">
                        <div class="col-md-12">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <div class="row">
                                        <div class="col-6">
                                            <h5 class="mt-2">{{ __('Country Settings') }}</h5>
                                        </div>
                                        <div class="col-6 text-end ">
                                            <div class="">
                                                @if (module_is_active('ImportExport'))
                                                    @include('import-export::import.country_import', ['module' => 'country'])
                                                    @include('import-export::export.country_export', ['module' => 'country'])
                                                @endif
                                                <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true"
                                                    data-size="md" data-title="{{ __('Create Country') }}"
                                                    data-url="{{ route('countries.create') }}" data-bs-toggle="tooltip"
                                                    title="{{ __('Add Country') }}"
                                                    data-bs-original-title="{{ __('Create Country') }}">
                                                    <i class="ti ti-plus"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3 g-0">
                                    <div class="card-body table-border-style">
                                        <div class="table-responsive ecom-data-table">
                                            <table class="table" id="country-table" style="width:100%">
                                                <thead>
                                                    <tr>
                                                        @if (module_is_active('BulkDelete'))
                                                            @include('bulk-delete::pages.checkbox')
                                                        @endif
                                                        <th>Name</th>
                                                        <th class="text-end">Action</th>
                                                    </tr>
                                                </thead>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade @if (isset($country_active_tab) && $country_active_tab == 'pills-state-tab') show active @endif" id="pills-state"
                    role="tabpanel" aria-labelledby="pills-state-tab">
                    <div id="State_Setting">
                        <div class="card mb-3">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col-sm-4 col-12">
                                        <h5 class="state-set">{{ __('State Settings') }}</h5>
                                    </div>
                                    <div class="col-sm-8 col-12 justify-content-sm-end">
                                        <div class="row">
                                            <form method="GET" action="{{ route('countries.index') }}"
                                                accept-charset="UTF-8" id="customer_submit">
                                                @csrf
                                                <input type="hidden" name="country_active_tab" value="pills-city-tab">
                                                <div class=" d-flex align-items-center float-right justify-content-sm-between gap-2">
                                                    <div>
                                                        <div class="btn-box">
                                                            {!! Form::select('country_id', $get_country, null, [
                                                                'class' => 'form-control country select2',
                                                                'name' => 'country_id',
                                                                'id' => 'country_filter',
                                                            ]) !!}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        @if (module_is_active('ImportExport'))
                                                            @include('import-export::import.state_import', ['module' => 'state'])
                                                            @include('import-export::export.state_export', ['module' => 'state'])
                                                        @endif
                                                        <a href="#" class="btn btn-sm btn-primary"
                                                            data-ajax-popup="true" data-size="md"
                                                            data-title="{{ __('Create State') }}"
                                                            data-url="{{ route('state.create') }}"
                                                            data-bs-toggle="tooltip" title="{{ __('Add State') }}"
                                                            data-bs-original-title="{{ __('Create State') }}">
                                                            <i class="ti ti-plus"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body table-border-style">
                                <div class="table-responsive ecom-data-table">
                                    <table class="table" id="state-data-table" style="width:100%">
                                        <thead>
                                            <tr>
                                                @if (module_is_active('BulkDelete'))
                                                    @include('bulk-delete::pages.checkbox')
                                                @endif
                                                <th>{{ __('Name') }}</th>
                                                <th class="text-center">{{ __('Country') }}</th>
                                                <th class="text-end">{{ __('Action') }}</th>
                                            </tr>
                                        </thead>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade @if (isset($country_active_tab) && $country_active_tab == 'pills-city-tab') show active @endif" id="pills-city"
                    role="tabpanel" aria-labelledby="pills-city-tab">
                    <div id="City_Setting">
                        <div class="card mb-3">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col-sm-4 col-12">
                                        <h5 class="state-set">{{ __('City Settings') }}</h5>
                                    </div>
                                    <div class="col-sm-8 col-12 justify-content-sm-end">
                                        <div class="row">
                                            <form method="GET" action="{{ route('countries.index') }}"
                                                accept-charset="UTF-8" id="customer_submit">
                                                @csrf
                                                <input type="hidden" name="country_active_tab" value="pills-city-tab">
                                                <div class=" d-flex align-items-center float-right justify-content-sm-between gap-2">
                                                    <div>
                                                        <div class="btn-box">
                                                            {!! Form::select('state_id', $get_state, null, [
                                                                'class' => 'form-control State select2',
                                                                'id' => 'state_filter',
                                                            ]) !!}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        @if (module_is_active('ImportExport'))
                                                            @include('import-export::import.city_import', ['module' => 'city'])
                                                            @include('import-export::export.city_export', ['module' => 'city'])
                                                        @endif
                                                        <a href="#" class="btn btn-sm btn-primary"
                                                            data-ajax-popup="true" data-size="md"
                                                            data-title="{{ __('Create City') }}"
                                                            data-url="{{ route('city.create') }}"
                                                            data-bs-toggle="tooltip" title="{{ __('Add City') }}"
                                                            data-bs-original-title="{{ __('Create City') }}">
                                                            <i class="ti ti-plus"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body table-border-style">
                                <div class="table-responsive ecom-data-table">
                                    <table class="table" id="city-table" style="width:100%">
                                        <thead>
                                            <tr>
                                                @if (module_is_active('BulkDelete'))
                                                    @include('bulk-delete::pages.checkbox')
                                                @endif
                                                <th>{{ __('Name') }}</th>
                                                <th class="text-center">{{ __('State') }}</th>
                                                <th class="text-end">{{ __('Action') }}</th>
                                            </tr>
                                        </thead>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('custom-script')

    @include('layouts.includes.datatable-js')
    @if (\Auth::user()->type == 'super admin')
        <script type="text/javascript">
            $(function () {
                $('#country-table').DataTable({
                    processing: true,
                    serverSide: true,
                    responsive: true, // Enable responsive DataTable
                    ajax: "{{ route('countries.index') }}", // Make sure this route points to your controller
                    columns: getCountryColumns(),
                    initComplete: function(settings, json) {
                        removeSortingOrderFromHeader();
                        var tooltipTriggerList = [].slice.call(document.querySelectorAll(
                            '[data-bs-toggle="tooltip"]'));
                        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                            return new bootstrap.Tooltip(tooltipTriggerEl);
                        });
                    },
                    buttons: getCountryButtons(),
                    dom: "<'row'<'col-sm-12 col-md-3'l><'col-sm-12 col-md-9 data-tab-btn flex-wrap d-flex justify-content-end gap-1'Bf>>" +
                        "<'row'<'col-sm-12'tr>>" +
                        "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                    language: {
                        search: "", // Removes the "Search:" label
                        searchPlaceholder: "@lang('Search...')", // Optional: Add a placeholder to the search input
                        lengthMenu: " _MENU_ @lang('Entries Per Page')", // Localize the "Per page" dropdown
                        paginate: {
                            next: '<i class="ti ti-chevron-right"></i>',
                            previous: '<i class="ti ti-chevron-left"></i>'
                        },
                        info: "@lang('Showing') _START_ @lang('to') _END_ @lang('of') _TOTAL_ @lang('entries')", // Localize pagination info
                        emptyTable: "@lang('No data available in table')",
                    },
                    drawCallback: function(settings) {
                        removeSortingOrderFromHeader();
                        // Apply classes to the button container after DataTable initialization
                        $('.dt-buttons').addClass('d-flex gap-1');
                    }
                });

                var stateTable = $('#state-data-table').DataTable({
                    processing: true,
                    serverSide: true,
                    responsive: true, // Enable responsive DataTable
                    ajax: {
                        url: "{{ route('state.index') }}",
                        data: function(d) {
                            d.country_id = $('#country_filter').val(); // Get the state filter value
                        }
                    },
                    columns: getStateColumns(),
                    initComplete: function(settings, json) {
                        removeSortingOrderFromHeader();
                        var tooltipTriggerList = [].slice.call(document.querySelectorAll(
                            '[data-bs-toggle="tooltip"]'));
                        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                            return new bootstrap.Tooltip(tooltipTriggerEl);
                        });
                    },
                    buttons: getStateButtons(),
                    dom: "<'row'<'col-sm-12 col-md-3'l><'col-sm-12 col-md-9 data-tab-btn flex-wrap d-flex justify-content-end gap-1'Bf>>" +
                        "<'row'<'col-sm-12'tr>>" +
                        "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>", // Custom dom positioning for buttons
                    language: {
                        search: "", // Removes the "Search:" label
                        searchPlaceholder: "@lang('Search...')", // Optional: Add a placeholder to the search input
                        lengthMenu: " _MENU_ @lang('Entries Per Page')", // Localize the "Per page" dropdown
                        paginate: {
                            next: '<i class="ti ti-chevron-right"></i>',
                            previous: '<i class="ti ti-chevron-left"></i>'
                        },
                        info: "@lang('Showing') _START_ @lang('to') _END_ @lang('of') _TOTAL_ @lang('entries')", // Localize pagination info
                        emptyTable: "@lang('No data available in table')",
                    },
                    drawCallback: function() {
                        removeSortingOrderFromHeader();
                        // Apply classes to the button container after DataTable initialization
                        $('.dt-buttons').addClass('d-flex gap-1');
                    }
                });

                var cityTable = $('#city-table').DataTable({
                    processing: true,
                    serverSide: true,
                    responsive: true, // Enable responsive DataTable
                    ajax: {
                        url: "{{ route('city.index') }}",
                        data: function(d) {
                            d.state_id = $('#state_filter').val(); // Get the state filter value
                        }
                    },
                    columns: getCityColumns(),
                    initComplete: function(settings, json) {
                        removeSortingOrderFromHeader();
                        var tooltipTriggerList = [].slice.call(document.querySelectorAll(
                            '[data-bs-toggle="tooltip"]'));
                        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                            return new bootstrap.Tooltip(tooltipTriggerEl);
                        });
                    },
                    buttons: getCityButtons(),
                    dom: "<'row'<'col-sm-12 col-md-3'l><'col-sm-12 col-md-9 data-tab-btn flex-wrap d-flex justify-content-end gap-1'Bf>>" +
                        "<'row'<'col-sm-12'tr>>" +
                        "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>", // Custom dom positioning for buttons
                    language: {
                        search: "", // Removes the "Search:" label
                        searchPlaceholder: "@lang('Search...')", // Optional: Add a placeholder to the search input
                        lengthMenu: " _MENU_ @lang('Entries Per Page')", // Localize the "Per page" dropdown
                        paginate: {
                            next: '<i class="ti ti-chevron-right"></i>',
                            previous: '<i class="ti ti-chevron-left"></i>'
                        },
                        info: "@lang('Showing') _START_ @lang('to') _END_ @lang('of') _TOTAL_ @lang('entries')", // Localize pagination info
                        emptyTable: "@lang('No data available in table')",
                    },
                    drawCallback: function() {
                        removeSortingOrderFromHeader();
                        // Apply classes to the button container after DataTable initialization
                        $('.dt-buttons').addClass('d-flex flex-wrap gap-1');
                    }
                });

                // Listen for changes in the state filter and reload the DataTable
                $(document).on('change', '#state_filter', function() {
                    cityTable.ajax.reload();
                });
                $(document).on('change', '#country_filter', function() {
                    stateTable.ajax.reload();
                });

            });

            $(document).on('change', '#city_country_id', function() {
                var country_id = $(this).val();
                getState(country_id);
            });

            function getState(country_id) {
                var data = {
                    "country_id": country_id,
                    "_token": "{{ csrf_token() }}",
                }

                $.ajax({
                    url: '{{ route('getcitystate') }}',
                    method: 'POST',
                    data: data,
                    success: function(data) {
                        $('#city_state_id').empty();
                        $('#city_state_id').append('<option value="" disabled>{{ __('Select State') }}</option>');

                        $.each(data, function(key, value) {
                            $('#city_state_id').append('<option value="' + key + '">' + value + '</option>');
                        });
                        $('#city_state_id').val('');
                    }
                });
            }

            // Function to get country columns
            function getCountryColumns() {
                let countryColumns = [
                    { data: 'name', name: 'name' },
                    { data: 'action', name: 'action', orderable: false, searchable: false }
                ];

                if (typeof addCheckboxColumn === 'function') {
                    // Add checkbox column if BulkDelete module is active
                    addCheckboxColumn(countryColumns);
                }

                return countryColumns;
            }

            // Function to get state columns
            function getStateColumns() {
                let stateColumns = [
                    { data: 'name', name: 'name' },
                    { data: 'country_name', name: 'country.name' },
                    { data: 'action', name: 'action', orderable: false, searchable: false }
                ];

                if (typeof addCheckboxColumn === 'function') {
                    // Add checkbox column if BulkDelete module is active
                    addCheckboxColumn(stateColumns);
                }
                return stateColumns;
            }

            // Function to get city columns
            function getCityColumns() {
                let cityColumns = [
                    { data: 'name', name: 'name' },
                    { data: 'state_name', name: 'state.name' },
                    { data: 'action', name: 'action', orderable: false, searchable: false }
                ];

                if (typeof addCheckboxColumn === 'function') {
                    // Add checkbox column if BulkDelete module is active
                    addCheckboxColumn(cityColumns);
                }

                return cityColumns;
            }

            // Function to get country buttons
            function getCountryButtons() {
                let countryButtons = [{
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>',
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function(e, dt, node, config) {
                            dt.search('').columns().search('').draw(); // Clears search and filters, then redraws the table
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>',
                        className: 'btn btn-default buttons-reload btn-light-warning',
                        action: function(e, dt, node, config) {
                            dt.ajax.reload(); // Reloads the data
                        }
                    }
                ];

                if (typeof addBulkDeleteButton === 'function') {
                    // Add bulk delete button for country
                    addBulkDeleteButton(countryButtons, 'country', 'country-table');
                }

                return countryButtons;
            }

            // Function to get state buttons
            function getStateButtons() {
                let stateButtons = [{
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>',
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function(e, dt, node, config) {
                            dt.search('').columns().search('').draw(); // Clears search and filters, then redraws the table
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>',
                        className: 'btn btn-default buttons-reload btn-light-warning',
                        action: function(e, dt, node, config) {
                            dt.ajax.reload(); // Reloads the data
                        }
                    }
                ];

                if (typeof addBulkDeleteButton === 'function') {
                    // Add bulk delete button for state
                    addBulkDeleteButton(stateButtons, 'state', 'state-data-table');
                }

                return stateButtons;
            }

            // Function to get city buttons
            function getCityButtons() {
                let cityButtons = [{
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>',
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function(e, dt, node, config) {
                            dt.search('').columns().search('').draw(); // Clears search and filters, then redraws the table
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>',
                        className: 'btn btn-default buttons-reload btn-light-warning',
                        action: function(e, dt, node, config) {
                            dt.ajax.reload(); // Reloads the data
                        }
                    }
                ];

                if (typeof addBulkDeleteButton === 'function') {
                    // Add bulk delete button for city
                    addBulkDeleteButton(cityButtons, 'city', 'city-table');
                }

                return cityButtons;
            }
        </script>
    @endif
    @if (module_is_active('BulkDelete'))
      @include('bulk-delete::pages.script')
   @endif

@endpush
