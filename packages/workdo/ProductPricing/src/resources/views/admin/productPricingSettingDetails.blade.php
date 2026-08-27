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

<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-product-pricing-tab') show active @endif" id="pills-product_pricing" role="tabpanel"
    aria-labelledby="pills-product_pricing-tab">
    <div id="product_pricing_settings">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> {{ __('Product Pricing Rules') }} </h5>
                <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
                    <div class="form-group me-3 mb-0">
                        {!! Form::select('all_rules_condition', $allRulesConditions, $setting['all_rules_condition'] ?? null, [
                            'class' => 'form-control all_rules_condition',
                        ]) !!}
                    </div>
                    <div class="form-group mb-0 mt-1">
                        <a href="{{ route('productpricing.create') }}" class="btn btn-sm btn-primary"
                         data-bs-toggle="tooltip" title="{{ __('Add Product Pricing Rule') }}">
                            <i class="ti ti-plus"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="col-xl-12">
                        <div class="table-responsive ecom-data-table p-4">
                            <table class="table" id="product-pricing-table" style="width:100%">
                                <thead>
                                    <tr>
                                        @if (module_is_active('BulkDelete'))
                                            @include('bulk-delete::pages.checkbox')
                                        @endif
                                        <th>{{ __('Pricing Method') }}</th>
                                        <th>{{ __('Condition') }}</th>
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
            $('#product-pricing-table').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
                ajax: "{{ route('productpricing.list') }}", // Replace with your route
                columns: getProductPricingColumns(),
                initComplete: function(settings, json) {
                    removeSortingOrderFromHeader();
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                buttons: getProductPricingButtons(),
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
                    $('.dt-buttons').addClass('d-flex gap-1 w-auto');
                }
            });
        });

        // Function to get columns
        function getProductPricingColumns() {
            let productPricingColumns = [{
                    data: 'pricing_method',
                    name: 'pricing_method'
                },
                {
                    data: 'conditions',
                    name: 'conditions',
                    orderable: false,
                    searchable: false,
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
                addCheckboxColumn(productPricingColumns);
            }

            return productPricingColumns;
        }

        // Function to get buttons
        function getProductPricingButtons() {
            let productPricingButtons = [
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
                addBulkDeleteButton(productPricingButtons, 'productpricing-addon', 'product-pricing-table');
            }

            return productPricingButtons;
        }
    </script>
    <script>
        $(document).ready(function() {
            $(document).on("change", ".all_rules_condition", function() {
                var all_rules_condition = $(".all_rules_condition").val();
                $.ajax({
                    url: '{{ route('productpricing.rulesCondition') }}',
                    type: 'POST',
                    data: {
                        "all_rules_condition": all_rules_condition,
                    },
                    success: function(data) {
                        if (data.status == true) {
                            show_toastr("success", data.message, "success");
                        } else {
                            show_toastr("error", data.message, "error");
                        }
                    },
                });
            });

            $(document).on("change", ".rule_condition", function() {
                var rule_condition = $(this).val();
                var rule_id = $(this).attr('data-id');
                $.ajax({
                    url: '{{ route('productpricing.ruleCondition') }}',
                    type: 'POST',
                    data: {
                        "rule_condition": rule_condition,
                        "rule_id": rule_id,
                    },
                    success: function(data) {
                        if (data.status == true) {
                            show_toastr("success", data.message, "success");
                        } else {
                            show_toastr("error", data.message, "error");
                        }
                    },
                });
            });
        });
    </script>
    @if (module_is_active('BulkDelete'))
        @include('bulk-delete::pages.script')
    @endif
@endpush
