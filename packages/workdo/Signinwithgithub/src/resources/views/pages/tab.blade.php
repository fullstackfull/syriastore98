<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'github_signin_setting' ? 'active' : '' }}"
        data-url="{{ route('github-login.form') }}" data-tab="github_signin_setting" id="pills-github_signin-tab"
        data-bs-toggle="pill" data-bs-target="#eco-github_setting" type="button" role="tab"
        aria-controls="pills-github_signin" aria-selected="false">
        {{ __('Manage Github') }}
    </a>
</li>
