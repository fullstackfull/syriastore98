<div class="card mb-3" id="badge-view-setting">

        <div class="d-flex  p-4 pb-0">
            <div class="pe-2">
                <input type="radio" class="btn-check" name="storage_setting" id="textbadge-outlined" autocomplete="off"
                    {{ isset($setting['storage_setting']) && $setting['storage_setting'] == 'textbadge' ? 'checked' : '' }}
                    value="textbadge" checked>
                <label class="btn btn-outline-primary btn-badge text-break" for="textbadge-outlined">{{ __('Text Badge') }}</label>
            </div>

            <div class="pe-2">
                <input type="radio" class="btn-check" name="storage_setting" id="imagebadge-outlined"
                    autocomplete="off"
                    {{ isset($setting['storage_setting']) && $setting['storage_setting'] == 'imagebadge' ? 'checked' : '' }}
                    value="imagebadge">
                <label class="btn btn-outline-primary btn-badge text-break" for="imagebadge-outlined">
                    {{ __('Image Badge') }}</label>
            </div>
        </div>

            {{-- text badge setting --}}
            <div
                class="textbadge-setting row {{ isset($setting['storage_setting']) && $setting['storage_setting'] == 'textbadge' ? ' ' : 'd-none' }}">
                <div id="textbadge-view-setting">
                    {{ Form::model($setting, ['route' => 'savebadgedata', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
                    <div class="card-header d-flex justify-content-between align-items-center gap-3">
                        <h5 class=""> {{ __('Badge Settings') }} </h5>
                        <div class="form-check form-switch d-inline-block">
                            <input type="checkbox" name="textbadge_enabled" class="form-check-input textbadge_enabled"
                                id="textbadge_enabled"
                                {{ isset($setting['storage_setting']) && $setting['storage_setting'] == 'textbadge' ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        @csrf
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <div class="form-group">
                                    {{ Form::label('textcolor', __('Text Color'), ['class' => 'form-label']) }}
                                    <input type="color" name="textcolor" id="textcolor" class="form-control"
                                        value="{{ isset($setting->textcolor) ? $setting->textcolor : '#000000' }}">
                                    @error('textcolor')
                                        <span class="invalid-textcolor" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                    @enderror
                                    <label for="textcolor"
                                        class="mt-2 small">{{ __('Select the text color of the badge') }}</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    {{ Form::label('bgcolor', __('Background Color'), ['class' => 'form-label']) }}
                                    <input type="color" name="bgcolor" id="bgcolor" class="form-control"
                                        value="{{ isset($setting->bgcolor) ? $setting->bgcolor : '#ffffff' }}">
                                    @error('bgcolor')
                                        <span class="invalid-bgcolor" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                    @enderror
                                    <label for="bgcolor"
                                        class="mt-2 small">{{ __('Select the Background color of the badge') }}</label>
                                </div>

                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    {{ Form::label('badgetxt', __('Badge Text'), ['class' => 'form-label']) }}
                                    <input type="text" name="badgetxt" id="badgetxt" class="form-control"
                                        value="{{ isset($setting->badgetxt) ? $setting->badgetxt : '' }}">
                                    @error('badgetxt')
                                        <span class="invalid-badgetxt" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                    @enderror
                                    <label for="badgetxt"
                                        class="mt-2 small">{{ __('Text Of The Badge (i.e New, Latest,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        Trending)') }}
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                {{ Form::label('position', __('Position'), ['class' => 'form-label']) }}
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <input type="number" name="position_top" id="position" class="form-control"
                                            value="{{ isset($setting->position_top) ? $setting->position_top : '' }}">
                                        <label for="position_top" class="mt-2 small">{{ __('Top') }}</label>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <input type="number" name="position_left" id="position" class="form-control"
                                            value="{{ isset($setting->position_left) ? $setting->position_left : '' }}">
                                        <label for="position_left" class="mt-2 small">{{ __('Left') }}</label>
                                    </div>
                                </div>
                                @error('position')
                                    <span class="invalid-position" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                {{ Form::label('padding', __('Padding (pixel)'), ['class' => 'form-label']) }}
                                <div class="row">
                                    <div class="col-md-3">
                                        <input type="number" name="padding_top" id="padding" class="form-control"
                                            value="{{ isset($setting->padding_top) ? $setting->padding_top : '' }}">
                                        <label for="padding_top" class="mt-2 small">{{ __('Top') }}</label>

                                    </div>
                                    <div class="col-md-3 form-group">
                                        <input type="number" name="padding_bottom" id="padding"
                                            class="form-control"
                                            value="{{ isset($setting->padding_bottom) ? $setting->padding_bottom : '' }}">
                                        <label for="padding_bottom" class="mt-2 small">{{ __('Bottom') }}</label>
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <input type="number" name="padding_left" id="padding"
                                            class="form-control"
                                            value="{{ isset($setting->padding_left) ? $setting->padding_left : '' }}">
                                        <label for="padding_left" class="mt-2 small">{{ __('Left') }}</label>

                                    </div>
                                    <div class="col-md-3 form-group">
                                        <input type="number" name="padding_right" id="padding"
                                            class="form-control"
                                            value="{{ isset($setting->padding_right) ? $setting->padding_right : '' }}">
                                        <label for="padding_right" class="mt-2 small">{{ __('Right') }}</label>
                                    </div>
                                </div>
                                @error('padding')
                                    <span class="invalid-padding" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                {{ Form::label('radius', __('Radius (pixel)'), ['class' => 'form-label']) }}
                                <div class="row">
                                    <div class="col-md-3 form-group">
                                        <input type="number" name="radius_top_left" id="radius"
                                            class="form-control"
                                            value="{{ isset($setting->radius_top_left) ? $setting->radius_top_left : '' }}">
                                        <label for="radius_top_left" class="mt-2 small">{{ __('Top Left') }}</label>
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <input type="number" name="radius_top_right" id="radius"
                                            class="form-control"
                                            value="{{ isset($setting->radius_top_right) ? $setting->radius_top_right : '' }}">
                                        <label for="radius_top_right"
                                            class="mt-2 small">{{ __('Top Right') }}</label>
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <input type="number" name="radius_bottom_left" id="radius"
                                            class="form-control"
                                            value="{{ isset($setting->radius_bottom_left) ? $setting->radius_bottom_left : '' }}">
                                        <label for="radius_bottom_left"
                                            class="mt-2 small">{{ __('Bottom Left') }}</label>
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <input type="number" name="radius_bottom_right" id="radius"
                                            class="form-control"
                                            value="{{ isset($setting->radius_bottom_right) ? $setting->radius_bottom_right : '' }}">
                                        <label for="radius_bottom_right"
                                            class="mt-2 small">{{ __('Bottom Right') }}</label>
                                    </div>
                                </div>
                                @error('radius')
                                    <span class="invalid-radius" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    {{ Form::label('opacity', __('Badge Opacity'), ['class' => 'form-label']) }}
                                    <input type="number" name="opacity" id="opacity" class="form-control"
                                        min="0" max="1" step="0.1"
                                        value="{{ isset($setting->opacity) ? $setting->opacity : '' }}">
                                    @error('opacity')
                                        <span class="invalid-opacity" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                    @enderror
                                    <label for="opacity" class="mt-2 small">{{ __('Set Opacity') }}</label>
                                </div>
                            </div>


                            <div class="col-md-6 align-items-center">
                                {{ Form::label('view', __('Quick View Of Badge'), ['class' => 'form-label']) }}

                                <div class="pro-preview-box">
                                    <div class="textbadge-preview">
                                        <div class="badge"
                                            style="background-color: {{ isset($setting->bgcolor) ? $setting->bgcolor : '#000000' }}; color: {{ isset($setting->txtcolor) ? $setting->txtcolor : '#ffffff' }};">
                                            <span>{{ isset($setting->badgetxt) ? $setting->badgetxt : 'test' }}</span>
                                        </div>
                                    </div>
                                    <h4 class="product-view">{{ __('Product View') }}</h4> <!-- Centering the text -->
                                </div>
                            </div>

                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-end flex-wrap ">
                        <input type="submit" value="{{ __('Save Changes') }}"
                            class="btn-submit btn btn-primary btn-badge">
                    </div>
                    {!! Form::close() !!}
                </div>
            </div>


            {{-- Image Badge setting --}}

            <div
                class="imagebadge-setting row {{ isset($setting['storage_setting']) && $setting['storage_setting'] == 'imagebadge' ? ' ' : 'd-none' }}">
                <div id="imagebadge-view-setting">
                    {!! Form::open(['route' => 'saveimagebadgedata', 'method' => 'POST', 'enctype' => 'multipart/form-data']) !!}
                    <div class="card-header d-flex justify-content-between align-items-center gap-3">
                        <h5 class=""> {{ __('Image Badge Settings') }} </h5>
                        <div class="form-check form-switch d-inline-block">
                            <input type="checkbox" name="imagebadge_enabled"
                                class="form-check-input imagebadge_enabled" id="imagebadge_enabled"
                                {{ isset($setting['storage_setting']) && $setting['storage_setting'] == 'imagebadge' ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        @csrf
                        <div class="row mt-2">
                            <div class="col-md-6">
                                {{ Form::label('img_position', __('Position'), ['class' => 'form-label']) }}
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <input type="number" name="img_position_top" id="img_position_top"
                                            class="form-control"
                                            value="{{ isset($existingImageBadge->position_top) ? $existingImageBadge->position_top : '' }}">
                                        <label for="img_position_top" class="mt-2 small">{{ __('Top') }}</label>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <input type="number" name="img_position_left" id="img_position_left"
                                            class="form-control"
                                            value="{{ isset($existingImageBadge->position_left) ? $existingImageBadge->position_left : '' }}">
                                        <label for="img_position_left" class="mt-2 small">{{ __('Left') }}</label>
                                    </div>
                                </div>
                                @error('img_position')
                                    <span class="invalid-position" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                {{ Form::label('img_padding', __('Padding (pixel)'), ['class' => 'form-label']) }}
                                <div class="row">
                                    <div class="col-md-3">
                                        <input type="number" name="img_padding_top" id="img_padding_top"
                                            class="form-control"
                                            value="{{ isset($existingImageBadge->padding_top) ? $existingImageBadge->padding_top : '' }}">
                                        <label for="img_padding_top" class="mt-2 small">{{ __('Top') }}</label>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" name="img_padding_bottom" id="img_padding_bottom"
                                            class="form-control"
                                            value="{{ isset($existingImageBadge->padding_bottom) ? $existingImageBadge->padding_bottom : '' }}">
                                        <label for="img_padding_bottom"
                                            class="mt-2 small">{{ __('Bottom') }}</label>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" name="img_padding_left" id="img_padding_left"
                                            class="form-control"
                                            value="{{ isset($existingImageBadge->padding_left) ? $existingImageBadge->padding_left : '' }}">
                                        <label for="img_padding_left" class="mt-2 small">{{ __('Left') }}</label>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" name="img_padding_right" id="img_padding_right"
                                            class="form-control"
                                            value="{{ isset($existingImageBadge->padding_right) ? $existingImageBadge->padding_right : '' }}">
                                        <label for="img_padding_right" class="mt-2 small">{{ __('Right') }}</label>
                                    </div>
                                </div>
                                @error('img_padding')
                                    <span class="invalid-padding" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                {{ Form::label('img_radius', __('Radius (pixel)'), ['class' => 'form-label']) }}
                                <div class="row">
                                    <div class="col-md-3">
                                        <input type="number" name="img_radius_top_left" id="img_radius_top_left"
                                            class="form-control"
                                            value="{{ isset($existingImageBadge->radius_top_left) ? $existingImageBadge->radius_top_left : '' }}">
                                        <label for="img_radius_top_left"
                                            class="mt-2 small">{{ __('Top Left') }}</label>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" name="img_radius_top_right" id="img_radius_top_right"
                                            class="form-control"
                                            value="{{ isset($existingImageBadge->radius_top_right) ? $existingImageBadge->radius_top_right : '' }}">
                                        <label for="img_radius_top_right"
                                            class="mt-2 small">{{ __('Top Right') }}</label>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" name="img_radius_bottom_left"
                                            id="img_radius_bottom_left" class="form-control"
                                            value="{{ isset($existingImageBadge->radius_bottom_left) ? $existingImageBadge->radius_bottom_left : '' }}">
                                        <label for="img_radius_bottom_left"
                                            class="mt-2 small">{{ __('Bottom Left') }}</label>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" name="img_radius_bottom_right"
                                            id="img_radius_bottom_right" class="form-control"
                                            value="{{ isset($existingImageBadge->radius_bottom_right) ? $existingImageBadge->radius_bottom_right : '' }}">
                                        <label for="img_radius_bottom_right"
                                            class="mt-2 small">{{ __('Bottom Right') }}</label>
                                    </div>
                                </div>
                                @error('img_radius')
                                    <span class="invalid-radius" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    {{ Form::label('img_opacity', __('Image Badge Opacity'), ['class' => 'form-label']) }}
                                    <input type="number" name="img_opacity" id="img_opacity" class="form-control"
                                        min="0" max="1" step="0.1"
                                        value="{{ isset($existingImageBadge->opacity) ? $existingImageBadge->opacity : '' }}">
                                    @error('img_opacity')
                                        <span class="invalid-opacity" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                    @enderror
                                    <label for="img_opacity" class="mt-2 small">{{ __('Set Opacity') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    {{ Form::label('img_width', __('Width'), ['class' => 'form-label']) }}
                                    <input type="number" name="img_width" id="img_width" class="form-control"
                                        value="{{ isset($existingImageBadge->width) ? $existingImageBadge->width : '' }}">
                                    @error('img_width')
                                        <span class="invalid-opacity" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                    @enderror
                                    <label for="img_width" class="mt-2 small">{{ __('Width') }}</label>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <div id="uploaded-image" class="image-preview">
                                        {{ Form::label('bgimage', __('Preview Your Image'), ['class' => 'form-label']) }}
                                        @if (isset($existingImageBadge->filepath))
                                            <img id="img-bg-preview"
                                                src="{{ get_file($existingImageBadge->filepath) }}"
                                                alt="Uploaded Background Image" style="max-width: 300px;"
                                                class="form-control">
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                {{ Form::label('img_view', __('Quick View Of Badge'), ['class' => 'form-label']) }}
                                @if ($existingImageBadge != null)
                                    <div class="pro-preview-box">
                                        <div class="img-badge-preview">
                                            <div class="img-badge" style="
                                                top: {{ isset($existingImageBadge->position_top) ? $existingImageBadge->position_top : '0' }}px;
                                                left: {{ isset($existingImageBadge->position_left) ? $existingImageBadge->position_left : '0' }}px;
                                                padding: {{ isset($existingImageBadge->padding_top) ? $existingImageBadge->padding_top : '0' }}px {{ isset($existingImageBadge->padding_right) ? $existingImageBadge->padding_right : '0' }}px {{ isset($existingImageBadge->padding_bottom) ? $existingImageBadge->padding_bottom : '0' }}px {{ isset($existingImageBadge->padding_left) ? $existingImageBadge->padding_left : '0' }}px;
                                                opacity: {{ isset($existingImageBadge->opacity) ? $existingImageBadge->opacity : '1' }};
                                            ">
                                                <span>
                                                    <img id="quick-view-img"
                                                        src="{{ get_file($existingImageBadge->filepath) }}"
                                                        alt="Uploaded Background Image"
                                                        style="
                                                            border-radius: {{ isset($existingImageBadge->radius_top_left) ? $existingImageBadge->radius_top_left : '0' }}px {{ isset($existingImageBadge->radius_top_right) ? $existingImageBadge->radius_top_right : '0' }}px {{ isset($existingImageBadge->radius_bottom_right) ? $existingImageBadge->radius_bottom_right : '0' }}px {{ isset($existingImageBadge->radius_bottom_left) ? $existingImageBadge->radius_bottom_left : '0' }}px;
                                                            width: {{ isset($existingImageBadge->width) && $existingImageBadge->width ? $existingImageBadge->width.'px' : 'auto' }};
                                                        "
                                                        class="img-bg-preview form-control">
                                                </span>
                                            </div>
                                        </div>
                                        <h4 class="product-view">{{ __('Product View') }}</h4>
                                    </div>
                                @endif
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    {{ Form::label('bgimage', __('Background Image'), ['class' => 'form-label']) }}
                                    <input type="file" name="bgimage" id="bgimage" class="form-control"
                                        onchange="previewImage(event)">
                                    @error('bgimage')
                                        <span class="invalid-bgimage" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                    @enderror
                                    <label for="bgimage"
                                        class="mt-2 small">{{ __('Set Background Image') }}</label>
                                </div>
                            </div>


                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-end flex-wrap ">
                        <input type="submit" value="{{ __('Save Changes') }}"
                            class="btn-submit btn btn-primary btn-badge">
                    </div>
                    {!! Form::close() !!}
                </div>
            </div>
</div>
