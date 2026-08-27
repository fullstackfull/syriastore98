<?php $__env->startSection('page-title'); ?>
<?php echo e(__('Cart Page')); ?>

<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
 <main>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($themeSettings['cart_banner_status'] && $themeSettings['cart_banner_status'] == '1'): ?>
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('<?php echo e(get_file($themeSettings['cart_banner_image'] ?? '')); ?>');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize"><?php echo e($themeSettings['cart_banner_title'] ?? __('Cart Details')); ?></h2>
        </div>
      </div>
    </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($themeSettings['cart_status'] && $themeSettings['cart_status'] == '1'): ?>
    <section class="lg:py-20 py-10 cart-page-section">
      
    </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </main>
<?php $__env->stopSection(); ?>
<?php echo $__env->make('front_end.layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/front_end/pages/cart.blade.php ENDPATH**/ ?>