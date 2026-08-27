{{ Form::open(['route' => 'barcode.setting', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

<div class="row">
    <div class="form-group col-md-6">
        {{ Form::label('barcode_type', __('Barcode Type'), ['class' => 'form-label text-dark']) }}
        {{ Form::select('barcode_type', ['PHARMA' => 'PHARMA', 'PHARMA2T' => 'PHARMA2T', 'CODABAR' => 'CODABAR' ,'KIX'=>'KIX','RMS4CC'=>'RMS4CC','UPCA'=>'UPCA'], $settings['barcode_type'] ?? '', ['class' => 'form-control', 'data-toggle' => 'select']) }}
    </div>
    <div class="form-group col-md-6">
        {{ Form::label('barcode_color', __('Barcode Color'), ['class' => 'form-label text-dark']) }}
        {{ Form::select('barcode_color', ['black' => 'Black', 'blue' => 'Blue', 'brown' => 'Brown', 'green' => 'Green', 'yellow' => 'Yellow', 'orange' => 'Orange'], $settings['barcode_color'] ?? '', ['class' => 'form-control', 'data-toggle' => 'select']) }}
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}

