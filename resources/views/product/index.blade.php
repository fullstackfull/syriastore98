@extends('layouts.app')

@section('page-title')
    {{ __('Product') }}
@endsection
@php
    $logo = asset(Storage::url('uploads/profile/'));
    $admin = getAdminAllSetting();
@endphp
@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Product') }}</li>
@endsection

@section('action-button')
    @permission('Create Product')
    <div class="text-end gap-1 d-flex all-button-box justify-content-md-end justify-content-center">
        @if (module_is_active('ImportExport'))
            @permission('product import')
                @include('import-export::import.button', ['module' => 'product'])
            @endpermission
            @permission('product export')
                @include('import-export::export.button', ['module' => 'product'])
            @endpermission
        @endif

        @if (module_is_active('HubSpot'))
            @permission('hubspot manage')
                @include('hub-spot::product.button', ['module' => 'product'])
            @endpermission
        @endif


        <a href="{{ route('product.list') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Grid View') }}" class="btn btn-sm btn-primary btn-icon ">
            <i class="ti ti-layout-grid"></i>
        </a>

        <a href="{{ route('product.create') }}" class="btn btn-sm btn-primary" data-title="{{ __('Create New Product') }}"
            data-bs-toggle="tooltip" title="{{ __('Add New Product') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    @endpermission
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            {{-- P2-03-2. ABOVE the table, because it changes what the table shows: a control
                 that alters a list belongs where the eye arrives before the rows, not below
                 them. It is a plain GET form — see the partial for why that needs no script. --}}
            @include('product.partials.filters')

            <x-datatable :dataTable="$dataTable" />

            {{-- P2-03-6: ONE panel for the whole list, filled per row — the same arrangement
                 the orders list uses, so the focus-return bookkeeping has one id to key on. --}}
            <x-ss.panel id="ss-product-preview-panel"
                        :title="__('Product preview')"
                        :loading-text="__('Loading product…')"
                        :error-text="__('This product could not be loaded.')" />
        </div>
    </div>
@endsection
@push('custom-script')
<script>
    $(document).ready(function() {
        var successMsg = localStorage.getItem('success_msg');
        if (successMsg) {
            show_toastr('Success', successMsg, 'success');
            localStorage.removeItem('success_msg');
        }
    });
</script>
@endpush
