<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($combinations[0]) > 0): ?>
    <div class="card-body">
        <div class="faq" id="accordionExample">
            <div class="row">
                <div class="col-12">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $combinations; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $combination): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php
                            $sku = $product_name;

                            $str = '';
                            foreach ($combination as $key => $item) {
                                if ($key > 0) {
                                    $str .= '-' . str_replace(' ', '', $item);
                                    $sku .= '-' . str_replace(' ', '', $item);
                                } else {
                                    $str .= str_replace(' ', '', $item);
                                    $sku .= '-' . str_replace(' ', '', $item);
                                }
                            }
                        ?>
                        <div class="form-group accordion accordion-flush" id="payment-gateways">
                            <div id="" class="accordion-item card attribute_options_datas">
                                <div class="accordion-item card media remove_<?php echo e($str); ?> remove_option_<?php echo e($str); ?>">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(strlen($str) > 0): ?>
                                        <?php
                                            $ProductVariantData = $product->ProductVariant($str);
                                            $variationOptions = [];
                                            if ($ProductVariantData) {
                                                $variationOptions = explode(',', $ProductVariantData->variation_option);
                                            }
                                        ?>
                                        <h2 class="accordion-header" id="COD">
                                            <button class="accordion-button collapsed according-delete-input"
                                                type="button" data-bs-toggle="collapse"
                                                data-bs-target="#collapseone_<?php echo e($str); ?>" aria-expanded="false"
                                                aria-controls="collapseone_<?php echo e($str); ?>">
                                                <span class="d-flex align-items-center">
                                                    <i class="ti ti-credit-card me-2"></i><?php echo e($str); ?>

                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($ProductVariantData->id)): ?>
                                                        <a href="#"
                                                            class="action-btn btn-danger  btn btn-sm d-inline-flex align-items-center delete-comment"
                                                            data-url="<?php echo e(route('product.attribute.delete', $ProductVariantData->id)); ?>"
                                                            data-id="<?php echo e($ProductVariantData->id); ?>">
                                                            <i class="ti ti-trash"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a class="btn btn-sm btn-danger delete_option"
                                                            data-id="<?php echo e($str); ?>">
                                                            <i class="ti ti-trash text-white py-1"></i>
                                                        </a>
                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                </span>
                                            </button>
                                        </h2>

                                        <div id="collapseone_<?php echo e($str); ?>" class="accordion-collapse collapse"
                                            aria-labelledby="COD" data-bs-parent="#accordionExample">
                                            <div class="accordion-body">
                                                <div class="row">
                                                    <div class="form-group col-md-3">
                                                        <div class="form-check">
                                                            <input class="form-check-input input-primary enable_view"
                                                                value="enabled"
                                                                name="variation_option_<?php echo e($str); ?>[]"
                                                                type="checkbox" id="enable_view_<?php echo e($str); ?>"
                                                                <?php if(in_array('enabled', $variationOptions)): ?> checked <?php endif; ?>>
                                                            <label class="form-check-label"
                                                                for="enable_view_<?php echo e($str); ?>"><?php echo e(__('Enabled')); ?></label>
                                                        </div>
                                                    </div>
                                                    <div class="form-group col-md-3">
                                                        <div class="form-check">
                                                            <input class="form-check-input input-primary downloadable"
                                                                value="downloadable_product"
                                                                name="variation_option_<?php echo e($str); ?>[]"
                                                                type="checkbox"
                                                                id="downloadable_product_<?php echo e($str); ?>"
                                                                <?php if(in_array('downloadable_product', $variationOptions)): ?> checked <?php endif; ?>>
                                                            <label class="form-check-label downloadable"
                                                                for="downloadable_product_<?php echo e($str); ?>"><?php echo e(__('Downloadable')); ?></label>
                                                        </div>
                                                    </div>
                                                    <div class="form-group col-md-3">
                                                        <div class="form-check">
                                                            <input
                                                                class="form-check-input input-primary virtual_product"
                                                                value="virtual_product"
                                                                name="variation_option_<?php echo e($str); ?>[]"
                                                                type="checkbox"
                                                                id="virtual_product_<?php echo e($str); ?>"
                                                                <?php if(in_array('virtual_product', $variationOptions)): ?> checked <?php endif; ?>>
                                                            <label class="form-check-label"
                                                                for="virtual_product_<?php echo e($str); ?>"><?php echo e(__('Virtual')); ?></label>
                                                        </div>
                                                    </div>
                                                    <div class="form-group col-md-3">
                                                        <div class="form-check">
                                                            <input class="form-check-input input-primary manage_stock"
                                                                value="manage_stock" type="checkbox"
                                                                name="variation_option_<?php echo e($str); ?>[]"
                                                                id="manage_stock_<?php echo e($str); ?>"
                                                                <?php if(in_array('manage_stock', $variationOptions)): ?> checked <?php endif; ?>>
                                                            <label class="form-check-label"
                                                                for="manage_stock_<?php echo e($str); ?>"><?php echo e(__('Manage stock?')); ?></label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="form-group col-md-6 sku">
                                                        <?php echo Form::label('', __('SKU'), ['class' => 'form-label']); ?>

                                                        <?php echo Form::text('product_sku_' . $str, $sku, ['class' => 'form-control']); ?>

                                                    </div>
                                                    <div class="form-group col-md-6 weight-div product_weights_<?php echo e($str); ?>"
                                                        data-id="<?php echo e($str); ?>">
                                                        <?php echo Form::label('', __('Weight(Kg)'), ['class' => 'form-label ']); ?>

                                                        <?php echo Form::number(
                                                            'product_weight_' . $str,
                                                            !empty($ProductVariantData->weight) ? $ProductVariantData->weight : 0,
                                                            ['class' => 'form-control', 'min' => '0', 'step' => '0.01'],
                                                        ); ?>

                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="form-group col-md-6">
                                                        <?php echo Form::label('', __('Variation Price'), ['class' => 'form-label']); ?>

                                                        <?php echo Form::number(
                                                            'product_variation_price_' . $str,
                                                            !empty($ProductVariantData->variation_price) ? $ProductVariantData->variation_price : 0,
                                                            ['class' => 'form-control', 'min' => '0', 'step' => '0.01'],
                                                        ); ?>

                                                    </div>
                                                    <div class="form-group col-md-6">
                                                        <?php echo Form::label('', __('Sale Price'), ['class' => 'form-label']); ?>

                                                        <?php echo Form::number(
                                                            'product_sale_price_' . $str,
                                                            !empty($ProductVariantData->price) ? $ProductVariantData->price : 0,
                                                            ['class' => 'form-control', 'min' => '0', 'step' => '0.01'],
                                                        ); ?>

                                                    </div>
                                                </div>
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ProductVariantData): ?>
                                                    <div class="form-group col-md-12 shipping-div">
                                                        <?php echo Form::label('', __('Shipping'), ['class' => 'form-label']); ?>

                                                        <?php echo Form::select('shipping_id_' . $str, $Shipping, $ProductVariantData->shipping, [
                                                            'class' => 'form-control',
                                                            'data-role' => 'tagsinput',
                                                            'id' => 'Shipping',
                                                        ]); ?>

                                                    </div>
                                                <?php else: ?>
                                                    <div class="form-group col-md-12 shipping-div">
                                                        <?php echo Form::label('', __('Shipping'), ['class' => 'form-label']); ?>

                                                        <?php echo Form::select('shipping_id_' . $str, $Shipping, null, [
                                                            'class' => 'form-control',
                                                            'data-role' => 'tagsinput',
                                                            'id' => 'Shipping',
                                                        ]); ?>

                                                    </div>
                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ProductVariantData != null && $ProductVariantData->stock_status != null): ?>
                                                    <div class="form-group col-md-12 stock_status">
                                                        <?php echo Form::label('', __('Stock Status'), ['class' => 'form-label']); ?>

                                                        <?php echo Form::select(
                                                            'stock_status_' . $str,
                                                            [
                                                                '' => 'Select option',
                                                                'in_stock' => 'In Stock',
                                                                'out_of_stock' => 'Out Of Stock',
                                                                'on_backorder' => 'On Backorder',
                                                            ],
                                                            $ProductVariantData->stock_status,
                                                            ['class' => 'form-control'],
                                                        ); ?>

                                                    </div>
                                                <?php else: ?>
                                                    <div class="form-group col-md-12 stock_status">
                                                        <?php echo Form::label('', __('Stock Status'), ['class' => 'form-label']); ?>

                                                        <?php echo Form::select(
                                                            'stock_status_' . $str,
                                                            [
                                                                '' => 'Select option',
                                                                'in_stock' => 'In Stock',
                                                                'out_of_stock' => 'Out Of Stock',
                                                                'on_backorder' => 'On Backorder',
                                                            ],
                                                            null,
                                                            ['class' => 'form-control'],
                                                        ); ?>

                                                    </div>
                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                <div class="form-group col-md-12">
                                                    <?php echo Form::label('', __('Description'), ['class' => 'form-label']); ?>

                                                    <?php echo Form::textarea(
                                                        'product_description_' . $str,
                                                        !empty($ProductVariantData->description) ? $ProductVariantData->description : '',
                                                        ['rows' => 4, 'class' => 'form-control'],
                                                    ); ?>

                                                </div>
                                                <div class="row col-md-12 d-none enable_manage_stock manageble_stock_<?php echo e($str); ?>"
                                                    id="enable_manage_stock" data-id="<?php echo e($str); ?>">
                                                    <div class="form-group col-md-4 ">
                                                        <?php echo Form::label('', __('Stock'), ['class' => 'form-label']); ?>

                                                        <?php echo Form::number('product_stock_' . $str, !empty($ProductVariantData->stock) ? $ProductVariantData->stock : 0, [
                                                            'class' => 'form-control productVariantStock',
                                                        ]); ?>

                                                    </div>
                                                    <div class="form-group col-md-5">
                                                        <?php echo Form::label('', __('Allow BackOrders:'), ['class' => 'form-label']); ?>

                                                        <div class="form-check m-1">
                                                            <input type="radio" id="not_allow_<?php echo e($str); ?>" value="not_allow"
                                                                name="stock_order_status_<?php echo e($str); ?>"
                                                                class="form-check-input"
                                                                <?php echo e($ProductVariantData ? ($ProductVariantData->stock_order_status == 'not_allow' ? 'checked' : '') : ''); ?>>
                                                            <label class="form-check-label"
                                                                for="not_allow_<?php echo e($str); ?>"><?php echo e(__('Do Not Allow')); ?></label>
                                                        </div>
                                                        <div class="form-check m-1">
                                                            <input type="radio" id="notify_customer_<?php echo e($str); ?>"
                                                                value="notify_customer"
                                                                name="stock_order_status_<?php echo e($str); ?>"
                                                                class="form-check-input"
                                                                <?php echo e($ProductVariantData ? ($ProductVariantData->stock_order_status == 'notify_customer' ? 'checked' : '') : ''); ?>>
                                                            <label class="form-check-label"
                                                                for="notify_customer_<?php echo e($str); ?>"><?php echo e(__('Allow, But notify customer')); ?></label>
                                                        </div>
                                                        <div class="form-check m-1">
                                                            <input type="radio" id="allow_<?php echo e($str); ?>" value="allow"
                                                                name="stock_order_status_<?php echo e($str); ?>"
                                                                class="form-check-input code"
                                                                <?php echo e($ProductVariantData ? ($ProductVariantData->stock_order_status == 'allow' ? 'checked' : '') : ''); ?>>
                                                            <label class="form-check-label"
                                                                for="allow_<?php echo e($str); ?>"><?php echo e(__('Allow')); ?></label>
                                                        </div>
                                                    </div>
                                                    <div class="form-group col-md-3 ">
                                                        <?php echo Form::label('', __('Low stock threshold'), ['class' => 'form-label ']); ?>

                                                        <?php echo Form::number(
                                                            'low_stock_threshold_' . $str,
                                                            !empty($ProductVariantData->low_stock_threshold) ? $ProductVariantData->low_stock_threshold : 0,
                                                            ['class' => 'form-control', 'min' => '0', 'step' => '0.01'],
                                                        ); ?>

                                                    </div>
                                                </div>
                                                <input type="hidden" class="options_data_<?php echo e($str); ?>[]"
                                                    name="options_datas[]" value="<?php echo e($str); ?>">
                                                <div class="row download-product d-none down_product_<?php echo e($str); ?>"
                                                    data-id="<?php echo e($str); ?>" id="download-product">
                                                    <div class="form-group mb-0">
                                                        <label for="downloadable_product"
                                                            class="form-label"><?php echo e(__('Downloadable Product')); ?></label>
                                                            <input type="file"
                                                            name="downloadable_product_<?php echo e($str); ?>"
                                                            id="downloadable_product" class="form-control downloadable_product_variant"
                                                            data-value="downloadable_product_<?php echo e($str); ?>"
                                                            onchange="document.getElementById('down_products')?.setAttribute('src', window.URL.createObjectURL(this.files[0]))">

                                                     <img id="down_products" src="" width="20%" class="mt-2" />

                                                    </div>
                                                </div>
                                                <?php
                                                    $module = App\Facades\ModuleFacade::find('CartQuantityControl');
                                                    $maximum_quantity = !empty($ProductVariantData->maximum_quantity)? $ProductVariantData->maximum_quantity: '';
                                                    $minimum_quantity = !empty($ProductVariantData->minimum_quantity)? $ProductVariantData->minimum_quantity: '';
                                                    $setting = getAdminAllSetting(Auth::user()->id);
                                                ?>
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($module) && $module->isEnabled()): ?>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(Auth::check() && strpos(Auth::user()->currentPlan['modules'], 'CartQuantityControl')): ?>
                                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['cart_quantity_control_enable']) && $setting['cart_quantity_control_enable'] == 'on'): ?>
                                                            <?php echo $__env->make('cart-quantity-control::pages.editCartQuantityControlVariant',['str' => $str,'minimum_quantity' => $minimum_quantity,'maximum_quantity' => $maximum_quantity,], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<script src="<?php echo e(asset('public/js/jquery-3.6.0.min.js')); ?>"></script>

<script>
    $(document).on('click', '.delete_option', function(event) {
        event.preventDefault();

        var id = $(this).attr('data-id');

        $(this).closest('.attribute_options_datas').remove();


    });

    $(function() {
        $('body').on('click', '.delete-comment', function(e) {
            e.preventDefault();
            var id = $(this).attr('data-id');
            var url = $(this).attr('data-url');

            var data = {
                'data': id
            };
            // now make the ajax request
            $.ajax({
                type: "DELETE",
                url: url,
                data: data,
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr(
                        'content') // Include CSRF token
                },
                context: this,
                success: function(data) {
                    $('#loader').fadeOut();
                    show_toastr('<?php echo e(__('Success')); ?>',
                        '<?php echo e(__('Variant Deleted Successfully!')); ?>', 'success');
                    $(this).closest('.media').remove();
                }
            });
        });
    });

    $('.enable_view').change(function() {
        if ($(this).prop('checked') == true) {
            var optionValue = $(this).val();
            if (optionValue == 'enabled') {}
        }
    });

    $(document).on('change', '.downloadable', function() {
        var optionValue = $(this).val();
        var container = $(this).closest('.accordion-item');

        if ($(this).prop('checked')) {
            if (optionValue == 'downloadable_product') {
                container.find('.download-product').removeClass('d-none');
            }
        } else {
            container.find('.download-product').addClass('d-none');
        }
    });

    $(document).on('change', '.virtual_product', function() {
        var optionValue = $(this).val();
        var container = $(this).closest('.accordion-item');

        if ($(this).prop('checked')) {
            if (optionValue == 'virtual_product') {
                container.find('.weight-div').addClass('d-none');
                container.find('.shipping-div').addClass('d-none');
            }
        } else {
            container.find('.weight-div').removeClass('d-none');
            container.find('.shipping-div').addClass('d-none');
        }
    });

    $(document).on('change', '.manage_stock', function() {
        var optionValue = $(this).val();
        var container = $(this).closest('.accordion-item');

        if ($(this).prop('checked')) {
            if (optionValue == 'manage_stock') {
                container.find('.enable_manage_stock').removeClass('d-none');
                container.find('.stock_status').hide();
            }
        } else {
            container.find('.enable_manage_stock').addClass('d-none');
            container.find('.stock_status').show();
        }
    });


    $(document).ready(function() {
        $('.accordion-item.card.attribute_options_datas').each(function() {

            $('.download-product').each(function() {
                var downProduct = $(this);
                var downProductId = downProduct.attr('data-id');
                var downloadableCheckbox = downProduct.closest('.accordion-item').find(
                    '.downloadable');
                if (downloadableCheckbox.prop('checked') == true) {
                    $('.down_product_' + downProductId).removeClass('d-none');
                } else {
                    $('.down_product_' + downProductId).addClass('d-none');
                }
            });

            // Handle virtual product checkbox
            $('.weight-div').each(function() {
                var downProduct = $(this);
                var downProductId = downProduct.attr('data-id');
                var downloadableCheckbox = downProduct.closest('.accordion-item').find(
                    '.virtual_product');
                if (downloadableCheckbox.prop('checked') == true) {
                    $('.product_weights_' + downProductId).addClass('d-none');
                } else {
                    $('.product_weights_' + downProductId).removeClass('d-none');
                }
            });

            // Handle manage stock checkbox
            $('.enable_manage_stock').each(function() {
                var downProduct = $(this);
                var downProductId = downProduct.attr('data-id');
                var downloadableCheckbox = downProduct.closest('.accordion-item').find(
                    '.manage_stock');
                if (downloadableCheckbox.prop('checked') == true) {
                    $('.manageble_stock_' + downProductId).removeClass('d-none');
                } else {
                    $('.manageble_stock_' + downProductId).addClass('d-none');
                }
            });


        });
    });

    $('#enable_product_stock').change(function() {

        if ($(this).prop('checked') == true) {
            $('.stock_status').hide();
        } else {
            $('.stock_status').show();
        }
    });
    $(document).ready(function() {
        if ($('.enable_product_stock').prop('checked') == true) {
            $('.stock_status').hide();
        } else {
            $('.stock_status').show();
        }

    });
</script>
<?php /**PATH /home/storesyria/public_html/resources/views/product/attribute_combinations_edit.blade.php ENDPATH**/ ?>