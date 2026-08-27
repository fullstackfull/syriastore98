<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-lottery-tab') show active @endif" id="pills-lottery" role="tabpanel"
    aria-labelledby="pills-lottery-tab">
    <div class="card">
        {{ Form::model($setting, ['route' => ['update.store.lottery.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
        <input type="hidden" name="app_setting_tab" value="pills-lottery-tab">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class=""> {{ __('Lottery Prizes Settings') }} </h5>
            <div class="d-flex flex-wrap justify-content-lg-end drp-languages mb-2">
                <div class="custom-control custom-switch">
                    <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="" name="enable_lottery"
                        id="enable_lottery" {{ $setting['enable_lottery'] ?? '' == 'on' ? 'checked="checked"' : '' }}>
                    <label class="custom-control-label" for="enable_lottery"></label>
                </div>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row mt-2">
                <div class="row col-md-12">
                    <div class="col-md-3">
                        <div class="form-group">
                            {{ Form::label('max_ticket', __('Maximum Ticket'), ['class' => 'col-form-label']) }}
                            {{ Form::number('max_ticket', null, ['class' => 'form-control', 'id' => 'max_ticket', 'placeholder' => __('Enter Max Store'), 'required' => 'required']) }}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {{ Form::label('min_ticket', __('Minimum Ticket'), ['class' => 'col-form-label']) }}
                            {{ Form::number('min_ticket', null, ['class' => 'form-control', 'id' => 'min_ticket', 'placeholder' => __('Enter Max Products'), 'required' => 'required']) }}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {{ Form::label('max_ticket_per_user', __('Maximum Ticket Per User'), ['class' => 'col-form-label']) }}
                            {{ Form::number('max_ticket_per_user', null, ['class' => 'form-control', 'id' => 'max_ticket', 'placeholder' => __('Enter Max Products'), 'required' => 'required']) }}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {{ Form::label('number_of_winner', __('Number Of Winner'), ['class' => 'col-form-label']) }}
                            {{ Form::number('number_of_winner', null, ['class' => 'form-control', 'id' => 'number_of_winner', 'placeholder' => __('Enter Max Store'), 'required' => 'required']) }}
                        </div>
                    </div>
                </div>
                <div class="row col-md-12">
                    <div class="col-md-3">
                        <div class="form-group">
                            {{ Form::label('ticket_price', __('Ticket Price'), ['class' => 'col-form-label']) }}
                            {{ Form::number('ticket_price', null, ['class' => 'form-control', 'id' => 'ticket_price', 'placeholder' => __('Enter Max User'), 'required' => 'required']) }}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group form-switch p-0">
                            {{ Form::label('refund_ticket', __('Refund Ticket Option'), ['class' => 'col-form-label']) }}<br>
                            <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                data-onstyle="primary" name="refund_ticket" id="refund_ticket"
                                {{ isset($setting['refund_ticket']) && $setting['refund_ticket'] == 'on' ? 'checked="checked"' : '' }}>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group form-switch p-0">
                            {{ Form::label('lottery_products', __('Select Products for Lottery'), ['class' => 'col-form-label']) }}
                            {!! Form::select('lottery_products[]', $product, $lottery_product, [
                            'class' => 'form-control lottery_products',
                            'id' => 'lottery_products',
                            'multiple',
                            'data-role' => 'tagsinput',
                            ]) !!}
                        </div>
                    </div>
                </div>
            </div>
            <h5 class="form-group">{{ __('Manage Lottery Time') }}</h5>
            <div class="row mt-2">
                <div class="row col-md-12">
                    <div class="form-group col-md-3">
                        {!! Form::label('', __('Start Date'), ['class' => 'form-label']) !!}
                        {!! Form::date('lottery_start_date', old('lottery_start_date'), ['class' => 'form-control', 'required']) !!}
                    </div>
                    <div class="form-group col-md-3">
                        {!! Form::label('', __('Start Time'), ['class' => 'form-label']) !!}
                        {!! Form::time('lottery_start_time', old('lottery_start_time'), ['class' => 'form-control', 'required']) !!}
                    </div>
                    <div class="form-group col-md-3">
                        {!! Form::label('', __('End Date'), ['class' => 'form-label']) !!}
                        {!! Form::date('lottery_end_date', old('lottery_end_date'), ['class' => 'form-control', 'required']) !!}
                    </div>
                    <div class="form-group col-md-3">
                        {!! Form::label('', __('End Time'), ['class' => 'form-label']) !!}
                        {!! Form::time('lottery_end_time', old('lottery_end_time'), ['class' => 'form-control', 'required']) !!}
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end flex-wrap ">
            <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary">
        </div>
        {{ Form::close() }}
    </div>
    <div id="lottery_settings">
        <div class="card">
            {{ Form::model($currEmailTempLang, ['route' => ['update.lottery.email.settings', $currEmailTempLang->parent_id], 'method' => 'PUT']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-lottery-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> {{ __('Lottery Prizes Email Setting') }} </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages mb-2">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class=""
                            name="lottery_email_enabled" id="lottery_email_enabled"
                            {{ $emailTemplate->template->is_active == '1' ? 'checked="checked"' : '' }}>
                        <label class="custom-control-label" for="lottery_email_enabled"></label>
                    </div>
                </div>
            </div>
            <div class="d-flex flex-wrap justify-content-lg-end drp-languages mb-4">
                <ul class="list-unstyled mb-0 m-2">
                    <li class="dropdown dash-h-item drp-language ">
                        <a class="dash-head-link dropdown-toggle arrow-none me-0 bg-info rounded-1"
                            data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false"
                            aria-expanded="false" id="dropdownLanguage">
                            <span
                                class="drp-text hide-mob lottery-language">{{ Str::upper($currEmailTempLang->language) }}</span>
                            <i class="ti ti-chevron-down drp-arrow nocolor email_arrow"></i>
                        </a>
                        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end" aria-labelledby="dropdownLanguage">
                            @foreach ($languages as $key => $lang)
                                <a href="{{ route('manage.lottery.email.language', [$emailTemplate->id, $key]) }}"
                                    class="dropdown-item lottery-language-option {{ $currEmailTempLang->language == $key ? 'text-primary' : '' }}"
                                    data-lang-key="{{ $key }}">{{ Str::upper($lang) }}
                                </a>
                            @endforeach
                        </div>
                    </li>
                </ul>
                <ul class="list-unstyled mb-0 m-2">
                    <li class="dropdown dash-h-item drp-language ">
                        <a class="dash-head-link dropdown-toggle arrow-none me-0 bg-warning rounded-1" data-bs-toggle="dropdown"
                            href="#" role="button" aria-haspopup="false" aria-expanded="false" id="dropdownLanguage">
                                <span class="drp-text hide-mob" id="link1">{{ __('Template: ') }}{{ $emailTemplate->name }}</span>
                            <i class="ti ti-chevron-down drp-arrow nocolor"></i>
                        </a>
                        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end"
                            aria-labelledby="dropdownLanguage">
                            @foreach ($EmailTemplates as $EmailTemplate)
                                <a href="#" class="dropdown-item mail-template {{ $EmailTemplate->name == $emailTemplate->name ? 'text-primary' : '' }}"
                                data-id="{{ $EmailTemplate->id }}">
                                {{ $EmailTemplate->name }}
                                </a>
                            @endforeach
                        </div>
                    </li>
                </ul>
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
                            <input type="hidden" name="template_id" value="{{$emailTemplate->id}}">
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
                                {{ Form::textarea('content', $currEmailTempLang->content, ['class' => 'summernote-simple lottery-email-language', 'required' => 'required']) }}
                            </div>

                            <div class="modal-footer">
                                {{ Form::hidden('language', null, ['class' => 'lottery-language-code']) }}
                                {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-primary']) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            {{ Form::close() }}
        </div>
    </div>
</div>
@push('scripts')
<script>
    $(document).ready(function() {
        $('.mail-template').on('click', function(event) {
            event.preventDefault();
            $('.mail-template').removeClass('text-primary');
        
            $(this).addClass('text-primary');

            var templateId = $(this).data('id');
            $.ajax({
                url: '{{ route('manage.lottery.mail', '') }}/' + templateId,
                method: 'GET',
                success: function(data) {
                    
                    $('input[name="subject"]').val(data.subject);
                    $('input[name="template_id"]').val(data.template_id);
                    $('#link1').text('{{ __('Template: ') }}' + data.subject);
                    $('input[name="from"]').val(data.from);
                    $('textarea[name="content"]').val(data.content);
                },
                error: function(xhr) {
                    console.error('Error fetching template data:', xhr);
                }
            });
        });


        $('.lottery-language-option').on('click', function(event) {
            event.preventDefault();

            var url = $(this).attr('href');
            var langKey = $(this).data('lang-key');

            $.ajax({
                url: url,
                type: 'GET',
                success: function(response) {
                    $('#loader').fadeOut();
                    $('.lottery-email-language').summernote('code', response.data.currentEmailTemplateLang.content);
                    $('.lottery-language').text(response.data.currentEmailTemplateLang.language.toUpperCase());
                    $('.lottery-language-option').removeClass('text-primary');
                    $('.lottery-language-code').val(response.data.currentEmailTemplateLang.language);
                    $('a[data-lang-key="' + langKey + '"]').addClass('text-primary');
                },
                error: function(xhr) {
                    console.error('Error changing language:', xhr);
                    $('#loader').fadeOut();
                }
            });
        });
    });
</script>
@endpush