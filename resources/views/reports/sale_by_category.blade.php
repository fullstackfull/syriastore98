@extends('layouts.app')

@section('page-title')
    {{ __('Sales Category Report') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Sales Category Report') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        <a href="javascript:;" class="btn btn-sm btn-primary btn-icon exportChartButton" title="{{ __('Export') }}"
            data-bs-toggle="tooltip" data-bs-placement="top">
            <i class="ti ti-file-export"></i>
        </a>
    </div>
@endsection
@section('content')
    <div class="row">
        <div class="col-xxl-12">
            <div class="card card-body">
                {{-- The period bar is `<x-ss.report-filter>`: this screen had its own copy, as
                     did the other two sale-by screens and the reports hub, and all four carried
                     the same defects — a `role="tablist"` whose buttons were not tabs, a
                     selection kept only in a CSS class, `<div>`s as direct children of a `<ul>`,
                     and an unlabelled date field. --}}
                <x-ss.report-filter id="category" :label="__('Report period')"
                                    generate-value="date-wise" :generate-disabled="true">
                    {{-- The multi-select had NO LABEL, and its Apply and Reset controls were
                         `<a href="#">` and `<a href="">` — the second navigates away on click,
                         which is not what "Reset" means to a keyboard user who lands on it. --}}
                    <div class="ss-field">
                        <label class="ss-label" for="category_id">{{ __('Categories') }}</label>
                        {!! Form::select('category_id[]', $MainCategoryList, null, [
                            'class' => 'form-control multi-select',
                            'multiple' => 'multiple',
                            'data-role' => 'tagsinput',
                            'id' => 'category_id',
                        ]) !!}
                    </div>

                    <button type="button" class="ss-btn ss-btn--secondary ss-btn--sm filter-apply-btn"
                            title="{{ __('Apply') }}">
                        <i class="ti ti-search" aria-hidden="true"></i>
                        <span class="ss-visually-hidden">{{ __('Apply') }}</span>
                    </button>
                    <a href="{{ url()->current() }}" class="ss-btn ss-btn--secondary ss-btn--sm"
                       title="{{ __('Reset') }}">
                        <i class="ti ti-refresh" aria-hidden="true"></i>
                        <span class="ss-visually-hidden">{{ __('Reset') }}</span>
                    </a>
                </x-ss.report-filter>
            </div>
        </div>
        <div class="chart_data pc-dt-export">

        </div>
    </div>
@endsection
@push('custom-script')
    <script>
        if ($(".multi-select").length > 0) {
            $($(".multi-select")).each(function(index, element) {
                var id = $(element).attr('id');
                var multipleCancelButton = new Choices(
                    '#' + id, {
                        removeItemButton: true,
                    }
                );
            });
        }
        $(document).ready(function() {

            function fetchData(value, date) {
                var csrfToken = $('meta[name="csrf-token"]').attr('content');
                var selectedCategory = $('#category_id').val();
                $.ajax({
                    type: "GET",
                    url: '{{ route('reports.category.order.chart') }}',
                    async: true,
                    data: {
                        _token: csrfToken,
                        chart_data: value,
                        Date: date,
                        selectedCategory: selectedCategory,
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        $('.chart_data').html(data.html);
                        var seriesData = [];

                        data.NetSaleTotal.forEach(function(category) {
                            var series = {
                                name: category.name,
                                type: 'column',
                                data: category.data,
                            };
                            seriesData.push(series);
                        });

                        (function() {
                            var options = {
                                series: seriesData,
                                chart: {
                                    height: 350,
                                    type: 'line',
                                    toolbar: {
                                        show: false
                                    },
                                },
                                stroke: {
                                    width: [0, 4]
                                },
                                colors: ['#F4B41ABF', 'var(--theme-color)','#D80027','#002333'],

                                xaxis: {
                                    categories: data.monthList,
                                }
                            };

                            var chart = new ApexCharts(document.querySelector(".traffic-chart"),
                                options);
                            chart.render();
                        })();
                    },
                    error: function(error) {
                        $('#loader').fadeOut();
                    }
                });
            }

            /* `aria-pressed` is the only record of the selection — the CSS selects on it too,
               so the announced state and the visible state cannot drift apart. The old parallel
               `active` class is what let this page look selected to a sighted user and selected
               to nobody else. */
            $(".chart-data").on("click", function() {
                $(".chart-data").attr('aria-pressed', 'false');
                $(this).attr('aria-pressed', 'true');
                var value = $(this).val();
                var date = $('#category-date').val();

                fetchData(value, date);
            });

            fetchData($('.chart-data[aria-pressed="true"]').val(), $('#category-date').val());

            /* Generate is no longer one of the period buttons, so it needs its own handler:
               it runs the `date-wise` branch against whatever is in the date field, which is
               what its `value` said when it was wearing the `chart-data` class. */
            $('.generate_button').on('click', function() {
                fetchData($(this).val(), $('#category-date').val());
            });

            $('.filter-apply-btn').on('click', function() {
                var selectedCategory = $('#category_id').val();

                var selectedValue = $('.chart-data[aria-pressed="true"]').val();
                var date = $('#category-date').val();

                fetchData(selectedValue, date, selectedCategory);
            });

            $('#category_id').on('change', function() {
                var selectedCategory = $(this).val();
            });

        });
    </script>
    <script>
        $(document).ready(function() {
            $('.exportChartButton').click(function() {
                $.ajax({
                    url: '{{ route('category.order.export') }}',
                    method: 'GET',
                    success: function(data) {
                        $('#loader').fadeOut();
                        if (data.hasOwnProperty("monthList") && data.hasOwnProperty(
                                "NetSaleTotal")) {
                            var csvContent =
                                "data:text/csv;charset=utf-8,Duration,Category Name,Total Net Sale\n";

                            // Assuming data.NetSaleTotal is an array of objects with "name" and "data" properties
                            for (var i = 0; i < data.monthList.length; i++) {
                                var categoryName = data.NetSaleTotal[0]
                                .name; // Assuming you want the "main-category" name
                                var netSale = data.NetSaleTotal[0].data[
                                i]; // Assuming you want the data for "main-category"

                                csvContent +=
                                    `${data.monthList[i]},${categoryName},${netSale}\n`;
                            }

                            var encodedUri = encodeURI(csvContent);
                            var link = document.createElement("a");
                            link.setAttribute("href", encodedUri);
                            link.setAttribute("download", "exported_data.csv");
                            document.body.appendChild(link);
                            link.click();
                        } else {
                            console.error("Data is not in the expected format:", data);
                        }

                    }
                });
            });


            $('.generate_button').prop('disabled', true);
            $('#category-date').on('change', function() {
                // Check if a date is selected
                if ($(this).val() !== '') {
                    // Enable the "Generate" button
                    $('.generate_button').prop('disabled', false);
                } else {
                    // Disable the "Generate" button if no date is selected
                    $('.generate_button').prop('disabled', true);
                }
            });
        });
    </script>
@endpush
