@extends('layouts.app')

@section('page-title', __('Blogs'))

@section('action-button')
@permission('Create Blog')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="lg" data-title="{{__('Add Blog')}}"
            data-url="{{ route('blog.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Blog') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
@endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Blogs') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
        <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
    @endsection

