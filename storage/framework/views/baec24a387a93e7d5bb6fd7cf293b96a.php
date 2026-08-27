<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('AdditionalCustomFields')): ?> 
<div class="custom-fields">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $fields; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
     <?php
            // Find the matching value for the current field
             $matchedField = $productFields->where('field_id', $field->id)->first();
            $fieldValue = $matchedField ? $matchedField->value : '';
            $options = json_decode($field->options, true) ?? []; // Decode options JSON if applicable
        ?>

        <div class="form-group">
            <label for="field_<?php echo e($field->id); ?>"><?php echo e($field->label); ?></label>
            <input type="hidden" name="field_label_<?php echo e($field->id); ?>" value="<?php echo e($field->label); ?>">

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($field->type === 'Text'): ?>
                <input type="text" id="field_<?php echo e($field->id); ?>" name="field_<?php echo e($field->id); ?>" class="form-control" value="<?php echo e($fieldValue); ?>">
            
            <?php elseif($field->type === 'Textarea'): ?>
                <textarea id="field_<?php echo e($field->id); ?>" name="field_<?php echo e($field->id); ?>" class="form-control"><?php echo e($fieldValue); ?></textarea>
            
            <?php elseif($field->type === 'Radio' && is_array($options)): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $options; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="form-check">
                        <input type="radio" id="field_<?php echo e($field->id); ?>_<?php echo e($loop->index); ?>" name="field_<?php echo e($field->id); ?>" value="<?php echo e($option); ?>" class="form-check-input"
                            <?php echo e($fieldValue == $option ? 'checked' : ''); ?>>
                        <label class="form-check-label" for="field_<?php echo e($field->id); ?>_<?php echo e($loop->index); ?>"><?php echo e($option); ?></label>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            
            <?php elseif($field->type === 'Checkbox' && is_array($options)): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $options; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="form-check">
                        <input type="checkbox" id="field_<?php echo e($field->id); ?>_<?php echo e($loop->index); ?>" name="field_<?php echo e($field->id); ?>[]" value="<?php echo e($option); ?>" class="form-check-input"
                            <?php echo e(is_array(json_decode($fieldValue, true)) && in_array($option, json_decode($fieldValue, true)) ? 'checked' : ''); ?>>
                        <label class="form-check-label" for="field_<?php echo e($field->id); ?>_<?php echo e($loop->index); ?>"><?php echo e($option); ?></label>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            
            <?php elseif($field->type === 'Select' && is_array($options)): ?>
         
                <select id="field_<?php echo e($field->id); ?>" name="field_<?php echo e($field->id); ?>" class="form-control">
                    <option value="" <?php echo e($fieldValue === null ? 'selected' : ''); ?>>Select</option>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $options; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($option); ?>" <?php echo e($fieldValue == $option ? 'selected' : ''); ?>><?php echo e($option); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>


            
            <?php elseif($field->type === 'Date'): ?>
                <input type="date" id="field_<?php echo e($field->id); ?>" name="field_<?php echo e($field->id); ?>" class="form-control" value="<?php echo e($fieldValue); ?>">
            
            <?php elseif($field->type === 'Time'): ?>
                <input type="time" id="field_<?php echo e($field->id); ?>" name="field_<?php echo e($field->id); ?>" class="form-control" value="<?php echo e($fieldValue); ?>">
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/AdditionalCustomFields/src/app/Providers/../../resources/views/product/edit.blade.php ENDPATH**/ ?>