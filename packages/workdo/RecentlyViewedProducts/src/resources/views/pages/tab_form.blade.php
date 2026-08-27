<div class="card mb-3"  id="recently-view-setting">
    <form method="POST" action="{{ route('recently-view.settings') }}" accept-charset="UTF-8">
        @csrf
        <div class="card-header">
            <div class="row">
                <div class=" d-flex justify-content-between ">
                    <h5 class="h6 md-0">{{ __('Recently Viewed Setting') }}</h5>
                    {!! Form::hidden('enable_recently_view_product', 'off') !!}
                    <div class="form-check form-switch d-inline-block">
                        {!! Form::checkbox(
                            'enable_recently_view_product',
                            'on',
                            isset($setting['enable_recently_view_product']) && $setting['enable_recently_view_product'] == 'on',
                            [
                                'class' => 'form-check-input',
                                'id' => 'enable_recently_view_product',
                            ],
                        ) !!}
                        <label class="custom-control-label form-control-label"
                            for="enable_recently_view_product"></label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="form-group col-md-12">
                    {{ Form::label('Default Time', __('Default Time'), ['class' => 'form-label']) }}
                    {{ Form::text('recently_viewed_time', isset($setting['recently_viewed_time']) ? $setting['recently_viewed_time'] : '', ['class' => 'form-control', 'rows' => 3, 'placeholder' => __('Recently Viewed Time')]) }}
                    @error('recently_viewed_time')
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
