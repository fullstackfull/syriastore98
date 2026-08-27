<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

<div class="modal-body">
    <div class="row">
        <div class="col-md-6 col-sm-12 col-lg-6 ">
            <div class="">
                <div class="card-header d-flex justify-content-between">
                    <h5 class=""><?php echo e(__('Shipping Information')); ?></h5>
                </div>
                <div class="card-body pt-0">
                    <address class="mb-0 text-sm">
                        <dl class="row mt-4 align-items-center">
                            <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Name')); ?></dt>
                            <dd class="col-sm-9 text-sm">
                                <?php echo e(!empty($order['delivery_informations']['name']) ? $order['delivery_informations']['name'] : ''); ?>

                            </dd>
                            <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Email')); ?></dt>
                            <dd class="col-sm-9 text-sm">
                                <?php echo e(!empty($order['delivery_informations']['email']) ? $order['delivery_informations']['email'] : ''); ?>

                            </dd>
                            <dt class="col-sm-3 h6 text-sm"><?php echo e(__('City')); ?></dt>
                            <dd class="col-sm-9 text-sm">
                                <?php echo e(!empty($order['delivery_informations']['city']) ? $order['delivery_informations']['city'] : ''); ?>

                            </dd>
                            <dt class="col-sm-3 h6 text-sm"><?php echo e(__('State')); ?></dt>
                            <dd class="col-sm-9 text-sm">
                                <?php echo e(!empty($order['delivery_informations']['state']) ? $order['delivery_informations']['state'] : ''); ?>

                            </dd>
                            <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Country')); ?></dt>
                            <dd class="col-sm-9 text-sm">
                                <?php echo e(!empty($order['delivery_informations']['country']) ? $order['delivery_informations']['country'] : ''); ?>

                            </dd>
                            <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Postal Code')); ?></dt>
                            <dd class="col-sm-9 text-sm">
                                <?php echo e(!empty($order['delivery_informations']['post_code']) ? $order['delivery_informations']['post_code'] : ''); ?>

                            </dd>
                            <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Phone')); ?></dt>
                            <dd class="col-sm-9 text-sm">
                                <a href="https://api.whatsapp.com/send?phone=<?php echo e(!empty($order['delivery_informations']['phone']) ? $order['delivery_informations']['phone'] : ''); ?>&amp;text=Hi"
                                    target="_blank">
                                    <?php echo e(!empty($order['delivery_informations']['phone']) ? $order['delivery_informations']['phone'] : ''); ?>

                                </a>
                            </dd>
                        </dl>
                    </address>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-sm-12 col-lg-6 ">
            <div class="">
                <div class="card-header d-flex justify-content-between">
                    <h5 class=""><?php echo e(__('Billing Information')); ?></h5>
                </div>
                <div class="card-body pt-0">
                    <dl class="row mt-4 align-items-center">
                        <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Name')); ?></dt>
                        <dd class="col-sm-9 text-sm">
                            <?php echo e(!empty($order['billing_informations']['name']) ? $order['billing_informations']['name'] : ''); ?>

                        </dd>
                        <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Email')); ?></dt>
                        <dd class="col-sm-9 text-sm">
                            <?php echo e(!empty($order['billing_informations']['email']) ? $order['billing_informations']['email'] : ''); ?>

                        </dd>
                        <dt class="col-sm-3 h6 text-sm"><?php echo e(__('City')); ?></dt>
                        <dd class="col-sm-9 text-sm">
                            <?php echo e(!empty($order['billing_informations']['city']) ? $order['billing_informations']['city'] : ''); ?>

                        </dd>
                        <dt class="col-sm-3 h6 text-sm"><?php echo e(__('State')); ?></dt>
                        <dd class="col-sm-9 text-sm">
                            <?php echo e(!empty($order['billing_informations']['state']) ? $order['billing_informations']['state'] : ''); ?>

                        </dd>
                        <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Country')); ?></dt>
                        <dd class="col-sm-9 text-sm">
                            <?php echo e(!empty($order['billing_informations']['country']) ? $order['billing_informations']['country'] : ''); ?>

                        </dd>
                        <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Postal Code')); ?></dt>
                        <dd class="col-sm-9 text-sm">
                            <?php echo e(!empty($order['billing_informations']['post_code']) ? $order['billing_informations']['post_code'] : ''); ?>

                        </dd>
                        <dt class="col-sm-3 h6 text-sm"><?php echo e(__('Phone')); ?></dt>
                        <dd class="col-sm-9 text-sm">
                            <a href="https://api.whatsapp.com/send?phone=<?php echo e(!empty($order['billing_informations']['phone']) ? $order['billing_informations']['phone'] : ''); ?>&amp;text=Hi"
                                target="_blank">
                                <?php echo e(!empty($order['billing_informations']['phone']) ? $order['billing_informations']['phone'] : ''); ?>

                            </a>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
    <div class="row modal-shipping-row">
        <div class="col-md-6">
            <b class="col-sm-3 h6 text-sm mx-2 shipping-text"><?php echo e(__('Payment Type')); ?></b>
            <?php echo e($order['paymnet_type']); ?>

        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($orders->delivery_id != 0): ?>
            <?php
                $shipping = \App\Models\Shipping::find($orders->delivery_id);
            ?>
            <div class="col-md-6">
                <b class="col-sm-3 h6 text-sm mx-2 "><?php echo e(__('Shipping Method')); ?></b>
                <?php echo e($shipping->name ?? ''); ?>

            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div><br>
    <div class="row">
        <div class="col-lg-12">
            <div class="table-responsive">

            <table class="table modal-table">
                <thead>
                    <tr>
                        <th><?php echo e(__('Item')); ?></th>
                        <th><?php echo e(__('Quantity')); ?></th>
                        <th><?php echo e(__('Total')); ?></th>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($order['order_status'] == 1): ?>
                            <th><?php echo e(__('Downloadable Product')); ?></th>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $order['product']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php
                            $variant = \App\Models\ProductVariant::where('id', $item['variant_id'])->first();
                            $product = \App\Models\Product::where('id', $item['product_id'])->first();
                        ?>

                        <tr>
                            <td class="total">
                                <span class="h6 text-sm"> <a href="#"><?php echo e($item['name']); ?></a>
                                </span> <br>
                                <span class="text-sm"> <?php echo e($item['variant_name']); ?> </span>
                            </td>
                            <td>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($order['paymnet_type'] == 'POS'): ?>
                                    <?php echo e($item['quantity']); ?>

                                <?php else: ?>
                                    <?php echo e($item['qty']); ?>

                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </td>
                            <td>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($order['paymnet_type'] == 'POS'): ?>
                                    <?php echo e(currency_format_with_sym(($item['orignal_price'] ?? 0) * ($item['quantity'] ?? 0), getCurrentStore()) ?? SetNumberFormat(($item['orignal_price'] ?? 0) * ($item['quantity'] ?? 0))); ?>

                                <?php else: ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductPricing') && isset($item['sale_price'])): ?>
                                        <?php echo e(currency_format_with_sym($item['sale_price'] ?? 0, getCurrentStore()) ?? SetNumberFormat($item['sale_price'])); ?>

                                    <?php else: ?>
                                        <?php echo e(currency_format_with_sym($item['final_price'] ?? 0, getCurrentStore()) ?? SetNumberFormat($item['final_price'])); ?>

                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </td>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product || $variant): ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($order['order_status'] == 1): ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($variant->downloadable_product) != null || !empty($product->downloadable_product) != null): ?>
                                        <td>
                                            <?php if(!empty($variant->downloadable_product)): ?>
                                                <a class="downloadable_product_<?php echo e($item['product_id']); ?>"
                                                    href="<?php echo e(get_file($variant->downloadable_product)); ?>" download
                                                    style="display: none;"></a>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($product->downloadable_product)): ?>
                                                <a class="downloadable_product_<?php echo e($item['product_id']); ?>"
                                                    href="<?php echo e(get_file($product->downloadable_product)); ?>" download
                                                    style="display: none;"></a>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            <button
                                                class="download-btn action-btn btn-primary btn btn-sm align-items-center"
                                                data-bs-toggle="tooltip" data-bs-placement="top"
                                                title="<?php echo e(__('Download')); ?>"
                                                data-product-id="<?php echo e($item['product_id']); ?>">
                                                <i class="ti ti-download "></i>
                                            </button>
                                        </td>
                                    <?php else: ?>
                                        <td>-</td>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>

        </div>
    </div>
    <div class="row modal-bottom-btn-wrapper">
        <div class="col-md-3 co-12">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($order['order_status'] == 0): ?>
                <a href="#" class="btn btn-sm btn-info me-2 delivered-button-status">
                    <?php echo e(__('Delivered')); ?>

                </a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <div class="col-md-3 col-12 text-end">
            <a href="<?php echo e(route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order['id']))); ?>"
                class="btn btn-sm btn-success me-2">
                <?php echo e(__('Edit Order')); ?>

            </a>
        </div>
    </div>
    <script>
        $(document).ready(function() {
            $(".delivered-button-status").click(function(event) {
                event.preventDefault();

                var csrfToken = $('meta[name="csrf-token"]').attr('content');
                var orderId = $(this).data("order-id");
                $.ajax({
                    type: "POST",
                    url: "<?php echo e(route('order.order_status_update', ['id' => $order['id']])); ?>",
                    data: {
                        "_token": csrfToken
                    },
                    success: function(response) {
                        show_toastr('<?php echo e(__('Success')); ?>',
                            '<?php echo e(__('Status Updated Successfully!')); ?>', 'success');
                        $('#commanModel').modal('hide');
                        location.reload(); // Reload the page
                        $('#loader').fadeOut();
                    },
                    error: function(xhr, textStatus, errorThrown) {
                        $('#loader').fadeOut();
                    }
                });
            });

        });
        document.querySelectorAll('.download-btn').forEach(button => {
            button.addEventListener('click', function() {
                const productId = this.getAttribute('data-product-id');
                const downloadLink = document.querySelector('.downloadable_product_' + productId);
                if (downloadLink) {
                    downloadLink.click();
                } else {
                    console.error('Download link not found for product ID:', productId);
                }
            });
        });
    </script>
<?php /**PATH /home/storesyria/public_html/resources/views/order/order_show.blade.php ENDPATH**/ ?>