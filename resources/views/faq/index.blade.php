
@extends('layouts.app')

@section('page-title', __('FAQs'))

@section('action-button')
    @permission('Create Faqs')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        {{-- Phase 2.5 / primary-action-named-only-by-title. The only name was `title`, which
             Bootstrap blanks when it initialises the tooltip, leaving the Tabler glyph U+EB0B as
             the computed accessible name. Visible <span> plus `aria-hidden` on the <i>, following
             the three shipped precedents; no `aria-label` beside visible text (WCAG 2.5.3). --}}
        <a href="#" class="ss-btn ss-btn--primary ss-btn--sm" data-ajax-popup="true" data-size="md" data-title="{{__('Add FAQs')}}"
            data-url="{{ route('faqs.create') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add FAQs') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span>{{ __('Add FAQs') }}</span>
        </a>
    </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('FAQs') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
    @endsection

