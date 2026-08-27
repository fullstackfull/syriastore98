<?php
$displaylang = App\Models\Utility::languages();
if (auth()->user() && auth()->user()->language) {
$currentLanguage = auth()->user() ? auth()->user()->language : 'en';
} else {
$currentLanguage = Cookie::get('LANGUAGE');
if (empty($currentLanguage)) {
$currentLanguage = auth()->user() ? auth()->user()->language : 'en';
}
}
$store = getStoreById(getCurrentStore());
$theme_url = App\Http\Controllers\HomeController::getThemeUrl($store);
?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['cust_theme_bg']) && $setting['cust_theme_bg'] == 'on'): ?>
<header class="dash-header transprent-bg">
    <?php else: ?>
    <header class="dash-header">
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <div class="header-wrapper">
                <ul class="list-unstyled gap-2">
                    <li class="dash-h-item mob-hamburger">
                        <a href="#!" class="dash-head-link" id="mobile-collapse">
                            <div class="hamburger hamburger--arrowturn">
                                <div class="hamburger-box">
                                    <div class="hamburger-inner"></div>
                                </div>
                            </div>
                        </a>
                    </li>
                    <li class="dropdown dash-h-item drp-company">
                        <a class="dash-head-link dropdown-toggle arrow-none me-0" data-bs-toggle="dropdown" href="#"
                            role="button" aria-haspopup="false" aria-expanded="false">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            <span class="hide-mob">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!Auth::guest()): ?>
                                <?php echo e(__('Hi, ')); ?><?php echo e(!empty(Auth::user()) ? Auth::user()->name : ''); ?>!
                                <?php else: ?>
                                <?php echo e(__('Guest')); ?>

                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </span>
                            <i class="ti ti-chevron-down drp-arrow nocolor hide-mob"></i>
                        </a>
                        <div class="dropdown-menu dash-h-dropdown">

                            <a href="<?php echo e(route('profile')); ?>" class="dropdown-item">
                                <i class="ti ti-user"></i>
                                <span><?php echo e(__(' Profile')); ?></span>
                            </a>
                            <form method="POST" action="<?php echo e(route('logout')); ?>" id="form_logout">
                                <a href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();"
                                    class="dropdown-item">
                                    <i class="ti ti-power"></i>
                                    <?php echo csrf_field(); ?>
                                    <?php echo e(__(' Log Out')); ?>

                                </a>
                            </form>
                        </div>
                    </li>
                    <?php if (is_impersonating($guard = null)) : ?>
                    <li class="dropdown dash-h-item">
                        <a class="dropdown-item dash-head-link dropdown-toggle arrow-none  bg-danger"
                            href="<?php echo e(route('exit.admin')); ?>"><i class="ti ti-ban"></i>
                            <?php echo e(__('Exit Admin Login')); ?>

                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            <div class="dash-center-drp">
                <ul class="list-unstyled exit-company-btn">                    
                </ul>
            </div>
            <div class="dash-right-drp">
                <ul class="list-unstyled header-icon-list">                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user() && auth()->user()->type == 'super admin'): ?>
                    <li class="web-browse-icon">
                        <a href="<?php echo e(url('config-cache')); ?>" data-bs-toggle="tooltip"
                        title="<?php echo e(__('Clear Cache')); ?>" class="dash-head-link cust-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
                                <path id="_74846e5be5db5b666d3893933be03656"
                                    data-name="74846e5be5db5b666d3893933be03656"
                                    d="M7.719,8.911H8.9V10.1H7.719v1.185H6.539V10.1H5.36V8.911h1.18V7.726h1.18ZM5.36,13.652h1.18v1.185H5.36v1.185H4.18V14.837H3V13.652H4.18V12.467H5.36Zm13.626-2.763H10.138V10.3a1.182,1.182,0,0,1,1.18-1.185h2.36V2h1.77V9.111h2.36a1.182,1.182,0,0,1,1.18,1.185ZM18.4,18H16.044a9.259,9.259,0,0,0,.582-2.963.59.59,0,1,0-1.18,0A7.69,7.69,0,0,1,14.755,18H12.5a9.259,9.259,0,0,0,.582-2.963.59.59,0,1,0-1.18,0A7.69,7.69,0,0,1,11.216,18H8.958a22.825,22.825,0,0,0,1.163-5.926H18.99A19.124,19.124,0,0,1,18.4,18Z"
                                    transform="translate(-3 -2)" fill="#060606"></path>
                            </svg>
                        </a>
                    </li>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(auth()->user() && auth()->user()->type == 'admin'): ?>
                    <li class="web-browse-icon">
                        <a href="<?php echo e($theme_url ?? '#'); ?>" target="_blank" data-bs-toggle="tooltip"
                        title="<?php echo e(__('Store Link')); ?>" class="dash-head-link cust-btn">
                           <svg data-replit-metadata="client/src/components/icons/CustomIcons.tsx:36:4" data-component-name="svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#060606" stroke-width="2" class="w-4 h-4"><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:37:6" data-component-name="path" d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline data-replit-metadata="client/src/components/icons/CustomIcons.tsx:38:6" data-component-name="polyline" points="9,22 9,12 15,12 15,22"></polyline><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:39:6" data-component-name="path" d="m15 7 3 3-3 3M8 13l-3-3 3-3"></path></svg>
                        </a>
                    </li>
                    <li class="web-browse-icon">
                        <a href="<?php echo e(route('pos.index')); ?>" data-bs-toggle="tooltip"
                        title="<?php echo e(__('POS')); ?>" class="dash-head-link cust-btn h-100 ">
                           <svg data-replit-metadata="client/src/components/icons/CustomIcons.tsx:46:4" data-component-name="svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#060606" stroke-width="2" class="w-5 h-5 mr-3 text-primary"><rect data-replit-metadata="client/src/components/icons/CustomIcons.tsx:47:6" data-component-name="rect" x="2" y="4" width="20" height="16" rx="2"></rect><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:48:6" data-component-name="path" d="M7 15h10M7 11h4"></path><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:49:6" data-component-name="circle" cx="18" cy="8" r="2"></circle></svg>
                        </a>
                    </li>
                    
                    <li class="dropdown quick-add-btn header-quick-add">
                        <a class="dash-head-link dropdown-toggle arrow-none h-100 btn-q-add"
                        title="<?php echo e(__('Quick Add')); ?>" data-bs-toggle="dropdown" href="#"
                            role="button" aria-haspopup="false" aria-expanded="false">
                            <i class="ti ti-plus"></i>
                            <span class="text-store"><?php echo e(__('Quick Add')); ?></span>
                        </a>
                        <div class="dropdown-menu">
                            <a href="<?php echo e(route('product.create')); ?>" data-size="lg" data-title="<?php echo e(__('Add Product')); ?>"
                                class="dropdown-item text-wrap"
                                data-bs-placement="top "><span><?php echo e(__('Add New Product')); ?></span></a>
                            <a href="#" data-size="md" data-url="<?php echo e(route('taxes.create')); ?>" data-ajax-popup="true"
                                data-title="<?php echo e(__('Create Tax')); ?>" class="dropdown-item text-wrap"
                                data-bs-placement="top "><span><?php echo e(__('Add New Tax')); ?></span></a>
                            <a href="#" data-size="md" data-url="<?php echo e(route('category.create')); ?>"
                                data-ajax-popup="true" data-title="<?php echo e(__('Create Category')); ?>"
                                class="dropdown-item text-wrap"
                                data-bs-placement="top"><span><?php echo e(__('Add New Category')); ?></span></a>
                            <a href="#" data-size="md" data-url="<?php echo e(route('coupon.create')); ?>" data-ajax-popup="true"
                                data-title="<?php echo e(__('Create Coupon')); ?>" class="dropdown-item text-wrap"
                                data-bs-placement="top "><span><?php echo e(__('Add New Coupon')); ?></span></a>
                        </div>
                    </li>
                    
                 
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    <?php if(auth()->user() && auth()->user()->type == 'admin'): ?>
                    <li class="dash-h-item drp-language menu-lnk has-item">
                        <?php
                        $activeStore = getCurrentStore();
                        $store = \App\Models\Store::find($activeStore);
                        $stores = auth()->user()->stores;
                        ?>
                        <a class="dash-head-link arrow-none me-0 h-100 megamenu-btn"
                            data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false"
                            data-bs-placement="bottom" data-bs-original-title="Select Store">
                            <svg data-replit-metadata="client/src/components/icons/CustomIcons.tsx:24:4" data-component-name="svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#060606" stroke-width="2" class="w-4 h-4"><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:25:6" data-component-name="circle" cx="13.5" cy="6.5" r=".5"></circle><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:26:6" data-component-name="circle" cx="17.5" cy="10.5" r=".5"></circle><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:27:6" data-component-name="circle" cx="8.5" cy="7.5" r=".5"></circle><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:28:6" data-component-name="circle" cx="6.5" cy="12.5" r=".5"></circle><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:29:6" data-component-name="path" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"></path></svg>
                            <span class="hide-mob"><?php echo e(ucfirst($store->name ?? '')); ?></span>
                            <i class="ti ti-chevron-down drp-arrow nocolor"></i>
                        </a>
                        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end px-2">
                            <input type="text" id="searchInput" class="form-control mb-2"
                                placeholder="<?php echo e(__('Search...')); ?>">
                            <div id="storeList" style="max-height: 200px; overflow-y: auto;">
                                <?php if(auth()->user()->type == 'admin'): ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $stores; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $store): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($store->is_active): ?>
                                <a href="<?php if($activeStore == $store->id): ?> # <?php else: ?> <?php echo e(route('change.store', $store->id)); ?> <?php endif; ?>"
                                    class="dropdown-item">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($activeStore == $store->id): ?>
                                    <i class="ti ti-checks text-primary"></i>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php echo e(ucfirst($store->name)); ?>

                                </a>
                                <?php else: ?>
                                <a href="#!" class="dropdown-item">
                                    <i class="ti ti-lock"></i>
                                    <span><?php echo e($store->name); ?></span>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset(auth()->user()->type)): ?>
                                    <?php if(auth()->user()->type == 'admin'): ?>
                                    <span class="badge bg-dark"><?php echo e(__(auth()->user()->type)); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-dark"><?php echo e(__('Shared')); ?></span>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php else: ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $user->stores; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $store): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($store->is_active): ?>
                                <a href="#" class="dropdown-item">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($activeStore == $store->id): ?>
                                    <i class="ti ti-checks text-primary"></i>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php echo e(ucfirst($store->name)); ?>

                                </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        <a href="<?php echo e(route('stores.create')); ?>"
                            class="dropdown-item border-top py-1 text-primary d-flex align-items-center gap-1"
                            ><i class="ti ti-circle-plus"></i>
                            <span class="create-store"> <?php echo e(__('Create New Store')); ?></span>
                        </a>
                        </div>

                    </li>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    <li class="dropdown dash-h-item drp-language">
                        <a class="dash-head-link dropdown-toggle  arrow-none me-0 h-100" data-bs-toggle="dropdown"
                            href="#" role="button" aria-haspopup="false" aria-expanded="false">
                            <svg data-replit-metadata="client/src/components/icons/CustomIcons.tsx:14:4" data-component-name="svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#060606" stroke-width="2" class="w-4 h-4"><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:15:6" data-component-name="circle" cx="12" cy="12" r="10"></circle><line data-replit-metadata="client/src/components/icons/CustomIcons.tsx:16:6" data-component-name="line" x1="2" y1="12" x2="22" y2="12"></line><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:17:6" data-component-name="path" d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                            <span><?php echo e(Str::upper($currentLanguage)); ?></span>
                            <i class="ti ti-chevron-down drp-arrow nocolor"></i>
                        </a>

                        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $displaylang; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $lang): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['disable_lang']) && str_contains($setting['disable_lang'], $key)): ?>
                            <?php unset($key); ?>
                            <?php continue; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <a href="<?php echo e(route('change.language', $key)); ?>"
                                class="dropdown-item <?php echo e($currentLanguage == $key ? 'text-primary' : ''); ?>">
                                <span><?php echo e(Str::ucfirst($lang)); ?></span>
                            </a>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user() && auth()->user()->type == 'super admin'): ?>
                            <a href="<?php echo e(route('manage.language', [auth()->user()->language])); ?>"
                                class="dropdown-item border-top py-1 text-primary"><?php echo e(__('Manage Languages')); ?>

                            </a>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </header><?php /**PATH /home/storesyria/public_html/resources/views/partision/header.blade.php ENDPATH**/ ?>