<div class="accordion-item card mb-3">
    <h2 class="accordion-header" id="{{ $gateway['id'] }}">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
            data-bs-target="#collapseone_{{ $gateway['id'] }}" aria-expanded="false"
            aria-controls="collapseone_{{ $gateway['id'] }}">
            <span class="d-flex align-items-center w-100 justify-content-between">
                <div>
                    <i class="ti ti-credit-card me-2"></i>{{ __($gateway['name']) }}
                </div>
                <div class="form-check form-switch d-inline-block me-2">
                    {!! Form::checkbox(
                        $gateway['enable_key'],
                        'on',
                        isset($setting[$gateway['enable_key']]) && $setting[$gateway['enable_key']] === 'on',
                        [
                            'class' => 'form-check-input',
                            'id' => $gateway['enable_key'],
                        ],
                    ) !!}
                    <label class="custom-control-label form-control-label"
                        for="{{ $gateway['enable_key'] }}"></label>
                </div>
            </span>
        </button>
    </h2>
    <div id="collapseone_{{ $gateway['id'] }}" class="accordion-collapse collapse"
        aria-labelledby="{{ $gateway['id'] }}" data-bs-parent="#payment-gateways">
        <div class="accordion-body">
            <div class="row">
                <div class="col-md-12 mb-2">
                    <small class="text-muted">
                        <i class="ti ti-info-circle me-1"></i>
                        {{ __('This configuration will be used for product checkout processing.') }}
                    </small>
                </div>

                @if(isset($gateway['mode_options']))
                <div class="col-lg-12 pb-3">
                    <h6 class="mb-2">{{ __($gateway['name'].' Mode') }}</h6>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach($gateway['mode_options'] as $mode => $label)
                        <div class="mode-selector">
                            <div class="p-3 {{ (isset($setting[$gateway['id'].'_mode']) && $setting[$gateway['id'].'_mode'] == $mode) || (!isset($setting[$gateway['id'].'_mode']) && $mode == 'sandbox') ? 'border-primary' : '' }}">
                                <div class="form-check">
                                    <input type="radio" name="{{ $gateway['id'] }}_mode" value="{{ $mode }}"
                                        class="form-check-input" id="{{ $gateway['id'] }}_{{ $mode }}"
                                        {{ (isset($setting[$gateway['id'].'_mode']) && $setting[$gateway['id'].'_mode'] == $mode) || (!isset($setting[$gateway['id'].'_mode']) && $mode == 'sandbox') ? 'checked="checked"' : '' }}>
                                    <label class="form-check-label text-dark" for="{{ $gateway['id'] }}_{{ $mode }}">
                                        {{ __($label) }}
                                    </label>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <div class="row g-3">
                    @if(isset($gateway['fields']))
                    <div class="col-md-{{ count($gateway['fields']) <= 2 ? '8' : '7' }}">
                        <div class="row g-3">
                            @foreach($gateway['fields'] as $field)
                            <div class="col-md-{{ count($gateway['fields']) <= 2 ? '12' : '6' }}">
                                <div class="form-group">
                                    <label for="{{ $field['key'] }}" class="form-label">{{ __($field['label']) }}</label>
                                    <input class="form-control" placeholder="{{ __('Enter').' '.__($field['label']) }}"
                                        name="{{ $field['key'] }}" id="{{ $field['key'] }}" type="text" 
                                        value="{{ $setting[$field['key']] ?? '' }}">
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-md-{{ count($gateway['fields']) <= 2 ? '4' : '5' }}">
                        <div class="form-group">
                            <label class="form-label d-block">{{ __('Gateway Image') }}</label>
                            <div class="border p-3 rounded bg-light text-center position-relative">
                                <label for="{{ $gateway['image_key'] }}" class="btn btn-sm btn-primary position-absolute" style="top:5px; right:5px;">
                                    <i class="ti ti-upload"></i>
                                </label>
                                <input type="file" name="{{ $gateway['image_key'] }}" id="{{ $gateway['image_key'] }}" class="d-none">
                                <img alt="{{ __($gateway['name']).' '.__('Image') }}"
                                    src="{{ get_file($setting[$gateway['image_key']] ?? Storage::url($gateway['image_default'])) ?? asset(Storage::url($gateway['image_default'])) }}"
                                    class="img-fluid p-1" style="max-height: 100px; max-width: 100%;">
                            </div>
                        </div>
                    </div>
                    @else
                    <div class="col-md-8">
                        <div class="form-group">
                            <label for="{{ $gateway['description_key'] }}" class="form-label">{{ __('Description') }}</label>
                            {!! Form::textarea(
                                $gateway['description_key'], 
                                $setting[$gateway['description_key']] ?? $gateway['description_default'] ?? '', 
                                [
                                    'class' => 'form-control',
                                    'placeholder' => __('Enter Description'),
                                    'rows' => '4',
                                    'id' => $gateway['description_key'],
                                ]
                            ) !!}
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label d-block">{{ __('Gateway Image') }}</label>
                            <div class="border p-3 rounded bg-light text-center position-relative">
                                <label for="{{ $gateway['image_key'] }}" class="btn btn-sm btn-primary position-absolute" style="top:5px; right:5px;">
                                    <i class="ti ti-upload"></i>
                                </label>
                                <input type="file" name="{{ $gateway['image_key'] }}" id="{{ $gateway['image_key'] }}" class="d-none">
                                <img alt="{{ __($gateway['name']).' '.__('Image') }}"
                                    src="{{ get_file($setting[$gateway['image_key']] ?? Storage::url($gateway['image_default'])) ?? asset(Storage::url($gateway['image_default'])) }}"
                                    class="img-fluid p-1" style="max-height: 100px; max-width: 100%;">
                            </div>
                        </div>
                    </div>
                    @endif
                </div>

                @if(\Auth::user()->type == 'admin' && isset($gateway['description_key']) && isset($gateway['fields']))
                <div class="col-md-12 mt-3">
                    <div class="form-group">
                        <label for="{{ $gateway['description_key'] }}" class="form-label">{{ __('Description') }}</label>
                        {!! Form::textarea(
                            $gateway['description_key'], 
                            $setting[$gateway['description_key']] ?? '', 
                            [
                                'class' => 'form-control',
                                'placeholder' => __('Enter Description'),
                                'rows' => '3',
                                'id' => $gateway['description_key'],
                            ]
                        ) !!}
                        <small class="form-text text-muted">
                            {{ __('This description will be shown to customers during checkout.') }}
                        </small>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>