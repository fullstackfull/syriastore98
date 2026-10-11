@extends('front_end.layouts.app')

@section('page-title')
    {{ __('Verify OTP') }}
@endsection

@section('content')
<main>
    <section class="py-10 lg:py-20">
        <div class="md:container w-full mx-auto px-4">
            <div class="max-w-md mx-auto">

                <div class="text-center lg:mb-8 mb-6">
                    <h2 class="font-bold text-2xl lg:text-3xl mb-3">
                        {{ __('Verify OTP') }}
                    </h2>

                    <p class="text-gray-600">
                        {{ __('Enter the 6-digit verification code sent to your mobile.') }}
                    </p>
                </div>

                <div class="bg-gray-50 border md:p-8 p-4 rounded-lg shadow-sm">

                    @if(session('success'))
                        <div class="alert alert-success mb-3">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger mb-3">
                            {{ session('error') }}
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger mb-3">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form
                        method="POST"
                        action="{{ route('customer.password.otp.verify', $storeSlug) }}"
                        class="md:space-y-6 space-y-4"
                    >
                        @csrf

                        <div>
                            <label for="otp" class="block mb-2 font-medium text-sm md:text-base">
                                {{ __('Verification Code') }}
                                <span class="text-red-500">*</span>
                            </label>

                            <input
                                type="text"
                                name="otp"
                                id="otp"
                                class="form-input"
                                inputmode="numeric"
                                maxlength="6"
                                pattern="[0-9]{6}"
                                autocomplete="one-time-code"
                                required
                                autofocus
                            >
                        </div>

                        <button type="submit" class="btn-primary w-full">
                            {{ __('Verify Code') }}
                        </button>

                        <div class="text-center">
                            <a
                                href="{{ route('customer.password.request', $storeSlug) }}"
                                class="text-primary hover:text-primary-dark font-medium"
                            >
                                {{ __('Request a new code') }}
                            </a>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </section>
</main>
@endsection