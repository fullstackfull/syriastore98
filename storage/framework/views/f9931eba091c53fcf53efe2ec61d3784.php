<?php
    $settings = \Workdo\LandingPage\Entities\LandingPageSetting::settings();
    $logo = get_file('storage/uploads/landing_page_image');

    $sup_logo = get_file('storage/uploads/logo');
    $superadmin = \App\Models\User::where('type', 'super admin')->first();
    $setting = getSuperAdminAllSetting();
    $SITE_RTL = $setting['SITE_RTL'] ?? 'off';

    // $color = !empty($setting['color']) ? $setting['color'] : 'theme-3';

    if (!isset($setting['color'])) {
        $color = 'theme-3';
    } elseif (isset($setting['color_flag']) && $setting['color_flag'] == 'true') {
        $color = 'custom-color';
    } else {
        if (
            !in_array($setting['color'], [
                'theme-1',
                'theme-2',
                'theme-3',
                'theme-4',
                'theme-5',
                'theme-6',
                'theme-7',
                'theme-8',
                'theme-9',
                'theme-10',
            ])
        ) {
            $color = 'custom-color' ?? 'theme-3';
        } else {
            $color = $setting['color'] ?? 'theme-3';
        }
    }
    $menusettings = \Workdo\LandingPage\Entities\OwnerMenuSetting::where('created_by', $superadmin->id)->first();

    if (isset($menusettings) && $menusettings->menus_id) {
        $topNavItems = \Workdo\LandingPage\Entities\OwnerMenuSetting::get_ownernav_menu($menusettings->menus_id);
    }
?>
<!DOCTYPE html>
<html lang="en" dir="<?php echo e(isset($setting['SITE_RTL']) && $setting['SITE_RTL'] == 'on' ? 'rtl' : ''); ?>">

