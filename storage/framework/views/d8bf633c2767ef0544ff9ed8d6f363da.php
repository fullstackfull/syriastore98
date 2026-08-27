<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductBarCode')): ?>
<?php
    $enable_product_barcode = \App\Models\Utility::GetValueByName('enable_product_barcode', $store->id);
?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($enable_product_barcode) && $enable_product_barcode == 'on'): ?>
        <?php echo $__env->make('product-bar-code::pages.qrcode', ['product_id' => $product->product_id ?? null ,'slug' => $slug ?? null], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/storesyria/public_html/resources/views/front_end/hooks/cart_table_body.blade.php ENDPATH**/ ?>