@extends('layouts.app')

@section('page-title', __('Support Ticket'))


@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Support Ticket') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
        <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
    @endsection

