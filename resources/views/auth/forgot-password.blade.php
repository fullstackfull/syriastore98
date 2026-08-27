

@php
    $adminSetting = getSuperAdminAllSetting();
    config([
        'captcha.secret' => $adminSetting['NOCAPTCHA_SECRET'] ?? '',
        'captcha.sitekey' => $adminSetting['NOCAPTCHA_SITEKEY'] ?? '',
        'options' => [
            'timeout' => 30,
        ],
    ]);
@endphp

@extends('layouts.guest')

@section('page-title')
    {{ __('Forget password') }}
@endsection
@if (isset($adminSetting['cust_darklayout']) && $adminSetting['cust_darklayout'] == 'on')
    <style>
        .g-recaptcha {
            filter: invert(1) hue-rotate(180deg) !important;
        }
    </style>
@endif

@section('content')
    <div>
        <div class="text-center">
            <h2 class="mb-2 h3 f-w-600">{{ __('Forgot Password') }}</h2>
            <div class="text-border-bottom"></div>
            <p>{{ __('Enter your email to receive a password reset link') }}</p>
        </div>
        <!-- Session Status -->
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <!-- Validation Errors -->
        <x-auth-validation-errors class="mb-4" :errors="$errors" />

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <!-- Email Address -->
            <div>
                <label for="" class="form-label">{{ __('Email') }}</label>
                <x-input id="email" class="form-control" type="email" name="email" :value="old('email')" required
                    autofocus placeholder="{{ __('Enter email address') }}" />
            </div>

            @if (isset($adminSetting['RECAPTCHA_MODULE']) && $adminSetting['RECAPTCHA_MODULE'] == 'yes')
                @if (isset($adminSetting['NOCAPTCHA_VERSON']) && $adminSetting['NOCAPTCHA_VERSON'] == 'v2')
                    <div class="form-group col-lg-12 col-md-12 mt-3">
                        {!! NoCaptcha::display((isset($adminSetting['cust_darklayout']) && $adminSetting['cust_darklayout'] == 'on')? ['data-theme' => 'dark'] : []) !!}
                        @error('g-recaptcha-response')
                            <span class="small text-danger" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>
                @else
                    <div class="form-group col-lg-12 col-md-12 mt-3">
                        <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response" class="form-control">
                        @error('g-recaptcha-response')
                            <span class="error small text-danger" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>
                @endif
            @endif

            {{-- {!! Form::hidden('type', 'admin') !!} --}}
            <button class="btn btn-primary btn-block mt-3 w-100" type="submit">
                {{ __('Send Password Reset Link') }}
            </button>
            <div class="mt-3 text-center">
                <a class="underline text-gray-600 hover:text-gray-900" href="{{ route('login') }}">
                    {{ __('Back to ') }} <span class="text-primary"> {{ __('Login') }}</span>
                </a>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
@if (isset($adminSetting['RECAPTCHA_MODULE']) && $adminSetting['RECAPTCHA_MODULE'] == 'yes')
    @if (isset($adminSetting['NOCAPTCHA_VERSON']) && $adminSetting['NOCAPTCHA_VERSON'] == 'v2')
            {!! NoCaptcha::renderJs() !!}
    @else
        <script src="https://www.google.com/recaptcha/api.js?render={{ $adminSetting['NOCAPTCHA_SITEKEY'] }}"></script>
        <script>
            $(document).ready(function() {
                grecaptcha.ready(function() {
                    grecaptcha.execute('{{ $adminSetting['NOCAPTCHA_SITEKEY'] }}', {
                        action: 'submit'
                    }).then(function(token) {
                        $('#g-recaptcha-response').val(token);
                    });
                });
            });
        </script>
    @endif
@endif
@endpush
