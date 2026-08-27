@extends('layouts.app')

@section('page-title', __('Lottery Order'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Lottery Order') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection