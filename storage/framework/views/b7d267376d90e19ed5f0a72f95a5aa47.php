<?php

    use App\Models\Utility;
    $settings = \Workdo\LandingPage\Entities\LandingPageSetting::settings();
    $logo = get_file('storage/uploads/landing_page_image');
    $sup_logo = get_file('storage/uploads/logo');
    $setting = getSuperAdminAllSetting();
    $SITE_RTL = Cookie::get('SITE_RTL');
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
    $cust_darklayout = Cookie::get('cust_darklayout');
    if ($cust_darklayout == '') {
        $setting['cust_darklayout'] = 'off';
    }
    $cust_theme_bg = Cookie::get('cust_theme_bg');
    if ($cust_theme_bg == '') {
        $setting['cust_theme_bg'] = 'on';
    }
    $menusettings = \Workdo\LandingPage\Entities\OwnerMenuSetting::where('created_by', 1)->first();

    if (isset($menusettings) && $menusettings->menus_id) {
        $topNavItems = \Workdo\LandingPage\Entities\OwnerMenuSetting::get_ownernav_menu($menusettings->menus_id);
    }
?>
<!DOCTYPE html>
<html lang="en" dir="<?php echo e($setting['SITE_RTL'] == 'on' ? 'rtl' : ''); ?>">

<head>

    <title><?php echo e(!empty($setting->title_text) ? $setting->title_text : $page['menubar_page_name']); ?></title>

    <!-- Meta -->
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="author" content="WorkDo.io" />

    <meta name="title" content="<?php echo e(isset($settings['metatitle']) ? $settings['metatitle'] : 'EcommerceGo'); ?>">
    <meta name="keywords" content="<?php echo e(isset($settings['metakeyword']) ? $settings['metakeyword'] : 'EcommerceGo, Store with Multi theme and Multi Store'); ?>">
    <meta name="description" content="<?php echo e(isset($settings['metadesc']) ? $settings['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.'); ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo e(env('APP_URL')); ?>">
    <meta property="og:title" content="<?php echo e(isset($settings['metatitle']) ? $settings['metatitle'] : 'EcommerceGo'); ?>">
    <meta property="og:description" content="<?php echo e(isset($settings['metadesc']) ? $settings['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.'); ?> ">
    <meta property="og:image" content="<?php echo e(get_file(isset($settings['metaimage']) ? $settings['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png')); ?><?php echo e('?'.time()); ?>">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo e(env('APP_URL')); ?>">
    <meta property="twitter:title" content="<?php echo e(isset($settings['metatitle']) ? $settings['metatitle'] : 'EcommerceGo'); ?>">
    <meta property="twitter:description" content="<?php echo e(isset($settings['metadesc']) ? $settings['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.'); ?> ">
    <meta property="twitter:image" content="<?php echo e(get_file(isset($settings['metaimage']) ? $settings['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png')); ?><?php echo e('?'.time()); ?>">
    <!-- Favicon icon -->
    <link rel="icon" href="<?php echo e(get_file($setting['favicon'] . '?timestamp=' . time())); ?>"
        type="image/x-icon" />

    <!-- font css -->
    <link rel="stylesheet" href=" <?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/fonts/tabler-icons.min.css')); ?>" />
    <link rel="stylesheet" href=" <?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/fonts/feather.css')); ?>" />
    <link rel="stylesheet" href="  <?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/fonts/fontawesome.css')); ?>" />
    <link rel="stylesheet" href="<?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/fonts/material.css')); ?>" />

    <!-- vendor css -->
    <link rel="stylesheet" href="  <?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/css/style.css')); ?>" />
    <link rel="stylesheet" href=" <?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/css/customizer.css')); ?>" />
    <link rel="stylesheet" href=" <?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/css/landing-page.css')); ?>" />
    <link rel="stylesheet" href=" <?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/css/custom.css')); ?>" />

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($SITE_RTL) && $SITE_RTL == 'on'): ?>
        <link rel="stylesheet" href="<?php echo e(asset('assets/css/style-rtl.css')); ?>">
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on'): ?>
        <link rel="stylesheet" href="<?php echo e(asset('assets/css/style-dark.css')); ?>">
    <?php else: ?>
        <link rel="stylesheet" href="<?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/css/style.css')); ?>"
            id="main-style-link">
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <link rel="stylesheet" href="<?php echo e(asset('css/custom-color.css')); ?>">
    <style>
        :root {
            --color-customColor: <?=$setting['color'] ?? 'linear-gradient(141.55deg, rgba(240, 244, 243, 0) 3.46%, #ffffff 99.86%)' ?>;
        }
    </style>
