<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-skip_cart-tab') show active @endif" id="pills-skip_cart" role="tabpanel"
    aria-labelledby="pills-skip_cart-tab">
    <div id="skip_cart_settings">
        <div class="card">
            {{ Form::model($settings, ['route' => ['save.skip.cart.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data', 'id' => 'skip_cart-setting']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-skip_cart-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> {{ __('Skip Cart Settings') }} </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class=""
                            name="enable_skip_cart" id="enable_skip_cart"
                            {{ $settings['enable_skip_cart'] ?? '' == 'on' ? 'checked="checked"' : '' }}>
                        <label class="custom-control-label" for="enable_skip_cart"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="col-md-12">
                        <div class="row">
                            <div class="form-group col-md-4">
                                {!! Form::label('', __('Checkout Setting'), ['class' => 'form-label']) !!}
                                {!! Form::select(
                                    'skip_cart_checkout_setting',
                                    [
                                        'one_click_checkout' => 'Single Tap Checkout',
                                        'one_page_checkout' => 'On Page Checkout',
                                        'sticky_cart' => 'Sticky Cart',
                                    ],
                                    old('skip_cart_checkout_setting'),
                                    ['class' => 'form-control', 'id' => 'checkout_setting'],
                                ) !!}
                            </div>
                            <div class="col-md-8">
                                <div id="one_click_form_view" style="display: none;">
                                    <div class="row">
                                        <div class="form-group col-md-6">
                                            {!! Form::label('', __('Add to Cart Button Text'), ['class' => 'form-label']) !!}
                                            {!! Form::text('skip_cart_button_text', old('skip_cart_button_text'), [
                                                'class' => 'form-control',
                                                'placeholder' => __('Enter Add to Cart Button Text'),
                                            ]) !!}
                                        </div>
                                        <div class="form-group col-md-6">
                                            {!! Form::label('', __('Button Link'), ['class' => 'form-label']) !!}
                                            {!! Form::select(
                                                'skip_cart_button_link',
                                                ['cart_page' => 'On Cart Page', 'checkout_page' => 'On Checkout Page'],
                                                old('skip_cart_button_link'),
                                                [
                                                    'class' => 'form-control',
                                                ],
                                            ) !!}
                                        </div>
                                    </div>
                                </div>
                                <div id="sticky_checkout_view" style="display: none;">
                                    <div class="form-group col-md-6">
                                        {!! Form::label('', __('Set Sticky Position'), ['class' => 'form-label']) !!}
                                        {!! Form::select(
                                            'skip_cart_sticky_position',
                                            ['position_top' => 'Top', 'position_bottom' => 'Bottom', 'position_left' => 'Left', 'position_right' => 'Right'],
                                            old('skip_cart_sticky_position'),
                                            [
                                                'class' => 'form-control',
                                            ],
                                        ) !!}
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
            {{ Form::close() }}
        </div>
    </div>
</div>
@push('scripts')
    <script>
        $(document).ready(function() {
            $('#checkout_setting').on('change', function() {
                if ($(this).val() == 'one_click_checkout') {
                    $('#one_click_form_view').show();
                    $('#sticky_checkout_view').hide();
                } else if ($(this).val() == 'sticky_cart') {
                    $('#sticky_checkout_view').show();
                    $('#one_click_form_view').hide();
                } else {
                    $('#one_click_form_view').hide();
                    $('#sticky_checkout_view').hide();
                }
            });
            $('#checkout_setting').trigger('change');
        });
    </script>
@endpush
