<span class="d-flex gap-1 justify-content-end">
    @if (auth()->user()->type == 'super admin')
        <a href="#!" data-size="md"
            data-url="{{ route('users.edit', $user->id) }}" data-bs-toggle="tooltip" data-ajax-popup="true"
            class="btn btn-sm btn-info" data-bs-original-title="{{ __('Edit User') }}" data-title="{{ __('Edit User') }}"
            title="{{ \Auth::user()->type == 'super admin' ? __('Edit User') : __('Edit User') }}">
            <i class="ti ti-pencil"></i>
        </a>
    @endif

    @if (auth()->user()->type == 'super admin')
        {!! Form::open(['method' => 'DELETE', 'route' => ['users.destroy', $user->id], 'class' => 'd-inline']) !!}
        <a href="#" class="btn btn-sm btn-danger bs-pass-para show_confirm" data-confirm="{{ __('Are You Sure?') }}"
            data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
            data-confirm-yes="delete-form-{{ $user->id }}"  class="btn btn-sm btn-secondary" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Delete') }}" data-title="{{ __('Delete') }}" title="{{ __('Delete') }}">
            <i class="ti ti-trash"></i>
        </a>
        {!! Form::close() !!}
    @endif

    @if (auth()->user()->type == 'super admin')
        <a href="{{ route('login.with.admin', encrypt($user->id)) }}" class="btn btn-sm btn-secondary" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Login As Admin') }}" data-title="{{ __('Login As Admin') }}" title="{{ __('Login As Admin') }}">
            <i class="ti ti-replace"></i>
        </a>
    @endif

    <a href="#" data-url="{{ route('plan.upgrade', encrypt($user->id)) }}" data-size="lg" data-ajax-popup="true" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Upgrade Plan') }}" data-title="{{ __('Upgrade Plan') }}" title="{{ __('Upgrade Plan') }}"><i class="ti ti-trophy py-1"></i></a>

    <a href="#" data-url="{{ route('user.info', encrypt($user->id)) }}" data-size="lg" data-ajax-popup="true" class="btn btn btn-sm btn-light" data-ajax-popup="true" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Admin Info') }}" data-title="{{ __('Admin Info') }}" title="{{ __('Admin Info') }}"><i class="ti ti-users py-1"></i></a>

    <a href="#" class="btn btn-sm btn-warning"
        data-url="{{ route('stores.link', encrypt($user->id)) }}" data-size="md"
        data-ajax-popup="true" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Store Link') }}"
        data-title="{{ __('Store Link') }}"
        title="{{ __('Store Link') }}">
        <i class="ti ti-unlink py-1"></i>
    </a>


    <a href="#"
        data-url="{{ route('stores.reset.password', \Crypt::encrypt($user->id)) }}"
        data-ajax-popup="true" data-size="md" class="btn btn-sm btn-dark" data-bs-toggle="tooltip"
        data-bs-original-title="{{ __('Reset Password') }}"
        data-title="{{ __('Reset Password') }}"
        title="{{ __('Reset Password') }}">
        <i class="ti ti-adjustments"></i>
    </a>

    @if ($user->is_enable_login == 1)
        <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
            class="btn btn-sm btn-danger" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Login Disable') }}"
        data-title="{{ __('Login Disable') }}"
        title="{{ __('Login Disable') }}">
            <i class="ti ti-road-sign"></i>
        </a>
    @elseif ($user->is_enable_login == 0 && $user->password == null)
        <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
            class="btn btn-sm btn-success" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Login Enable') }}"
        data-title="{{ __('Login Enable') }}"
        title="{{ __('Login Enable') }}">
            <i class="ti ti-road-sign"></i>
        </a>
    @else
        <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
            class="btn btn-sm btn-success" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Login Enable') }}"
        data-title="{{ __('Login Enable') }}"
        title="{{ __('Login Enable') }}">
            <i class="ti ti-road-sign"></i>
        </a>
    @endif
</span>