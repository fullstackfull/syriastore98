<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-partial_payment-tab') show active @endif" id="pills-partial_payment" role="tabpanel"
    aria-labelledby="pills-partial_payment-tab">
    <div id="partial_payment_settings">
        <div class="card">
            {{ Form::model($settings, ['route' => ['update.partial.payment.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data', 'id' => 'partial-payment-setting']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-partial_payment-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> {{ __('Partial Payment Settings') }} </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class=""
                            name="enable_partial_payment" id="enable_partial_payment"
                            {{ $settings['enable_partial_payment'] ?? '' == 'on' ? 'checked="checked"' : '' }}>
                        <label class="custom-control-label" for="enable_partial_payment"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="row col-md-12">
                        <div class="form-group col-md-4">
                            <div class="custom-control form-switch p-0">
                                <label class="form-check-label form-label"
                                    for="force_deposit">{{ __('Force Deposit') }}</label><br>
                                <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                    data-onstyle="primary" name="force_deposit" id="force_deposit"
                                    {{ isset($settings['force_deposit']) && $settings['force_deposit'] == 'on' ? 'checked="checked"' : '' }}>
                            </div>
                        </div>
                        <div class="form-group col-md-4">
                            {!! Form::label('', __('Deposit Type'), ['class' => 'form-label']) !!}
                            {!! Form::select('deposit_type', ['percentage' => 'Percentage', 'flat' => 'Flat'], null, [
                                'class' => 'form-control',
                                'id' => 'category',
                            ]) !!}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('deposit_amount', __('Deposit Amount'), ['class' => 'form-label']) }}
                            {{ Form::number('deposit_amount', null, ['class' => 'form-control', 'required' => 'required', 'min' => '1', 'step' => '0.01']) }}
                        </div>
                    </div>
                    <div class="row col-md-12">
                        <div class="form-group col-md-4">
                            {!! Form::label('', __('Deposit Selection'), ['class' => 'form-label']) !!}
                            {!! Form::select(
                                'deposit_selection',
                                ['pay_deposit' => 'Pay Deposit', 'full_amount' => 'Full Amount'],
                                old('deposit_selection'),
                                ['class' => 'form-control'],
                            ) !!}
                        </div>
                        <div class="form-group col-md-4">
                            {!! Form::label('', __('Manage Status'), ['class' => 'form-label']) !!}
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="on" id="set_order_status"
                                    name="set_order_status[]" {{ in_array('on', $options) ? 'checked' : '' }}>
                                <label class="form-check-label" for="set_order_status">
                                    {{ __('Adjust orders with partial payment status.') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <h5 class="form-group">{{ __('Manage Calculation') }}</h5>
                <div class="row mt-2">
                    <div class="row col-md-12">
                        <div class="form-group col-md-4">
                            {!! Form::label('', __('Manage Tax'), ['class' => 'form-label']) !!}
                            {!! Form::select(
                                'tax_option',
                                [
                                    'with_future_payment' => 'Count in Future Payment',
                                    'split_amount' => 'Divide in proportion to deposit values',
                                    'with_deposit' => 'Count in Deposit',
                                ],
                                old('tax_option'),
                                ['class' => 'form-control', 'id' => 'category'],
                            ) !!}
                        </div>
                        <div class="form-group col-md-4">
                            {!! Form::label('', __('Manage Shipping'), ['class' => 'form-label']) !!}
                            {!! Form::select(
                                'shipping_option',
                                [
                                    'with_future_payment' => 'Count in Future Payment',
                                    'split_amount' => 'Divide in proportion to deposit values',
                                    'with_deposit' => 'Count in Deposit',
                                ],
                                old('shipping_option'),
                                ['class' => 'form-control', 'id' => 'category'],
                            ) !!}
                        </div>
                        <div class="form-group col-md-4">
                            {!! Form::label('', __('Manage Coupon'), ['class' => 'form-label']) !!}
                            {!! Form::select(
                                'coupon_option',
                                [
                                    'with_future_payment' => 'Count in Future Payment',
                                    'split_amount' => 'Divide in proportion to deposit values',
                                    'with_deposit' => 'Count in Deposit',
                                ],
                                old('coupon_option'),
                                ['class' => 'form-control', 'id' => 'category'],
                            ) !!}
                        </div>
                    </div>
                </div>
                <h5 class="form-group">{{ __('Manage Payments') }}</h5>
                <div class="row mt-2">
                    <div class="row col-md-12">
                        <div class="form-group col-md-6">
                            {{ Form::label('deposite_payment_methods', __('Payment Methods for Deposit'), ['class' => 'col-form-label']) }}
                            {!! Form::select('deposite_payment_methods[]', $Payments, $deposit_enablePaymentdata, [
                                'class' => 'form-control deposite_payment_methods',
                                'id' => 'deposite_payment_methods',
                                'multiple',
                                'data-role' => 'tagsinput',
                            ]) !!}
                        </div>
                        <div class="form-group col-md-6">
                            {{ Form::label('partial_payment_methods', __('Payment Methods for Partial Payment'), ['class' => 'col-form-label']) }}
                            {!! Form::select('partial_payment_methods[]', $Payments, $enablePaymentdata, [
                                'class' => 'form-control partial_payment_methods',
                                'id' => 'partial_payment_methods',
                                'multiple',
                                'data-role' => 'tagsinput',
                            ]) !!}
                        </div>
                    </div>
                </div>
                <h5 class="form-group">{{ __('Remainder Mail Setting') }}</h5>
                <div class="row mt-2">
                    <div class="row col-md-12">
                        <div class="form-group col-md-6">
                            {!! Form::label('', __('Duration Type'), ['class' => 'form-label']) !!}
                            {!! Form::select(
                                'remainder_duration_type',
                                ['month' => 'Month', 'year' => 'Year'],
                                old('remainder_duration_type'),
                                ['class' => 'form-control', 'id' => 'category'],
                            ) !!}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('remainder_duration_period', __('Duration Period'), ['class' => 'form-label']) }}
                            {!! Form::number('remainder_duration_period', null, [
                                'class' => 'form-control',
                                'placeholder' => 'Please Enter Duration Period Number.',
                                'id' => 'remainder_duration_period',
                            ]) !!}
                            <div id="validation-message" class="text-danger" style="display: none;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-badge btn-primary">
            </div>
            {{ Form::close() }}
        </div>
        <div class="card">
            {{ Form::model($currEmailTempLang, ['route' => ['update.payment.email.settings', $currEmailTempLang->parent_id], 'method' => 'PUT']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-partial_payment-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> {{ __('Payment Remainder Settings') }} </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages gap-2">
                        <ul class="list-unstyled m-0">
                            <li class="dropdown dash-h-item drp-language ">
                                <a class="dash-head-link dropdown-toggle arrow-none me-0 bg-info rounded-1"
                                    data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false"
                                    aria-expanded="false" id="dropdownLanguage">
                                    <span
                                        class="drp-text hide-mob payment-language">{{ Str::upper($currEmailTempLang->language) }}</span>
                                    <i class="ti ti-chevron-down drp-arrow nocolor email_arrow"></i>
                                </a>
                                <div class="dropdown-menu dash-h-dropdown dropdown-menu-end"
                                    aria-labelledby="dropdownLanguage">
                                    @foreach ($languages as $key => $lang)
                                        <a href="{{ route('manage.payment.email.language', [$emailTemplate->id, $key]) }}"
                                            class="dropdown-item languages-option {{ $currEmailTempLang->language == $key ? 'text-primary' : '' }}"
                                            data-lang-key="{{ $key }}">{{ Str::upper($lang) }}</a>
                                    @endforeach
                                </div>
                            </li>
                        </ul>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class=""
                            name="customer_email_enabled" id="customer_email_enabled"
                            {{ $emailTemplate->template->is_active == '1' ? 'checked="checked"' : '' }}>
                        <label class="custom-control-label" for="customer_email_enabled"></label>
                    </div>
                </div>
            </div>
            {{-- <div class="d-flex flex-wrap justify-content-lg-end drp-languages mb-4">
                <ul class="list-unstyled mb-0 m-2">
                    <li class="dropdown dash-h-item drp-language ">
                        <a class="dash-head-link dropdown-toggle arrow-none me-0 bg-info rounded-1"
                            data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false"
                            aria-expanded="false" id="dropdownLanguage">
                            <span
                                class="drp-text hide-mob payment-language">{{ Str::upper($currEmailTempLang->language) }}</span>
                            <i class="ti ti-chevron-down drp-arrow nocolor email_arrow"></i>
                        </a>
                        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end"
                            aria-labelledby="dropdownLanguage">
                            @foreach ($languages as $key => $lang)
                                <a href="{{ route('manage.payment.email.language', [$emailTemplate->id, $key]) }}"
                                    class="dropdown-item languages-option {{ $currEmailTempLang->language == $key ? 'text-primary' : '' }}"
                                    data-lang-key="{{ $key }}">{{ Str::upper($lang) }}</a>
                            @endforeach
                        </div>
                    </li>
                </ul>
            </div> --}}
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="col-xl-12">
                        <div class="row">
                            <div class="col-lg-12 col-md-12 col-sm-12">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="row text-xs">
                                            <p class="mb-1">{{ __('Customer Name') }} : <span
                                                    class="pull-right text-primary">{customer_name}</span></p>
                                            <p class="mb-1">{{ __('Paid Amount') }} : <span
                                                    class="pull-right text-primary">{amount}</span></p>
                                            <p class="mb-1">{{ __('Date') }} : <span
                                                    class="pull-right text-primary">{date}</span></p>
                                            <p class="mb-1">{{ __('Pending Amount') }} : <span
                                                    class="pull-right text-primary">{remaining_amount}</span></p>
                                            <p class="mb-1">{{ __('Remaining Date') }} : <span
                                                    class="pull-right text-primary">{remaining_date}</span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group col-6">
                                {{ Form::label('subject', __('Subject'), ['class' => 'col-form-label text-dark']) }}
                                {{ Form::text('subject', null, ['class' => 'form-control font-style', 'required' => 'required']) }}
                            </div>
                            <div class="form-group col-md-6">
                                {{ Form::label('from', __('From'), ['class' => 'col-form-label text-dark']) }}
                                {{ Form::text('from', $emailTemplate->from, ['class' => 'form-control font-style', 'required' => 'required']) }}
                            </div>
                            <div class="form-group col-12">
                                {{ Form::label('content', __('Email Message'), ['class' => 'col-form-label text-dark']) }}
                                {{ Form::textarea('content', $currEmailTempLang->content, ['class' => 'summernote-simple payment-email-language', 'required' => 'required']) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                {{ Form::hidden('language', null, ['class' => 'language-code']) }}
                {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-badge btn-primary']) }}
            </div>
            {{ Form::close() }}
        </div>
    </div>
</div>

@push('scripts')
    <script>
        $(document).ready(function() {
            $('.languages-option').on('click', function(event) {
                event.preventDefault();

                var url = $(this).attr('href');
                var langKey = $(this).data('lang-key');

                $.ajax({
                    url: url,
                    type: 'GET',
                    success: function(response) {
                        $('.payment-email-language').summernote('code', response.data
                            .currentEmailTemplateLang.content);
                        $('.payment-language').text(response.data.currentEmailTemplateLang
                            .language.toUpperCase());
                        $('.languages-option').removeClass('text-primary');
                        $('.language-code').val(response.data.currentEmailTemplateLang
                            .language);
                        $('a[data-lang-key="' + langKey + '"]').addClass('text-primary');
                    },
                    error: function(xhr) {
                        console.error('Error changing language:', xhr);
                    }
                });
            });
        });
    </script>
    <script>
        $(document).ready(function() {
            $('#remainder_duration_period').on('input', function() {
                var value = $(this).val();
                if (value > 12) {
                    $('#validation-message').text('Duration period must be at least 12.').show();
                } else {
                    $('#validation-message').hide();
                }
            });
        });
    </script>
@endpush
