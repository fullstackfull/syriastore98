@extends('layouts.app')

@section('page-title', __('Orders Review'))

@section('action-button')

@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Orders Review') }}</li>
@endsection

@section('content')
    <!-- [ Main Content ] start -->
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
    <!-- [ Main Content ] end -->
@endsection
