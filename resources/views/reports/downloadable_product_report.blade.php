@extends('layouts.app')

@section('page-title')
    {{ __('Sales Downloadable Product') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Sales Downloadable Product') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
    </div>
@endsection
@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
