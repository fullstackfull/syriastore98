@extends('front_end.layouts.app')

@section('page-title')
{{ __('Checkout Page') }}
@endsection

@section('content')
<main>
    @if ($themeSettings['checkout_banner_status'] && $themeSettings['checkout_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['checkout_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    {{ $themeSettings['checkout_banner_title'] ?? __('Checkout Page') }}</h2>
            </div>
        </div>
    </section>
    @endif

    @if ($themeSettings['checkout_status'] && $themeSettings['checkout_status'] == '1')
   <section class="py-10 lg:py-20">
        <div class="md:container w-full mx-auto px-4">
            {!! Form::open([
                'route' => ['payment.process', $store->slug],
                'method' => 'post',
                'enctype' => 'multipart/form-data',
                'id' => 'formdata',
            ]) !!}
            @csrf
                <div class="flex flex-col lg:flex-row gap-8">
                    <!-- Left Side - Checkout Forms -->
                    <div class="lg:w-2/3">
                        <div class="md:space-y-8 space-y-6">
                            <div>
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">1. {{ __('Customer Information') }}</h2>
                                <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <!-- First Name -->
                                        <div>
                                            <label for="first-name" class="block mb-2 font-medium md:text-base text-sm">{{ __('First Name') }} <span
                                                class="text-red-500">*</span></label>
                                            {!! Form::text('billing_info[firstname]', !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->first_name : '', [
                                                'class' => 'form-input',
                                                'placeholder' => 'John',
                                                'id' => 'first-name',
                                                'required' => true
                                            ]) !!}
                                        </div>

                                        <!-- Last Name -->
                                        <div>
                                            <label for="last-name" class="block mb-2 font-medium md:text-base text-sm">{{ __('Last Name') }} <span
                                                class="text-red-500">*</span></label>
                                            {!! Form::text('billing_info[lastname]', !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->last_name : '', [
                                                'class' => 'form-input',
                                                'placeholder' => 'Doe',
                                                'id' => 'last-name',
                                                'required' => true
                                            ]) !!}
                                        </div>

                                        <!-- Email Field -->
                                        <div>
                                            <label for="email" class="block mb-2 font-medium md:text-base text-sm">{{ __('Email Address') }} <span
                                                class="text-red-500">*</span></label>
                                            {!! Form::email('billing_info[email]', !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->email : '', [
                                                'class' => 'form-input',
                                                'placeholder' => 'shop@company.com',
                                                'id' => 'email',
                                                'required' => true
                                            ]) !!}
                                        </div>

                                        <!-- Phone Number -->
                                        <div>
                                            <label for="phone" class="block mb-2 font-medium md:text-base text-sm">{{ __('Phone Number') }} <span
                                                class="text-red-500">*</span></label>
                                            {!! Form::number('billing_info[billing_user_telephone]', !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->mobile : '', [
                                                'class' => 'form-input',
                                                'placeholder' => '1234567890',
                                                'id' => 'phone',
                                                'required' => true
                                            ]) !!}
                                        </div>
                                    </div>

                                    @if (\App\Models\Utility::CustomerAuthCheck($store->slug) != true)
                                        <!-- Account Section -->
                                        <div class="mb-4 mt-4">
                                            <div class="checkbox flex items-center mb-2 gap-2">
                                                <input type="checkbox" id="register" name="register" value="{{ old('register') }}"
                                                    class="rounded border-gray-300 text-primary focus:ring-primary">
                                                <label for="create-account" class="flex-1 font-medium">{{ __('Create an account?') }}</label>
                                            </div>
                                            <p class="text-sm text-gray-500">{{ __('Create an account for faster checkout and to track your orders') }}</p>
                                        </div>
                                    @endif

                                    <!-- Password Fields (Initially Hidden) -->
                                    {{-- <div class="mb-4 hidden">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label for="password" class="block mb-2 font-medium md:text-base text-sm">Password <span
                                                        class="text-red-500">*</span></label>
                                                <input type="password" id="password" class="form-input" />
                                            </div>
                                            <div>
                                                <label for="confirm-password" class="block mb-2 font-medium md:text-base text-sm">Confirm
                                                    Password <span class="text-red-500">*</span></label>
                                                <input type="password" id="confirm-password" class="form-input" />
                                            </div>
                                        </div>
                                    </div> --}}

                                    <!-- Delivery Method -->
                                    {{-- <div>
                                        <label class="block mb-2 font-medium md:text-base text-sm">Delivery Method <span
                                                class="text-red-500">*</span></label>
                                        <div class="space-y-3">
                                            <div class="radio-btn flex items-center p-3 border rounded-md">
                                                <input type="radio" id="delivery-standard" name="delivery-method"
                                                    value="standard" class="text-primary focus:ring-primary mr-2"
                                                    checked />
                                                <label for="delivery-standard"
                                                    class="flex flex-col md:flex-row md:items-center justify-between w-full">
                                                    <span class="font-medium">Standard Delivery (1-2 days)</span>
                                                    <span class="font-medium text-primary-dark">Free</span>
                                                </label>
                                            </div>
                                            <div class="radio-btn flex items-center p-3 border rounded-md">
                                                <input type="radio" id="delivery-express" name="delivery-method"
                                                    value="express" class="text-primary focus:ring-primary mr-2" />
                                                <label for="delivery-express"
                                                    class="flex flex-col md:flex-row md:items-center justify-between w-full">
                                                    <span class="font-medium">Express Delivery (Same Day)</span>
                                                    <span class="font-medium text-primary-dark">$5.99</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div> --}}
                                </div>
                            </div>

                            <div class="mt-4">
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">2. {{ __('Billing Information') }}</h2>
                                <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">

                                    <div class="selling_address_form col-12">
                                    </div>
                                </div>
                            </div>                          
                            
                            <!-- Shipping Address -->
                            <div class="mt-4">
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">3. {{ __('Shipping Address') }}</h2>
                                <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
                                    <div class="checkbox flex items-center gap-2">
                                        <input type="checkbox" id="same-address" name="delivery_not_same" class="rounded border-gray-300 text-primary focus:ring-primary"> {{-- checked --}}
                                        <label for="same-address" class="flex-1 font-medium">{{ __('Ship To A Different Address?') }}</label>
                                    </div>

                                    <!-- Shipping address fields will be shown when "Same as billing address" is unchecked -->
                                    <div class="shipping_address_form col-12">
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">4. {{ __('Payment Method') }}</h2>
                                <div class="bg-gray-50 border rounded-lg shadow-sm">
                                    <!-- Payment Options -->
                                    <div class="space-y-3 md:p-6 p-4 max-h-[484px] overflow-y-auto">
                                        <input type="hidden" name="payment_type" class="payment_types" value="cod">
                                        @foreach ($payments as $key => $payment)
                                            @if ($payment['status'] == 'on')
                                                <div class="radio-btn flex p-3 border rounded-md gap-3 flex-col">
                                                    <div class="flex ">
                                                        <input type="radio" id="{{ $payment['name'] }}" name="payment_setting_id" {{ $key == 0 ? 'checked' : '' }} value="{{ $payment['name'] }}" onchange="document.getElementsByName('payment_type')[0].value = '{{ $payment['name'] }}'" class="text-primary focus:ring-primary mr-2 payment_change">
                                                        <label for="{{ $payment['name'] }}" class="flex items-center w-full flex-wrap">
                                                            <span class="font-medium mr-3">{{ $payment['name_string'] }}</span>
                                                            <div class="center-descrp text-sm"> {{ $payment['detail'] }} </div>
                                                            {{-- <div class="radio-right flex gap-2" style="height: 30px;">
                                                                <img src="{{ asset($payment['image']) }}" alt="{{ $payment['name'] ?? 'cod'}}" class="paymentimag{{ $payment['name'] }}" style="height: 100%; width: 100%;">
                                                            </div> --}}
                                                        </label>
                                                    </div>

                                                    @if($payment['name'] == 'whatsapp')
                                                        <form method="POST" action="{{ route('user.whatsapp',$slug) }}" class="payment-method-form">
                                                            @csrf
                                                            <div class="form-group w-100">
                                                                <input name="wts_number" class="form-input max-w-md phone-number" value="{{ old('wts_number') }}"  id="wts_number" type="text" placeholder="{{ __('Enter Your Phone Number') }}">
                                                            </div>

                                                        </form>
                                                    @endif
                                                    @if($payment['name'] == 'bank_transfer')
                                                        <div class="form-group w-100">
                                                            <input type="file" name="payment_receipt" class="form-input max-w-md mb-3 bank_transfer_receipt">
                                                        </div>
                                                    @endif
                                                    @if($payment['name'] == 'Paiementpro')
                                                    <div class="flex gap-3 sm:flex-row flex-col">
                                                        <div class="form-group" id="mobile_div">
                                                            <input type="text" name="mobile_number" value="{{ old('mobile_number') }}"  class="form-input max-w-md font-style mobile_number" id="mobile_number" placeholder="{{ __('Enter Your Phone Number') }}">
                                                        </div>
                                                        <div class="form-group flex flex-col gap-2" id="channel_div">
                                                            <input type="text" name="channel" value="{{ old('channel') }}"  class="form-input max-w-md font-style channel" id="channel" placeholder="{{ __('Enter Your Phone Number') }}">
                                                            <small class="text-red-500">{{ __('Example') }} : OMCIV2,MOMO,CARD,FLOOZ ,PAYPAL</small>
                                                        </div>
                                                    </div>
                                                    @endif
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">5. {{ __('Order Notes') }}</h2>
                                <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
                                    <label class="block mb-2 font-medium md:text-base text-sm">{{ __('Add Comments About Your Order') }}</label>
                                    <textarea class="form-input" name="payment_comment" placeholder="Description" rows="3"></textarea>
                                </div>
                            </div>
                        
                            @stack('CustomFieldView')
                            @stack('addCheckoutAttachment')

                            @if (isset($settings['additional_notes']) && $settings['additional_notes'] == 'on')
                                <div class="mt-4">
                                    <h2 class="font-semibold text-xl md:mb-4 mb-3">6. {{ __('Additional Notes') }}</h2>
                                    <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
                                        <div class="acnav-list additional_notes">

                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    @include('front_end.hooks.checkout_form')

                    {!! Form::hidden('coupon_code', null, ['id' => 'coupon_code']) !!}
                    {!! Form::hidden('sub_total', null, ['id' => 'sub_total_checkout_page']) !!}
                    <!-- Right Side - Order Summary -->
                    <div class="lg:w-1/3">
                        <div class="checkout_page_cart col-lg-3 col-12 ">
                            <div class="checkout-page-right">
        
                            </div>
                        </div>
                    </div>
                </div>
            {!! Form::close() !!}
        </div>
    </section>
    @endif
</main>
@endsection

@php
    $payfast_mode = \App\Models\Utility::GetValueByName('payfast_mode', $store->id);
    $pfHost = $payfast_mode == 'sandbox' ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
@endphp

@push('page-script')
    
<script>
    'use strict';
    $(document).ready(function() {
        loadAddressForm("billing", ".selling_address_form");
    
        handleShippingAddress();

        loadAdditionalNote();
    });

    $(document).on("change", "#same-address", function() {
        handleShippingAddress();
    });

    function handleShippingAddress() {
        if ($("#same-address").is(":checked")) {
            loadAddressForm("delivery", ".shipping_address_form");
        } else {
            $(".shipping_address_form").empty();
        }
    }

    function loadAddressForm(type, targetElement) {
        $.ajax({
            url: "{{ route('order.address.form',$slug) }}",
            method: "POST",
            data: {
                type: type
            },
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
            success: function(response) {

                $(targetElement).html(response.data.html);
                // common_event();
            },
            error: function(xhr, status, error) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    show_toastr('Error', res.message || 'Something went wrong', 'error');
                } catch (e) {
                    show_toastr('Error', 'Something went wrong', 'error');
                }
            },
        });
    }

    function loadAdditionalNote(type, targetElement) {
        $.ajax({
            url: additionalnote,
            method: 'GET',
            context: this,
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
            success: function (response) {
                $('.paymentlist_data_tab').removeClass('is-open');
                $('.paymentlist_data').hide();
                $('.additional_notes').html(response.html_data);
                $('.additional_notes_tab').addClass('is-open');
            },
            error: function(xhr, status, error) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    show_toastr('Error', res.message || 'Something went wrong', 'error');
                } catch (e) {
                    show_toastr('Error', 'Something went wrong', 'error');
                }
            }
        });
    }
