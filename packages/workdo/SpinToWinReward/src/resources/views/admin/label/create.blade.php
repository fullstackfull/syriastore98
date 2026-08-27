{{ Form::open(['route' => 'spintowin-label.store', 'method' => 'post', 'enctype' => 'multipart/form-data', 'id'=> 'uploadForm']) }}

<div class="row">
    <div class="form-group col-md-12">
        {!! Form::label('spintowin-label', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('name', null, ['class' => 'form-control', 'required' => 'true', 'id' => 'spintowin-label', 'placeholder' => 'Enter Label']) !!}
        <span id="nameError" style="color: red; display: none;">{{ __('This field is required.') }}</span>
    </div>
    
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}