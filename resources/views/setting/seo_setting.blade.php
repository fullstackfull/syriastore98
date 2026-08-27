<div class="card" id="seo_customize">
    <div class="card-header d-flex justify-content-between align-items-center gap-3">
        <h5 class=""> {{ __('SEO Settings') }} </h5>

        @if ($plan && $plan->enable_chatgpt == 'on')
            <a href="#" class="btn btn-primary btn-badge float-end ai-btn" data-size="lg"
                data-ajax-popup-over="true" data-url="{{ route('generate', ['meta']) }}"
                data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Generate') }}"
                data-title="{{ __('Generate Content With AI') }}">
                <i class="fas fa-robot"></i> {{ __('Generate with AI') }}
            </a>
        @endif

    </div>
    {{ Form::model($setting, ['route' => 'meta-seo.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
    <div class="card-body p-4">

        <input type="hidden" name="app_setting_tab" value="pills-seo-tab">
        <div class="row mt-2">
            <div class="col-md-6">
                <div class="form-group">
                    {{ Form::label('metatitle', __('Meta Title'), ['class' => 'form-label']) }}
                    {!! Form::text('metatitle', null, [
                        'class' => 'form-control',
                        'placeholder' => __('Meta Title'),
                    ]) !!}
                    @error('meta_keyword')
                        <span class="invalid-about" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group">
                    {{ Form::label('metakeyword', __('Meta Keywords'), ['class' => 'form-label']) }}
                    {!! Form::textarea('metakeyword', null, [
                        'class' => 'form-control',
                        'rows' => 3,
                        'placeholder' => __('Meta Keyword'),
                    ]) !!}
                    @error('meta_keywords')
                        <span class="invalid-about" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    {{ Form::label('metadesc', __('Meta Description'), ['class' => 'form-label']) }}
                    {!! Form::textarea('metadesc', null, [
                        'class' => 'form-control',
                        'rows' => 3,
                        'placeholder' => __('Meta Description'),
                    ]) !!}

                    @error('meta_description')
                        <span class="invalid-about" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
            </div>

            <div class="col-md-6">
                <div class="form-group pt-0">
                    <div class=" setting-card">
                        <label for="" class="form-label">{{ __('Meta Image') }}</label>
                        <div class="seo-image">
                            <a href="{{ asset(!empty($setting['metaimage']) ? $setting['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png') }}"
                                target="_blank" class="d-block">
                                <img id="meta_image" alt="your image"
                                    src="{{ asset(!empty($setting['metaimage']) ? $setting['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png') }}"
                                    width="100%" class="img_setting">
                            </a>
                        </div>
                        <div class="choose-files mt-3">
                            <label for="metaimage">
                                <div class="btn-badge bg-primary full_logo"> <i
                                        class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                                </div>
                                <input type="file" class="form-control file"
                                    accept="image/png, image/gif, image/jpeg,image/jpg" id="metaimage"
                                    name="metaimage"
                                    onchange="document.getElementById('metaimage').src = window.URL.createObjectURL(this.files[0])"
                                    data-filename="full_logo">
                            </label>
                        </div>
                        @error('metaimage')
                            <div class="row">
                                <span class="invalid-logo" role="alert">
                                    <strong class="text-danger">{{ $message }}</strong>
                                </span>
                            </div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end flex-wrap ">
        <input type="submit" value="{{ __('Save Changes') }}" class="btn-badge btn-submit btn btn-primary">
    </div>
    {!! Form::close() !!}
</div>
<!-- end style customization -->
