@extends('layouts.app')

@section('page-title')
    {{ __('Users') }}
@endsection

@php
    $logo = asset(Storage::url('uploads/profile/'));
@endphp

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Users') }}</li>
@endsection

@section('action-button')
    <div class="text-end d-flex gap-1 all-button-box justify-content-md-end justify-content-center">
        <a href="{{ route('users.index') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Grid View') }}"
            class="btn btn-sm btn-primary btn-icon">
            <i class="ti ti-layout-grid"></i>
        </a>
        @permission('Create User')
            <a href="#" class="btn btn-sm btn-badge btn-primary" data-ajax-popup="true" data-size="md" data-title="{{__('Add User')}}"
                data-url="{{ route('users.create') }}" data-bs-toggle="tooltip" title="{{ __('Add User') }}">
                <i class="ti ti-plus"></i>
            </a>
        @endpermission
    </div>
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Users') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection