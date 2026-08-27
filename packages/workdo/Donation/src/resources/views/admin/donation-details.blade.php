<div class="tab-pane fade @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-store_donation-tab')) show active @endif" id="pills-store_donation" role="tabpanel" aria-labelledby="pills-store_donation-tab">
    <div id="store_donation_settings">
        <div class="card">
            {{ Form::model($donations, ['route' => ['update.store.donation.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
            <input type="hidden" name="app_setting_tab" value="pills-store_donation-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> {{ __('Donation Settings') }} </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="" name="enable_donation"
                            id="enable_donation" {{ $donations['enable_donation'] ?? '' == 'on' ? 'checked="checked"' : '' }}>
                        <label class="custom-control-label" for="enable_donation"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="form-group col-md-6">
                            {{ Form::label('payment_method', __('Payment Methods'), ['class' => 'col-form-label']) }}
                            {!! Form::select('payment_methods[]', $Payments ?? [], $enablePaymentdata ?? [], [
                                    'class' => 'form-control payment_method',
                                    'id' => 'payment_method',
                                    'multiple',
                                    'data-role' => 'tagsinput',
                                ]) !!}
                            @error('payment_method')
                                <span class="invalid-payment_method" role="alert">
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
