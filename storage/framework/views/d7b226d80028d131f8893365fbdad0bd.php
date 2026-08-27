<?php $currency_icon = \App\Models\Utility::GetValueByName('CURRENCY', getCurrentStore()); ?>

<?php $__env->startSection('page-title', __('POS')); ?>
<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item"><?php echo e(__('POS')); ?></li>
<?php $__env->stopSection(); ?>
<?php $__env->startPush('css'); ?>
<style>
    .pos-product-text{
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 1;
        overflow: hidden;
    }
</style>
<?php $__env->stopPush(); ?>
<?php $__env->startSection('content'); ?>
    <div class="container-fluid px-2">
        <?php $lastsegment = request()->segment(count(request()->segments())) . '_' . getCurrentStore(); ?>
        <div class="mt-2 row product-tab-wrp">
            <div class="card-header p-2">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <h3 class="mb-0 p-2"><?php echo e(__('Product Section')); ?></h3>
                    </div>
                </div>
            </div>
            <div class="category-wrp">
                <div class="row">
                    <div class="col-lg-8 col-12">
                        <div class="button-list b-bottom catgory-pad mb-4">
                            <div class="row form-row m-0 pro-cat" id="categories-listing">
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-12">
                        <div class="search-bar-left">
                            <form class="mb-0">
                                <div class="row gap-1">
                                    <div class="col-12 ">
                                        <div class="search-btn d-flex gap-2" style="justify-content: flex-end;">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text"><i class="ti ti-search"></i></span>
                                                </div>
                                                <input id="searchproduct" type="text"
                                                    data-url="<?php echo e(route('search.products')); ?>"
                                                    placeholder="<?php echo e(__('Search Product')); ?>"
                                                    class="form-control pr-4 rounded-right">
                                            </div>
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text"><i class="ti ti-search"></i></span>
                                                </div>
                                                <input id="searchproductsku" type="text"
                                                    data-url="<?php echo e(route('search.products.sku')); ?>"
                                                    placeholder="<?php echo e(__('Search by SKU')); ?>"
                                                    class="form-control pr-4 rounded-right">
                                            </div>
                                            <?php echo $__env->yieldPushContent('AddBarcodeSearch'); ?>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-7 col-12">
                <div class="sop-card card mb-0">
                    <div class="card-body p-3">
                        <div class="right-content">
                            <div class="product-body-nop">
                                <div class="form-row row" id="product-listing">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5 col-12 ps-lg-0">
                <div class="sop-card billing-table card m-0 h-100">
                    <div class="card-header p-2">
                        <div class="row">
                            <div class="col-md-6">
                                <h3 class="mb-0 p-2"><?php echo e(__('Billing Section')); ?></h3>
                            </div>
                            <div class="col-md-6">
                                <?php echo e(Form::select('customer_id', $customers, '', ['class' => 'form-control select customer_select', 'id' => 'customer', 'required' => 'required'])); ?>

                                <?php echo e(Form::hidden('vc_name_hidden', '', ['id' => 'vc_name_hidden'])); ?>

                                <input type="hidden" id="store_id" value="<?php echo e(getCurrentStore()); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-body carttable cart-product-list carttable-scroll d-flex flex-column h-100 justify-content-between"
                        id="carthtml">
                        <?php $total = 0 ?>
                        <div class="table-responsive">
                            <table class="table pos-table">
                                <thead>
                                    <tr>
                                        <th> Image</th>
                                        <th class="text-left"><?php echo e(__('Name')); ?></th>
                                        <th class="text-center"><?php echo e(__('QTY')); ?></th>
                                        <th><?php echo e(__('Tax')); ?></th>
                                        <th class="text-center"><?php echo e(__('Price')); ?></th>
                                        <th class="text-center"><?php echo e(__('Sub Total')); ?></th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session($lastsegment) && !empty(session($lastsegment)) && count(session($lastsegment)) > 0): ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = session($lastsegment); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $id => $details): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <?php
                                                $product = \App\Models\Product::find($details['id']);
                                                if ($product->product_type === 'digital') {
                                                    // Force refresh digital product image from database
                                                    $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                                                    if ($digitalImage && !empty($digitalImage->image_path)) {
                                                        $image_url = $digitalImage->image_path;
                                                    } else {
                                                        // Always use correct path regardless of what's in cover_image_path
                                                        $digitalFiles = ['ebook.jpg', 'course.jpg', 'photos.jpg', 'software.jpg', 'templates.jpg'];
                                                        $fileIndex = ($product->id - 1) % count($digitalFiles);
                                                        $image_url = 'storage/uploads/digital_products/' . $digitalFiles[$fileIndex];
                                                    }
                                                } else {
                                                    $image_url = !empty($product->cover_image_path)
                                                        ? $product->cover_image_path
                                                        : 'default.jpg';
                                                }
                                                $total = $total + (float) $details['total_orignal_price'];
                                                $Tax = \App\Models\Tax::where('id', $product->tax_id)->get();
                                            ?>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(getCurrentStore() == $product->store_id): ?>
                                                <tr data-product-id="<?php echo e($id); ?>"
                                                    id="product-id-<?php echo e($id); ?>">
                                                    <td class="cart-images">
                                                        <img alt="Image placeholder" src="<?php echo e(get_file($image_url)); ?>"
                                                            class="card-image avatar rounded-circle-sale shadow hover-shadow-lg">
                                                    </td>
                                                    <td class="text-left name"><?php echo e($details['name']); ?></td>
                                                    <td>
                                                        <span class="quantity buttons_added">
                                                            <input type="button" value="-" class="minus bg-primary">
                                                            <input type="number" step="1" min="1"
                                                                max="" name="quantity" title="<?php echo e(__('Quantity')); ?>"
                                                                class="input-number" data-url="<?php echo e(url('update-cart/')); ?>"
                                                                data-id="<?php echo e($id); ?>" size="4"
                                                                value="<?php echo e($details['quantity']); ?>" style="width:50px;">
                                                            <input type="button" value="+" class="plus bg-primary">
                                                        </span>
                                                    </td>

                                                    <td class=" cart-summary-table">
                                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($tax_option['price_type']) &&
                                                                $tax_option['price_type'] != 'inclusive' &&
                                                                (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including')): ?>
                                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $Tax; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key1 => $value1): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                                <span class="badge badge-primary"> <?php echo e($value1->name); ?>

                                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $value1->tax_methods(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $methods): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                                        <?php echo e($methods->tax_rate); ?>%
                                                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                                </span>
                                                                <br>
                                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                        <?php else: ?>
                                                            --
                                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    </td>

                                                    <td class="price text-center">
                                                        <?php echo e(currency_format_with_sym($details['orignal_price'], getCurrentStore())); ?>

                                                    </td>

                                                    <td class="text-center">
                                                        <span
                                                            class="total_orignal_price"><?php echo e(currency_format_with_sym($details['total_orignal_price'], getCurrentStore())); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php echo Form::open([
                                                            'method' => 'DELETE',
                                                            'class' => 'mb-0',
                                                            'route' => ['remove-from-cart'],
                                                            'id' => 'delete-form-' . $id,
                                                        ]); ?>

                                                        <button type="button" class="show_confirm btn btn-sm btn-danger"
                                                            data-confirm="<?php echo e(__('Are You Sure?')); ?>"
                                                            data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>"
                                                            data-text-yes="<?php echo e(__('Yes')); ?>"
                                                            data-text-no="<?php echo e(__('No')); ?>">
                                                            <i class="ti ti-trash" data-bs-toggle="tooltip"
                                                                title="<?php echo e(__('Delete')); ?>"></i>
                                                        </button>
                                                        <input type="hidden" name="session_key"
                                                            value="<?php echo e($lastsegment); ?>">
                                                        <input type="hidden" name="id"
                                                            value="<?php echo e($id); ?>">
                                                        <?php echo Form::close(); ?>

                                                    </td>
                                                </tr>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php else: ?>
                                        <tr class="text-center no-found">
                                            <td colspan="7"><?php echo e(__('No Data Found.!')); ?></td>
                                        </tr>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="total-section mt-3">
                            <div class="row align-items-center">
                                <div class="col-xxl-6 col-12">
                                    <div class="left-inner d-flex">
                                        <span>Discount in our product</span>
                                        <?php echo e(Form::number('discount', null, ['class' => ' form-control discount', 'required' => 'required', 'placeholder' => __('Discount')])); ?>

                                        <?php echo e(Form::hidden('discount_hidden', '', ['id' => 'discount_hidden'])); ?>

                                    </div>
                                </div>
                                <div class="col-xxl-6 col-12">
                                    <div class="right-inner d-flex justify-content-between ">
                                        <div class="billing-price d-flex justify-content-between">
                                            <h6 class="mb-0 text-dark"><?php echo e(__('Sub Total')); ?> :</h6>
                                            <h6 class="mb-0 text-dark subtotal_price" id="displaytotal">
                                                <?php echo e(currency_format_with_sym($total, getCurrentStore()) ?? SetNumberFormat($total)); ?>

                                            </h6>
                                        </div>

                                        <div class="d-flex justify-content-between">
                                            <h6 class="mb-0"><?php echo e(__('Total')); ?> :</h6>
                                            <h6 class="totalamount mb-0">
                                                <?php echo e(currency_format_with_sym($total, getCurrentStore()) ?? SetNumberFormat($total)); ?>

                                            </h6>
                                        </div>
                                    </div>
                                    <div class="billing-price d-flex justify-content-between">
                                        <span class="mb-0 text-dark"><?php echo e(__('You are saving')); ?> :</span>
                                        <p class="mb-0 text-dark discount_price" id="discounttotal">
                                            <?php echo e(currency_format_with_sym(0, getCurrentStore()) ?? SetNumberFormat($total)); ?>

                                        </p>
                                    </div>

                                    <div class="pt-3 d-flex" id="btn-pur">

                                        <div class="tab-content d-flex btn-empty justify-content-between gap-2 w-100 d-flex align-items-center">
                                            <?php echo Form::open(['method' => 'post', 'route' => ['empty-cart'], 'id' => 'delete-form-emptycart']); ?>

                                            <a href="#" class="btn btn-danger show_confirm rounded m-0"
                                                data-confirm="<?php echo e(__('Are You Sure?')); ?>"
                                                data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>"
                                                data-text-yes="<?php echo e(__('Yes')); ?>"
                                                data-text-no="<?php echo e(__('No')); ?>"><?php echo e(__('Empty Cart')); ?>

                                            </a>
                                            <input type="hidden" name="session_key" value="<?php echo e($lastsegment); ?>"
                                                id="empty_cart">
                                            <?php echo Form::close(); ?>


                                            <?php if (app('laratrust')->hasPermission('Create Pos')) : ?>
                                                <button type="button" class="btn h-100 btn-primary rounded"
                                                    data-pos-ajax-popup="true" data-size="xl" data-align="centered"
                                                    data-url="<?php echo e(route('pos.create')); ?>"
                                                    data-title="<?php echo e(__('POS Invoice')); ?>"
                                                    <?php if(session($lastsegment) && !empty(session($lastsegment)) && count(session($lastsegment)) > 0): ?> <?php else: ?> disabled="disabled" <?php endif; ?>>
                                                    <?php echo e(__('PAY')); ?>

                                                </button>
                                            <?php endif; // app('laratrust')->permission ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('custom-script'); ?>
    <script>
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        function formatCurrency(price) {
            var currencySymbol = "<?php echo e($currency_icon); ?>";
            return currencySymbol + addCommas(price);
        }

        $(document).ready(function() {
            $("#vc_name_hidden").val($('.customer_select').val());
            $("#discount_hidden").val($('.discount').val());

            $(function() {
                getProductCategories();

            });

            if ($('#searchproduct').length > 0) {
                var url = $('#searchproduct').data('url');
                var store_id = $("#store_id").val();
                searchProducts(url, '', '0', store_id);
            }
            // if ($('#searchproductsku').length > 0) {
            //     var url = $('#searchproductsku').data('url');
            //     var store_id = $("#store_id").val();
            //     searchProductsSku(url, '', '0', store_id);
            // }
            $('.customer_select').change(function() {
                $("#vc_name_hidden").val($(this).val());
            });

            $(document).on('click', '#clearinput', function(e) {
                var IDs = [];
                $(this).closest('div').find("input").each(function() {
                    IDs.push('#' + this.id);
                });
                $(IDs.toString()).val('');
            });

            $(document).on('keyup', 'input#searchproduct', function() {
                var url = $(this).data('url');
                var value = this.value.trim();
                var cat = $('.cat-active').children().data('cat-id');
                var store_id = $("#store_id").val();
                //if (value.length > 3) {
                    searchProducts(url, value, cat, store_id);
                //}
            });
            $(document).on('keyup', 'input#searchproductsku', function() {
                var url = $(this).data('url');
                var value = this.value.trim();
                var cat = $('.cat-active').children().data('cat-id');
                var store_id = $("#store_id").val();
                //if (value.length > 3) {
                    searchProductsSku(url, value, cat, store_id);
                //}
            });


            function searchProducts(url, value, cat_id, store_id = '0') {
                var session_key = $('#empty_cart').val();
                $.ajax({
                    type: 'GET',
                    url: url,
                    data: {
                        'search': value,
                        'cat_id': cat_id,
                        'store_id': store_id,
                        'session_key': session_key
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        $('#product-listing').html(data.html);
                        // Check if exactly one product is found
                        let productCount = $(".toacart").length;
                        if (data.code === 200 && productCount === 1 && !data.in_cart) {
                            $(".toacart").first().trigger("click"); // Auto-add product to cart
                        }
                    }
                });
            }

            function searchProductsSku(url, value, cat_id, store_id = '0') {
                var session_key = $('#empty_cart').val();
                $.ajax({
                    type: 'GET',
                    url: url,
                    data: {
                        'search': value,
                        'cat_id': cat_id,
                        'store_id': store_id,
                        'session_key': session_key
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        $('#product-listing').html(data.html);
                        // Check if exactly one product is found
                        let productCount = $(".toacart").length;
                        if (data.code === 200 && productCount === 1 && !data.in_cart) {
                            $(".toacart").first().trigger("click"); // Auto-add product to cart
                        }
                    }
                });
            }

            function getProductCategories() {
                $.ajax({
                    type: 'GET',
                    url: '<?php echo e(route('product.categories')); ?>',
                    success: function(data) {
                        $('#loader').fadeOut();
                        $('#categories-listing').html(data.html);
                    }
                });
            }

            // Select each category button and add a click event
            $(document).on('click', '#categories-listing .btn', function() {
                // Remove 'active' class from all buttons
                $('#categories-listing .btn').removeClass('active');

                // Add 'active' class to the clicked button
                $(this).addClass('active');
            });

            $(document).on('click', '.toacart', function() {
                var sum = 0
                $.ajax({
                    url: $(this).data('url'),
                    success: function(data) {
                        $('#loader').fadeOut();

                        if (data.code == '200') {

                            $('#displaytotal').text(formatCurrency(data.product
                                .total_orignal_price));
                            $('.totalamount').text(formatCurrency(data.product
                                .total_orignal_price));

                            if ('carttotal' in data) {
                                $.each(data.carttotal, function(key, value) {
                                    $('#product-id-' + value.id +
                                        ' .total_orignal_price').text(
                                        formatCurrency(
                                            value.total_orignal_price));
                                    sum += value.total_orignal_price;
                                });
                                $('#displaytotal').text(formatCurrency(sum));

                                $('.totalamount').text(formatCurrency(sum));

                                $('.discount').val('');
                            }

                            $('#tbody').append(data.carthtml);
                            $('.no-found').addClass('d-none');
                            $('.carttable #product-id-' + data.product.id +
                                ' input[name="quantity"]').val(data.product.quantity);
                            $('#btn-pur button').removeAttr('disabled');
                           // $('.btn-empty button').addClass('btn-clear-cart');

                        }
                    },
                    error: function(data) {
                        $('#loader').fadeOut();
                        data = data.responseJSON;
                        show_toastr('<?php echo e(__('Error')); ?>', data.error, 'error');
                    }
                });
            });





            $(document).on('change keyup', '#carthtml input[name="quantity"]', function(e) {
                e.preventDefault();
                var ele = $(this);
                var sum = 0;
                var quantity = ele.closest('span').find('input[name="quantity"]').val();
                var discount = $('.discount').val();
                var session_key = $('#empty_cart').val();
                if (quantity != 0 && quantity != null) {
                    $.ajax({
                        url: ele.data('url'),
                        method: "patch",
                        data: {
                            id: ele.attr("data-id"),
                            quantity: quantity,
                            discount: discount,
                            session_key: session_key
                        },
                        success: function(data) {
                            $('#loader').fadeOut();
                            if (data.code == '200') {

                                if (quantity == 0) {
                                    ele.closest(".row").hide(250, function() {
                                        ele.closest(".row").remove();
                                    });
                                    if (ele.closest(".row").is(":last-child")) {
                                        $('#btn-pur button').attr('disabled', 'disabled');
                                        $('.btn-empty button').removeClass('btn-clear-cart');
                                    }
                                }

                                $.each(data.product, function(key, value) {
                                    sum += value.total_orignal_price;
                                    $('#product-id-' + value.id +
                                        ' .total_orignal_price').text(
                                        formatCurrency(value.total_orignal_price));
                                });

                                $('#displaytotal').text(formatCurrency(sum));
                                if (discount <= sum) {
                                    $('.totalamount').text(data.discount);
                                } else {
                                    $('.totalamount').text(formatCurrency(0));
                                }
                            }
                        },
                        error: function(data) {
                            $('#loader').fadeOut();
                            data = data.responseJSON;
                            show_toastr('<?php echo e(__('Error')); ?>', data.error, 'error');
                        }
                    });
                }
            });

            $(document).on('click', '.remove-from-cart', function(e) {
                e.preventDefault();

                var ele = $(this);
                var sum = 0;

                if (confirm('<?php echo e(__("Are you sure?")); ?>')) {
                    ele.closest(".row").hide(250, function() {
                        ele.closest(".row").parent().parent().remove();
                    });
                    if (ele.closest(".row").is(":last-child")) {
                        $('#btn-pur button').attr('disabled', 'disabled');
                        $('.btn-empty button').removeClass('btn-clear-cart');
                    }
                    $.ajax({
                        url: ele.data('url'),
                        method: "DELETE",
                        data: {
                            id: ele.attr("data-id"),

                        },
                        success: function(data) {
                            $('#loader').fadeOut();
                            if (data.code == '200') {

                                $.each(data.product, function(key, value) {
                                    sum += value.total_orignal_price;
                                    $('#product-id-' + value.id +
                                        ' .total_orignal_price').text(addCommas(
                                        value.total_orignal_price));
                                });

                                $('#displaytotal').text(addCommas(sum));

                                show_toastr('success', data.success, 'success')
                            }
                        },
                        error: function(data) {
                            $('#loader').fadeOut();
                            data = data.responseJSON;
                            show_toastr('<?php echo e(__('Error')); ?>', data.error, 'error');
                        }
                    });
                }
            });

            $(document).on('click', '.btn-clear-cart', function(e) {
                e.preventDefault();

                if (confirm('<?php echo e(__("Remove all items from cart?")); ?>')) {

                    $.ajax({
                        url: $(this).data('url'),
                        data: {
                            session_key: session_key
                        },
                        success: function(data) {
                            location.reload();
                        },
                        error: function(data) {
                            $('#loader').fadeOut();
                            data = data.responseJSON;
                            show_toastr('<?php echo e(__('Error')); ?>', data.error, 'error');
                        }
                    });
                }
            });

            $(document).on('click', '.btn-done-payment', function(e) {
                e.preventDefault();
                var ele = $(this);

                $.ajax({
                    url: ele.data('url'),

                    method: 'GET',
                    data: {
                        vc_name: $('#vc_name_hidden').val(),
                        warehouse_name: $('#warehouse_name_hidden').val(),
                        discount: $('#discount_hidden').val(),
                    },
                    beforeSend: function() {
                        ele.remove();
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        if (data.code == 200) {
                            show_toastr('success', data.success, 'success')
                        }

                    },
                    error: function(data) {
                        $('#loader').fadeOut();
                        data = data.responseJSON;
                        show_toastr('<?php echo e(__("Error")); ?>', data.error, 'error');
                    }

                });

            });

            $(document).on('click', '.category-select', function(e) {
                var cat = $(this).data('cat-id');
                var white = 'text-white';
                var dark = 'text-dark';
                $('.category-select').find('.tab-btns').removeClass('btn-primary')
                $(this).find('.tab-btns').addClass('btn-primary')
                $('.category-select').parent().removeClass('cat-active');
                $('.category-select').find('.card-title').removeClass('text-white').addClass('text-dark');
                $('.category-select').find('.card-title').parent().removeClass('text-white').addClass(
                    'text-dark');
                $(this).find('.card-title').removeClass('text-dark').addClass('text-white');
                $(this).find('.card-title').parent().removeClass('text-dark').addClass('text-white');
                $(this).parent().addClass('cat-active');
                var url = '<?php echo e(route('search.products')); ?>'
                var store_id = $('#store_id').val();
                searchProducts(url, '', cat, store_id);
            });




            $(document).on('keyup', '.discount', function() {
                var discount = $('.discount').val();
                var total = $('#displaytotal').text();
                var maintotal = parseFloat(total.replace("$", "").replace(",", ""))
                if (discount <= maintotal) {
                    $("#discount_hidden").val(discount);
                } else {
                    $("#discount_hidden").val(maintotal);
                }
                $.ajax({
                    url: "<?php echo e(route('cartdiscount')); ?>",
                    method: 'POST',
                    data: {
                        discount: discount,
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        if (discount <= maintotal) {
                            $('.totalamount').text(data.total);
                            $('.discount_price').text(data.discount);
                        } else {
                            $('.totalamount').text(addCommas(0));
                            $('.discount_price').text(addCommas(0));
                        }
                    },
                    error: function(data) {
                        $('#loader').fadeOut();
                        data = data.responseJSON;
                        show_toastr('<?php echo e(__("Error")); ?>', data.error, 'error');
                    }
                });
                var price = <?php echo e($total); ?>

                var total_amount = price - discount;
                $('.totalamount').text(total_amount);
            });
        });


        // Product Variant script

        $(document).on('change', '.variant-selection', function() {
            var variants = [];
             var session_key = "<?php echo e($lastsegment); ?>";
            $(".variant-selection").each(function(index, element) {
                if (element.value != '' && element.value != undefined) {
                    var el_val = element.value;
                    variants.push(el_val);
                }
            });
            if (variants.length > 0) {

                $.ajax({
                    url: '<?php echo e(route('get.products.variant.quantity')); ?>',
                    data: {
                        "_token": $('meta[name="csrf-token"]').attr('content'),
                        variants: variants.join('-'),
                        product_id: $('#product_id').val(),
                        session_key: session_key
                    },

                    success: function(data) {
                        $('#loader').fadeOut();
                        if (data.variant_id == 0) {
                            $('.variant_stock1').addClass('d-none');
                            $('.variation_price1').html('Please Select Variants');
                            // $('#variant_qty').val('0');
                        } else {
                            var qty = 'Price : ' + data.price;
                            var amount = 'QTY : ' + data.quantity;
                            $('.variation_price1').html(qty);
                            $('#variant_id').val(data.variant_id);
                            // $('#variant_qty').val(data.quantity);
                            $('.variant_qty').html(amount);
                            $('.variant_stock1').removeClass('d-none');
                            if (data.quantity != 0) {
                                $('.variant_stock1').html('In Stock');
                                $(".variant_stock1").css({
                                    "backgroundColor": "#C2FFA5",
                                    "color": "#58A336"
                                });
                            } else {
                                $(".variant_qty").css({
                                    "color": "rgb(253 58 110)"
                                });
                                $('.variant_qty').html('Out Of Stock');
                            }
                        }
                    }
                });
            }
        });


        $(document).on('click', '.toacartvariant', function() {

            var sum = 0;
            var id = $(this).attr('data-id');
            var session_key = "<?php echo e($lastsegment); ?>";
            var variants = [];
            $(".variant-selection").each(function(index, element) {
                variants.push(element.value);
            });

            if (jQuery.inArray('0', variants) != -1) {
                show_toastr('Error', "<?php echo e(__('Please select all option.')); ?>", 'error');
                return false;
            }

            var variation_ids = $('#variant_id').val();

            $.ajax({
                url: '<?php echo e(route('pos.add.to.cart', ['__product_id', 'session_key', 'variation_id'])); ?>'
                    .replace('__product_id', id).replace('session_key', session_key).replace('variation_id',
                        variation_ids ?? 0),
                data: {
                    "_token": "<?php echo e(csrf_token()); ?>",
                    variants: variants.join('-'),
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.code == '200') {

                        $('#displaytotal').text(formatCurrency(data.product.total_orignal_price));
                        $('.totalamount').text(formatCurrency(data.product.total_orignal_price));

                        if ('carttotal' in data) {
                            $.each(data.carttotal, function(key, value) {
                                $('#product-id-' + value.id + ' .total_orignal_price').text(
                                    formatCurrency(value.total_orignal_price));
                                sum += value.total_orignal_price;
                            });
                            $('#displaytotal').text(formatCurrency(sum));

                            $('.totalamount').text(formatCurrency(sum));

                            $('.discount').val('');
                        }

                        $('#tbody').append(data.carthtml);
                        $('.no-found').addClass('d-none');
                        $('.carttable #product-id-' + data.product.id + ' input[name="quantity"]').val(
                            data.product.quantity);
                        $('#btn-pur button').removeAttr('disabled');
                       // $('.btn-empty button').addClass('btn-clear-cart');

                    }
                },
                error: function(data) {
                    $('#loader').fadeOut();
                    data = data.responseJSON;
                    show_toastr('<?php echo e(__('Error')); ?>', data.error, 'error');
                }
            });
        });

        $(document).on('click', '.add_to_cart_variant', function() {
            $('#commonModal').modal('hide');
        });
    </script>
    <script>
        var site_currency_symbol_position = '<?php echo e(\App\Models\Utility::getValByName('site_currency_symbol_position')); ?>';
        var site_currency_symbol = '<?php echo e(\App\Models\Utility::getValByName('site_currency_symbol')); ?>';
    </script>


    <script>
        var filename = $('#filename').val()

        function saveAsPDF() {
            var element = document.getElementById('printableArea');
            var opt = {
                margin: 0.3,
                filename: filename,
                image: {
                    type: 'jpeg',
                    quality: 1
                },
                html2canvas: {
                    scale: 4,
                    dpi: 72,
                    letterRendering: true
                },
                jsPDF: {
                    unit: 'in',
                    format: 'A2'
                }
            };
            html2pdf().set(opt).from(element).save();
        }

        $(document).on('click', '.payment-done-btn', function(e) {
            $('.modal-dialog').removeClass('modal-xl');
            e.preventDefault();
            var ele = $(this);
            $.ajax({
                url: "<?php echo e(route('pos.data.store')); ?>",
                method: 'POST',
                data: {
                    vc_name: $('#vc_name_hidden').val(),
                    store_id: $('#store_id').val(),
                    discount: $('#discount_hidden').val(),
                    price: $('.totalamount').text(),
                },
                beforeSend: function() {
                    ele.remove();
                },
                success: function(data) {
                console.log(data,'data')
                    $('#loader').fadeOut();
                    if (data.code == 200) {
                        $('#carthtml').load(document.URL + ' #carthtml');
                        show_toastr('success', data.success, 'success')
                    }

                    if (data.code == 404) {
                        $('#carthtml').load(document.URL + ' #carthtml');
                    }
                },
                error: function(data) {
                    $('#loader').fadeOut();
                    data = data.responseJSON;
                    show_toastr('<?php echo e(__('Error')); ?>', data.error, 'error');
                }

            });
        });

        $(document).on('click', 'a[data-pos-ajax-popup="true"], button[data-pos-ajax-popup="true"], div[data-pos-ajax-popup="true"]', function () {

            var title = $(this).data('title');
            var size = ($(this).attr('data-size') == '') ? 'md' : $(this).attr('data-size');
            var url = $(this).data('url');
            $("#commonModal .modal-title").html(title);
            $("#commonModal .modal-dialog").removeClass('modal-lg').removeClass('modal-md').removeClass('modal-sm');
            $("#commonModal .modal-dialog").addClass('modal-' + size);

            $.ajax({
                url: url,
                success: function (data) {
                    $('#commonModal .modal-body').html('');
                    $('#commonModal .modal-body').html(data.html);
                    $("#commonModal").modal('show');
                    $('#loader').fadeOut();
                },
                error: function (data) {
                    data = data.responseJSON;
                }
            });

        });
    </script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/pos/index.blade.php ENDPATH**/ ?>