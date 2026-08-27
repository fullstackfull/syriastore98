<div class="tab-pane fade" id="pills-duo2FA" role="tabpanel" aria-labelledby="pills-duo2FA-tab">
    <div id="duo2fa_setting">
        <div class="card">
            {{ Form::model($store, ['route' => ['duo2fa.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class=""> {{ __('Duo2FA Setting') }} </h5>
                    <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    {!! Form::hidden('duo_setting_enabled', 'off') !!}
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                            data-onstyle="primary" name="duo_setting_enabled" id="duo_setting_enabled"
                            {{ isset($store->duo_setting_enabled) && $store->duo_setting_enabled == 'on' ? 'checked="checked"' : '' }}>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row mt-2">
                        <div class="form-group col-md-6">
                            {{-- <div class="form-group"> --}}
                                {!! Form::label('duo_api_host_name', __('Duo API Host Name'), ['class' => 'form-label']) !!}
                                {!! Form::text(
                                    'duo_api_host_name',
                                    !empty($store->duo_api_host_name) ? $store->duo_api_host_name : '',
                                    [
                                        'class' => 'form-control',
                                        'placeholder' => 'Enter Here...',
                                    ],
                                ) !!}
                                @error('duo_api_host_name')
                                    <span class="invalid-quick_button_title" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                            {{-- </div> --}}
                        </div>
                        <div class="form-group col-md-6">
                            {{-- <div class="form-group"> --}}
                                {!! Form::label('duo_secret_key', __('Duo Secret Key'), ['class' => 'form-label']) !!}
                                {!! Form::text(
                                    'duo_secret_key',
                                    !empty($store->duo_secret_key) ? $store->duo_secret_key : '',
                                    ['class' => 'form-control', 'placeholder' => 'Enter Here...'],
                                ) !!}
                                @error('duo_secret_key')
                                    <span class="invalid-quick_button_title" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                            {{-- </div> --}}
                        </div>
                        <div class="form-group col-md-6">
                            {{-- <div class="form-group"> --}}
                            {!! Form::label('duo_integration_key', __('Duo Integration Key'), ['class' => 'form-label']) !!}
                            {!! Form::text(
                                'duo_integration_key',
                                !empty($store->duo_integration_key) ? $store->duo_integration_key : '',
                                ['class' => 'form-control', 'placeholder' => 'Enter Here...'],
                            ) !!}
                                @error('duo_integration_key')
                                    <span class="invalid-quick_button_title" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                            {{-- </div> --}}
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
