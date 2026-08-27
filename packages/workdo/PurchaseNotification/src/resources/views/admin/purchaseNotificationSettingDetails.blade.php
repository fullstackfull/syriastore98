<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-purchase_notification-tab') show active @endif" id="pills-purchase_notification"
    role="tabpanel" aria-labelledby="pills-purchase_notification-tab">
    <div id="purchase_notification_settings">
        <div class="card">
            {{ Form::model($setting, ['route' => 'purchase.notification.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
            @csrf
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> {{ __('Purchase Notification Settings') }} </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary"
                            class="purchase_notification_enabled" name="purchase_notification_enabled"
                            id="purchase_notification_enabled"
                            {{ isset($setting['purchase_notification_enabled']) && $setting['purchase_notification_enabled'] == 'on' ? 'checked' : '' }}>
                        <label class="custom-control-label" for="purchase_notification_enabled"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2 purchase-notification">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between">
                            <div>
                                <h5> {{ __('Product Setting') }} </h5>
                            </div>
                        </div>
                        <div class="card-body slider-body-rows">
                            <div class="row">
                                @php
                                    $get_product =
                                        isset($setting['product_id']) && !empty($setting['product_id'])
                                            ? explode(',', $setting['product_id'])
                                            : [];
                                @endphp
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('product_id', __('Select Products'), ['class' => ' form-label']) }}
                                        <select name="product_id[]" data-role="tagsinput" id="product_id" multiple>
                                            @foreach ($products as $Key => $product)
                                                <option @if (in_array($product->id, $get_product)) selected @endif
                                                    value={{ $product->id }}>
                                                    {{ $product->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="custom-control form-switch p-0 ">
                                            <label class="form-check-label mb-2"
                                                for="open_product_page">{{ __('Open Product Page') }}</label><br>
                                            <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                                data-onstyle="primary" name="open_product_page" id="open_product_page"
                                                {{ isset($setting['open_product_page']) && $setting['open_product_page'] == 'on' ? 'checked="checked"' : '' }}>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between">
                            <div>
                                <h5> {{ __('Time Setting') }} </h5>
                            </div>
                        </div>
                        <div class="card-body slider-body-rows">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('next_time_display', __('Next Time Display'), ['class' => 'form-label']) }}
                                        {{ Form::number('next_time_display', null, ['class' => 'form-control', 'placeholder' => 'Next Time Display']) }}
                                        @error('next_time_display')
                                            <span class="invalid-next_time_display" role="alert">
                                                <strong class="text-danger">{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('display_time', __('Display Time'), ['class' => 'form-label']) }}
                                        {{ Form::number('display_time', null, ['class' => 'form-control', 'placeholder' => 'Display Time']) }}
                                        @error('display_time')
                                            <span class="invalid-display_time" role="alert">
                                                <strong class="text-danger">{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
            </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>

@push('custom-script')
    <script>
        if ($('.purchase_notification_enabled').is(':checked')) {
            $('.purchase-notification').removeClass('disabledCookie');
        } else {
            $('.purchase-notification').addClass('disabledCookie');
        }

        $('.purchase_notification_enabled').on('change', function() {
            if ($('.purchase_notification_enabled').is(':checked')) {
                $('.purchase-notification').removeClass('disabledCookie');
            } else {
                $('.purchase-notification').addClass('disabledCookie');
            }
        });
    </script>
@endpush
