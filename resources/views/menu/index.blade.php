@extends('layouts.app')

@section('page-title', __('Menus'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Menus') }}</li>
@endsection

@section('action-button')
    @permission('Create Menu')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{__('Add Menus')}}"
            data-url="{{ route('menus.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Menu') }}">
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
