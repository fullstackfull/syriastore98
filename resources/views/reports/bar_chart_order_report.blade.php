@extends('layouts.app')

@section('page-title')
    {{ __('Sales Report') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Sales Report') }}</li>
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
        {{-- Real navigation, so it stays an <a> with its route. Its icon was bare too, so
             Bootstrap emptied this title as well and it had no name either. --}}
        <a href="{{ route('reports.order_report') }}" class="btn btn-sm btn-primary btn-icon"
            title="{{ __('Line Chart') }}" data-bs-toggle="tooltip" data-bs-placement="top">
            <i class="ti ti-chart-line" aria-hidden="true"></i>
            <span class="ss-visually-hidden">{{ __('Line Chart') }}</span>
        </a>
    </div>
@endsection
@section('content')
    <div class="row">
        <div class="col-xxl-12">
            <div class="card card-body">
                {{-- The period bar is `<x-ss.report-filter>`; this screen had its own copy, as
                     did five others, and every copy carried the same defects. See the component
                     for what they were. The empty `#deals-staff-report` div that sat inside the
                     `<ul>` here is gone with it: it had `height` and `width` ATTRIBUTES on a
                     `<div>`, which do nothing, and nothing ever drew into it. --}}
                <x-ss.report-filter id="orderbar" :label="__('Report period')"
                                    generate-value="date-wise" :generate-disabled="true" />
            </div>
        </div>
        <div class="chart_data pc-dt-export">

        </div>
    </div>
@endsection
@push('custom-script')
    <script>
        $(document).ready(function() {
            function fetchData(value, date) {
                var csrfToken = $('meta[name="csrf-token"]').attr('content');

                $.ajax({
                    type: "GET",
                    url: '{{ route('reports.order.chart.data') }}',
                    async: true,
                    data: {
                        _token: csrfToken,
                        chart_data: value,
                        Date: date,
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        $('.chart_data').html(data.html);
                        (function() {
                            var options = {
                                chart: {
                                    height: 250,
                                    type: 'area',
                                    toolbar: {
                                        show: false,
                                    },
                                },
                                dataLabels: {
                                    enabled: false
                                },
                                stroke: {
                                    width: 2,
                                    curve: 'smooth'
                                },
                                series: [{
                                    name: 'Total Gross Sale',
                                    data: data.GrossSaleTotal
                                }, {
                                    name: 'Total Average Gross Sale',
                                    data: data.AverageGrossSales
                                }, {
                                    name: 'Total Net Sale',
                                    data: data.NetSaleTotal
                                }, {
                                    name: 'Total Average Net Sale',
                                    data: data.AverageNetSales
                                }],
                                xaxis: {
                                    categories: data.monthList,
                                },
                                colors: ['#ffa21d', '#FF3A6E','#008FFB', '#00E396'],

                                grid: {
                                    strokeDashArray: 4,
                                },
                                legend: {
                                    show: false,
                                }
                            };
                            if (!areAllZeros()) {
                                options.yaxis = {
                                    title: {
                                        text: ''
                                    },
                                    labels: {
                                        formatter: function(value) {
                                            return value.toFixed(
                                                2); // Format to 2 decimal places
                                        }
                                    }
                                };
                            }

                            function areAllZeros() {
                                // Check if all data arrays contain only zeros
                                return (
                                    data.GrossSaleTotal.every(function(val) {
                                        return val === 0;
                                    }) &&
                                    data.AverageGrossSales.every(function(val) {
                                        return val === 0;
                                    }) &&
                                    data.NetSaleTotal.every(function(val) {
                                        return val === 0;
                                    }) &&
                                    data.AverageNetSales.every(function(val) {
                                        return val === 0;
                                    })
                                );
                            }

                            var chart = new ApexCharts(document.querySelector(".myChart"), options);
                            chart.render();
                        })();
                        (function() {
                            var options = {
                                series: [{
                                        name: 'Total charged of Shipping',
                                        data: data.ShippingTotal
                                    },
                                    {
                                        name: 'Total Worth of coupons used',
                                        data: data.CouponTotal
                                    }
                                ],
                                chart: {
                                    height: 350,
                                    type: 'line',
                                    toolbar: {
                                        show: false
                                    },
                                },
                                forecastDataPoints: {
                                    count: 7
                                },
                                stroke: {
                                    width: 5,
                                    curve: 'smooth'
                                },
                                xaxis: {
                                    categories: data.monthList,
                                    tickAmount: 10,
                                },
                                colors: ['#F4B41A', 'var(--theme-color)'],
                                title: {
                                    text: '',
                                    align: 'left',
                                    style: {
                                        fontSize: "16px",
                                        color: '#666'
                                    }
                                },
                                fill: {
                                    type: 'gradient',
                                    gradient: {
                                        shade: 'dark',
                                        gradientToColors: ['#FDD835'],
                                        shadeIntensity: 1,
                                        type: 'horizontal',
                                        opacityFrom: 1,
                                        opacityTo: 1,
                                        stops: [0, 100, 100, 100]
                                    },
                                }
                            };

                            var chart = new ApexCharts(document.querySelector(".traffic-chart"),
                                options);
                            chart.render();

                        })();

                        var options = {
                            series: [data.PurchasedItemTotal, data.TotalOrderCount],
                            chart: {
                                width: 350,
                                type: 'donut',
                            },
                            colors: ['#F4B41A', 'var(--theme-color)'],
                            labels: ['Total Items', 'Total Order'],
                            responsive: [{
                                breakpoint: 480,
                                options: {
                                    chart: {
                                        width: 230
                                    },
                                    legend: {
                                        position: 'bottom'
                                    }
                                }
                            }]
                        };
                        var chart = new ApexCharts(document.querySelector(".radialbar-charts"), options);
                        chart.render();
                    },
                    error: function(error) {
                        $('#loader').fadeOut();
                    }
                });
            }

            $(".chart-data").on("click", function() {
                $(".chart-data").attr('aria-pressed', 'false');
                $(this).attr('aria-pressed', 'true');
                var value = $(this).val();
                var date = $('#orderbar-date').val();

                fetchData(value, date);
            });

            /* Generate is no longer one of the period buttons, so it needs its own handler: it
               runs the `date-wise` branch against whatever is in the date field, which is what
               its `value` said when it was wearing the `chart-data` class. */
            $('.generate_button').on('click', function() {
                fetchData($(this).val(), $('#orderbar-date').val());
            });

            fetchData($('.chart-data[aria-pressed="true"]').val(), $('#orderbar-date').val());
        });
    </script>
    <script>
        $(document).ready(function() {
            $('.exportChartButton').click(function() {
                $.ajax({
                    url: '{{ route('order.barchart.reports.export') }}',
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
                        if (!Array.isArray(data.monthList)
                            || !Array.isArray(data.NetSaleTotal)
                            || !Array.isArray(data.AverageNetSales)
                            || !Array.isArray(data.GrossSaleTotal)
                            || !Array.isArray(data.AverageGrossSales)
                            || !Array.isArray(data.ShippingTotal)
                            || !Array.isArray(data.CouponTotal)) {
                            show_toastr(@json(__('Error')), @json(__('Something went wrong. Please try again.')), 'error');
                            return;
                        }

                        if (data.monthList.length === 0) {
                            show_toastr(@json(__('Error')), @json(__('No records found')), 'error');
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
                                @json(__('Order value')),
                                @json(__('Average order value')),
                                @json(__('Order value, before discounts')),
                                @json(__('Average order value, before discounts')),
                                @json(__('Shipping charged')),
                                @json(__('Coupon value applied')),
                            ].map(function(h) {
                                return '"' + String(h).replace(/"/g, '""') + '"';
                            }).join(',');

                            var csvContent = "data:text/csv;charset=utf-8,﻿" + header + "\n";
                            for (var i = 0; i < data.monthList.length; i++) {
                                csvContent +=
                                    `${data.monthList[i]},${data.NetSaleTotal[i]},${data.AverageNetSales[i]},${data.GrossSaleTotal[i]},${data.AverageGrossSales[i]},${data.ShippingTotal[i]},${data.CouponTotal[i]}\n`;
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
            $('#orderbar-date').on('change', function() {

                if ($(this).val() !== '') {
                    $('.generate_button').prop('disabled', false);
                } else {
                    $('.generate_button').prop('disabled', true);
                }
            });
        });
    </script>
@endpush
