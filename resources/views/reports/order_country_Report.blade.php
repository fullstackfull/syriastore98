@extends('layouts.app')

@section('page-title')
    {{ __('Top Selling By Country Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Top Selling By Country Report') }}</li>
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

@push('css')
    <link rel="stylesheet" href="{{ asset('assets/css/svgMap.min.css') }}">
@endpush

@section('content')
    @include('layouts.includes.store-syria-ds')

    {{--
        TWO NUMBERS THAT COUNT DIFFERENT THINGS, PRINTED SIDE BY SIDE.
        =========================================================================================

        The header read `{{ __('Total Order Is') }} : {{ $total }}` beside a per-country chart —
        but `$total` is every order the store has taken, while the chart counts only orders with
        a billing-detail row whose country resolves. The columns therefore sum to something at or
        below the printed total, with nothing saying why, and a merchant reading it concludes
        either the chart is broken or their orders have gone missing.

        Both numbers are named for what they are now, and the gap between them is stated rather
        than left to be inferred. A country the hardcoded map does not know is counted too: it
        used to be dropped with no `else` at all, so its orders vanished from the map and from
        any sum taken off it.

        AND THE SAME THREE-CARDS-ONE-HEADING SHAPE AS THE STATUS REPORT: a pie, a bar and an area
        chart of the same series, all three titled "Top Selling By Country Report", none with any
        accessible representation. One bar chart with a real table, plus the map — which is kept
        because geography is a genuinely different reading of the data, and marked `aria-hidden`
        because the table beside it says the same thing better.
    --}}
    <div class="row" id="printableArea">
        <div class="col-xl-7 mb-4">
            <x-ss.card :title="__('Orders by country')"
                       :note="__('Counts orders that carry a billing country. Counts orders, not value.')">
                <x-ss.bar-chart
                    :labels="$billingData->pluck('country_name')->all()"
                    :values="$billingData->pluck('total_orders')->all()"
                    :title="__('Orders by country')"
                    :value-label="__('Orders')"
                    :column-label="__('Country')"
                    :period="$periodLabel" />

                <x-slot:footer>
                    <p class="ss-field__hint" style="margin:0">
                        {{ __('Orders with a billing country') }}:
                        <span class="ss-num">{{ number_format($withCountry) }}</span>
                        {{ __('of') }}
                        <span class="ss-num">{{ number_format($total) }}</span>
                        {{ __('total orders') }} ({{ $periodLabel }}).
                        @if ($total > $withCountry)
                            {{ __('The rest have no billing country recorded.') }}
                        @endif
                    </p>
                </x-slot:footer>
            </x-ss.card>
        </div>

        <div class="col-xl-5 mb-4">
            <x-ss.card :title="__('Countries')"
                       :note="__('A picture of the same figures. The table above is the readable version.')">
                {{-- The map is a picture of the table in the card beside it, so it is hidden from
                     assistive technology for the same reason the bars are. --}}
                <div id="map" aria-hidden="true"></div>

                @if ($unmapped > 0)
                    <p class="ss-field__hint">
                        <span class="ss-num">{{ number_format($unmapped) }}</span>
                        {{ __('orders are in countries this map cannot draw. They are in the table, not on the map.') }}
                    </p>
                @endif
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
                filename: @json(__('Top Selling By Country Report')),
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

    <script src="{{ asset('assets/js/svgMap.min.js') }}"></script>
    <script>
        new svgMap({
            targetElementID: 'map',
            data: {
                data: {
                    pageviews: {
                        name: @json(__('Orders')),
                        format: '{0} ' + @json(__('Orders')),
                        thousandSeparator: ',',
                    },
                },
                applyData: 'pageviews',
                values: {!! json_encode($formattedData) !!},
            },
            colorMin: '#a2eeed',
            colorMax: '#19c8c7',
            flagType: 'emoji',
        });
    </script>
@endpush
