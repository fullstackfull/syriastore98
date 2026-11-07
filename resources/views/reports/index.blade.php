@extends('layouts.app')

@section('page-title')
    {{ __('Customer Reports') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Customer Reports') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        <button type="button" class="btn btn-sm btn-primary btn-icon exportChartButton"
                title="{{ __('Export to CSV') }}" data-bs-toggle="tooltip" data-bs-placement="top">
            <i class="ti ti-file-export" aria-hidden="true"></i>
            <span class="ss-visually-hidden">{{ __('Export to CSV') }}</span>
        </button>
    </div>
@endsection

@section('content')
    @include('layouts.includes.store-syria-ds')

    {{-- The period bar, its markup and its reasoning, live in `<x-ss.report-filter>`: four
         report screens had a copy of it and all four carried the same defects. --}}
    <div class="row">
        <div class="col-xxl-12">
            <div class="ss-card">
                <x-ss.report-filter id="report" :label="__('Report period')" />
            </div>
        </div>

        {{-- `aria-live="polite"` because the whole body is replaced by AJAX: without it the
             period changes and a screen-reader user is told nothing at all. `aria-busy` is set
             while the request is in flight so the old figures are not read as the new ones. --}}
        <div class="chart_data pc-dt-export" aria-live="polite" aria-busy="false"></div>
    </div>
@endsection

@push('custom-script')
    <script>
        $(document).ready(function() {
            var $region = $('.chart_data');

            function fetchData(value, date) {
                var csrfToken = $('meta[name="csrf-token"]').attr('content');

                $region.attr('aria-busy', 'true');

                $.ajax({
                    type: "GET",
                    url: '{{ route('reports.chart') }}',
                    async: true,
                    data: {
                        _token: csrfToken,
                        chart_data: value,
                        Date: date,
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        /* The body is server-rendered Blade, charts and tables included, so
                           there is nothing to initialise here any more. The three ApexCharts
                           this handler used to build had no accessible representation at all;
                           the ss.bar-chart component ships the numbers as a real table instead.
                           (Written without angle brackets on purpose: Blade scans this file
                           whole, and a component tag inside a JavaScript comment is still an
                           unclosed component tag to the compiler.) */
                        $region.html(data.html);
                    },
                    error: function(error) {
                        $('#loader').fadeOut();
                    },
                    complete: function() {
                        $region.attr('aria-busy', 'false');
                    }
                });
            }

            /* `aria-pressed` is the ONLY record of which period is selected — the CSS selects
               on it too (`.ss-chip[aria-pressed="true"]`), so the announced state and the
               visible state cannot drift apart. A parallel `is-active` class is what let the
               old version look selected to a sighted user and selected to nobody else. */
            function selectedPeriod() {
                return $('.chart-data[aria-pressed="true"]').val();
            }

            $(".chart-data").on("click", function() {
                $(".chart-data").attr('aria-pressed', 'false');
                $(this).attr('aria-pressed', 'true');

                fetchData(selectedPeriod(), $('#report-date').val());
            });

            /* Generate carries no period of its own: it re-runs whichever is selected, against
               whatever is in the date field. */
            $(".generate_button").on("click", function() {
                fetchData(selectedPeriod(), $('#report-date').val());
            });

            fetchData(selectedPeriod(), $('#report-date').val());
        });
    </script>
    <script>
        $(document).ready(function() {
            $('.exportChartButton').click(function() {
                $.ajax({
                    url: '{{ route('reports.export') }}',
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
                            || !Array.isArray(data.userTotal)
                            || !Array.isArray(data.registerTotal)
                            || !Array.isArray(data.guest)
                            || !Array.isArray(data.newguestTotal)) {
                            show_toastr(@json(__('Error')), @json(__('Something went wrong. Please try again.')), 'error');
                            return;
                        }

                        if (data.monthList.length === 0) {
                            show_toastr(@json(__('Error')), @json(__('No records found')), 'error');
                            return;
                        }

                        try {
                            /* The header row is translated: a merchant reading the admin in
                               Arabic was handed a CSV with English column names. */
                            var header = [
                                @json(__('Period')),
                                @json(__('Orders from registered customers')),
                                @json(__('Orders from guests')),
                                @json(__('New registered customers')),
                                @json(__('New guests')),
                            ].join(',');

                            var csvContent = "data:text/csv;charset=utf-8,﻿" + header + "\n";
                            for (var i = 0; i < data.monthList.length; i++) {
                                csvContent +=
                                    `${data.monthList[i]},${data.userTotal[i]},${data.guest[i]},${data.registerTotal[i]},${data.newguestTotal[i]}\n`;
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
        });
    </script>
@endpush
