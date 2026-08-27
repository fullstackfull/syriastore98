@extends('layouts.app')

@section('page-title', __('Shipping Zone'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Shipping Zone') }}</li>
@endsection

@section('action-button')
    @permission('Create Shipping Zone')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Shipping Zone') }}" data-url="{{ route('shipping-zone.create') }}" data-bs-toggle="tooltip"
            title="{{ __('Add Shipping Zone') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    @endpermission
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
