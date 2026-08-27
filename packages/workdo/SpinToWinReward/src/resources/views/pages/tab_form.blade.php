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
    @endif
@endpush

<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-spin_to_win-tab') show active @endif" id="pills-spin_to_win" role="tabpanel"
    aria-labelledby="pills-spin_to_win-tab">
    <div id="spin_to_win_settings">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> {{ __('Spin To Win Label') }} </h5>
                <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
                    <div class="form-group mb-0 mt-1">
                        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md"
                            data-title="{{ __('Add Label') }}" data-url="{{ route('spintowin-label.create') }}" data-bs-toggle="tooltip"
                            title="{{ __('Add Label') }}">
                            <i class="ti ti-plus"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="col-xl-12">
                        <div class="table-responsive ecom-data-table p-4">
                            <table class="table" id="spin-to-win-table" style="width:100%">
                                <thead>
                                    <tr>
                                        @if (module_is_active('BulkDelete'))
                                            @include('bulk-delete::pages.checkbox')
                                        @endif
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
    </div>
</div>

@push('custom-script')
    @include('layouts.includes.datatable-js')
    <script type="text/javascript">
        $(function() {
            $('#spin-to-win-table').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
                ajax: "{{ route('spintowin-label.index') }}", // Replace with your route
                columns: getSpinToWinColumns(),
                initComplete: function(settings, json) {
                    removeSortingOrderFromHeader();
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                buttons: getSpinLabelButtons(),
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
        });

        // Function to get columns
        function getSpinToWinColumns() {
            let SpintowinLabelColumns = [{
                    data: 'name',
                    name: 'name'
                },
                {
                    data: 'action',
                    name: 'action',
                    orderable: false,
                    searchable: false,
                    className: 'text-end'
                }
            ];
            if (typeof addCheckboxColumn === 'function') {
                // Add checkbox column if BulkDelete module is active
                addCheckboxColumn(SpintowinLabelColumns);
            }

            return SpintowinLabelColumns;
        }

        // Function to get buttons
        function getSpinLabelButtons() {
            let spinlabelsButtons = [
                {
                    text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>',
                    className: 'btn btn-default buttons-reset btn-light-info',
                    action: function(e, dt, node, config) {
                        dt.search('').columns().search('').draw();
                    }
                },
                {
                    text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>',
                    className: 'btn btn-default buttons-reload btn-light-warning',
                    action: function(e, dt, node, config) {
                        dt.ajax.reload();
                    }
                }
            ];

            if (typeof addBulkDeleteButton === 'function') {
                // Add bulk delete button for countdown
                addBulkDeleteButton(spinlabelsButtons, 'spintowin-addon', 'spin-to-win-table');
            }

            return spinlabelsButtons;
        }
    </script>
@endpush