<head>
    <title>
        <?php echo e(\App\Models\Utility::GetValueByName('title_text', 1) ? \App\Models\Utility::GetValueByName('title_text', 1) : (env('APP_NAME') ?? 'Store Syria saas')); ?>

    </title>
    <!-- Meta -->
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="author" content="WorkDo.io" />
    <meta name="base-url" content="<?php echo e(URL::to('/')); ?>">

    <meta name="title" content="<?php echo e(isset($settings['metatitle']) ? $settings['metatitle'] : 'Store Syria'); ?>">
    <meta name="keywords" content="<?php echo e(isset($settings['metakeyword']) ? $settings['metakeyword'] : 'Store Syria, Store with Multi theme and Multi Store'); ?>">
    <meta name="description" content="<?php echo e(isset($settings['metadesc']) ? $settings['metadesc'] : 'Discover the efficiency of Store Syria, a user-friendly web application by Workdo.io.'); ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo e(env('APP_URL')); ?>">
    <meta property="og:title" content="<?php echo e(isset($settings['metatitle']) ? $settings['metatitle'] : 'Store Syria'); ?>">
    <meta property="og:description" content="<?php echo e(isset($settings['metadesc']) ? $settings['metadesc'] : 'Discover the efficiency of Store Syria, a user-friendly web application by Workdo.io.'); ?> ">
    <meta property="og:image" content="<?php echo e(get_file(isset($settings['metaimage']) ? $settings['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png')); ?><?php echo e('?'.time()); ?>">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo e(env('APP_URL')); ?>">
    <meta property="twitter:title" content="<?php echo e(isset($settings['metatitle']) ? $settings['metatitle'] : 'Store Syria'); ?>">
    <meta property="twitter:description" content="<?php echo e(isset($settings['metadesc']) ? $settings['metadesc'] : 'Discover the efficiency of Store Syria, a user-friendly web application by Workdo.io.'); ?> ">
    <meta property="twitter:image" content="<?php echo e(get_file(isset($settings['metaimage']) ? $settings['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png')); ?><?php echo e('?'.time()); ?>">

    <!-- Favicon icon -->
    <link rel="icon" href="<?php echo e(get_file($setting['favicon'] . '?timestamp=' . time())); ?>"
        type="image/x-icon" />

    <!-- font css -->
    <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/fonts/tabler-icons.min.css')); ?>" />
    <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/fonts/feather.css')); ?>" />
    <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/fonts/fontawesome.css')); ?>" />
    <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/fonts/material.css')); ?>" />

    <!-- vendor css -->
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($SITE_RTL == 'on' && isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on'): ?>
        <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/css/style-rtl.css')); ?>" />
        <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/css/style-dark.css')); ?>" />
    <?php elseif($SITE_RTL == 'on' && isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'off'): ?>
        <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/css/style-rtl.css')); ?>" />
    <?php elseif($SITE_RTL == 'off' && isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on'): ?>
        <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/css/style.css')); ?>" />
        <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/css/style-dark.css')); ?>" />
    <?php else: ?>
        <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/css/style.css')); ?>" />
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/css/customizer.css')); ?>" />
    <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/css/landing-page.css')); ?>" />
    <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/css/custom.css')); ?>" />
    <link rel="stylesheet" href="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/assets/swiper/dist/css/swiper-bundle.min.css')); ?>" />

    

    <link rel="stylesheet" href="<?php echo e(url('css/custom-color.css')); ?>" />
    <!-- notification css -->
    <link rel="stylesheet" href="<?php echo e(url('assets/css/plugins/notifier.css')); ?>" />

    <style>
        :root {
            --color-customColor: <?=$setting['color'] ?? 'linear-gradient(141.55deg, rgba(240, 244, 243, 0) 3.46%, #ffffff 99.86%)' ?>;
        }



body.custom-color .bg-primary{

background: linear-gradient(141.55deg, #6000ff 3.46%, #000 99.86%), #fff !important;

}

.container-offset{
padding:1.5rem !important;

}

.site-footer{


background: linear-gradient(44deg,rgba(96, 0, 255, 1) 0%, rgba(52, 32, 85, 1) 96%);
}
    </style>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Syria Platform</title>
    
    <!-- 1. الستايلات (Tailwind CSS CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- 2. السكربت الأساسي (Alpine.js للتبديل بين اللغات والتفاعلات) -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <!-- خطوط عربية احترافية -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        .transition-all { transition: all 0.3s ease; }
    </style>
</head>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on'): ?>

    <body class="<?php echo e($color); ?> landing-dark landing-page">
    <?php else: ?>

        <body class="<?php echo e($color); ?> landing-page">
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<!-- [ Header ] start -->
<header class="main-header">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($settings['topbar_status']) && $settings['topbar_status'] == 'on'): ?>
        <div class="announcement bg-dark text-center p-2">
            <p class="mb-0"><?php echo $settings['topbar_notification_msg']; ?></p>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($settings['menubar_status']) && $settings['menubar_status'] == 'on'): ?>
        <div class="container">
            <nav class="navbar navbar-expand-md  default top-nav-collapse">
                <div class="header-left custom-header-logo">
                    <a class="navbar-brand bg-transparent logo" href="<?php echo e(\URL::to('/')); ?>">
                        <img src="<?php echo e(file_exists($settings['site_logo']) ? get_file($settings['site_logo']) . '?timestamp=' . time() : $logo . '/' . $settings['site_logo'] . '?timestamp=' . time()); ?>" class="logo"
                            alt="logo">
                    </a>
                </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($menusettings) &&
                        isset($menusettings->menus_id) &&
                        $menusettings->enable_header == 'on' &&
                        !empty($topNavItems)): ?>
                    <div class="collapse navbar-collapse" id="navbarTogglerDemo01">
                        <ul class="lnding-menubar p-0 m-0 navbar-nav flex-column flex-md-row w-100">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $topNavItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $navGroup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <li class="menu-lnk has-item nav-item dropdown">
                                    <a class="dash-head-link nav-link" href="#" data-bs-toggle="dropdown">
                                        <span>
                                            <?php echo e($navGroup['name']); ?>

                                        </span>
                                        <i class="ti ti-chevron-down drp-arrow"></i>
                                    </a>
                                    <div class="menu-dropdown dropdown-menu">
                                        <ul class="p-0 m-0 list-unstyled">
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $navGroup['items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $nav): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nav->type == 'page'): ?>
                                                    <li class="lnk-itm">
                                                        <a href="<?php echo e(url('landing-pages' . '/' . $nav->slug)); ?>"
                                                            target="<?php echo e($nav->target); ?>" class="dropdown-item">
                                                            <span><?php echo e($nav->title); ?></span>
                                                        </a>
                                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($nav->children) && isset($nav->children)): ?>
                                                            <ul class="lnk-child">
                                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $nav->children[0]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $child): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($child)): ?>
                                                                        <li>
                                                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($child->type == 'page'): ?>
                                                                                <a href="<?php echo e(url('landing-pages' . '/' . $child->slug)); ?>"
                                                                                    target="<?php echo e($child->target); ?>"
                                                                                    class="dropdown-item">
                                                                                    <span><?php echo e($child->title); ?></span>
                                                                                </a>
                                                                            <?php else: ?>
                                                                                <a href="<?php echo e($child->slug); ?>"
                                                                                target="<?php echo e($child->target); ?>"
                                                                                class="dropdown-item">
                                                                                    <span><?php echo e($child->title); ?></span>
                                                                                </a>
                                                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                                        </li>
                                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                            </ul>
                                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    </li>
                                                <?php else: ?>
                                                    <li>
                                                        <a href="<?php echo e($nav->slug); ?>" target="<?php echo e($nav->target); ?>"
                                                            class="dropdown-item">
                                                            <span><?php echo e($nav->title); ?></span>
                                                        </a>
                                                        <?php if(!empty($nav->children)): ?>
                                                            <ul>
                                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $nav->children[0]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $child): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($child)): ?>
                                                                        <li>
                                                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($child->type == 'page'): ?>
                                                                                <a href="<?php echo e(url('landing-pages' . '/' . $child->slug)); ?>"
                                                                                    target="<?php echo e($child->target); ?>"
                                                                                    class="dropdown-item">
                                                                                    <span><?php echo e($child->title); ?></span>
                                                                                </a>
                                                                            <?php else: ?>
                                                                                <a href="<?php echo e($child->slug); ?>"
                                                                                target="<?php echo e($child->target); ?>"
                                                                                class="dropdown-item">
                                                                                    <span><?php echo e($child->title); ?></span>
                                                                                </a>
                                                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                                        </li>
                                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                            </ul>
                                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    </li>
                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </ul>
                                    </div>
                                </li>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </ul>
                        <button class="navbar-toggler bg-primary" type="button" data-bs-toggle="collapse"
                            data-bs-target="#navbarTogglerDemo01" aria-controls="navbarTogglerDemo01"
                            aria-expanded="false" aria-label="Toggle navigation">
                            <span class="navbar-toggler-icon"></span>
                        </button>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <div class="ms-auto d-flex justify-content-end gap-2">
                    <a href="<?php echo e(route('login')); ?>" class="btn btn-outline-light"><span
                            class="hide-mob me-2"><?php echo e(__('Login')); ?></span> <i data-feather="log-in"></i></a>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($setting['SIGNUP'] == 'on'): ?>
                        <a href="<?php echo e(route('register')); ?>" class="btn btn-outline-light"><span
                                class="hide-mob me-2"><?php echo e(__('Register')); ?></span> <i data-feather="user-check"></i></a>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <button class="navbar-toggler " type="button" data-bs-toggle="collapse"
                        data-bs-target="#navbarTogglerDemo01" aria-controls="navbarTogglerDemo01"
                        aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </nav>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

