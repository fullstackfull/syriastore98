@extends('layouts.app')

@section('page-title')
    {{ __('DeliveryBoy') }}
@endsection

@php
    $logo = asset(Storage::url('uploads/profile/'));
@endphp

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('DeliveryBoy') }}</li>
@endsection

@section('action-button')
    <div class="text-end d-flex gap-1 all-button-box justify-content-md-end justify-content-center">
        <a href="{{ route('deliveryboy.index') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Grid View') }}"
            class="btn btn-sm btn-primary btn-icon">
            <i class="ti ti-layout-grid"></i>
        </a>
        @permission('Create DeliveryBoy')
        <a href="#" class="btn btn-sm btn-primary btn-badge mx-1" data-ajax-popup="true" data-size="md" data-title="{{__('Create DeliveryBoy')}}"
                data-url="{{ route('deliveryboy.create') }}" data-bs-toggle="tooltip" title="{{ __('Add DeliveryBoy') }}">
                <i class="ti ti-plus"></i>
            </a>
        @endpermission
    </div>
@endsection


@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
