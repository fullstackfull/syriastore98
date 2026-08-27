<!-- Address -->
<div class="<?php echo e($type == 'delivery' ? 'mt-4' : ''); ?>">
    <label for="<?php echo e($type); ?>_address" class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__('Address')); ?> <span
        class="text-red-500">*</span></label>
    <textarea name="billing_info[<?php echo e($type); ?>_address]" type="text" id="<?php echo e($type); ?>_address" class="form-input" placeholder="<?php echo e(__('Enter here...')); ?>" required><?php echo e(isset($customer_address) && !empty($customer_address->address) ? $customer_address->address : ''); ?></textarea>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = [$type . '_info[' . $type . '_address]'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
        <span class="text-danger"><?php echo e($message); ?></span>
    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 row">
    <!-- Country -->
    <div>
        <label for="<?php echo e($type); ?>_country_id" class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__("Country")); ?> <span
            class="text-red-500">*</span></label>
        <select class="form-input country_change select2 delivery_list" id="<?php echo e($type); ?>_country_id" name="billing_info[<?php echo e($type); ?>_country]" required>
            <option value=""><?php echo e(__('Select Country')); ?></option>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $countries; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $country): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($country->id); ?>" <?php echo e((isset($customer_address) && !empty($customer_address->country_id) ? $customer_address->country_id : '') == $country->id ? 'selected' : ''); ?>>
                    <?php echo e($country->name); ?>

                </option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = [$type. '_country_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
            <span class="text-danger"><?php echo e($message); ?></span>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>


    <!-- State -->
    <div>
        <label for="<?php echo e($type); ?>_state_id" class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__("State")); ?></label>
        <select class="form-input state_name state_chage select2 delivery_list" id="<?php echo e($type); ?>_state_id" name="billing_info[<?php echo e($type); ?>_state]" data-select="<?php echo e(isset($customer_address) && !empty($customer_address->state_id) ? $customer_address->state_id : ''); ?>">
            <option value=""><?php echo e(__('Select State')); ?></option>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $state_option; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $state): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($state->id); ?>" <?php echo e((isset($customer_address) && !empty($customer_address->state_id) ? $customer_address->state_id : '') == $state->id ? 'selected' : ''); ?>>
                    <?php echo e($state->name); ?>

                </option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
    </div>

    <!-- City -->
    <div>
        <label for="<?php echo e($type); ?>_city_id" class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__("City")); ?></label>
        <select class="form-input city_change select2 delivery_list" id="<?php echo e($type); ?>_city_id" name="billing_info[<?php echo e($type); ?>_city]" data-select="<?php echo e(isset($customer_address) && !empty($customer_address->city_id) ? $customer_address->city_id : ''); ?>">
            <option value=""><?php echo e(__('Select City')); ?></option>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $city_option; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $city): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($city->id); ?>" <?php echo e((isset($customer_address) && !empty($customer_address->city_id) ? $customer_address->city_id : '') == $city->id ? 'selected' : ''); ?>>
                    <?php echo e($city->name); ?>

                </option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
    </div>

    <!-- Postal Code -->
    <div>
        <label for="billing_info[<?php echo e($type); ?>_postecode]" class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__("Postal Code")); ?></label>
        <input name="billing_info[<?php echo e($type); ?>_postecode]" id="<?php echo e($type); ?>_postecode" type="text" class="form-input"  placeholder="<?php echo e(__('Enter here...')); ?>"value="<?php echo e(isset($customer_address) && !empty($customer_address->postcode) ? $customer_address->postcode : ''); ?>">
    </div>
</div><?php /**PATH /home/storesyria/public_html/resources/views/front_end/pages/address-field.blade.php ENDPATH**/ ?>