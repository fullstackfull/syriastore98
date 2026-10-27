{{--
    Storage, on the design system.
    =============================================================================================

    THIS TAB WAS A HARD FATAL ON EVERY STORE THAT HAD NEVER SAVED IT. The local block read

        explode(',', isset($setting['local_storage_validation'])
            ? $setting['local_storage_validation'] : [])

    and the else branch hands `explode()` an ARRAY, which under PHP 8 is a `TypeError`, not a
    notice. It sits inside a `@foreach` over `config('files_types')` (92 entries), so it fired on
    the first iteration. `settingForm()` catches it — a `ViewException` is an `ErrorException` is
    an `Exception` — and answers HTTP 200 with "Something went wrong!", so the merchant saw the
    whole Storage tab refuse to open with no indication why. The two sibling blocks passed `null`
    instead of `[]`, which PHP 8.3 only deprecates; that is why only the local one was fatal, and
    why a seeded install never showed it. `$csv` below coerces once, and
    `SettingsPanelFreshStoreRenderTest` renders all 23 panels with `$setting = []` so the class
    cannot come back.

    AND FOURTEEN FIELDS HAD NO ACCESSIBLE NAME. Every text and number input here was
    `<label class="form-label" for="s3_key">` over `<input name="s3_key">` with NO `id`, so the
    `for` pointed at nothing and the label named nothing.

    THE NAIVE FIX FOR THAT CREATES A WORSE BUG, which is worth stating because it is not visible
    from either half alone. Three labels in the Wasabi block carried the S3 block's targets —
    `for="s3_key"` over `name="wasabi_key"`, `for="s3_region"` over `name="wasabi_region"`, and
    `for="wasabi_root"` on BOTH the Wasabi Root field and the Max Upload Size field. While the
    ids were absent those `for`s were merely dead. Add the ids without reading the labels and they
    come alive pointing at the wrong input, in a different and usually hidden panel: clicking
    "Wasabi Key" would focus the S3 Key box. Each label is now paired with the control it sits
    over, and `SettingsPanelContractTest` asserts every `for` resolves to an id in the same file.

    A THIRD THING, invalid rather than broken: the Local radio carried a bare `checked` ON TOP OF
    its conditional one, so with S3 or Wasabi stored the markup had two checked radios in one
    group. Browsers resolve that by document order, and Local is first, so it worked only by
    accident of which radio comes last. Now `@checked()` alone, with Local as the default when
    nothing is stored — the same outcome the stray attribute was reaching for.

    THE JS CONTRACT IS UNCHANGED AND NOT OURS TO MOVE. `index.blade.php` binds
    `[name=storage_setting]` and toggles `d-none` on `.local-setting`, `.s3-setting` and
    `.wasabi-setting`. Those four selectors, every field `name`, the `data-role="tagsinput"` hook
    and the POST route stay exactly as they were.
--}}
@php
    /* One coercion for all three blocks. `$setting[$k]` is absent on a fresh store, and was
       `[]` in one branch and `null` in the others -- the first fatal, the second deprecated. */
    $csv = fn (?string $key) => explode(',', (string) ($setting[$key] ?? ''));
@endphp

