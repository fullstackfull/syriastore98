@extends('layouts.app')

@section('page-title', __('Additional Field'))

@section('action-button')
@permission('Create AdditionalField')
<div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
    <a href="#" class="btn btn-sm btn-primary add_attribute" data-ajax-popup="true" data-size="md"
        data-title="{{ __('Add Additional Field') }}"
        data-url="{{ route('additionalfieldincheckout.create') }}"
        data-bs-toggle="tooltip" title="{{ __('Add Additional Field') }}">
        <i class="ti ti-plus"></i>
    </a>
</div>
@endpermission

@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Additional Field') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
