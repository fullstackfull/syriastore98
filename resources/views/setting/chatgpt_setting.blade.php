<div class="card" id="Chatgpt_Setting">
    {{ Form::model($setting, ['route' => 'chatgpt.setting', 'method' => 'post']) }}
    @csrf
    <div class="card-header">
        <div class="row">
            <div class="col-lg-10 col-md-10 col-sm-10">
                <h5>{{ __('Chat GPT Key Settings') }}</h5>
                <small class="text-muted">{{ __('Edit your key details') }}</small>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="row mt-2">
            <div class="form-group">
            <label for="chat_gpt_model" class="col-form-label">{{ __('Chat GPT Model Name') }}</label>                                            
                <select name="chat_gpt_model" id="chat_gpt_model" class="form-control" required>
                    @foreach(getAiModelName() as $groupLabel => $options)
                        @if(is_array($options))
                            <optgroup label="{{ $groupLabel }}">
                                @foreach($options as $key => $model)
                                    <option value="{{ $key }}" {{ isset($setting['chat_gpt_model']) && $setting['chat_gpt_model'] == $key ? 'selected' : '' }}>
                                        {{ $model }}
                                    </option>
                                @endforeach
                            </optgroup>                                               
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <div class="field_wrapper">
                    @if (isset($ai_key_settings) && count($ai_key_settings) > 0)
                        <?php $i = 1; ?>
                        @foreach ($ai_key_settings as $key_data)
                            <div class="d-flex gap-1 mb-4">
                                <input type="text" class="form-control" name="api_key[]"
                                    value="{{ $key_data->key }}" placeholder="{{__('Enter Chatgpt Key Here')}}"/>
                                @if ($i == 1)
                                    <a href="javascript:void(0);" class="btn-badge add_button btn btn-primary"
                                        title="Add field"><i class="ti ti-plus"></i></a>
                                @else
                                    <a href="javascript:void(0);" class="btn-badge remove_button btn btn-danger"><i
                                            class="ti ti-trash"></i></a>
                                @endif
                            </div>
                            <?php $i++; ?>
                        @endforeach
                    @else
                        <div class="d-flex gap-1 mb-4">
                            <input type="text" class="form-control" name="api_key[]" value="" placeholder="{{__('Enter Chatgpt Key Here')}}" />

                            <a href="javascript:void(0);" class="btn-badge add_button btn btn-primary" title="Add field"><i
                                    class="ti ti-plus"></i></a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end flex-wrap ">
        <input type="submit" value="{{ __('Save Changes') }}" class="btn-badge btn-submit btn btn-primary">
    </div>
    {{ Form::close() }}
</div>
