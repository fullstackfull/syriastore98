@php
    $currentTheme = $store->theme_id ?? 'greentic';
    $themeColors = [
        'greentic' => [
            'primary' => '#4caf50',
            'primary_dark' => '#388e3c',
            'primary_light' => '#4CAF501A',
            'accent' => '#ff9800',
            'accent_dark' => '#f57c00',
            'white' => '#ffffff',
            'black' => '#060606'
        ],
        'stylique' => [
            'primary' => '#f8796c',
            'primary_dark' => '#e55a4a',
            'primary_light' => '#f8796c1A',
            'accent' => '#6c63ff',
            'accent_dark' => '#5a52e6',
            'white' => '#ffffff',
            'black' => '#333333'
        ],
        'techzonix' => [
            'primary' => '#007bff',
            'primary_dark' => '#0056b3',
            'primary_light' => '#007bff1A',
            'accent' => '#28a745',
            'accent_dark' => '#1e7e34',
            'white' => '#ffffff',
            'black' => '#212529'
        ]
    ];
    $colors = $themeColors[$currentTheme] ?? $themeColors['greentic'];
@endphp

@extends('front_end.layouts.app')
@section('page-title')
{{ __('Donation Form') }}
@endsection
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
@section('content')
<section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['product_list_banner_image'] ?? '') }}');">
    <div class="md:container w-full mx-auto px-4">
    <div class="text-center relative z-[2]">
        <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ __('Make Donation') }}</h2>
         <p class="coupon-subtitle">{{ __('Choose how you want to help') }}</p>
    </div>
    </div>
