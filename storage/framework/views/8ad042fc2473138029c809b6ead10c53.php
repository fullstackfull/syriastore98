<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($storeCustom->class_json) && !empty($storeCustom->class_json)): ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(((request()->route('storeSlug') == $slug) && $storeCustom->is_publish == 'on') || (request()->route('storeSlug') != $slug)): ?>
        <?php
            // Decode the JSON string into an associative array
            $cssVariables = json_decode($storeCustom->class_json, true);
        ?>

        <style>
            :root {
                <?php $__currentLoopData = $cssVariables; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php echo e($key); ?>: <?php echo e($value); ?>;
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            }
        </style>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><?php /**PATH /home/storesyria/public_html/packages/workdo/ThemeCustomizer/src/app/Providers/../../resources/views/pages/style.blade.php ENDPATH**/ ?>