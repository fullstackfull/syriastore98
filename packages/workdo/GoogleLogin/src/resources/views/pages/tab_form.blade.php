<div id="googlelogin_setting">
    <div class="card mb-3">
        <form method="POST" action="{{ route('googlelogin.settings') }}" enctype="multipart/form-data" accept-charset="UTF-8">
            @csrf
            <div class="card-header">
                <div class="row">
                    <div class="col-12">
                        <div class="d-flex justify-content-between">
                            <div class="">
                                <h5 class=""> {{ __('Google Login Setting') }} </h5>
                            </div>

                            {!! Form::hidden('google_login_setting_enabled', 'off') !!}
                            <div class="form-check form-switch d-inline-block">
                                {!! Form::checkbox(
                                    'google_login_setting_enabled',
                                    'on',
                                    isset($setting['google_login_setting_enabled']) && $setting['google_login_setting_enabled'] == 'on',
                                    [
                                        'class' => 'form-check-input',
                                        'id' => 'google_login_setting_enabled',
                                    ],
                                ) !!}
                                <label class="custom-control-label form-control-label"
                                    for="google_login_setting_enabled"></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row">
                    <div class="col-lg-6 form-group">
                        {!! Form::label('Google_client_id', __('Google Client ID'), ['class' => 'form-label']) !!}
                        {!! Form::text(
                            'Google_client_id',
                            !empty($setting['Google_client_id']) ? $setting['Google_client_id'] : '',
                            [
                                'class' => 'form-control',
                                'placeholder' => 'Google Client ID',
                            ],
                        ) !!}
                    </div>
                    <div class="col-lg-6 form-group">
                        {!! Form::label('Google_client_secret_key', __('Google Client Secret'), ['class' => 'form-label']) !!}
                        {!! Form::text(
                            'Google_client_secret_key',
                            !empty($setting['Google_client_secret_key']) ? $setting['Google_client_secret_key'] : '',
                            ['class' => 'form-control', 'placeholder' => 'Google Client Secret'],
                        ) !!}
                    </div>
                    <div class="col-lg-12 form-group">
                        {!! Form::label('Google_authorized_url', __('Google Callback URL'), ['class' => 'form-label']) !!}
                        <div class="input-group">
                            <input type="text" value="{{ !empty($callback_url) ? $callback_url : '' }}"
                                id="myInput" class="form-control d-inline-block" name="Google_authorized_url"
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
        </form>
    </div>
</div>
