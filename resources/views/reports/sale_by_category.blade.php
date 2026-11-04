@extends('layouts.app')

@section('page-title')
    {{ __('Sales Category Report') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Sales Category Report') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        {{-- A <button>, and it says what it does. This was an <a href="javascript:;"> whose only
             behaviour is the jQuery .click() below, so Space did not activate it and the dead href
             was announced as the name. Its name then came from `title`, which is not a name here:
             bundled Bootstrap is 5.1.3, whose _fixTitle guards the title->aria-label copy on an
             UNTRIMMED textContent -- the whitespace between the tags is truthy, so the copy was
             skipped while setAttribute("title", "") in the same expression ran anyway. The glyph
             was all that was left, and Chromium reads it as a private-use character.
             The tooltip is kept on purpose: it does NOT break, because _fixTitle moves the title
             into data-bs-original-title first, so a sighted mouse user still gets it.
             __('Export to CSV') in BOTH places, not __('Export'): a differing pair is a 2.5.3
             label-in-name mismatch, and ar.json maps 'Export' to الصادرات -- "exports", the
             commerce noun, not the verb. --}}
        <button type="button" class="btn btn-sm btn-primary btn-icon exportChartButton"
                title="{{ __('Export to CSV') }}" data-bs-toggle="tooltip" data-bs-placement="top">
            <i class="ti ti-file-export" aria-hidden="true"></i>
            <span class="ss-visually-hidden">{{ __('Export to CSV') }}</span>
        </button>
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

                        {{-- A failed export used to be entirely silent: no file, no message, and not even a
                             spinner -- #loader is display:none and nothing on this path ever shows it. The only
                             handling of a bad payload was console.error, which no merchant reads. Five of the six
                             handlers answered HTTP 500 on a session that had never fetched a chart; the sixth
                             returned an empty-string sentinel that passed every hasOwnProperty guard and
                             downloaded a file with nothing but a header row.

                             FOUR FAILURES, FOUR INSTRUMENTS, and none of them is redundant:
                               * error: catches the transport failure -- a dropped network, a session that expired
                                 mid-visit. It earns its place however well the handler is guarded.
                               * Array.isArray separates a real series from that '' sentinel BY TYPE.
                                 hasOwnProperty was true for the string, so it admitted what the loop cannot read.
                               * the length branch is what stops a header-only download, and it is NOT optional.
                                 The handler now defaults every key to [], so Array.isArray([]) is TRUE and the
                                 type guard passes on its own -- without this branch all six screens would hand
                                 over a header-only file in silence, turning one defect into six. It is also the
                                 right answer for a genuinely empty period: a header row is not an export.
                               * try/catch, because error: CANNOT see a throw inside success -- and
                                 data.NetSaleTotal[0].name is reachable with NetSaleTotal empty.
                             --}}
                        if (!Array.isArray(data.monthList) || !Array.isArray(data.NetSaleTotal)) {
                            show_toastr(@json(__('Error')), @json(__('Something went wrong. Please try again.')), 'error');
                            return;
                        }

                        if (data.monthList.length === 0) {
                            show_toastr(@json(__('Error')), @json(__('No records found')), 'error');
                            return;
                        }

                        {{-- THIRD, AND AFTER THE EMPTY-PERIOD BRANCH ON PURPOSE. The loop below reads
                             data.NetSaleTotal[0].name, so an empty series is a TypeError at i=0 -- which
                             error: cannot see, because it is thrown inside success. It is NOT an empty
                             period though: a period with no orders still yields one series of zeroes, so
                             [] here means no brand/category resolved at all. Folding it into the type
                             guard above (as first drafted) made an unprimed session -- the ordinary state
                             of a bookmarked report, where monthList is [] too -- report "Something went
                             wrong" instead of "No records found". Measured by driving the rendered
                             handler against the real payload shapes. --}}
                        if (data.NetSaleTotal.length < 1) {
                            show_toastr(@json(__('Error')), @json(__('Something went wrong. Please try again.')), 'error');
                            return;
                        }

                        try {
                            {{-- Translated header, CSV-quoted. A merchant reading the admin in Arabic was
                                 handed a CSV with English column names, and the file carried no
                                 byte-order mark even though column 1 is already an Arabic month
                                 name from ReportController::yearMonth(). The U+FEFF prefix is
                                 that mark -- encodeURI turns it into %EF%BB%BF.
                                 The quoting is load-bearing and only in English: encodeURI does
                                 NOT escape a comma, and the settled label "Order value, before
                                 discounts" carries one in en.json and in the 14 locales that
                                 fall back to it, so an unquoted join adds a column and
                                 misaligns every field after it. ar.json translates it
                                 comma-free, so an Arabic-only check of this would pass. --}}
                            var header = [
                                @json(__('Duration')),
                                @json(__('Category')),
                                @json(__('Order value')),
                            ].map(function(h) {
                                return '"' + String(h).replace(/"/g, '""') + '"';
                            }).join(',');

                            var csvContent = "data:text/csv;charset=utf-8,﻿" + header + "\n";

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
                        } catch (e) {
                            show_toastr(@json(__('Error')), @json(__('Something went wrong. Please try again.')), 'error');
                        }
                    },
                    error: function(xhr) {
                        $('#loader').fadeOut();
                        show_toastr(@json(__('Error')),
                            (xhr && xhr.responseJSON && xhr.responseJSON.message)
                                ? xhr.responseJSON.message
                                : @json(__('Something went wrong. Please try again.')), 'error');
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
