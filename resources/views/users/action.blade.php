{{--
    P2-05-5 — the staff list's row actions, on the design system.

    FIVE CONTROLS, NONE OF THEM NAMED AT RUNTIME AND NONE OF THEM GATED.

    1. THE NAMES. Each carried `title` and a pre-set `data-bs-original-title`, which looks more
       deliberate than the usual case and is not: `data-bs-toggle="tooltip"` still makes Bootstrap
       blank the `title` attribute on initialisation, and `data-bs-original-title` is not an
       accessible name — it is the plugin's own storage. So after page load every control here was
       an unnamed button wrapping an unlabelled `<i>`, five per row. Each now carries an
       `aria-label` naming the USER as well as the verb.

    2. THE GATES, each mirroring ITS OWN handler rather than a single blanket check, because the
       four handlers do not agree:
         - `users.edit`            -> `isAbleTo('Edit User')`            (UserController::edit)
         - `users.destroy`         -> `isAbleTo('Delete User')`          (UserController::destroy)
         - `stores.reset.password` -> `authorize('resetPassword', $user)` (StoreController:732)
         - `users.enable.login`    -> `can('update', $user)`             (UserController::userLoginManage)
       The last two are POLICIES, not permission names, so they are `@can`. That is NOT the
       `@can('Some Permission')` trap CLAUDE.md warns about — that one is always false because
       `permissions_as_gates => false` unhooks Laratrust from the Gate; a real policy ability is
       unaffected.

    3. THE BUTTONS came off the vendored theme (`btn-info`, `btn-danger`, `btn-dark`,
       `btn-success`), the family P2-04-4 measured below AA in 44 of 56 readings.

    ONE SIMPLIFICATION, AND IT IS BEHAVIOUR-PRESERVING BY MEASUREMENT RATHER THAN BY EYE. The
    login control had THREE branches: `is_enable_login == 1`, then
    `is_enable_login == 0 && password == null`, then `@else`. The second and third were
    BYTE-IDENTICAL -- same route, same `btn-success`, same three title attributes -- so the
    condition on the second decided nothing. They are one `@else` now. Diffed against the
    committed original before collapsing them; had they differed in any attribute this would have
    been a behaviour change rather than a tidy-up.

    WHAT IS DELIBERATELY UNCHANGED. `\Crypt::encrypt($user->id)` stays in the two routes that use
    it. It is NOT an authorization control — APP_KEY is in git history, so an encrypted id is
    forgeable (SEC-F1 records this, and both handlers authorize independently of it) — but
    removing it here would change the URL shape those routes parse. The `.show_confirm` contract,
    the DELETE form and the ajax-popup attributes are untouched.
--}}
<span class="d-flex gap-1 justify-content-end">
    @permission('Edit User')
        <a href="#!" data-size="md"
            data-url="{{ route('users.edit', $user->id) }}"
            data-ajax-popup="true"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Edit User') }}"
            aria-label="{{ __('Edit user :name', ['name' => $user->name]) }}">
            <i class="ti ti-pencil" aria-hidden="true"></i>
        </a>
    @endpermission

    @permission('Delete User')
        {!! Form::open(['method' => 'DELETE', 'route' => ['users.destroy', $user->id], 'class' => 'd-inline']) !!}
        <a href="#" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet bs-pass-para show_confirm"
            data-confirm="{{ __('Are You Sure?') }}"
            data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
            data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
            data-confirm-yes="delete-form-{{ $user->id }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Delete') }}"
            aria-label="{{ __('Delete user :name', ['name' => $user->name]) }}">
            <i class="ti ti-trash" aria-hidden="true"></i>
        </a>
        {!! Form::close() !!}
    @endpermission

    @can('resetPassword', $user)
        <a href="#"
            data-url="{{ route('stores.reset.password', \Crypt::encrypt($user->id)) }}"
            data-ajax-popup="true" data-size="md"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--secondary"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Reset Password') }}"
            aria-label="{{ __('Reset password for :name', ['name' => $user->name]) }}">
            <i class="ti ti-adjustments" aria-hidden="true"></i>
        </a>
    @endcan

    @can('update', $user)
        @if ($user->is_enable_login == 1)
            <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
                class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet"
                data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Login Disable') }}"
                aria-label="{{ __('Disable login for :name', ['name' => $user->name]) }}">
                <i class="ti ti-road-sign" aria-hidden="true"></i>
            </a>
        @else
            <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
                class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
                data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Login Enable') }}"
                aria-label="{{ __('Enable login for :name', ['name' => $user->name]) }}">
                <i class="ti ti-road-sign" aria-hidden="true"></i>
            </a>
        @endif
    @endcan
</span>
