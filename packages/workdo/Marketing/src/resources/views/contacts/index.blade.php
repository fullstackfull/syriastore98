@extends('layouts.app')
@section('breadcrumb')
    <li class="breadcrumb-item active" aria-current="page">{{ __('Contacts') }}</li>
@endsection
@section('page-title')
    {{ __('Contacts') }}
@endsection
@section('title')
    {{ __('Contacts') }}
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
