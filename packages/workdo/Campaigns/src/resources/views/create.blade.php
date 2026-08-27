{{ Form::open(['url' => route('campaigns.store'), 'enctype' => 'multipart/form-data', 'id' => 'formdata']) }}
<div class="row">

    <div class="form-group col-md-12">
        {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}
        {{ Form::text('name', null, ['class' => 'form-control font-style', 'required' => 'required', 'placeholder' => 'Enter Campaigns Name']) }}
    </div>

    <div class="form-group col-6">
        {{ Form::label('store', __('Store'), ['class' => 'form-label']) }}
        {!! Form::select('store', $store, null, [
            'class' => 'form-control select2 ',
            'required' => 'required',
        ]) !!}
        @error('category')
            <small class="invalid-role" role="alert">
                <strong class="text-danger">{{ $message }}</strong>
            </small>
        @enderror

    </div>

    <div class="form-group col-md-6">
        {{ Form::label('product', __('Product'), ['class' => 'form-label']) }}
        {!! Form::select('product', [], null, [
            'class' => 'form-control select2  product',
            'required' => 'required',
            'id' => 'product',
        ]) !!}
    </div>
    <div class="col-sm-6 form-group">
        {{ Form::label('Start Date', __('Start Date'), ['class' => 'form-control-label']) }}
        {{ Form::date('start_date', null, ['class' => 'form-control mt-2 start_date', 'required' => 'required', 'min' => \Carbon\Carbon::now()->format('Y-m-d'), 'onchange' => 'updateEndDateMin()']) }}
        @error('start_date')
            <span class="invalid-favicon text-xs text-danger" role="alert">{{ $message }}</span>
        @enderror
    </div>
    <div class="col-sm-6 form-group">
        {{ Form::label('End Date', __('End Date'), ['class' => 'form-control-label']) }}
        {{ Form::date('end_date', null, ['class' => 'form-control mt-2 end_date', 'required' => 'required']) }}
        @error('end_date')
            <span class="invalid-favicon text-xs text-danger" role="alert">{{ $message }}</span>
        @enderror
    </div>

    <div class="col-6 form-group">
        {{ Form::label('total_days', __('Total Days'), ['class' => 'form-label']) }}
        {{ Form::number('total_days', null, ['class' => 'form-control total_days', 'readonly' => 'readonly']) }}
        @error('total_days')
            <span class="invalid-favicon text-xs text-danger" role="alert">{{ $message }}</span>
        @enderror
    </div>
    <div class="col-6 form-group ">
        {{ Form::label('total_cost', __('Total Cost'), ['class' => 'form-label']) }}
        {{ Form::number('total_cost', null, ['class' => 'form-control total_cost', 'readonly' => 'readonly']) }}

        @error('total_cost')
            <span class="invalid-favicon text-xs text-danger" role="alert">{{ $message }}</span>
        @enderror
        <input type="hidden" name="total_price" class="total_price">
    </div>
    <div class="row coupon-div d-none">
        <div class="col-md-11">
            <div class="form-group">
                <label for="oupon" class="form-label">{{ __('Coupon') }}</label>
                <input type="text" id="bank_coupon" name="coupon_id" class="form-control coupon"
                    placeholder="{{ __('Enter Coupon Code') }}">
            </div>
        </div>
        <div class="col-1 my-auto">
            <a href="#" class="apply-btn btn btn-lg btn-light-primary btn-create apply-coupon-promote mt-2"
                data-bs-toggle="tooltip" data-bs-title="{{ __('Apply') }}"><span><i
                        data-feather="save"></i></span></a>
        </div>
        <small>{{ __('After apply coupon the final cost is ::') }} <b> <span class="coupon-price">- </span></b>
        </small>
    </div>

    <div class="row form-group">
        {{ Form::label('total_cost', __('Payment Method'), ['class' => 'form-label']) }}
        @php
            $enabledPayments = \Workdo\Campaigns\app\Models\Campaigns::isEnablePayment();
        @endphp
        @foreach ($enabledPayments as $payment)
            @if ($payment == 'Bank_transfer')
                @continue
            @endif
            <div class="col-sm-12 col-lg-6 col-md-6">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <div class="ms-3">
                                    <label for="{{ $payment }}-payment">
                                        <h5 class="mb-0 text-capitalize pointer payment-option"
                                            value="{{ $payment }}">{{ $payment }}</h5>
                                    </label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input payment_type" name="payment_type"
                                    id="{{ $payment }}-payment" type="radio" value="{{ $payment }}"
                                    class="payment-label">
                            </div>

                        </div>
                    </div>

                </div>

            </div>
        @endforeach
    </div>
    <!-- Additional fields for "payment pro" -->
    <div id="payment-pro" class="col-sm-12" style="display: none;">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <label>{{ __('Add Field for paiment pro') }}</label>
                    <div class="col-12 mb-3">
                        <label for="mobile_number" class="form-label">{{ __('Mobile Number') }}</label>
                        <input type="text" class="form-control" id="mobile_number" name="mobile_number">
                    </div>
                    <div class="col-12 mb-3">
                        <label for="card" class="form-label">{{ __('Channel') }}</label>
                        <input type="text" class="form-control" id="channel" name="channel">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Additional fields for "paytm" -->
    <div id="paytm" class="col-sm-12" style="display: none;">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <label>{{ __('Add Field for paytm') }}</label>
                    <div class="col-12 mb-3">
                        <label for="mobile" class="form-label">{{ __('Mobile Number') }}</label>
                        <input type="text" class="form-control" id="mobiler" name="mobile">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-footer pb-0">
    <button type="button" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
    <input class="btn btn-primary payfast_form btn-badge mx-1" type="submit" value="{{ __('Create') }}">