</header>
<!-- [ Header ] End -->
<!-- [ Banner ] start -->
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($settings['home_status']) && $settings['home_status'] == 'on'): ?>
    <section class="main-banner bg-primary" id="home">
        <div class="container-offset">
            <div class="row gy-3 g-0 align-items-center">
                <div class="col-xxl-4 col-md-6">
                    <span class="badge py-2 px-3 bg-white text-dark  fw-bold mb-3">
                        <?php echo e($settings['home_offer_text']); ?></span>
                    <h1 class="mb-3 text-white">
                   Store Syria with Multi theme and Multi Store 
                    </h1>
                    <h6 class="mb-0 text-white"><?php echo e($settings['home_description']); ?></h6>
                    <div class="d-flex gap-3 mt-4 banner-btn">
                     
                    </div>
                </div>

                <div class="col-xxl-8 col-md-6">
                    <div class="dash-preview">
                        <img class="img-fluid preview-img" src="<?php echo e(file_exists($settings['home_banner']) ? get_file($settings['home_banner']) : $logo . '/' . $settings['home_banner']); ?>"
                            alt="">
                    </div>
                </div>
            </div>
        </div>
        <div class="container">
            <div class="row g-0 gy-2 mt-4 align-items-center">
                <div class="col-xxl-3">
                    <p class="mb-0"><?php echo e(__('Trusted by')); ?> <b
                            class="fw-bold"><?php echo e($settings['home_trusted_by']); ?></b></p>
                </div>
                <div class="col-xxl-9">
                    <div class="row gy-3 row-cols-9">
              
                    </div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<!-- [ Banner ] start -->
