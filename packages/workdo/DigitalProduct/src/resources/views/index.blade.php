@extends('layouts.app')

@section('page-title')
    {{ __('Digital Product') }}
@endsection

@php
    $logo = asset(Storage::url('uploads/profile/'));
    $admin = getAdminAllSetting();

@endphp


@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Digital Product') }}</li>
@endsection

@section('action-button')
    @permission('Create Digital Product')
        <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary add_attribute" data-ajax-popup="true" data-size="lg"
                data-title="{{ __('Add Digital Product') }}" data-url="{{ route('digitalproduct.create') }}" data-bs-toggle="tooltip"
                title="{{ __('Add Digital Product') }}">
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
@push('custom-script')
    @if ($msg != 0)
        <script>
            show_toastr('{{ __('Success') }}', '{!! $msg !!}', 'success');
        </script>
    @endif
@endpush
