
<div class="tab-pane fade @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-club_point-tab')) show active @endif" id="pills-club_point" role="tabpanel" aria-labelledby="pills-club_point-tab">
    <div id="club_point_settings">
        <div class="card">
            {{ Form::model($setting, ['route' => 'club.point.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-club_point-tab">
                <div class="card-header d-flex justify-content-between align-items-center gap-3">
                    <h5 class=""> {{ __('Reward/Club Point Settings') }} </h5>
                    {{-- <div class="form-check form-switch d-inline-block">
                        <input type="checkbox" name="club_point_enabled" class="form-check-input club_point_enabled" id="club_point_enabled" {{ isset($setting['club_point_enabled']) && $setting['club_point_enabled'] == 'on' ? 'checked' : ''}}>
                    </div> --}}
                    <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="club_point_enabled"
                                name="club_point_enabled" id="club_point_enabled"
                                {{ isset($setting['club_point_enabled']) && $setting['club_point_enabled'] == 'on' ? 'checked' : ''}}>
                            <label class="custom-control-label" for="club_point_enabled"></label>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row mt-2">
                        <div class="row point-settings faq" id="accordionExample">
                            <div class="accordion accordion-flush">
                                <div class="accordion-item card">
                                    <h2 class="accordion-header" id="order_reward_setting">
                                        <button class="accordion-button collapsed" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapseone_order_reward_setting"
                                            aria-expanded="false" aria-controls="collapseone_order_reward_setting">
                                            <div class="d-flex align-items-center">
                                                <label class="form-check-label" for="platformswt1"><b>{{__('Order Reward Setting')}}</b></label>
                                            </div>
                                        </button>
                                    </h2>

                                    <div id="collapseone_order_reward_setting" class="accordion-collapse collapse"
                                        aria-labelledby="order_reward_setting" data-bs-parent="#accordionExample">
                                        <div class="accordion-body p-4">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        {{ Form::label('order_point_price', __('Order Price'), ['class' => 'form-label']) }}
                                                        {{ Form::number('order_point_price', null, ['class' => 'form-control', 'placeholder' => 'Order Price']) }}
                                                        @error('order_point_price')
                                                            <span class="invalid-order_point_price" role="alert">
                                                                <strong class="text-danger">{{ $message }}</strong>
                                                            </span>
                                                        @enderror
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        {{ Form::label('order_points', __('Order Points'), ['class' => 'form-label']) }}
                                                        {{ Form::number('order_points', null, ['class' => 'form-control', 'placeholder' => 'Order Points']) }}
                                                        @error('order_points')
                                                            <span class="invalid-order_points" role="alert">
                                                                <strong class="text-danger">{{ $message }}</strong>
                                                            </span>
                                                        @enderror
                                                    </div>
                                                </div>
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <span>
                                                            {{ __('So this is the example of how customer reward points will be counted : (Checkout Order Price (Ex. 100) * Reward Point(Ex. 10) / (Order Price(Ex. 40)) = (Customer Total Points : 25)') }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item card">
                                    <h2 class="accordion-header" id="reward_setting">
                                        <button class="accordion-button collapsed" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapseone_reward_setting"
                                            aria-expanded="false" aria-controls="collapseone_reward_setting">
                                            <div class="d-flex align-items-center">
                                                <label class="form-check-label" for="platformswt1"><b>{{__('Reward Setting')}}</b></label>
                                            </div>
                                        </button>
                                    </h2>

                                    <div id="collapseone_reward_setting" class="accordion-collapse collapse"
                                        aria-labelledby="reward_setting" data-bs-parent="#accordionExample">
                                        <div class="accordion-body p-4">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        {{ Form::label('reward_points', __('Reward Points'), ['class' => 'form-label']) }}
                                                        {{ Form::number('reward_points', null, ['class' => 'form-control', 'placeholder' => 'Reward Points']) }}
                                                        @error('reward_points')
                                                            <span class="invalid-reward_points" role="alert">
                                                                <strong class="text-danger">{{ $message }}</strong>
                                                            </span>
                                                        @enderror
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        {{ Form::label('reward_point_price', __('Reward Price'), ['class' => 'form-label']) }}
                                                        {{ Form::number('reward_point_price', null, ['class' => 'form-control', 'placeholder' => 'Reward Price']) }}
                                                        @error('reward_point_price')
                                                            <span class="invalid-reward_point_price" role="alert">
                                                                <strong class="text-danger">{{ $message }}</strong>
                                                            </span>
                                                        @enderror
                                                    </div>
                                                </div>
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <span>
                                                            {{ __('So this is the example of how customer reward price will be counted : (Customer Total Points (Ex. 100) * Reward Price(Ex. 10) / (Reward Points(Ex. 50)) = (Discount Reward Price : 20)') }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item card">
                                    <h2 class="accordion-header" id="checkout_reward_setting">
                                        <button class="accordion-button collapsed" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapseone_checkout_reward_setting"
                                            aria-expanded="false" aria-controls="collapseone_checkout_reward_setting">
                                            <div class="d-flex align-items-center">
                                                <label class="form-check-label" for="platformswt1"><b>{{__('Checkout Reward Setting')}}</b></label>
                                            </div>
                                        </button>
                                    </h2>

                                    <div id="collapseone_checkout_reward_setting" class="accordion-collapse collapse"
                                        aria-labelledby="checkout_reward_setting" data-bs-parent="#accordionExample">
                                        <div class="accordion-body p-4">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        {{ Form::label('checkout_points', __('Checkout Points'), ['class' => 'form-label']) }}
                                                        {{ Form::number('checkout_points', null, ['class' => 'form-control', 'placeholder' => 'Checkout Points']) }}
                                                        @error('checkout_points')
                                                            <span class="invalid-checkout_points" role="alert">
                                                                <strong class="text-danger">{{ $message }}</strong>
                                                            </span>
                                                        @enderror
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        {{ Form::label('checkout_price', __('Checkout Price'), ['class' => 'form-label']) }}
                                                        {{ Form::number('checkout_price', null, ['class' => 'form-control', 'placeholder' => 'Checkout Price']) }}
                                                        @error('checkout_price')
                                                            <span class="invalid-checkout_price" role="alert">
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
        if ($('.club_point_enabled').is(':checked')) {

            $('.point-settings').removeClass('disabledCookie');
        } else {

            $('.point-settings').addClass('disabledCookie');
        }

        $('.club_point_enabled').on('change', function() {
            if ($('.club_point_enabled').is(':checked')) {

                $('.point-settings').removeClass('disabledCookie');
            } else {

                $('.point-settings').addClass('disabledCookie');
            }
        });
    </script>
@endpush
