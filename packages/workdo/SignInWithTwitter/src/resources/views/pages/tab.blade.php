<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'twitter_signin_setting' ? 'active' : '' }}"
        data-url="{{ route('twitter-login.form') }}" data-tab="twitter_signin_setting" id="pills-twitter_signin-tab"
        data-bs-toggle="pill" data-bs-target="#eco-twitter_setting" type="button" role="tab"
        aria-controls="pills-twitter_signin" aria-selected="false">
        {{ __('Manage Twitter') }}
    </a>
</li>
