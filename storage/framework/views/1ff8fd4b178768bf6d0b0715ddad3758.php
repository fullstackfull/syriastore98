<?php $__env->startSection('page-title', __('Dashboard')); ?>

<?php $__env->startSection('action-button'); ?>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('breadcrumb'); ?>
<?php $__env->stopSection(); ?>

<?php
    $setting = getAdminAllSetting();
    $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users text-blue-500 h-6 w-6"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>';
    $customer_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-plus text-blue-500 h-6 w-6"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>';
    $pending_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-clock text-blue-500 h-6 w-6"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
    $return_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rotate-ccw text-blue-500 h-6 w-6"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>';
    $confirmed_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check-circle text-blue-500 h-6 w-6"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
    $cancel_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x-circle text-blue-500 h-6 w-6"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';
    $shipped_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-truck text-blue-500 h-6 w-6"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>';
    $delivered_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-package w-6 h-6 text-primary" data-replit-metadata="client/src/components/dashboard/StatsGrid.tsx:85:18" data-component-name="Icon"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"></path><path d="m7.5 4.27 9 5.15"></path></svg>';
    $total_order_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shopping-cart text-blue-500 h-6 w-6"><circle cx="8" cy="21" r="1"></circle><circle cx="19" cy="21" r="1"></circle><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path></svg>';
?>

<?php $__env->startSection('content'); ?>
    <div class="row">
        <!-- [ sample-page ] start -->
        <div class="col-12">
            <div class="row mb-4 dash-order-status g-4">
                <div class="col-12">
                    <div class="row g-4">
                        <?php if (isset($component)) { $__componentOriginal88210b161ac9da424ac08c0f17c4d197 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal88210b161ac9da424ac08c0f17c4d197 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard-card','data' => ['count' => $pending_order,'label' => __('Pending orders'),'icon' => $pending_icon]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pending_order),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('Pending orders')),'icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pending_icon)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $attributes = $__attributesOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $component = $__componentOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__componentOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal88210b161ac9da424ac08c0f17c4d197 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal88210b161ac9da424ac08c0f17c4d197 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard-card','data' => ['count' => $return_order,'label' => __('Order Return'),'icon' => $return_icon]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($return_order),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('Order Return')),'icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($return_icon)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $attributes = $__attributesOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $component = $__componentOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__componentOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal88210b161ac9da424ac08c0f17c4d197 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal88210b161ac9da424ac08c0f17c4d197 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard-card','data' => ['count' => $confirmed_order,'label' => __('Confirmed Order'),'icon' => $confirmed_icon]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($confirmed_order),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('Confirmed Order')),'icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($confirmed_icon)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $attributes = $__attributesOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $component = $__componentOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__componentOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal88210b161ac9da424ac08c0f17c4d197 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal88210b161ac9da424ac08c0f17c4d197 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard-card','data' => ['count' => $cancel_order,'label' => __('Cancel Order'),'icon' => $cancel_icon]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($cancel_order),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('Cancel Order')),'icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($cancel_icon)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $attributes = $__attributesOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $component = $__componentOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__componentOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal88210b161ac9da424ac08c0f17c4d197 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal88210b161ac9da424ac08c0f17c4d197 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard-card','data' => ['count' => $shipped_order,'label' => __('Order Shipped'),'icon' => $shipped_icon]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shipped_order),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('Order Shipped')),'icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shipped_icon)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $attributes = $__attributesOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $component = $__componentOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__componentOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal88210b161ac9da424ac08c0f17c4d197 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal88210b161ac9da424ac08c0f17c4d197 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard-card','data' => ['count' => $delivered_order,'label' => __('Order Delivered'),'icon' => $delivered_icon]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($delivered_order),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('Order Delivered')),'icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($delivered_icon)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $attributes = $__attributesOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $component = $__componentOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__componentOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal88210b161ac9da424ac08c0f17c4d197 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal88210b161ac9da424ac08c0f17c4d197 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard-card','data' => ['count' => $totle_order,'label' => __('Total Orders'),'icon' => $total_order_icon]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($totle_order),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('Total Orders')),'icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($total_order_icon)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $attributes = $__attributesOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $component = $__componentOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__componentOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal88210b161ac9da424ac08c0f17c4d197 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal88210b161ac9da424ac08c0f17c4d197 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard-card','data' => ['count' => $totle_customers,'label' => __('Total Customers'),'icon' => $customer_icon]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($totle_customers),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('Total Customers')),'icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($customer_icon)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $attributes = $__attributesOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__attributesOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal88210b161ac9da424ac08c0f17c4d197)): ?>
