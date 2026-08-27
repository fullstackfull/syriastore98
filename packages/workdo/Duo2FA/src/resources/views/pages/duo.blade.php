<style>
    #duo_iframe {
        border-bottom: 1px solid;
        overflow-y: auto;
    }
</style>

@php
    $adminSetting = getSuperAdminAllSetting();
@endphp

@extends('layouts.guest')

@section('page-title')
    {{ __('Login') }}
@endsection

@section('content')
    <script src="{{ asset('packages/workdo/Duo2FA/resources/src/assets/js/Duo-Web-v1.bundled.min.js') }}"></script>
    <div class="">
        <h2 class="mb-3 f-w-600">{{ __('Login') }}</h2>
    </div>
    <div class="">
        <!-- Session Status -->
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <!-- Validation Errors -->
        <x-auth-validation-errors class="mb-4" :errors="$errors" />

        <form method="POST" action="{{$data['POST']}}" id="duo_form">
            @csrf
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <input type="hidden" name="user_email" value="{{ $data['USER'] }}">
            <input type="hidden" name="response" id="duo_response">
            <iframe id="duo_iframe" width="100%" height="350" frameborder="0" allowtransparency="true" style="background:transparent;"></iframe>
        </form>
    </div>
@endsection

@push('scripts')
    <script type="text/javascript">
        Duo.init({
            'host': '{{ $data['HOST'] }}',
            'post_action': '{{ $data['POST'] }}',
            'sig_request': '{{ $data['SIG'] }}'
        });
    </script>
@endpush
