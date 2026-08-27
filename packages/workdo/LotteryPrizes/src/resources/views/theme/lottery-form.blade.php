<style>
    .payment-method-form {
        display: flex;
        width: 100%;
        flex-wrap: wrap;
        padding: 15px 15px 15px 30px;
    }

    .payment-method-form .radio-group label {
        padding: 2px 8px;
        border: 0;
        max-width: 200px;
        width: 100%;
        margin: 0; 
    } 
    .payment-method-form .radio-group {
        margin: 0 0px 10px 0px;
        max-width: 25%;
        width: 100%;
    }
    .modal-footer {
        justify-content: flex-start;
    }
    .modal-footer .btn {
        width: auto !important;
    }
    .checkout-page .login-form .form-heading h3, .check-head{
        padding: 0 15px; 
    }
   .payment-methods-container{
        margin-bottom: 0;
    }
    .payment-methods-container .check-head{ 
        padding: 0;
    }
    .address-details .form-container {
        padding: 20px 20px 0 20px;
    }
    @media only screen and (max-width: 991px) {
        .payment-method-form .radio-group { 
        max-width: 33.33%; 
    }
    }
    @media only screen and (max-width: 767px) {
        .payment-method-form .radio-group { 
        max-width: 50%; 
    }
    .address-details .form-container {
        padding: 12px 12px 0 12px;
    }
    }
    @media only screen and (max-width: 575px) {
        .payment-method-form .radio-group { 
        max-width: 100%; 
    }
    .payment-method-form { 
    padding: 10px 10px 10px 20px;
}

    }
</style>
{{ Form::open(['route' => array('lottery-form.data.store', $store->slug), 'method' => 'post', 'enctype' => 'multipart/form-data', 'id'=> 'lottery-form']) }}

