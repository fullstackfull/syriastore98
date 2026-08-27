<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-recent_orders-tab') show active @endif" id="pills-recent_orders" role="tabpanel"
    aria-labelledby="pills-recent_orders-tab">
    <div id="recent_orders_settings">
        <div class="card">
            {{ Form::model($setting, ['route' => 'recent.orders.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-recent_orders-tab">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> {{ __('Recent Orders Settings') }} </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary"
                            class="recent_orders_enabled" name="recent_orders_enabled" id="recent_orders_enabled"
                            {{ isset($setting['recent_orders_enabled']) && $setting['recent_orders_enabled'] == 'on' ? 'checked' : '' }}>
                        <label class="custom-control-label" for="recent_orders_enabled"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row recent_orders-settings faq mt-2">
                    {{-- <div class="row "> --}}
                        {{-- <div class="accordion-body p-4"> --}}
                            {{-- <div class="row"> --}}
                                <div class="col-md-4">
                                    <div class="form-group">
                                        {{ Form::label('recent_orders', __('Recent Orders'), ['class' => 'form-label']) }}
                                        {{ Form::number('recent_orders', null, ['class' => 'form-control', 'placeholder' => 'Recent Orders']) }}
                                        @error('recent_orders')
                                            <span class="invalid-recent_orders" role="alert">
                                                <strong class="text-danger">{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            {{-- </div> --}}
                        {{-- </div> --}}
                    {{-- </div> --}}
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
        if ($('.recent_orders_enabled').is(':checked')) {
            $('.recent_orders-settings').removeClass('disabledCookie');
        } else {
            $('.recent_orders-settings').addClass('disabledCookie');
        }
        $('.recent_orders_enabled').on('change', function() {
            if ($('.recent_orders_enabled').is(':checked')) {
                $('.recent_orders-settings').removeClass('disabledCookie');
            } else {
                $('.recent_orders-settings').addClass('disabledCookie');
            }
        });
    </script>
@endpush
