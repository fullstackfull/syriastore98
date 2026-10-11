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
            <div class="bg-gray-50 border md:p-8 p-4 rounded-lg shadow-sm">

    {{-- Tabs --}}
    <div class="flex gap-2 mb-6">
        <button
            type="button"
            id="email_tab"
            class="btn-primary flex-1"
            onclick="showResetMethod('email')"
        >
            {{ __('Email') }}
        </button>

        <button
            type="button"
            id="otp_tab"
            class="btn-secondary flex-1"
            onclick="showResetMethod('otp')"
        >
            {{ __('OTP') }}
        </button>
    </div>

    {{-- Email Reset --}}
    <div id="email_reset_box">

        <form
            method="POST"
            action="{{ route('customer.password.email', $slug) }}"
            class="md:space-y-6 space-y-4"
        >
            @csrf

            <div>
                <label
                    for="email"
                    class="block mb-2 font-medium text-sm md:text-base"
                >
                    {{ $themeSettings['forgot_password_email'] ?? __('Email Address') }}
                    <span class="text-red-500">*</span>
                </label>

                <input
                    type="email"
                    name="email"
                    id="email"
                    class="form-input"
                    value="{{ old('email') }}"
                    required
                />
            </div>

            {!! Form::hidden('type', 'customer') !!}

            <button type="submit" class="btn-primary w-full">
                {{ __('Send Password Reset Link') }}
            </button>
        </form>

    </div>

    {{-- OTP Reset --}}
    <div id="otp_reset_box" style="display: none;">

        <form
            method="POST"
            action="{{ route('customer.password.otp.send', $slug) }}"
            class="md:space-y-6 space-y-4"
        >
            @csrf

            <div>
                <label
                    for="mobile"
                    class="block mb-2 font-medium text-sm md:text-base"
                >
                    {{ __('Mobile Number') }}
                    <span class="text-red-500">*</span>
                </label>

                <input
                    type="text"
                    name="mobile"
                    id="mobile"
                    class="form-input"
                    value="{{ old('mobile') }}"
                    placeholder="+9639XXXXXXXX"
                    required
                />
            </div>

            <button type="submit" class="btn-primary w-full">
                {{ __('Send OTP') }}
            </button>
        </form>

    </div>

    {{-- Back to login --}}
    <div class="text-center mt-6">
        <p class="text-gray-600">

            {!! $themeSettings['forgot_password_back_description']
                ?? __('Remember your password?') !!}

            <a
                href="{{ route('customer.login', $slug) }}"
                class="text-primary hover:text-primary-dark font-medium"
            >
                {{ $themeSettings['forgot_password_back_button']
                    ?? __('Back to login') }}
            </a>

        </p>
    </div>

</div>

<script>
function showResetMethod(method) {

    const emailBox = document.getElementById('email_reset_box');
    const otpBox = document.getElementById('otp_reset_box');

    const emailTab = document.getElementById('email_tab');
    const otpTab = document.getElementById('otp_tab');

    if (method === 'otp') {

        emailBox.style.display = 'none';
        otpBox.style.display = 'block';

        otpTab.classList.add('btn-primary');
        otpTab.classList.remove('btn-secondary');

        emailTab.classList.remove('btn-primary');
        emailTab.classList.add('btn-secondary');

    } else {

        emailBox.style.display = 'block';
        otpBox.style.display = 'none';

        emailTab.classList.add('btn-primary');
        emailTab.classList.remove('btn-secondary');

        otpTab.classList.remove('btn-primary');
        otpTab.classList.add('btn-secondary');
    }
}
</script>
            </div>
        </div>
    </section>
    @endif
</main>
@endsection