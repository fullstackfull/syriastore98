@extends('layouts.app')

@section('page-title', __('Partial Payment'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Partial Payment History') }}</li>
@endsection
@push('css')
<style>
    .status_badge{
        min-width: 105px !important;
    }
</style>
@endpush
@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
