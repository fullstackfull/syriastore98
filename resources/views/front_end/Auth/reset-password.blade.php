@extends('front_end.layouts.app')
@section('page-title')
    {{ __('Reset Password') }}
@endsection

@section('content')
<main>
    @if ($themeSettings['reset_banner_status'] && $themeSettings['reset_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['reset_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['reset_banner_title'] ?? __('Reset Password') }}
                </h2>
            </div>
        </div>
    </section>
    @endif
    @if ($themeSettings['reset_password_status'] && $themeSettings['reset_password_status'] == '1')
    <section class="py-10 lg:py-20">
        <div class="md:container w-full mx-auto px-4">
            <div class="max-w-md mx-auto">
                <div class="text-center lg:mb-8 mb-6">
                    <h2 class="font-bold text-2xl lg:text-3xl mb-3">{{ $themeSettings['reset_password_title'] ?? __('Reset Your Password?') }}</h2>
                    <p class="text-gray-600">{!! $themeSettings['reset_password_description'] ?? __('Enter your email to receive a password reset link.') !!}</p>
                </div>
                <div id="email-form" class="bg-gray-50 border md:p-8 p-4 rounded-lg shadow-sm">
                    <form method="POST" action="{{ route('customer.password.update',$slug) }}"
                        class="md:space-y-6 space-y-4">
                        @csrf
                        <input type="hidden" name="token" value="{{ request()->query('token') }}">
                        <!-- Email Field -->
                        <div>
                            <label for="email"
                                class="block mb-2 font-medium text-sm md:text-base">{{ $themeSettings['reset_password_email'] ?? __('Email Address') }}
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="email" name="email" id="email" class="form-input" value="{{ request()->get('email')}}" required />
                        </div>
                        <!-- Password Field -->
                        <div>
                            <label for="password"
                                class="block mb-2 font-medium text-sm md:text-base">{{ $themeSettings['reset_password_pwd'] ?? __('Password') }}
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="password" name="password" id="password" class="form-input" required />
                        </div>
                        <!-- Confirm Field -->
                        <div>
                            <label for="password_confirmation"
                                class="block mb-2 font-medium text-sm md:text-base">{{ $themeSettings['reset_password_confirm_pwd'] ?? __('Confirm Password') }}
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="password" name="password_confirmation" id="password_confirmation" class="form-input" required />
                        </div>
                        {!! Form::hidden('type', 'customer') !!}
                        <!-- Submit Button -->
                        <button type="submit" class="btn-primary w-full">
                            {{ $themeSettings['reset_password_button'] ?? __('Reset Password') }}
                        </button>

                    </form>
                </div>
            </div>
        </div>
    </section>
    @endif
</main>
@endsection