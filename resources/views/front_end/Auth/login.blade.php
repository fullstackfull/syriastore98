@extends('front_end.layouts.app')
@section('page-title')
    {{ __('Login Page') }}
@endsection
@section('content')
<main>
    @if ($themeSettings['login_page_status'] && $themeSettings['login_page_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
      style="background-image: url('{{ get_file($themeSettings['login_page_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['login_page_title'] ?? __('Sign In') }}</h2>
        </div>
      </div>
    </section>
    @endif

    @if ($themeSettings['login_status'] && $themeSettings['login_status'] == '1')
    <section class="py-10 lg:py-20">
      <div class="md:container w-full mx-auto px-4">
        <div class="max-w-md mx-auto">
          <div class="text-center lg:mb-8 mb-6">
            <h2 class="text-2xl sm:text-4xl font-bold mb-2 capitalize">{{ $themeSettings['login_title'] ?? __('Sign In') }}</h2>
            <p class="text-gray-600">{!! $themeSettings['login_description'] ?? __('Welcome back! Sign in to your account to continue.') !!}</p>
          </div>
          
          <!-- Login Form -->
          <div class="bg-gray-50 border md:p-8 p-4 rounded-lg shadow-sm md:mb-6 mb-4">
            <form  method="POST" action="{{ route('customer.login.save',$slug) }}" class="login-form md:space-y-6 space-y-4">
                @csrf
              <!-- Email Field -->
              <div>
                <label for="email" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['login_email'] ?? __('Email Address') }} <span class="text-red-500">*</span></label>
                <input type="email" name="email" id="email" class="form-input" required/>
              </div>
              
              <!-- Password Field -->
              <div>
                <div class="flex justify-between mb-2">
                  <label for="password" class="font-medium md:text-base text-sm">{{ $themeSettings['login_password'] ?? __('Password') }} <span class="text-red-500">*</span></label>
                  @if ($themeSettings['login_forgot_status'] && $themeSettings['login_forgot_status'] == '1')
                  <a href="{{ route('customer.password.request',$slug) }}" class="text-sm text-primary hover:text-primary-dark">{{ $themeSettings['login_forgot_link'] ?? __('Forgot password?') }}</a>
                  @endif
                </div>
                <input type="password" name="password" id="password" class="form-input" required/>
              </div>
              
              <!-- Remember Me -->
              <div class="flex items-center checkbox gap-2">
                <input type="checkbox" id="remember" class="rounded border-gray-300 text-primary focus:ring-primary" />
                <label for="remember">{{ $themeSettings['login_remember'] ?? __('Remember me') }}</label>
              </div>
               {!! Form::hidden('type', 'customer') !!}
              <!-- Submit Button -->
              <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="w-full btn-primary">
                  {{ $themeSettings['login_button'] ?? __('Sign In') }}
                </button>
                @stack('storeSigInButton')
              </div>

            </form>
          </div>
          @if ($themeSettings['login_register_status'] && $themeSettings['login_register_status'] == '1')
          <!-- Registration Link -->
          <div class="text-center">
            <p class="text-gray-600">
              {!! $themeSettings['login_register_description'] ?? __("Don't have an account?") !!} 
              <a href="{{ route('customer.register',$slug) }}" class="text-primary hover:text-primary-dark font-medium">{{ $themeSettings['login_register_link'] ?? __('Sign up here') }}</a>
            </p>
          </div>
          @endif
        </div>
      </div>
    </section>
    @endif
  </main>
@endsection



