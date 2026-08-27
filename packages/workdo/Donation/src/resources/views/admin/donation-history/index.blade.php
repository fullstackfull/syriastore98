@extends('layouts.app')

@section('page-title', __('Customer Donation History'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Customer Donation History') }}</li>
@endsection
@section('action-button')
    <div class="text-end">
        <a class="btn btn-sm btn-primary btn-icon me-2 export-btn" href="{{ route('donationhistory.export') }}"
            data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Export') }}" filename="{{ __('Donation') }}">
            <i class="ti ti-file-export"></i>
        </a>
    </div>
@endsection
@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
