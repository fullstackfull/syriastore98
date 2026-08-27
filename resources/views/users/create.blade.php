<form method="post" action="{{ route('users.store') }}" autocomplete="off">
    @csrf
    <div class="row">
        <div class="form-group col-md-12">
            {{Form::label('name',__('Name'),array('class'=>'form-label'))}}
            {{Form::text('name',null,array('class'=>'form-control','id'=>'name','placeholder'=>__('Enter Name'), 'required' => 'required'))}}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('Email',__('Email'),array('class'=>'form-label')) }}
            {{ Form::email('email',null,array('class'=>'form-control','id'=>'email','placeholder'=>__('Enter Email'),'required'=>'required')) }}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('Password',__('Password'),array('class'=>'form-label')) }}
            {{ Form::password('password',array('class'=>'form-control','id'=>'password','placeholder'=>__('Enter Password'),'required'=>'required')) }}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('role',__('User Role'),array('class'=>'form-label')) }}
            {{ Form::select('role',$roles,null,array('class'=>'form-control','id'=>'role','placeholder'=>__('Select Role'),'required'=>'required')) }}
        </div>
        <div class="modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Create')}}" class="btn btn-primary btn-badge mx-1">
        </div>
    </div>
</form>
