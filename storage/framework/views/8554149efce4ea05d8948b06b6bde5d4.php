<div class="tab-pane fade  <?php if(isset($app_setting_tab) && $app_setting_tab == 'pills-boost_sales-tab'): ?> show active <?php endif; ?>" id="pills-boost_sales" role="tabpanel"
    aria-labelledby="pills-boost_sales-tab">
    <div id="boost_sales_settings">
        <div class="card">
            <?php echo e(Form::model($setting, ['route' => 'boost.sales.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

            <?php echo csrf_field(); ?>
            <input type="hidden" name="app_setting_tab" value="pills-boost_sales-tab">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> <?php echo e(__('Boost Sale Settings')); ?> </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary"
                            class="boost_sales_enabled" name="boost_sales_enabled" id="boost_sales_enabled"
                            <?php echo e(isset($setting['boost_sales_enabled']) && $setting['boost_sales_enabled'] == 'on' ? 'checked' : ''); ?>>
                        <label class="custom-control-label" for="boost_sales_enabled"></label>
                    </div>
                </div>
            </div>
            <div class="card-body slider-body-rows boost-sales p-4">
                <div class="row mt-2">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label"
                                for="example3cols3Input"><?php echo e(__('Which data you want to show ?')); ?></label>
                            <div class="row ms-1">
                                <div class="form-check col-md-4">
                                    <input class="form-check-input" type="radio" name="boost_sales_product_display"
                                        value="default product" <?php if(!isset($setting['boost_sales_product_display']) || $setting['boost_sales_product_display'] == 'default product'): ?> checked <?php endif; ?>
                                        id="defaultProduct">
                                    <label class="form-check-label" for="defaultProduct">
                                        <?php echo e(__('Default Product')); ?>

                                    </label>
                                </div>
                                <div class="form-check col-md-4">
                                    <input class="form-check-input" type="radio" name="boost_sales_product_display"
                                        value="selected product" <?php if(isset($setting['boost_sales_product_display']) && $setting['boost_sales_product_display'] == 'selected product'): ?> checked <?php endif; ?>
                                        id="selectedProduct">
                                    <label class="form-check-label" for="selectedProduct">
                                        <?php echo e(__('Selected Product')); ?>

                                    </label>
                                </div>
                                <div class="form-check col-md-4">
                                    <input class="form-check-input" type="radio" name="boost_sales_product_display"
                                        value="both" <?php if(isset($setting['boost_sales_product_display']) && $setting['boost_sales_product_display'] == 'both'): ?> checked <?php endif; ?>
                                        id="bothProduct">
                                    <label class="form-check-label" for="bothProduct">
                                        <?php echo e(__('Both')); ?>

                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                        $get_product =
                            isset($setting['boost_sale_product_id']) && !empty($setting['boost_sale_product_id'])
                                ? explode(',', $setting['boost_sale_product_id'])
                                : [];
                    ?>
                    <div class="col-md-6 boost_sale_product">
                        <div class="form-group">
                            <?php echo e(Form::label('boost_sale_product_id', __('Select Products'), ['class' => ' form-label'])); ?>

                            <select name="boost_sale_product_id[]" data-role="tagsinput" id="boost_sale_product_id"
                                multiple>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $Key => $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <option <?php if(in_array($product->id, $get_product)): ?> selected <?php endif; ?> value=<?php echo e($product->id); ?>>
                                        <?php echo e($product->name); ?></option>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                
                <input type="submit" value="<?php echo e(__('Save Changes')); ?>" class="btn-submit btn btn-primary btn-badge">
                
            </div>
            <?php echo Form::close(); ?>

        </div>
    </div>
</div>
<?php $__env->startPush('custom-script'); ?>
    <script>
        if ($('.boost_sales_enabled').is(':checked')) {
            $('.boost-sales').removeClass('disabledCookie');
        } else {
            $('.boost-sales').addClass('disabledCookie');
        }

        $('.boost_sales_enabled').on('change', function() {
            if ($('.boost_sales_enabled').is(':checked')) {
                $('.boost-sales').removeClass('disabledCookie');
            } else {
                $('.boost-sales').addClass('disabledCookie');
            }
        });

        if ($('#defaultProduct').is(':checked')) {
            $('.boost_sale_product').addClass('d-none');
        }
        $('#defaultProduct').on('click', function() {
            $('.boost_sale_product').addClass('d-none');
        });
        $('#selectedProduct').on('click', function() {
            $('.boost_sale_product').removeClass('d-none');
        });
        $('#bothProduct').on('click', function() {
            $('.boost_sale_product').removeClass('d-none');
        });
    </script>
<?php $__env->stopPush(); ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/BoostSales/src/app/Providers/../../resources/views/admin/boostSalesSettingDetails.blade.php ENDPATH**/ ?>