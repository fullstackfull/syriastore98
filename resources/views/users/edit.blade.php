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
            {{Form::label('name',__('Name'),array('class'=>'ss-label'))}}
            {{Form::text('name',$user->name,array('class'=>'ss-input','id'=>'name','placeholder'=>__('Enter Name'),'required'=>'required'))}}
        </div>
        <div class="ss-field col-md-12">
            {{ Form::label('email',__('Email'),array('class'=>'ss-label')) }}
            {{ Form::email('email',$user->email,array('class'=>'ss-input','id'=>'email','placeholder'=>__('Enter Email'),'required'=>'required')) }}
        </div>
        @if (auth()->user()->type != 'super admin')
        <div class="ss-field col-md-12">
            {{ Form::label('role',__('User Role'),array('class'=>'ss-label')) }}
            {{ Form::select('role',$roles,$user->roles,array('class'=>'ss-select','id'=>'role','placeholder'=>__('Select Role'),'required'=>'required')) }}
        </div>
        @endif
        <div class="ss-dialog__footer modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="ss-btn ss-btn--secondary ss-btn--sm" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Update')}}" class="ss-btn ss-btn--primary ss-btn--sm mx-1">
        </div>
    </div>
</form>
</div>