</div>
{{ Form::close() }}
<script>
    $(document).ready(function() {
        // Event listener for payment method radio buttons
        $("input[name='payment_type']").change(function() {
            if ($(this).val() === 'Paiementpro') {
                $("#payment-pro").slideDown(400, function() {
                    $("#payment-pro input").prop('required', true);
                });
            } else {
                $("#payment-pro input").prop('required', false);
                $("#payment-pro").slideUp(400);
            }
        });
    });
</script>
<script>
    $(document).ready(function() {
        // Event listener for payment method radio buttons
        $("input[name='payment_type']").change(function() {
            if ($(this).val() === 'Paytm') {
                $("#paytm").show();
                $("#paytm input").prop('required', true);
            } else {
                $("#paytm").hide();
                $("#paytm input").prop('required', false);
            }
        });
    });
</script>

<script>
    var selectProductId = '{{ isset($promoteData->store) ? $promoteData->stroe : 0 }}';

    function getProduct(store_id) {
        $.ajax({
            url: '{{ route('campaigns.product') }}',
            type: 'POST',
            data: {
                "store_id": store_id,
                "_token": "{{ csrf_token() }}",
            },
            success: function(data) {
                $('.product').empty();
                $('.product').append('<option value="">Select Product</option>');
                $.each(data, function(key, value) {
                    var selected = (key == selectProductId) ? 'selected' : '';
                    $('.product').append('<option value="' + key + '" ' + selected + '>' + value +
                        '</option>');
                });
            }
        });
    }


    $(document).on('change', 'select[name=store]', function() {
        var store_id = $(this).val();
        getProduct(store_id);
    });

    $(document).ready(function() {
        var initialCategoryId = '{{ isset($promoteData->store) ? $promoteData->store : 0 }}';
        if (initialCategoryId) {
            getProduct(initialCategoryId);
        }
    });

    function findPrice(total_days) {
        $.ajax({
            url: '{{ route('campaigns.costing') }}',
            type: 'POST',
            data: {
                "total_days": total_days,
                "_token": "{{ csrf_token() }}",
            },
            success: function(data) {
                if (data.costData != null && data.costData.price) {
                    $('.total_cost').val(data.costData.price * total_days);
                    $('.total_price').val(data.costData.price * total_days);
                    $('.coupon-div').removeClass('d-none');
                } else {
                    $('.total_cost').val(0);
                    $('.total_price').val(0);
                    $('.coupon-div').addClass('d-none');
                }
            }
        });
    }

    function calculateTotalDaysTotalCost() {
        var startDate = $('.start_date').val();
        var endDate = $('.end_date').val();

        if (startDate && endDate) {
            var startDateObj = new Date(startDate);
            var endDateObj = new Date(endDate);
            var timeDiff = endDateObj.getTime() - startDateObj.getTime();
            var totalDays = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
            $('.total_days').val(totalDays);
            findPrice(totalDays);

        } else {
            $('.total_days').val('');
        }
        var totalDays = parseInt($('.total_days').val(), 10);
    }
    $('.end_date, .start_date').change(calculateTotalDaysTotalCost);



    feather.replace();
    $(document).on('click', '.apply-coupon-promote', function() {
        var ele = $(this);
        var coupon = ele.closest('.row').find('.coupon').val();
        $.ajax({
            url: '{{ route('apply.coupon.promote') }}',
            type: 'GET',
            datType: 'json',
            data: {
                coupon: coupon,
                total_cost: $('.total_price').val()
            },
            headers: {
                'Content-Type': 'application/json'
            },
            success: function(data) {
                if (data.is_success == true) {
                    $('.coupon-price').text(data.final_price);
                    $('.total_cost').val(data.price);
                    show_toastr('Success', data.message, 'success')
                } else if (data.is_success == false) {
                    show_toastr('Error', data.message, 'error')

                } else {
                    show_toastr('Error', 'Coupon code is required', 'error')

                }
            }
        })
    });

    function updateEndDateMin() {
        var startDate = document.querySelector('.start_date').value;
        document.querySelector('.end_date').min = startDate;
    }
</script>
{{-- for payfast --}}
@php
    $payment_setting = getSuperAdminAllSetting();
    $payfast_mode = isset($payment_setting['payfast_mode']) ? $payment_setting['payfast_mode'] : '';
    $pfHost = $payfast_mode == 'sandbox' ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
@endphp

<script src="https://cdn.jsdelivr.net/jquery.validation/1.16.0/jquery.validate.min.js"></script>
<script src="https://cdn.jsdelivr.net/jquery.validation/1.16.0/additional-methods.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-steps/1.1.0/jquery.steps.min.js"></script>

<script>
    $(document).ready(function() {
        setTimeout(() => {
            $("#formdata").append("<div id='get-payfast-input-data'></div>");
        }, 100);
    });
    $(document).on("click", ".payfast_form", function(e) {
        e.preventDefault();
        var payment_type = $('input[name="payment_type"]:checked').val();
        if (payment_type == 'Payfast') {
            get_payfast_status();
        } else {
            $('#formdata').submit();
        }
    });

    function get_payfast_status(amount, coupon) {
        var formdata = $('#formdata').serializeArray();

        $.ajax({
            url: "{{ route('campaigns.store') }}",
            method: 'POST',
            data: formdata,
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(data) {
                if (data.success == true) {
                    $('#get-payfast-input-data').append(data.inputs);
                    $('#formdata').submit();
                } else {
                    show_toastr('Error', data.inputs, 'error')
                }
            }
        });
    }
    $(".payfast_form").on("click", function(e) {
        var payment_type = $('input[name="payment_type"]:checked').val();

        if (payment_type == 'Payfast') {
            $('#formdata').attr('action', "https://{{ $pfHost }}/eng/process");
            e.preventDefault();
        }
    });
</script>
