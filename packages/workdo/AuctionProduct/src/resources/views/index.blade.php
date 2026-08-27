@extends('layouts.app')

@section('page-title')
    {{ __('Auction Product') }}
@endsection

@php
    $logo = asset(Storage::url('uploads/profile/'));
    $admin = getAdminAllSetting();

@endphp


@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Auction Product') }}</li>
@endsection

@section('action-button')
    @permission('Create Auction Product')
        <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="{{ route('auctionproduct.create') }}" class="btn btn-sm btn-primary add_attribute" data-bs-toggle="tooltip"
                title="{{ __('Create Auction Product') }}">
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
