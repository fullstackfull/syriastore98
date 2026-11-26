{{--
    P2-05-5 — the create-user form, on the design system.

    TWO OF THE FOUR LABELS POINTED AT IDS THAT DO NOT EXIST. `Form::label('Email', …)` renders
    `<label for="Email">` while the input is `id="email"`, and the same for `Password`. HTML id
    matching is CASE-SENSITIVE, so both labels associated with nothing: clicking them did not
    focus the field and a screen reader announced both inputs unlabelled. Verified by rendering
    the helper rather than by reading it -- `<label for="Email">` against `<input id="email">`.

    That is the same consequence as the category form's `Form::label('', …)` (P2-05-4) by a
    different mechanism, which is why it survived: a scan for the empty-string form would not
    have found it. `name` and `role` were already correct, so only two of four were broken --
    the kind of partial breakage that looks fine at a glance.

    Styling follows: `form-control` -> `ss-input` / `ss-select`, `form-label` -> `ss-label`, the
    wrappers -> `ss-field`, the footer buttons -> the design system's. The route, the field
    names, `@csrf`, `required` and the ajax-popup contract are untouched.

    `data-ss-theme="light"` for the same measured reason as P2-05-4: the host's Bootstrap modal
    keeps a WHITE `.modal-content` in dark mode, so design-system components inside it would
    otherwise resolve their dark tokens against white.
--}}
<div data-ss-theme="light">
<form method="post" action="{{ route('users.store') }}" autocomplete="off">
    @csrf
    <div class="row">
        <div class="ss-field col-md-12">
            <span class="ss-label-line">{{Form::label('name',__('Name'),array('class'=>'ss-label'))}}<span class="ss-label__required" aria-hidden="true">*</span></span>
            {{Form::text('name',null,array('class'=>'ss-input','id'=>'name','placeholder'=>__('Enter Name'), 'required' => 'required'))}}
        </div>
        <div class="ss-field col-md-12">
            <span class="ss-label-line">{{ Form::label('email',__('Email'),array('class'=>'ss-label')) }}<span class="ss-label__required" aria-hidden="true">*</span></span>
            {{ Form::email('email',null,array('class'=>'ss-input','id'=>'email','placeholder'=>__('Enter Email'),'required'=>'required')) }}
        </div>
        <div class="ss-field col-md-12">
            <span class="ss-label-line">{{ Form::label('password',__('Password'),array('class'=>'ss-label')) }}<span class="ss-label__required" aria-hidden="true">*</span></span>
            {{ Form::password('password',array('class'=>'ss-input','id'=>'password','placeholder'=>__('Enter Password'),'required'=>'required')) }}
        </div>
        <div class="ss-field col-md-12">
            <span class="ss-label-line">{{ Form::label('role',__('User Role'),array('class'=>'ss-label')) }}<span class="ss-label__required" aria-hidden="true">*</span></span>
            {{ Form::select('role',$roles,null,array('class'=>'ss-select','id'=>'role','aria-describedby'=>'role-help','placeholder'=>__('Select Role'),'required'=>'required')) }}
            {{-- GAP-060 / P02-I4. THREE THINGS A MERCHANT CANNOT SEE FROM THIS CONTROL, each read
                 off the write path rather than inferred.

                 1. THE LIST IS SCOPED TO THE STORE THEY ARE IN. `UserController::create():59` is
                    `Role::where('created_by', creatorId())->where('store_id', getCurrentStore())`,
                    and `store():139-142` re-scopes the same way before writing. So a role created
                    in another store is simply absent, and an empty list means "no role exists in
                    THIS store yet" rather than "no roles exist".
                 2. THE ROLE BECOMES THE ACCOUNT TYPE. `store():155` writes
                    `$user->type = $role_r->name`, which is the column every policy in this
                    codebase compares against.
                 3. THE MEMBER IS TIED TO THAT STORE. `store():157` writes
                    `is_assign_store = $objUser->current_store`.

                 `x-ss.field-help` AND NOT `x-ss.choice-group`: one `<select>` is ONE control in
                 the accessibility tree, so a fieldset would be the wrong association rather than
                 a stricter one. The component owns the `id`; this call site owns the
                 `aria-describedby`, and an orphan hint is decoration. --}}
            <x-ss.field-help for="role">{{ __('Only roles you created in the store you are working in are listed, so a role added in another store will not appear here. The role becomes this member\'s account type and decides which screens they can open, and the member is assigned to the store you are working in.') }}</x-ss.field-help>
        </div>
        <div class="ss-dialog__footer modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="ss-btn ss-btn--secondary ss-btn--sm" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Create')}}" class="ss-btn ss-btn--primary ss-btn--sm mx-1">
        </div>
    </div>
</form>
</div>
