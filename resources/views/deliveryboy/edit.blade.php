{{ Form::model($deliveryBoy, ['route' => ['deliveryboy.update', $deliveryBoy->id],'method' => 'PUT']) }}
    @csrf
    <div class="row">
        <div class="form-group col-md-12">
            {{Form::label('name',__('Name'),array('class'=>'form-label'))}}
            {{Form::text('name',null,array('class'=>'form-control','placeholder'=>__('Enter Name'),'required'=>'required'))}}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('Email',__('Email'),array('class'=>'form-label')) }}
            {{ Form::email('email',null,array('class'=>'form-control','placeholder'=>__('Enter Email'),'required'=>'required')) }}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('Contact',__('Contact'),array('class'=>'form-label')) }}
            {{ Form::number('contact',null,array('class'=>'form-control','placeholder'=>__('Enter Contact Number'))) }}
        </div>

        <div class="form-group col-md-12">
            {{ Form::label('commission_percentage', __('Commission Percentage (%)'), array('class'=>'form-label')) }}
            {{ Form::number('commission_percentage', null, array(
                'class'=>'form-control',
                'placeholder'=>__('Enter Commission Percentage'),
                'step'=>'0.01',
                'min'=>'0',
                'max'=>'100'
            )) }}
            <small class="text-muted">{{ __('Optional: Percentage of the delivery cost earned by this delivery boy.') }}</small>
        </div>

        <div class="form-group col-md-12 mt-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_commission_enabled" id="is_commission_enabled" value="1" {{ old('is_commission_enabled', $deliveryBoy->is_commission_enabled ?? false) ? 'checked' : '' }}>
                <label class="form-check-label fw-bold" for="is_commission_enabled">
                     (Enable Commission)
                </label>
            </div>
        </div>
        <!-- ========================================== -->

        <input type="hidden" name="type" value="deliveryboy">

        <div class="modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Update')}}" class="btn btn-primary btn-badge mx-1">
        </div>
    </div>
</form>