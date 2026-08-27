<div class="tab-pane fade @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-productcatelog-tab')) show active @endif" id="pills-productcatelog" role="tabpanel" aria-labelledby="pills-productcatelog-tab">
    <div id="catelog_request_email">
        <div class="col-md-12">
            {{ Form::model($currEmailTempLang, ['route' => ['updateProductCatelogEmail.setting', $currEmailTempLang->parent_id], 'method' => 'POST']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-productcatelog-tab">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex gap-3 align-items-center flex-wrap justify-content-between">
                        <h5 class="m-0"> {{ __('Catelog Request Email Template') }} </h5>
                        <div class="gap-2 switch-width text-end d-flex">
                            <div class="">
                                <ul class="list-unstyled mb-0">
                                    <li class="form-control dropdown dash-h-item bg-info drp-language"
                                        style="width: 75px;">
                                        <a class="dash-head-link dropdown-toggle arrow-none me-0 rounded-1"
                                            data-bs-toggle="dropdown" href="#" role="button"
                                            aria-haspopup="false" aria-expanded="false" id="dropdownLanguage">
                                            <span
                                                class="drp-text hide-mob">{{ Str::upper($currEmailTempLang->language) }}</span>
                                            <i class="ti ti-chevron-down drp-arrow nocolor email_arrow"></i>
                                        </a>
                                        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end"
                                            aria-labelledby="dropdownLanguage">
                                            @foreach ($languages as $key => $lang)
                                                <a href="{{ route('manage.adminemail.language', [$emailTemplate->id, $key]) }}"
                                                    class="dropdown-item {{ $currEmailTempLang->language == $key ? 'text-primary' : '' }}">{{ Str::upper($lang) }}</a>
                                            @endforeach
                                        </div>
                                    </li>
                                </ul>
                            </div>
                            <div class="">
                                <div class="form-group mb-0">
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary"
                                            class="" name="is_active" id="is_active"
                                            {{ $emailTemplate->template->is_active == '1' ? 'checked="checked"' : '' }}>
                                        <label class="custom-control-label" for="is_active"></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-12 col-md-12 col-sm-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="row text-xs">
                                        <p class="mb-1">{{ __('App Name') }} : <span
                                                class="pull-right text-primary">{app_name}</span></p>
                                        <p class="mb-1">{{ __('App URL') }} : <span
                                                class="pull-right text-primary">{app_url}</span></p>
                                        <p class="mb-1">{{ __('PDF View') }} : <span
                                                class="pull-right text-primary">{pdf_view}</span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            {{ Form::label('subject', __('Subject'), ['class' => 'col-form-label text-dark']) }}
                            {{ Form::text('subject', null, ['class' => 'form-control font-style', 'required' => 'required']) }}
                        </div>

                        <div class="col-6">
                            {{ Form::label('from', __('From'), ['class' => 'col-form-label text-dark']) }}
                            {{ Form::text('from', $emailTemplate->from, ['class' => 'form-control font-style', 'required' => 'required']) }}
                        </div>

                        <div class="col-12">
                            {{ Form::label('content', __('Email Message'), ['class' => 'col-form-label text-dark']) }}
                            {{ Form::textarea('content', $currEmailTempLang->content, ['class' => 'summernote-simple', 'required' => 'required']) }}
                        </div>
                    </div><br>
                </div>
                <div class="card-footer text-end">
                    {{ Form::hidden('language', null) }}
                    {{ Form::hidden('user_email_template_id', $emailTemplate->template->id ?? 0) }}
                    {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-primary btn-badge']) }}
                </div>
            </div>
            {{ Form::close() }}
        </div>
    </div>
</div>
