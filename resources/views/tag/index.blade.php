@extends('layouts.app')

@section('page-title', __('Tag'))

@section('action-button')
    @permission('Create Tag')

    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary btn-badge" data-ajax-popup="true" data-size="md" data-title="{{__('Add Tag')}}"
            data-url="{{ route('tag.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Tag') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Tag') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection

