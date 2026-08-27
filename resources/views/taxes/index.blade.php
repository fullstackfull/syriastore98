@extends('layouts.app')

@section('page-title', __('Tax Class'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Tax Class') }}</li>
@endsection

@section('action-button')
    @permission('Create Tax')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Tax Class') }}" data-url="{{ route('taxes.create') }}" data-bs-toggle="tooltip"
            title="{{ __('Add Tax Class') }}">
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
