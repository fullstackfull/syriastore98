@extends('layouts.app')

@section('page-title', __('Shipping Method'))

@section('breadcrumb')
    <li class="breadcrumb-item">
        <a href="{{ route('shipping-zone.index') }}">{{ __('Shipping Zone') }} @if (isset($shippingZones->zone_name))
                ({{ $shippingZones->zone_name }})
            @endif
        </a>
    </li>
    <li class="breadcrumb-item">{{ __('Shipping Method') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