<?php $component = $__componentOriginal88210b161ac9da424ac08c0f17c4d197; ?>
<?php unset($__componentOriginal88210b161ac9da424ac08c0f17c4d197); ?>
<?php endif; ?>

                    </div>
                </div>
                <div class="col-12 admin-cards">
                    <div class="row g-4">
                        <div class="col-xxl-4 col-12 theme-card">
                            <div class="dashboard-theme-card  dashboard-card">
                                <div class="theme-image buisness-img">
                                    <span class="card-badge btn btn-sm btn-primary btn-badge"><?php echo e(ucfirst($store->name ?? '')); ?></span>
                                    <img src="<?php echo e(asset('themes/' . APP_THEME() . '/theme_img/img_1.png')); ?>"
                                        alt="theme-image">
                                </div>
                                <div class="theme-card-button">
                                    <a class="btn btn-outline-primary btn-badge h-100 d-flex align-items-center justify-content-center"
                                        href="<?php echo e(route('theme.pages', APP_THEME())); ?>">
                                        <?php echo e(__('Customize')); ?>

                                    </a>
                                    <a class="btn btn-primary d-flex align-items-center justify-content-center"
                                        href="<?php echo e(route('theme.index')); ?>">
                                        <?php echo e(__('Manage Themes')); ?>

                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="col-xxl-4 col-12">
                            <div class="dashboard-card">
                                <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
                                    <h4 class="m-0"><?php echo e(__('Top Category')); ?></h4>
                                    <p class="m-0"><?php echo e(__('By Sales')); ?></p>
                                </div>
                                <div class="dash-category-tab" id="useradd-sidenav">
                                    <ul class="nav nav-pills w-100 pro-cat row store-setting-tab" id="pills-cat-tab"
                                        role="tablist">
                                        <li class="nav-item  col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#all-category-order" class="nav-link btn-sm f-w-600 active"
                                                id="all-category-tab" data-bs-toggle="pill"
                                                data-bs-target="#all-category-home" type="button" role="tab"
                                                aria-controls="all-category-home" aria-selected="true">
                                                <?php echo e(__('All')); ?>

                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#today-category-order" class="nav-link btn-sm f-w-600"
                                                id="today-category-tab" data-bs-toggle="pill"
                                                data-bs-target="#today-category" type="button" role="tab"
                                                aria-controls="today-category" aria-selected="true">
                                                <?php echo e(__('Today')); ?>

                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#week-category-order" class="nav-link btn-sm f-w-600"
                                                id="pills-seo-tab" data-bs-toggle="pill" data-bs-target="#pills-seo"
                                                type="button" role="tab" aria-controls="pills-seo" aria-selected="true">
                                                <?php echo e(__('Week')); ?>

                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#month-category-order" class="nav-link btn-sm f-w-600"
                                                id="pills-seo-tab" data-bs-toggle="pill" data-bs-target="#pills-seo"
                                                type="button" role="tab" aria-controls="pills-seo" aria-selected="true">
                                                <?php echo e(__('Month')); ?>

                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#year-category-order" class="nav-link btn-sm f-w-600"
                                                id="pills-seo-tab" data-bs-toggle="pill" data-bs-target="#pills-seo"
                                                type="button" role="tab" aria-controls="pills-seo" aria-selected="true">
                                                <?php echo e(__('Year')); ?>

                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content dash-category-tab-content mt-4">
                                        <div id="category-tab-data"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xxl-4 col-12">
                            <div class="dashboard-card">
                                <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
                                    <h4 class="m-0"><?php echo e(__('Top Brand')); ?></h4>
                                    <p class="m-0"><?php echo e(__('By Sales')); ?></p>
                                </div>
                                <div class="dash-brand-tab" id="brand-sidenav">
                                    <ul class="nav nav-pills w-100 pro-cat row store-setting-tab" id="pills-brand-tab"
                                        role="tablist">
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#all-brand-order" class="nav-link btn-sm f-w-600 active"
                                                id="all-brand-tab" data-bs-toggle="pill" data-bs-target="#all-brand"
                                                type="button" role="tab" aria-controls="all-brand" aria-selected="true">
                                                <?php echo e(__('All')); ?>

                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#today-brand-order" class="nav-link btn-sm f-w-600"
                                                id="today-brand-tab" data-bs-toggle="pill" data-bs-target="#today-brand"
                                                type="button" role="tab" aria-controls="today-brand" aria-selected="true">
                                                <?php echo e(__('Today')); ?>

                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#week-brand-order" class="nav-link btn-sm f-w-600" id="pills-seo-tab"
                                                data-bs-toggle="pill" data-bs-target="#pills-seo" type="button" role="tab"
                                                aria-controls="pills-seo" aria-selected="true">
                                                <?php echo e(__('Week')); ?>

                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#month-brand-order" class="nav-link btn-sm f-w-600" id="pills-seo-tab"
                                                data-bs-toggle="pill" data-bs-target="#pills-seo" type="button" role="tab"
                                                aria-controls="pills-seo" aria-selected="true">
                                                <?php echo e(__('Month')); ?>

                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#year-brand-order" class="nav-link btn-sm f-w-600" id="pills-seo-tab"
                                                data-bs-toggle="pill" data-bs-target="#pills-seo" type="button" role="tab"
                                                aria-controls="pills-seo" aria-selected="true">
                                                <?php echo e(__('Year')); ?>

                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content dash-category-tab-content mt-4">
                                        <div id="brand-tab-data"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-4 admin-cards">
                <div class="col-xxl-4 col-12">
                    <div class="row g-4">
                        <div class="col-12">
                            <div class="dashboard-card">
                                <div class="card-content-info d-flex gap-3 flex-wrap flex-column flex-sm-row">
                                    <div
                                        class="d-flex align-items-center align-items-sm-start gap-3 flex-1 flex-column flex-sm-row">
                                        <a href="<?php echo e(!empty(auth()->user()->profile_image) && file_exists(auth()->user()->profile_image) ? get_file(auth()->user()->profile_image) : asset(Storage::url('uploads/profile/avatar.png'))); ?>"
                                            target="_blank" class="user-img">
                                            <img src="<?php echo e(!empty(auth()->user()->profile_image) && file_exists(auth()->user()->profile_image) ? get_file(auth()->user()->profile_image) : asset(Storage::url('uploads/profile/avatar.png'))); ?>"
                                                alt="user-image">
                                        </a>
                                        <div class="user-info">
                                            <h4 class="mb-2"><?php echo e(__(auth()->user()->name)); ?>

                                            </h4>
                                            <p><?php echo e(__('Have a nice day! Did you know that you can quickly add your favorite product or category to the theme?')); ?>

                                            </p>
                                            <div
                                                class="d-flex align-items-center gap-2 flex-wrap justify-content-center justify-content-sm-start">
                                                <div class="dropdown quick-add-btn">
                                                    <a class="btn btn-primary btn-sm btn-q-add btn-badge gap-1"
                                                        data-bs-toggle="dropdown" href="#" role="button"
                                                        aria-haspopup="false" aria-expanded="false">
                                                        <i class="ti ti-plus"></i>
                                                        <span><?php echo e(__('Quick Add')); ?></span>
                                                    </a>
                                                    <div class="dropdown-menu">
                                                        <a href="<?php echo e(route('product.create')); ?>" data-size="lg"
                                                            data-title="<?php echo e(__('Add Product')); ?>"
                                                            class="dropdown-item text-wrap"
                                                            data-bs-placement="top "><span><?php echo e(__('Add New Product')); ?></span></a>
                                                        <a href="#" data-size="md" data-url="<?php echo e(route('taxes.create')); ?>"
                                                            data-ajax-popup="true" data-title="<?php echo e(__('Create Tax')); ?>"
                                                            class="dropdown-item text-wrap"
                                                            data-bs-placement="top "><span><?php echo e(__('Add New Tax')); ?></span></a>
                                                        <a href="#" data-size="md" data-url="<?php echo e(route('category.create')); ?>"
                                                            data-ajax-popup="true"
                                                            data-title="<?php echo e(__('Create Main Category')); ?>"
                                                            class="dropdown-item text-wrap"
                                                            data-bs-placement="top"><span><?php echo e(__('Add
                                                                                            New Main Category')); ?></span></a>
                                                        <a href="#" data-size="md" data-url="<?php echo e(route('coupon.create')); ?>"
                                                            data-ajax-popup="true" data-title="<?php echo e(__('Create Coupon')); ?>"
                                                            class="dropdown-item text-wrap"
                                                            data-bs-placement="top "><span><?php echo e(__('Add New Coupon')); ?></span></a>
                                                    </div>
                                                </div>
                                                <a href="#" id="socialShareButton"
                                                    class="socialShareButton btn btn-sm btn-outline-primary btn-badge">
                                                    <i class="ti ti-share"></i>
                                                </a>
                                                <a href="#" class="btn btn-outline-primary btn-sm cp_link btn-badge"
                                                    data-link="<?php echo e($theme_url); ?>" data-bs-toggle="tooltip"
                                                    data-bs-placement="top" title="<?php echo e(__('Click to copy link')); ?>">
                                                    <?php echo e(__('Theme Link')); ?>

                                                    <i class="ms-1" data-feather="copy"></i>
                                                </a>

                                                <div id="sharingButtonsContainer" class="sharingButtonsContainer"
                                                    style="display: none;">
                                                    <div
                                                        class="Demo1 d-flex align-items-center justify-content-center mb-5 hidden">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="qr-code-wrp">
                                        <h5 class="text-capitalize text-center"><?php echo e($store->name); ?></h5>
                                        <div class="code-img">
                                            <?php echo QrCode::generate($theme_url); ?>

                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="dashboard-card">
                                <div class="card-header">
                                    <h4><?php echo e(__('Storage Status')); ?><small>(<?php echo e($users->storage_limit . 'MB'); ?> / <?php echo e(($plan->storage_limit ?? 0) . 'MB'); ?>)</small></h4>
                                </div>
                                <div class="card-body">
                                    <div id="device-chart"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xxl-4 col-12">
                    <div class="overflow-auto dashboard-card">
                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                            <h4 class="m-0"><?php echo e(__('Latest Products')); ?></h4>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($latests && count($latests) > 0): ?>
                                <a class="text-primary" href="<?php echo e(route('product.index')); ?>"><?php echo e(__('View All')); ?></a>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table dataTable">
                                <thead>
                                    <tr>
                                        <th>
                                            <?php echo e(__('Image')); ?>

                                        </th>
                                        <th scope="col" class="sort" data-sort="name">
                                            <?php echo e(__('Product')); ?>

                                        </th>

                                        <th scope="col" class="sort text-right" data-sort="completion">
                                            <?php echo e(__('Price')); ?>

                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($latests) && count($latests) > 0): ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $latests; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $latest): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <a href="<?php echo e(route('product.index')); ?>" target="_blank"
                                                            class="rounded-1 border overflow-hidden border border-primary border-2 rounded">
                                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($latest->cover_image_path): ?>
                                                                <img src="<?php echo e(get_file($latest->cover_image_path)); ?>" width="100"
                                                                    class="cover_img1" alt="images">
                                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                        </a>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <h6 class="m-0"><?php echo e($latest->name); ?>

                                                        </h6>
                                                    </div>
                                                </td>
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($latest->variant_product == 0): ?>

                                                                        <td>
                                                                            <h6 class="m-0">
                                                                                <?php echo e(currency_format_with_sym($latest->final_price ?? 0, getCurrentStore()) ??
                                                    SetNumberFormat($latest->final_price)); ?>

                                                                            </h6>
                                                                        </td>
                                                <?php else: ?>

                                                    <td>
                                                        <h6 class="m-0"><?php echo e(__('In Variant')); ?></h6>
                                                    </td>
                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </tr>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-4 col-12">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <h4><?php echo e(__('Recent Orders')); ?></h4>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($new_orders && count($new_orders) > 0): ?>
                                <a class="text-primary" href="<?php echo e(route('order.index')); ?>"><?php echo e(__('View All')); ?></a>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table dataTable">
                                <thead>
                                    <tr>
                                        <th scope="col"><?php echo e(__('Orders')); ?></th>
                                        <th scope="col" class="sort"><?php echo e(__('Date')); ?></th>
                                        <th scope="col" class="sort"><?php echo e(__('Status')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($new_orders) > 0): ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $new_orders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $order): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($order->status != 'Cancel Order'): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center table-inner-text">
                                                            <a href="<?php echo e(route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id))); ?>"
                                                                <?php $btn_class = 'bg-info';
                                                                    if (
                                                                        $order->delivered_status == 2 ||
                                                                        $order->delivered_status == 3
                                                                    ) {
                                                                        $btn_class = 'bg-danger';
                                                                    } elseif ($order->delivered_status == 1) {
                                                                        $btn_class = 'bg-success';
                                                                    } elseif ($order->delivered_status == 4) {
                                                                        $btn_class = ' bg-warning';
                                                                    } elseif ($order->delivered_status == 5) {
                                                                        $btn_class = 'bg-secondary';
                                                                    } elseif ($order->delivered_status == 6) {
                                                                        $btn_class = 'bg-dark';
                                                                } ?>
                                                                class="btn bg-primary btn-sm text-sm btn-badge" data-bs-toggle="tooltip"
                                                                title="<?php echo e(__('Invoice ID')); ?>">
                                                                <span class="btn-inner--icon"></span>
                                                                <span class="btn-inner--text">#<?php echo e($order->product_order_id); ?></span>
                                                            </a>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <h6 class="m-0">
                                                            <?php echo e(\App\Models\Utility::dateFormat($order->order_date)); ?>

                                                        </h6>
                                                    </td>



                                                    <td class="">
                                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($order->delivered_status == 0): ?>
                                                            <button type="button" class="btn btn-sm btn-info btn-icon">

                                                                <span class="btn-inner--text"> <?php echo e(__('Pending')); ?>

                                                                </span>
                                                            </button>
                                                        <?php elseif($order->delivered_status == 1): ?>
                                                            <button type="button" class="btn btn-sm btn-success btn-icon">

                                                                <span class="btn-inner--text"> <?php echo e(__('Delivered')); ?>

                                                                </span>
                                                            </button>
                                                        <?php elseif($order->delivered_status == 2): ?>
                                                            <button type="button" class="btn btn-sm btn-danger btn-icon">

                                                                <span class="btn-inner--text"> <?php echo e(__('Cancel')); ?>

                                                                </span>
                                                            </button>
                                                        <?php elseif($order->delivered_status == 3): ?>
                                                            <button type="button" class="btn btn-sm btn-danger btn-icon">

                                                                <span class="btn-inner--text"> <?php echo e(__('Return')); ?>

                                                                </span>
                                                            </button>
                                                        <?php elseif($order->delivered_status == 4): ?>
                                                            <button type="button" class="btn btn-sm btn-warning btn-icon">

                                                                <span class="btn-inner--text"> <?php echo e(__('Confirmed')); ?>


                                                                </span>
                                                            </button>
                                                        <?php elseif($order->delivered_status == 5): ?>
                                                            <button type="button" class="btn btn-sm btn-secondary btn-icon">

                                                                <span class="btn-inner--text"> <?php echo e(__('Picked Up')); ?>


                                                                </span>
                                                            </button>
                                                        <?php elseif($order->delivered_status == 6): ?>
                                                            <button type="button" class="btn btn-sm btn-dark btn-icon">

                                                                <span class="btn-inner--text"> <?php echo e(__(key: 'Shipped')); ?>

                                                                </span>
                                                            </button>
                                                        <?php elseif($order->delivered_status == 8): ?>
                                                            <button type="button" class="btn btn-sm btn-dark btn-icon">
                                                                <span class="btn-inner--text"> <?php echo e(__('Pre Order')); ?>


                                                                </span>
                                                            </button>
                                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('custom-script'); ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->type != 'superadmin'): ?>
        <script>
            $(document).ready(function () {
                $('.cp_link').on('click', function () {
                    var value = $(this).attr('data-link');
                    var $temp = $("<input>");
                    $("body").append($temp);
                    $temp.val(value).select();
                    document.execCommand("copy");
                    $temp.remove();
                    show_toastr('Success', '<?php echo e(__("Link copied")); ?>', 'success')
                });
            });
        </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <script>

        // new-chart-js-start
        $(document).ready(function () {
            var options = {
                series: [<?php echo e(round($storage_limit, 2)); ?>],
                chart: {
                    height: 550,
                    type: 'radialBar',
                    offsetY: -20,
                    sparkline: {
                        enabled: true
                    },
                    toolbar: {
                        show: false
                    },
                },
                plotOptions: {
                    radialBar: {
                        startAngle: -90,
                        endAngle: 90,
                        track: {
                            background: "var(--third-color)",
                            strokeWidth: '97%',
                            margin: 5, // margin is in pixels
                        },
                        dataLabels: {
                            name: {
                                show: true,
                                fontSize: '16px',
                            },
                            value: {
                                offsetY: -50,
                                fontSize: '20px'
                            }
                        }
                    }
                },
                grid: {
                    padding: {
                        top: -10
                    }
                },
                colors: ["var(--theme-color)"],
                labels: ['Used'],
                responsive: [{
                    breakpoint: 600,
                    options: {
                        chart: {
                            height: 300,
                        },
                        plotOptions: {
                            radialBar: {
                                startAngle: -90,
                                endAngle: 90,
                                size: '70%',
                            }
                        },
                        dataLabels: {
                            value: {
                                offsetY: -40,
                                fontSize: '18px',
                            },
                        }
                    }
                },
                {
                    breakpoint: 1024,
                    options: {
                        chart: {
                            height: 450,
                        },
                        plotOptions: {
                            radialBar: {
                                size: '80%',
                            }
                        },
                        dataLabels: {
                            value: {
                                offsetY: -45,
                                fontSize: '18px',
                            },
                        }
                    }
                }
                ]
            };

            var chart = new ApexCharts(document.querySelector("#device-chart"), options);
            chart.render();
        });


        // Assuming this value is injected from your backend
        var defaultTimezone = "<?php echo e($setting['defult_timezone'] ?? 'Asia/Kolkata'); ?>"; // Example: -5 for EST

        // Function to get the current hour in the specified timezone
        function getHourInTimezone(timezone) {
            var date = new Date();
            var options = {
                timeZone: timezone,
                hour: '2-digit',
                hour12: false,
            };
            var formatter = new Intl.DateTimeFormat([], options);
            var formattedTime = formatter.formatToParts(date);
            var hour = formattedTime.find(part => part.type === 'hour').value;
            return parseInt(hour, 10);
        }

        // Get the current hour in the specified timezone
        var curHr = getHourInTimezone(defaultTimezone);

        // Set greeting based on the calculated hour
        var target = document.getElementById("greetings");
        if (target && curHr < 12) {
            target.innerHTML = "<?php echo e(__('Good Morning,')); ?>";
        } else if (target && curHr < 17) {
            target.innerHTML = "<?php echo e(__('Good Afternoon,')); ?>";
        } else {
            if (target) {
                target.innerHTML = "<?php echo e(__('Good Evening,')); ?>";
            }
        }
    </script>
    <script>
        $(document).on('click', '.code', function () {
            var type = $(this).val();
            $('#code_text').addClass('col-md-12').removeClass('col-md-8');
            $('#autogerate_button').addClass('d-none');
            if (type == 'auto') {
                $('#code_text').addClass('col-md-8').removeClass('col-md-12');
                $('#autogerate_button').removeClass('d-none');
            }
        });

        $(document).on('click', '#code-generate', function () {
            var length = 10;
            var result = '';
            var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            var charactersLength = characters.length;
            for (var i = 0; i < length; i++) {
                result += characters.charAt(Math.floor(Math.random() * charactersLength));
            }
            $('#auto-code').val(result);
        });
    </script>
    <script>
        function add_more_customer_choice_option(i, name) {
            $('#customer_choice_options').append(
                '<div class="form-group"><input type="hidden" name="choice_no[]" value="' + i + '">' +
                '<label for="choice_attributes">' + name + ':</label>' +
                '<input type="text" class="form-control variant_choice" name="choice_options_' + i +
                '[]" __="<?php echo e(__('Enter choice values')); ?>"  data-role="tagsinput" id="variant_tag' + i +
                '" onchange="update_sku($(this).val())">' +
                '</div>');
            comman_function();
        }

        $(document).ready(function () {
            var customURL = <?php echo json_encode($theme_url); ?>;
            console.log('customURL', customURL);
            $('.Demo1').socialSharingPlugin({
                url: customURL,
                title: $('meta[property="og:title"]').attr('content'),
                description: $('meta[property="og:description"]').attr('content'),
                img: $('meta[property="og:image"]').attr('content'),
                enable: ['whatsapp', 'facebook', 'twitter', 'pinterest', 'instagram']
            });

            $('.socialShareButton').click(function (e) {
                e.preventDefault();
                $('.sharingButtonsContainer').toggle();
            });

            // Fetch order details for "Today" tab
            fetchOrderDetails('all-category', 'category');
            fetchOrderDetails('all-category', 'brand');

            // Add event listener for tab changes
            $('.dash-category-tab .nav-pills a[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
                // Get the ID of the active tab
                var tabId = $(e.target).attr('href');
                $('#loader').fadeIn();
                // Fetch order details for "Today" tab
                fetchOrderDetails(tabId, 'category');


            });

            // Add event listener for tab changes
            $('.dash-brand-tab .nav-pills a[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
                // Get the ID of the active tab
                var tabId = $(e.target).attr('href');
                $('#loader').fadeIn();
                // Fetch order details for "Today" tab
                fetchOrderDetails(tabId, 'brand');


            });

            // Function to fetch order details based on tab ID
            function fetchOrderDetails(tabId, type) {
                // Make an AJAX request to fetch order details
                $.ajax({
                    url: "<?php echo e(route('top.brand.category.chart')); ?>", // Replace with your Laravel route
                    type: 'POST',
                    data: {
                        tabId: tabId,
                        type: type
                    },
                    success: function (response) {
                        // Update UI with fetched order details
                        // For example, you can update a div with the fetched data
                        $('#' + type + '-tab-data').html('');
                        $('#' + type + '-tab-data').html(response.html);
                        $('#loader').fadeOut();
                    },
                    error: function (xhr, status, error) {
                        // Handle error
                        console.error(error);
                    }
                });
            }
        });
    </script>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-DN7D163CD8"></script>
    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }
        gtag('js', new Date());
        gtag('config', 'G-DN7D163CD8');
    </script>
<?php $__env->stopPush(); ?>
<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/dashboard.blade.php ENDPATH**/ ?>