<!-- [ features ] start -->

<!-- [ features ] start -->
<!-- [ element ] start -->


<!-- [ element ] end -->
<?php echo $__env->yieldPushContent('campaignsPage'); ?>
<!-- [ element ] start -->


<!-- [ element ] end -->
<!-- [ Screenshots ] start -->
<div x-data="{ lang: 'ar' }" :dir="lang === 'ar' ? 'rtl' : 'ltr'">
    
    <!-- زر تبديل اللغة 
    <div class="fixed top-20 right-5 z-50">
        <button @click="lang = (lang === 'ar' ? 'en' : 'ar')" class="bg-blue-600 text-white px-4 py-1 rounded shadow-lg text-sm font-bold">
            <span x-show="lang === 'ar'">English</span>
            <span x-show="lang === 'en'">عربي</span>
        </button>
    </div>
-->
    <!-- قسم الميزات -->
    <section class="py-20 bg-white">
        <div class="container mx-auto px-6">
            <h2 class="text-center text-4xl font-black mb-16" x-text="lang === 'ar' ? 'لماذا تختار Store Syria؟' : 'Why Choose Store Syria?'"></h2>
            
            <div class="grid md:grid-cols-3 gap-8">
                <!-- ميزة 1 -->
                <div class="p-8 border border-gray-100 rounded-3xl hover:border-blue-500 transition duration-300">
                    <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center mb-6 font-bold text-xl">01</div>
                    <h3 class="text-xl font-bold mb-4" x-text="lang === 'ar' ? 'سرعة فائقة' : 'Ultra Fast'"></h3>
                    <p class="text-gray-600" x-text="lang === 'ar' ? 'أداء خيالي بفضل هندسة السيرفرات المباشرة.' : 'Exceptional performance powered by native server engineering.'"></p>
                </div>
                
                <!-- ميزة 2 -->
                <div class="p-8 border border-gray-100 rounded-3xl hover:border-blue-500 transition duration-300">
                    <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center mb-6 font-bold text-xl">02</div>
                    <h3 class="text-xl font-bold mb-4" x-text="lang === 'ar' ? 'تخصيص كامل' : 'Full Customization'"></h3>
                    <p class="text-gray-600" x-text="lang === 'ar' ? 'تحكم في أدق تفاصيل متجرك الإلكتروني بسهولة.' : 'Complete control over every detail of your online store.'"></p>
                </div>

                <!-- ميزة 3 -->
                <div class="p-8 border border-gray-100 rounded-3xl hover:border-blue-500 transition duration-300">
                    <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center mb-6 font-bold text-xl">03</div>
                    <h3 class="text-xl font-bold mb-4" x-text="lang === 'ar' ? 'ربط ذكي' : 'Smart Integration'"></h3>
                    <p class="text-gray-600" x-text="lang === 'ar' ? 'ادمج Stripe و Firebase بضغطة زر واحدة.' : 'Seamless integration with Stripe and Firebase.'"></p>
                </div>
            </div>
        </div>
    </section>

    <!-- قسم آراء العملاء -->


