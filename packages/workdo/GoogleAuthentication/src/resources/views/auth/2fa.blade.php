@extends('layouts.guest')
@section('page-title')
    {{ __('Login') }}
@endsection
@section('content')
                <div class="">
                    <h2 class="mb-3 f-w-600">{{ __('Login') }}</h2>
                </div>
                <form class="form-horizontal" action="{{ route('2faVerify') }}" method="POST">
                    {{ csrf_field() }}
                    <input type="hidden" name="2fa_referrer"
                        value="{{ 'dashboard' ?? URL()->current() }}">
                    <div class="form-group">
                        <div class="col-md-12">
                            <p>{{ __('Please enter the') }} <strong>{{ __('OTP') }}</strong> {{ __('generated on your Authenticator App.') }} <br> {{ __('Ensure you
                                submit the current one because it refreshes every 30 seconds.') }}</p>
                            <label for="one_time_password" class="col-md-12 form-label">{{ __('One Time Password') }}</label>
                            <input id="one_time_password" type="password"
                                class="form-control @if ($errors->any()) is-invalid @endif"
                                name="one_time_password" required="required" autofocus>
                            @if ($errors->any())
                                <span class="error invalid-email text-danger" role="alert">
                                    @foreach ($errors->all() as $error)
                                        <small>{{ $error }}</small>
                                    @endforeach
                                </span>
                            @endif
                        </div>
                    </div>
                    <span id="otpError" style="color: red; display: none;">{{ __('OTP cannot contain spaces.') }}</span>

                    <div class="form-group">
                        <div class="col-md-6 col-md-offset-4 mt-3">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                {{ __('Login')}}
                            </button>
                        </div>
                    </div>
                </form>
@endsection
@push('scripts')
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function () {
            $(document).on('input', '#one_time_password', function () {
                var otpValue = $(this).val();
                var hasSpace = otpValue.includes(' ');

                if (hasSpace) {
                    $('#otpError').show();
                    $('#submitBtn').attr('disabled', true);
                } else {
                    $('#otpError').hide();
                    $('#submitBtn').attr('disabled', false);
                }
            });
        });
    </script>
@endpush