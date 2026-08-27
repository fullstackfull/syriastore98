<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'facebook_signin_setting' ? 'active' : '' }}"
        data-url="{{ route('facebook-login.form') }}" data-tab="facebook_signin_setting" id="pills-facebook_signin-tab"
        data-bs-toggle="pill" data-bs-target="#eco-facebook_setting" type="button" role="tab"
        aria-controls="pills-facebook_signin" aria-selected="false">
        {{ __('Manage Facebook') }}
    </a>
</li>
