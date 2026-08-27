<div class="tab-pane fade @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-store_locator-tab')) show active @endif" id="pills-store_locator" role="tabpanel" aria-labelledby="pills-store_locator-tab">
    <div id="store_locator_settings">
        <div class="card">
            {{ Form::model($settings, ['route' => ['update.store.locator.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
            @csrf
            <input type="hidden" name="app_setting_tab" value="pills-store_locator-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> {{ __('Store Locator Settings') }} </h5>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="form-group col-md-6">
                            {{ Form::label('store_address', __('Add Your Address'), ['class' => 'col-form-label']) }}
                            {{ Form::text('store_address', null, array('class' => 'form-control','id'=>"store-locate-address",'required'=>'required','autocomplete' => 'off')) }}
                            @error('store_address')
                                <span class="invalid-store_address" role="alert">
                                    <strong class="text-danger">{{ $message }}</strong>
                                </span>
                            @enderror
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
            </div>
            {{ Form::close() }}
        </div>
    </div>
</div>

@push('scripts')
    @if ($settings['store_locator_enabled'] ?? '' == 'on' && !empty($settings['store_locator_api_key'] ?? ''))
        <script
            src="https://maps.googleapis.com/maps/api/js?key={{ $settings['store_locator_api_key'] }}&callback=initAutocomplete&libraries=places&v=weekly"
            defer></script>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                document.getElementById("store-locate-address").focus();
            });
        </script>

        <script type="text/javascript">
            let location_data = '';
            let autocomplete;

            function initAutocomplete() {
                const addressField = document.querySelector("#store-locate-address");
                autocomplete = new google.maps.places.Autocomplete(addressField, {
                    componentRestrictions: {
                        country: ["us", "ca", "in"]
                    },
                    fields: ["address_components", "geometry"],
                    types: ["address"],
                });

                autocomplete.addListener("place_changed", function() {
                    fillInAddress();
                });
            }

            function fillInAddress() {
                const place = autocomplete.getPlace();
                for (const component of place.address_components) {
                    const componentType = component.types[0];
                }
                location_data = addressField.value;
            }
            window.initAutocomplete = initAutocomplete;
        </script>
    @endif
@endpush
