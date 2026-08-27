    {{ Form::model($user, ['route' => ['users.update', $user->id],'method' => 'PUT']) }}

    @csrf
    <div class="row">
        <div class="form-group col-md-12">
            {{Form::label('name',__('Name'),array('class'=>'form-label'))}}
            {{Form::text('name',$user->name,array('class'=>'form-control','id'=>'name','placeholder'=>__('Enter Name'),'required'=>'required'))}}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('email',__('Email'),array('class'=>'form-label')) }}
            {{ Form::email('email',$user->email,array('class'=>'form-control','id'=>'email','placeholder'=>__('Enter Email'),'required'=>'required')) }}
        </div>
        @if (auth()->user()->type != 'super admin')
        <div class="form-group col-md-12">
            {{ Form::label('role',__('User Role'),array('class'=>'form-label')) }}
            {{ Form::select('role',$roles,$user->roles,array('class'=>'form-control','id'=>'role','placeholder'=>__('Select Role'),'required'=>'required')) }}
        </div>
        @endif
        <div class="modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Update')}}" class="btn btn-primary btn-badge mx-1">
        </div>
    </div>
</form>