</head>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on'): ?>

    <body class="<?php echo e($color); ?> landing-dark">
    <?php else: ?>

        <body class="<?php echo e($color); ?>">
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<!-- [ Header ] start -->
<header class="main-header">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($settings['topbar_status'] == 'on'): ?>
        <div class="announcement bg-dark text-center p-2">
            <p class="mb-0"><?php echo $settings['topbar_notification_msg']; ?></p>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($settings['menubar_status'] == 'on'): ?>
        <div class="container">
            <nav class="navbar navbar-expand-md  default top-nav-collapse">
                <div class="header-left">
                    <a class="navbar-brand bg-transparent" href="<?php echo e(url('/')); ?>">
                        <img src="<?php echo e(file_exists($settings['site_logo']) ? get_file($settings['site_logo']) . '?timestamp=' . time() : $logo . '/' . $settings['site_logo'] . '?timestamp=' . time()); ?>" alt="logo">
                    </a>
                </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($menusettings) &&
                        isset($menusettings->menus_id) &&
                        $menusettings->enable_header == 'on' &&
                        !empty($topNavItems)): ?>
                    <div class="collapse navbar-collapse" id="navbarTogglerDemo01">
                        <ul class="lnding-menubar p-0 m-0">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $topNavItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $navGroup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <li class="menu-lnk has-item">
                                    <a class="dash-head-link" href="#">
                                        <span>
                                            <?php echo e($navGroup['name']); ?>

                                        </span>
                                        <i class="ti ti-chevron-down drp-arrow"></i>
                                    </a>
                                    <div class="menu-dropdown">
                                        <ul class="p-0 m-0">
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
                    <a href="<?php echo e(route('login')); ?>" class="btn btn-outline-dark rounded"><span
                            class="hide-mob me-2"><?php echo e(__('Login')); ?></span> <i data-feather="log-in"></i></a>
                    <a href="<?php echo e(route('register')); ?>" class="btn btn-outline-dark rounded"><span
                            class="hide-mob me-2"><?php echo e(__('Register')); ?></span> <i data-feather="user-check"></i></a>
                    <button class="navbar-toggler " type="button" data-bs-toggle="collapse"
                        data-bs-target="#navbarTogglerDemo01" aria-controls="navbarTogglerDemo01" aria-expanded="false"
                        aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </nav>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</header>

<!-- [ Header ] End -->
<!-- [ common banner ] start -->
<section class="common-banner bg-primary">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-4">
                <div class="title">
                    <h1 class=""><?php echo $page['menubar_page_name']; ?></h1>
                </div>
            </div>

        </div>
    </div>
</section>
<!-- [ common banner ] end -->
<!-- [ Static content ] start -->

<section class="static-content section-gap">
    <div class="container">
        <div class="mb-5">
            <?php echo $page['menubar_page_contant']; ?>

        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($settings['testimonials_status'] == 'on'): ?>
            <?php
                $testimonials = json_decode($settings['testimonials'], true);

                // Ensure testimonials are a non-empty array
                if (is_array($testimonials) && !empty($testimonials)) {
                    // Get a random testimonial
                    $randomKey = array_rand($testimonials, 1);
                    $testimonial = (object) $testimonials[$randomKey];
                } else {
                    $testimonial = null;
                }
            ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($testimonial): ?>
                <div>
                    <div class="row gy-4">
                        <div class="col-12">
                            <div class="bg-primary p-4 rounded">
                                <div class="row gy-3 align-items-center">
                                    <div class="col-xxl-6 col-lg-6">
                                        <div class="d-flex flex-column flex-sm-row gap-3">
                                            <span class="theme-avtar avtar avtar-xl bg-light-dark rounded-1">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="23"
                                                    viewBox="0 0 36 23" fill="none">
                                                    <path
                                                        d="M12.4728 22.6171H0.770508L10.6797 0.15625H18.2296L12.4728 22.6171ZM29.46 22.6171H17.7577L27.6669 0.15625H35.2168L29.46 22.6171Z"
                                                        fill="white"></path>
                                                </svg>
                                            </span>
                                            <div>
                                                <h2><?php echo $testimonial->testimonials_title; ?></h2>
                                                <p class="mb-0"><?php echo $testimonial->testimonials_description; ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xxl-6 col-lg-6">
                                        <div
                                            class="d-flex align-items-center gap-3 justify-content-center justify-content-sm-end">
                                            <div class="text-end">
                                                <b class="d-block"><?php echo e($testimonial->testimonials_user); ?></b>
                                                <span class="d-block"><?php echo $testimonial->testimonials_designation; ?></span>
                                                <span>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($i = 1; $i <= (int) $testimonial->testimonials_star; $i++): ?>
                                                        <i data-feather="star"></i>
                                                    <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                </span>
                                            </div>
                                            <span class="theme-avtar avtar avtar-l rounded-circle">
                                                <img src="<?php echo e(file_exists($testimonial->testimonials_user_avtar) ? get_file($testimonial->testimonials_user_avtar) : $logo . '/' . $testimonial->testimonials_user_avtar); ?>"
                                                    class="img-fluid rounded-circle" alt="">
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</section>

<!-- [ Static content ] end -->
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
                            <button type="submit" class="btn btn-primary btn-dark"><?php echo e(__('Join Us!')); ?></button>
                        </div>
                    </form>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>
    <div class="border-top border-dark text-center p-2">
        



        <p class="mb-0"> &copy; <?php echo e(date('Y')); ?>

            <?php echo e($setting['footer_text'] ? $setting['footer_text'] : config('app.name', 'E-CommerceGo')); ?>

        </p>


    </div>
</footer>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($setting['enable_cookie'] == 'on'): ?>
    <?php echo $__env->make('layouts.cookie_consent', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<!-- [ Footer ] end -->
<!-- Required Js -->

<script src="<?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/js/plugins/popper.min.js')); ?>"></script>
<script src="<?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/js/plugins/bootstrap.min.js')); ?>"></script>
<script src="<?php echo e(asset('packages/workdo/LandingPage/src/Resources/assets/js/plugins/feather.min.js')); ?>"></script>


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
</body>

</html>
<?php /**PATH /home/storesyria/public_html/packages/workdo/LandingPage/src/Providers/../Resources/views/layouts/custompage.blade.php ENDPATH**/ ?>