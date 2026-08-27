<div class="tab-pane fade @if (isset($app_setting_tab) && $app_setting_tab == 'pills-hours-tab') show active @endif" id="pills-hours" role="tabpanel"
    aria-labelledby="pills-hours-tab">
    <div id="hours">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> {{ __('Hours Settings') }} </h5>
            </div>
            {{ Form::model($business_hours, ['route' => 'theme.hours.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
            <div class="card-body p-4">
                <input type="hidden" name="app_setting_tab" value="pills-hours-tab">
                <div class="bussiness-hours">
                    <div class="row align-items-center gy-4">
                        <div class="col-lg-12">
                            <div class="bussiness-hours-header">
                                <div class="row">
                                    <div class="col-lg-2">
                                        <strong>{{ __('Day') }}</strong>
                                    </div>
                                    <div class="col-lg-5">
                                        <strong>{{ __('Start Time') }}</strong>
                                    </div>
                                    <div class="col-lg-5">
                                        <strong>{{ __('End Time') }}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @foreach ($days as $k => $day)
                            <div class="col-12">
                                <div class="row cust-day-row gy-2 gx-2 align-items-center">
                                    <div class="col-lg-auto flex-grow-1 col-md-12">
                                        <div class="form-check">
                                            <input class="form-check-input days" name="days_{{ $k }}"
                                                type="checkbox" id="days_{{ $k }}"
                                                @if (!is_null($business_hours)) {{ isset($business_hours->$k) && $business_hours->$k->days == 'on' ? 'checked' : '' }} @endif>
                                            <label class="form-check-label f-4" for="days_{{ $k }}">
                                                {{ __($day) }}
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-xxl-5 col-lg-4 col-md-6 col-sm-6">
                                        <div class="form-group mb-0">
                                            <input type="time" id="days_{{ $k }}_start"
                                                data-id="days_{{ $k }}" name="start-{{ $k }}"
                                                class="form-control timepicker" placeholder="08:10"
                                                value="{{ !is_null($business_hours) && isset($business_hours->$k) && $business_hours->$k->days == 'on'
                                                    ? $business_hours->$k->start_time
                                                    : '' }}"
                                                onchange="changeFunction(this.id)">
                                        </div>
                                    </div>
                                    <div class="col-xxl-5 col-lg-4 col-md-6 col-sm-6">
                                        <div class="form-group mb-0">
                                            <input type="time" id="days_{{ $k }}_end"
                                                data-id="days_{{ $k }}" name="end-{{ $k }}"
                                                class="form-control timepicker" onchange="changeFunction(this.id)"
                                                placeholder="08:10"
                                                value="{{ !is_null($business_hours) && isset($business_hours->$k) && $business_hours->$k->days == 'on' ? $business_hours->$k->end_time : '' }}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                {{-- @permission('Edit Store Setting') --}}
                <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
                {!! Form::close() !!}
                {{-- @endpermission --}}
            </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>
