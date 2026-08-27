@extends('layouts.app')

@section('page-title', __('Category'))

@section('action-button')
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Category') }}</li>
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
@section('content')
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <h5></h5>
                    <div class="table-responsive">
                        <table class="table"  id="woocom-category-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>{{ __('Cover Image') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th class="text-end">{{ __('Action') }}</th>
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
            $('#woocom-category-table').DataTable({
                processing: true,
                serverSide: true,
                responsive: true, // Enable responsive DataTable
                ajax: "{{ route('woocom_category.index') }}", // Make sure this route points to your controller
                columns: [{
                        data: 'cover_image',
                        name: 'cover_image',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'name',
                        name: 'name'
                    },
                    {
                        data: 'action',
                        name: 'action',
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
                        width: '40%',
                        targets: 0
                    }, // Set width for columns if needed
                    {
                        width: '30%',
                        targets: 1,
                        className: 'text-center'
                    },
                    {
                        width: '30%',
                        targets: 2
                    }
                ],
                buttons: [{
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>', // Customize icon/text
                        className: 'btn btn-default buttons-reset btn-light-info',
                        action: function(e, dt, node, config) {
                            dt.search('').columns().search('')
                        .draw(); // Clears search and filters, then redraws the table
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>', // Customize icon/text
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
                    $('.dt-buttons').addClass('d-flex w-auto gap-1');
                }
            });
        });
    </script>
@endpush
