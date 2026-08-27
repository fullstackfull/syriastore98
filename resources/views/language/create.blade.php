{{ Form::open(array('route' => array('store.language'))) }}
<div class="form-group">
    {{ Form::label('code', __('Language Code'),array('class'=>'form-label'))}}
    {{ Form::text('code', '', array('class' => 'form-control','required'=>'required','placeholder'=>__('Enter Language Code'))) }}
    @error('code')
    <span class="invalid-code" role="alert">
            <strong class="text-danger">{{ $message }}</strong>
        </span>
    @enderror
</div>
<div class="form-group">
    {{ Form::label('name', __('Language Name'),array('class'=>'form-label'))}}
    {{ Form::text('name', '', array('class' => 'form-control','required'=>'required','placeholder'=>__('Enter Language Name'))) }}
    @error('name')
    <span class="invalid-name" role="alert">
            <strong class="text-danger">{{ $message }}</strong>
        </span>
    @enderror
</div>
<div class="modal-footer pb-0">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Save')}}" class="btn btn-primary btn-badge mx-1">
</div>
{{ Form::close() }}

