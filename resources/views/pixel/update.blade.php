{{ Form::open(['route'=>array('pixel-setting.update',$pixelFields->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data']) }}
<div class="row">
    <div class="col-12">
        <div class="form-group">
            {{-- P2.5 a11y -- THE LABEL POINTED AT A NAME THAT DOES NOT EXIST.
                `Form::label($for, $text)` writes `for` from its FIRST argument, and these two
                passed the human-readable caption there while `Form::select`/`Form::text` generate
                their id from the FIELD NAME. So the markup read
                `<label for="Platform">` ... `<select id="platform">` -- different strings, no
                association at all, and the modal harness reported `select[name=platform]` as
                unlabelled in all four combinations of both pixel modals.
                A placeholder is not a name and neither is a nearby caption; the `for` has to
                match. --}}
            {{ Form::label('platform', __('Platform'), ['class' => 'col-form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
            {{ Form::select('platform', $pixel_plateforms,$pixelFields->platform, ['class' => 'form-control', 'placeholder'=>'Please Select','required'=>'required']) }}
        </div>
        <div class="form-group">
            {{  Form::label('pixel_id',__('Pixel Id'),['class'=>'col-form-label'])  }}<span class="ss-label__required" aria-hidden="true">*</span>
            {{ Form::text('pixel_id',$pixelFields->pixel_id,array('class'=>'form-control','placeholder'=>'Enter Pixel Id','required'=>'required')) }}
        </div>
    </div>
</div>
<div class="modal-footer pb-0">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Update') }}" class="btn btn-badge btn-primary mx-1">
</div>
{{ Form::close() }}
