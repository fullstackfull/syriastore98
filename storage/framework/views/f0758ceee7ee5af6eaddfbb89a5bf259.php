  <!-- Footer -->
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($themeSettings['footer_status'] && $themeSettings['footer_status'] == '1'): ?>
    <footer class="bg-gray-900 text-white pt-12 pb-8 site-footer">
      <div class="md:container mx-auto px-4 w-full">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 md:gap-8 gap-4">
          <!-- About Column -->
          <div>
            <div class="mb-4 footer-logo">
              <a href="<?php echo e(route('landing_page', $slug)); ?>">
                  <img src="<?php echo e(get_file($themeSettings['footer_logo'] ?? '')); ?>" alt="Logo" width="150" height="150" loading="lazy">
              </a>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($themeSettings['footer_description']) && !empty($themeSettings['footer_description'])): ?>
                  <p class="text-gray-400 mb-4" id="<?php echo e($themeSettings['footer_description']); ?>_preview">
                      <?php echo $themeSettings['footer_description']; ?>

                  </p>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <div class="flex gap-4 mt-4">
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = json_decode($themeSettings['footer_repeater']); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $social): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <a href="<?php echo e($social->link ?? '#'); ?>" class="text-gray-400 hover:text-white" aria-label="SocialLink">
                  <i class="<?php echo e($social->icon ?? '#'); ?>"></i>
                </a>
              <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
          </div>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($themeSettings['footer_menu_status']) && $themeSettings['footer_menu_status'] == 1): ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = json_decode($themeSettings['footer_menu_repeater']); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $footer_menu): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div>
              <h4 class="text-lg font-semibold mb-4"><?php echo e($footer_menu->title); ?></h4>
              <ul class="space-y-2 text-gray-400">
                <?php
                  $menuItems = getNavMenu($footer_menu->menu ?? '');
                ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($menuItems)): ?>
                  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $menuItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $menu): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <li><a href="<?php echo e($menu['url'] ?? '#'); ?>"  class="hover:text-white"><?php echo e($menu['title'] ?? ''); ?></a></li>
                  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </ul>
            </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($themeSettings['footer_newsletter_status']) && $themeSettings['footer_newsletter_status'] == 1): ?>
              <!-- Newsletter Column -->
              <div>
              <h3 class="text-lg font-semibold mb-4"><?php echo e($themeSettings['footer_newsletter_title'] ?? __('Join Our Newsletter')); ?></h3>
              <p class="text-gray-400 mb-4">
                  <?php echo e($themeSettings['footer_newsletter_sub_title'] ?? __('Subscribe to our newsletter to get updates on our latest collections and exclusive offers')); ?>

              </p>
              <form action="<?php echo e(route('newsletter.store', $slug)); ?>" method="post" class="mt-4">
                  <?php echo csrf_field(); ?>
                  <div class="flex">
                  <input name="email" type="email" placeholder="<?php echo e(__('Your email address')); ?>"
                      class="px-4 py-2 w-full rounded-l-lg rtl:rounded-r-lg rtl:rounded-l-none outline-none text-gray-900" />
                  <button type="submit" class="bg-[var(--primary-color)] px-4 py-2 rounded-r-lg rtl:rounded-l-lg rtl:rounded-r-none hover:bg-opacity-90">
                      <i class="fas fa-paper-plane rtl:scale-x-[-1]"></i>
                  </button>
                  </div>
              </form>
              </div>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($themeSettings['copy_right_status']) && $themeSettings['copy_right_status'] == 1): ?>
          <div
              class="md:mt-10 mt-6 pt-6 border-t border-gray-800 text-center text-gray-400 text-sm flex flex-wrap gap-4 sm:justify-between justify-center">
              <p><?php echo e($themeSettings['copy_right_description'] ?? __('© 2025, eCommerce Theme Powered by:workdo.io')); ?></p>
              <div class="flex justify-center gap-2">
              <img src="<?php echo e(get_file($themeSettings['copy_right_footer_image_1'])); ?>" alt="Visa" class="h-6 object-cover" />
              <img src="<?php echo e(get_file($themeSettings['copy_right_footer_image_2'])); ?>" alt="Mastercard" class="h-6 object-cover" />
              
              </div>
          </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </div>
    </footer>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><?php /**PATH /home/storesyria/public_html/themes/stylique/views/front_end/partison/footer.blade.php ENDPATH**/ ?>