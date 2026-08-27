<!--Start System Setting-->
<div class="card" id="System_Setting">
    <div class="card-header">
        <h5 class=""> {{ __('System Settings') }} </h5>
    </div>
    {{ Form::open(['route' => 'system.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
    {{ Form::model($setting, ['route' => 'system.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
    <div class="card-body p-4">
        
            <div class="row">
                <div class="col-md-6 col-12 form-group">
                    {{ Form::label('default_language', __('Default Language'), ['class' => 'form-label']) }}
                    <div class="changeLanguage">
                        <select name="default_language" id="default_language" class="form-control" data-toggle="select">
                            @foreach (\App\Models\Utility::languages() as $code => $language)
                            <option @if (\Auth::user()['default_language']==$code) selected @endif value="{{ $code }}">
                                {{ ucFirst($language) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class=" col-md-6 col-12 form-group">
                    {{ Form::label('defult_timezone', __('Default Timezone'), ['class' => 'form-label']) }}
                    <select name="defult_timezone" id="defult_timezone" class="form-control">
                        <option value="" @if (isset($setting['defult_timezone']) && $setting['defult_timezone']=='' ) selected="selected" @endif>
                            {{ __('Select Timezone') }}</option>
                            @foreach ($timezones as $key => $timezone)
                            <option value="{{ $key }}" @if (isset($setting['defult_timezone']) && $setting['defult_timezone'] == $key ) selected="selected" @endif>
                            {{ ucFirst($timezone) }}</option>
                            @endforeach
                    </select>
                </div>

                <div class=" col-md-6 col-12 form-group">
                    <label for="site_date_format" class="form-label">{{ __('Date Format') }}</label>
                    <select type="text" name="site_date_format" class="form-control" data-toggle="select" id="site_date_format">
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

                <div class="col-md-6 col-12 form-group">
                    <label for="site_time_format" class="form-label">{{ __('Time Format') }}</label>
                    <select type="text" name="site_time_format" class="form-control" data-toggle="select" id="site_time_format">
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
    <div class="card-footer d-flex justify-content-end flex-wrap ">
        <input type="submit" value="{{ __('Save Changes') }}" class="btn-badge btn-submit btn btn-primary">
    </div>
    {!! Form::close() !!}
</div>
<!--End System Setting-->