<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'slack_signin_setting' ? 'active' : '' }}"
        data-url="{{ route('slack-login.form') }}" data-tab="slack_signin_setting" id="pills-slack_signin-tab"
        data-bs-toggle="pill" data-bs-target="#eco-slack_setting" type="button" role="tab"
        aria-controls="pills-slack_signin" aria-selected="false">
        {{ __('Manage Slack') }}
    </a>
</li>