</script>

    <script src="https://cdn.jsdelivr.net/jquery.validation/1.16.0/jquery.validate.min.js"></script>
    <script src="https://cdn.jsdelivr.net/jquery.validation/1.16.0/additional-methods.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-steps/1.1.0/jquery.steps.min.js"></script>
    <script>
        $(document).ready(function() {
            setTimeout(() => {
                $("#formdata").append("<div id='get-payfast-input-data'></div>");
            }, 100);
        });
        $(document).on("click", ".payfast_form", function(event) {

            var payment_type = $('.payment_types').val();
            console.log(payment_type);
            
            if (payment_type == 'payfast') {
                get_payfast_status();
            }else{
                $('#formdata').submit();
            }
        });
        @if (\Auth::guard('customers')->user())
            function get_payfast_status(amount,coupon){
                var formdata = $('#formdata').serializeArray();
                var slug = '{{ $store->slug }}';
                $.ajax({
                    url: payfast_payment,
                    method: 'POST',
                    data: formdata,
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function (data) {
                        if (data.success == true) {
                            $('#get-payfast-input-data').append(data.inputs);
                            $('#formdata').submit();
                        }else{
                            show_toastr('Error', data.inputs, 'error')
                        }
                    }
                });
            }
        @else
            function get_payfast_status(amount,coupon){
                var formdata = $('#formdata').serializeArray();
                var slug = '{{ $store->slug }}';
                $.ajax({
                    url: payfast_payment_guest,
                    method: 'POST',
                    data: formdata,
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function (data) {
                        if (data.success == true) {
                            $('#get-payfast-input-data').append(data.inputs);
                            $('#formdata').submit();
                        }else{
                            show_toastr('Error', data.inputs, 'error')
                        }
                    }
                });
            }
        @endif
        $(".payfast_form").on("click", function(e) {
            var payment_type = $('#payment_type').val();

            if (payment_type == 'payfast') {
                $('#formdata').attr('action', "https://{{ $pfHost }}/eng/process");
                e.preventDefault();
            }
        });
    </script>


