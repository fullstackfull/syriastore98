@extends('layouts.app')

@section('page-title')
    {{ __('Top Sales Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Top Sales Report') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        <button type="button" class="btn btn-sm btn-primary btn-icon" onclick="saveAsPDF()"
                title="{{ __('Download') }}" data-bs-toggle="tooltip" data-bs-placement="top">
            <i class="ti ti-download" aria-hidden="true"></i>
            <span class="ss-visually-hidden">{{ __('Download') }}</span>
        </button>
    </div>
@endsection

@section('content')
    @include('layouts.includes.store-syria-ds')

    {{--
        FOUR PIE CHARTS WITH NOTHING BEHIND THEM.
        =========================================================================================

        Each of the four was an ApexCharts pie in an empty div: no table, no summary, no
        `role="img"` label. A merchant using a screen reader got four empty cards. And a pie is
        the wrong shape for "top five" anyway — it claims the five slices are the whole, when
        they are the top of a longer list.

        They are bar charts with real tables now: the five are the top five, the table says what
        each one counted, and nothing implies they add up to everything.

        THE PRODUCT CHART WAS ALSO PAIRING THE WRONG LABELS WITH THE WRONG COUNTS — see
        `ReportController::top_product()`. That was invisible on this screen precisely because a
        pie with no table gives the reader nothing to check against.
    --}}
    <div class="row" id="printableArea">
        <div class="col-xl-6 mb-4">
            <x-ss.card :title="__('Top Selling Products')"
                       :note="__('The five products appearing in the most orders. Counts orders, not quantity or value.')">
                <x-ss.bar-chart
                    :labels="$productNames"
                    :values="$productCounts"
                    :title="__('Top Selling Products')"
                    :value-label="__('Orders')"
                    :column-label="__('Product')"
                    :period="$periodLabel" />
            </x-ss.card>
        </div>

        <div class="col-xl-6 mb-4">
            <x-ss.card :title="__('Top Selling Category')"
                       :note="__('Categories by the number of distinct orders containing one of their products.')">
                <x-ss.bar-chart
                    :labels="$top_sales->pluck('sale_name')->all()"
                    :values="$top_sales->pluck('total_sale')->all()"
                    :title="__('Top Selling Category')"
                    :value-label="__('Orders')"
                    :column-label="__('Category')"
                    :period="$periodLabel" />
            </x-ss.card>
        </div>

        <div class="col-xl-6 mb-4">
            <x-ss.card :title="__('Top Selling Brand')"
                       :note="__('Brands by the number of distinct orders containing one of their products.')">
                <x-ss.bar-chart
                    :labels="$top_brand_sales->pluck('sale_name')->all()"
                    :values="$top_brand_sales->pluck('total_sale')->all()"
                    :title="__('Top Selling Brand')"
                    :value-label="__('Orders')"
                    :column-label="__('Brand')"
                    :period="$periodLabel" />
            </x-ss.card>
        </div>

        <div class="col-xl-6 mb-4">
            <x-ss.card :title="__('Top Payment Method')"
                       :note="__('Orders grouped by the method they were paid with.')">
                {{-- The keys are raw `orders.payment_type` values, which went to the chart
                     untranslated — an Arabic merchant read English method names on an Arabic
                     page. A method with no translation falls back to its own name rather than
                     to an empty label. --}}
                <x-ss.bar-chart
                    :labels="$paymentMethods->keys()->map(fn ($m) => __($m ?: 'Unknown'))->all()"
                    :values="$paymentMethods->values()->all()"
                    :title="__('Top Payment Method')"
                    :value-label="__('Orders')"
                    :column-label="__('Payment method')"
                    :period="$periodLabel" />
            </x-ss.card>
        </div>
    </div>
@endsection

@push('custom-script')
    <script src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
    <script>
        function saveAsPDF() {
            var element = document.getElementById('printableArea');
            var opt = {
                margin: 0.3,
                filename: @json(__('Top Sales Report')),
                image: {type: 'jpeg', quality: 1},
                html2canvas: {scale: 4, dpi: 72, letterRendering: true},
                jsPDF: {unit: 'in', format: 'A2'}
            };

            /* Open the numbers before capturing, or the PDF is a picture of bars with no values. */
            var data = element.querySelectorAll('details.ss-chart-data');
            var wasOpen = [];
            data.forEach(function (d, i) { wasOpen[i] = d.open; d.open = true; });

            html2pdf().set(opt).from(element).save().then(function () {
                data.forEach(function (d, i) { d.open = wasOpen[i]; });
            });
        }
    </script>
@endpush
