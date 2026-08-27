{{ Form::open(['route' => 'currency.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

<div class="row">
    <div class="form-group col-md-12">
        {!! Form::label('name', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('name', null, ['class' => 'form-control', 'placeholder' => 'Enter Name', 'required' => 'true']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('code', __('Code'), ['class' => 'form-label']) !!}
        {!! Form::text('code', null, ['class' => 'form-control', 'placeholder' => 'Enter Currency Code', 'required' => 'true']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('symbol', __('Symbol'), ['class' => 'form-label']) !!}
        {!! Form::text('symbol', null, ['class' => 'form-control', 'placeholder' => 'Enter Currency Symbol', 'required' => 'true']) !!}
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