<!-- [ Screenshots ] start -->
<!-- [ subscription ] start -->
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($settings['plan_status']) && $settings['plan_status']): ?>
    <section class="subscription bg-primary section-gap" id="plan">
        <div class="container">
            <div class="row mb-2 justify-content-center">
                <div class="col-xxl-6">
                    <div class="title text-center mb-4">
                        <span class="d-block mb-2 fw-bold text-uppercase"><?php echo e(__('PLAN')); ?></span>
                        <h2 class="mb-4"><?php echo $settings['plan_heading']; ?></h2>
                        <p><?php echo $settings['plan_description']; ?></p>
                    </div>
                </div>
            </div>
            <div class="row justify-content-center">
                <?php
                    $collection = \App\Models\Plan::where('is_disable', 1)->orderBy('price', 'asc')->get();
                ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $collection; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="col-xxl-3 col-lg-4 col-md-6 mb-5">
                        <div class="card price-card shadow-none bg-dark"
                            style="<?php echo e($key == 2 ? 'color:white !important' : ''); ?>"> 
                            <div class="card-body">
                                <span class="price-badge bg-dark"><?php echo e($value->name); ?></span>
                                <span
                                    class="f-w-600 p-price"><?php echo e(trim(default_currency_format_with_sym($value->price))); ?><small
                                        class="text-sm">/<?php echo e(__($value->duration)); ?></small></span>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($value->trial == '1'): ?>
                                    <p class="mb-0"><?php echo e(__('Free Trial Days : ')); ?><b><?php echo e($value->trial_days); ?></b>
                                    </p>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($value->description): ?>
                                <p class="mb-0">
                                    <?php echo $value->description; ?>

                                </p>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <ul class="list-unstyled mb-3">
                                    <li>
                                        <div class="form-check text-start">
                                            <label class="form-check-label"
                                                for="customCheckc1"><?php echo e($value->max_stores != -1 ? $value->max_stores . ' ' . __('Store') : __('Unlimited')); ?></label>
                                        </div>
                                    </li>
                                    <li>
                                        <div class="form-check text-start">
                                            <label class="form-check-label" for="customCheckc1">
                                                <?php echo e($value->max_products != -1 ? $value->max_products . __(' Store') : __('Unlimited')); ?>

                                                <?php echo e(__('Products')); ?></label>
                                        </div>
                                    </li>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($value->enable_domain == 'on'): ?>
                                        <li>
                                            <div class="form-check text-start">
                                                <label class="form-check-label"
                                                    for="customCheckc1"><?php echo e($value->enable_domain == 'on' ? __('Enable Custom Domain') : ''); ?></label>
                                            </div>
                                        </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($value->enable_subdomain == 'on'): ?>
                                    <li>
                                        <div class="form-check text-start">
                                            <label class="form-check-label"
                                                for="customCheckc1"><?php echo e($value->enable_subdomain == 'on' ? __('Enable Sub Domain') : ''); ?></label>
                                        </div>
                                    </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($value->pwa_store == 'on'): ?>
                                    <li>
                                        <div class="form-check text-start">
                                            <label class="form-check-label"
                                                for="customCheckc1"><?php echo e($value->pwa_store == 'on' ? __('Enable Progressive Web App (PWA)') : ''); ?></label>
                                        </div>
                                    </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($value->enable_chatgpt == 'on'): ?>
                                    <li>
                                        <div class="form-check text-start">
                                            <label class="form-check-label"
                                                for="customCheckc1"><?php echo e($value->enable_chatgpt == 'on' ? __('Enable Chatgpt') : ''); ?></label>
                                        </div>
                                    </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                </ul>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($setting['SIGNUP'] == 'on'): ?>
                                    <div class="d-grid">
                                        <a href="<?php echo e(route('register', ['plan_id' => \Illuminate\Support\Facades\Crypt::encrypt($value->id)])); ?>" class="btn btn-primary "><?php echo e(__('Start with Starter')); ?> <i data-feather="log-in" class="ms-2"></i> </a>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            </div>
        </div>
    </section>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<!-- [ subscription ] end -->
