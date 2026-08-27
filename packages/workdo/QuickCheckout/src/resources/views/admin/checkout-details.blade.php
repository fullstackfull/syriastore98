<div class="tab-pane fade @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-quick_checkout-tab')) show active @endif" id="pills-quick_checkout" role="tabpanel" aria-labelledby="pills-quick_checkout-tab">
    <div id="quick_checkout_settings">
        <div class="card">
            {{ Form::model($settings, ['route' => ['update.quick.checkout.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-quick_checkout-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class=""> {{ __('Quick Checkout Settings') }} </h5>
                    <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="" name="enable_quick_checkout"
                                id="enable_quick_checkout" {{ $settings['enable_quick_checkout'] ?? '' == 'on' ? 'checked="checked"' : '' }}>
                            <label class="custom-control-label" for="enable_quick_checkout"></label>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row mt-2">
                        <div class="form-group col-md-6">
                                {{ Form::label('quick_button_title', __('Add Button Title'), ['class' => 'col-form-label']) }}
                                {{ Form::text('quick_button_title', null, array('class' => 'form-control','id'=>"store-locate-address",'required'=>'required','placeholder' => 'Enter Your Button Title')) }}
                                @error('quick_button_title')
                                    <span class="invalid-quick_button_title" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end flex-wrap ">
                    <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
                </div>
            {{ Form::close() }}
        </div>
    </div>
</div>