</section>
<section class="py-16 bg-gray-50 relative">
    <div class="max-w-5xl mx-auto px-4">
        
        <!-- Progress Steps -->
        <div class="mb-12">
            <div class="flex items-center justify-center space-x-8">
                <div class="flex items-center" style="color: {{ $colors['primary'] }};">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold" style="background: {{ $colors['primary'] }};">1</div>
                    <span class="ml-3 font-medium">{{ __('Your Info') }}</span>
                </div>
                <div class="w-16 h-1 bg-gray-300 rounded"></div>
                <div class="flex items-center text-gray-400">
                    <div class="w-10 h-10 bg-gray-300 rounded-full flex items-center justify-center text-white font-bold">2</div>
                    <span class="ml-3 font-medium">{{ __('Donation') }}</span>
                </div>
                <div class="w-16 h-1 bg-gray-300 rounded"></div>
                <div class="flex items-center text-gray-400">
                    <div class="w-10 h-10 bg-gray-300 rounded-full flex items-center justify-center text-white font-bold">3</div>
                    <span class="ml-3 font-medium">{{ __('Payment') }}</span>
                </div>
            </div>
        </div>

        <form id="donation-form" action="{{ route('customerdonation.store', $store->slug) }}" method="POST" class="space-y-8">
            @csrf

            <!-- Personal Information Card -->
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden transform hover:scale-[1.01] transition-all duration-300">
                <div class="p-6" style="background: linear-gradient(135deg, {{ $colors['primary'] }}, {{ $colors['accent'] }});">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-white bg-opacity-20 rounded-xl flex items-center justify-center mr-4">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-white">{{ __('Personal Information') }}</h2>
                            <p class="text-indigo-100">{{ __('Tell us a bit about yourself') }}</p>
                        </div>
                    </div>
                </div>
                
                <div class="p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div class="form-group">
                            {!! Form::label('name', __('Full Name'), ['class' => 'block text-sm font-semibold text-gray-700 mb-2']) !!}
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                </div>
                                {!! Form::text('name', old('name'), ['class' => 'w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200 bg-gray-50 focus:bg-white', 'placeholder' => 'Enter your full name', 'required']) !!}
                            </div>
                        </div>

                        <div class="form-group">
                            {!! Form::label('email', __('Email Address'), ['class' => 'block text-sm font-semibold text-gray-700 mb-2']) !!}
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path>
                                    </svg>
                                </div>
                                {!! Form::email('email', old('email'), ['class' => 'w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200 bg-gray-50 focus:bg-white', 'placeholder' => 'your@email.com', 'required']) !!}
                            </div>
                        </div>

                        <div class="form-group">
                            {!! Form::label('number', __('Phone Number'), ['class' => 'block text-sm font-semibold text-gray-700 mb-2']) !!}
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                    </svg>
                                </div>
                                {!! Form::number('number', old('number'), ['class' => 'w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200 bg-gray-50 focus:bg-white', 'placeholder' => '+1 (555) 123-4567', 'required']) !!}
                            </div>
                        </div>

                        <div class="form-group lg:col-span-3">
                            {!! Form::label('address', __('Address'), ['class' => 'block text-sm font-semibold text-gray-700 mb-2']) !!}
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                </div>
                                {!! Form::text('address', old('address'), ['class' => 'w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200 bg-gray-50 focus:bg-white', 'placeholder' => 'Enter your full address', 'required']) !!}
                            </div>
                        </div>

                        <div class="form-group">
                            {!! Form::label('country_id', __('Country'), ['class' => 'block text-sm font-semibold text-gray-700 mb-2']) !!}
                            {!! Form::select('country_id', $country_option, old('country_id'), ['class' => 'w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200 bg-gray-50 focus:bg-white country_change', 'id' => 'countryList']) !!}
                        </div>

                        <div class="form-group">
                            {!! Form::label('state_id', __('State/Province'), ['class' => 'block text-sm font-semibold text-gray-700 mb-2']) !!}
                            {!! Form::select('state_id', [], old('state_id'), ['class' => 'w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200 bg-gray-50 focus:bg-white state_name state_chage', 'id' => 'stateList']) !!}
                        </div>

                        <div class="form-group">
                            {!! Form::label('city_id', __('City'), ['class' => 'block text-sm font-semibold text-gray-700 mb-2']) !!}
                            {!! Form::select('city_id', [], old('city_id'), ['class' => 'w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200 bg-gray-50 focus:bg-white city_name city_change', 'id' => 'cityList']) !!}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Donation Details Card -->
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden transform hover:scale-[1.01] transition-all duration-300">
                <div class="bg-gradient-to-r from-emerald-500 to-teal-600 p-6">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-white bg-opacity-20 rounded-xl flex items-center justify-center mr-4">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-white">{{ __('Your Donation') }}</h2>
                            <p class="text-emerald-100">{{ __('Choose how you want to help') }}</p>
                        </div>
                    </div>
                </div>
                
                <div class="p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="form-group">
                            {!! Form::label('donation', __('Donation Type'), ['class' => 'block text-sm font-semibold text-gray-700 mb-2']) !!}
                            {!! Form::select('donation', $donation, old('donation'), ['class' => 'w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition duration-200 bg-gray-50 focus:bg-white', 'id' => 'donation']) !!}
                        </div>

                        <div class="form-group donation_amount_div hidden" data_val="0">
                            {!! Form::label('amount', __('Donation Amount'), ['class' => 'block text-sm font-semibold text-gray-700 mb-2']) !!}
                            <span>
                                {!! Form::select('amount', [], old('amount'), ['class' => 'w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition duration-200 bg-gray-50 focus:bg-white', 'id' => 'amount-dropdown']) !!}
                            </span>
                        </div>
                    </div>
                    
                    <!-- Impact Message -->
                    <div class="mt-6 p-6 bg-gradient-to-r from-emerald-50 to-teal-50 rounded-xl border border-emerald-200">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h4 class="text-lg font-semibold text-emerald-800">{{ __('Your Impact') }}</h4>
                                <p class="text-emerald-700 mt-1">{{ __('Every donation helps us continue our mission to make a positive difference in the world. Thank you for your generosity!') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Methods Card -->
            <div class="payment-methods-container">
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden transform hover:scale-[1.01] transition-all duration-300 hidden payment-card">
                    <div class="bg-gradient-to-r from-blue-500 to-indigo-600 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-white bg-opacity-20 rounded-xl flex items-center justify-center mr-4">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-white payment-label">{{ __('Payment Method') }}</h2>
                                <p class="text-blue-100">{{ __('Choose your preferred payment option') }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-8">
                        <div class="method-select grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
                        
                        <!-- Security Badge -->
                        <div class="mt-6 flex items-center justify-center text-gray-500">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            <span class="text-sm">{{ __('Your payment information is secure and encrypted') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="text-center">
                <button type="submit" class="inline-flex items-center px-8 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-bold text-lg rounded-2xl shadow-xl transform transition duration-300 hover:scale-105 focus:outline-none focus:ring-4 focus:ring-indigo-300 donation_payfast_payment">
                    <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                    </svg>
                    {{ __('Complete Donation') }}
                    <svg class="w-5 h-5 ml-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                    </svg>
                </button>
                
                <p class="mt-4 text-gray-500 text-sm">{{ __('By completing this donation, you agree to our terms and conditions') }}</p>
            </div>
        </form>
    </div>
</section>
@php
$theme_name = !empty($currentTheme) ? $currentTheme : env('DATA_INSERT_APP_THEME');
$payfast_mode = \App\Models\Utility::GetValueByName('payfast_mode', $theme_name);
$pfHost = $payfast_mode == 'sandbox' ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
@endphp
@push('page-script')
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function () {
        // Initialize Select2
        $('#countryList, #stateList, #cityList').select2({
            placeholder: "Select an option",
            allowClear: true
        });

        $('.select2-container--default').addClass('form-control');

        // Load States based on Country
        $('#countryList').on('change', function () {
            let countryId = $(this).val();
            $('#stateList').empty().trigger('change');
            $('#cityList').empty().trigger('change');

            if (countryId) {
                $.ajax({
                    url: "{{ route('get.state') }}",
                    type: "POST",
                    data: {
                        country_id: countryId,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function (response) {
                        $('#stateList').append('<option value="">Select State</option>');
                        $.each(response, function (id, name) {
                            $('#stateList').append(`<option value="${id}">${name}</option>`);
                        });
                        $('#stateList').trigger('change');
                    }
                });
            }
        });

        // Load Cities based on State
        $('#stateList').on('change', function () {
            let stateId = $(this).val();
            $('#cityList').empty().trigger('change');

            if (stateId) {
                $.ajax({
                    url: "{{ route('getcitystate') }}",
                    type: "POST",
                    data: {
                        state_id: stateId,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function (response) {
                        $('#cityList').append('<option value="">Select City</option>');
                        $.each(response, function (id, name) {
                            $('#cityList').append(`<option value="${id}">${name}</option>`);
                        });
                        $('#cityList').trigger('change');
                    }
                });
            }
        });

        // Donation type change → load amounts
        $('#donation').on('change', function () {
            let id = $(this).val();
            let val = $('.donation_amount_div').attr('data_val');

            $.ajax({
                url: "{{ route('get.donation.amount', $store->slug) }}",
                type: 'POST',
                data: {
                    id: id,
                    val: val,
                    _token: '{{ csrf_token() }}'
                },
                success: function (response) {
                    $('#loader').fadeOut();
                    if (response.status) {
                        $('.donation_amount_div')
                            .removeClass('d-none hidden')
                            .attr('data_val', 0);
                        $('.donation_amount_div span').html(response.html);
                        setupAmountChangeHandler();

                        if (Array.isArray(response.paymentMethods)) {
                            setupPaymentMethods(response.paymentMethods);
                        }
                    }
                }
            });
        });

        // Setup custom amount handler
        function setupAmountChangeHandler() {
            $('#amount').on('change', function () {
                if ($(this).val() === 'other') {
                    if (!$('#custom-amount').length) {
                        $(this).after(
                            '<input type="text" class="form-control mt-2" id="custom-amount" name="custom_amount" placeholder="Enter custom amount">'
                        );
                    }
                } else {
                    $('#custom-amount').remove();
                }
            });
        }

        // Render payment methods
        function setupPaymentMethods(paymentMethods) {
            var container = $('.method-select');
            container.empty();
            $('.payment-card').removeClass('hidden');

            $.each(paymentMethods, function (index, method) {
                container.append(`
                    <div class="radio-group col-xl-3 col-lg-4 col-md-4 col-sm-6 col-12">
                        <div class="donation-payment">
                            <input class="donationpayment_method" type="radio" name="donationpayment_method" id="donationpayment_method_${index}" value="${method}">
                            <label class="form-check-label" for="donationpayment_method_${index}">${method}</label>
                        </div>
                    </div>
                `);
            });
        }

        // Add Payfast hidden container
        setTimeout(() => {
            $("#donation-form").append("<div id='get-payfast-input-data'></div>");
        }, 100);

        // Intercept Payfast button
        $(".donation_payfast_payment").on("click", function (e) {
            var payment_type = $('input[name="donationpayment_method"]:checked').val();

            if (payment_type === 'Payfast') {
                e.preventDefault();
                $('#donation-form').attr('action', "https://{{ $pfHost }}/eng/process");
                get_payfast_status();
            } else {
                $('#donation-form').submit();
            }
        });

        // Get Payfast token (if needed)
        function get_payfast_status() {
            var formdata = $('#donation-form').serializeArray();
            $.ajax({
                url: "{{ route('customerdonation.store', $store->slug) }}",
                method: 'POST',
                data: formdata,
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function (data) {
                    $('#loader').fadeOut();
                    if (data.success === true) {
                        $('#get-payfast-input-data').append(data.inputs);
                        $('#donation-form').submit();
                    } else {
                        show_toastr('Error', data.inputs, 'error');
                    }
                }
            });
        }
    });
</script>
@endpush

@endsection