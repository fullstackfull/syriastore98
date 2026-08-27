@extends('layouts.app')

@section('page-title', __('Order'))

@section('action-button')
<div class="text-end">
    <a class="btn btn-sm btn-primary btn-icon export-btn" href="{{ route('order.export') }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Export') }}" filename="{{ __('Order') }}">
        <i class="ti ti-file-export"></i>
    </a>
    <a href="{{ route('order.index') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('List View') }}" class="btn btn-sm btn-primary btn-icon ">
        <i class="ti ti-list"></i>
    </a>
</div>
@endsection

@section('breadcrumb')
<li class="breadcrumb-item">{{ __('Order') }}</li>
@endsection

@section('content')
<div class="row mb-4 d-flex order-card-wrp">
    @foreach ($orders as $order)
    @php
        $btn_class = 'fa fa-check-circle text-warning';
        $title = __('Pending');
        if ($order->delivered_status == 0) {
        $btn_class = 'fa fa-check-circle text-warning';
        $title = __('Pending');
        } elseif ($order->delivered_status == 1) {
        $btn_class = 'ti ti-checks text-secondary';
        $title = __('Delivered');
        } elseif ($order->delivered_status == 2) {
        $btn_class = 'fa fa-check text-danger';
        $title = __('Cancel');
        } elseif ($order->delivered_status == 4) {
        $btn_class = 'fa fa-check text-primary';
        $title = __('Comfirmed');
        } elseif ($order->delivered_status == 5) {
        $btn_class = 'fa fa-truck text-info';
        $title = __('Picked Up');
        }elseif ($order->delivered_status == 6) {
            $btn_class = 'fa fa-spinner text-success';
            $title = __('Shipped');
        }elseif ($order->delivered_status == 3 && $order->return_status == 1) {
            $btn_class = 'ti ti-truck-return text-secondary';
            $title = __('Return request processing');
        }elseif ($order->delivered_status == 3 && $order->return_status == 2) {
            $btn_class = 'fa fa-undo text-success';
            $title = __('Return');
        }elseif ($order->delivered_status == 3 && $order->return_status == 3) {
            $btn_class = 'fa fa-close text-danger';
            $title = __('Return cancelled');
        }
    @endphp
    <div class="col-xxl-3 col-xl-4 col-sm-6 col-12 d-flex">
        <div class="card user-card order-card border mb-0">
            <div class="card-header p-3  border-bottom h-100">
                <div class="user-img-wrp d-flex gap-3 align-items-center">
                    <div class="user-image rounded border-2 border border-primary">
                        <a class="dropdown-item" href="#" data-value="confirmed" title="{{ $title }}">
                            <i class="{{ $btn_class }} h-100 w-100" ></i>
                        </a>
                    </div>
                    <div class="user-content">
                        <span class="text-dark text-md">
                            @if ($order->is_guest == 1) 
                                <b>{{ __('Guest') }}</b>
                            @elseif ($order->customer_id != 0) 
                                <b>{{ (!empty($order->CustomerData->name) ? $order->CustomerData->name : '') }}</b>
                                <br>
                                {{ (!empty($order->CustomerData->mobile) ? $order->CustomerData->mobile : '') }}
                            @else 
                                <b>{{__('Walk-in-customer')}}</b>
                            @endif
                        </span>
                        <br>
                        <span class="text-dark text-md"><b>{{__('Price')}} : </b>
                        {{ currency_format_with_sym(($order->final_price ?? 0), getCurrentStore()) ?? SetNumberFormat($order->final_price) }}</span>
                    </div>
                </div>
            </div>
            <div class="card-body p-3 gap-1 d-flex align-items-center justify-content-between">
            <h4 class="mb-0"><a href="{{route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}" class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="{{ __('Invoice ID') }}">
                                <span class="btn-inner--icon"></span>
                                <span class="btn-inner--text">#{{ $order->product_order_id }}</span>
                            </a></h4>
                <div class="bottom-icons d-flex flex-wrap align-items-center">
                    @if ($order->delivered_status == 3 && $order->return_status == 1)
                        <a href="#" class="btn btn-sm btn-primary return_request me-2" data-id="{{ $order->id }}" data-status="2" data-bs-toggle="tooltip"
                            title="{{ __('Approve') }}">
                            <i class="ti ti-check"></i>
                        </a>
                        <a href="#" class="btn btn-sm btn-danger return_request me-2" data-id="{{ $order->id }}" data-status="3" data-bs-toggle="tooltip"
                            title="{{ __('Cancel') }}">
                            <i class="ti ti-circle-x"></i>
                        </a>
                    @endif
                    <a href="javascript:void(0)"
                        data-url="{{ route('order.order_view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}" data-size="lg"
                        data-ajax-popup="true" data-title="{{ __('Order') }}    #{{ $order->product_order_id }}"
                        class="x-3 btn btn-sm border me-2" data-bs-toggle="tooltip"
                        data-original-title="{{ __('Show') }}" data-bs-toggle="tooltip"
                        title="{{ __('Show') }}">
                        <i class="ti ti-eye"></i>
                    </a>
                    <a href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}"
                        class="btn btn-sm border me-2" data-bs-toggle="tooltip"
                        title="{{ __('Edit') }}">
                        <i class="ti ti-pencil"></i>
                    </a>
                    {!! Form::open(['method' => 'DELETE', 'route' => ['order.destroy', $order->id], 'class' => 'd-inline']) !!}
                    <button type="button" class="btn btn-sm border show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                        title="{{ __('Delete') }}">
                        <i class="ti ti-trash text-danger"></i>
                    </button>
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>
{!! $orders->links('layouts.global-pagination') !!}
@endsection

@push('custom-script')
    <script>
        $(document).on('click', '.code', function() {
            var type = $(this).val();
            $('#code_text').addClass('col-md-12').removeClass('col-md-8');
            $('#autogerate_button').addClass('d-none');
            if (type == 'auto') {
                $('#code_text').addClass('col-md-8').removeClass('col-md-12');
                $('#autogerate_button').removeClass('d-none');
            }
        });

        $(document).on('click', '.return_request', function() {
            var id = $(this).attr('data-id');
            var status = $(this).attr('data-status');
            var data = {
                id: id,
                status: status
            }
            $.ajax({
                url: '{{ route('order.return.request') }}',
                method: 'POST',
                data: data,
                context:this,
                success: function (response)
                {
                    $('#loader').fadeOut();
                    if(response.status == 'error') {
                        show_toastr('{{ __('Error') }}', response.message, 'error')
                    } else {
                        show_toastr('{{ __('Success') }}', response.message, 'success')
                        $(this).parent().find('.return_request').remove();
                    }
                }
            });
        });

        $(document).on('click', '#code-generate', function() {
            var length = 10;
            var result = '';
            var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            var charactersLength = characters.length;
            for (var i = 0; i < length; i++) {
                result += characters.charAt(Math.floor(Math.random() * charactersLength));
            }
            $('#auto-code').val(result);
        });
    </script>
@endpush
