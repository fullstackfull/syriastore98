@extends('layouts.app')

@section('page-title', __('Blog Category'))

@section('action-button')
@permission('Create Blog Category')
<div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
    <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md"
        data-title="{{__('Add Blog Category')}}"
        data-url="{{ route('blog-category.create') }}"
        data-bs-toggle="tooltip" title="{{ __('Add Blog Category') }}">
        <i class="ti ti-plus"></i>
    </a>
</div>
@endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Blog Category') }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-xl-12">
        <x-datatable :dataTable="$dataTable" />
    </div>
    </div>
</div>
@endsection

@push('custom-script')
@endpush
