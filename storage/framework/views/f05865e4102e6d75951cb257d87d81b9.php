<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale()) ?? 'en'); ?>" dir="<?php echo e(($currantLang == 'ar' || $currantLang == 'he') ? 'rtl' : 'ltr'); ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1" />
  <meta name="author" content="<?php echo e(config('app.name') ?? Stylique); ?>">
  <meta name="base-url" content="<?php echo e(URL::to('/')); ?>">
  <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
   <?php echo metaKeywordSetting($metakeyword ?? null,$metadesc ?? null,$metaimage ?? null,$slug); ?>

  <title><?php echo $__env->yieldContent('page-title'); ?></title>
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="<?php echo e(asset('themes/' . $currentTheme . '/assets/css/all.min.css')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset('themes/' . $currentTheme . '/assets/css/swiper-bundle.min.css')); ?>" />
  
    <link rel="stylesheet" href="<?php echo e(asset('themes/' . $currentTheme . '/assets/css/main-style.css')); ?>">
  
  <script src="<?php echo e(asset('themes/' . $currentTheme . '/assets/js/tailwind.js')); ?>"></script>
 
    <meta name="mobile-wep-app-capable" content="yes">
    <meta name="apple-mobile-wep-app-capable" content="yes">
    <meta name="msapplication-starturl" content="/">
    <!-- Favicon icon -->
    <?php
        $favicon = $themeSettings['header_favicon'] ?? '';
        $faviconUrl = !empty($favicon) ? get_file($favicon) : asset('storage/uploads/logo/favicon.png');
    ?>
    <link rel="icon" href="<?php echo e($faviconUrl); ?>" type="image/x-icon" />
    <link rel="apple-touch-icon" href="<?php echo e($faviconUrl); ?>">
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo e($faviconUrl); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo e($faviconUrl); ?>">
    <link rel="apple-touch-icon" sizes="167x167" href="<?php echo e($faviconUrl); ?>">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($store->enable_pwa_store == 'on'): ?>
        <link rel="manifest"
            href="<?php echo e(asset('storage/uploads/customer_app/store_' . $store->id . '/manifest.json')); ?>" />
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($store->pwa_store($store->slug)->theme_color)): ?>
        <meta name="theme-color" content="<?php echo e($store->pwa_store($store->slug)->theme_color); ?>" />
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(!empty($store->pwa_store($store->slug)->background_color)): ?>
        <meta name="apple-mobile-web-app-status-bar"
            content="<?php echo e($store->pwa_store($store->slug)->background_color); ?>" />
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <style>
        <?php echo $storecss ?? ''; ?>

    </style>
    <?php echo $__env->yieldPushContent('page-style'); ?>

    <script src="<?php echo e(asset('js/jquery.min.js')); ?>"></script>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($currantLang == 'ar' || $currantLang == 'he'): ?>
        <link rel="stylesheet" href="<?php echo e(asset('assets/css/rtl-floating-wpp.min.css')); ?>">
    <?php else: ?>
        <link rel="stylesheet" href="<?php echo e(asset('assets/css/floating-wpp.min.css')); ?>">
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/plugins/select2.min.css')); ?>">
    <script src="<?php echo e(asset('assets/js/plugins/select2.min.js')); ?>"></script>
</head>

<body>
    <div class="overlay" id="filter-overlay"></div>
   <?php echo $__env->make('front_end.partison.header', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($pixelScript)): ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $pixelScript; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $script): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?= $script ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php echo $__env->yieldContent('content'); ?>
    <?php echo $__env->make('front_end.partison.footer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <?php echo $__env->make('front_end.jQueryRoute', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
  <!-- Swiper JS -->

  <script src="<?php echo e(asset('themes/' . $currentTheme . '/assets/js/swiper-bundle.min.js')); ?>"></script>

  <script src="<?php echo e(asset('themes/' . $currentTheme . '/assets/js/custom.js')); ?>"></script>

   <!--Theme Routes Script-->
    
    <!--scripts end here-->
   <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($message = Session::get('success')): ?>
        <script>
            var site_url = $('meta[name="base-url"]').attr('content');
            notifier.show('Success', '<?php echo $message; ?>', 'success', site_url +
                '/public/assets/images/notification/ok-48.png', 4000);
        </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($message = Session::get('error')): ?>
        <script>
            var site_url = $('meta[name="base-url"]').attr('content');
            notifier.show('Error', '<?php echo $message; ?>', 'danger', site_url +
                '/public/assets/images/notification/high_priority-48.png', 4000);
        </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php echo $__env->make('front_end.hooks.footer_link', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php echo $__env->yieldPushContent('page-script'); ?>
    <script>

        function show_toastr(title, message, type) {
            var o, i;
            var icon = '';
            var cls = '';
            if (type == 'success') {
                cls = 'primary';
                notifier.show('Success', message, 'success', site_url + '/public/assets/images/notification/ok-48.png', 4000);
            } else {
                cls = 'danger';
                notifier.show('Error', message, 'danger', site_url + '/public/assets/images/notification/high_priority-48.png', 4000);
            }
        }
    </script>
</body>
</html>
<?php /**PATH /home/storesyria/public_html/themes/stylique/views/front_end/layouts/app.blade.php ENDPATH**/ ?>