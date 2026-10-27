{{--
    P2-05-5 — the reset-password form, on the design system.

    A styling change only: the labels here already named their inputs correctly (unlike
    `users/create.blade.php` beside it, where `Form::label('Email', …)` rendered
    `<label for="Email">` against `<input id="email">`). `form-control` -> `ss-input`,
    `form-label` -> `ss-label`, the wrappers -> `ss-field`, the footer buttons -> the design
    system's. Routes, field names, `required`, `@csrf` and the ajax-popup contract are untouched.

    `data-ss-theme="light"` names the surface: the host's Bootstrap modal keeps a WHITE
    `.modal-content` in dark mode (measured in P2-05-4), so design-system components inside it
    would otherwise resolve their dark tokens against white.
--}}
<div data-ss-theme="light">
{{ Form::model($user, ['route' => ['users.resetpassword', $user->id], 'method' => 'post']) }}

<div class="row">
    <div class="ss-field col-12">
        {{ Form::label('password', __('Password'), ['class' => 'ss-label']) }}
        <div class="form-icon-user">
            <input id="password" type="password" class="ss-input @error('password') is-invalid @enderror"
                name="password" required autocomplete="new-password" placeholder="Enter Password">
        </div>
        @error('password')
            <span class="invalid-feedback" role="alert">
                <strong>{{ $message }}</strong>
            </span>
        @enderror
    </div>
    <div class="ss-field col-12">
        {{ Form::label('password_confirmation', __('Confirm Password'), ['class' => 'ss-label']) }}
        <div class="form-icon-user">
            <input id="password_confirmation" type="password" class="ss-input" name="password_confirmation" required
                autocomplete="new-password" placeholder="Enter Confirm Password">
        </div>
    </div>
</div>
<div class="ss-dialog__footer modal-footer pb-0">
    <input type="button" value="{{ __('Cancel') }}" class="ss-btn ss-btn--secondary ss-btn--sm" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Update') }}" class="ss-btn ss-btn--primary ss-btn--sm mx-1">
</div>
{{ Form::close() }}
</div>
