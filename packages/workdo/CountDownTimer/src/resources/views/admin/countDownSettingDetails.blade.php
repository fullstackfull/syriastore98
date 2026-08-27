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
<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-count_down-tab') show active @endif" id="pills-count_down" role="tabpanel"
    aria-labelledby="pills-count_down-tab">
    <div id="count_down_settings">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> {{ __('Count-Down Days Settings') }} </h5>
            </div>
            <div class="card-body p-4">
                {{ Form::model($setting, ['route' => 'count.down.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
                @csrf
                <input type="hidden" name="app_setting_tab" value="pills-count_down-tab">
                <div class="row mt-2">
                    <div class="col-md-6">
                        <div class="form-group">
                            {{ Form::label('start_countdown_days', __('Count-Down Days'), ['class' => 'form-label']) }}
                            {{ Form::number('start_countdown_days', null, ['class' => 'form-control', 'placeholder' => 'Count-Down Days']) }}
                            @error('start_countdown_days')
                                <span class="invalid-start_countdown_days" role="alert">
                                    <strong class="text-danger">{{ $message }}</strong>
                                </span>
                            @enderror
                            <span
                                class="text-muted">{{ __('Note: Enter the number of days before you want the count-down to show.') }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
            </div>
            {!! Form::close() !!}
        </div>
    </div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <h5 class=""> {{ __('Count-Down List') }} </h5>
            <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
                <a href="#" class="btn btn-sm btn-primary btn-badge" data-ajax-popup="true" data-size="md"
                    data-title="{{ __('Add Count-Down') }}" data-url="{{ route('countdowntimer.create') }}"
                    data-bs-toggle="tooltip" title="{{ __('Add Count-Down') }}">
                    <i class="ti ti-plus"></i>
                </a>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row mt-2">
                <div class="col-xl-12">
                    <div class="table-responsive ecom-data-table p-4">
                        <table class="table" id="countdown-table" style="width:100%">
                            <thead>
                                <tr>
                                    @if (module_is_active('BulkDelete'))
                                        @include('bulk-delete::pages.checkbox')
                                    @endif
                                    <th>{{ __('Title') }}</th>
                                    <th>{{ __('Theme Layout') }}</th>
                                    <th>{{ __('Start Date') }}</th>
                                    <th>{{ __('Start Time') }}</th>
                                    <th>{{ __('Status') }}</th>
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

@push('custom-script')
    @include('layouts.includes.datatable-js')
    <script type="text/javascript">
        $(function() {
            $('#countdown-table').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
                ajax: "{{ route('countdowntimers.list') }}",
                columns: getcountdownColumns(),
                initComplete: function(settings, json) {
                    removeSortingOrderFromHeader();
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll(
                        '[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                buttons: getcountdownButtons(),
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

        // Function to get countdown columns
        function getcountdownColumns() {
            let countdownColumns = [{
                    data: 'title',
                    name: 'title'
                },
                {
                    data: 'theme_layout',
                    name: 'theme_layout',
                    orderable: false,
                    searchable: false
                },
                {
                    data: 'start_date',
                    name: 'start_date'
                },
                {
                    data: 'start_time',
                    name: 'start_time'
                },
                {
                    data: 'status',
                    name: 'status',
                    orderable: false,
                    searchable: false
                },
                {
                    data: 'action',
                    name: 'action',
                    orderable: false,
                    searchable: false
                }
            ];

            if (typeof addCheckboxColumn === 'function') {
                // Add checkbox column if BulkDelete module is active
                addCheckboxColumn(countdownColumns);
            }

            return countdownColumns;
        }

        // Function to get countdown buttons
        function getcountdownButtons() {
            let countdownButtons = [
                {
                    text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="@lang("Reset")" data-bs-original-title="@lang("Reset")"></i>',
                    className: 'btn btn-default buttons-reset btn-light-info btn-badge',
                    action: function(e, dt, node, config) {
                        dt.search('').columns().search('').draw();
                    }
                },
                {
                    text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="@lang("Reload")" data-bs-original-title="@lang("Reload")"></i>',
                    className: 'btn btn-default buttons-reload btn-light-warning btn-badge',
                    action: function(e, dt, node, config) {
                        dt.ajax.reload();
                    }
                }
            ];

            if (typeof addBulkDeleteButton === 'function') {
                // Add bulk delete button for countdown
                addBulkDeleteButton(countdownButtons, 'countdown-addon', 'countdown-table');
            }

            return countdownButtons;
        }
    </script>
    @if (module_is_active('BulkDelete'))
        @include('bulk-delete::pages.script')
    @endif
@endpush
