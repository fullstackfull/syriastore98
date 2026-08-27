@extends('layouts.guest')
@section('page-title')
    {{__('Login')}}
@endsection
@section('language-bar')
        <li class="nav-item">
            <select class="btn btn-primary my-1 me-2 " onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);" id="language">
                @foreach(languages() as $language)
                    <option class="" @if($lang == $language) selected @endif value="{{ route('login',$language) }}">{{Str::upper($language)}}</option>
                @endforeach
            </select>
        </li>
@endsection
@section('content')
<div class="card">
    <div class="row align-items-center text-start">
        <div class="col-xl-6">
            <div class="card-body">
                <div class="">
                    <h2 class="mb-3 f-w-600">{{ __('Login') }}</h2>
                </div>
                <form class="form-horizontal" method="POST" action="{{ route('authentication.check') }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="lang" value="{{(isset($lang) ? $lang : '')}}">

                    <div class="form-group">
                        <div class="col-md-12">
                        <p>Please enter the  <strong>OTP</strong> generated on your Authenticator App. <br> Ensure you submit the current one because it refreshes every 30 seconds.</p>
                        <label for="one_time_password" class="col-md-12 form-label">One Time Password</label>
                            <input id="one_time_password" type="password" class="form-control" name="one_time_password" required="required" autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-6 col-md-offset-4 mt-3">
                            <button type="submit" class="btn btn-primary">
                                Login
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-xl-6 img-card-side">
            <div class="auth-img-content">
                <img src="{{ asset('assets/images/auth/img-auth-3.svg') }}" alt="" class="img-fluid">
                <h3 class="text-white mb-4 mt-5"> {{ __('“Attention is the new currency”') }}</h3>
                <p class="text-white"> {{__('The more effortless the writing looks, the more effort the writer
                    actually put into the process.')}}</p>
            </div>
        </div>
    </div>
</div>

@endsection
