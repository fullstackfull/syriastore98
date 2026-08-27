{{Form::model(null, array('route' => array('marketplace_screenshots_update',[$slug, $key]), 'method' => 'POST','enctype' => "multipart/form-data")) }}
<div class="">
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('screenshots_heading', __('Heading'), ['class' => 'form-label']) }}
                {{ Form::text('screenshots_heading',$screenshot['screenshots_heading'], ['class' => 'form-control ', 'placeholder' => __('Enter Heading'),'required'=>'required', 'id' => 'screenshots_heading'])  }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('screenshots', __('Screenshot'), ['class' => 'form-label']) }}
                <div class="logo-content">
                    <img id="image" class="w-100 logo" src="{{get_file($screenshot['screenshots'])}}"
                          style="filter: drop-shadow(2px 3px 7px #011C4B);">
                </div>
                <input type="file" name="screenshots" id="screenshots" class="form-control">
            </div>
        </div>

    </div>
</div>
<div class="modal-footer pb-0">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn btn-badge mx-1 btn-primary">
</div>
{{ Form::close() }}
