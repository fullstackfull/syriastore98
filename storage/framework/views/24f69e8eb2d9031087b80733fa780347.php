<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductPricing') && isset($item->sale_price)): ?>
    <ins class="no-underline"><?php echo e(currency_format_with_sym($item->sale_price, $store->id)); ?>

    </ins>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->total_orignal_price > $item->sale_price): ?>
    <del class="text-gray-500"><?php echo e(currency_format_with_sym($item->total_orignal_price, $store->id) ?? SetNumberFormat($item->total_orignal_price)); ?></del>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php else: ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->final_price == $item->total_orignal_price): ?>
    <ins class="no-underline"><?php echo e(currency_format_with_sym($item->final_price, $store->id)); ?>

    </ins>
    <?php else: ?> 
    <ins class="no-underline"><?php echo e(currency_format_with_sym($item->final_price, $store->id)); ?>

    </ins>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->total_orignal_price > $item->final_price): ?>
        <del class="text-gray-500"><?php echo e(currency_format_with_sym($item->total_orignal_price, $store->id)); ?></del>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/storesyria/public_html/resources/views/front_end/hooks/manage_cart_price.blade.php ENDPATH**/ ?>