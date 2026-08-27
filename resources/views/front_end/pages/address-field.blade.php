<!-- Address -->
<div class="{{ $type == 'delivery' ? 'mt-4' : '' }}">
    <label for="{{$type}}_address" class="block mb-2 font-medium md:text-base text-sm">{{ __('Address') }} <span
        class="text-red-500">*</span></label>
    <textarea name="billing_info[{{$type}}_address]" type="text" id="{{$type}}_address" class="form-input" placeholder="{{ __('Enter here...') }}" required>{{ isset($customer_address) && !empty($customer_address->address) ? $customer_address->address : '' }}</textarea>
        @error($type . '_info[' . $type . '_address]')
        <span class="text-danger">{{ $message }}</span>
    @enderror
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 row">
    <!-- Country -->
    <div>
        <label for="{{$type}}_country_id" class="block mb-2 font-medium md:text-base text-sm">{{ __("Country") }} <span
            class="text-red-500">*</span></label>
        <select class="form-input country_change select2 delivery_list" id="{{$type}}_country_id" name="billing_info[{{$type}}_country]" required>
            <option value="">{{ __('Select Country') }}</option>
            @foreach ($countries as $country)
                <option value="{{ $country->id }}" {{ (isset($customer_address) && !empty($customer_address->country_id) ? $customer_address->country_id : '') == $country->id ? 'selected' : '' }}>
                    {{ $country->name }}
                </option>
            @endforeach
        </select>
        @error($type. '_country_id')
            <span class="text-danger">{{ $message }}</span>
        @enderror
    </div>


    <!-- State -->
    <div>
        <label for="{{$type}}_state_id" class="block mb-2 font-medium md:text-base text-sm">{{ __("State") }}</label>
        <select class="form-input state_name state_chage select2 delivery_list" id="{{$type}}_state_id" name="billing_info[{{$type}}_state]" data-select="{{ isset($customer_address) && !empty($customer_address->state_id) ? $customer_address->state_id : '' }}">
            <option value="">{{ __('Select State') }}</option>
            @foreach ($state_option as $state)
                <option value="{{ $state->id }}" {{ (isset($customer_address) && !empty($customer_address->state_id) ? $customer_address->state_id : '') == $state->id ? 'selected' : '' }}>
                    {{ $state->name }}
                </option>
            @endforeach
        </select>
    </div>

    <!-- City -->
    <div>
        <label for="{{$type}}_city_id" class="block mb-2 font-medium md:text-base text-sm">{{ __("City") }}</label>
        <select class="form-input city_change select2 delivery_list" id="{{$type}}_city_id" name="billing_info[{{$type}}_city]" data-select="{{ isset($customer_address) && !empty($customer_address->city_id) ? $customer_address->city_id : '' }}">
            <option value="">{{ __('Select City') }}</option>
            @foreach ($city_option as $city)
                <option value="{{ $city->id }}" {{ (isset($customer_address) && !empty($customer_address->city_id) ? $customer_address->city_id : '') == $city->id ? 'selected' : '' }}>
                    {{ $city->name }}
                </option>
            @endforeach
        </select>
    </div>

    <!-- Postal Code -->
    <div>
        <label for="billing_info[{{$type}}_postecode]" class="block mb-2 font-medium md:text-base text-sm">{{ __("Postal Code") }}</label>
        <input name="billing_info[{{$type}}_postecode]" id="{{$type}}_postecode" type="text" class="form-input"  placeholder="{{ __('Enter here...') }}"value="{{ isset($customer_address) && !empty($customer_address->postcode) ? $customer_address->postcode : '' }}">
    </div>
</div>