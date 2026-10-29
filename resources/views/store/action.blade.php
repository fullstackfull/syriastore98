<span class="d-flex gap-1 justify-content-end">
    @if (auth()->user()->type == 'super admin')
        <a aria-label="{{ __('Edit User') }}" href="#!" data-size="md"
            data-url="{{ route('users.edit', $user->id) }}" data-bs-toggle="tooltip" data-ajax-popup="true"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-original-title="{{ __('Edit User') }}" data-title="{{ __('Edit User') }}"
            title="{{ \Auth::user()->type == 'super admin' ? __('Edit User') : __('Edit User') }}">
            <i class="ti ti-pencil" aria-hidden="true"></i>
        </a>
    @endif

    @if (auth()->user()->type == 'super admin')
        {!! Form::open(['method' => 'DELETE', 'route' => ['users.destroy', $user->id], 'class' => 'd-inline']) !!}
        <a aria-label="{{ __('Delete :thing', ['thing' => __('User')]) }}" href="#" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet bs-pass-para show_confirm" data-confirm="{{ __('Are You Sure?') }}"
            data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
            data-confirm-yes="delete-form-{{ $user->id }}"  data-bs-toggle="tooltip" data-bs-original-title="{{ __('Delete') }}" data-title="{{ __('Delete') }}" title="{{ __('Delete') }}">
            <i class="ti ti-trash" aria-hidden="true"></i>
        </a>
        {!! Form::close() !!}
    @endif

    @if (auth()->user()->type == 'super admin')
        <a aria-label="{{ __('Login As Admin') }}" href="{{ route('login.with.admin', encrypt($user->id)) }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Login As Admin') }}" data-title="{{ __('Login As Admin') }}" title="{{ __('Login As Admin') }}">
            <i class="ti ti-replace" aria-hidden="true"></i>
        </a>
    @endif

    <a aria-label="{{ __('Upgrade Plan') }}" href="#" data-url="{{ route('plan.upgrade', encrypt($user->id)) }}" data-size="lg" data-ajax-popup="true" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Upgrade Plan') }}" data-title="{{ __('Upgrade Plan') }}" title="{{ __('Upgrade Plan') }}"><i class="ti ti-trophy py-1" aria-hidden="true"></i></a>

    <a aria-label="{{ __('Admin Info') }}" href="#" data-url="{{ route('user.info', encrypt($user->id)) }}" data-size="lg" data-ajax-popup="true" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-ajax-popup="true" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Admin Info') }}" data-title="{{ __('Admin Info') }}" title="{{ __('Admin Info') }}"><i class="ti ti-users py-1" aria-hidden="true"></i></a>

    <a aria-label="{{ __('Store Link') }}" href="#" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
        data-url="{{ route('stores.link', encrypt($user->id)) }}" data-size="md"
        data-ajax-popup="true" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Store Link') }}"
        data-title="{{ __('Store Link') }}"
        title="{{ __('Store Link') }}">
        <i class="ti ti-unlink py-1" aria-hidden="true"></i>
    </a>


    <a aria-label="{{ __('Reset Password') }}" href="#"
        data-url="{{ route('stores.reset.password', \Crypt::encrypt($user->id)) }}"
        data-ajax-popup="true" data-size="md" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip"
        data-bs-original-title="{{ __('Reset Password') }}"
        data-title="{{ __('Reset Password') }}"
        title="{{ __('Reset Password') }}">
        <i class="ti ti-adjustments" aria-hidden="true"></i>
    </a>

    @if ($user->is_enable_login == 1)
        <a aria-label="{{ __('Login Disable') }}" href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Login Disable') }}"
        data-title="{{ __('Login Disable') }}"
        title="{{ __('Login Disable') }}">
            <i class="ti ti-road-sign" aria-hidden="true"></i>
        </a>
    @elseif ($user->is_enable_login == 0 && $user->password == null)
        <a aria-label="{{ __('Login Enable') }}" href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Login Enable') }}"
        data-title="{{ __('Login Enable') }}"
        title="{{ __('Login Enable') }}">
            <i class="ti ti-road-sign" aria-hidden="true"></i>
        </a>
    @else
        <a aria-label="{{ __('Login Enable') }}" href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Login Enable') }}"
        data-title="{{ __('Login Enable') }}"
        title="{{ __('Login Enable') }}">
            <i class="ti ti-road-sign" aria-hidden="true"></i>
        </a>
    @endif
</span>