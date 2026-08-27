@extends('layouts.app')

@section('page-title')
    {{ __('Additional Custom Fields') }}
@endsection

@section('breadcrumb')
<li class="breadcrumb-item">{{ __('Product') }}</li> 
<li class="breadcrumb-item">{{ __('Additional Custom Fields') }}</li>
@endsection

@section('action-button')
@permission('Create AdditionalCustomFields')
    <div class="float-end">
        <a href="#" data-url="{{ route('additionalcustomfields.create') }}" 
           data-bs-toggle="tooltip" 
           title="{{ __('Create') }}"
           data-ajax-popup="true" 
           data-title="{{ __('Create New Custom Field') }}" 
           class="btn btn-sm btn-primary">
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

