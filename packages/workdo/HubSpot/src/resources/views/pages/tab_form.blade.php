<div id="hub-spot-setting">
    <div class="card mb-3">
        {{ Form::open(['route' => 'hubspot.setting.store', 'enctype' => 'multipart/form-data']) }}
        @csrf
        <div class="card-header">
            <div class="row">
                <div class="col-lg-10 col-md-10 col-sm-10">
                    <h5>{{ __('HubSpot') }}</h5>
                </div>
                <div class="col-lg-2 col-md-2 col-sm-2 text-end">
                    <div class="form-check form-switch custom-switch-v1 float-end">
                        <input type="checkbox" name="hubspot_is_enable" class="form-check-input input-primary" id="hubspot_is_enable"
                            {{ isset($setting['hubspot_is_enable']) && $setting['hubspot_is_enable'] == 'on' ? 'checked' : 'off' }}>
                        <label class="form-check-label" for="hubspot_is_enable"></label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="form-group col-md-12">
                    <label class="form-label">{{ __('Access Token') }}</label> <br>
                    <input class="form-control hubspot_class" placeholder="{{ __('Enter Access Token') }}"
                        name="hubspot_access_token" type="text"
                        value="{{ isset($setting['hubspot_access_token']) ? $setting['hubspot_access_token'] : '' }}"
                        id="hubspot_access_token"
                        {{ isset($setting['hubspot_is_enable']) && $setting['hubspot_is_enable'] == 'on' ? '' : 'disabled' }}>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-primary btn-badge']) }}
        </div>
        {{ Form::close() }}
    </div>
</div>
<script>
    $(document).on('click', '#hubspot_is_enable', function() {
        if ($('#hubspot_is_enable').prop('checked')) {
            $(".hubspot_class").removeAttr("disabled");
        } else {
            $('.hubspot_class').attr("disabled", "disabled");
        }
    });
</script>
