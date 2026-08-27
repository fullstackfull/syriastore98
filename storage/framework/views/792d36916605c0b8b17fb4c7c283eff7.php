<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['product', 'store']));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter((['product', 'store']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<div class="flex product-card flex-col h-full bg-white rounded-lg overflow-hidden shadow-md hover:shadow-lg transition-shadow duration-300">
        <?php echo \App\Models\Product::productSalesPage( $store->slug, $product->id, false); ?>

        <div class="relative">
            <a href="<?php echo e(url($store->slug . '/product/' . $product->slug)); ?>">
                <img src="<?php echo e(get_file($product->cover_image_path)); ?>"
                width="400" height="400"
                alt="<?php echo e($product->name); ?>"
                loading="lazy"
                class="w-full h-80 object-cover object-top product-image" />
            </a>
        
            <div class="absolute top-4 right-4">
                <button class="wishlist-btn wishbtn-globaly <?php echo e($product->in_whishlist ? 'active' : ''); ?> relative flex bg-white rounded p-2 border" 
                        product_id="<?php echo e($product->id); ?>"
                        in_wishlist="<?php echo e($product->in_whishlist ? 'remove' : 'add'); ?>">
                        <i class="far fa-heart text-gray-600"></i>
                    </button>
                    <?php echo \App\Models\Product::actionLinks( $store, $product); ?>

                </div>
            </div>
        <div class="h-full flex flex-col">
            <div class="p-4 pb-0 flex-1">
                <h3 class="font-medium mb-1 text-lg">
                <a href="<?php echo e(url($store->slug . '/product/' . $product->slug)); ?>"><?php echo e($product->name); ?></a>
                </h3>
                <p class="text-gray-500 text-sm mb-2 line-clamp-2">
                <?php echo e(Str::limit(strip_tags($product->description), 100)); ?>

                </p>
            </div>
            <div class="p-4 pt-0 flex justify-between items-center">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->variant_product == 0): ?>
                <div>
                    <span class="font-bold text-lg">
                    <?php echo \App\Models\Product::getProductPrice($product, $store); ?>

                    </span>
                </div>
                <?php else: ?>
                <span class="font-bold text-lg">
                    <ins><?php echo e(__('In Variant')); ?></ins>
                </span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <button class="addtocart-btn btn addcart-btn-globaly bg-[var(--primary-color)] text-white px-3 py-1 rounded hover:bg-opacity-90 transition-colors duration-300"
                tabindex="0"
                product_id="<?php echo e($product->id); ?>"
                variant_id="0"
                qty="1">
                <span><?php echo e(__('Add to cart')); ?></span>
                </button>
                <?php echo \App\Models\Product::ProductcardButton($store->slug, $product); ?>

            </div>
        </div>
</div><?php /**PATH /home/storesyria/public_html/themes/stylique/views/components/product-card.blade.php ENDPATH**/ ?>