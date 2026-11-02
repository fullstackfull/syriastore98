@extends('layouts.app')

@section('page-title')
    {{ __('Order Status Report') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Order Status Report') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        {{-- Was `<a href="#" onclick="saveAsPDF()">` whose only text was a `data-bs-original-title`
             attribute — which is not an accessible name, and which Bootstrap's tooltip rewrites on
             init anyway. A control that does something is a <button>, and it says what it does. --}}
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
        THREE CARDS, ONE DATASET, ONE HEADING.
        =========================================================================================

        This screen drew the same nine numbers three times — a pie, a bar chart and an AREA
        chart — and gave all three cards the identical title "Order Status Report". A sighted
        merchant could not tell which was which; a screen-reader user heard the same heading
        three times over three empty divs, because not one of the three ApexCharts had any
        accessible representation.

        The area chart was the worst of them: an area chart draws a CONTINUOUS quantity, and
        these are categories. The line sloping from "Cancel" to "Return" says there is something
        between them, and there is not.

        So: one picture and one table. `<x-ss.bar-chart>` carries the numbers as a real table
        with a caption and `scope` on both axes, and the bars are `aria-hidden` because they are
        a picture OF that table.
    --}}
    <div class="row" id="printableArea">
        <div class="col-12">
            <x-ss.card :title="__('Orders by status')"
                       :note="__('Every order the store has taken, grouped by its delivery status. Counts orders, not value.')">
                <x-ss.bar-chart
                    :labels="array_keys($orderStatusCounts)"
                    :values="array_values($orderStatusCounts)"
                    :title="__('Orders by status')"
                    :value-label="__('Orders')"
                    :column-label="__('Status')"
                    :period="$periodLabel" />

                <x-slot:footer>
                    {{-- THE PARTS MUST SUM TO THE TOTAL, and the reader must be able to check.
                         The old map covered seven of the nine statuses, so for a store using
                         partially-paid or pre-order the breakdown was quietly short and nothing
                         on the screen revealed it. --}}
                    <p class="ss-field__hint" style="margin:0">
                        {{ __('Total orders') }}:
                        <span class="ss-num">{{ number_format($orderStatusTotal) }}</span>
                        ({{ $periodLabel }})
                    </p>
                </x-slot:footer>
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
                filename: @json(__('Order Status Report')),
                image: {type: 'jpeg', quality: 1},
                html2canvas: {scale: 4, dpi: 72, letterRendering: true},
                jsPDF: {unit: 'in', format: 'A2'}
            };

            /* The numbers live in a <details> that is closed by default, so a PDF taken as-is
               would be a picture of bars with no values. Open it for the capture and put it
               back afterwards. */
            var data = element.querySelectorAll('details.ss-chart-data');
            var wasOpen = [];
            data.forEach(function (d, i) { wasOpen[i] = d.open; d.open = true; });

            html2pdf().set(opt).from(element).save().then(function () {
                data.forEach(function (d, i) { d.open = wasOpen[i]; });
            });
        }
    </script>
@endpush
