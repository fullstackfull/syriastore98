<div id="bitbucket_setting">
    <div class="card">
        {{ Form::open(['route' => 'bitbucket.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
        <div class="card-header d-flex justify-content-between ">
            <h5> {{ __('Bitbucket Setting') }} </h5>
            {!! Form::hidden('bitbucket_setting_enabled', 'off') !!}
            <input type="hidden" name="setting_tab" value="bitbucket_signin_setting">
            <div class="form-check form-switch d-inline-block">
                {!! Form::checkbox(
                    'bitbucket_setting_enabled',
                    'on',
                    isset($setting['bitbucket_setting_enabled']) && $setting['bitbucket_setting_enabled'] === 'on',
                    [
                        'class' => 'form-check-input',
                        'id' => 'bitbucket_setting_enabled',
                    ],
                ) !!}
                <label class="custom-control-label form-control-label" for="bitbucket_setting_enabled"></label>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row">
                <div class="col-lg-6 form-group">
                    {!! Form::label('Bitbucket Client Id', __('Bitbucket Client Id'), ['class' => 'form-label']) !!}
                    {!! Form::text(
                        'bitbucket_client_id',
                        !empty($setting['bitbucket_client_id']) ? $setting['bitbucket_client_id'] : '',
                        [
                            'class' => 'form-control',
                            'placeholder' => 'Bitbucket Client Id',
                        ],
                    ) !!}
                </div>
                <div class="col-lg-6 form-group">
                    {!! Form::label('Bitbucket Client Secret', __('Bitbucket Client Secret'), ['class' => 'form-label']) !!}
                    {!! Form::text(
                        'bitbucket_client_secret',
                        !empty($setting['bitbucket_client_secret']) ? $setting['bitbucket_client_secret'] : '',
                        [
                            'class' => 'form-control',
                            'placeholder' => 'Bitbucket Client Secret',
                        ],
                    ) !!}
                </div>
                <div class="col-lg-12 form-group">
                    {!! Form::label('Bitbucket Callback URL', __('Bitbucket Callback URL'), ['class' => 'form-label']) !!}
                    <div class="input-group">
                        <input type="text" value="{{ !empty($callback_url) ? $callback_url : '' }}"
                            id="myInput" class="form-control d-inline-block" name="bitbucket_callback"
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
