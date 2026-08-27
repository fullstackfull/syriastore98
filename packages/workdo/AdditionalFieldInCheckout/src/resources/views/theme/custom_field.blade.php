@if(count($custom_fields) > 0)
    <div class="mt-4">
        <h2 class="font-semibold text-xl md:mb-4 mb-3">{{ __('Additional Fields') }}</h2>
        <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ($custom_fields as $field)
                    @php
                        $options = !empty(json_decode($field->options)) ? json_decode($field->options) : [];
                        $status = "custom_field[{$field->name}][visible_option]";
                        $field_name = "custom_field[{$field->name}][field_name]";
                        $field_value = "custom_field[{$field->name}][field_value]";
                        $visible_order = in_array('view_order_page', $options) ? 'on' : 'off';
                    @endphp

                    @if ($field->type == 'textarea')
                        <div>
                            <label class="block mb-2 font-medium md:text-base text-sm">
                                {{ $field->field_title }}:
                            </label>
                            <textarea
                                class="{{ $field->class }} form-input w-full"
                                name="{{ $field->name }}"
                                placeholder="{{ $field->placeholder }}"
                                rows="3"
                                {{ in_array('require_enable', $options) ? 'required' : '' }}>{{ $field->default_value }}</textarea>
                        </div>
                    @elseif ($field->type == 'select')
                        <div>
                            <label class="block mb-2 font-medium md:text-base text-sm">
                                {{ $field->field_title }}:
                            </label>
                            <select
                                name="{{ $field->name }}"
                                class="{{ $field->class }} form-input w-full"
                                {{ in_array('require_enable', $options) ? 'required' : '' }}>
                                <option value="{{ $field->default_value }}">{{ $field->default_value }}</option>
                            </select>
                        </div>
                    @else
                        <div>
                            <label class="block mb-2 font-medium md:text-base text-sm">
                                {{ $field->field_title }}:
                            </label>
                            <input
                                type="{{ $field->type }}"
                                name="{{ $field->name }}"
                                class="{{ $field->class }} form-input w-full"
                                placeholder="{{ $field->placeholder }}"
                                value="{{ $field->default_value }}"
                                {{ in_array('require_enable', $options) ? 'required' : '' }}>
                        </div>
                    @endif

                    <input type="hidden" name="{{ $status }}" value="{{ $visible_order }}">
                    <input type="hidden" name="{{ $field_value }}" value="{{ $field->default_value }}">
                    <input type="hidden" name="{{ $field_name }}" value="{{ $field->field_title }}">
                @endforeach
            </div>
        </div>
    </div>
@endif