<div class="row">
    <h3 class="check-head">{{ __('Your Personal Details') }}</h3>
    <div class="personal-info-form">
        <div class="form-container">
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <label>{{ __('First Name') }}<sup aria-hidden="true">*</sup>:</label>
                        {!! Form::text(
                        'lottery_info[firstname]',
                        !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->first_name : '',
                        [
                        'class' => 'form-control',
                        'placeholder' => 'John',
                        'required' => true,
                        ],
                        ) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <label>{{ __('Last Name') }}<sup aria-hidden="true">*</sup>:</label>
                        {!! Form::text(
                        'lottery_info[lastname]',
                        !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->last_name : '',
                        [
                        'class' => 'form-control',
                        'placeholder' => 'Doe',
                        'required' => true,
                        ],
                        ) !!}

                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <label>{{ __('E-mail') }}<sup aria-hidden="true">*</sup>:</label>
                        {!! Form::email(
                        'lottery_info[email]',
                        !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->email : '',
                        [
                        'class' => 'form-control',
                        'placeholder' => 'shop@company.com',
                        'required' => true,
                        ],
                        ) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <label>{{ __('Telephone') }}<sup aria-hidden="true">*</sup>:</label>
                        {!! Form::number(
                        'lottery_info[lottery_user_telephone]',
                        !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->mobile : '',
                        [
                        'class' => 'form-control',
                        'placeholder' => '1234567890',
                        ],
                        ) !!}
                    </div>
                </div>
            </div>
        </div>
    </div>
    <section class="address-details">
        <h3 class="check-head">{{ __('Your Address') }}</h3>
        <div class="form-container">
            <div class="row">
                @auth('customers')
                @if (!empty($address_list->data->data))
                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <label> {{ __('Address') }} <sup
                                aria-hidden="true">*</sup>:</label>
                        <select
                            class="form-control lottery_address_list shipping_list">
                            <option value="">{{ __('select address') }}
                            </option>
                            @foreach ($address_list->data->data as $address)
                            <option value="{{ $address->id }}">
                                {{ $address->title }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <a href="{{ route('my-account.index', $store->slug) }}">
                            <i class="ti ti-circle-plus" style="font-size: 25px;"></i>
                        </a>
                    </div>
                </div>
                @endif
                @endauth

                @if (auth('customers')->user() && empty($address_list->data->data))
                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <label>{{ __('Address') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::text('lottery_info[lottery_address]', null, [
                        'class' => 'form-control getvalueforval',
                        'placeholder' => 'address',
                        'required' => true,
                        ]) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12 list_height_css">
                    <div class="form-group">
                        <label>{{ __('Country') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::select('lottery_info[lottery_country]', $country_option, null, [
                        'class' => 'form-control country_change',
                        'required' => true,
                        ]) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12 list_height_css">
                    <div class="form-group">
                        <label>{{ __('Region') }} / {{ __('State') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::select('lottery_info[lottery_state]', [], null, [
                        'class' => 'form-control state_name state_chage',
                        'placeholder' => 'Select State',
                        'required' => true,
                        'data-select' => 0,
                        ]) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12 list_height_css">
                    <div class="form-group">
                        <label>{{ __('City') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::select('lottery_info[lottery_city]', [], null, [
                        'class' => 'form-control city_change',
                        'placeholder' => 'Select City',
                        'required' => true,
                        'data-select' => 0,
                        ]) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <label>{{ __('Post Code') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::text('lottery_info[lottery_postecode]', null, [
                        'class' => 'form-control getvalueforval',
                        'placeholder' => 'post code',
                        ]) !!}
                    </div>
                </div>
                @endif

                @if (\App\Models\Utility::CustomerAuthCheck($store->slug) != true)
                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <label>{{ __('Address') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::text('lottery_info[lottery_address]', null, [
                        'class' => 'form-control getvalueforval',
                        'placeholder' => 'address',
                        'required' => true,
                        ]) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12 list_height_css">
                    <div class="form-group">
                        <label>{{ __('Country') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::select('lottery_info[lottery_country]', $country_option, null, [
                        'class' => 'form-control country_change',
                        'required' => true,
                        ]) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12 list_height_css">
                    <div class="form-group">
                        <label>{{ __('Region') }} / {{ __('State') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::select('lottery_info[lottery_state]', [], null, [
                        'class' => 'form-control state_name state_chage',
                        'placeholder' => 'Select State',
                        'required' => true,
                        'data-select' => 0,
                        ]) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12 list_height_css">
                    <div class="form-group">
                        <label>{{ __('City') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::select('lottery_info[lottery_city]', [], null, [
                        'class' => 'form-control city_change',
                        'placeholder' => 'Select City',
                        'required' => true,
                        'data-select' => 0,
                        ]) !!}
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="form-group">
                        <label>{{ __('Post Code') }}<sup
                                aria-hidden="true">*</sup>:</label>
                        {!! Form::text('lottery_info[lottery_postecode]', null, [
                        'class' => 'form-control getvalueforval',
                        'placeholder' => 'post code',
                        ]) !!}
                    </div>
                </div>
                @endif
                <input type="hidden" class="hidden" name="product_id" value={{$product->id}}>
                <input type="hidden" class="hidden" name="ticket_price" value={{$lottery_setting->ticket_price}}>
                <div class="col-12">
                    <div class=" mt-3">
                        <div class="payment-methods-container payment-checkbox">
                            <span class="payment-label d-none"><b>{{ __('Payment Method') }}</b></span>
                            <div class="method-select">
                                <h3 class="check-head">{{ __('Select Your Payments') }}</h3>
                                <div class="payment-method-form">
                                    @if ($payment_list->status == 1)
                                    @foreach ($payment_list->data as $payment_key => $payment_data)
                                    @if ($payment_data->status == 'on')
                                    <div class="radio-group">
                                        <div class="payment">
                                            <input class="payment_method" type="radio" name="payment_methods" id="payment_method_{{$payment_key}}" value="{{ ucfirst($payment_data->name_string) }}">
                                            <label class="form-check-label" for="payment_method_{{$payment_key}}">
                                                {{ $payment_data->name_string }}</label>
                                        </div>
                                    </div>
                                    @endif
                                    @endforeach
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <div class="modal-footer pb-0">
        <div class="d-flex acc-back-btn-wrp align-items-center justify-content-end"></div>
        <input type="button" value="{{__('Cancel')}}" class="btn btn-light" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-primary ticket_payfast_payment">
    </div>
</div>
</div>
{!! Form::close() !!}
@php
$theme_name = !empty($currentTheme) ? $currentTheme : env('DATA_INSERT_APP_THEME');
$payfast_mode = \App\Models\Utility::GetValueByName('payfast_mode', $theme_name);
$pfHost = $payfast_mode == 'sandbox' ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
@endphp
<script>
    $(document).on('change', '.country_change', function(e) {
        var country_id = $(this).val();
        var data = {
            country_id: country_id
        };

        $.ajax({
            url: state_list,
            method: 'POST',
            data: data,
            context: this,
            success: function(response) {
                var $stateSelect = $(this).closest('.row').find('.state_chage');

                $(this).closest('.row').find('.nice-select.state_chage').remove();
                var state = $stateSelect.attr('data-select') || '';

                var options = '';

                $.each(response, function(i, item) {
                    var selected = (i == state) ? 'selected' : '';
                    options += '<option value="' + i + '" ' + selected + '>' + item + '</option>';
                });

                $stateSelect.html(options);
                $stateSelect.val(state || '0');
                $stateSelect.niceSelect();

                if (state && state !== '0') {
                    $stateSelect.trigger('change');
                }
            }
        });
    });

    $(document).on('change', '.state_chage', function(e) {
        var state_id = $(this).val();
        var data = {
            state_id: state_id
        };

        $.ajax({
            url: city_list,
            method: 'POST',
            data: data,
            context: this,
            success: function(response) {
                var $citySelect = $(this).closest('.row').find('.city_change');

                $(this).closest('.row').find('.nice-select.city_change').remove();
                var city = $citySelect.attr('data-select') || '';
                var options = '';

                $.each(response, function(i, item) {
                    var selected = (i == city) ? 'selected' : '';
                    options += '<option value="' + i + '" ' + selected + '>' + item + '</option>';
                });

                $citySelect.html(options);
                $citySelect.val(city || '0');
                $citySelect.niceSelect();

                if (city && city !== '0') {
                    $citySelect.trigger('change');
                }
            }
        });
    });


    $(document).ready(function() {
        setTimeout(() => {
            $("#lottery-form").append("<div id='get-payfast-input-data'></div>");
        }, 100);
    });
    $(document).on("click", ".ticket_payfast_payment", function(event) {
        var payment_type = $('input[name="payment_methods"]:checked').val();
        if (payment_type == 'payfast') {
            get_payfast_status();
        } else {
            $('#lottery-form').submit();
        }
    });

    function get_payfast_status(amount, coupon) {
        var formdata = $('#lottery-form').serializeArray();
        var slug = '{{ $store->slug }}';
        $.ajax({
            url: "{{ route('lottery-form.data.store', $store->slug) }}",
            method: 'POST',
            data: formdata,
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(data) {
                $('#loader').fadeOut();
                if (data.success == true) {
                    $('#get-payfast-input-data').append(data.inputs);
                    $('#lottery-form').submit();
                } else {
                    show_toastr('Error', data.inputs, 'error')
                }
            }
        });
    }
    $(".ticket_payfast_payment").on("click", function(e) {
        var payment_type = $('input[name="payment_methods"]:checked').val();

        if (payment_type == 'payfast') {
            $('#lottery-form').attr('action', "https://{{ $pfHost }}/eng/process");
            e.preventDefault();
        }
    });
</script>