@extends('front_end.layouts.app')
@section('page-title')
{{ __('Forgot Password') }}
@endsection
@section('content')
<main>
    @if ($themeSettings['forgot_banner_status'] && $themeSettings['forgot_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['forgot_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['forgot_banner_title'] ?? __('Forgot Password') }}
                </h2>
            </div>
        </div>
    </section>
    @endif
    @if ($themeSettings['forgot_password_status'] && $themeSettings['forgot_password_status'] == '1')
    <section class="py-10 lg:py-20">
        <div class="md:container w-full mx-auto px-4">
            <div class="max-w-md mx-auto">
                <div class="text-center lg:mb-8 mb-6">
                    <h2 class="font-bold text-2xl lg:text-3xl mb-3">{{ $themeSettings['forgot_password_title'] ?? __('Forgot Your Password?') }}</h2>
                    <p class="text-gray-600">{!! $themeSettings['forgot_password_description'] ?? __('Enter your email to receive a password reset link.') !!}</p>
                </div>
                <div id="email-form" class="bg-gray-50 border md:p-8 p-4 rounded-lg shadow-sm">
                    <form method="POST" action="{{ route('customer.password.email',$slug) }}"
                        class="md:space-y-6 space-y-4">
                        @csrf
                        <!-- Email Field -->
                        <div>
                            <label for="email"
                                class="block mb-2 font-medium text-sm md:text-base">{{ $themeSettings['forgot_password_email'] ?? __('Email Address') }}
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="email" name="email" id="email" class="form-input" required />
                        </div>
                        {!! Form::hidden('type', 'customer') !!}
                        <!-- Submit Button -->
                        <button type="submit" class="btn-primary w-full">
                            {{ $themeSettings['forgot_password_button'] ?? __('Reset Password') }}
                        </button>

                        <div class="text-center">
                            <p class="text-gray-600">
                                {!! $themeSettings['forgot_password_back_description'] ?? __('Remember your password?') !!}
                                <a href="{{ route('customer.login',$slug) }}"
                                    class="text-primary hover:text-primary-dark font-medium">{{ $themeSettings['forgot_password_back_button'] ?? __('Back to login') }}</a>
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
    @endif
</main>
@endsection