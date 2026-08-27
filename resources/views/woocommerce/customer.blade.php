@extends('layouts.app')

@section('page-title')
    {{ __(' Customers') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __(' Customers') }}</li>
@endsection

@section('action-button')
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
@php
    $customer_avatar = asset(Storage::url('uploads/customerprofile/'));
@endphp

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <h5></h5>
                    <div class="table-responsive">
                        <table class="table" id="woocom-customer-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th class="ignore"> {{__('Customer Avatar')}}</th>
                                    <th  class="text-center"> {{__('Name')}}</th>
                                    <th  class="text-center"> {{__('Email')}}</th>
                                    <th  class="text-center"> {{__('Phone No')}}</th>
                                    <th class="text-end"> {{__('Action')}}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('custom-script')
@include('layouts.includes.datatable-js')
    <script type="text/javascript">
        $(function() {
            $('#woocom-customer-table').DataTable({
                processing: true,
                serverSide: true,
                responsive: true, // Enable responsive DataTable
                ajax: "{{ route('woocom_customer.index') }}", // Make sure this route points to your controller
                columns: [{
                        data: 'customer_image',
                        name: 'customer_image',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'first_name',
                        name: 'name',
                        className: 'text-center'
                    },
                    {
                        data: 'email',
                        name: 'email',
                        className: 'text-center'
                    },
                    {
                        data: 'phone',
                        name: 'phone',
                        className: 'text-center'
                    },
                    {
                        data: 'action',
                        name: 'action',
                         className: 'text-end',
                        orderable: false,
                        searchable: false
                    }
                ],
                initComplete: function(settings, json) {
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll(
                        '[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                columnDefs: [{
                        width: '20%',
                        targets: 0
                    }, // Set width for columns if needed
                    {
                        width: '25%',
                        targets: 1,
                        className: 'text-center'
                    },
                    {
                        width: '25%',
                        targets: 1,
                        className: 'text-center'
                    },
                    {
                        width: '15%',
                        targets: 1,
                        className: 'text-center'
                    },
                    {
                        width: '15%',
                        targets: 2
                    }
                ],
                buttons: [{
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>',
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function(e, dt, node, config) {
                            dt.search('').columns().search('')
                        .draw(); // Clears search and filters, then redraws the table
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>',
                        className: 'btn btn-default buttons-reload btn-light-warning',
                        action: function(e, dt, node, config) {
                            dt.ajax.reload(); // Reloads the data
                        }
                    }
                ],
                dom: "<'row'<'col-4 d-flex justify-content-start'l><'col-8 data-tab-btn flex-wrap d-flex justify-content-end gap-1'Bf>>" +
                    "<'row'<'col-sm-12'tr>>" +
                    "<'row'<'col-4 d-flex align-items-center'i><'col-8 d-flex justify-content-end'p>>",
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
                    // Apply classes to the button container after DataTable initialization
                    $('.dt-buttons').addClass('d-flex gap-1 w-auto');
                }
            });
        });
    </script>
    <script src="https://rawgit.com/unconditional/jquery-table2excel/master/src/jquery.table2excel.js"></script>
    <script>
        const d = new Date();
        let seconds = d.getSeconds();
        $('.csv').on('click', function() {
            $('.ignore').remove();
            $("#pc-dt-export").table2excel({
                filename: "Customer_" + seconds
            });
            window.location.reload();
        })
    </script>
@endpush
