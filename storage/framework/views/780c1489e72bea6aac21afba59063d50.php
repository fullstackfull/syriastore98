<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductPricing') && isset($item->sale_price)): ?>
   <div class="font-semibold text-right"><?php echo e(currency_format_with_sym($item->sale_price, $store->id) ?? SetNumberFormat($item->sale_price)); ?>

   </div>
<?php else: ?>
   <div class="font-semibold text-right"><?php echo e(currency_format_with_sym($item->final_price, $store->id) ?? SetNumberFormat($item->final_price)); ?>

   </div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/storesyria/public_html/resources/views/front_end/hooks/manage_checkout_price.blade.php ENDPATH**/ ?>