    <!-- Coupon Email Setting -->
    <div id="coupon-settings">
        <div class="card">
            {{ Form::open(['route' => 'couponemail.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
            <div class="card-header d-flex justify-content-between ">
                <h5> {{ __('Coupon Email Settings') }} </h5>
                {!! Form::hidden('couponemail_setting_enabled', 'off') !!}
                <div class="form-check form-switch d-inline-block">
                    {!! Form::checkbox(
                        'couponemail_setting_enabled',
                        'on',
                        isset($couponEmails['couponemail_setting_enabled']) && $couponEmails['couponemail_setting_enabled'] === 'on',
                        [
                            'class' => 'form-check-input ',
                            'id' => 'couponemail_setting_enabled',
                        ],
                    ) !!}
                    <label class="custom-control-label form-control-label" for="couponemail_setting_enabled"></label>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row">
                    <div class="col-lg-6 form-group">
                        {{ Form::label('couponemail_setting', __('Select Coupon Email'), ['class' => ' col-form-label']) }}
                        {{ Form::select('couponemail_setting', $couponemail, isset($couponEmails['couponemail_setting']) ? $couponEmails['couponemail_setting'] : '', ['id' => 'couponemail_setting', 'class' => 'form-control choices', 'searchEnabled' => 'true']) }}
                    </div>
                </div>
                <div class="row">
                    <div class="col-12" id="getcouponemailfields" class="getcouponemailfields">
                    </div>
                </div>

            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
            </div>
            {!! Form::close() !!}
        </div>
    </div>
