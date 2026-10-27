@extends('layouts.app')

@section('page-title')
    {{ __('Stock Reports') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">
        {{ __('Stock Reports') }}</li>
@endsection

@section('action-button')
    <div class="text-end">

    </div>
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
            <div class="sticky-top " style="top:60px">
                <div class="list-group list-group-flush addon-set-tab" id="useradd-sidenav">
                    {{-- P2-05-3. `ss-tabrail` scopes the active pill's AA fix to THIS screen. The pill measured
                             2.59:1 (white on the brand green) in all four configurations, and the rail markup is
                             shared with three other screens that belong to other slices -- see the DS rule and
                             F106 for why the fix is scoped rather than applied to the shared selector. --}}
                    {{--
                        ALL THREE TABS CLAIMED `aria-selected="true"`.
                        A tablist has exactly one selected tab; three saying so means a screen
                        reader announces all three as current, and the one that is actually
                        showing is distinguishable only by the `active` CSS class — which is the
                        same visible-but-not-announced split the period bars had.

                        The loop is not tidying for its own sake: it makes the invariant
                        structural. `$ssActive` is compared once per tab and drives BOTH the
                        class and the attribute, so they cannot be edited apart again.
                    --}}
                    <ul class="nav nav-pills flex-column w-100 gap-2 ss-tabrail" id="pills-tab" role="tablist">
                        @foreach ([
                            'pills-low-stock-tab' => ['pills-low-stock', 'LowStock_Setting', __('Low In Stock')],
                            'pills-out-of-stock-tab' => ['pills-out-of-stock', 'OutOfStock_Setting', __('Out Of Stock')],
                            'pills-most-stocked-tab' => ['pills-most-stocked', 'MostStock_Setting', __('Most Stocked')],
                        ] as $ssTabId => [$ssPane, $ssAnchor, $ssLabel])
                            @php $ssActive = ($stock_active_tab ?? 'pills-low-stock-tab') === $ssTabId; @endphp
                            <li class="nav-item" role="presentation">
                                <a href="#{{ $ssAnchor }}"
                                   class="nav-link @if ($ssActive) active @endif list-group-item list-group-item-action rounded-1 text-center f-w-600"
                                   id="{{ $ssTabId }}" data-bs-toggle="pill" data-bs-target="#{{ $ssPane }}"
                                   role="tab" aria-controls="{{ $ssPane }}"
                                   aria-selected="{{ $ssActive ? 'true' : 'false' }}">
                                    {{ $ssLabel }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-xl-9">
            <div class="tab-content" id="pills-tabContent">
                <div class="tab-pane fade @if(isset($stock_active_tab) && ($stock_active_tab == 'pills-low-stock-tab')) show active @endif" id="pills-low-stock" role="tabpanel"
                    aria-labelledby="pills-low-stock-tab">
                    <div id="LowStock_Setting">
                        <div class="col-md-12">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <div class="row align-items-center">
                                        <div class="col-12">
                                            <h5 class="state-set">{{ __('Low in Stock Products') }}</h5>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3 g-0">
                                    <div class="card-body table-border-style ss-dt-card-body">
                                        <div class="table-responsive ecom-data-table">
                                            <table class="table" id="low-stock-table" style="width:100%">
                                                <thead>
                                                    <tr>
                                                        <th>{{ __('Product Name') }}</th>
                                                        <th>{{ __('Category') }}</th>
                                                        <th>{{ __('Stock Status') }}</th>
                                                        <th>{{ __('Stock Quntity') }}</th>
                                                        <th>{{ __('Action') }}</th>
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
                <div class="tab-pane fade @if(isset($stock_active_tab) && ($stock_active_tab == 'pills-out-of-stock-tab')) show active @endif" id="pills-out-of-stock" role="tabpanel" aria-labelledby="pills-out-of-stock-tab">
                    <div id="OutOfStock_Setting">
                        <div class="card mb-3">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col-12">
                                        <h5 class="state-set">{{ __('Out of Stock Products') }}</h5>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body table-border-style ss-dt-card-body">
                                <div class="table-responsive ecom-data-table">
                                    <table class="table" id="out-of-stock-table" style="width:100%">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Product Name') }}</th>
                                                <th>{{ __('Category') }}</th>
                                                <th>{{ __('Stock Status') }}</th>
                                                <th>{{ __('Stock Quntity') }}</th>
                                                <th>{{ __('Action') }}</th>
                                            </tr>
                                        </thead>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade @if(isset($stock_active_tab) && ($stock_active_tab == 'pills-most-stocked-tab')) show active @endif" id="pills-most-stocked" role="tabpanel" aria-labelledby="pills-most-stocked-tab">
                    <div id="MostStock_Setting">
                        <div class="card mb-3">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col-12">
                                        <h5 class="state-set">{{ __('Most Stocked Products') }}</h5>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body table-border-style ss-dt-card-body">
                                <div class="table-responsive ecom-data-table">
                                    <table class="table" id="most-stocked-table" style="width:100%">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Product Name') }}</th>
                                                <th>{{ __('Category') }}</th>
                                                <th>{{ __('Stock Status') }}</th>
                                                <th>{{ __('Stock Quntity') }}</th>
                                                <th>{{ __('Action') }}</th>
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
    <script type="text/javascript">
        $(function () {
            $('#low-stock-table').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,  // Enable responsive DataTable
                ajax: "{{ route('stockReport.lowStock') }}",  // Make sure this route points to your controller
                columns: [
                    { data: 'product_name', name: 'product_name' },
                    { data: 'category', name: 'category' },
                    { data: 'stock_status', name: 'stock_status',render: function(data, type, row) {
                            return data;  // Render stock_status as HTML
                        }
                    },
                    { data: 'stock', name: 'stock' },
                    {data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center ss-col-locked ss-col-pinned'}
                ],
                initComplete: function(settings, json) {
                    removeSortingOrderFromHeader();
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                buttons: [
                    {
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>', // Customize icon/text
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function(e, dt, node, config) {
                            dt.search('').columns().search('').draw();  // Clears search and filters, then redraws the table
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>', // Customize icon/text
                        className: 'btn btn-default buttons-reload btn-light-warning',
                        action: function(e, dt, node, config) {
                            dt.ajax.reload();  // Reloads the data
                        }
                    }
                ],
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

            $('#out-of-stock-table').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,  // Enable responsive DataTable
                ajax: {
                    url: "{{ route('stockReport.outOfStock') }}",
                    data: function(d) {
                        //
                    }
                },
                columns: [
                    { data: 'product_name', name: 'product_name' },
                    { data: 'category', name: 'category' },
                    { data: 'stock_status', name: 'stock_status',render: function(data, type, row) {
                        return data;  // Render stock_status as HTML
                    } },
                    { data: 'stock', name: 'stock' },
                    {data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center ss-col-locked ss-col-pinned'}
                ],
                initComplete: function(settings, json) {
                    removeSortingOrderFromHeader();
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                buttons: [
                    {
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>', // You can change the icon or text
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function (e, dt, node, config) {
                            dt.search('').columns().search('').draw();  // Clears search and filter, then redraws the table
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>', // You can change the icon or text
                        className: 'btn btn-default buttons-reload btn-light-warning',
                        action: function (e, dt, node, config) {
                            dt.ajax.reload();  // Reloads the data
                        }
                    }
                ],
                dom:  "<'row'<'col-sm-12 col-md-3'l><'col-sm-12 col-md-9 data-tab-btn flex-wrap d-flex justify-content-end gap-1'Bf>>" +
                    "<'row'<'col-sm-12'tr>>" +
                    "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",  // Custom dom positioning for buttons
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

            $('#most-stocked-table').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,  // Enable responsive DataTable
                ajax: {
                    url: "{{ route('stockReport.mostStocked') }}",
                    data: function(d) {

                    }
                },
                columns: [
                    { data: 'product_name', name: 'product_name' },
                    { data: 'category', name: 'category' },
                    { data: 'stock_status', name: 'stock_status',render: function(data, type, row) {
                        return data;  // Render stock_status as HTML
                    } },
                    { data: 'stock', name: 'stock' },
                    { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center ss-col-locked ss-col-pinned'}
                ],
                initComplete: function(settings, json) {
                    removeSortingOrderFromHeader();
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                buttons: [
                    {
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>', // You can change the icon or text
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function (e, dt, node, config) {
                            dt.search('').columns().search('').draw();  // Clears search and filter, then redraws the table
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>', // You can change the icon or text
                        className: 'btn btn-default buttons-reload btn-light-warning',
                        action: function (e, dt, node, config) {
                            dt.ajax.reload();  // Reloads the data
                        }
                    }
                ],
                dom:  "<'row'<'col-sm-12 col-md-3'l><'col-sm-12 col-md-9 data-tab-btn flex-wrap d-flex justify-content-end gap-1'Bf>>" +
                    "<'row'<'col-sm-12'tr>>" +
                    "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",  // Custom dom positioning for buttons
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

        });
    </script>
@endpush
