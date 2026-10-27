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
        {{-- P2-05-4. Was an icon-only <a> whose only name was `title`, which Bootstrap's
             tooltip MOVES to data-bs-original-title and blanks on initialisation -- so after
             page load the primary action on this screen was an unnamed control wrapping an
             unlabelled <i>. The visible label follows the pilot screen
             (attribute_option/index), which the acceptance names as the reference for this
             area; it also removes the need for the name to come from a tooltip at all. --}}
        <a href="#" class="ss-btn ss-btn--primary ss-btn--sm" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Category') }}"
            data-url="{{ route('category.create') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add Category') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span>{{ __('Add Category') }}</span>
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
