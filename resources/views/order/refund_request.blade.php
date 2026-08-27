@extends('layouts.app')

@section('page-title', __('Order Refund Request'))

@section('action-button')
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Order Refund Request') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
