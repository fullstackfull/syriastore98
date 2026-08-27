<div class="supdiv" style="top: 40px;">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductQuickView')): ?>
        <?php
            $enable_quickview = \App\Models\Utility::GetValueByName('enable_product_quickview', $store->id);
        ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($enable_quickview) && $enable_quickview == 'on'): ?>
            <?php echo $__env->make('product-quick-view::pages.button', ['product_slug' => $product->slug ?? null, 'slug' => $store->slug ?? null], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductCompare')): ?>
        <?php
            $enable_compare = \App\Models\Utility::GetValueByName('enable_product_compare', $store->id);
        ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($enable_compare) && $enable_compare == 'on'): ?>
            <?php echo $__env->make('product-compare::pages.button', ['product_slug' => $product->slug ?? null, 'slug' => $store->slug ?? null], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>


    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductsPDF')): ?>
        <?php
            $ProductsPDF_enable = \App\Models\Utility::GetValueByName('products_pdf_enable', $store->id);
        ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($ProductsPDF_enable) && $ProductsPDF_enable == 'on'): ?>

            <?php echo $__env->make('products-pdf::pages.button', ['product_slug' => $product->slug ?? null, 'slug' => $store->slug ?? null], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductCSV')): ?>
        <?php
            $product_csvswich_is_enable = \App\Models\Utility::GetValueByName('product_csvswich_is_enable', $store->id);
        ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($product_csvswich_is_enable) && $product_csvswich_is_enable == 'on'): ?>
            <?php echo $__env->make('product-csv::pages.button', ['product_slug' => $product->slug ?? null, 'slug' => $store->slug ?? null], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

</div><?php /**PATH /home/storesyria/public_html/resources/views/front_end/hooks/action_link.blade.php ENDPATH**/ ?>