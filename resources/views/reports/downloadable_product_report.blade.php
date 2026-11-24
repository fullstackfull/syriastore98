@extends('layouts.app')

@section('page-title')
    {{ __('Sales Downloadable Product') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Sales Downloadable Product') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
    </div>
@endsection
@section('content')
    @include('layouts.includes.store-syria-ds')

    {{-- ANA-002 / GAP-221. A plain GET form: this table fetches its rows from `url()->full()` as
         evaluated when the page renders, so a reload carries the period into the draw as well.
         See the component's docblock for why this posts `ss_period` rather than the legacy
         `chart_data` vocabulary. --}}
    <x-ss.analytics-period-filter
        :action="route('reports.order_downloadable_report')"
        :period-key="$periodKey"
        :period-options="$periodOptions"
        :period-from="$periodFrom"
        :period-to="$periodTo"
        :period-today="$periodToday"
        :period-clamped="$periodClamped" />

    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
