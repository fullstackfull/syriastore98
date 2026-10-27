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
            {{Form::label('name',__('Name'),array('class'=>'ss-label'))}}
            {{Form::text('name',null,array('class'=>'ss-input','id'=>'name','placeholder'=>__('Enter Name'), 'required' => 'required'))}}
        </div>
        <div class="ss-field col-md-12">
            {{ Form::label('email',__('Email'),array('class'=>'ss-label')) }}
            {{ Form::email('email',null,array('class'=>'ss-input','id'=>'email','placeholder'=>__('Enter Email'),'required'=>'required')) }}
        </div>
        <div class="ss-field col-md-12">
            {{ Form::label('password',__('Password'),array('class'=>'ss-label')) }}
            {{ Form::password('password',array('class'=>'ss-input','id'=>'password','placeholder'=>__('Enter Password'),'required'=>'required')) }}
        </div>
        <div class="ss-field col-md-12">
            {{ Form::label('role',__('User Role'),array('class'=>'ss-label')) }}
            {{ Form::select('role',$roles,null,array('class'=>'ss-select','id'=>'role','placeholder'=>__('Select Role'),'required'=>'required')) }}
        </div>
        <div class="ss-dialog__footer modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="ss-btn ss-btn--secondary ss-btn--sm" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Create')}}" class="ss-btn ss-btn--primary ss-btn--sm mx-1">
        </div>
    </div>
</form>
</div>
