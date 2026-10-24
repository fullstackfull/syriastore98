@extends('layouts.app')
@section('page-title', __('Dashboard'))
@section('action-button')
@endsection
@section('content')
    <div class="row">
        <div class="col-12">
            <div class="row mb-4 dash-order-status g-4">
                <div class="col-12">
                    <div class="row g-4">
                        <x-dashboard-card :count="$pending_order" :label="__('Pending orders')" :icon="$pending_icon" />
                        <x-dashboard-card :count="$return_order" :label="__('Order Return')" :icon="$return_icon" />
                        <x-dashboard-card :count="$confirmed_order" :label="__('Confirmed Order')"
                            :icon="$confirmed_icon" />
                        <x-dashboard-card :count="$cancel_order" :label="__('Cancel Order')" :icon="$cancel_icon" />
                        <x-dashboard-card :count="$shipped_order" :label="__('Order Shipped')" :icon="$shipped_icon" />
                        <x-dashboard-card :count="$delivered_order" :label="__('Order Delivered')"
                            :icon="$delivered_icon" />
                        <x-dashboard-card :count="$totle_order" :label="__('Total Orders')" :icon="$total_order_icon" />
                        <x-dashboard-card :count="$totle_customers" :label="__('Total Customers')" :icon="$customer_icon" />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
