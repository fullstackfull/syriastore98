{{--
    P2-05-5 — the roles list's row actions, on the design system.

    Unlike the staff list beside it, these were ALREADY permission-gated and the gates already
    matched their handlers (`RoleController::edit`/`::destroy` on `Edit Role` / `Delete Role`), so
    nothing about who sees what changes here.

    What was wrong is the same two things as everywhere else in this sweep: both controls carried
    only `title`, which Bootstrap's tooltip moves to `data-bs-original-title` and blanks on
    initialisation, so each was an unnamed control wrapping an unlabelled `<i>`; and both came off
    the vendored theme's `btn-info` / `btn-danger`, the family P2-04-4 measured below AA in 44 of
    56 readings across LTR/RTL x light/dark.

    The `data-ajax-popup` contract, `data-size="xl"`, the DELETE form and `.show_confirm` are
    untouched.
--}}
<span class="d-flex gap-1 justify-content-end">
    @permission('Edit Role')
        <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
            data-url="{{ route('roles.edit', $role->id) }}" data-size="xl"
            data-ajax-popup="true" data-title="{{ __('Edit Roles') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Edit') }}"
            aria-label="{{ __('Edit role :name', ['name' => $role->name]) }}">
            <i class="ti ti-pencil" aria-hidden="true"></i>
        </button>
    @endpermission

    @permission('Delete Role')
        {!! Form::open(['method' => 'DELETE', 'route' => ['roles.destroy', $role->id], 'class' => 'd-inline']) !!}
        <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm"
            data-confirm="{{ __('Are You Sure?') }}"
            data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
            data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Delete') }}"
            aria-label="{{ __('Delete role :name', ['name' => $role->name]) }}">
            <i class="ti ti-trash" aria-hidden="true"></i>
        </button>
        {!! Form::close() !!}
    @endpermission
</span>
