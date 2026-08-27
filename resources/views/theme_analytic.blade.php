@extends('layouts.app')

@section('page-title')
    {{ __('Store Analytics') }}
@endsection

@push('css-page')
@endpush
@section('content')

    <div class="row">
        <div class="col-xxl-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h4>{{ __('Visitor') }}</h4>
                </div>
                <div class="card-body">
                    <div id="apex-storedashborad" data-color="primary" data-height="200"></div>
                </div>
            </div>
        </div>
        <div class="col-xxl-6 dash-data">
            <div class="card min-h-490 overflow-auto">
                <div class="card-header d-flex justify-content-between">
                    <h4>{{ __('Top URL') }}</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <th>{{ __('Page URL') }}</th>
                                <th class="text-end">{{ __('Views') }}</th>
                            </thead>
                            <tbody>
                                @if (count($visitor_url) > 0)
                                    @foreach ($visitor_url as $url)
                                        <tr>
                                            <td>
                                                <h6 class="m-0">
                                                    <a href="{{$url->url ?? '#'}}" target="_blank">
                                                        @php
                                                            $path = parse_url($url->url, PHP_URL_PATH);
                                                            $pageName = 'Unknown Page';
                                                            
                                                            if (str_contains($path, '/product-list')) $pageName = __('Product List');
                                                            elseif (str_contains($path, '/product/')) $pageName = __('Product Details');
                                                            elseif (str_contains($path, '/cart')) $pageName = __('Shopping Cart');
                                                            elseif (str_contains($path, '/checkout')) $pageName = __('Checkout');
                                                            elseif (str_contains($path, '/blog')) $pageName = __('Blog');
                                                            elseif (str_contains($path, '/article/')) $pageName = __('Blog Article');
                                                            elseif (str_contains($path, '/about')) $pageName = __('About Us');
                                                            elseif (str_contains($path, '/contact-us')) $pageName = __('Contact Us');
                                                            elseif (str_contains($path, '/faq')) $pageName = __('FAQ');
                                                            elseif (str_contains($path, '/collections')) $pageName = __('Collections');
                                                            elseif (str_contains($path, '/brand/')) $pageName = __('Brand Page');
                                                            elseif (str_contains($path, '/privacy-policy')) $pageName = __('Privacy Policy');
                                                            elseif (str_contains($path, '/track-order')) $pageName = __('Track Order');
                                                            elseif (str_contains($path, '/wishlist')) $pageName = __('Wishlist');
                                                            elseif (str_contains($path, '/search-product')) $pageName = __('Search Results');
                                                            elseif (str_contains($path, '/login')) $pageName = __('Login');
                                                            elseif (str_contains($path, '/register')) $pageName = __('Register');
                                                            elseif (str_contains($path, '/my-account')) $pageName = __('My Account');
                                                            elseif (str_contains($path, '/order')) $pageName = __('Orders');
                                                            elseif (str_contains($path, '/page/')) $pageName = __('Custom Page');
                                                            elseif (str_contains($path, '/market')) $pageName = __('Markte Place');
                                                            elseif (str_contains($path, '/support-ticket')) $pageName = __('Support Ticket');
                                                            elseif (str_contains($path, '/home') || preg_match('/\/[^\/]+\/?$/', $path)) $pageName = __('Home Page');
                                                        @endphp
                                                        {{ $pageName }}
                                                    </a>
                                                </h6>
                                            </td>
                                            <td class="text-end">
                                                <h6 class="m-0">{{ $url->total }}</h6>
                                            </td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="2">
                                            <h6 class="text-center">{{ __('No Data found') }}</h6>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card data-chart">
                <div class="card-header d-flex justify-content-between">
                    <h4>{{ __('Device') }}</h4>
                </div>
                <div class="card-body">
                    <div id="pie-storedashborad"></div>
                </div>
            </div>
        </div>
        <div class="col-xxl-6 dash-data">
            <div class="card min-h-490 overflow-auto data-chart">
                <div class="card-header d-flex justify-content-between">
                    <h4>{{ __('Platform') }}</h4>
                </div>
                <div class="card-body">
                    <div id="user_platform-chart"></div>
                </div>
            </div>
            <div class="card data-chart">
                <div class="card-header d-flex justify-content-between">
                    <h4>{{ __('Browser') }}</h4>
                </div>
                <div class="card-body">
                    <div id="pie-storebrowser"></div>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('custom-script')
    <script>
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
                    name: "{{ __('Total Page View') }}",
                    data: {!! json_encode($chartData['data']) !!}
                }, {
                    name: "{{ __('Unique Page View') }}",
                    data: {!! json_encode($chartData['unique_data']) !!}
                }],

                xaxis: {
                    categories: {!! json_encode($chartData['label']) !!},
                    title: {
                        text: 'Days'
                    }
                },
                colors: ['#75DA48', '#F4B41A'],

                grid: {
                    strokeDashArray: 4,
                },
                legend: {
                    show: false,
                },
                yaxis: {
                    tickAmount: 3,
                }
            };
            var chart = new ApexCharts(document.querySelector("#apex-storedashborad"), options);
            chart.render();
        })();

        var deviceData = {!! json_encode($devicearray['data']) !!};
        if (deviceData.length === 0) {
            document.querySelector("#pie-storedashborad").innerHTML = '<p class="text-center">No Data Found</p>';
        } else {
            var options = {
                series: {!! json_encode($devicearray['data']) !!},
                chart: {
                    width: 350,
                    type: 'donut',
                },
                colors: ["#10B77F", "#F4B41A", "#F4614D", "#F1F1F1"],
                labels: {!! json_encode($devicearray['label']) !!},
                responsive: [{
                    breakpoint: 480,
                    options: {
                        chart: {
                            width: 200
                        },
                        legend: {
                            position: 'bottom',
                        }
                    }
                }]
            };
            var chart = new ApexCharts(document.querySelector("#pie-storedashborad"), options);
            chart.render();
        }


        var deviceData = {!! json_encode($browserarray['data']) !!};
        if (deviceData.length === 0) {
            document.querySelector("#pie-storebrowser").innerHTML = '<p class="text-center">No Data Found</p>';
        } else {
            var options = {
                series: {!! json_encode($browserarray['data']) !!},
                chart: {
                    width: 350,
                    type: 'donut',
                },
                colors: ["#10B77F", "#F4B41A", "#F4614D", "#F1F1F1"],
                labels: {!! json_encode($browserarray['label']) !!},
                responsive: [{
                    breakpoint: 480,
                    options: {
                        chart: {
                            width: 200
                        },
                        legend: {
                            position: 'bottom',
                        }
                    }
                }]
            };
            var chart = new ApexCharts(document.querySelector("#pie-storebrowser"), options);
            chart.render();
        }
    </script>
    <script>
       document.addEventListener('DOMContentLoaded', function () {
            // Define PurposeStyle globally
            window.PurposeStyle = function () {
                var e = getComputedStyle(document.body);
                return {
                    colors: {
                        gray: {
                            100: "#f6f9fc", 200: "#e9ecef", 300: "#dee2e6", 400: "#ced4da", 500: "#adb5bd", 
                            600: "#8898aa", 700: "#525f7f", 800: "#32325d", 900: "#212529"
                        },
                        theme: {
                            primary: e.getPropertyValue("--primary") ? e.getPropertyValue("--primary").replace(" ", "") : "#6e00ff",
                            info: e.getPropertyValue("--info") ? e.getPropertyValue("--info").replace(" ", "") : "#00B8D9",
                            success: e.getPropertyValue("--success") ? e.getPropertyValue("--success").replace(" ", "") : "#36B37E",
                            danger: e.getPropertyValue("--danger") ? e.getPropertyValue("--danger").replace(" ", "") : "#FF5630",
                            warning: e.getPropertyValue("--warning") ? e.getPropertyValue("--warning").replace(" ", "") : "#FFAB00",
                            dark: e.getPropertyValue("--dark") ? e.getPropertyValue("--dark").replace(" ", "") : "#212529"
                        },
                        transparent: "transparent"
                    },
                    fonts: {
                        base: "Nunito"
                    }
                };
            }

            // Initialize PurposeStyle globally
            window.PurposeStyle = PurposeStyle();

            // Now initialize the chart after PurposeStyle is ready
            var WorkedHoursChart = function() {
                var $chart = $('#user_platform-chart');

                function init($this) {
                    var userPlatformOptions = {
                        chart: {
                            width: '100%',
                            type: 'bar',
                            zoom: {
                                enabled: false
                            },
                            toolbar: {
                                show: false
                            },
                            shadow: {
                                enabled: false,
                            },
                        },
                        plotOptions: {
                            bar: {
                                horizontal: false,
                                distributed: true,
                                columnWidth: '25%',
                                borderRadius: 12,
                                endingShape: 'rounded'
                            },
                        },
                        colors: ["#10B77F", "#F4B41A", "#F4614D", "#F1F1F1"],
                        stroke: {
                            show: true,
                            width: 2,
                            colors: ['transparent']
                        },
                        series: [{
                            name: 'Platform',
                            data: {!! json_encode($platformarray['data']) !!},
                        }],
                        xaxis: {
                            labels: {
                                style: {
                                    colors: PurposeStyle.colors.gray[600],  // Using PurposeStyle
                                    fontSize: '14px',
                                    fontFamily: PurposeStyle.fonts.base,   // Using PurposeStyle
                                    cssClass: 'apexcharts-xaxis-label',
                                },
                            },
                            axisBorder: {
                                show: false
                            },
                            axisTicks: {
                                show: true,
                                borderType: 'solid',
                                color: PurposeStyle.colors.gray[300],  // Using PurposeStyle
                                height: 6,
                                offsetX: 0,
                                offsetY: 0
                            },
                            title: {
                                text: '{{ __('Platform') }}'
                            },
                            categories: {!! json_encode($platformarray['label']) !!},
                        },
                        yaxis: {
                            labels: {
                                style: {
                                    color: PurposeStyle.colors.gray[600],  // Using PurposeStyle
                                    fontSize: '12px',
                                    fontFamily: PurposeStyle.fonts.base,   // Using PurposeStyle
                                },
                            },
                            axisBorder: {
                                show: false
                            },
                            axisTicks: {
                                show: true,
                                borderType: 'solid',
                                color: PurposeStyle.colors.gray[300],  // Using PurposeStyle
                                height: 6,
                                offsetX: 0,
                                offsetY: 0
                            }
                        },
                        fill: {
                            type: 'solid',
                            opacity: 1
                        },
                        grid: {
                            borderColor: PurposeStyle.colors.gray[300],  // Using PurposeStyle
                            strokeDashArray: 5,
                        },
                        dataLabels: {
                            enabled: false
                        }
                    };

                    var height = $this.data().height;
                    userPlatformOptions.chart.height = height ? height : 350;
                    var user_platform = new ApexCharts($this[0], userPlatformOptions);

                    setTimeout(function() {
                        user_platform.render();
                    }, 300);
                }

                if ($chart.length) {
                    $chart.each(function() {
                        init($(this));
                    });
                }
            };

            // Call the chart initialization after DOM is fully loaded
            WorkedHoursChart();
        });
    </script>
@endpush