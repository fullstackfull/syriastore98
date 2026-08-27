{{ Form::open(['route' => 'auctionsetting.store', 'method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'repeater']) }}
<div class="row">
    <div class="form-group col-md-6">
        {!! Form::label('name', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('name', null, ['class' => 'form-control', 'id' => 'name', 'required']) !!}
    </div>
    <div class="form-group col-md-6 format">
        {!! Form::label('auction_product', __('Auction Products'), ['class' => 'form-label']) !!}
        {!! Form::select('auction_product[]', $product, null, [
            'class' => 'form-control select2',
            'id' => 'auction_product',
            'multiple',
            'data-role' => 'tagsinput',
        ]) !!}
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('start_date', __('Start Date'), ['class' => 'form-label']) !!}
        {!! Form::date('start_date', null, ['class' => 'form-control', 'id' => 'start_date', 'required']) !!}
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('end_date', __('End Date'), ['class' => 'form-label']) !!}
        {!! Form::date('end_date', null, ['class' => 'form-control', 'id' => 'end_date', 'required']) !!}
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('start_time', __('Start Time'), ['class' => 'form-label']) !!}
        {!! Form::time('start_time', null, ['class' => 'form-control', 'id' => 'start_time', 'required']) !!}
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('end_time', __('End Time'), ['class' => 'form-label']) !!}
        {!! Form::time('end_time', null, ['class' => 'form-control', 'id' => 'end_time', 'required']) !!}
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
<link rel="stylesheet" href="{{ asset('public/assets/css/plugins/daterangepicker.css') }}">
<script src="{{ asset('public/assets/js/plugins/daterangepicker.js') }}"></script>
<script src="{{ asset('public/js/jquery-ui.min.js') }}"></script>
