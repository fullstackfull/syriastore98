@extends('layouts.app')

@section('page-title')
    {{ __('Reward Club Point Transections') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">{{ __('Reward Club Point Transections') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        <button class="btn btn-md btn-outline-success">{{ __('Total Points : ')}} {{$customer->total_club_point}}</button>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <h5></h5>
                    <div class="table-responsive">
                        <table class="table mb-0 dataTable" id="pc-dt-export">
                            <thead>
                                <tr>
                                    <th> {{ __('Order ID') }}</th>
                                    <th> {{ __('Products') }}</th>
                                    <th> {{ __('Earned Points') }}</th>
                                    <th> {{ __('Used Points') }}</th>
                                    <th> {{ __('Reward Price') }}</th>
                                </tr>
                            </thead>
                            <tbody id="service-filter-data">
                                @foreach ($pointTransections as $transection)
                                    <tr class="font-style">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <a href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($transection->order_id)) }}"
                                                    class="btn bg-success btn-sm text-sm"
                                                    data-bs-toggle="tooltip" title="{{ __('Order ID') }}">
                                                    <span class="btn-inner--text">{{__('#')}}{{ $transection->orderData->product_order_id }}</span>
                                                </a>
                                            </div>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm  btn-outline-primary me-2"
                                                data-url="{{ route('rewardclubpoint.show', $transection->id) }}" data-size="md"
                                                data-ajax-popup="true" data-title="{{ __('Products') }}">
                                                <i  data-bs-toggle="tooltip" title="Product"> </i>{{ __('View Products')}}
                                            </button>
                                            @php
                                                $product_count = count(explode(',', $transection->product_id));
                                            @endphp
                                            <span class="badge rounded p-2 f-w-600  bg-light-success">
                                                {{$product_count}}</span>
                                            </td>
                                        </td>
                                        <td>{{ $transection->points }}</td>
                                        <td>{{ $transection->used_points }}</td>
                                        <td>{{ currency_format_with_sym(($transection->reward_price ?? 0), $transection->store_id) ?? SetNumberFormat($transection->reward_price ?? 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
