<form id="address-form" action="{{ route('save-address', $store->slug) }}" method="POST" class="space-y-4">
    @csrf
    @if(isset($address))
        <input type="hidden" name="id" value="{{ $address->id }}">
    @endif

    <!-- Address Label -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="address_type" class="block mb-2 font-medium md:text-base text-sm text-left rtl:text-right">{{ __('Address Label') }}</label>
            <select name="address_type" id="address_type" class="w-full rounded-md border focus:ring-primary focus:border-primary" required>
                <option value="0" {{ isset($address) && $address->address_type == '0' ? 'selected' : '' }}>Home</option>
                <option value="1" {{ isset($address) && $address->address_type == '1' ? 'selected' : '' }}>Work</option>
                <option value="2" {{ isset($address) && $address->address_type == '2' ? 'selected' : '' }}>Other</option>
            </select>
        </div>
    </div>

    <!-- Full Name and Phone -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="full_name" class="block mb-2 font-medium md:text-base text-sm text-left rtl:text-right">{{ __('Full Name') }} <span class="text-red-500">*</span></label>
            <input type="text" name="full_name" id="full_name" class="form-input text-left rtl:text-right" value="{{ $address->full_name ?? old('full_name') }}" required />
        </div>
        <div>
            <label for="phone" class="block mb-2 font-medium md:text-base text-sm text-left rtl:text-right">{{ __('Phone Number') }} <span class="text-red-500">*</span></label>
            <input type="tel" name="phone" id="phone" class="form-input text-left rtl:text-right" value="{{ $address->phone ?? old('phone') }}" required />
        </div>
    </div>

    <!-- Address Line 1 and 2 -->
    <div>
        <label for="address1" class="block mb-2 font-medium md:text-base text-sm text-left rtl:text-right">{{ __('Address') }}  <span class="text-red-500">*</span></label>
        <input type="text" name="address" id="address1" class="form-input text-left rtl:text-right" value="{{ $address->address ?? old('address') }}" required />
    </div>
   
    <!-- Country, State, and City -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label for="country" class="block mb-2 font-medium md:text-base text-sm text-left rtl:text-right">{{ __('Country') }} <span class="text-red-500">*</span></label>
            <select name="country_id" id="country" class="select2 w-full rounded-md border focus:ring-primary focus:border-primary" required>
                <option value="">{{ __('Select a country') }}</option>
                @foreach($countries as $country)
                    <option value="{{ $country->id }}" {{ (isset($address) && $address->country_id == $country->id) || old('country_id') == $country->id ? 'selected' : '' }}>{{ $country->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="state" class="block mb-2 font-medium md:text-base text-sm text-left rtl:text-right">{{ __('State/Province') }} <span class="text-red-500">*</span></label>
            <select name="state_id" id="state" class="select2 w-full rounded-md border focus:ring-primary focus:border-primary" required>
                <option value="">{{ __('Select a state') }}</option>
                @if(isset($address) && $address->state_id)
                    <option value="{{ $address->state_id }}" selected>{{ $address->state }}</option>
                @endif
            </select>
        </div>
        <div>
            <label for="city" class="block mb-2 font-medium md:text-base text-sm text-left rtl:text-right">{{ __('City') }} <span class="text-red-500">*</span></label>
            <select name="city_id" id="city" class="select2 w-full rounded-md border focus:ring-primary focus:border-primary" required>
                <option value="">{{ __('Select a city') }}</option>
                @if(isset($address) && $address->city_id)
                    <option value="{{ $address->city_id }}" selected>{{ $address->city }}</option>
                @endif
            </select>
        </div>
    </div>

    <!-- City, State, and Zip -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label for="zip" class="block mb-2 font-medium md:text-base text-sm text-left rtl:text-right">{{ __('Postal Code') }} <span
                    class="text-red-500">*</span></label>
            <input type="text" name="postcode" id="zip"
                class="form-input text-left rtl:text-right"
                required />
        </div>
    </div>
    <!-- Default Address -->
    <div>
        <div class="checkbox flex items-center gap-2">
            <input type="checkbox" name="default_address" id="is_default" class="rounded border text-primary focus:ring-primary" {{ (isset($address) && $address->default_address) || old('default_address') ? 'checked' : '' }} />
            <label for="is_default" class="flex-1 font-medium text-left rtl:text-right">{{ __('Make this my default address') }}</label>
        </div>
    </div>

    <!-- Delivery Instructions -->
    <div>
        <label for="instructions" class="block mb-2 font-medium md:text-base text-sm text-left rtl:text-right">{{ __('Delivery Instructions') }}</label>
        <textarea name="instructions" id="instructions" class="form-input text-left rtl:text-right" rows="3">{{ $address->instructions ?? old('instructions') }}</textarea>
    </div>

    <!-- Form Actions -->
    <div class="flex flex-wrap gap-4">
        <button type="submit" class="btn-primary">
            {{ isset($address) ? __('Update Address') : __('Save Address') }}
        </button>
        <button type="button" class="close-modal bg-gray-50 border text-gray-700 font-medium py-2.5 px-6 rounded-md hover:bg-primary/10 transition-all duration-300">
            {{ __('Cancel') }}
        </button>
    </div>
</form>

<script>
$(document).ready(function() {
    // Initialize select2 for all select elements
    $('.select2').select2({
        theme: 'default',
        width: '100%'
    });

    // Handle country change
    $('#country').on('change', function() {
        var countryId = $(this).val();
        var stateSelect = $('#state');
        var citySelect = $('#city');
        
        // Clear state and city dropdowns
        stateSelect.empty().append('<option value="">{{ __("Select a state") }}</option>');
        citySelect.empty().append('<option value="">{{ __("Select a city") }}</option>');
        
        if(countryId) {
            // Fetch states for selected country
            $.ajax({
                url: "{{ route('states.list', $store->slug) }}",
                type: "POST",
                data: { country_id: countryId },
                success: function(data) {
                    $.each(data, function(key, value) {
                        stateSelect.append('<option value="' + key + '">' + value + '</option>');
                    });
                    stateSelect.trigger('change');
                }
            });
        }
    });

    // Handle state change
    $('#state').on('change', function() {
        var stateId = $(this).val();
        var citySelect = $('#city');
        
        // Clear city dropdown
        citySelect.empty().append('<option value="">{{ __("Select a city") }}</option>');
        
        if(stateId) {
            // Fetch cities for selected state
            $.ajax({
                url: "{{ route('city.list', $store->slug) }}",
                type: "POST",
                data: { state_id: stateId },
                success: function(data) {
                    $.each(data, function(key, value) {
                        citySelect.append('<option value="' + key + '">' + value + '</option>');
                    });
                    citySelect.trigger('change');
                }
            });
        }
    });

    // If editing address, trigger country change to load states
    @if(isset($address) && $address->country_id)
        $('#country').trigger('change');
    @endif
});
</script>