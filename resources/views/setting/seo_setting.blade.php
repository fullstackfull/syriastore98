{{--
    SEO settings, on the design system.
    =============================================================================================

    THREE OF THE FOUR `@error` BLOCKS COULD NEVER FIRE. They named `meta_keyword`,
    `meta_keywords` and `meta_description` while the fields are `metatitle`, `metakeyword` and
    `metadesc` — so a validation failure on any of the three rendered nothing at all and the
    merchant was shown a form that had silently refused to save. Only `metaimage` matched.

    THE IMAGE PREVIEW NEVER UPDATED. The file input's `onchange` did
    `document.getElementById('metaimage').src = …` — `metaimage` is the FILE INPUT's own id, and
    assigning `src` to an `<input type="file">` does nothing. The preview `<img>` is
    `meta_image`, one underscore apart.

    The Meta Image label was `<label for="">` — an empty `for`, associated with nothing.
--}}
<div class="ss-card" id="seo_customize">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('SEO Settings') }}</h2>
        </div>

        @if ($plan && $plan->enable_chatgpt == 'on')
            <a href="#" class="ss-btn ss-btn--secondary ai-btn" data-size="lg"
               data-ajax-popup-over="true" data-url="{{ route('generate', ['meta']) }}"
               data-title="{{ __('Generate Content With AI') }}">
                <i class="fas fa-robot" aria-hidden="true"></i> {{ __('Generate with AI') }}
            </a>
        @endif
    </div>

    {{ Form::model($setting, ['route' => 'meta-seo.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
    <div class="ss-card__body">
        <input type="hidden" name="app_setting_tab" value="pills-seo-tab">

        <div class="row">
            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="metatitle">{{ __('Meta Title') }}</label>
                    <input type="text" class="ss-input" id="metatitle" name="metatitle"
                           value="{{ $setting['metatitle'] ?? '' }}"
                           placeholder="{{ __('Meta Title') }}"
                           @error('metatitle') aria-describedby="metatitle-error" aria-invalid="true" @enderror>
                    @error('metatitle')
                        <p class="ss-field__error" id="metatitle-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="ss-field">
                    <label class="ss-label" for="metakeyword">{{ __('Meta Keywords') }}</label>
                    <textarea class="ss-input" id="metakeyword" name="metakeyword" rows="3"
                              placeholder="{{ __('Meta Keyword') }}"
                              @error('metakeyword') aria-describedby="metakeyword-error" aria-invalid="true" @enderror>{{ $setting['metakeyword'] ?? '' }}</textarea>
                    @error('metakeyword')
                        <p class="ss-field__error" id="metakeyword-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="ss-field">
                    <label class="ss-label" for="metadesc">{{ __('Meta Description') }}</label>
                    <textarea class="ss-input" id="metadesc" name="metadesc" rows="3"
                              placeholder="{{ __('Meta Description') }}"
                              @error('metadesc') aria-describedby="metadesc-error" aria-invalid="true" @enderror>{{ $setting['metadesc'] ?? '' }}</textarea>
                    @error('metadesc')
                        <p class="ss-field__error" id="metadesc-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="metaimage">{{ __('Meta Image') }}</label>

                    @php
                        $ssMetaImage = asset(! empty($setting['metaimage'])
                            ? $setting['metaimage']
                            : 'storage/uploads/ecommercego-saas-preview.png');
                    @endphp

                    <a href="{{ $ssMetaImage }}" target="_blank" rel="noopener noreferrer" class="d-block">
                        {{-- `alt=""` and `aria-hidden`: the link around it is what carries the
                             meaning, and "your image" named nothing. --}}
                        <img id="meta_image" src="{{ $ssMetaImage }}" alt="" aria-hidden="true"
                             width="100%" class="img_setting">
                    </a>

                    <input type="file" class="ss-input file mt-3"
                           accept="image/png, image/gif, image/jpeg, image/jpg"
                           id="metaimage" name="metaimage" data-filename="full_logo"
                           onchange="if (this.files[0]) { document.getElementById('meta_image').src = window.URL.createObjectURL(this.files[0]); }"
                           @error('metaimage') aria-describedby="metaimage-error" aria-invalid="true" @enderror>

                    @error('metaimage')
                        <p class="ss-field__error" id="metaimage-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
