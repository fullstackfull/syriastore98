@extends('layouts.app')

@section('page-title', __('Delivery Syria'))

@section('breadcrumb')
    <li class="breadcrumb-item">
        <a href="{{ route('dashboard') }}">
            {{ __('Dashboard') }}
        </a>
    </li>

    <li class="breadcrumb-item">
        {{ __('Delivery Syria') }}
    </li>
@endsection

@section('content')
    <div class="row">

        {{-- Wallet --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Wallet') }}</h5>
                </div>

                <div class="card-body">
                    @php
                        $balances = $wallet['balances_by_currency'] ?? [];
                        $walletDetails = $wallet['details'] ?? [];
                    @endphp

                    @if (!empty($balances))
                        <div class="row">
                            @foreach ($balances as $currency => $balance)
                                <div class="col-md-4 col-sm-6 mb-3">
                                    <div class="border rounded p-3 h-100">
                                        <div class="text-muted">
                                            {{ __('Available Balance') }}
                                        </div>

                                        <div class="fs-4 fw-bold">
                                            {{ $balance }} {{ $currency }}
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-muted">
                            {{ __('No wallet balance is available.') }}
                        </div>
                    @endif

                    @if (!empty($walletDetails))
                        <div class="table-responsive mt-3">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Currency') }}</th>
                                        <th>{{ __('Balance') }}</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($walletDetails as $detail)
                                        <tr>
                                            <td>
                                                {{ ucfirst($detail['type'] ?? '-') }}
                                            </td>

                                            <td>
                                                {{ $detail['currency'] ?? '-' }}
                                            </td>

                                            <td>
                                                {{ $detail['balance'] ?? '0.00' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Parcels --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="mb-0">{{ __('Parcels') }}</h5>

                    <div class="btn-group">
                        <a
                            href="{{ route('delivery-syria.index', ['visible' => 0]) }}"
                            class="btn btn-sm {{ $visible === 0 ? 'btn-primary' : 'btn-outline-primary' }}"
                        >
                            {{ __('Awaiting Transfer') }}
                        </a>

                        <a
                            href="{{ route('delivery-syria.index', ['visible' => 1]) }}"
                            class="btn btn-sm {{ $visible === 1 ? 'btn-primary' : 'btn-outline-primary' }}"
                        >
                            {{ __('Transferred') }}
                        </a>
                    </div>
                </div>

                <div class="card-body">
                    @if (!empty($errorMessage))
                        <div class="alert alert-danger">
                            {{ $errorMessage }}
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>{{ __('Tracking ID') }}</th>
                                    <th>{{ __('Invoice') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('Phone') }}</th>
                                    <th>{{ __('Address') }}</th>
                                    <th>{{ __('Weight') }}</th>
           
                                    <th>{{ __('Company') }}</th>
                                    <th>{{ __('Delivery Charge') }}</th>
                                    <th>{{ __('COD') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Created At') }}</th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse ($parcels as $parcel)
                                    <tr>
                                        <td>
                                            <strong>
                                                {{ $parcel['tracking_id'] ?? '-' }}
                                            </strong>
                                        </td>

                                        <td>
                                            {{ $parcel['invoice_no'] ?? '-' }}
                                        </td>

                                        <td>
                                            {{ $parcel['customer_name'] ?? '-' }}
                                        </td>

                                        <td>
                                            {{ $parcel['customer_phone'] ?? '-' }}
                                        </td>

                                        <td style="min-width: 220px;">
                                            {{ $parcel['customer_address'] ?? '-' }}
                                        </td>

                                        <td>
                                            {{ $parcel['weight'] ?? '-' }}
                                        </td>

                            

                                        <td>
                                            {{ $parcel['company_name'] ?? '-' }}
                                        </td>

                                        <td>
                                            {{ $parcel['delivery_charge'] ?? '0.00' }}
                                        </td>

                                        <td>
                                            {{ $parcel['cod_amount'] ?? '0.00' }}
                                        </td>

                                        <td>
                                            <span class="badge bg-info">
                                                {{ $parcel['statusName'] ?? __('Unknown') }}
                                            </span>
                                        </td>

                                        <td>
                                            {{ $parcel['created_at'] ?? '-' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="text-center py-4 text-muted">
                                            {{ __('No parcels found.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Pagination --}}
                    @if (!empty($meta) && ($meta['last_page'] ?? 1) > 1)
                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3">
                            <div class="text-muted">
                                {{ __('Page') }}
                                {{ $meta['current_page'] ?? 1 }}
                                {{ __('of') }}
                                {{ $meta['last_page'] ?? 1 }}

                                —
                                {{ $meta['total'] ?? 0 }}
                                {{ __('parcels') }}
                            </div>

                            <div class="btn-group">
                                @if (($meta['current_page'] ?? 1) > 1)
                                    <a
                                        href="{{ route('delivery-syria.index', [
                                            'visible' => $visible,
                                            'page' => ($meta['current_page'] ?? 1) - 1,
                                        ]) }}"
                                        class="btn btn-outline-primary btn-sm"
                                    >
                                        {{ __('Previous') }}
                                    </a>
                                @endif

                                @if (($meta['current_page'] ?? 1) < ($meta['last_page'] ?? 1))
                                    <a
                                        href="{{ route('delivery-syria.index', [
                                            'visible' => $visible,
                                            'page' => ($meta['current_page'] ?? 1) + 1,
                                        ]) }}"
                                        class="btn btn-outline-primary btn-sm"
                                    >
                                        {{ __('Next') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection