<?php echo e(Form::open(['route' => 'additionalcustomfields.store', 'class' => 'needs-validation', 'novalidate'])); ?>


<div class="row">
    <!-- Custom Field Name -->
    <div class="form-group col-md-12">
        <?php echo e(Form::label('name', __('Custom Field Name'), ['class' => 'form-label'])); ?>

        <?php echo e(Form::text('name', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => 'Create New Custom Field'])); ?>

    </div>

    <!-- Field Type -->
    <div class="form-group col-md-12">
        <?php echo e(Form::label('type', __('Type'), ['class' => 'form-label'])); ?>

        <?php echo e(Form::select('type', $types, null, [
            'class' => 'form-control',
            'required' => 'required',
            'id' => 'field-type',
        ])); ?>

    </div>

    <!-- Dynamic Fields for Radio, Select, Checkbox (Options) -->
    <div id="options-container" class="row d-none">
        <label for="options" class="form-label"><?php echo e(__('Options')); ?></label>
        <div class="col-11">
            <div id="options-list">
                <div class="option-item mb-3">
                    <input type="text" name="options[]" class="form-control mb-2" placeholder="<?php echo e(__('Option')); ?>">
                </div>
            </div>
        </div>
        <div class="col-1 mt-1">
            <button type="button" id="add-option" class="btn btn-sm btn-success "><i class="ti ti-plus"></i></button>
        </div>
    </div>
</div>

<div class="modal-footer">
    <input type="button" value="<?php echo e(__('Cancel')); ?>" class="btn btn-light" data-bs-dismiss="modal">
    <input type="submit" value="<?php echo e(__('Create')); ?>" class="btn btn-primary">
</div>

<?php echo e(Form::close()); ?>

<script>
    $(document).ready(function () {
        // Show/Hide options container based on the selected field type
        $("#field-type").on('change', function () {
            var value = $(this).val();

            if (value == 'radio' || value == 'checkbox' || value == 'select') {
                $("#options-container").removeClass('d-none');
                $("#options-list input").attr('required', true); // Set required attribute
            } else {
                $("#options-container").addClass('d-none');
                $("#options-list input").removeAttr('required'); // Remove required attribute
            }
        });

        // Add option dynamically
        $("#add-option").on('click', function () {
            var optionHtml = `
                <div class="option-item mb-3 d-flex align-items-center">
                    <input type="text" name="options[]" class="form-control me-2" placeholder="Option" required>
                    <button type="button" class="btn btn-sm btn-danger remove-option"><i class="ti ti-trash"></i></button>
                </div>`;
            $("#options-list").append(optionHtml);
        });

        // Remove specific option dynamically
        $(document).on('click', '.remove-option', function () {
            $(this).closest('.option-item').remove();
        });

        // Validate form before submission
        $("form.needs-validation").on('submit', function (e) {
            let isValid = true;
            const selectedType = $("#field-type").val();

            // If field type is radio, checkbox, or select, validate options
            if (selectedType === 'radio' || selectedType === 'checkbox' || selectedType === 'select') {
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
                    alert('Please provide at least one option for the selected field type.');
                }
            }

            if (!isValid) {
                e.preventDefault(); // Stop form submission
            }
        });
    });
</script>
<?php /**PATH /home/storesyria/public_html/packages/workdo/AdditionalCustomFields/src/app/Providers/../../resources/views/create.blade.php ENDPATH**/ ?>