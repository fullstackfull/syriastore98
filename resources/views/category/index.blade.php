@extends('layouts.app')

@section('page-title', __('Category'))

@section('action-button')
   @permission('Create Product Category')
    <div class=" text-end gap-1 d-flex all-button-box justify-content-md-end justify-content-center">
        @if (module_is_active('ImportExport'))
            @permission('main-category import')
                @include('import-export::import.maincategory_import', ['module' => 'maincategory'])
            @endpermission
            @permission('main-category export')
                @include('import-export::export.maincategory_export', ['module' => 'maincategory'])
            @endpermission
        @endif
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{ __('Add Category')}}"
            data-url="{{ route('category.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Category') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Category') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
        <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
