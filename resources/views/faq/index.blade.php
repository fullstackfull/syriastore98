
@extends('layouts.app')

@section('page-title', __('FAQs'))

@section('action-button')
    @permission('Create Faqs')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{__('Add FAQs')}}"
            data-url="{{ route('faqs.create') }}" data-bs-toggle="tooltip" title="{{ __('Add FAQs') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('FAQs') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
    @endsection

