{{ Form::model($customField, [
    'route' => ['additionalcustomfields.update', $customField->id],
    'method' => 'PUT',
    'class' => 'needs-validation',
    'novalidate'
]) }}
<input type="hidden" name="id" value="{{ $customField->id }}">

<div class="row">
    <!-- Custom Field Name -->
    <div class="form-group col-md-12">
        {{ Form::label('name', __('Custom Field Name'), ['class' => 'form-label']) }}
        {{ Form::text('name', $customField->label, [
            'class' => 'form-control',
            'required' => 'required',
            'placeholder' => __('Create New Custom Field')
        ]) }}
    </div>

    <!-- Field Type -->
    <div class="form-group col-md-12">
        {{ Form::label('type', __('Type'), ['class' => 'form-label']) }}
        {{ Form::select('type', $types, strtolower($customField->type), [
            'class' => 'form-control',
            'required' => 'required',
            'id' => 'field-type'
        ]) }}
    </div>

    <!-- Dynamic Fields for Radio, Select, Checkbox (Options) -->
    <div id="options-container" class="row {{ $customField->type === 'radio' || $customField->type === 'checkbox' || $customField->type === 'select' ? '' : 'd-none' }}">
        <label for="options" class="form-label">{{ __('Options') }}</label>
        <div class="col-11">
            <div id="options-list">
                @php
                    $options = json_decode($customField->options, true) ?? [];
                @endphp
                @foreach($options as $option)
                    <div class="option-item mb-3 d-flex align-items-center">
                        <input type="text" name="options[]" class="form-control me-2" value="{{ $option }}" placeholder="{{ __('Option') }}" required>
                        <button type="button" class="btn btn-sm btn-danger remove-option"><i class="ti ti-trash"></i></button>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="col-1 mt-1">
            <button type="button" id="add-option" class="btn btn-sm btn-success"><i class="ti ti-plus"></i></button>
        </div>
    </div>
</div>

<div class="modal-footer">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-light" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Update') }}" class="btn btn-primary">
</div>

{{ Form::close() }}

<script>
    $(document).ready(function () {
        // Show/Hide options container based on the selected field type
        function toggleOptionsContainer() {
            var fieldType = $("#field-type").val();
            if (fieldType === 'radio' || fieldType === 'checkbox' || fieldType === 'select') {
                $("#options-container").removeClass('d-none');
                $("#options-list input").attr('required', true);
            } else {
                $("#options-container").addClass('d-none');
                $("#options-list input").removeAttr('required');
            }
        }

        toggleOptionsContainer();

        $("#field-type").on('change', function () {
            toggleOptionsContainer();
        });

        // Add option dynamically
        $("#add-option").on('click', function () {
            var optionHtml = `
                <div class="option-item mb-3 d-flex align-items-center">
                    <input type="text" name="options[]" class="form-control me-2" placeholder="{{ __('Option') }}" required>
                    <button type="button" class="btn btn-sm btn-danger remove-option"><i class="ti ti-trash"></i></button>
                </div>`;
            $("#options-list").append(optionHtml);
        });

        // Remove specific option dynamically
        $(document).on('click', '.remove-option', function () {
            $(this).closest('.option-item').remove();
        });

        // Client-side validation
        $("form.needs-validation").on('submit', function (e) {
            var isValid = true;
            var fieldType = $("#field-type").val();

            // Validate options if required
            if (fieldType === 'radio' || fieldType === 'checkbox' || fieldType === 'select') {
                let hasValidOption = false;

                $("input[name='options[]']").each(function () {
                    if ($(this).val().trim() !== '') {
                        hasValidOption = true;
                        $(this).removeClass('is-invalid');
                    } else {
                        $(this).addClass('is-invalid');
                    }
                });

                if (!hasValidOption) {
                    isValid = false;
                    alert('{{ __("Please provide at least one valid option.") }}');
                }
            }

            if (!isValid) {
                e.preventDefault();
            }
        });
    });
</script>
