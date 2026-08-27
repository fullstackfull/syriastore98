<div class="row">
    <div class="col-md-12">
        {{-- Register --}}
        @if ($couponemail_setting == '0')
            <div class="row align-items-center">
                <div class="col-6">
                    {{-- <div class="form-group">
                        {{ Form::label('is_active', __('Enable Coupon Sending'), ['class' => 'form-label']) }}
                        <div>
                            {{ Form::hidden('is_active', 'off') }}
                            <input type="checkbox" class="form-check-input" name="is_active"
                                {{ isset($couponEmail['is_active']) && $couponEmail['is_active'] == 'on' ? 'checked' : '' }}>
                        </div>
                    </div> --}}
                    {!! Form::hidden('is_active', 'off') !!}
                    <div class="form-check form-switch d-inline-block">
                        <label class="custom-control-label form-control-label" for="is_active">{{__('Enable Coupon Sending')}}</label>
                        {!! Form::checkbox(
                            'is_active',
                            'on',
                            isset($couponEmail['is_active']) && $couponEmail['is_active'] === 'on',
                            [
                                'class' => 'form-check-input',
                                'id' => 'is_active',
                            ],
                        ) !!}
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group">
                        <label for="selected_coupon" class="form-label">{{ __('Selected Coupon') }}</label>
                        {{ Form::select('selected_coupon', $selectCoupons, isset($couponEmail['selected_coupon']) ? $couponEmail['selected_coupon'] : null, ['class' => 'form-control choices', 'searchEnabled' => 'true', 'placeholder' => 'Select Coupon']) }}
                    </div>
                </div>
            </div>
            <div class="form-group">
                {{ Form::label('email_subject', __('Email Subject'), ['class' => 'form-label']) }}
                {{ Form::text('email_subject', isset($couponEmail['email_subject']) ? $couponEmail['email_subject'] : null, ['class' => 'form-control font-style', 'required' => 'required']) }}
            </div>
            <div class="form-group">
                {{ Form::label('email_content', __('Email Content'), ['class' => 'form-label']) }}
                {{ Form::textarea('email_content', isset($couponEmail['email_content']) ? $couponEmail['email_content'] : null, ['class' => 'summernote-simple form-control']) }}
            </div>
        @elseif($couponemail_setting == '1')
            <div class="row align-items-center">
                <div class="col-6">
                    {{-- <div class="form-group">
                        {{ Form::label('first_is_active', __('Enable Coupon Sending'), ['class' => 'form-label']) }}
                        <div>
                            {{ Form::hidden('first_is_active', 'off') }}
                            <input type="checkbox" class="form-check-input" name="first_is_active"
                                {{ isset($couponEmail['first_is_active']) && $couponEmail['first_is_active'] == 'on' ? 'checked' : '' }}>
                        </div>
                    </div> --}}
                    {!! Form::hidden('first_is_active', 'off') !!}
                    <div class="form-check form-switch d-inline-block">
                        <label class="custom-control-label form-control-label" for="first_is_active">{{__('Enable Coupon Sending')}}</label>
                        {!! Form::checkbox(
                            'first_is_active',
                            'on',
                            isset($couponEmail['first_is_active']) && $couponEmail['first_is_active'] === 'on',
                            [
                                'class' => 'form-check-input',
                                'id' => 'first_is_active',
                            ],
                        ) !!}
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group">
                        <label for="first_selected_coupon" class="form-label">{{ __('Selected Coupon') }}</label>
                        {{ Form::select('first_selected_coupon', $selectCoupons, isset($couponEmail['first_selected_coupon']) ? $couponEmail['first_selected_coupon'] : null, ['class' => 'form-control choices', 'searchEnabled' => 'true', 'placeholder' => 'Select Coupon']) }}
                    </div>
                </div>
            </div>
            <div class="form-group">
                {{ Form::label('first_email_subject', __('Email Subject'), ['class' => 'form-label']) }}
                {{ Form::text('first_email_subject', isset($couponEmail['first_email_subject']) ? $couponEmail['first_email_subject'] : null, ['class' => 'form-control font-style', 'required' => 'required']) }}
            </div>
            <div class="form-group">
                {{ Form::label('first_email_content', __('Email Content'), ['class' => 'form-label']) }}
                {{ Form::textarea('first_email_content', isset($couponEmail['first_email_content']) ? $couponEmail['first_email_content'] : null, ['class' => 'summernote-simple form-control']) }}
            </div>
        @elseif($couponemail_setting == '2')
            <div class="row align-items-center">
                <div class="col-6">
                    {{-- <div class="form-group">
                        {{ Form::label('order_is_active', __('Enable Coupon Sending'), ['class' => 'form-label']) }}
                        <div>
                            {{ Form::hidden('order_is_active', 'off') }}
                            <input type="checkbox" class="form-check-input" name="order_is_active"
                                {{ isset($couponEmail['order_is_active']) && $couponEmail['order_is_active'] == 'on' ? 'checked' : '' }}>
                        </div>
                    </div> --}}
                    {!! Form::hidden('order_is_active', 'off') !!}
                    <div class="form-check form-switch d-inline-block">
                        <label class="custom-control-label form-control-label" for="order_is_active">{{__('Enable Coupon Sending')}}</label>
                        {!! Form::checkbox(
                            'order_is_active',
                            'on',
                            isset($couponEmail['order_is_active']) && $couponEmail['order_is_active'] === 'on',
                            [
                                'class' => 'form-check-input',
                                'id' => 'order_is_active',
                            ],
                        ) !!}
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group">
                        <div class="form-group">
                            {{ Form::label('order_order_thresholds', __('Order Thresholds'), ['class' => 'form-label']) }}
                            {{ Form::text('order_order_thresholds', isset($couponEmail['order_order_thresholds']) ? $couponEmail['order_order_thresholds'] : null, ['class' => 'form-control font-style', 'required' => 'required']) }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                {{ Form::label('order_email_subject', __('Email Subject'), ['class' => 'form-label']) }}
                {{ Form::text('order_email_subject', isset($couponEmail['order_email_subject']) ? $couponEmail['order_email_subject'] : null, ['class' => 'form-control font-style', 'required' => 'required']) }}
            </div>
            <div class="form-group">
                {{ Form::label('order_email_content', __('Email Content'), ['class' => 'form-label']) }}
                {{ Form::textarea('order_email_content', isset($couponEmail['order_email_content']) ? $couponEmail['order_email_content'] : null, ['class' => 'summernote-simple form-control']) }}
            </div>
        @endif
    </div>
</div>

<script src="{{ asset('js/custom.js') }}{{ '?' . time() }}"></script>
