<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->variant_product == 0): ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($product->is_sale_enable) && $product->is_sale_enable == true): ?>
        <span class="product_final_price"> <?php echo currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,($product->sale_price ?? $product->price)), $store->id); ?></span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($product->sale_price)): ?>
            <span class="product_orignal_price"> <?php echo currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,$product->price), $store->id); ?></span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php else: ?> 
        <?php if(!empty($product->sale_price) && $product->sale_price < $product->price): ?>
            <span class="product_final_price"> <?php echo currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,$product->sale_price), $store->id); ?></span>
            <span class="product_orignal_price"> <?php echo currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,$product->price), $store->id); ?></span>
        <?php else: ?>
            <span class="product_final_price"> <?php echo currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,$product->price), $store->id); ?></span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php else: ?>
<span class="product_final_price"> <?php echo currency_format_with_sym(0, $store->id); ?></span>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><?php /**PATH /home/storesyria/public_html/resources/views/front_end/hooks/product_price.blade.php ENDPATH**/ ?>