<?php
    if(auth()->user() && auth()->user()->type == 'admin') {
        $setting = getAdminAllSetting();
        $supperadminSetting = \App\Models\Setting::where('name', 'disable_lang')->where('created_by', 1)->pluck('value', 'name')->toArray();
        $setting['disable_lang'] = $supperadminSetting['disable_lang'] ?? null;
    } else {
        $setting = getSuperAdminAllSetting();
    }
    $SuperadminData = getSuperAdminAllSetting();
    $cust_darklayout = \App\Models\Utility::GetValueByName('cust_darklayout');
    if ($cust_darklayout == '') {
        $setting['cust_darklayout'] = 'off';
    }

    $cust_theme_bg = \App\Models\Utility::GetValueByName('cust_theme_bg');
    if($cust_theme_bg == ''){
        $setting['cust_theme_bg'] = 'on';
    }

    $SITE_RTL = \App\Models\Utility::GetValueByName('SITE_RTL');
    if($SITE_RTL == ''){
        $setting['SITE_RTL'] = 'off';
    }

    if (!isset($setting['color'])) {
        $themeColor = 'theme-3';
        $color = 'theme-3';
    } elseif (isset($setting['color_flag']) && $setting['color_flag'] == 'true') {
        $themeColor = 'custom-color';
        $color = $setting['color'];
    } else {
        if (!in_array($setting['color'], ['theme-1','theme-2','theme-3','theme-4','theme-5','theme-6','theme-7','theme-8','theme-9','theme-10'])) {
            $themeColor = 'custom-color' ?? 'theme-3';
            $color = $setting['color'];
        } else {
            $themeColor = $setting['color'] ?? 'theme-3';
            $color = $setting['color'];
        }

    }

    if(auth()->user() && auth()->user()->language) {
        $setting['currantLang'] = auth()->user()->language;
    } else {
        $setting['currantLang'] = 'en';
    }

    if ($setting['currantLang'] == 'ar' || $setting['currantLang'] == 'he') {
        $setting['SITE_RTL'] = 'on';
    }
?>

