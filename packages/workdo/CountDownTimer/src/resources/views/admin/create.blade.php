<style>
    ul.uploaded-pics {
    list-style-type: none;
    padding-left: 0px !important;
    }
    ul.uploaded-pics input[type="radio"][id^="checkthis"] {
        display: none;
    }
    ul.uploaded-pics label {
        padding: 5px;
        display: block;
        position: relative;
        cursor: pointer;
        width: 100%;
        margin: auto;
        border-radius: 5px;
    }
    ul.uploaded-pics input[type="radio"]:checked+label img {
        z-index: -1;
        border: 2px dashed #0095ff;
    }
    ul.uploaded-pics label img {
        width: 100%;
        transition-duration: 0.2s;
        transform-origin: 50% 50%;
        object-fit: scale-down;
    }
</style>
{{ Form::open(['route' => 'countdowntimer.store', 'method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'repeater']) }}
<div class="row">
    <div class="form-group col-md-12">
        {!! Form::label('title', __('Title'), ['class' => 'form-label']) !!}
        {!! Form::text('title', null, ['class' => 'form-control', 'id' => 'title', 'required', 'placeholder' => 'Title']) !!}
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('start_date', __('Start Date'), ['class' => 'form-label']) !!}
        {!! Form::date('start_date', null, ['class' => 'form-control', 'id' => 'start_date', 'required']) !!}
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('start_time', __('Start Time'), ['class' => 'form-label']) !!}
        {!! Form::time('start_time', null, ['class' => 'form-control', 'id' => 'start_time', 'required']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('description', __('Description'), ['class' => 'form-label']) !!}
        {!! Form::textarea('description', null, ['class' => 'form-control autogrow', 'id' => 'description', 'rows' => '3']) !!}
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('sale_image', __('Sale Logo'), ['class' => 'form-label']) !!}
        <label for="sale_image" class="image-upload bg-primary pointer w-100">
            <i class="ti ti-upload px-1"></i> {{ __('Choose File Here') }}
        </label>
        <input type="file" name="sale_image" id="sale_image" class="d-none">
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('image', __('Cover Image'), ['class' => 'form-label']) !!}
        <label for="image" class="image-upload bg-primary pointer w-100">
            <i class="ti ti-upload px-1"></i> {{ __('Choose File Here') }}
        </label>
        <input type="file" name="image" id="image" class="d-none">
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('status', __('Status'), ['class' => 'form-label']) !!}
        <div class="form-check form-switch">
            {!! Form::checkbox(
                'status',
                'on',
                true,
                [
                    'class' => 'form-check-input',
                    'id' => 'status',
                ],
            ) !!}
        </div>
    </div>
    <div class="horizontal mt-3">
        <div class="verticals twelve">
            <div class="form-group col-md-6">
                {{ Form::label('Select-Theme-Layout', __('Select Theme Layout'),['class'=>'form-control-label', 'id' => 'Select-Theme-Layout']) }}
            </div>
            <ul class="uploaded-pics row">
                @foreach($themeLayouts as $key => $themeLayout)
                    @php
                        $key++;
                    @endphp
                    <li class="col-xxl-4 col-md-6">
                        <input type="radio" id="checkthis{{$key}}" value="{{$key}}" name="theme_layout" {{$key === 5 ? 'checked' : ''}}/>
                        <label for="checkthis{{$key}}">
                            <img src="{{ asset('packages/workdo/CountDownTimer/src/resources/assets/layout-img/'.$themeLayout.'.png') }}"/>
                        </label>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
