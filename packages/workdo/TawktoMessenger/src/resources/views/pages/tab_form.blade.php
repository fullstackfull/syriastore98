<div id="tawk-to-setting">
    <div class="card mb-3">
        <form method="POST" action="{{ route('tawk-to.settings') }}" accept-charset="UTF-8">
            @csrf
            <div class="card-header">
                <div class="row">
                    <div class="col-12">
                        <div class="d-flex justify-content-between">
                            <div class="">
                                <h5 class=""> {{ __('Tawk.to Messenger Setting') }} </h5>
                                <small>{{ __('Copy widget code from Tawk.to website and add here to set up Tawk.to Messenger live Support setting for customer.') }}</small>
                            </div>
                            {!! Form::hidden('tawkto_setting_enabled', 'off') !!}
                            <div class="form-check form-switch d-inline-block">
                                {!! Form::checkbox(
                                    'tawkto_setting_enabled',
                                    'on',
                                    isset($setting['tawkto_setting_enabled']) && $setting['tawkto_setting_enabled'] == 'on',
                                    [
                                        'class' => 'form-check-input',
                                        'id' => 'tawkto_setting_enabled',
                                    ],
                                ) !!}
                                <label class="custom-control-label form-control-label"
                                    for="tawkto_setting_enabled"></label>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-12">

                        {{ Form::textarea('tawk_to_messenger', isset($setting['tawk_to_messenger']) ? $setting['tawk_to_messenger'] : null, ['class' => 'form-control ', 'required' => 'required', 'placeholder' => '<script type="text/javascript"> </script>', 'rows' => 6]) }}
                        @error('tawk_to_messenger')
                            <span class="invalid-about" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-primary btn-badge']) }}
            </div>
        </form>
    </div>
</div>