<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', ($setting['currantLang'] ?? app()->getLocale()))); ?>" dir="<?php echo e(isset($setting['SITE_RTL']) && $setting['SITE_RTL'] == 'on'? 'rtl' : ''); ?>" id="html-dir-tag">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="author" content="WorkDo.io" />
    <meta name="base-url" content="<?php echo e(URL::to('/')); ?>">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

    <meta name="title" content="<?php echo e(isset($SuperadminData['metatitle']) ? $SuperadminData['metatitle'] : 'EcommerceGo'); ?>">
    <meta name="keywords" content="<?php echo e(isset($SuperadminData['metakeyword']) ? $SuperadminData['metakeyword'] : 'EcommerceGo, Store with Multi theme and Multi Store'); ?>">
    <meta name="description" content="<?php echo e(isset($SuperadminData['metadesc']) ? $SuperadminData['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.'); ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo e(env('APP_URL')); ?>">
    <meta property="og:title" content="<?php echo e(isset($SuperadminData['metatitle']) ? $SuperadminData['metatitle'] : 'EcommerceGo'); ?>">
    <meta property="og:description" content="<?php echo e(isset($SuperadminData['metadesc']) ? $SuperadminData['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.'); ?> ">
    <meta property="og:image" content="<?php echo e(get_file(isset($SuperadminData['metaimage']) ? $SuperadminData['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png')); ?><?php echo e('?'.time()); ?>">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo e(env('APP_URL')); ?>">
    <meta property="twitter:title" content="<?php echo e(isset($SuperadminData['metatitle']) ? $SuperadminData['metatitle'] : 'EcommerceGo'); ?>">
    <meta property="twitter:description" content="<?php echo e(isset($SuperadminData['metadesc']) ? $SuperadminData['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.'); ?> ">
    <meta property="twitter:image" content="<?php echo e(get_file(isset($SuperadminData['metaimage']) ? $SuperadminData['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png')); ?><?php echo e('?'.time()); ?>">

    <title><?php echo e(isset($setting['title_text']) ? $setting['title_text'] : ( env('APP_NAME') ?? 'Ecommercego saas')); ?> - <?php echo $__env->yieldContent('page-title'); ?> </title>

    <link rel="stylesheet" href="<?php echo e(asset('assets/css/plugins/select2.min.css')); ?>">
    <!-- Favicon icon -->
    <link rel="icon" href="<?php echo e(isset($setting['favicon']) ? get_file($setting['favicon']) . '?timestamp=' . time() : asset(Storage::url('uploads/logo/favicon.png')) . '?timestamp=' . time()); ?>" type="image/x-icon" />

    <!-- notification css -->
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/plugins/notifier.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/plugins/bootstrap-switch-button.min.css')); ?>">
    <!-- datatable css -->
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/plugins/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('css/custom-color.css')); ?>">
    <!-- Fonts -->
    <!-- font css -->
    <link rel="stylesheet" href="<?php echo e(asset('/assets/fonts/tabler-icons.min.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('/assets/fonts/feather.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('/assets/fonts/fontawesome.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('/assets/fonts/material.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/customizer.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('public/assets/css/plugins/dropzone.css')); ?>" type="text/css" />

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['cust_darklayout']) && isset($setting['SITE_RTL']) && $setting['cust_darklayout'] == 'on' && $setting['SITE_RTL'] == 'on'): ?>
        <link rel="stylesheet" href="<?php echo e(asset('public/assets/css/rtl-style-dark.css')); ?>" id="main-style-link">
        <link rel="stylesheet" href="<?php echo e(asset('css/rtl-custom.css')); ?><?php echo e('?v=' . time()); ?>"  id="main-style-custom-link">
        <link rel="stylesheet" href="<?php echo e(asset('css/rtl-loader.css')); ?><?php echo e('?v=' . time()); ?>" >
    <?php elseif(isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on'): ?>
        <link rel="stylesheet" href="<?php echo e(asset('assets/css/style-dark.css')); ?>" id="main-style-link">
        <link rel="stylesheet" href="<?php echo e(asset('css/custom.css')); ?><?php echo e('?v=' . time()); ?>"  id="main-style-custom-link">
        <link rel="stylesheet" href="<?php echo e(asset('css/loader.css')); ?><?php echo e('?v=' . time()); ?>" >
    <?php elseif(isset($setting['SITE_RTL']) && $setting['SITE_RTL'] == 'on'): ?>
        <link rel="stylesheet" href="<?php echo e(asset('assets/css/style-rtl.css')); ?>" id="main-style-link">
        <link rel="stylesheet" href="<?php echo e(asset('css/rtl-custom.css')); ?><?php echo e('?v=' . time()); ?>"  id="main-style-custom-link">
        <link rel="stylesheet" href="<?php echo e(asset('css/rtl-loader.css')); ?><?php echo e('?v=' . time()); ?>" >
    <?php else: ?>
        <link rel="stylesheet" href="<?php echo e(asset('assets/css/style.css')); ?>" id="main-style-link">
        <link rel="stylesheet" href="<?php echo e(asset('css/custom.css')); ?><?php echo e('?v=' . time()); ?>"  id="main-style-custom-link">
        <link rel="stylesheet" href="<?php echo e(asset('css/loader.css')); ?><?php echo e('?v=' . time()); ?>" >
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <link rel="stylesheet" href="<?php echo e(asset('assets/css/plugins/flatpickr.min.css')); ?>">

    <link rel="stylesheet" href="<?php echo e(asset('assets/css/summernote/summernote-bs4.css')); ?>">



    <link rel="stylesheet" href="<?php echo e(asset('css/emojionearea.min.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('css/calendar.css')); ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>


    <!-- Scripts -->

    <style>
        <?php echo isset($setting['storecss']) ? $setting['storecss'] :  ''; ?>


        :root {
            --color-customColor: <?= $color ?? 'linear-gradient(141.55deg, rgba(240, 244, 243, 0) 3.46%, #ffffff 99.86%)' ?>;
            --bs-custom-color-border: <?= $color ?? '#ffff' ?>;
        }

        #storeList {
            display: none;
        }

        .dropdown-menu.show #storeList {
            display: block;
        }
        .select2-container {
            width: 100% !important;
        }
        .select2-container--default .select2-results__option--highlighted[aria-selected] {

        }
        .note-link-unlink-sample #commanModel{
            display: none !important;
        }
        .note-modal-footer {
            height: 50px;
        }

    </style>
     <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(app()->getLocale() == 'ar' || app()->getLocale() == 'he'): ?>
        <style>
            .select2-selection__rendered {
                float : right;
            }

        </style>
    <?php else: ?>
        <style>
        .select2-selection__rendered {
            float : left;
        }
        </style>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php echo $__env->yieldPushContent('css'); ?>

    <?php echo isset($setting['storejs']) ? $setting['storejs'] :  ''; ?>


