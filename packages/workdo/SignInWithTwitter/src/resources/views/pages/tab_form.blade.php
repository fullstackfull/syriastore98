<div id="twitter_setting">
    <div class="card">
        {{ Form::open(['route' => 'twitter.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
        <div class="card-header d-flex justify-content-between ">
            <h5> {{ __('Twitter Setting') }} </h5>
            {!! Form::hidden('twitter_setting_enabled', 'off') !!}
            <input type="hidden" name="setting_tab" value="twitter_signin_setting">
            <div class="form-check form-switch d-inline-block">
                {!! Form::checkbox('twitter_setting_enabled', 'on', isset($setting['twitter_setting_enabled']) && $setting['twitter_setting_enabled'] === 'on', [
                    'class' => 'form-check-input',
                    'id' => 'twitter_setting_enabled',
                ]) !!}
                <label class="custom-control-label form-control-label"
                    for="twitter_setting_enabled"></label>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row">
                <div class="col-lg-6 form-group">
                    {!! Form::label('twitter client id', __('Twitter Client Id'), ['class' => 'form-label']) !!}
                    {!! Form::text('twitter_client_id', !empty($setting['twitter_client_id']) ? $setting['twitter_client_id'] : '', [
                        'class' => 'form-control',
                        'placeholder' => 'Twitter Client Id',
                    ]) !!}
                </div>
                <div class="col-lg-6 form-group">
                   {!! Form::label('twitter client secret', __('Twitter Client Secret'), ['class' => 'form-label']) !!}
                   {!! Form::text('twitter_client_secret', !empty($setting['twitter_client_secret']) ? $setting['twitter_client_secret'] : '', [
                       'class' => 'form-control',
                       'placeholder' => 'Twitter Client Secret',
                   ]) !!}
               </div>
               <div class="col-lg-12 form-group">
                   {!! Form::label('twitter callback', __('Twitter Callback URL'), ['class' => 'form-label']) !!}
                   <div class="input-group">
                        <input type="text" value="{{ !empty($callback_url) ? $callback_url : '' }}"
                            id="myInput" class="form-control d-inline-block" name="twitter_callback"
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
