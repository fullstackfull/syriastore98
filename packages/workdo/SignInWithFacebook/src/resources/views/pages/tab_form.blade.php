<div id="facebook_setting">
    <div class="card">
        {{ Form::open(['route' => 'facebooklogin.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
        <div class="card-header d-flex justify-content-between ">
            <h5> {{ __('Facebook Setting') }} </h5>
            {!! Form::hidden('facebook_setting_enabled', 'off') !!}
            <div class="form-check form-switch d-inline-block">
                {!! Form::checkbox(
                    'facebook_setting_enabled',
                    'on',
                    isset($setting['facebook_setting_enabled']) && $setting['facebook_setting_enabled'] === 'on',
                    [
                        'class' => 'form-check-input',
                        'id' => 'facebook_setting_enabled',
                    ],
                ) !!}
                <label class="custom-control-label form-control-label" for="facebook_setting_enabled"></label>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row">
                <div class="col-lg-6 form-group">
                    {!! Form::label('Facebook Client Id', __('Facebook Client Id'), ['class' => 'form-label']) !!}
                    {!! Form::text(
                        'facebook_client_id',
                        !empty($setting['facebook_client_id']) ? $setting['facebook_client_id'] : '',
                        [
                            'class' => 'form-control',
                            'placeholder' => 'Facebook Client Id',
                        ],
                    ) !!}
                </div>
                <div class="col-lg-6 form-group">
                    {!! Form::label('Facebook Client Secret', __('Facebook Client Secret'), ['class' => 'form-label']) !!}
                    {!! Form::text(
                        'facebook_client_secret',
                        !empty($setting['facebook_client_secret']) ? $setting['facebook_client_secret'] : '',
                        [
                            'class' => 'form-control',
                            'placeholder' => 'Facebook Client Secret',
                        ],
                    ) !!}
                </div>
                <div class="col-lg-12 form-group">
                    {!! Form::label('Facebook Callback', __('Facebook Callback URL'), ['class' => 'form-label']) !!}
                    <div class="input-group">
                        <input type="text" value="{{ !empty($callback_url) ? $callback_url : '' }}"
                            id="myInput" class="form-control d-inline-block" name="facebook_callback"
                            aria-label="Recipient's username" aria-describedby="button-addon2"
                            readonly>
                        <div class="input-group-append">
                            <button class="btn btn-outline-primary btn-badge" type="button"
                                onclick="myFunction()" id="button-addon2"><i
                                    class="far fa-copy"></i>
                                {{ __('Copy Link') }}</button>
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
