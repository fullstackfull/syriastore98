@extends('layouts.app')

@section('page-title')
    {{ __('Users') }}
@endsection

@php
    $logo = asset(Storage::url('uploads/profile/'));
@endphp


@section('action-button')
    {{-- P2-05-5. The Grid View link had NO `title` at all, only `data-bs-original-title` — which
         is the tooltip plugin's own storage and never an accessible name — so it was unnamed from
         the moment it rendered rather than only after the plugin initialised. The Add button was
         the usual case: icon-only, named by a `title` the plugin blanks. --}}
    <div class="text-end d-flex gap-1 all-button-box justify-content-md-end justify-content-center">
        <a href="{{ route('users.index') }}"
            class="ss-btn ss-btn--secondary ss-btn--sm ss-btn--icon"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Grid View') }}"
            aria-label="{{ __('Grid View') }}">
            <i class="ti ti-layout-grid" aria-hidden="true"></i>
        </a>
        @permission('Create User')
            <a href="#" class="ss-btn ss-btn--primary ss-btn--sm" data-ajax-popup="true" data-size="md"
                data-title="{{ __('Add User') }}"
                data-url="{{ route('users.create') }}"
                data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add User') }}">
                <i class="ti ti-plus" aria-hidden="true"></i>
                <span>{{ __('Add User') }}</span>
            </a>
        @endpermission
    </div>
@endsection

{{-- P2-05-5. There were TWO `@section('breadcrumb')` blocks in this file. Blade keeps the
     LAST one for a given name unless `@parent` is used, so the first — which carried
     `aria-current="page"` — was dead markup that read as deliberate. Kept the one that renders
     and gave it back the `aria-current` the dead one had. --}}
@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Users') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection