@extends('layouts.app')
@section('page-title')
    {{ __('Campaigns Analytics') }}
@endsection
@section('title')
    {{ __('Campaigns Analytics') }}
@endsection
@section('breadcrumb')
    @if (Auth::user() && Auth::user()->type == 'admin')
        <li class="breadcrumb-item active" aria-current="page"><a
                href="{{ route('campaigns.index') }}">{{ __('Campaigns') }}</a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">{{ __('Campaigns Analytics') }}</li>
    @else
        <li class="breadcrumb-item active" aria-current="page"><a
                href="{{ route('campaigns.index') }}">{{ __('Campaigns') }}</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ __('Campaigns Analytics') }}</li>
    @endif
@endsection
@section('content')
    <div class="row">
        <div class="col-xl-6 col-md-12">
            <div class="card">
                <div class="card-header">
                    <div class="float-end">
                        <span class="mb-0 float-right">{{ __('Total Days') }}</span>
                    </div>
                    <h5>{{ __('Campaign') }}</h5>
                    <small></small>
                </div>
                <div class="card-body">
                    <div id="campaignChartDate"></div>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
    <script src="{{ asset('js/purpose.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/apexcharts.js') }}"></script>
    <script>
        var devicearray = {!! json_encode($devicearray) !!};

        var options = {
            colors: ['#FFB6C1', '#B0C4DE'],

            chart: {
                height: 400,
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
                    columnWidth: '30%',
                    borderRadius: 10,
                    dataLabels: {
                        position: 'top',
                    },
                }
            },
            stroke: {
                show: true,
                width: 1,
                colors: ['#fff']
            },
            series: [{
                    name: 'Total Days',
                    data: devicearray.total_days,
                },
                {
                    name: 'Total Cost',
                    data: devicearray.total_cost,
                }
            ],

            xaxis: {
                labels: {
                    style: {
                        colors: '#293240',
                        fontSize: '12px',
                        fontFamily: "sans-serif",
                        cssClass: 'apexcharts-xaxis-label',
                    },
                },
                axisBorder: {
                    show: false
                },
                axisTicks: {
                    show: true,
                    borderType: 'solid',
                    color: '#f2f2f2',
                    height: 6,
                    offsetX: 0,
                    offsetY: 0
                },
                title: {
                    text: 'Store'
                },
                categories: devicearray.label,
            },
            yaxis: {
                labels: {
                    style: {
                        color: '#f2f2f2',
                        fontSize: '12px',
                        fontFamily: "Open Sans",
                    },
                },
                axisBorder: {
                    show: false
                },
                axisTicks: {
                    show: true,
                    borderType: 'solid',
                    color: '#f2f2f2',
                    height: 6,
                    offsetX: 0,
                    offsetY: 0
                }
            },
            fill: {
                type: 'solid',
                opacity: 1
            },
            markers: {
                size: 4,
                opacity: 0.7,
                strokeColor: "#000",
                strokeWidth: 3,
                hover: {
                    size: 7,
                }
            },
            grid: {
                borderColor: '#f2f2f2',
                strokeDashArray: 5,
            },
            dataLabels: {
                enabled: false
            }

        };

        var chart = new ApexCharts(document.querySelector("#campaignChartDate"), options);
        chart.render();
    </script>
@endpush
