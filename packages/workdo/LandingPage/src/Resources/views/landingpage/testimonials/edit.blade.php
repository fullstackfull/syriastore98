{{Form::model(null, array('route' => array('testimonials_update', $key), 'method' => 'POST','enctype' => "multipart/form-data")) }}
    @csrf
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('testimonials_title', __('Title'), ['class' => 'form-label']) }}
                {{ Form::text('testimonials_title',$testimonial['testimonials_title'], ['class' => 'form-control ', 'placeholder' => __('Enter Title'), 'id' => 'testimonials_title']) }}
            </div>
        </div>

        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('testimonials_star', __('Star'), ['class' => 'form-label']) }}
                {{ Form::number('testimonials_star',$testimonial['testimonials_star'], ['class' => 'form-control ', 'min'=>'1', 'max'=>'5','required'=>'required', 'placeholder' => __('Enter Star'), 'id' => 'testimonials_star']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('testimonials_description', __('Description'), ['class' => 'form-label']) }}
                {{ Form::textarea('testimonials_description', $testimonial['testimonials_description'], ['class' => 'form-control', 'placeholder' => __('Enter Description'), 'id'=>'mytextarea']) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('testimonials_user', __('User'), ['class' => 'form-label']) }}
                {{ Form::text('testimonials_user',$testimonial['testimonials_user'], ['class' => 'form-control ', 'placeholder' => __('Enter User Name'), 'id' => 'testimonials_user']) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('testimonials_designation', __('Designation'), ['class' => 'form-label']) }}
                {{ Form::text('testimonials_designation',$testimonial['testimonials_designation'], ['class' => 'form-control ', 'placeholder' => __('Enter Designation'), 'id' => 'testimonials_designation']) }}
            </div>
        </div>

        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('testimonials_user_avtar', __('User Avtar'), ['class' => 'form-label']) }}
                <input type="file" id="testimonials_user_avtar" name="testimonials_user_avtar" class="form-control">
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge mx-1 btn-primary">
    </div>

{{ Form::close() }}