<!-- [ FAqs ] start -->
<section class="py-20 bg-gray-50">
    <div class="container mx-auto px-6 max-w-3xl" x-data="{ open: 1 }">
        <!-- العنوان -->
        <h2 class="text-center text-4xl font-black mb-12" 
            x-text="lang === 'ar' ? 'أسئلة شائعة حول المنصة' : 'Frequently Asked Questions'">
        </h2>

        <div class="space-y-4">
            <!-- السؤال 1 -->
            <div class="bg-white p-6 rounded-2xl border cursor-pointer shadow-sm" @click="open = 1">
                <div class="flex justify-between font-bold text-lg">
                    <span x-text="lang === 'ar' ? 'هل أحتاج لخبرة برمجية للبدء؟' : 'Do I need coding experience?'"></span>
                    <span>+</span>
                </div>
                <div x-show="open === 1" class="mt-4 text-gray-600" x-cloak>
                    <span x-text="lang === 'ar' ? 'لا، المنصة موجهة لأصحاب الأعمال وتدعم الإدارة المباشرة دون الحاجة لخبرة تقنية.' : 'No, the platform is designed for business owners and supports direct management without technical expertise.'"></span>
                </div>
            </div>

            <!-- السؤال 2 -->
            <div class="bg-white p-6 rounded-2xl border cursor-pointer shadow-sm" @click="open = 2">
                <div class="flex justify-between font-bold text-lg">
                    <span x-text="lang === 'ar' ? 'هل يمكن ربط بوابات دفع عالمية؟' : 'Can I connect global payment gateways?'"></span>
                    <span>+</span>
                </div>
                <div x-show="open === 2" class="mt-4 text-gray-600" x-cloak>
                    <span x-text="lang === 'ar' ? 'نعم، ندعم Stripe والعديد من البوابات العالمية والمحلية بكفاءة عالية.' : 'Yes, we support Stripe and many global and local gateways with high efficiency.'"></span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- [ FAqs ] end -->
<!-- [ testimonial ] start -->

    
    <!-- زر تبديل اللغة -->

    <!-- قسم الميزات -->
 

    <!-- قسم آراء العملاء -->
    <section class="py-20 bg-gray-50">
        <div class="container mx-auto px-6">
            <h2 class="text-center text-4xl font-black mb-16" x-text="lang === 'ar' ? 'قصص نجاح من Store Syria' : 'Success Stories from Store Syria'"></h2>
            
            <div class="grid md:grid-cols-2 gap-8">
                <!-- رأي 1 -->
                <div class="bg-white p-8 rounded-2xl shadow-sm border border-gray-100">
                    <p class="text-gray-700 italic mb-6" x-text="lang === 'ar' ? 'أفضل منصة تعاملت معها، السرعة والاستقرار غير مسبوقين!' : 'The best platform I have ever used, the speed and stability are unmatched!'"></p>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-gray-200 rounded-full"></div>
                        <div>
                            <h4 class="font-bold">أحمد خالد</h4>
                            <p class="text-sm text-gray-500">متجر إلكتروني</p>
                        </div>
                    </div>
                </div>

                <!-- رأي 2 -->
                <div class="bg-white p-8 rounded-2xl shadow-sm border border-gray-100">
                    <p class="text-gray-700 italic mb-6" x-text="lang === 'ar' ? 'وفرت علينا الكثير من العناء في إدارة عمليات الشحن والطلبات.' : 'It saved us so much trouble in managing our shipping and order processes.'"></p>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-gray-200 rounded-full"></div>
                        <div>
                            <h4 class="font-bold">سارة مروان</h4>
                            <p class="text-sm text-gray-500">منصة تجزئة</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>


