{{ Form::open(array('route' => 'ownermenus.store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
    <div class="">
        @csrf
        <div class="row">
            <div class="form-group col-md-12">
                {{Form::label('name',__('Menu Name'),['class'=>'form-label'])}}
                {{Form::text('name',null,array('class'=>'form-control font-style','placeholder'=>__('Enter Menu Title'),'required'=>'required'))}}
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
{{ Form::close() }}
