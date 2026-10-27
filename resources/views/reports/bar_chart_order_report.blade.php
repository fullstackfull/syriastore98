@extends('layouts.app')

@section('page-title')
    {{ __('Sales Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Sales Report') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        <a href="javascript:;" class="btn btn-sm btn-primary btn-icon exportChartButton" title="{{ __('Export') }}"
            data-bs-toggle="tooltip" data-bs-placement="top">
            <i class="ti ti-file-export"></i>
        </a>
        <a href="{{ route('reports.order_report') }}" class="btn btn-sm btn-primary btn-icon"
            title="{{ __('Line Chart') }}" data-bs-toggle="tooltip" data-bs-placement="top">
            <i class="ti ti-chart-line"></i>
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
                        if (data.hasOwnProperty("monthList") && data.hasOwnProperty("NetSaleTotal") &&
                            data
                            .hasOwnProperty("AverageNetSales") && data.hasOwnProperty(
                                "GrossSaleTotal") && data.hasOwnProperty("AverageGrossSales") && data.hasOwnProperty(
                                "ShippingTotal") && data.hasOwnProperty("CouponTotal")) {
                            var csvContent =
                                "data:text/csv;charset=utf-8,Duration,Total Net Sale ,Total Average Net Sale , Total Gross Sale ,Total Average Gross Sale ,Total Shipping Charge ,Total Worth of coupon \n";
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
                        } else {
                            console.error("Data is not in the expected format:", data);
                        }
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
