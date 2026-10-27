@extends('layouts.app')

@section('page-title', __('Attributes'))

@section('action-button')
    @permission('Create Attributes')
    <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
        {{-- P2-05-4. Same defect and same fix as the category list: the only name was a
             `title` that Bootstrap blanks when it initialises the tooltip. `.add_attribute` is
             kept -- it is a selector the page's own script binds to. --}}
        <a href="#" class="ss-btn ss-btn--primary ss-btn--sm add_attribute" data-ajax-popup="true"
            data-size="md" data-title="{{ __('Add Attribute') }}"
            data-url="{{ route('product-attributes.create') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add Attribute') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span>{{ __('Add Attribute') }}</span>
        </a>
    </div>
    @endpermission

@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Attributes') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection