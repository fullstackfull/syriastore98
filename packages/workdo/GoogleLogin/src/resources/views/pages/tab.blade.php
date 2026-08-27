<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'google_signin_setting' ? 'active' : '' }}"
        data-url="{{ route('google-login.form') }}" data-tab="google_signin_setting" id="pills-google_signin-tab"
        data-bs-toggle="pill" data-bs-target="#eco-google_setting" type="button" role="tab"
        aria-controls="pills-google_signin" aria-selected="false">
        {{ __('Manage Google') }}
    </a>
</li>