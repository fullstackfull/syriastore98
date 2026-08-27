<?php $__env->startSection('page-title', __('Store Settings')); ?>

<?php $__env->startSection('action-button'); ?>
<!-- Search Input -->
<div class="store-setting-search d-flex justify-content-end">
    <input type="text" id="tab-search" class="form-control" style="max-width: 300px;"
        placeholder="<?php echo e(__('Search...')); ?>">
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('breadcrumb'); ?>
<li class="breadcrumb-item"><?php echo e(__('Store Settings')); ?></li>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="row">
    <!-- Content here -->
    <div class="col-xl-12">
        <div class="card">
            <div class="list-group list-group-flush app-seeting-tab" id="useradd-sidenav">
                <ul class="nav nav-pills w-100  row store-setting-tab" id="pills-tab" role="tablist">
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                        <a href="#Theme_Setting"
                            class="nav-link <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-home-tab')): ?> active show <?php endif; ?> btn-sm f-w-600"
                            id="pills-home-tab" data-bs-toggle="pill" data-bs-target="#pills-home" type="button"
                            role="tab" aria-controls="pills-home" aria-selected="true">
                            <?php echo e(__('Store Settings')); ?>

                        </a>

                    </li>
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
                        <a href="#SEO_Setting"
                            class="nav-link btn-sm f-w-600 <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-seo-tab')): ?> active show <?php endif; ?>"
                            id="pills-seo-tab" data-bs-toggle="pill" data-bs-target="#pills-seo" type="button"
                            role="tab" aria-controls="pills-seo" aria-selected="true">
                            <?php echo e(__('SEO Settings')); ?>

                        </a>

                    </li>
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
                        <a href="#custom_Setting"
                            class="nav-link <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-custom-tab')): ?> active show <?php endif; ?> btn-sm f-w-600"
                            id="pills-custom-tab" data-bs-toggle="pill" data-bs-target="#pills-custom" type="button"
                            role="tab" aria-controls="pills-custom" aria-selected="true">
                            <?php echo e(__('Custom Settings')); ?>

                        </a>

                    </li>
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
                        <a href="#checkout_Setting"
                            class="nav-link <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-checkout-tab')): ?> active show <?php endif; ?> btn-sm f-w-600"
                            id="pills-checkout-tab" data-bs-toggle="pill" data-bs-target="#pills-checkout" type="button"
                            role="tab" aria-controls="pills-checkout" aria-selected="true">
                            <?php echo e(__('Checkout Settings')); ?>

                        </a>

                    </li>
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
                        <a href="#shippinglabel_Setting"
                            class="nav-link <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-shippinglabel-tab')): ?> active show <?php endif; ?> btn-sm f-w-600"
                            id="pills-shippinglabel-tab" data-bs-toggle="pill" data-bs-target="#pills-shippinglabel" type="button"
                            role="tab" aria-controls="pills-shippinglabel" aria-selected="true">
                            <?php echo e(__('Shipping Label Settings')); ?>

                        </a>

                    </li>
                    <?php echo $__env->yieldPushContent('appSettingTab'); ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-xl-12">
        <div class="tab-content store-tab-content" id="pills-tabContent">
            <!-- Tab panes -->
            <div class="tab-pane fade <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-home-tab')): ?> show active <?php endif; ?>"
                id="pills-home" role="tabpanel" aria-labelledby="pills-home-tab">
                <div id="Theme_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> <?php echo e(__('Store Settings')); ?> </h5>
                        </div>
                        <?php echo e(Form::model($setting, ['route' => 'theme.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

                        <div class="card-body p-4 store-setting-tab">
                            <input type="hidden" name="app_setting_tab" value="pills-home-tab">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="row ">
                                        <div class="form-group col-md-3">
                                            <?php echo e(Form::label('theme_name', __('Store Name'), ['class' => 'form-label'])); ?>

                                            <?php echo Form::text('theme_name', $store->name, ['class' => 'form-control',
                                            'placeholder' => __('Store Name')]); ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['theme_name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                            <span class="invalid-store_name" role="alert">
                                                <strong class="text-danger">
                                                    <?php echo e($message); ?>

                                                </strong>
                                            </span>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <?php echo e(Form::label('email', __('Store Email'), ['class' => 'form-label'])); ?>

                                            <?php echo Form::text('email', $store->email, ['class' => 'form-control',
                                            'placeholder' => __('Store Email')]); ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                            <span class="invalid-store_name" role="alert">
                                                <strong class="text-danger">
                                                    <?php echo e($message); ?>

                                                </strong>
                                            </span>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <?php echo e(Form::label('store_slug', __('Store Slug'), ['class' => 'form-label'])); ?>

                                            <?php echo Form::text('store_slug', $store->slug, ['class' => 'form-control',
                                            'placeholder' => __('Store Slug')]); ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['store_slug'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                            <span class="invalid-store_slug" role="alert">
                                                <strong class="text-danger">
                                                    <?php echo e($message); ?>

                                                </strong>
                                            </span>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <?php echo e(Form::label('languages-dropdown', __('Store Language'), ['class' => 'form-label'])); ?>

                                            <?php echo Form::select('default_language', $languages, $store->default_language, [
                                            'class' => 'form-control',
                                            'data-role' => 'tagsinput',
                                            'id' => 'languages-dropdown',
                                            ]); ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['default_language'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                            <span class="invalid-store_name" role="alert">
                                                <strong class="text-danger">
                                                    <?php echo e($message); ?>

                                                </strong>
                                            </span>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="row">
                                        <?php echo $__env->yieldPushContent('appSettingEnableBtn'); ?>
                                    </div>
                                </div>
                               
                                <div class="col-lg-3 col-sm-6 col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5><?php echo e(__('Invoice Logo')); ?></h5>
                                        </div>
                                        <div class="card-body pt-0">
                                            <div class=" setting-card">
                                                <div class="logo-content mt-4">
                                                    <a href="<?php echo e(!empty($invoice_logo) ? $invoice_logo : $profile . '/logo.png'); ?>"
                                                        target="_blank">
                                                        <img src="<?php echo e(!empty($invoice_logo) ? $invoice_logo : $profile . '/logo.png'); ?>"
                                                            class="big-logo invoice_logo img_setting" id="invoiceLogo">
                                                    </a>
                                                </div>
                                                <div class="choose-files mt-4">
                                                    <label for="invoice_logo">
                                                        <div class=" bg-primary logo_update"> <i
                                                                class="ti ti-upload px-1"></i><?php echo e(__('Choose File Here')); ?>

                                                        </div>
                                                        <input type="file" name="invoice_logo" id="invoice_logo"
                                                            class="form-control file"
                                                            data-filename="invoice_logo_update"
                                                            onchange="document.getElementById('invoiceLogo').src = window.URL.createObjectURL(this.files[0])">
                                                    </label>
                                                </div>
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['invoice_logo'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                                <div class="row">
                                                    <span class="invalid-invoice_logo" role="alert">
                                                        <strong class="text-danger"><?php echo e($message); ?></strong>
                                                    </span>
                                                </div>
                                                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-3 col-sm-6 col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5><?php echo e(__('Store Image')); ?></h5>
                                        </div>
                                        <div class="card-body pt-0">
                                            <div class=" setting-card">
                                                <div class="logo-content mt-4">
                                                    <a href="<?php echo e(!empty($theme_image) ? $theme_image : $profile . '/logo.png'); ?>"
                                                        target="_blank">
                                                        <img src="<?php echo e(!empty($theme_image) ? $theme_image : $profile . '/logo.png'); ?>"
                                                            class="big-logo store_image img_setting" id="storeImage">
                                                    </a>
                                                </div>
                                                <div class="choose-files mt-4">
                                                    <label for="theme_image">
                                                        <div class=" bg-primary logo_update"> <i
                                                                class="ti ti-upload px-1"></i><?php echo e(__('Choose File Here')); ?>

                                                        </div>
                                                        <input type="file" class="form-control file" name="theme_image"
                                                            id="theme_image" data-filename="logo_update"
                                                            onchange="document.getElementById('storeImage').src = window.URL.createObjectURL(this.files[0])">
                                                    </label>
                                                </div>
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['theme_image'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                                <div class="row">
                                                    <span class="invalid-logo" role="alert">
                                                        <strong class="text-danger"><?php echo e($message); ?></strong>
                                                    </span>
                                                </div>
                                                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end flex-wrap gap-1">
                            <?php if (app('laratrust')->hasPermission('Edit Store Setting')) : ?>
                            <input type="submit" value="<?php echo e(__('Save Changes')); ?>"
                                class="btn-submit btn btn-primary btn-badge">
                            <?php endif; // app('laratrust')->permission ?>
                            <?php echo Form::close(); ?>


                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\Auth::user()->type == 'admin'): ?>
                            <?php echo Form::open([
                            'method' => 'DELETE',
                            'route' => ['ownerstore.remove', getCurrentStore()],
                            'class' => 'd-inline',
                            ]); ?>

                            <button type="button"
                                class="btn btn-secondary btn-danger btn-sm btn-badge show_confirm danger-btn"
                                data-confirm="<?php echo e(__('Are You Sure?')); ?>"
                                data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>"
                                data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>">
                                <span class="text-black"><?php echo e(__('Delete Store')); ?></span>
                            </button>
                            <?php echo Form::close(); ?>

                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-seo-tab')): ?> show active <?php endif; ?>"
                id="pills-seo" role="tabpanel" aria-labelledby="pills-seo-tab">
                <div id="SEO_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> <?php echo e(__('SEO Settings')); ?> </h5>
                        </div>
                        <?php echo e(Form::model($setting, ['route' => 'seo.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

                        <div class="card-body p-4">
                            <input type="hidden" name="app_setting_tab" value="pills-seo-tab">
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <i class="fab fa-google fs-3 me-1" aria-hidden="true"></i>
                                        <?php echo e(Form::label('google_analytic', __('Google Analytic'), ['class' => 'form-label'])); ?>

                                        <?php echo e(Form::text('google_analytic', null, ['class' => 'form-control', 'placeholder' => 'UA-XXXXXXXXX-X'])); ?>

                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['google_analytic'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                        <span class="invalid-google_analytic" role="alert">
                                            <strong class="text-danger"><?php echo e($message); ?></strong>
                                        </span>
                                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <i class="fab fa-facebook-f fs-3 me-1" aria-hidden="true"></i>
                                        <?php echo e(Form::label('facebook_pixel_code', __('Facebook Pixel'), ['class' => 'form-label'])); ?>

                                        <?php echo e(Form::text('fbpixel_code', null, ['class' => 'form-control', 'placeholder' => 'UA-0000000-0'])); ?>

                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['facebook_pixel_code'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                        <span class="invalid-google_analytic" role="alert">
                                            <strong class="text-danger"><?php echo e($message); ?></strong>
                                        </span>
                                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <?php echo e(Form::label('metakeyword', __('Meta Keywords'), ['class' => 'form-label'])); ?>

                                        <?php echo Form::textarea('metakeyword', null, [
                                        'class' => 'form-control',
                                        'rows' => 3,
                                        'placeholder' => __('Meta Keyword'),
                                        ]); ?>

                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['meta_keywords'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                        <span class="invalid-about" role="alert">
                                            <strong class="text-danger"><?php echo e($message); ?></strong>
                                        </span>
                                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>

                                    <div class="form-group">
                                        <?php echo e(Form::label('metadesc', __('Meta Description'), ['class' => 'form-label'])); ?>

                                        <?php echo Form::textarea('metadesc', null, [
                                        'class' => 'form-control',
                                        'rows' => 3,
                                        'placeholder' => __('Meta Description'),
                                        ]); ?>


                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['meta_description'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                        <span class="invalid-about" role="alert">
                                            <strong class="text-danger"><?php echo e($message); ?></strong>
                                        </span>
                                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group pt-0">
                                        <div class=" setting-card">
                                            <label for="" class="form-label"><?php echo e(__('Meta Image')); ?></label>
                                            <div class="">
                                                <a href="<?php echo e(asset(!empty($setting['metaimage']) ? $setting['metaimage'] : 'themes/stylique/theme_img/img_1.png')); ?>"
                                                    target="_blank">
                                                    <img id="meta_image" alt="your image"
                                                        src="<?php echo e(asset(!empty($setting['metaimage']) ? $setting['metaimage'] : 'themes/stylique/theme_img/img_1.png')); ?>"
                                                        width="220px" class="img_setting">
                                                </a>
                                            </div>
                                            <div class="choose-files mt-3">
                                                <label for="metaimage">
                                                    <div class=" bg-primary full_logo"> <i
                                                            class="ti ti-upload px-1"></i><?php echo e(__('Choose File Here')); ?>

                                                    </div>
                                                    <input type="file" name="metaimage" id="metaimage"
                                                        class="form-control file" data-filename="metaimage"
                                                        onchange="document.getElementById('meta_image').src = window.URL.createObjectURL(this.files[0])">
                                                </label>
                                            </div>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['metaimage'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                            <div class="row">
                                                <span class="invalid-logo" role="alert">
                                                    <strong class="text-danger"><?php echo e($message); ?></strong>
                                                </span>
                                            </div>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end flex-wrap ">
                            <?php if (app('laratrust')->hasPermission('Edit Store Setting')) : ?>
                            <input type="submit" value="<?php echo e(__('Save Changes')); ?>"
                                class="btn-submit btn btn-primary btn-badge">
                            <?php endif; // app('laratrust')->permission ?>
                        </div>
                        <?php echo Form::close(); ?>

                    </div>
                </div>
            </div>
            <div class="tab-pane fade  <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-custom-tab')): ?> show active <?php endif; ?>"
                id="pills-custom" role="tabpanel" aria-labelledby="pills-custom-tab">
                <div id="custom_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> <?php echo e(__('Custom Settings')); ?> </h5>

                        </div>
                        <?php echo e(Form::model($setting, ['route' => 'theme.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

                        <div class="card-body p-4">
                            <input type="hidden" name="app_setting_tab" value="pills-custom-tab">
                            <div class="row mt-2">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && ($plan->enable_domain == 'on' || $plan->enable_subdomain == 'on')): ?>
                                <div class="form-group col-md-6 py-4">
                                    <div class="radio-button-group row gy-2 mts">
                                        <div class="col-sm-4">
                                            <label
                                                class="btn btn-outline-primary btn-badge w-100 <?php echo e($enable_storelink == 'on' ? 'active' : ''); ?>">
                                                <input type="radio" class="domain_click  radio-button"
                                                    name="enable_domain" value="enable_storelink" id="enable_storelink"
                                                    <?php echo e($enable_storelink == 'on' ? 'checked' : ''); ?>>
                                                <?php echo e(__('Store Link')); ?>

                                            </label>
                                        </div>
                                        <div class="col-sm-4">
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && ($plan->enable_domain == 'on')): ?>
                                            <label
                                                class="btn btn-outline-primary btn-badge w-100 <?php echo e($enable_domain == 'on' ? 'active' : ''); ?>">
                                                <input type="radio" class="domain_click radio-button"
                                                    name="enable_domain" value="enable_domain" id="enable_domain"
                                                    <?php echo e($enable_domain == 'on' ? 'checked' : ''); ?>>
                                                <?php echo e(__('Custom Domain')); ?>

                                            </label>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                        <div class="col-sm-4">
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && ($plan->enable_subdomain == 'on')): ?>
                                            <label
                                                class="btn btn-outline-primary btn-badge w-100 <?php echo e($enable_subdomain == 'on' ? 'active' : ''); ?>">
                                                <input type="radio" class="domain_click radio-button"
                                                    name="enable_domain" value="enable_subdomain" id="enable_subdomain"
                                                    <?php echo e($enable_subdomain == 'on' ? 'checked' : ''); ?>>
                                                <?php echo e(__('Sub Domain')); ?>

                                            </label>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($domainPointing == 1): ?>
                                    <div class="text-sm mt-2" id="domainnote"
                                        style="<?php echo e($enable_domain == 'on' ? 'display: block' : ''); ?>">
                                        <span><b class="text-success"><?php echo e(__('Note : Before add Custom Domain, your domain A record is pointing to our server IP :')); ?><?php echo e($serverIp); ?>|
                                                <?php echo e(__('Your Custom Domain IP Is This: ')); ?></b></span>
                                    </div>
                                    <?php else: ?>
                                    <div class="text-sm mt-2" id="domainnote" style="display: none">
                                        <span><b><?php echo e(__('Note : Before add Custom Domain, your domain A record is pointing to our server IP :')); ?><?php echo e($serverIp); ?>|</b>
                                            <b class="text-danger"><?php echo e(__('Your Custom Domain IP Is This: ')); ?>

                                                <?php echo e($serverIp); ?></b></span>
                                    </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($subdomainPointing == 1): ?>
                                    <div class="text-sm mt-2" id="subdomainnote" style="display: none">
                                        <span><b class="text-success"><?php echo e(__('Note : Before add Sub Domain, your domain A record is pointing to our server IP :')); ?><?php echo e($serverIp); ?>|
                                                <?php echo e(__('Your Sub Domain IP Is This: ')); ?></b></span>
                                    </div>
                                    <?php else: ?>
                                    <div class="text-sm mt-2" id="subdomainnote" style="display: none">
                                        <span><b><?php echo e(__('Note : Before add Sub Domain, your domain A record is pointing to our server IP :')); ?><?php echo e($serverIp); ?>|</b>
                                            <b class="text-danger"><?php echo e(__('Your Sub Domain IP Is This: ')); ?></b></span>
                                    </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>

                                <div class="form-group col-md-6" id="StoreLink"
                                    style="<?php echo e($enable_storelink == 'on' ? 'display: block' : 'display: none'); ?>">
                                    <?php echo e(Form::label('store_link', __('Store Link'), ['class' => 'form-label'])); ?>

                                    <div class="input-group">
                                        <input type="text" value="<?php echo e(route('landing_page', $slug)); ?>" id="myInput"
                                            class="form-control d-inline-block me-2" aria-label="Recipient's username"
                                            aria-describedby="button-addon2" readonly>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary btn-badge" type="button"
                                                onclick="myFunction()" id="button-addon2"><i class="far fa-copy"></i>
                                                <?php echo e(__('Copy Link')); ?></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group col-md-6 domain"
                                    style="<?php echo e($enable_domain == 'on' ? 'display:block' : 'display:none'); ?>">
                                    <?php echo e(Form::label('store_domain', __('Custom Domain'), ['class' => 'form-label'])); ?>

                                    <?php echo e(Form::text('domains', $domains, ['class' => 'form-control', 'placeholder' => __('xyz.com')])); ?>

                                </div>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && ($plan->enable_subdomain == 'on')): ?>
                                <div class="form-group col-md-6 sundomain"
                                    style="<?php echo e($enable_subdomain == 'on' ? 'display:block' : 'display:none'); ?>">
                                    <?php echo e(Form::label('store_subdomain', __('Sub Domain'), ['class' => 'form-label'])); ?>

                                    <div class="input-group">
                                        <?php echo e(Form::text('subdomain', $slug, ['class' => 'form-control', 'placeholder' => __('Enter Domain'), 'readonly'])); ?>

                                        <div class="input-group-append">
                                            <span class="input-group-text"
                                                id="basic-addon2">.<?php echo e($subdomain_name); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php else: ?>
                                <div class="form-group col-md-6" id="StoreLink">
                                    <?php echo e(Form::label('store_link', __('Store Link'), ['class' => 'form-label'])); ?>

                                    <div class="input-group">
                                        <input type="text" value="<?php echo e(route('landing_page', $slug)); ?>" id="myInput"
                                            class="form-control d-inline-block" aria-label="Recipient's username"
                                            aria-describedby="button-addon2" readonly>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary btn-badge" type="button"
                                                onclick="myFunction()" id="button-addon2"><i class="far fa-copy"></i>
                                                <?php echo e(__('Copy Link')); ?></button>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <div class="form-group col-md-6">
                                    <?php echo e(Form::label('storejs', __('Store Custom JS'), ['class' => 'form-label'])); ?>

                                    <?php echo e(Form::textarea('storejs', null, ['class' => 'form-control', 'rows' => 3, 'placeholder' => __('console.log(hello);')])); ?>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['storejs'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                    <span class="invalid-about" role="alert">
                                        <strong class="text-danger"><?php echo e($message); ?></strong>
                                    </span>
                                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>

                                <div class="form-group col-md-6">
                                    <?php echo e(Form::label('storecss', __('Store Custom CSS'), ['class' => 'form-label'])); ?>

                                    <?php echo e(Form::textarea('storecss', null, ['class' => 'form-control', 'rows' => 3, 'placeholder' => __('Custom CSS')])); ?>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['storecss'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                    <span class="invalid-about" role="alert">
                                        <strong class="text-danger"><?php echo e($message); ?></strong>
                                    </span>
                                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>

                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end flex-wrap">
                            <?php if (app('laratrust')->hasPermission('Edit Store Setting')) : ?>
                            <input type="submit" value="<?php echo e(__('Save Changes')); ?>"
                                class="btn-submit btn btn-primary btn-badge">
                            <?php echo Form::close(); ?>

                            <?php endif; // app('laratrust')->permission ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade  <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-checkout-tab')): ?> show active <?php endif; ?>"
                id="pills-checkout" role="tabpanel" aria-labelledby="pills-checkout-tab">
                <div id="checkout_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> <?php echo e(__('Checkout Settings')); ?> </h5>

                        </div>
                        <div class="card-body p-4">
                            <?php echo e(Form::model($setting, ['route' => 'theme.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

                            <div class="row mt-2">
                                <input type="hidden" name="app_setting_tab" value="pills-checkout-tab">
                                <div class="form-group col-lg-6 col-sm-6 col-md-6">
                                    <div class="custom-control form-switch p-0">
                                        <label class="form-check-label mb-2 text-primary"
                                            for="additional_notes"><?php echo e(__('Additional Notes')); ?></label><br>
                                        <small class="mb-2 d-inline-block"> <?php echo e(__('Note')); ?>:
                                            <?php echo e(__('Enable the Additional Notes functionality to allow users to provide extra order-specific information on the checkout page.')); ?></small><br>
                                        <?php echo Form::hidden('additional_notes', 'off'); ?>

                                        <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                            data-onstyle="primary" name="additional_notes" id="additional_notes"
                                            <?php echo e(isset($Additional_notes) && $Additional_notes == 'on' ? 'checked="checked"' : ''); ?>>
                                    </div>
                                </div>

                                <div class="form-group col-lg-6 col-sm-6 col-md-6">
                                    <div class="custom-control form-switch p-0 ">
                                        <label class="form-check-label mb-2 text-primary"
                                            for="is_checkout_login_required"><?php echo e(__('Is Checkout Login Required')); ?></label><br>
                                        <small class="mb-2 d-inline-block"> <?php echo e(__('Note')); ?>:
                                            <?php echo e(__('Use the Is Checkout Required feature to prevent guest checkout, requiring users to log in before completing their purchase for added security.')); ?></small><br>
                                        <?php echo Form::hidden('is_checkout_login_required', 'off'); ?>

                                        <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                            data-onstyle="primary" name="is_checkout_login_required"
                                            id="is_checkout_login_required"
                                            <?php echo e(isset($is_checkout_login_required) && $is_checkout_login_required == 'on' ? 'checked="checked"' : ''); ?>>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end flex-wrap">
                            <?php if (app('laratrust')->hasPermission('Edit Store Setting')) : ?>
                            <input type="submit" value="<?php echo e(__('Save Changes')); ?>"
                                class="btn-submit btn btn-primary btn-badge">
                            <?php echo Form::close(); ?>

                            <?php endif; // app('laratrust')->permission ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade  <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-shippinglabel-tab')): ?> show active <?php endif; ?>"
                id="pills-shippinglabel" role="tabpanel" aria-labelledby="pills-shippinglabel-tab">
                <div id="shippinglabel_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> <?php echo e(__('Shipping Label Settings')); ?> </h5>

                        </div>
                        <?php echo e(Form::model($setting, ['route' => 'shippinglabel.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

                            <div class="card-body p-4">
                                <input type="hidden" name="app_setting_tab" value="pills-shippinglabel-tab">
                                <div class="row mt-2">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <?php echo e(Form::label('store_address', __('Address'), ['class' => 'form-label'])); ?>

                                            <?php echo e(Form::text('store_address', null, ['class' => 'form-control', 'placeholder' => 'Address'])); ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['store_Address'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                                <span class="invalid-store_Address" role="alert">
                                                    <strong class="text-danger"><?php echo e($message); ?></strong>
                                                </span>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <?php echo e(Form::label('store_city', __('City'), ['class' => 'form-label'])); ?>

                                            <?php echo e(Form::text('store_city', null, ['class' => 'form-control', 'placeholder' => 'City'])); ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['store_city'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                                <span class="invalid-store_city" role="alert">
                                                    <strong class="text-danger"><?php echo e($message); ?></strong>
                                                </span>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <?php echo e(Form::label('store_state', __('State'), ['class' => 'form-label'])); ?>

                                            <?php echo e(Form::text('store_state', null, ['class' => 'form-control', 'placeholder' => 'State'])); ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['store_state'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                                <span class="invalid-store_state" role="alert">
                                                    <strong class="text-danger"><?php echo e($message); ?></strong>
                                                </span>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <?php echo e(Form::label('store_country', __('Country'), ['class' => 'form-label'])); ?>

                                            <?php echo e(Form::text('store_country', null, ['class' => 'form-control', 'placeholder' => 'Country'])); ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['store_country'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                                <span class="invalid-store_country" role="alert">
                                                    <strong class="text-danger"><?php echo e($message); ?></strong>
                                                </span>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <?php echo e(Form::label('store_zipcode', __('Zipcode'), ['class' => 'form-label'])); ?>

                                            <?php echo e(Form::number('store_zipcode', null, ['class' => 'form-control', 'placeholder' => 'Zipcode'])); ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['store_zipcode'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                                <span class="invalid-store_zipcode" role="alert">
                                                    <strong class="text-danger"><?php echo e($message); ?></strong>
                                                </span>
                                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-end flex-wrap">
                                <?php if (app('laratrust')->hasPermission('Edit Store Setting')) : ?>
                                <input type="submit" value="<?php echo e(__('Save Changes')); ?>"
                                    class="btn-submit btn btn-primary btn-badge">
                                <?php endif; // app('laratrust')->permission ?>
                            </div>
                        <?php echo Form::close(); ?>

                    </div>
                </div>
            </div>
            <?php echo $__env->yieldPushContent('appSettingTabForm'); ?>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('custom-script'); ?>
<script>
function myFunction() {
    var copyText = document.getElementById("myInput");
    copyText.select();
    copyText.setSelectionRange(0, 99999)
    document.execCommand("copy");
    show_toastr('Success', "<?php echo e(__('Link copied')); ?>", 'success');
}

var scrollSpy = new bootstrap.ScrollSpy(document.body, {
    target: '#useradd-sidenav',
    offset: 300
});
</script>


<script>
$(function() {
    $('body').on('click', '.image_delete', function(e) {
        e.preventDefault();
        var id = $(this).attr('data-id');
        var data = {
            'image': id
        };
        // now make the ajax request
        $.ajax({
            type: "POST",
            dataType: "json",
            url: "#",
            data: data,
            context: this,
            success: function(data) {
                $(this).closest('.product_Image').remove();
                $('#loader').fadeOut();
                $('#Main_Page_Content_web_post').find('.submit_form').click();
            }
        });
    });
});

document.getElementById('tab-search').addEventListener('input', function() {
    var searchValue = this.value.toLowerCase();
    var navItems = document.querySelectorAll('#pills-tab .nav-item');

    navItems.forEach(function(item) {
        var tabText = item.querySelector('.nav-link').textContent.toLowerCase();
        if (tabText.includes(searchValue)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
});
</script>
<?php $__env->stopPush(); ?>
                <!-- Tab content -->

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/AppSetting/index.blade.php ENDPATH**/ ?>