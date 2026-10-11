@extends('front_end.layouts.app')

@section('page-title')
    {{ __('Reset Password') }}
@endsection

@section('content')
<main>
    <section class="py-10 lg:py-20">
        <div class="md:container w-full mx-auto px-4">

            <div class="max-w-md mx-auto">

                <div class="text-center lg:mb-8 mb-6">
                    <h2 class="font-bold text-2xl lg:text-3xl mb-3">
                        {{ __('Create New Password') }}
                    </h2>

                    <p class="text-gray-600">
                        {{ __('Enter your new password below.') }}
                    </p>
                </div>

                <div class="bg-gray-50 border md:p-8 p-4 rounded-lg shadow-sm">

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
                        action="{{ route('customer.password.otp.reset', $storeSlug) }}"
                        class="md:space-y-6 space-y-4"
                    >
                        @csrf

                        <div>
                            <label
                                for="password"
                                class="block mb-2 font-medium text-sm md:text-base"
                            >
                                {{ __('New Password') }}
                                <span class="text-red-500">*</span>
                            </label>

                            <input
                                type="password"
                                name="password"
                                id="password"
                                class="form-input"
                                autocomplete="new-password"
                                required
                                autofocus
                            >
                        </div>

                        <div>
                            <label
                                for="password_confirmation"
                                class="block mb-2 font-medium text-sm md:text-base"
                            >
                                {{ __('Confirm Password') }}
                                <span class="text-red-500">*</span>
                            </label>

                            <input
                                type="password"
                                name="password_confirmation"
                                id="password_confirmation"
                                class="form-input"
                                autocomplete="new-password"
                                required
                            >
                        </div>

                        <button
                            type="submit"
                            class="btn-primary w-full"
                        >
                            {{ __('Reset Password') }}
                        </button>

                    </form>

                </div>

            </div>

        </div>
    </section>
</main>
@endsection