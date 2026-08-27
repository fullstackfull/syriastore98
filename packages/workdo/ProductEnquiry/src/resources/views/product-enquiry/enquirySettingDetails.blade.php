<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-product-enquiry-tab') show active @endif" id="pills-product_enquiry" role="tabpanel"
    aria-labelledby="pills-product_enquiry-tab">
    <div id="product_enquiry_settings">
        <div class="card">
            {{ Form::model($currEmailTempLang, ['route' => ['update.enquiry.email.settings', $currEmailTempLang->parent_id], 'method' => 'PUT']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-product-enquiry-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> {{ __('Product Enquiry Email Setting') }} </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages gap-2">
                    <ul class="list-unstyled mb-0">
                        <li class="dropdown dash-h-item drp-language">
                            <a class="dash-head-link dropdown-toggle arrow-none me-0 bg-info rounded-1"
                                data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false"
                                aria-expanded="false" id="dropdownLanguage">
                                <span
                                    class="drp-text hide-mob customer-language">{{ Str::upper($currEmailTempLang->language) }}</span>
                                <i class="ti ti-chevron-down drp-arrow nocolor email_arrow"></i>
                            </a>
                            <div class="dropdown-menu dash-h-dropdown dropdown-menu-end" aria-labelledby="dropdownLanguage">
                                @foreach ($languages as $key => $lang)
                                <a href="{{ route('manage.enquiry.email.language', [$emailTemplate->id, $key]) }}"
                                    class="dropdown-item enquiry-language-option {{ $currEmailTempLang->language == $key ? 'text-primary' : '' }}"
                                    data-lang-key="{{ $key }}">{{ Str::upper($lang) }}</a>
                                @endforeach
                            </div>
                        </li>
                    </ul>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class=""
                            name="enquiry_email_enabled" id="enquiry_email_enabled"
                            {{ $emailTemplate->template->is_active == '1' ? 'checked="checked"' : '' }}>
                        <label class="custom-control-label" for="enquiry_email_enabled"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="col-xl-12">
                        <div class="row">
                            <div class="col-lg-12 col-md-12 col-sm-12">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="row text-xs">
                                            <p class="mb-1">{{ __('Product Name') }} : <span
                                                    class="pull-right text-primary">{product_name}</span></p>
                                            <p class="mb-1">{{ __('Product Query') }} : <span
                                                    class="pull-right text-primary">{product_query}</span></p>
                                            <p class="mb-1">{{ __('Product Answer') }} : <span
                                                    class="pull-right text-primary">{product_answer}</span></p>
                                            <p class="mb-1">{{ __('customer Name') }} : <span
                                                    class="pull-right text-primary">{customer_name}</span></p>
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
                                {{ Form::textarea('content', $currEmailTempLang->content, ['class' => 'summernote-simple customer-email-language', 'required' => 'required']) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap">
                {{ Form::hidden('language', null, ['class' => 'language-code']) }}
                {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-primary btn-badge']) }}
            </div>
            {{ Form::close() }}
        </div>
    </div>
</div>
@push('scripts')
    <script>
        $(document).ready(function() {
            $('.enquiry-language-option').on('click', function(event) {
                event.preventDefault();
                var url = $(this).attr('href');
                var langKey = $(this).data('lang-key');
                $.ajax({
                    url: url,
                    type: 'GET',
                    success: function(response) {
                        $('.customer-email-language').summernote('code', response.data
                            .currentEmailTemplateLang.content);
                        $('.customer-language').text(response.data.currentEmailTemplateLang
                            .language.toUpperCase());
                        $('.enquiry-language-option').removeClass('text-primary');
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
@endpush