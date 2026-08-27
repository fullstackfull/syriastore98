@extends('front_end.layouts.app')
@section('page-title')
    {{ __('Register Page') }}
@endsection
@section('content')
<main>
    @if ($themeSettings['register_banner_status'] && $themeSettings['register_banner_status'] == '1')
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
      style="background-image: url('{{ get_file($themeSettings['register_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['register_banner_title'] ?? __('Register') }}</h2>
        </div>
      </div>
    </section>
    @endif

    @if ($themeSettings['register_status'] && $themeSettings['register_status'] == '1')
    <section class="py-10 lg:py-20">
        <div class="container mx-auto px-4">
            <div class="max-w-2xl mx-auto">
                <div class="text-center mb-8">
                    <h2 class="text-2xl sm:text-4xl font-bold mb-2 capitalize">{{ $themeSettings['register_title'] ?? __('Create Your Account')}}</h2>
                    <p class="text-gray-600">{!! $themeSettings['register_description'] ?? __('Join FreshMart for a seamless shopping experience') !!}</p>
                </div>

                <!-- Registration Form -->
                <div class="bg-gray-50 border md:p-8 p-4 rounded-lg mb-6">
                    <form method="POST" action="{{ route('customer.registerdata', $store->slug) }}"
                        class="md:space-y-6 space-y-4">
                        @csrf
                        <!-- Personal Information -->
                        <div>
                            <h2 class="font-heading font-semibold text-xl mb-4">{{ $themeSettings['register_personal_info'] ?? __('Personal Information')}}</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 md:gap-6 gap-4">
                                <!-- First Name -->
                                <div>
                                    <label for="first-name" class="block mb-2 font-medium">{{ $themeSettings['register_first_name'] ??  __('First Name') }} <span
                                            class="text-red-500">*</span></label>
                                    <input name="first_name" type="text" value="{{ old('first_name') }}" class="form-input"
                                        placeholder="John" required="">
                                </div>

                                <!-- Last Name -->
                                <div>
                                    <label for="last-name" class="block mb-2 font-medium">{{ $themeSettings['register_last_name'] ?? __('Last Name') }} <span
                                            class="text-red-500">*</span></label>
                                    <input name="last_name" type="text" value="{{ old('last_name') }}" class="form-input"
                                        placeholder="Doe" required="">
                                </div>
                            </div>
                        </div>

                        <!-- Account Information -->
                        <div>
                            <h2 class="font-heading font-semibold text-xl mb-4">{{ $themeSettings['register_account_info'] ?? __('Account Information') }}</h2>

                            <!-- Email Field -->
                            <div class="md:mb-6 mb-4">
                                <label for="email" class="block mb-2 font-medium">{{ $themeSettings['register_email'] ?? __('Email Address') }} <span
                                        class="text-red-500">*</span></label>
                                <input name="email" type="email" value="{{ old('email') }}" class="form-input"
                                    placeholder="shop@company.com" required="">
                                <p class="text-sm text-gray-500 mt-1">{{ $themeSettings['register_email_note'] ?? __("We'll send order confirmations and receipts to this
                                    email") }}</p>
                            </div>

                            <!-- Phone Field -->
                            <div class="md:mb-6 mb-4">
                                <label for="phone" class="block mb-2 font-medium">{{ $themeSettings['register_phone'] ?? __('Phone Number') }} <span
                                        class="text-red-500">*</span></label>
                                <input name="mobile" type="number" value="{{ old('mobile') }}" class="form-input"
                                    placeholder="1234567890" required="">
                                <p class="text-sm text-gray-500 mt-1">{{ $themeSettings['register_phone_note'] ?? __('For order updates and delivery notifications') }}</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 md:gap-6 gap-4">
                                <!-- Password Field -->
                                <div>
                                    <label for="password" class="block mb-2 font-medium">{{$themeSettings['register_password'] ?? __('Password') }} <span
                                            class="text-red-500">*</span></label>
                                    <input name="password" type="password" value="{{ old('password') }}" class="form-input"
                                        placeholder="**********" required="">
                                </div>

                                <!-- Confirm Password Field -->
                                <div>
                                    <label for="confirm-password" class="block mb-2 font-medium">{{ $themeSettings['register_confirm_password'] ?? __('Confirm Password') }} <span
                                            class="text-red-500">*</span></label>
                                    <input name="password_confirmation" type="password" value="{{ old('password') }}"
                                        class="form-input" placeholder="***********" required="">
                                </div>
                            </div>
                            <p class="text-sm text-gray-500 mt-2">{{ $themeSettings['register_password_note'] ?? __('Password must be at least 8 characters long and include a
                                mix of letters, numbers, and special characters') }}</p>
                        </div>

                       
                        <!-- Terms and Privacy -->
                        @if (isset($themeSettings['register_terms']))
                        <div class="border-t border-gray-200 pt-6">
                            <div class="checkbox flex items-start gap-2">
                                <input type="checkbox" name="terms" id="terms"
                                    class="rounded border-gray-300 text-primary focus:ring-primary mt-1" required />
                                <label for="terms" class="flex-1 text-gray-700">
                                    {!! $themeSettings['register_terms'] ?? "" !!}
                                </label>
                            </div>
                        </div>
                        @endif
  
                        <!-- Submit Button -->
                        {!! Form::hidden('login_type', 'customer') !!}
                        @if (isset($ref) && module_is_active('ProductAffiliate'))
                            <input type="hidden" name="ref_code" value="{{ $ref }}">
                        @endif
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="submit" class="w-full btn-primary">
                                {{ $themeSettings['register_button'] ?? __('Create Account') }}
                            </button>
    
                            @stack('storeSigInButton')
                        </div>
                    </form>
                    
                </div>
                @if (isset($themeSettings['register_login_status']) && $themeSettings['register_login_status'] == 1)
                    <!-- Login Link -->
                    <div class="text-center">
                        <p class="text-gray-600">
                            {!! $themeSettings['register_login_description'] ?? __('Already have an account?') !!}
                            <a href="{{ route('customer.login', $slug) }}" class="text-primary hover:text-primary-dark font-medium">{{ $themeSettings['register_login_link'] ?? __('Sign in here') }}</a>
                        </p>
                    </div>
                    @endif
            </div>
    </section>
    @endif
</main>
@endsection