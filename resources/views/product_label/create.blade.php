{{ Form::open(['route' => 'product-label.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

<div class="row">
    <div class="form-group  col-md-12">
        {!! Form::label('', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('name', null, ['class' => 'form-control']) !!}
    </div>
    <div class="form-group col-md-6 d-flex align-items-center gap-2">
        <label for="status" class="form-label me-2 pointer">{{ __('Status') }}</label>
        <div class="form-check form-switch">
            <input type="hidden" name="status" value="0">
            <input type="checkbox" name="status" class="form-check-input status mb-1 input-primary pointer"
                value="1" id="status">
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{ __('Cancel') }}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{ __('Create') }}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
