@extends('layouts.app')

@section('page-title', __('Donation'))

@section('action-button')
    @permission('Create Donation')
        <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary add_attribute" data-ajax-popup="true" data-size="md"
                data-title="{{ __('Add Donation') }}" data-url="{{ route('donation.create') }}" data-bs-toggle="tooltip"
                title="{{ __('Create Donation') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endpermission

@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Donation') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
