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
                {{--
                    F117 — the repeater, where "blank keeps it" needs an identity of its own.
                    =================================================================================

                    Every other converted panel has a field NAME to fall back on. This one posts
                    `api_key[]`, and the stored VALUE was the identity: the old save did
                    `updateOrCreate(['key' => $posted])` and then
                    `whereNotIn('key', $posted)->delete()`. A masked box posts nothing that
                    identifies a row, so under that save it could only misfire — silently
                    reconciling nothing when any box is blank (an empty input arrives as NULL,
                    and `not in (NULL)` matches no row), and deleting every other operator's keys
                    when they are all filled, because the delete had no owner predicate.

                    So each saved row now carries its own row id in the field name —
                    `api_key[e<id>]` — and a new row keeps the bare `api_key[]` the add-button JS
                    clones. PHP merges the two: string keys for the rows that already exist,
                    integer keys for the ones just typed. Removing a row from the form still
                    deletes it, exactly as before, because its id stops being posted.
                --}}
                <div class="field_wrapper">
                    @php $ai_key_rows = (isset($ai_key_settings) && count($ai_key_settings) > 0) ? $ai_key_settings : null; @endphp
                    @if ($ai_key_rows)
                        @foreach ($ai_key_rows as $key_data)
                            <div class="d-flex gap-1 mb-4 align-items-start">
                                <div class="flex-grow-1">
                                    <x-ss.secret-field
                                        :name="'api_key[e' . $key_data->id . ']'"
                                        :label="__('Chat GPT Key') . ' ' . $loop->iteration"
                                        :stored="$key_data->key"
                                        :hint="__('Remove the row and save to delete this key.')" />
                                </div>
                                @if ($loop->first)
                                    <a href="javascript:void(0);" class="btn-badge add_button btn btn-primary"
                                        title="{{ __('Add field') }}"><i class="ti ti-plus" aria-hidden="true"></i><span class="ss-visually-hidden">{{ __('Add another key') }}</span></a>
                                @else
                                    <a href="javascript:void(0);" class="btn-badge remove_button btn btn-danger"
                                        title="{{ __('Remove field') }}"><i class="ti ti-trash" aria-hidden="true"></i><span class="ss-visually-hidden">{{ __('Remove this key') }}</span></a>
                                @endif
                            </div>
                        @endforeach
                    @else
                        <div class="d-flex gap-1 mb-4 align-items-start">
                            <div class="flex-grow-1">
                                <x-ss.secret-field
                                    name="api_key[]"
                                    :label="__('Chat GPT Key')"
                                    :stored="null"
                                    :placeholder="__('Enter Chatgpt Key Here')" />
                            </div>
                            <a href="javascript:void(0);" class="btn-badge add_button btn btn-primary"
                                title="{{ __('Add field') }}"><i class="ti ti-plus" aria-hidden="true"></i><span class="ss-visually-hidden">{{ __('Add another key') }}</span></a>
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
