<div class="tab-pane fade  @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-free_shipping_popup-tab')) show active @endif" id="pills-free_shipping_popup" role="tabpanel" aria-labelledby="pills-free_shipping_popup-tab">
    <div id="free_shipping_popup_settings">
        <div class="card">
            {{ Form::model($setting, ['route' => 'free.shipping.popup.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-free_shipping_popup-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> {{ __('Free Shipping Pop-Up Settings') }} </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="" name="free_shipping_popup_enabled"
                            id="free_shipping_popup_enabled" {{ $setting['free_shipping_popup_enabled'] ?? '' == 'on' ? 'checked="checked"' : '' }}>
                        <label class="custom-control-label" for="free_shipping_popup_enabled"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="col-md-6 free-shipping-settings">
                        <div class="form-group">
                            {{ Form::label('free_shipping_apply_price', __('Frre Shipping Apply Price'), ['class' => 'form-label']) }}
                            {{ Form::number('free_shipping_apply_price', null, ['class' => 'form-control free_shipping_apply_price', 'placeholder' => 'Enter Frre Shipping Apply Price']) }}
                            @error('free_shipping_apply_price')
                                <span class="invalid-free_shipping_apply_price" role="alert">
                                    <strong class="text-danger">{{ $message }}</strong>
                                </span>
                            @enderror
                            <span class="text-muted">{{ __('Note: Enter the minimum price from which you want the free shipping price to apply.') }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                {{-- @permission('Edit Store Setting') --}}
                <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
                {{-- @endpermission --}}
            </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>

@push('custom-script')
    <script>
        if ($('#free_shipping_popup_enabled').is(':checked')) {

            $('.free-shipping-settings').removeClass('disabledCookie');
            $('.free_shipping_apply_price').attr("required", true);
            $('.free_shipping_apply_price').attr("min", 1);
        } else {

            $('.free-shipping-settings').addClass('disabledCookie');
            $('.free_shipping_apply_price').removeAttr("required");
            $('.free_shipping_apply_price').removeAttr("min");
        }

        $('#free_shipping_popup_enabled').on('change', function() {
            if ($('#free_shipping_popup_enabled').is(':checked')) {

                $('.free-shipping-settings').removeClass('disabledCookie');
                $('.free_shipping_apply_price').attr("required", true);
                $('.free_shipping_apply_price').attr("min", 1);
            } else {

                $('.free-shipping-settings').addClass('disabledCookie');
                $('.free_shipping_apply_price').removeAttr("required");
                $('.free_shipping_apply_price').removeAttr("min");
            }
        });
    </script>
@endpush