<script>
(function() {

    var originalAjax = $.ajax;

    var pendingCityAjaxSettings = null;
    var lastValidCitySettings = null;
    var activeShippingState = null;
    var isInternalRetrigger = false;

    function injectShippingParams(data, val, label) {
        val = val || '';
        label = label || '';

        if (typeof data === 'string') {
            data = data.replace(/&?shipping_id=[^&]*/g, '').replace(/&?shipping_label=[^&]*/g, '');
            var prefix = data.length > 0 ? '&' : '';
            return data + prefix + 'shipping_id=' + encodeURIComponent(val) + '&shipping_label=' + encodeURIComponent(label);
        } else if (window.FormData && data instanceof FormData) {
            data.set('shipping_id', val);
            data.set('shipping_label', label);
            return data;
        } else if (typeof data === 'object' && data !== null) {
            data.shipping_id = val;
            data.shipping_label = label;
            return data;
        } else {
            return 'shipping_id=' + encodeURIComponent(val) + '&shipping_label=' + encodeURIComponent(label);
        }
    }

    function getCheckedShippingInfo($radios) {
        var $checked = $radios.filter(':checked');
        return {
            val: $checked.val(),
            label: $.trim($checked.closest('label').text()) || $.trim($checked.parent().text())
        };
    }

    $(document).on('change', '#state', function() {
        if (isInternalRetrigger) {
            isInternalRetrigger = false;
            return;
        }

        console.log("🔄 تغيرت المحافظة (فعلياً من المستخدم).. تختيم الشحن الحالي كـ stale");

        $('input[name="shipping_id"]').attr('data-stale', 'true').prop('checked', false);

        activeShippingState = null;
        pendingCityAjaxSettings = null;
        lastValidCitySettings = null;
    });

    // 🚨 الإصلاح الأساسي: بدل الاعتماد على $.ajaxSetup (اللي بينتجاهل مع global:false)
    // منعمل override مباشر لـ $.ajax نفسها، فمنمسك كل ريكويست 100% بغض النظر عن أي إعداد.
    $.ajax = function(settings) {

        var url = (typeof settings === 'string') ? settings : (settings && settings.url) || '';

        if (url.indexOf('city-list') !== -1) {

            var $freshRadios = $('input[name="shipping_id"]:not([data-stale="true"])');
            var $checkedFresh = $freshRadios.filter(':checked');

            if ($freshRadios.length === 0 || $checkedFresh.length === 0 || activeShippingState !== $('#state').val()) {
                console.warn("⏸️ [City Hold] تم اعتراض ريكويست city-list مباشرة (override) .. بانتظار شحن المحافظة الجديدة.");
                pendingCityAjaxSettings = $.extend(true, {}, settings);
                // منرجع Deferred مرفوض هادئ حتى ما ينكسر أي .then/.done/.fail معلق عليه بالكود الأصلي
                return $.Deferred().reject({}, 'abort', 'held').promise();
            }

            var info = getCheckedShippingInfo($freshRadios);
            console.log("🚀 [City Send]", info.label, "| ID:", info.val);

            settings.data = injectShippingParams(settings.data, info.val, info.label);
            lastValidCitySettings = $.extend(true, {}, settings);
        }

        var jqXHR = originalAjax.apply(this, arguments);

        if (url.indexOf('city-list') === -1 && url.indexOf('shipping-method') !== -1) {
            jqXHR.done(function() {
                var $freshRadios = $('input[name="shipping_id"]:not([data-stale="true"])');

                if ($freshRadios.length > 0 && activeShippingState !== $('#state').val()) {
                    console.log("✨ وصل شحن المحافظة الجديدة! تحديث + إطلاق المدن المحجوزة...");
                    activeShippingState = $('#state').val();

                    if ($freshRadios.filter(':checked').length === 0) {
                        $freshRadios.first().prop('checked', true);
                    }

                    var info = getCheckedShippingInfo($freshRadios);

                    if (pendingCityAjaxSettings) {
                        console.log("▶️ إطلاق طلب المدن المحجوز...");
                        var settingsToRun = $.extend(true, {}, pendingCityAjaxSettings);
                        settingsToRun.data = injectShippingParams(settingsToRun.data, info.val, info.label);

                        pendingCityAjaxSettings = null;
                        $.ajax(settingsToRun);
                    } else {
                        isInternalRetrigger = true;
                        $('#state').trigger('change');
                    }
                }
            });
        }

        return jqXHR;
    };

    $(document).on('change', 'input[name="shipping_id"]', function() {
        var $checked = $(this);

        if ($checked.attr('data-stale') !== 'true' && lastValidCitySettings) {
            var info = getCheckedShippingInfo($(this));

            console.log("🔄 تبديل وسيلة الشحن يدوياً.. تحديث المدن فقط");

            var newSettings = $.extend(true, {}, lastValidCitySettings);
            newSettings.data = injectShippingParams(newSettings.data, $checked.val(),
                $.trim($checked.closest('label').text()) || $.trim($checked.parent().text()));

            $.ajax(newSettings);
        }
    });

})();
</script>
@endpush