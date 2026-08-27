<div class="card border mb-0 pb-0">
    <div class="d-flex justify-content-between align-items-center p-3">
        <h5 class=""> {{ __('Manage store configuration-wise') }} </h5>
        <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
            <div class="custom-control custom-switch">
                <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class=""
                    name="manage_store_setting_wise" id="manage_store_setting_wise"
                    {{ $partial_payment->manage_store_wise ?? '' == 'on' ? 'checked="checked"' : '' }}>
                <label class="custom-control-label" for="manage_store_setting_wise"></label>
            </div>
        </div>
    </div>
    @if (isset($partial_payment->manage_store_wise) && $partial_payment->manage_store_wise != 'on')
        <div class="card-body p-4 pb-0 partial-payment-form">
            <div class="row mt-2">
                <div class="row col-md-12">
                    <div class="form-group col-md-6">
                        <div class="custom-control form-switch p-0">
                            <label class="form-check-label form-label"
                                for="enable_deposit">{{ __('Enable Deposit') }}</label><br>
                            <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                data-onstyle="primary" name="enable_deposit" id="enable_deposit"
                                {{ isset($partial_payment->enable_deposit) && $partial_payment->enable_deposit == 'on' ? 'checked="checked"' : '' }}>
                        </div>
                    </div>
                    <div class="form-group col-md-6">
                        <div class="custom-control form-switch p-0">
                            <label class="form-check-label form-label"
                                for="force_deposit">{{ __('Force Deposit') }}</label><br>
                            <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                data-onstyle="primary" name="force_deposit" id="force_deposit"
                                {{ isset($partial_payment->force_deposit) && $partial_payment->force_deposit == 'on' ? 'checked="checked"' : '' }}>
                        </div>
                    </div>
                    <div class="form-group col-md-6">
                        {!! Form::label('category', __('Deposit Type'), ['class' => 'form-label']) !!}
                        {!! Form::select(
                            'deposit_type',
                            ['percentage' => 'Percentage', 'flat' => 'Flat'],
                            $partial_payment->deposit_type ?? null,
                            ['class' => 'form-control', 'id' => 'category'],
                        ) !!}
                    </div>
                    <div class="form-group col-md-6">
                        {{ Form::label('deposit_amount', __('Deposit Amount'), ['class' => 'form-label']) }}
                        {{ Form::number('deposit_amount', $partial_payment->deposit_amount ?? null, ['class' => 'form-control', 'required' => 'required', 'min' => '1', 'step' => '0.01']) }}
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="card-body p-4 partial-payment-form">
            <div class="row mt-2">
                <div class="row col-md-12">
                    <div class="form-group col-md-6">
                        <div class="custom-control form-switch p-0">
                            <label class="form-check-label form-label"
                                for="enable_deposit">{{ __('Enable Deposit') }}</label><br>
                            <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                data-onstyle="primary" name="enable_deposit" id="enable_deposit"
                                {{ isset($partial_payment->enable_deposit) && $partial_payment->enable_deposit == 'on' ? 'checked="checked"' : '' }}>
                        </div>
                    </div>
                    <div class="form-group col-md-6">
                        <div class="custom-control form-switch p-0">
                            <label class="form-check-label form-label"
                                for="force_deposit">{{ __('Force Deposit') }}</label><br>
                            <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                data-onstyle="primary" name="force_deposit" id="force_deposit"
                                {{ isset($partial_payment->force_deposit) && $partial_payment->force_deposit == 'on' ? 'checked="checked"' : '' }}>
                        </div>
                    </div>
                    <div class="form-group col-md-6">
                        {!! Form::label('category', __('Deposit Type'), ['class' => 'form-label']) !!}
                        {!! Form::select(
                            'deposit_type',
                            ['percentage' => 'Percentage', 'flat' => 'Flat'],
                            $partial_payment->deposit_type ?? null,
                            ['class' => 'form-control', 'id' => 'category'],
                        ) !!}
                    </div>
                    <div class="form-group col-md-6">
                        {{ Form::label('deposit_amount', __('Deposit Amount'), ['class' => 'form-label']) }}
                        {{ Form::number('deposit_amount', $partial_payment->deposit_amount ?? null, ['class' => 'form-control', 'required' => 'required', 'min' => '1', 'step' => '0.01']) }}
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@push('custom-script')
    <script src="{{ asset('js/jquery-ui.min.js') }}"></script>
    <script>
        $(document).ready(function() {
            $('#manage_store_setting_wise').change(function() {
                if ($(this).is(':checked')) {
                    $('.partial-payment-form').show();
                } else {
                    $('.partial-payment-form').hide();
                }
            });
            $('#manage_store_setting_wise').trigger('change');
        });
    </script>
@endpush
