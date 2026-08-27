@extends('layouts.app')

@section('page-title')
    {{ __('Top Sales Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Top Sales Report') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        <a href="#" class="btn btn-sm btn-primary btn-icon" onclick="saveAsPDF()"  data-bs-toggle="tooltip"  data-bs-original-title="{{ __('Download') }}">
            <i class="ti ti-download"></i>
        </a>
    </div>
@endsection

@section('content')
    <div class="row" id="printableArea">
        <!-- Card 1 with Chart -->
        <div class="col-md-6 mb-4">
            <div class="card h-100 m-0">
                <div class="card-header">
                    <h5 class="card-title"><b>{{ __('Top Selling Products') }}</b></h5>
                </div>
                <div class="card-body" style="overflow-x: overlay;">
                    <div id="topSellingProductsChart"></div>
                </div>
            </div>
        </div>

        <!-- Empty Card 2 -->
        <div class="col-md-6 mb-4">
            <div class="card h-100 m-0">
                <div class="card-header">
                    <h5 class="card-title"><b>{{ __('Top Selling Category') }}</b></h5>
                </div>
                <div class="card-body" style="overflow-x: overlay;">
                    <div id="topSellingsecondProductsChart"></div>
                </div>
            </div>
        </div>

        <!-- Empty Card 3 -->
        <div class="col-md-6 mb-4">
            <div class="card h-100 m-0">
                <div class="card-header">
                    <h5 class="card-title"><b>{{ __('Top Selling Brand') }}</b></h5>
                </div>
                <div class="card-body" style="overflow-x: overlay;">

                    <div id="topSellingBrandChart"></div>
                </div>
            </div>
        </div>

        <!-- Empty Card 4 -->
        <div class="col-md-6 mb-4">
            <div class="card h-100 m-0">
                <div class="card-header">
                    <h5 class="card-title"><b>{{ __('Top Payment Method') }}</b></h5>
                </div>
                <div class="card-body" style="overflow-x: overlay;">
                    <div id="paymentMethodsChart"></div>
                </div>
            </div>
        </div>
    </div>

    @push('custom-script')
    <script src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
    <script>
        function saveAsPDF() {
            var element = document.getElementById('printableArea');
            var opt = {
                margin: 0.3,
                filename: "Top Sales Report",
                image: {type: 'jpeg', quality: 1},
                html2canvas: {scale: 4, dpi: 72, letterRendering: true},
                jsPDF: {unit: 'in', format: 'A2'}
            };
            html2pdf().set(opt).from(element).save();
        }

        // Common chart options for all charts
        var commonOptions = {
            chart: {
                width: '100%', // Full width of the container
                height: '300px', // Fixed consistent height
                type: 'pie',
            },
            responsive: [{
                breakpoint: 480,
                options: {
                    chart: {
                        width: '100%',
                        height: '300px',
                    },
                    legend: {
                        position: 'bottom'
                    }
                }
            }],
            noData: {
                text: "@lang('No Data Found')",
                align: 'center',
                verticalAlign: 'middle',
                offsetX: 0,
                offsetY: 0,
                style: {
                    color: '#000',
                    fontSize: '14px',
                    fontFamily: undefined
                }
            }
        };

        // Controller-side (or Blade logic before the script block)
        var productCounts = @json(count($productCounts) > 0 ? $productCounts : []);
        var productNames = @json(count($productNames) > 0 ? $productNames : []);
        // Top Selling Products Chart
        var topSellingProductsOptions = {
            ...commonOptions,
            series: productCounts,
            labels: productNames
        };
        new ApexCharts(document.querySelector("#topSellingProductsChart"), topSellingProductsOptions).render();

        // Top Selling Category Chart
        var topSellingCategoryOptions = {
            ...commonOptions,
            series: @json($top_sales->pluck('total_sale')->all()),
            labels: @json($top_sales->pluck('sale_name')->all())
        };
        new ApexCharts(document.querySelector("#topSellingsecondProductsChart"), topSellingCategoryOptions).render();

        // Top Selling Brand Chart
        var topSellingBrandOptions = {
            ...commonOptions,
            series: @json($top_brand_sales->pluck('total_sale')->all()),
            labels: @json($top_brand_sales->pluck('sale_name')->all())
        };
        new ApexCharts(document.querySelector("#topSellingBrandChart"), topSellingBrandOptions).render();

        // Payment Methods Chart
        var paymentMethodsOptions = {
            ...commonOptions,
            series: @json($paymentMethods->values()->all()),
            labels: @json($paymentMethods->keys()->all())
        };
        new ApexCharts(document.querySelector("#paymentMethodsChart"), paymentMethodsOptions).render();
    </script>
    @endpush
@endsection
