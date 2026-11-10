{{--
    P2.5 — THIS PAGE RENDERED NOTHING.

    It was the last Breeze view left in the auth folder: `<x-guest-layout> … </x-guest-layout>`,
    which passes its body to the component as `$slot`. `resources/views/layouts/guest.blade.php`
    contains ZERO references to `$slot` — it is the WorkDo layout and emits page content with
    `@yield('content')` — so a layout that yields never printed the slot it was handed. The route
    answered HTTP 200 with correct chrome and an empty body, exactly as `/profile/edit` did, and
    for exactly the same reason.

    `GET confirm-password` IS a registered route (`routes/auth.php:52`, name `password.confirm`),
    so this was reachable and blank, not dead markup.

    Converted to the shape its five siblings already use — `@extends('layouts.guest')` plus
    `@section('content')` — and to the theme's own Bootstrap classes rather than the Tailwind ones
    it carried, which this application does not load at all (`@vite` appears in zero views and
    there is no `public/build`). `<x-input>` is the same component `reset-password.blade.php` and
    `forgot-password.blade.php` use, so the field is the one the rest of the auth flow renders.

    No reCAPTCHA block: this is a re-authentication prompt shown to a signed-in user, not a public
    form, and the siblings carry theirs for the public ones.

    Guarded by tests/Unit/Ui/SlotLayoutViewsRenderTest, which fails on any view that passes a slot
    to a layout that cannot print one, and by tests/Feature/ConfirmPasswordRendersSomethingTest,
    which drives the route and asserts the form is really in the response.
--}}
@extends('layouts.guest')

@section('page-title')
    {{ __('Confirm Password') }}
@endsection

@section('content')
    <div class="text-center">
        <h2 class="mb-2 h3 f-w-600">{{ __('Confirm Password') }}</h2>
        <div class="text-border-bottom"></div>
        <p>{{ __('This is a secure area of the application. Please confirm your password before continuing.') }}</p>
    </div>

    <div class="">
        <!-- Validation Errors -->
        <x-auth-validation-errors class="mb-4" :errors="$errors" />

        <form method="POST" action="{{ route('password.confirm') }}">
            @csrf

            <div class="form-group mb-3">
                <label class="form-label" for="password">{{ __('Password') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                <x-input id="password" class="form-control" type="password" name="password"
                    placeholder="{{ __('Enter Password') }}" autocomplete="current-password" required autofocus />
            </div>

            <div class="d-grid">
                <button class="btn btn-primary btn-block mt-2" type="submit">
                    {{ __('Confirm') }}
                </button>
            </div>
        </form>
    </div>
@endsection
