@extends('layouts.app')

@section('page-title', __('Tax Class'))

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('taxes.index') }}">{{ __('Tax Class') }} </a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ $tax_option->name }}</li>
@endsection

@section('action-button')
    @permission('Create Tax Method')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="javascript::void(0);" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{__('Add Tax Rates')}}"
            data-url="{{ route('taxes-method.create',$tax_option->id) }}" data-bs-toggle="tooltip" title="{{ __('Add Tax Rates') }}">
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
