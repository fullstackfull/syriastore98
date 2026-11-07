@extends('layouts.app')

@section('page-title', __('Tag'))

@section('action-button')
    @permission('Create Tag')

    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        {{-- Phase 2.5 / primary-action-named-only-by-title. The only name was `title`, which
             Bootstrap moves to `data-bs-original-title` and blanks on tooltip init, so the
             computed name was the Tabler glyph U+EB0B. `btn-badge` is dropped with the other
             Bootstrap button classes: it matches no rule in any stylesheet under public/. --}}
        <a href="#" class="ss-btn ss-btn--primary ss-btn--sm" data-ajax-popup="true" data-size="md" data-title="{{__('Add Tag')}}"
            data-url="{{ route('tag.create') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add Tag') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span>{{ __('Add Tag') }}</span>
        </a>
    </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Tag') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection

