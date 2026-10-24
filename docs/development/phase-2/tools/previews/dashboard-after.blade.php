@extends('layouts.app')
@section('page-title', __('Dashboard'))
@push('css')
    @include('layouts.includes.store-syria-ds')
@endpush
@section('action-button')
    <div class="ss-scope ss-page-header__actions justify-content-md-end justify-content-center">
        @permission('Create Product')
            <a href="{{ route('product.create') }}" class="ss-btn ss-btn--primary ss-btn--sm">
                <i class="ti ti-plus" aria-hidden="true"></i>
                <span>{{ __('Add Product') }}</span>
            </a>
        @endpermission
        @permission('Manage Order')
            <a href="{{ route('order.index') }}" class="ss-btn ss-btn--secondary ss-btn--sm">
                <i class="ti ti-shopping-cart" aria-hidden="true"></i>
                <span>{{ __('Orders') }}</span>
            </a>
        @endpermission
    </div>
@endsection
@section('content')
    @include('dashboard.overview')
@endsection
