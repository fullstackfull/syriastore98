{{--
    P2-05-5 — the edit-user form, on the design system.

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
    {{ Form::model($user, ['route' => ['users.update', $user->id],'method' => 'PUT']) }}

    @csrf
    <div class="row">
        <div class="ss-field col-md-12">
            <span class="ss-label-line">{{Form::label('name',__('Name'),array('class'=>'ss-label'))}}<span class="ss-label__required" aria-hidden="true">*</span></span>
            {{Form::text('name',$user->name,array('class'=>'ss-input','id'=>'name','placeholder'=>__('Enter Name'),'required'=>'required'))}}
        </div>
        <div class="ss-field col-md-12">
            <span class="ss-label-line">{{ Form::label('email',__('Email'),array('class'=>'ss-label')) }}<span class="ss-label__required" aria-hidden="true">*</span></span>
            {{ Form::email('email',$user->email,array('class'=>'ss-input','id'=>'email','placeholder'=>__('Enter Email'),'required'=>'required')) }}
        </div>
        @if (auth()->user()->type != 'super admin')
        <div class="ss-field col-md-12">
            <span class="ss-label-line">{{ Form::label('role',__('User Role'),array('class'=>'ss-label')) }}<span class="ss-label__required" aria-hidden="true">*</span></span>
            {{ Form::select('role',$roles,$user->roles,array('class'=>'ss-select','id'=>'role','aria-describedby'=>'role-help','placeholder'=>__('Select Role'),'required'=>'required')) }}
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
        @endif
        <div class="ss-dialog__footer modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="ss-btn ss-btn--secondary ss-btn--sm" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Update')}}" class="ss-btn ss-btn--primary ss-btn--sm mx-1">
        </div>
    </div>
</form>
</div>
