<!-- store-locate Setting -->
<div id="store-locate-settings">
    <div class="card">
        {{ Form::model($settings, ['route' => ['update.locator.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
        <div class="card-header d-flex justify-content-between ">
            <h5> {{ __('Store Locator Settings') }} </h5>
            {!! Form::hidden('store_locator_enabled', 'off') !!}
            <div class="form-check form-switch d-inline-block">
                {!! Form::checkbox(
                    'store_locator_enabled',
                    'on',
                    isset($settings['store_locator_enabled']) && $settings['store_locator_enabled'] == 'on',
                    [
                        'class' => 'form-check-input',
                        'id' => 'store_locator_enabled',
                    ],
                ) !!}
                <label class="custom-control-label form-control-label" for="store_locator_enabled"></label>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row">
                <div class="col-lg-6 form-group">
                    <label class="col-form-label">{{ __('API Key') }}</label> <br>
                    <input class="form-control fleet_key" placeholder="{{ __('API Key') }}" name="store_locator_api_key"
                        type="text"
                        value="{{ isset($settings['store_locator_api_key']) ? $settings['store_locator_api_key'] : '' }}">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end flex-wrap ">
            <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
        </div>
        {!! Form::close() !!}
    </div>
</div>