<!-- [ testimonial ] end -->
<!-- [ Footer ] start -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-row">
            <div class="ftr-col cmp-detail">
                <div class="footer-logo mb-3">
                    <a href="#">
                        <img src="<?php echo e(file_exists($settings['site_logo']) ? get_file($settings['site_logo']) . '?timestamp=' . time() : $logo . '/' . $settings['site_logo'] . '?timestamp=' . time()); ?>"
                            alt="logo">
                    </a>
                </div>
                <p>
                    <?php echo $settings['site_description']; ?>

                </p>

            </div>
            <?php if(isset($menusettings) && isset($menusettings->menus_id) && $menusettings->enable_footer == 'on'): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $topNavItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $navGroup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="ftr-col">
                        <ul class="list-unstyled">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $navGroup['items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $nav): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nav->type == 'page'): ?>
                                    <li>
                                        <a href="<?php echo e(url('landing-pages' . '/' . $nav->slug)); ?>"
                                            target="<?php echo e($nav->target); ?>"><?php echo e($nav->title); ?></a>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($nav->children) && isset($nav->children)): ?>
                                            <ul class="lnk-child">
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $nav->children[0]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $child): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($child)): ?>
                                                        <li>
                                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($child->type == 'page'): ?>
                                                                <a href="<?php echo e(url('landing-pages' . '/' . $child->slug)); ?>"
                                                                    target="<?php echo e($child->target); ?>"
                                                                    class="dropdown-item">
                                                                    <span><?php echo e($child->title); ?></span>
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="<?php echo e($child->slug); ?>"
                                                                target="<?php echo e($child->target); ?>"
                                                                class="dropdown-item">
                                                                    <span><?php echo e($child->title); ?></span>
                                                                </a>
                                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                        </li>
                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </ul>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </li>
                                <?php else: ?>
                                    <li>
                                        <a href="<?php echo e($nav->slug); ?>"
                                            target="<?php echo e($nav->target); ?>"><?php echo e($nav->title); ?></a>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($nav->children) && isset($nav->children)): ?>
                                            <ul class="lnk-child">
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $nav->children[0]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $child): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($child)): ?>
                                                        <li>
                                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($child->type == 'page'): ?>
                                                                <a href="<?php echo e(url('landing-pages' . '/' . $child->slug)); ?>"
                                                                    target="<?php echo e($child->target); ?>"
                                                                    class="dropdown-item">
                                                                    <span><?php echo e($child->title); ?></span>
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="<?php echo e($child->slug); ?>"
                                                                target="<?php echo e($child->target); ?>"
                                                                class="dropdown-item">
                                                                    <span><?php echo e($child->title); ?></span>
                                                                </a>
                                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                        </li>
                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </ul>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </li>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </ul>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($settings['joinus_status'] == 'on'): ?>
                <div class="ftr-col ftr-subscribe">
                    <h2><?php echo $settings['joinus_heading']; ?></h2>
                    <p><?php echo $settings['joinus_description']; ?></p>
                    <form method="post" action="<?php echo e(route('join_us_store')); ?>">
                        <?php echo csrf_field(); ?>
                        <div class="input-wrapper border border-dark">
                            <input type="text" name="email" placeholder="Type your email address...">
                            <button type="submit" class="btn btn-dark btn-primary"><?php echo e(__('Join Us!')); ?></button>
                        </div>
                    </form>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>
    <div class="border-top border-dark text-center p-2">
        

        <p class="mb-0">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['footer_text']) &&
                    (strpos($setting['footer_text'], '©') === false && strpos($setting['footer_text'], '&copy;') === false)): ?>
                &copy;
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php echo e(date('Y')); ?>

            <?php echo e(isset($setting['footer_text']) ? $setting['footer_text'] : config('app.name', 'E-CommerceGo')); ?>

        </p>


    </div>
