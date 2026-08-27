@if (module_is_active('AdditionalCustomFields')) 
<div class="custom-fields">
    @foreach ($fields as $field)
     @php
            // Find the matching value for the current field
             $matchedField = "";
            $fieldValue =  '';
            $options = json_decode($field->options, true) ?? []; // Decode options JSON if applicable
        @endphp

        <div class="form-group">
            <label for="field_{{ $field->id }}">{{ $field->label }}</label>
            <input type="hidden" name="field_label_{{ $field->id }}" value="{{ $field->label }}">

            @if ($field->type === 'Text')
                <input type="text" id="field_{{ $field->id }}" name="field_{{ $field->id }}" class="form-control" value="{{ $fieldValue }}">
            
            @elseif ($field->type === 'Textarea')
                <textarea id="field_{{ $field->id }}" name="field_{{ $field->id }}" class="form-control">{{ $fieldValue }}</textarea>
            
            @elseif ($field->type === 'Radio' && is_array($options))
                @foreach ($options as $option)
                    <div class="form-check">
                        <input type="radio" id="field_{{ $field->id }}_{{ $loop->index }}" name="field_{{ $field->id }}" value="{{ $option }}" class="form-check-input"
                            {{ $fieldValue == $option ? 'checked' : '' }}>
                        <label class="form-check-label" for="field_{{ $field->id }}_{{ $loop->index }}">{{ $option }}</label>
                    </div>
                @endforeach
            
            @elseif ($field->type === 'Checkbox' && is_array($options))
                @foreach ($options as $option)
                    <div class="form-check">
                        <input type="checkbox" id="field_{{ $field->id }}_{{ $loop->index }}" name="field_{{ $field->id }}[]" value="{{ $option }}" class="form-check-input"
                            {{ is_array(json_decode($fieldValue, true)) && in_array($option, json_decode($fieldValue, true)) ? 'checked' : '' }}>
                        <label class="form-check-label" for="field_{{ $field->id }}_{{ $loop->index }}">{{ $option }}</label>
                    </div>
                @endforeach
            
            @elseif ($field->type === 'Select' && is_array($options))
            <select id="field_{{ $field->id }}" name="field_{{ $field->id }}" class="form-control">
                    <option value="" {{ $fieldValue === null ? 'selected' : '' }}>Select</option>
                    @foreach ($options as $option)
                        <option value="{{ $option }}" {{ $fieldValue == $option ? 'selected' : '' }}>{{ $option }}</option>
                    @endforeach
                </select>
            
            @elseif ($field->type === 'Date')
                <input type="date" id="field_{{ $field->id }}" name="field_{{ $field->id }}" class="form-control" value="{{ $fieldValue }}">
            
            @elseif ($field->type === 'Time')
                <input type="time" id="field_{{ $field->id }}" name="field_{{ $field->id }}" class="form-control" value="{{ $fieldValue }}">
            @endif
        </div>
    @endforeach
</div>
@endif
