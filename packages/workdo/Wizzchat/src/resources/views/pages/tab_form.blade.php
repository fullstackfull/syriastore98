<div id="wizzchat-setting">
    <div class="card mb-3">
        <form method="POST" action="{{ route('wizzchat.settings') }}" accept-charset="UTF-8">
            @csrf
            <div class="card-header">
                <div class="row">
                    <div class="col-12">
                        <div class="d-flex justify-content-between">
                            <div class="">
                                <h5 class=""> {{ __('Wizzchat Setting') }} </h5>
                                <small>{{ __('Copy widget code from wizzchat website and add here to set up Wizzchat live Support setting for customer.') }} <a href="https://app.wizzchat.com/" target="_blank">{{ __('click here') }}</a></small>
                            </div>
                            {!! Form::hidden('is_wizzchat_enabled', 'off') !!}
                            <div class="form-check form-switch d-inline-block">
                                {!! Form::checkbox(
                                    'is_wizzchat_enabled',
                                    'on',
                                    isset($setting['is_wizzchat_enabled']) && $setting['is_wizzchat_enabled'] == 'on',
                                    [
                                        'class' => 'form-check-input',
                                        'id' => 'is_wizzchat_enabled',
                                    ],
                                ) !!}
                                <label class="custom-control-label form-control-label"
                                    for="is_wizzchat_enabled"></label>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-12">

                        {{ Form::textarea('wizzchat_messenger', isset($setting['wizzchat_messenger']) ? $setting['wizzchat_messenger'] : null, ['class' => 'form-control ', 'required' => 'required', 'placeholder' => '<script type="text/javascript"> </script>', 'rows' => 6]) }}
                        @error('wizzchat_messenger')
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
