@extends('layouts.app')

@section('page-title', __('Bundle Product'))

@section('action-button')
    @permission('Create Bundle Product')
        <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary add_attribute" data-ajax-popup="true" data-size="md"
                data-title="{{ __("Add Bundle Product") }}"
                data-url="{{ route('bundle-product.create') }}"
                data-bs-toggle="tooltip" title="{{ __('Create Bundle Product') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endpermission
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Bundle Product') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