<div class="ss-card" id="Storage_Setting">
    {{ Form::model($setting, ['route' => 'storage.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Storage Settings') }}</h2>
            <p class="ss-page-header__subtitle">{{ __('Where uploaded files are kept, and what may be uploaded') }}</p>
        </div>
    </div>

    <div class="ss-card__body">
        <fieldset class="ss-field">
            <legend class="ss-label">{{ __('Storage Driver') }}</legend>
            @php $driver = $setting['storage_setting'] ?? 'local'; @endphp
            <div class="ss-choice">
                <input type="radio" class="ss-choice__input" name="storage_setting"
                       id="storage_setting_local" value="local" @checked($driver === 'local')>
                <label class="ss-choice__label" for="storage_setting_local">{{ __('Local') }}</label>
            </div>
            <div class="ss-choice">
                <input type="radio" class="ss-choice__input" name="storage_setting"
                       id="storage_setting_s3" value="s3" @checked($driver === 's3')>
                <label class="ss-choice__label" for="storage_setting_s3">{{ __('AWS S3') }}</label>
            </div>
            <div class="ss-choice">
                <input type="radio" class="ss-choice__input" name="storage_setting"
                       id="storage_setting_wasabi" value="wasabi" @checked($driver === 'wasabi')>
                <label class="ss-choice__label" for="storage_setting_wasabi">{{ __('Wasabi') }}</label>
            </div>
        </fieldset>

        {{-- Local ------------------------------------------------------------------------ --}}
        <div class="local-setting row mt-3 {{ $driver === 'local' ? '' : 'd-none' }}">
            <div class="col-lg-8">
                <div class="ss-field">
                    <label class="ss-label" for="local_storage_validation">{{ __('Only Upload Files') }}</label>
                    <select class="ss-select" name="local_storage_validation[]" data-role="tagsinput"
                            id="local_storage_validation" multiple
                            aria-describedby="local_storage_validation-hint">
                        @foreach ($file_type as $f)
                            <option @selected(in_array($f, $csv('local_storage_validation'), true))>{{ $f }}</option>
                        @endforeach
                    </select>
                    <p class="ss-field__hint" id="local_storage_validation-hint">
                        {{ __('Only these file extensions may be uploaded') }}
                    </p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="ss-field">
                    <label class="ss-label" for="local_storage_max_upload_size">{{ __('Max Upload Size ( In KB)') }}</label>
                    <input type="number" class="ss-input" id="local_storage_max_upload_size"
                           name="local_storage_max_upload_size"
                           value="{{ $setting['local_storage_max_upload_size'] ?? '' }}"
                           placeholder="{{ __('Max Upload Size') }}">
                </div>
            </div>
        </div>

        {{-- AWS S3 ----------------------------------------------------------------------- --}}
        <div class="s3-setting row mt-3 {{ $driver === 's3' ? '' : 'd-none' }}">
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="s3_key">{{ __('S3 Key') }}</label>
                    <input type="text" class="ss-input" id="s3_key" name="s3_key"
                           value="{{ $setting['s3_key'] ?? '' }}" placeholder="{{ __('S3 Key') }}">
                </div>
            </div>
            <div class="col-lg-6">
                {{-- F117: the stored credential never reaches the page; the handler keeps it when
                     this box posts empty. --}}
                <x-ss.secret-field
                    name="s3_secret"
                    :label="__('S3 Secret')"
                    :stored="$setting['s3_secret'] ?? null" />
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="s3_region">{{ __('S3 Region') }}</label>
                    <input type="text" class="ss-input" id="s3_region" name="s3_region"
                           value="{{ $setting['s3_region'] ?? '' }}" placeholder="{{ __('S3 Region') }}">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="s3_bucket">{{ __('S3 Bucket') }}</label>
                    <input type="text" class="ss-input" id="s3_bucket" name="s3_bucket"
                           value="{{ $setting['s3_bucket'] ?? '' }}" placeholder="{{ __('S3 Bucket') }}">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="s3_url">{{ __('S3 URL') }}</label>
                    <input type="text" class="ss-input" id="s3_url" name="s3_url"
                           value="{{ $setting['s3_url'] ?? '' }}" placeholder="{{ __('S3 URL') }}">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    {{-- Its placeholder said "S3 Bucket". --}}
                    <label class="ss-label" for="s3_endpoint">{{ __('S3 Endpoint') }}</label>
                    <input type="text" class="ss-input" id="s3_endpoint" name="s3_endpoint"
                           value="{{ $setting['s3_endpoint'] ?? '' }}" placeholder="{{ __('S3 Endpoint') }}">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="s3_storage_validation">{{ __('Only Upload Files') }}</label>
                    <select class="ss-select" name="s3_storage_validation[]" data-role="tagsinput"
                            id="s3_storage_validation" multiple>
                        @foreach ($file_type as $f)
                            <option @selected(in_array($f, $csv('s3_storage_validation'), true))>{{ $f }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="s3_max_upload_size">{{ __('Max Upload Size ( In KB)') }}</label>
                    <input type="number" class="ss-input" id="s3_max_upload_size" name="s3_max_upload_size"
                           value="{{ $setting['s3_max_upload_size'] ?? '' }}"
                           placeholder="{{ __('Max Upload Size') }}">
                </div>
            </div>
        </div>

        {{-- Wasabi ----------------------------------------------------------------------- --}}
        <div class="wasabi-setting row mt-3 {{ $driver === 'wasabi' ? '' : 'd-none' }}">
            <div class="col-lg-6">
                <div class="ss-field">
                    {{-- Was `for="s3_key"`. --}}
                    <label class="ss-label" for="wasabi_key">{{ __('Wasabi Key') }}</label>
                    <input type="text" class="ss-input" id="wasabi_key" name="wasabi_key"
                           value="{{ $setting['wasabi_key'] ?? '' }}" placeholder="{{ __('Wasabi Key') }}">
                </div>
            </div>
            <div class="col-lg-6">
                {{-- F117: the stored credential never reaches the page; the handler keeps it when
                     this box posts empty. Its label also said `for="s3_secret"` while labelling
                     the WASABI field, cross-linking two different credentials' inputs. --}}
                <x-ss.secret-field
                    name="wasabi_secret"
                    :label="__('Wasabi Secret')"
                    :stored="$setting['wasabi_secret'] ?? null" />
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    {{-- Was `for="s3_region"`. --}}
                    <label class="ss-label" for="wasabi_region">{{ __('Wasabi Region') }}</label>
                    <input type="text" class="ss-input" id="wasabi_region" name="wasabi_region"
                           value="{{ $setting['wasabi_region'] ?? '' }}" placeholder="{{ __('Wasabi Region') }}">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="wasabi_bucket">{{ __('Wasabi Bucket') }}</label>
                    <input type="text" class="ss-input" id="wasabi_bucket" name="wasabi_bucket"
                           value="{{ $setting['wasabi_bucket'] ?? '' }}" placeholder="{{ __('Wasabi Bucket') }}">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="wasabi_url">{{ __('Wasabi URL') }}</label>
                    <input type="text" class="ss-input" id="wasabi_url" name="wasabi_url"
                           value="{{ $setting['wasabi_url'] ?? '' }}" placeholder="{{ __('Wasabi URL') }}">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    {{-- Its placeholder said "Wasabi Bucket". --}}
                    <label class="ss-label" for="wasabi_root">{{ __('Wasabi Root') }}</label>
                    <input type="text" class="ss-input" id="wasabi_root" name="wasabi_root"
                           value="{{ $setting['wasabi_root'] ?? '' }}" placeholder="{{ __('Wasabi Root') }}">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="wasabi_storage_validation">{{ __('Only Upload Files') }}</label>
                    <select class="ss-select" name="wasabi_storage_validation[]" data-role="tagsinput"
                            id="wasabi_storage_validation" multiple>
                        @foreach ($file_type as $f)
                            <option @selected(in_array($f, $csv('wasabi_storage_validation'), true))>{{ $f }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ss-field">
                    {{-- Was a SECOND `for="wasabi_root"`, so this field had no name of its own and
                         the Root field had two labels. --}}
                    <label class="ss-label" for="wasabi_max_upload_size">{{ __('Max Upload Size ( In KB)') }}</label>
                    <input type="number" class="ss-input" id="wasabi_max_upload_size"
                           name="wasabi_max_upload_size"
                           value="{{ $setting['wasabi_max_upload_size'] ?? '' }}"
                           placeholder="{{ __('Max Upload Size') }}">
                </div>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
