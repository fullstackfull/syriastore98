@extends('layouts.app')

@section('page-title')
    {{ __('Affiliate Product') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Affiliate Product') }}</li>
@endsection
@push('css')
    @include('layouts.includes.datatable-css')
    <style>
        div.dataTables_wrapper div.dataTables_paginate ul.pagination {
            gap: 0;
            flex-wrap: wrap;

        }

        div.dataTables_wrapper div.dataTables_paginate ul.pagination li {
            width: auto;
            height: auto;
        }
        #payout-request-table{
            width: 1111px !important;
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
                width: auto;
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
@endpush
@push('custom-script')
    <script>
        var scrollSpy = new bootstrap.ScrollSpy(document.body, {
            target: '#useradd-sidenav',
            offset: 200,
        })

        $('.tab-link').on('click', function() {
            var tabId = $(this).data('tab');
            $('.tab-content').addClass('d-none');
            $('#' + tabId).removeClass('d-none');

            $('.tab-link').removeClass('active');
            $(this).addClass('active');
        });
    </script>

    <script type="text/javascript">
        function enable() {
            const element = $('#is_enable').is(':checked');
            $('.referralDiv').addClass('disabledCookie');
            if (element == true) {
                $('.referralDiv').removeClass('disabledCookie');
            } else {
                $('.referralDiv').addClass('disabledCookie');
            }
        }
    </script>
@endpush

@php
    $settings = getAdminAllSetting();
    $currency = isset($settings['CURRENCY']) ? $settings['CURRENCY'] : '$';
@endphp
@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="row">
                <div class="col-xl-3">
                    <div class="card sticky-top" style="top:30px">
                        <div class="list-group list-group-flush" id="useradd-sidenav">
                            <a href="#transaction-card"
                                class="list-group-item list-group-item-action border-0 tab-link active"
                                data-tab="transaction-card">{{ __('Transaction') }}
                                <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                            </a>
                            <a href="#payout-request-card" class="list-group-item list-group-item-action border-0 tab-link"
                                data-tab="payout-request-card">{{ __('Payout Request') }}
                                <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                            </a>
                            <a href="#settings" class="list-group-item list-group-item-action border-0 tab-link"
                                data-tab="settings">{{ __('Settings') }}
                                <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-xl-9">
                    <div id="transaction-card" class="card tab-content">
                        <div class="card-header">
                            <h5>{{ __('Transaction') }}</h5>
                        </div>
                        <div class="card-body table-border-style">
                            <div class="table-responsive ecom-data-table">
                                <table class="table pc-dt-simple" id="transaction-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>{{ __('Customer Name') }}</th>
                                            <th>{{ __('Affiliate Customer Name') }}</th>
                                            <th>{{ __('Order Id') }}</th>
                                            <th>{{ __('Order Price') }}</th>
                                            <th>{{ __('Commission (%)') }}</th>
                                            <th>{{ __('Commission Amount') }}</th>
                                        </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div id="payout-request-card" class="card tab-content d-none">
                        <div class="card-header">
                            <h5>{{ __('Payout Request') }}</h5>
                        </div>
                        <div class="card-body table-border-style">
                            <div class="table-responsive ecom-data-table">
                                <table class="table pc-dt-simple" id="payout-request-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>{{ __('Customer Name') }}</th>
                                            <th>{{ __('Requested Date') }}</th>
                                            <th>{{ __('Requested Amount') }}</th>
                                            <th>{{ __('Action') }}</th>
                                        </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div id="settings" class="card tab-content d-none">
                        {{ Form::open(['route' => 'productaffiliate.store', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
                        <div
                            class="card-header flex-column flex-lg-row d-flex align-items-lg-center gap-2 justify-content-between">
                            <h5>{{ __('Settings') }}</h5>
                            <div class="form-check form-switch custom-switch-v1" onclick="enable()">
                                <input type="checkbox" name="is_enable" class="form-check-input input-primary"
                                    id="is_enable" {{ isset($setting) && $setting->is_enable == '1' ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_enable">{{ __('Enable') }}</label>
                            </div>
                        </div>
                        <div
                            class="card-body referralDiv {{ isset($setting) && $setting->is_enable == '0' ? 'disabledCookie ' : '' }}">
                            <div class="row">
                                <div class="row ">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('percentage', __('Commission Percentage (%)'), ['class' => 'form-label']) }}
                                            {{ Form::text('percentage',  isset($setting) ? $setting->percentage : '', ['class' => 'form-control', 'placeholder' => __('Enter Commission Percentage')]) }}
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('minimum_threshold_amount', __('Minimum Threshold Amount'), ['class' => 'form-label']) }}
                                            <div class="input-group">
                                                <span class="input-group-prepend"><span
                                                        class="input-group-text">{{ $currency }}</span></span>
                                                {{ Form::text('minimum_threshold_amount',  isset($setting) ? $setting->minimum_threshold_amount : '', ['class' => 'form-control', 'placeholder' => __('Enter Minimum Payout')]) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <button class="btn-submit btn btn-primary" type="submit">
                                {{ __('Save Changes') }}
                            </button>
                        </div>
                        {{ Form::close() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('custom-script')
    @include('layouts.includes.datatable-js')
    <script>
        $(document).ready(function() {
            $('#transaction-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: "{{ route('affiliate.transactions.data') }}",
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex'
                    },
                    {
                        data: 'company_name',
                        name: 'company_name'
                    },
                    {
                        data: 'affiliate_customer_name',
                        name: 'affiliate_customer_name'
                    },
                    {
                        data: 'order_id',
                        name: 'order_id'
                    },
                    {
                        data: 'final_price',
                        name: 'final_price'
                    },
                    {
                        data: 'commission',
                        name: 'commission'
                    },
                    {
                        data: 'commission_amount',
                        name: 'commission_amount'
                    }
                ],
                initComplete: function(settings, json) {
                    removeSortingOrderFromHeader();
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll(
                        '[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                columnDefs: [{
                        width: '10%',
                        targets: 0
                    },
                    {
                        width: '60%',
                        targets: 1,
                        className: 'text-center'
                    },
                    {
                        width: '30%',
                        targets: 2
                    }
                ],
                buttons: [{
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>',
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function(e, dt, node, config) {
                            dt.search('').columns().search('')
                                .draw();
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>',
                        className: 'btn btn-default buttons-reload btn-light-warning',
                        action: function(e, dt, node, config) {
                            dt.ajax.reload();
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
                    $('.dt-buttons').addClass('d-flex gap-1');
                }
            });

            $('#payout-request-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: "{{ route('affiliate.payrequests.data') }}",
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex'
                    },
                    {
                        data: 'company_name',
                        name: 'company_name'
                    },
                    {
                        data: 'date',
                        name: 'date'
                    },
                    {
                        data: 'requested_amount',
                        name: 'requested_amount'
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false,
                        searchable: false
                    }
                ],
                initComplete: function(settings, json) {
                    removeSortingOrderFromHeader();
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll(
                        '[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                columnDefs: [{
                        width: '10%',
                        targets: 0
                    },
                    {
                        width: '60%',
                        targets: 1,
                        className: 'text-center'
                    },
                    {
                        width: '30%',
                        targets: 2
                    }
                ],
                buttons: [{
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>',
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function(e, dt, node, config) {
                            dt.search('').columns().search('')
                                .draw();
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>',
                        className: 'btn btn-default buttons-reload btn-light-warning',
                        action: function(e, dt, node, config) {
                            dt.ajax.reload();
                        }
                    }
                ],
                dom: "<'row'<'col-sm-12 col-md-3'l><'col-sm-12 col-md-9 data-tab-btn flex-wrap d-flex justify-content-end gap-'Bf>>" +
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
                    $('.dt-buttons').addClass('d-flex gap-1');
                }
            });
        });
    </script>
@endpush
