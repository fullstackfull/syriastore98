<!-- slack Setting -->
<div id="slack-settings">
    <div class="card">
        {{ Form::open(['route' => 'slack.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
        <div class="card-header d-flex justify-content-between ">
            <div class="col-6">
                <h5> {{ __('Slack Setting') }} </h5>
            </div>
            <input type="hidden" name="setting_tab" value="slack_signin_setting">
            {!! Form::hidden('slack_setting_enabled', 'off') !!}
            <div class="form-check form-switch d-inline-block">
                {!! Form::checkbox('slack_setting_enabled', 'on', isset($setting['slack_setting_enabled']) && $setting['slack_setting_enabled'] === 'on', [
                'class' => 'form-check-input',
                'id' => 'slack_setting_enabled',
                ]) !!}
                <label class="custom-control-label form-control-label" for="slack_setting_enabled"></label>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row">
                <div class="col-lg-6">
                    <div class="form-group">
                        <label for="slack_client_id"
                            class="form-label">{{ __('Slack Client Id') }}</label>
                        <input class="form-control"
                            placeholder="Slack Client Id"
                            name="slack_client_id" type="text"
                            value="{{ !empty($setting['slack_client_id']) ? $setting['slack_client_id'] : '' }}">
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="form-group">
                        <label for="slack_secret"
                            class="form-label">{{ __('Slack Secret Key') }}</label>
                        <input class="form-control"
                            placeholder="Slack Secret Key"
                            name="slack_secret_key" type="text"
                            value="{{ !empty($setting['slack_secret_key']) ? $setting['slack_secret_key'] : '' }}">
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="form-group">
                        <label for="slack_secret"
                            class="form-label">{{ __('Slack Callback URL') }}</label>
                            <div class="input-group">
                                <input type="text" value="{{ !empty($callback_url) ? $callback_url : '' }}"
                                    id="myInput" class="form-control d-inline-block" name="slack_redirect_url"
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
        </div>
        <div class="card-footer d-flex justify-content-end flex-wrap ">
            <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
        </div>
        {!! Form::close() !!}
    </div>
</div>
