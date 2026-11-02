@extends('layouts.app')

{{--
    Phase 02 component gallery.

    A preview-only screen whose whole job is to put every Phase 02 component on
    one page so browser-checks-p02.cjs can DRIVE them -- focus trap, Esc,
    scroll lock, focus return, the dirty guard, the validation summary. The
    components were built in P2-01 and, until this existed, only the ones the
    dashboard happened to use had ever been rendered.

    It is not a merchant screen and is not routed.
--}}

@section('page-title', 'Phase 02 components')
@push('css')
    @include('layouts.includes.store-syria-ds')
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item">Phase 02 components</li>
@endsection

@section('content')
<div class="ss-scope ss-has-actionbar">

    <x-ss.card :title="__('Status')" class="mb-4">
        <div class="ss-row">
            <x-ss.status-badge tone="brand"   :label="__('Pending')" />
            <x-ss.status-badge tone="success" :label="__('Order Delivered')" />
            <x-ss.status-badge tone="warning" :label="__('Partially Paid')" />
            <x-ss.status-badge tone="danger"  :label="__('Cancel Order')" />
            <x-ss.status-badge tone="info"    :label="__('Order Shipped')" />
            <x-ss.status-badge               :label="__('Pre Order')" />
        </div>
    </x-ss.card>

    <x-ss.card :title="__('Filters')" class="mb-4">
        <div class="ss-chips">
            <x-ss.chip :label="__('All')"             :count="1284" :pressed="true" />
            <x-ss.chip :label="__('Pending')"         :count="12" />
            <x-ss.chip :label="__('Order Shipped')"   :count="21" />
            <x-ss.chip :label="__('Cancel Order')"    :count="24" :interactive="false" />
        </div>
    </x-ss.card>

    <x-ss.card :title="__('Storage Status')" class="mb-4">
        <x-ss.progress :value="62" :max="100" :label="__('Storage Status')" />
        <x-ss.switch name="demo_switch" :label="__('Active')" :checked="true" />
    </x-ss.card>

    <x-ss.help :title="__('Note')" class="mb-4">
        <ul class="ss-help__list">
            <li>{{ __('Drag an option to change the order customers see it in.') }}</li>
        </ul>
    </x-ss.help>

    <x-ss.card :title="__('Order')" class="mb-4">
        <x-ss.empty :title="__('No data available in table')"
                    :body="__('Create Order')" icon="shopping-cart">
            <x-slot:action>
                <a href="#create" class="ss-btn ss-btn--primary ss-btn--sm">{{ __('Create Order') }}</a>
            </x-slot:action>
        </x-ss.empty>
    </x-ss.card>

    {{-- The side panel, and the control that opens it. --}}
    <x-ss.card :title="__('Order')" class="mb-4">
        <button type="button" class="ss-btn ss-btn--secondary ss-btn--sm"
                id="open-order-panel"
                data-ss-panel-open="order-preview">
            {{ __('View') }}
        </button>
    </x-ss.card>

    {{-- A form, to drive the dirty guard and the validation summary. --}}
    <form method="POST" action="#save" data-ss-dirty-track id="demo-form">
        <x-ss.error-summary :errors="$errors" />

        <x-ss.card :title="__('Product')" class="mb-4">
            <div class="ss-field">
                <label class="ss-label" for="name">{{ __('Name') }}<span class="ss-label__required">*</span></label>
                <input class="ss-input" id="name" name="name" type="text"
                       @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif>
                @if ($errors->has('name'))
                    <p class="ss-field__error" id="name-error">{{ $errors->first('name') }}</p>
                @endif
            </div>
            <div class="ss-field">
                <label class="ss-label" for="price">{{ __('Price') }}</label>
                <input class="ss-input" id="price" name="price" type="text"
                       @if ($errors->has('price')) aria-invalid="true" aria-describedby="price-error" @endif>
                @if ($errors->has('price'))
                    <p class="ss-field__error" id="price-error">{{ $errors->first('price') }}</p>
                @endif
            </div>
        </x-ss.card>

        <x-ss.actionbar>
            <a href="#cancel" class="ss-btn ss-btn--ghost ss-btn--sm">{{ __('Cancel') }}</a>
            <button type="submit" class="ss-btn ss-btn--primary ss-btn--sm">{{ __('Save') }}</button>
        </x-ss.actionbar>
    </form>

    <x-ss.panel id="order-preview" :title="__('Order')">
        <dl class="ss-deflist">
            <dt class="ss-deflist__key">{{ __('Customer') }}</dt>
            <dd class="ss-deflist__val">{{ $previewCustomer }}</dd>
            <dt class="ss-deflist__key">{{ __('Date') }}</dt>
            <dd class="ss-deflist__val">{{ $previewDate }}</dd>
            <dt class="ss-deflist__key">{{ __('Status') }}</dt>
            <dd class="ss-deflist__val"><x-ss.status-badge tone="brand" :label="__('Pending')" /></dd>
        </dl>
        <x-slot:footer>
            <a href="#open" class="ss-btn ss-btn--primary ss-btn--sm">{{ __('View') }}</a>
            <button type="button" class="ss-btn ss-btn--ghost ss-btn--sm"
                    data-ss-panel-close="order-preview">{{ __('Cancel') }}</button>
        </x-slot:footer>
    </x-ss.panel>

</div>
@endsection