</head>

<body class="<?php echo e($themeColor ?? 'theme-3'); ?>">
    <?php echo $__env->make('partision.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <?php echo $__env->make('partision.header', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <!-- [ Main Content ] start -->
    <div class="dash-container">
        <div class="dash-content">
           <!-- [ breadcrumb ] start -->
           <div class="page-header mb-md-4 mb-3">
                <div class="page-block d-flex align-items-center justify-content-between gap-3 flex-wrap">
                        <div class="page-header-wrp">
                            <div class="page-header-title">
                                <h2 class="h3 mb-0"><?php echo $__env->yieldContent('page-title'); ?></h4>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\Request::route()->getName() != 'dashboard'): ?>
                            <ul class="breadcrumb mt-2">
                                <li class="breadcrumb-item">
                                        <a href="<?php echo e(route('dashboard')); ?>"><?php echo e(__('Home')); ?></a>
                                    </li>
                                    <?php echo $__env->yieldContent('breadcrumb'); ?>
                            </ul>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <div class="page-header-icon d-flex flex-wrap gap-2">
                            <?php echo $__env->yieldContent('action-button'); ?>
                        </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->
            <?php echo $__env->yieldContent('content'); ?>
        </div>
    </div>
    <!-- [ Main Content ] end -->

    <?php if(\Request::route()->getName() != 'pos.index'): ?>
        <div id="commanModel" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modelCommanModelLabel"
            aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content ">
                    <div class="modal-header">
                        <h4 class="modal-title" id="modelCommanModelLabel"></h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body"></div>
                </div>
            </div>
        </div>

        <div id="commanModelOver" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modelCommanModelLabel"
        aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content ">
                    <div class="modal-header">
                        <h4 class="modal-title" id="modelCommanModelLabel"></h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body"></div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="modal fade" id="commonModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>


    <div id="loader" class="loader-wrapper" style="display: none;">
        <span class="site-loader"> </span>
        <h3 class="loader-content"> <?php echo e(__('Loading . . .')); ?> </h3>
    </div>

    <?php echo $__env->make('partision.settingPopup', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php echo $__env->make('partision.footerlink', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php echo $__env->yieldPushContent('scripts'); ?>
    <?php echo $__env->yieldPushContent('custom-script'); ?>
    <?php echo $__env->yieldPushContent('custom-script1'); ?>
    <script type="text/javascript">
        function setActiveTheme(themeColor) {
            // Update the --active-theme-border variable based on the theme number
            document.documentElement.style.setProperty('--active-theme-border', `var(--bs-${themeColor}-border)`);
        }
        setActiveTheme("<?php echo e($themeColor); ?>");
        $(document).ready(function(){
            if ($('.select2').length > 0) {
                $('.select2').select2({
                    tags: true,
                    createTag: function (params) {
                      var term = $.trim(params.term);
                      if (term === '') {
                        return null;
                      }
                      return {
                        id: term,
                        text: term,
                        newTag: true
                      };
                    }
                });
            }
        })
        </script>
    <script>
        function add_more_choice_option(i, name) {
            // Create a safe CSS selector by removing special characters
            var safe_i = i.toString().replace(/[^a-zA-Z0-9_-]/g, '_');

        $('#attribute_options').append(
            '<div class="card oprtion"><div class="card-body "><input type="hidden" class="abd" name="attribute_no[]" value="' +
            i + '"><input type="hidden" class="abd" name="option_no[]" value="' + i + '">' +

            '<div class="row">' +
            '<div class="form-group col-lg-6 col-12">' +
            '<label for="choice_attributes" class="col-6">' + name + ':</label></div>' +

            '<div class="form-group col-lg-6 col-12 d-flex justify-content-end all-button-box">' +
            '<a href="#" class="btn btn-sm btn-primary add_attribute btn-badge" data-ajax-popup="true" data-title="<?php echo e(__('Add Attribute Option')); ?>" data-size="md" ' +
            'data-url="<?php echo e(route('product-attribute-option.create')); ?>/' + i + '" ' +
            'data-toggle="tooltip">' +
            '<i class="ti ti-plus"><?php echo e(__('Add Attribute Option')); ?></i></a></div></div>' +

            '<div class="row parent-clase">' +
            '<div class="form-group col-12">' +
            '<div class="form-chec1k form-switch p-0">' +
            '<input type="hidden" name="visible_attribute_' + i + '" value="0">' +
            '<input type="checkbox" class="form-check-input attribute-form-check" name="visible_attribute_' + i +
            '" id="visible_attribute_' + safe_i + '" value="1">' +
            '<label class="form-check-label" for="visible_attribute_' + safe_i + '"></label>' +
            '<label for="visible_attribute_' + safe_i + '" class=""> Visible on the product page</label></div>' +

            ' <div style="margin-top: 9px;"></div>' +

            '<div class="for-variation_data form-chec1k form-switch p-0 d-none use_for_variation" id="use_for_variation_' + safe_i + '"  data-id="' +
            i + '">' +
            '<input type="hidden" name="for_variation_' + i + '" value="0">' +
            '<input type="checkbox" class="form-check-input input-options attribute-form-check enable_variation_' +
            safe_i + '" name="for_variation_' + i + '" id="for_variation_' + safe_i + '" value="1" data-id="' + i +
            '" data-enable-variation=" enable_variation_' + safe_i + ' ">' +
            '<label class="form-check-label" for="for_variation_' + safe_i + '"></label>' +
            '<label for="for_variation_' + safe_i + '" class=""> Used for variations</label></div>' +
            '</div>' +

            '<div class="form-group col-12">' +
            '<select class="col-6 form-control attribute attribute_option_data" name="attribute_options_' + i +
            '[]" __="<?php echo e(__('Enter choice values')); ?>"  data-role="" multiple id="attribute' + safe_i +
            '" data-id="' + i + '" required ></select></div></div>' +

            '</div></div>');

        if ($('.enable_product_variant').prop('checked') == true) {
            $(".use_for_variation").removeClass("d-none");
        }

        comman_function();
    }
    $(document).on('click', '.note-btn', function () {
        var ariaLabel = $(this).attr('aria-label');
        if (ariaLabel === 'Link (CTRL+K)' || ariaLabel === 'Edit') {
            $('body').addClass('note-link-unlink-sample');
        }
    });
    $(document).on('click', '.close, .note-link-btn', function () {
        $('body').removeClass('note-link-unlink-sample');
    });

    $('#sideBarsearch').on('keyup', function () {
        let value = $(this).val().toLowerCase().trim();

        if (value === "") {
            // If input is empty, show all
            $('.dash-navbar > .dash-item').show();
            $('.dash-submenu').hide();
            return;
        }

        $('.dash-navbar > .dash-item').each(function () {
            let $item = $(this);
            let isMatch = false;

            // Main menu match
            let mainText = $item.find('> .dash-link .dash-mtext').text().toLowerCase();
            if (mainText.includes(value)) {
                isMatch = true;
            }

            // Submenu match
            $item.find('.dash-submenu .dash-item').each(function () {
                let $subItem = $(this);
                let subText = $subItem.find('.dash-link').text().toLowerCase();

                if (subText.includes(value)) {
                    isMatch = true;
                    $subItem.show();
                } else {
                    $subItem.hide();
                }
            });

            if (isMatch) {
                $item.show();
                $item.find('.dash-submenu').show();
            } else {
                $item.hide();
            }
        });
    });
</script>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(Session::has('success')): ?>
        <script>
            show_toastr('<?php echo e(__('Success')); ?>', '<?php echo Session::get('success'); ?>', 'success');
        </script>
        <?php Session::forget('success'); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(Session::has('error')): ?>
        <script>
            show_toastr('<?php echo e(__('Error')); ?>', '<?php echo Session::get('error'); ?>', 'error');
        </script>
        <?php Session::forget('error'); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>


    <?php
        $setting = getSuperAdminAllSetting();
    ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['enable_cookie']) && $setting['enable_cookie'] == 'on'): ?>
        <?php echo $__env->make('layouts.cookie_consent', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</body>

</html>
<?php /**PATH /home/storesyria/public_html/resources/views/layouts/app.blade.php ENDPATH**/ ?>