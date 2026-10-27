{{--
    Chat GPT keys, on the design system.
    =============================================================================================

    The PLATFORM OPERATOR's own OpenAI keys, not a merchant's: `ApikeySetiings` has no tenant
    column. Both the read (`settingForm`) and the save (`ChatgptSettings`) refuse anyone whose
    `users.type` is not `super admin`, and the listing is scoped to `created_by` so one operator
    never sees or deletes another's rows.

    THE REPEATER'S JS CONTRACT LIVES IN `index.blade.php`, NOT HERE: `.field_wrapper` is the
    container it appends to, `.add_button` and `.remove_button` are its handlers, and
    `$(this).parent('div').remove()` means the remove button's PARENT must be the whole row. All
    three are kept exactly, and the row below is still one `<div>` holding field and button.
--}}
<div class="ss-card" id="Chatgpt_Setting">
    {{ Form::model($setting, ['route' => 'chatgpt.setting', 'method' => 'post']) }}
    @csrf
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Chat GPT Key Settings') }}</h2>
            <p class="ss-page-header__subtitle">{{ __('Edit your key details') }}</p>
        </div>
    </div>
    <div class="ss-card__body">
        <div class="row">
            <div class="col-lg-6">
                <div class="ss-field">
            <label for="chat_gpt_model" class="ss-label">{{ __('Chat GPT Model Name') }}</label>
                <select name="chat_gpt_model" id="chat_gpt_model" class="ss-select" required>
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
            </div>
            <div class="col-12">
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
                                    <a href="javascript:void(0);" class="ss-btn ss-btn--primary ss-btn--icon add_button"
                                        title="{{ __('Add field') }}"><i class="ti ti-plus" aria-hidden="true"></i><span class="ss-visually-hidden">{{ __('Add another key') }}</span></a>
                                @else
                                    <a href="javascript:void(0);" class="ss-btn ss-btn--danger ss-btn--icon remove_button"
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
                            <a href="javascript:void(0);" class="ss-btn ss-btn--primary ss-btn--icon add_button"
                                title="{{ __('Add field') }}"><i class="ti ti-plus" aria-hidden="true"></i><span class="ss-visually-hidden">{{ __('Add another key') }}</span></a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {{ Form::close() }}
</div>
