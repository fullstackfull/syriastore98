<!--Start System Setting-->
<div class="ss-card" id="System_Setting">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('System Settings') }}</h2>
        </div>
    </div>
    {{-- TWO FORMS WERE OPENED HERE, back to back: a `Form::open` immediately followed by a
         `Form::model` for the same route, which emits a `<form>` nested inside a `<form>`.
         Nested forms are invalid HTML — the browser drops the inner one, and which of the two
         the submit button belongs to is then a parser decision rather than a choice. Only the
         `Form::model` is needed: it is what binds `$setting` to the fields. --}}
    {{ Form::model($setting, ['route' => 'system.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
    <div class="ss-card__body">
        
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="ss-field changeLanguage">
                        <label class="ss-label" for="default_language">{{ __('Default Language') }}</label>
                        <select name="default_language" id="default_language" class="ss-input">
                            @foreach (\App\Models\Utility::languages() as $code => $language)
                            <option @if (\Auth::user()['default_language']==$code) selected @endif value="{{ $code }}">
                                {{ ucFirst($language) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="ss-field">
                    <label class="ss-label" for="defult_timezone">{{ __('Default Timezone') }}</label>
                    <select name="defult_timezone" id="defult_timezone" class="ss-input">
                        <option value="" @if (isset($setting['defult_timezone']) && $setting['defult_timezone']=='' ) selected="selected" @endif>
                            {{ __('Select Timezone') }}</option>
                            @foreach ($timezones as $key => $timezone)
                            <option value="{{ $key }}" @if (isset($setting['defult_timezone']) && $setting['defult_timezone'] == $key ) selected="selected" @endif>
                            {{ ucFirst($timezone) }}</option>
                            @endforeach
                    </select>
                    </div>
                </div>

                <div class="col-md-6 col-12">
                    <div class="ss-field">
                    <label class="ss-label" for="site_date_format">{{ __('Date Format') }}</label>
                    <select type="text" name="site_date_format" class="ss-input" id="site_date_format">
                        <option value="M j, Y" @if (isset($setting['site_date_format']) && $setting['site_date_format']=='M j, Y' ) selected="selected" @endif>
                            {{ __('Jan 1,2015') }}</option>
                        <option value="d-m-Y" @if (isset($setting['site_date_format']) && $setting['site_date_format']=='d-m-Y' ) selected="selected" @endif>
                            {{ __('DD-MM-YYYY') }}</option>
                        <option value="m-d-Y" @if (isset($setting['site_date_format']) && $setting['site_date_format']=='m-d-Y' ) selected="selected" @endif>
                            {{ __('MM-DD-YYYY') }}</option>
                        <option value="Y-m-d" @if (isset($setting['site_date_format']) && $setting['site_date_format']=='Y-m-d' ) selected="selected" @endif>
                            {{ __('YYYY-MM-DD') }}</option>
                    </select>
                    </div>
                </div>

                <div class="col-md-6 col-12">
                    <div class="ss-field">
                    <label class="ss-label" for="site_time_format">{{ __('Time Format') }}</label>
                    <select type="text" name="site_time_format" class="ss-input" id="site_time_format">
                        <option value="g:i A" @if (isset($setting['site_time_format']) && $setting['site_time_format']=='g:i A' ) selected="selected" @endif>
                            {{ __('10:30 PM') }}</option>
                        <option value="g:i a" @if (isset($setting['site_time_format']) && $setting['site_time_format']=='g:i a' ) selected="selected" @endif>
                            {{ __('10:30 pm') }}</option>
                        <option value="H:i" @if (isset($setting['site_time_format']) && $setting['site_time_format']=='H:i' ) selected="selected" @endif>
                            {{ __('22:30') }}</option>
                    </select>
                    </div>
                </div>
            </div>        
    </div>
    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
<!--End System Setting-->