</footer>


</div>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['enable_cookie']) && $setting['enable_cookie'] == 'on'): ?>
    <?php echo $__env->make('layouts.cookie_consent', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<!-- [ Footer ] end -->
<!-- Required Js -->

<script src="<?php echo e(url('js/jquery-3.6.0.min.js')); ?>"></script>
<script src="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/js/plugins/popper.min.js')); ?>"></script>
<script src="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/js/plugins/bootstrap.min.js')); ?>"></script>
<script src="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/js/plugins/feather.min.js')); ?>"></script>
<script src="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/js/plugins/notifier.js')); ?>"></script>
<script src="<?php echo e(url('packages/workdo/LandingPage/src/Resources/assets/assets/swiper/dist/js/swiper-bundle.min.js')); ?>"></script>
<script src="<?php echo e(url('js/custom.js')); ?><?php echo e('?' . time()); ?>"></script>
<script>
    // Start [ Menu hide/show on scroll ]
    let ost = 0;
    document.addEventListener("scroll", function() {
        let cOst = document.documentElement.scrollTop;
        if (cOst == 0) {
            document.querySelector(".navbar").classList.add("top-nav-collapse");
        } else if (cOst > ost) {
            document.querySelector(".navbar").classList.add("top-nav-collapse");
            document.querySelector(".navbar").classList.remove("default");
        } else {
            document.querySelector(".navbar").classList.add("default");
            document
                .querySelector(".navbar")
                .classList.remove("top-nav-collapse");
        }
        ost = cOst;
    });
    // End [ Menu hide/show on scroll ]

    var scrollSpy = new bootstrap.ScrollSpy(document.body, {
        target: "#navbar-example",
    });
    feather.replace();
</script>
<script>
    // Function to show or hide navigation arrows based on the number of slides
    function toggleNavigationArrows(swiper) {
    const slideCount = swiper.slides.length;
    const nextEl = document.querySelector('.swiper-button-next');
    const prevEl = document.querySelector('.swiper-button-prev');
    if (slideCount <= swiper.params.slidesPerView) {
    nextEl.style.display = 'none';
    prevEl.style.display = 'none';
    } else {
    nextEl.style.display = '';
    prevEl.style.display = '';
    }
    }

    // Initialize Swiper
    var swiper = new Swiper('.campaign-slider', {
    spaceBetween: 15,
    mousewheel: false,
    keyboard: {
    enabled: true
    },
    breakpoints: {
    1199: {
    slidesPerView: 4,
    },
    991: {
    slidesPerView: 3,
    },
    768: {
    slidesPerView: 2,
    },
    0: {
    slidesPerView: 1,
    }
    },
    navigation: {
    nextEl: ".swiper-button-next",
    prevEl: ".swiper-button-prev"
    },
    on: {
    init: function () {
    toggleNavigationArrows(this);
    },
    resize: function () {
    toggleNavigationArrows(this);
    }
    }
    });

    // Initialize Feather icons
    feather.replace();
</script>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($message = Session::get('success')): ?>

    <script>
        var site_url = $('meta[name="base-url"]').attr('content');
        show_toastr('<?php echo e(__('Success')); ?>', '<?php echo $message; ?>', 'success')
    </script>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($message = Session::get('error')): ?>
    <script>
        var site_url = $('meta[name="base-url"]').attr('content');
        show_toastr('<?php echo e(__('Error')); ?>', '<?php echo $message; ?>', 'error')
    </script>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</body>

</html><?php /**PATH /home/storesyria/public_html/packages/workdo/LandingPage/src/Providers/../Resources/views/layouts/landingpage.blade.php ENDPATH**/ ?>