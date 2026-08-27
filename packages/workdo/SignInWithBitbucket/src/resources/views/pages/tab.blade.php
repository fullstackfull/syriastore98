
<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'bitbucket_signin_setting' ? 'active' : '' }}"
        data-url="{{ route('bitbucket-login.form') }}" data-tab="bitbucket_signin_setting" id="pills-bitbucket_signin-tab"
        data-bs-toggle="pill" data-bs-target="#eco-bitbucket_setting" type="button" role="tab"
        aria-controls="pills-bitbucket_signin" aria-selected="false">
        {{ __('Manage BitBucket') }}
    </a>
</li>
