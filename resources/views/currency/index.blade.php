@extends('layouts.app')
@section('page-title', __('Currency'))
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Currency') }}</li>
@endsection
@section('action-button')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary btn-badge" data-ajax-popup="true" data-size="md" data-title="{{__('Add Currency')}}"
            data-url="{{ route('currency.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Currency') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
@endsection

@push('css')
@endpush

@section('content')
    <div class="row">
        <div class="col-xl-12">
        <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection

@push('custom-script')
@endpush
