@extends('layouts.app')

@section('page-title')
    {{ __('Customer Reports') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Customer Reports') }}</li>
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

    {{--
        THE PERIOD PICKER IS THE MOST IMPORTANT CONTROL ON THIS PAGE, so it is a labelled group
        rather than four buttons that happen to look selected.

        What was here: four `<button class="nav-link chart-data">` inside a `<ul role="tablist">`
        with NO `role="tab"`, no `aria-selected` and no `aria-controls`, so the selected period
        was conveyed by a CSS class alone — a screen-reader user could not tell which of the four
        was active, and `role="tablist"` promised tabs that were not there. Two `<div>`s sat as
        direct children of that `<ul>`, which is invalid: only `<li>` may be a child of a list,
        and the date field and Generate button were announced as part of the list's item count.

        These are not tabs (a tab owns a panel that is already in the page; these refetch), so
        they are buttons in a labelled group with `aria-pressed`, which states the selection in
        the one place assistive technology reads it.
    --}}
    <div class="row">
        <div class="col-xxl-12">
            <div class="ss-card">
                <div class="ss-toolbar" role="group" aria-label="{{ __('Report period') }}">
                    @foreach ([
                        'year' => __('This year'),
                        'last-month' => __('Last month'),
                        'this-month' => __('This month'),
                        'seven-day' => __('Last 7 days'),
                    ] as $value => $label)
                        <button type="button"
                                class="ss-chip chart-data"
                                name="chart_data"
                                value="{{ $value }}"
                                aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach

                    <div class="ss-field">
                        <label class="ss-label" for="report-date">{{ __('Or pick a date') }}</label>
                        <input type="date"
                               class="ss-input date month-btn"
                               id="report-date"
                               name="date"
                               value="{{ request('date', date('Y-m-d')) }}">
                    </div>

                    <button type="button" class="ss-btn ss-btn--primary ss-btn--sm generate_button"
                            value="">{{ __('Generate') }}</button>
                </div>
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
                        if (data.hasOwnProperty("monthList") && data.hasOwnProperty(
                                "userTotal") && data.hasOwnProperty("registerTotal") && data
                            .hasOwnProperty("guest") && data.hasOwnProperty("newguestTotal")) {
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
                        } else {
                            console.error("Data is not in the expected format:", data);
                        }
                    }
                });
            });
        });
    </script>
@endpush
