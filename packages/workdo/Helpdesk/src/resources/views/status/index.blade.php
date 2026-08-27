@extends('layouts.app')
@php
    $company_settings = getAdminAllSetting();
@endphp
@section('page-title')
    {{ __('Helpdesk') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Helpdesk Status') }}</li>
@endsection
@section('action-button')
    <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
        @permission('Create Helpdesk Status')
            <a href="javascript::void(0);" class="btn btn-badge btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{ __('Create New Status') }}"
                data-url="{{ route('helpdesk.status.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Status') }}">
                <i class="ti ti-plus"></i>
            </a>
        @endpermission
    </div>
@endsection
@section('content')
    <div class="row">
        <div class="col-sm-3">
            @include('helpdesk::layouts.helpdesk_setup')
        </div>
        <div class="col-sm-9">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
