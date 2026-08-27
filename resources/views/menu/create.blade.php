{{ Form::open(['route' => 'menus.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
    <div class="row">
        <div class="form-group col-md-12">
            {!! Form::label('', __('Name'), ['class' => 'form-label']) !!}
            {!! Form::text('name', null, ['class' => 'form-control' , 'required']) !!}
        </div>
        <div class="modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
        </div>
    </div>
{!! Form::close() !!}
