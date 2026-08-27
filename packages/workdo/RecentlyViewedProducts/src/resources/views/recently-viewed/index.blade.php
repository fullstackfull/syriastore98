@extends('layouts.app')

@section('page-title', __('Recently view'))

@section('action-button')
    @permission('Create Recently Viewed Product')
        <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{ __('Add Recently Viewed')}}"
                data-url="{{ route('recently-view.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Recently Viewed') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Recently Viewed') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
