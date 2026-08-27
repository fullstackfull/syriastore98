<?php $__env->startSection('page-title'); ?>
<?php echo e(__('Product')); ?>

<?php $__env->stopSection(); ?>

<?php
$logo = asset(Storage::url('uploads/profile/'));
?>

<?php $__env->startSection('breadcrumb'); ?>
<li class="breadcrumb-item" aria-current="page"><a href="<?php echo e(route('product.index')); ?>"><?php echo e(__('Product')); ?></a></li>
<li class="breadcrumb-item" aria-current="page"><?php echo e(__('Edit')); ?></li>
<?php $__env->stopSection(); ?>
<?php $__env->startSection('action-button'); ?>
<div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
    <a href="#" class="btn  btn-primary " id="submit-all" data-title="<?php echo e(__('Update Product')); ?>" data-toggle="tooltip"
        title="<?php echo e(__('Update Product')); ?>">
        <i class="ti ti-plus drp-icon"></i> <span class="ms-2 me-2"><?php echo e(__('Update')); ?></span> </a>
</div>
<?php $__env->stopSection(); ?>
<?php
$plan = \App\Models\Plan::find(\Auth::user()->plan_id);
$stock_management = \App\Models\Utility::GetValueByName('stock_management');
$low_stock_threshold = \App\Models\Utility::GetValueByName('low_stock_threshold');
?>
<?php $__env->startSection('content'); ?>
<?php echo e(Form::model($product, ['route' => ['product.update', $product->id], 'method' => 'PUT', 'enctype' => 'multipart/form-data', 'id' => 'choice_form', 'class' => 'choice_form_edit'])); ?>


<div class="row product-page-info">
    <div class="col-md-12">
      <div class="row">
        <div class="col-lg-7 col-md-12 col-12">
          <div class="border rounded">
                <h5 class="mb-0 p-3 border-bottom"><?php echo e(__('Main Informations')); ?></h5>
                <div class="card-body">
                    <div class="product-info-top p-3 border-bottom">
                        <div class="row row-gap">
                            <div class="col-12">
                                <input type="hidden" name="id" value="<?php echo e($product->id); ?>">
                                <?php echo Form::label('', __('Name'), ['class' => 'form-label']); ?><span
                                    class="text-danger">*</span>
                                <?php echo Form::text('name', null, ['class' => 'form-control name']); ?>

                            </div>
                            <div class="col-12 parmalink ">
                                <?php echo Form::label('', __('parmalink'), ['class' => 'form-label col-md-3']); ?>

                                <div class="d-flex flex-wrap gap-3">
                                    <input class="input-group-text col-12" readonly id="basic-addon2"
                                        value="<?php echo e($link); ?>">
                                    <?php echo Form::text('slug', null, ['class' => 'form-control slug col-12', 'data-bs-toggle'=>'tooltip', 'title'=> __('Sku or Slug')]); ?>

                                </div>
                            </div>
                            <div class="col-sm-6 col-12">
                                <label class="form-label"><?php echo e(__('Category')); ?></label><span class="text-danger">*</span>
                                <select name="category_id" class="form-control" data-role="tagsinput" id="category_id">
                                    <option value=""><?php echo e(__('Select Category')); ?></option>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $categoryTree; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $cat): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <option value="<?php echo e($cat['id']); ?>" <?php echo e($cat['id'] ==  $product->category_id ? 'selected' : ''); ?>>
                                            <?php echo $cat['name']; ?>

                                        </option>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6 col-12 switch-width">
                                <?php echo e(Form::label('tax_id', __('Taxs'), ['class' => ' form-label'])); ?>

                                <select name="tax_id[]" data-role="tagsinput" id="tax_id" multiple>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $Tax; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $Key => $tax): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <option <?php if(in_array($Key, $get_tax)): ?> selected <?php endif; ?> value="<?php echo e($Key); ?>">
                                        <?php echo e($tax); ?></option>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6 col-12">
                                <?php echo Form::label('', __('Tax Status'), ['class' => 'form-label']); ?>

                                <?php echo Form::select('tax_status', $Tax_status, null, [ 'class' => 'form-control',
                                'data-role' => 'tagsinput', 'id' => 'tax_id', ]); ?>

                            </div>
                            <div class=" col-sm-6 col-12" data_val='0'>
                                <?php echo Form::label('', __('Brand'), ['class' => 'form-label']); ?>

                                <span>
                                    <?php echo Form::select('brand_id', $brands, null, [ 'class' => 'form-control',
                                    'data-role' => 'tagsinput', 'id' => 'brand-dropdown', ]); ?>

                                </span>
                            </div>
                            <div class=" col-sm-6 col-12" data_val='0'>
                                <?php echo Form::label('', __('Label'), ['class' => 'form-label']); ?>

                                <span>
                                    <?php echo Form::select('label_id', $labels, null, [ 'class' => 'form-control',
                                    'data-role' => 'tagsinput', 'id' => 'label-dropdown', ]); ?>

                                </span>
                            </div>
                            <div class="col-md-6 col-12">
                                <?php echo Form::label('', __('Tags'), ['class' => 'form-label']); ?>

                                <select name="tag_id[]" class="select2 form-control" id="tag_id" multiple required>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $tag; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <option <?php if(in_array($key, $get_tags)): ?> selected <?php endif; ?> value="<?php echo e($key); ?>">
                                        <?php echo e($t); ?></option>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-12">
                                <?php echo Form::label('', __('Shipping'), ['class' => 'form-label']); ?>

                                <?php echo Form::select('shipping_id', $Shipping, null, [
                                'class' => 'form-control',
                                'data-role' => 'tagsinput',
                                'id' => 'shipping_id',
                                ]); ?>

                            </div>
                            <div class="col-sm-6 col-12 product-weight">
                                <?php echo Form::label('', __('Weight(Kg)'), ['class' => 'form-label ']); ?>

                                <?php echo Form::number('product_weight', null, ['class' => 'form-control', 'min' => '0',
                                'step' => '0.01']); ?>

                            </div>
                            <div class="col-md-6 col-12 product_price product-weight">
                                <?php echo Form::label('', __('Price'), ['class' => 'form-label']); ?><span
                                    class="text-danger">*</span>
                                <?php echo Form::number('price', null, ['class' => 'form-control', 'min' => '0', 'step' =>
                                '0.01']); ?>

                            </div>
                            <div class="col-md-6 col-12 product-weight">
                                <?php echo Form::label('', __('Sale Price'), ['class' => 'form-label']); ?><!--<span
                                    class="text-danger">*</span>-->
                                <?php echo Form::number('sale_price', null, ['class' => 'form-control', 'min' => '0',
                                'step' => '0.01']); ?>

                            </div>
                        </div>
                    </div>
                    <!--Stock code-->
                    <div class="product-stock-div p-3 pb-0">
                        <h4><?php echo e(__('Product Stock')); ?></h4>
                        <div class="row form-group row-gap">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stock_management == 'on'): ?>
                            <div class=" col-md-6 col-12">
                                <div class="product-stock-top d-flex ">
                                <div class="form-check form-switch">
                                <input type="hidden" name="track_stock" value="0">
                                <?php echo Form::checkbox('track_stock', 1, null, [ 'class' => 'form-check-input enable_product_stock', 'id' => 'enable_product_stock', ]); ?>

                                <label class="form-check-label" for="enable_product_stock"></label>
                                </div>
                                <?php echo Form::label('', __('Stock Management'), ['class' => 'form-label']); ?>

                            </div>
                            </div>
                            <?php else: ?>
                            <div class="col-md-6 col-12 product_stock">
                                <?php echo Form::label('', __('Stock Management'), ['class' => 'form-label']); ?><br>
                                <label name="trending" value="">
                                    <small><?php echo e(__('Disabled in')); ?> 
                                        <a href="<?php echo e(route('setting.index') . '#Brand_Setting '); ?>"> <?php echo e(__('store')); ?> <?php echo e(__('setting')); ?></a>
                                    </small>
                                </label>
                            </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <div class="row row-gap">
                            <div class="col-12 stock_stats">
                                <?php echo Form::label('', __('Stock Status:'), ['class' => 'form-label f-w-800']); ?>

                                <div class="col-mb-9 d-flex flex-wrap row-gap">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input code" type="radio" id="in_stock" value="in_stock" name="stock_status" <?php echo e($product->stock_status == 'in_stock' ? 'checked' : ''); ?>>
                                    <label class="form-check-label" for="in_stock">
                                    <?php echo e(__('In Stock')); ?>

                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input code" type="radio" id="out_of_stock" value="out_of_stock" name="stock_status" <?php echo e($product->stock_status == 'out_of_stock' ? 'checked' : ''); ?>>
                                    <label class="form-check-label" for="out_of_stock">
                                    <?php echo e(__('Out of stock')); ?>

                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input code" type="radio" id="on_backorder" value="on_backorder" name="stock_status" <?php echo e($product->stock_status == 'on_backorder' ? 'checked' : ''); ?>>
                                    <label class="form-check-label" for="on_backorder">
                                    <?php echo e(__('On Backorder')); ?>

                                    </label>
                                </div>
                                </div>
                            </div>
                            </div>
                        </div>
                        <div class="product-stock-top-inner px-3">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stock_management == 'on'): ?>
                                <div class="row row-gap" id="options">
                                    <div class="col-md-6 col-12 product_stock">
                                        <?php echo Form::label('', __('Stock'), ['class' => 'form-label']); ?>

                                        <?php echo Form::number('product_stock', null, ['class' => 'form-control
                                            productStock']); ?>

                                    </div>
                                    <div class="col-md-6 col-12">
                                        <?php echo Form::label('', __('Low stock threshold'), ['class' => 'form-label']); ?>

                                        <?php echo Form::number('low_stock_threshold', $low_stock_threshold, ['class' =>
                                        'form-control', 'min' => '0']); ?>

                                    </div>
                                    <div class="col-12 mb-3">
                                        <?php echo Form::label('', __('Allow BackOrders:'), ['class' => 'form-label']); ?>

                                        <div class="form-check m-1">
                                            <input type="radio" id="not_allow" value="not_allow" name="stock_order_status" class="form-check-input code" <?php echo e($product->stock_order_status == 'not_allow' ? 'checked' : ''); ?>>
                                            <label class="form-check-label" for="not_allow"><?php echo e(__('Do Not Allow')); ?></label>
                                        </div>
                                        <div class="form-check m-1">
                                            <input type="radio" id="notify_customer" value="notify_customer" name="stock_order_status" class="form-check-input code" <?php echo e($product->stock_order_status == 'notify_customer' ? 'checked' : ''); ?>>
                                            <label class="form-check-label"
                                            for="notify_customer"><?php echo e(__('Allow, But notify customer')); ?></label>
                                        </div>
                                        <div class="form-check m-1">
                                            <input type="radio" id="allow" value="allow" name="stock_order_status" class="form-check-input code" <?php echo e($product->stock_order_status == 'allow' ? 'checked' : ''); ?>>
                                            <label class="form-check-label" for="allow"><?php echo e(__('Allow')); ?></label>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php echo $__env->yieldPushContent('editCartQuantityControlFilds'); ?>
                        </div>
                        <div class="product-stock-bottom p-3 pb-0 border-top">
                            <div class="col-12">
                                <h5 class="mb-3"><?php echo e(__('Main Informations')); ?></h5>
                                <div class="card">
                                    <div class="card-body ms-2">
                                        <div class="row row-gap align-items-center">
                                            <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                <div class="stock-main-opts align-items-center d-flex">
                                                    <div class="form-check form-switch">
                                                        <input type="hidden" name="variant_product" value="0">
                                                        <?php echo Form::checkbox('variant_product', 1, null, [ 'class' =>
                                                                                    'form-check-input enable_product_variant', 'id' =>
                                                                                    'enable_product_variant', ]); ?>

                                                        <label class="form-check-label" for="enable_product_variant"></label>
                                                    </div>
                                                    <?php echo Form::label('enable_product_variant', __('Display Variants'), ['class' => 'form-label']); ?>

                                                </div>
                                            </div>
                                            <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                <div class="stock-main-opts align-items-center d-flex">
                                                    <div class="form-check form-switch">
                                                        <input type="hidden" name="trending" value="0">
                                                        <?php echo Form::checkbox('trending', 1, null, ['class' =>
                                                            'form-check-input', 'id' => 'trending_product']); ?>

                                                        <label class="form-check-label" for="trending_product"></label>
                                                    </div>
                                                    <?php echo Form::label('trending_product', __('Trending'), ['class' => 'form-label']); ?>

                                                </div>
                                            </div>
                                            <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                <div class="stock-main-opts align-items-center d-flex">
                                                    <div class="form-check form-switch">
                                                        <input type="hidden" name="status" value="0">
                                                        <?php echo Form::checkbox('status', 1, null, ['class' =>
                                                            'form-check-input', 'id' => 'status']); ?>

                                                        <label class="form-check-label" for="status"></label>
                                                    </div>
                                                    <?php echo Form::label('status', __('Display Product'), ['class' => 'form-label']); ?>

                                                </div>
                                            </div>
                                            <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                <div class="stock-main-opts align-items-center d-flex">
                                                    <div class="form-check form-switch">
                                                        <input type="hidden" name="custom_field_status" value="0">
                                                        <?php echo Form::checkbox('custom_field_status', 1, null, [ 'class'
                                                            => 'form-check-input', 'id' => 'enable_custom_field', ]); ?>

                                                        <label class="form-check-label" for="enable_custom_field"></label>
                                                    </div>
                                                    <?php echo Form::label('enable_custom_field', __('Custom  Field'), ['class' => 'form-label']); ?>

                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
          
                <!--Image code-->
                <div class="product-image-sec border rounded mb-4 mt-4">
                    <h5 class="mb-3 p-3 border-bottom"><?php echo e(__('Product Image')); ?></h5>
                    <div class="card p-3 pt-0">
                        <div class="card-body">
                            <div class="row row-gap">
                                <div class="col-12">
                                    <?php echo e(Form::label('sub_images', __('Upload Product Images'), ['class' => 'form-label f-w-800'])); ?><span
                                        class="text-danger">*</span>
                                    <div class="dropzone dropzone-multiple" data-toggle="dropzone1"
                                        data-dropzone-url="http://" data-dropzone-multiple>
                                        <!-- Dropzone message with icon -->
                                        <div class="dz-message d-flex flex-column mb-2">
                                            <img src="<?php echo e(asset('assets/images/notification/upload_icon.png')); ?>"
                                                alt="Upload Icon"
                                                style="width: 50px; height: 50px; margin:0 auto 10px;">
                                            <span>Drop files here to upload</span>
                                        </div>

                                        <div class="fallback">
                                            <div class="custom-file">
                                                <input type="file" name="file" id="dropzone-1"
                                                    class="custom-file-input"
                                                    onchange="document.getElementById('dropzone').src = window.URL.createObjectURL(this.files[0])"
                                                    multiple>
                                                <img id="dropzone" src="" width="20%" class="mt-2" />
                                                <label class="custom-file-label"
                                                    for="customFileUpload"><?php echo e(__('Choose file')); ?></label>
                                            </div>
                                        </div>

                                        <ul
                                            class="dz-preview dz-preview-multiple list-group list-group-lg list-group-flush">
                                            <li class="list-group-item px-0">
                                                <div class="row align-items-center">
                                                    <div class="col-auto">
                                                        <div class="avatar">
                                                            <img class="rounded" src="" alt="Image placeholder"
                                                                data-dz-thumbnail>
                                                        </div>
                                                    </div>
                                                    <div class="col">
                                                        <h6 class="text-sm mb-1" data-dz-name>...</h6>
                                                        <p class="small text-muted mb-0" data-dz-size></p>
                                                    </div>
                                                    <div class="col-auto">
                                                        <a href="#" class="dropdown-item btn-badge" data-dz-remove>
                                                            <i class="fas fa-trash-alt"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="form-group pt-3">
                                        <div class="row gy-3 gx-3">
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $product_image; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $file): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <div class="col-sm-4 product_Image <?php echo e('delete_img_' . $file->id); ?>"
                                                data-id="<?php echo e($file->id); ?>">
                                                <div
                                                    class="position-relative p-2 border rounded border-primary overflow-hidden rounded">
                                                    <img src="<?php echo e(get_file($file->image_path)); ?>" alt=""
                                                        class="w-100">
                                                    <div
                                                        class="position-absolute text-center top-50 end-0 start-0 pb-3">
                                                        <a href="<?php echo e(get_file($file->image_path)); ?>"
                                                            download="" data-original-title="<?php echo e(__('Download')); ?>"
                                                            class="btn btn-sm btn-primary me-2"><i
                                                                class="ti ti-download"></i></a>
                                                        <a href="javascript::void(0)"
                                                            class="btn btn-sm btn-danger deleteRecord"
                                                            name="deleteRecord" data-id="<?php echo e($file->id); ?>"><i
                                                                class="ti ti-trash"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label for="cover_image"
                                        class="form-label"><?php echo e(__('Upload Cover Image')); ?></label><span
                                        class="text-danger">*</span>
                                    <input type="file" name="cover_image" id="cover_image"
                                        class="form-control custom-input-file"
                                        onchange="document.getElementById('upcoverImg').src = window.URL.createObjectURL(this.files[0]);"
                                        multiple>
                                    <img id="upcoverImg"
                                        src="<?php echo e(get_file($product->cover_image_path)); ?>" width="20%"
                                        class="mt-2" />
                                </div>

                                <div class=" col-12" id="downloadable-product-div">
                                    <div class="choose-file">
                                        <label for="downloadable_product"
                                            class="form-label"><?php echo e(__('Downloadable Product')); ?></label>
                                        <input type="file" class="form-control" name="downloadable_product"
                                            id="downloadable_product"
                                            onchange="document.getElementById('downloadable_product').src = window.URL.createObjectURL(this.files[0]);"
                                            multiple>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->downloadable_product != ''): ?>
                                        <img src="<?php echo e(get_file($product->downloadable_product)); ?> "
                                            width="20%">
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <div class="invalid-feedback"><?php echo e(__('invalid form file')); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12" id="preview_type">
                                    <?php echo e(Form::label('preview_type', __('Preview Type'), ['class' => 'form-label'])); ?>

                                    <?php echo e(Form::select('preview_type', $preview_type, null, ['class' => 'form-control font-style', 'id' => 'preview_type'])); ?>

                                </div>
                                <div class=" col-md-6 col-12" id="preview-video-div">
                                    <div class="choose-file">
                                        <label for="preview_video"
                                            class="form-label"><?php echo e(__('Preview Video')); ?></label>
                                        <input type="file" class="form-control" name="preview_video"
                                            id="preview_video"
                                            value="<?php echo e($product->preview_type == 'Video File' ? $product->preview_content : ''); ?>"
                                            onchange="document.getElementById('preview_video').src = window.URL.createObjectURL(this.files[0]);"
                                            multiple>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->preview_content != ''): ?>
                                        <a href="<?php echo e(get_file($product->preview_content)); ?>"
                                            target="_blank">
                                            <video height="100px" controls="" class="mt-2">
                                                <source id="preview_video"
                                                    src="<?php echo e(get_file($product->preview_content)); ?>"
                                                    type="video/mp4">
                                            </video>
                                        </a>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <div class="invalid-feedback"><?php echo e(__('invalid form file')); ?></div>
                                    </div>
                                </div>

                                <div class=" col-md-6 col-12 ml-auto d-none" id="preview-iframe-div">
                                    <?php echo e(Form::label('preview_iframe', __('Preview iFrame'), ['class' => 'form-label'])); ?>

                                    <textarea name="preview_iframe" id="preview_iframe"
                                        class="form-control font-style" rows="2"
                                        value=""><?php echo e($product->preview_type == 'iFrame' ? $product->preview_content : ''); ?></textarea>
                                </div>

                                <div class=" col-md-6 col-12" id="video_url_div">
                                    <?php echo e(Form::label('video_url', __('Video URL'), ['class' => 'form-label'])); ?>

                                    <input class="form-control font-style" name="video_url" type="text"
                                        id="video_url"
                                        value="<?php echo e($product->preview_type == 'Video Url' ? $product->preview_content : ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card border">
                        <div class="card-body p-3 pb-0 ">
                            <div class="row row-gap">
                                <div class="col-md-12">
                                    <?php echo Form::label('', __('Product Attribute'), ['class' => 'form-label']); ?>

                                    <?php echo Form::select('attribute_id[]', $ProductAttribute, $get_datas, [ 'class' =>
                                    'form-control product_attribute attribute_id', 'multiple' => 'multiple', 'data-role'
                                    => 'tagsinput', 'id' => 'attribute_id', ]); ?>

                                    <small><?php echo e(__('Choose the attributes of this product and then input values of each attribute')); ?></small>
                                </div>
                                <div class="attribute_options" id="attribute_options">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->product_attribute == 'NULL'): ?>
                                    <?php else: ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($product->product_attribute)): ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = json_decode($product->product_attribute); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $choice_option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <?php

                                    $value = implode(',', $choice_option->values);
                                    $idsArray = explode('|', $value);
                                    $get_datas = \App\Models\ProductAttributeOption::whereIn(
                                    'id',
                                    $idsArray,
                                    )
                                    ->get()
                                    ->pluck('terms')
                                    ->toArray();
                                    $get_data = implode(',', $get_datas);
                                    $option = \App\Models\ProductAttributeOption::where(
                                    'attribute_id',
                                    $choice_option->attribute_id,
                                    )
                                    ->get()
                                    ->pluck('terms')
                                    ->toArray();

                                    $attribute_id = $choice_option->attribute_id;

                                    $visible_attribute = isset(
                                    $choice_option->{'visible_attribute_' . $attribute_id},
                                    )
                                    ? $choice_option->{'visible_attribute_' . $attribute_id}
                                    : 0;
                                    $for_variation = isset(
                                    $choice_option->{'for_variation_' . $attribute_id},
                                    )
                                    ? $choice_option->{'for_variation_' . $attribute_id}
                                    : 0;
                                    ?>

                                    <div class="card">
                                        <div class="card-body">
                                            <input type="hidden" name="attribute_no[]"
                                                value="<?php echo e($choice_option->attribute_id); ?>">
                                            <div class="form-group row col-12">
                                                <div class="form-group col-md-6">
                                                    <label
                                                        for="attribute_id"><?php echo e(\App\Models\ProductAttribute::find($choice_option->attribute_id)->name); ?>:</label>
                                                </div>
                                                <div
                                                    class="form-group col-md-6 text-end d-flex all-button-box justify-content-md-end justify-content-center">
                                                    <a href="#" class="btn btn-sm btn-primary add_attribute btn-badge"
                                                        data-ajax-popup="true"
                                                        data-title="<?php echo e(__('Add Attribute Option')); ?>" data-size="md"
                                                        data-url="<?php echo e(route('product-attribute-option.create', $choice_option->attribute_id)); ?>"
                                                        data-toggle="tooltip">
                                                        <i class="ti ti-plus"><?php echo e(__('Add Attribute Option')); ?></i></a>
                                                </div>
                                            </div>
                                            <div class="form-group row col-12 parent-clase">
                                                <div class="form-group col-md-5">
                                                    <div class="form-chec1k form-switch">
                                                        <?php echo Form::hidden('visible_attribute_' .
                                                        $choice_option->attribute_id, 0); ?>

                                                        <?php echo Form::checkbox('visible_attribute_' .
                                                        $choice_option->attribute_id, 1, $visible_attribute == 1, [
                                                        'class' => 'form-check-input',
                                                        'id' => 'visible_attribute_' . $choice_option->attribute_id,
                                                        ]); ?>

                                                        <?php echo Form::label('visible_attribute_' . $choice_option->attribute_id, __('Visible on the product page'), [
                                                        'class' => 'form-check-label',
                                                        ]); ?>

                                                    </div>
                                                    <div style="margin-top: 9px;"></div>
                                                    <div class="use_for_variation form-chec1k form-switch">

                                                        <?php echo Form::hidden('for_variation_' .
                                                        $choice_option->attribute_id, 0); ?>

                                                        <?php echo Form::checkbox('for_variation_' .
                                                        $choice_option->attribute_id, 1, $for_variation == 1, [
                                                        'class' => 'form-check-input input-options enable_variation_' .
                                                        $choice_option->attribute_id,
                                                        'id' => 'for_variation_' . $choice_option->attribute_id,
                                                        'data-enable-variation' => 'enable_variation_' .
                                                        $choice_option->attribute_id,
                                                        'data-id' => $choice_option->attribute_id,
                                                        ]); ?>

                                                        <?php echo Form::label('for_variation_' . $choice_option->attribute_id, __('Used for variations'), [
                                                        'class' => 'form-check-label',
                                                        ]); ?>

                                                    </div>
                                                </div>
                                                <div class="form-group col-md-7">
                                                    <select
                                                        name="attribute_options_<?php echo e($choice_option->attribute_id); ?>[]"
                                                        data-role="tagsinput"
                                                        id="attribute_options_<?php echo e($choice_option->attribute_id); ?>"
                                                        multiple class="attribute_option_data">
                                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $option; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $f): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                        <option <?php if(in_array($f, $get_datas)): ?> selected <?php endif; ?>>
                                                            <?php echo e($f); ?>

                                                        </option>
                                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div class="attribute_combination" id="attribute_combination">
                                </div>
                            </div>
                        </div>
                    </div>    
        </div>
        
        <div class="col-lg-5 col-12">
            <div class="product-info-right border mb-4 rounded">
                <h5 class="mb-0 p-3 border-bottom"><?php echo e(__('About product')); ?></h5>
                <div class="card p-3 pb-0">
                    <div class="card-body">
                        <div class="form-group">
                            <?php echo e(Form::label('description', __('Product Description'), ['class' => 'form-label'])); ?>

                            <?php echo e(Form::textarea('description', null, ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Product Description'), 'id' => 'description'])); ?>

                        </div>
                        <div class="form-group">
                            <?php echo e(Form::label('specification', __('Product Specification'), ['class' => 'form-label'])); ?>

                            <?php echo e(Form::textarea('specification', null, ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Product Specification'), 'id' => 'specification'])); ?>

                        </div>
                        <div class="form-group">
                            <?php echo e(Form::label('detail', __('Product Details'), ['class' => 'form-label'])); ?>

                            <?php echo e(Form::textarea('detail', null, ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Product Details'), 'id' => 'detail'])); ?>

                        </div>
                    </div>
                </div>
            </div>
            <div class="product-price-info border rounded mb-4" style="display: none" id="custom_value">
                <div class="price-info-title p-3 border-bottom d-flex align-items-center  justify-content-between">
                    <h5 class="mb-0"><?php echo e(__('Custom Field')); ?></h5>
                    <a href="javascript:;" data-repeater-create
                        class="custom_field_repeater btn-badge btn-sm btn btn-light-primary">
                        <i class="ti ti-plus"></i>
                    </a>
                </div>
                <div class="card-body p-3">
                    <div id="custom_field_repeater_basic">
                        <div data-repeater-list="custom_field_repeater_basic">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($product->custom_field)): ?>
                            <?php
                                $customFieldData = json_decode($product->custom_field, true);
                                $customFieldData = is_array($customFieldData) ? $customFieldData : [];
                            ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $customFieldData; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div data-repeater-item class="mt-1">
                                <div class="row">
                                    <div class="col-md-6">
                                        <?php echo Form::label('', __('Custom Field'), ['class' => 'form-label']); ?>

                                        <?php echo Form::text('custom_field', $item['custom_field'], ['class' =>
                                        'form-control', 'placeholder' => __('Enter Custom Field')]); ?>

                                    </div>
                                    <div class="col-md-5">
                                        <?php echo Form::label('', __('Custom Value'), ['class' => 'form-label']); ?>

                                        <?php echo Form::text('custom_value', $item['custom_value'], [ 'id' =>
                                        'answer', 'rows' => 4, 'class' => 'form-control', 'placeholder' =>
                                        __('Enter Custom Value') ]); ?>

                                    </div>
                                    <div class="col-md-1">
                                        <label></label>
                                        <a href="javascript:;" data-repeater-delete
                                            class="btn field-trash-btn btn-sm btn-light-danger mt-3 mt-md-8 btn-badge"
                                            data-bs-toggle="tooltip" title="<?php echo e(__('Delete')); ?>">
                                            <i class="ti ti-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php else: ?>
                            <div data-repeater-item class="mt-1">
                                <div class="row">
                                    <div class="col-md-6">
                                        <?php echo Form::label('', __('Field'), ['class' => 'form-label']); ?>

                                        <?php echo Form::text('custom_field', null, ['class' => 'form-control',
                                        'placeholder' => __('Enter Custom Field')]); ?>

                                    </div>
                                    <div class="col-md-5">
                                        <?php echo Form::label('', __('Value'), ['class' => 'form-label']); ?>

                                        <?php echo Form::text('custom_value', null, ['id' => 'answer', 'rows' => 2,
                                        'class' => 'form-control','placeholder' => __('Enter Custom Value')]); ?>

                                    </div>
                                    <div class="col-md-1">
                                        <label></label>
                                        <a href="javascript:;" data-repeater-delete
                                            class="btn field-trash-btn btn-sm btn-light-danger mt-3 mt-md-8 btn-badge"
                                            data-bs-toggle="tooltip" title="<?php echo e(__('Delete')); ?>">
                                            <i class="ti ti-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <?php echo $__env->yieldPushContent('CustomFieldsEditView'); ?>

                    </div>
                </div>
            </div>
            
            <?php echo $__env->yieldPushContent('editsizeguidefields'); ?>
            <?php echo $__env->yieldPushContent('editwholesalefields'); ?>
            <?php echo $__env->yieldPushContent('EditProductPageSetting'); ?>
        </div>
      </div>
    </div>
  </div>
<?php echo Form::close(); ?>

<?php $__env->stopSection(); ?>

    <?php $__env->startPush('custom-script'); ?>
    <script src="<?php echo e(asset('js/jquery-ui.min.js')); ?>"></script>
    <script src="<?php echo e(asset('js/repeater.js')); ?>"></script>
    <script src="<?php echo e(asset('assets/css/summernote/summernote-bs4.js')); ?>"></script>
    <script>
        $(document).ready(function() {
            attribute_option_data();
            type();

            if ($('#enable_custom_field').prop('checked') == true) {
                $('#custom_value').show();
            }

            if ($('#enable_product_variant').prop('checked') == true) {
                $('.product-price-div').hide();
                $('.product-stock-div').hide();
                $('.product-weight').hide();
            }

            // tag
            $('.select2').select2({
                tags: true,
                createTag: function(params) {
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

            

            // prview video
            $("#preview_type").change(function() {
                $(this).find("option:selected").each(function() {
                    var optionValue = $(this).attr("value");
                    if (optionValue == 'Video Url') {

                        $('#video_url_div').removeClass('d-none');
                        $('#video_url_div').addClass('d-block');

                        $('#preview-iframe-div').addClass('d-none');
                        $('#preview-iframe-div').removeClass('d-block');

                        $('#preview-video-div').addClass('d-none');
                        $('#preview-video-div').removeClass('d-block');

                    } else if (optionValue == 'iFrame') {
                        $('#video_url_div').addClass('d-none');
                        $('#video_url_div').removeClass('d-block');

                        $('#preview-iframe-div').removeClass('d-none');
                        $('#preview-iframe-div').addClass('d-block');

                        $('#preview-video-div').addClass('d-none');
                        $('#preview-video-div').removeClass('d-block');

                    } else if (optionValue == 'Video File') {

                        $('#video_url_div').addClass('d-none');
                        $('#video_url_div').removeClass('d-block');

                        $('#preview-iframe-div').addClass('d-none');
                        $('#preview-iframe-div').removeClass('d-block');

                        $('#preview-video-div').removeClass('d-none');
                        $('#preview-video-div').addClass('d-block');
                    }
                });
            }).change();
        });

        function type() {
            if ($('#enable_product_stock').is(":checked") == true) {
                $("#options").show();
                $('.stock_div_status').hide();
            } else {
                $("#options").hide();
                $('.stock_div_status').show();
            }
        }

        //stock
        $(document).on("change", "#enable_product_stock", function() {
            if ($("#enable_product_stock").prop("checked")) {
                $("#options").show();
                $('.stock_div_status').hide();
            } else {
                $("#options").hide();
                $('.stock_div_status').show();
            }
        });
        
        $(document).on("change", ".product_attribute", function() {
            if ($('.enable_product_variant').prop('checked') == true) {
                $(".use_for_variation").removeClass("d-none");
            } else {
                $(".use_for_variation").addClass("d-none");
            }
        });

        $(document).on("change", "#enable_custom_field", function() {
            $('#custom_value').hide();
            if ($(this).prop('checked') == true) {
                $('#custom_value').show();
            }
        });

        $('#custom_field_repeater_basic').repeater({
                initEmpty: false,
                defaultValues: {
                    'text-input': 'foo'
                },
                show: function () {
                    $(this).slideDown();
                },
                hide: function (deleteElement) {
                    // Check if there is more than one field before allowing deletion
                    if ($('#custom_field_repeater_basic [data-repeater-item]').length > 1) {
                        $(this).slideUp(deleteElement);
                    } else {
                        // Show an error message if attempting to delete the last field
                        show_toastr('Error', 'At least one field is required.', 'error');
                    }
                }
        });

        // Manually add a new item when the "Add More" button is clicked
        $('.custom_field_repeater').on('click', function (e) {
            e.preventDefault();

            // Clone the last repeater item, clear its values, and append it to the repeater list
            var $repeaterList = $('#custom_field_repeater_basic [data-repeater-list]');
            var $items = $repeaterList.children();
            var index = $items.length;

            // Clone the last item
            var $newItem = $items.last().clone();

            // Clear input values and update name attributes with new index
            $newItem.find('input, select, textarea').each(function () {
                var $input = $(this);

                // Clear the value
                $input.val('');

                // Update the name attribute
                var name = $input.attr('name');
                if (name) {
                    // Replace the index inside square brackets
                    var updatedName = name.replace(/\[\d+\]/, '[' + index + ']');
                    $input.attr('name', updatedName);
                }
            });

            // Append the new item to the repeater list
            $repeaterList.append($newItem);
        });

        $(document).on('click', '.deleteRecord', function() {
            var id = $(this).data("id");
            $.ajax({

                url: '<?php echo e(route('products.file.detele', '__product_id')); ?>'.replace('__product_id', id),
                type: 'DELETE',
                data: {
                    id: id,
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    $('.delete_img_' + id).hide();
                    if (data.success) {
                        show_toastr('success', data.success, 'success');
                    }
                }

            });
        });

        // display variant hide show
        $(document).on("change", "#enable_product_variant", function() {
            $('.product-weight').show();
            $('.product-stock-div').show();
            $('#Product_Variant_Select').hide();
            $('.Product_Variant_atttt').hide();
            $('.attribute_combination').hide();
            $('.product-price-div').show();
            if ($(this).prop('checked') == true) {
                $('.product-price-div').hide();
                $('.product-stock-div').hide();
                $('.product-weight').hide();
                $('#Product_Variant_Select').show();
                $('.Product_Variant_atttt').show();
                $(".use_for_variation").removeClass("d-none");
                var inputValue = $('.attribute_option_data').val();
                if (inputValue != []) {
                    $('.attribute_combination').show();
                }

                attribute_option_data();
            }
        });

        //variation option on off
        if ($('.enable_product_variant').prop('checked') == true) {
            var inputValue = $('.attribute_option_data').val();
            if (inputValue != []) {
                var b = $('.attribute_option_data').closest('.parent-clase').find('.input-options');
                var enableVariationValue = b.data('enable-variation');
                var dataid = b.attr('data-id');
                $('.enable_variation_' + dataid).on('change', function() {
                    if ($('.enable_variation_' + dataid).prop('checked') == true) {
                        update_attribute();
                    } else {
                        $('.attribute_options_datas').empty();
                    }
                });

            }
        }

        //variation option on off
        $(document).on("change", "#enable_product_variant", function() {
            if ($('.enable_product_variant').prop('checked') == true) {

                var inputValue = $('.attribute_option_data').val();
                if (inputValue != []) {
                    var b = $('.attribute_option_data').closest('.parent-clase').find('.input-options');
                    var enableVariationValue = b.data('enable-variation');
                    var dataid = b.attr('data-id');
                    $('.enable_variation_' + dataid).on('change', function() {
                        if ($('.enable_variation_' + dataid).prop('checked') == true) {
                            $('.attribute_combination').show();
                            update_attribute();
                        } else {
                            $('.attribute_options_datas').empty();
                        }
                    });
                    if ($('.enable_variation_' + dataid).prop('checked') != true) {
                        $('.attribute_options_datas').empty();
                    }

                }
            }
        });

        // edit attribute data
        function attribute_option_data() {
            $.ajax({
                url: '<?php echo e(route('products.attribute_combination_data')); ?>',
                type: "POST",
                data: $('#choice_form').serialize(),
                success: function(data) {
                    $('#loader').fadeOut();
                    $('.attribute_combination').html(data);
                    if (data.length > 1) {
                        $('#quantity').hide();
                    } else {
                        $('#quantity').show();
                    }
                }
            });
        }

        $(document).on('change', '#attribute_id', function() {
            $('#attribute_options').html("<h3 class='d-none'>Variation</h3>");
            var option = $('.attribute_option').val();

            $.each($("#attribute_id option:selected"), function() {
                add_more_choice_option($(this).val(), $(this).text());
                var attribute_id = $(this).val();
                $.ajax({
                    url: '<?php echo e(route('products.attribute_option')); ?>',
                    type: 'POST',
                    data: {
                        "attribute_id": attribute_id,
                        "_token": "<?php echo e(csrf_token()); ?>",
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        $.each(data, function(key, value) {
                            $('.attribute_options_datas').empty();
                            $(".attribute").append('<option value="' + value + '">' +
                                value + '</option>');

                        });

                        var multipleCancelButton = new Choices('#attribute' + attribute_id, {
                            removeItemButton: true,
                        });
                    }
                });
            });
        });

        // variations form
        function update_attribute() {
            $.ajax({
                type: "PUT",
                url: '<?php echo e(route('products.attribute_combination_edit')); ?>',
                data: $('#choice_form').serialize(),
                success: function(data) {
                    $('#loader').fadeOut();
                    $('.attribute_combination').html(data);
                    if (data.length > 1) {
                        $('#quantity').hide();
                    } else {
                        $('#quantity').show();
                    }
                }
            });
        }

        $(document).on('change', '.attribute_option_data', function() {
            var b = $(this).closest('.parent-clase').find('.input-options');
            var enableVariationValue = b.data('enable-variation');
            var dataid = b.attr('data-id');
            if ($('.enable_variation_' + dataid).prop('checked') == true) {
                update_attribute();
            }

        });
    
        var Dropzones = function() {
            var e = $('[data-toggle="dropzone1"]'),
                t = $(".dz-preview");

            var CSRF_TOKEN = $('meta[name="csrf-token"]').attr('content');
            e.length && (Dropzone.autoDiscover = !1, e.each(function() {
                var e, a, n, o, i;
                e = $(this), a = void 0 !== e.data("dropzone-multiple"), n = e.find(t), o = void 0, i = {
                    url: "<?php echo e(route('product.store')); ?>",
                    headers: {
                        'x-csrf-token': CSRF_TOKEN,
                    },
                    thumbnailWidth: null,
                    thumbnailHeight: null,
                    previewsContainer: n.get(0),
                    previewTemplate: n.html(),
                    maxFiles: 10,
                    parallelUploads: 10,
                    autoProcessQueue: false,
                    uploadMultiple: true,
                    acceptedFiles: a ? null : "image/*",
                    success: function(file, response) {
                        if (response.flag == "success") {
                            show_toastr('success', response.msg, 'success');
                            window.location.href = "<?php echo e(route('product.create')); ?>";
                        } else {
                            show_toastr('Error', response.msg, 'error');
                        }
                    },
                    error: function(file, response) {
                        // Dropzones.removeFile(file);
                        if (response.error) {
                            show_toastr('Error', response.error, 'error');
                        } else {
                            show_toastr('Error', response, 'error');
                        }
                    },
                    init: function() {
                        var myDropzone = this;

                        this.on("addedfile", function(e) {
                            !a && o && this.removeFile(o), o = e
                        })
                    }
                }, n.html(""), e.dropzone(i)
            }))
        }()

        $('#submit-all').on('click', function() {

            $('#submit-all').attr('disabled', true);
            var fd = new FormData();

            var file = document.getElementById('cover_image').files[0];
            var preview_video = document.getElementById('preview_video').files[0];

            var downloadable_product = document.getElementById('downloadable_product').files[0];
            var inputs = $(".downloadable_product_variant");
            var downloadable_product_variant = [];
            for (var i = 0; i < inputs.length; i++) {
                var files = $(inputs[i]).prop('files');
                var dataValue = $(inputs[i]).data('value');
                downloadable_product_variant.push({
                    key: dataValue,
                    file: files
                });
                if (files && files.length > 0) {
                    for (var j = 0; j < files.length; j++) {
                        fd.append(dataValue, files[j]);
                    }
                }
            }
            // Append Summernote content to FormData

            if (file) {
                fd.append('cover_image', file);
            }
            if (preview_video) {
                fd.append('preview_video', preview_video);
            }
            if (downloadable_product) {
                fd.append('downloadable_product', downloadable_product);
            }



            var files = $('[data-toggle="dropzone1"]').get(0).dropzone.getAcceptedFiles();
            $.each(files, function(key, file) {
                fd.append('product_image[' + key + ']', $('[data-toggle="dropzone1"]')[0].dropzone
                    .getAcceptedFiles()[key]); // attach dropzone image element
            });

            var other_data = $('#choice_form').serializeArray();

            $.each(other_data, function(key, input) {
                fd.append(input.name, input.value);
            });

            var checkCartQuantityModule = "<?php echo e(module_is_active('CartQuantityControl') ? 'yes' : 'no'); ?>";

            if (checkCartQuantityModule == 'yes') {

                var cartQuantityValidationCheck = $(".cartQuantityValidationCheck").val();

                if (cartQuantityValidationCheck == 'false') {
                    show_toastr('Error', 'Please correct the error message before submitting the form.');
                    return false;
                } else {
                    $.ajax({
                        url: "<?php echo e(route('product.update', $product->id)); ?>",
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        data: fd,
                        contentType: false,
                        processData: false,
                        type: 'POST',
                        success: function(data) {
                            $('#loader').fadeOut();
                            if (data.flag == "success") {
                                $('#submit-all').attr('disabled', true); 
                                localStorage.setItem('success_msg', data.msg);
                                window.location.href = "<?php echo e(route('product.index')); ?>" + '?id=2';
                            } else {
                                show_toastr('Error', data.msg, 'error');
                                $('#submit-all').attr('disabled', false);
                            }
                        },
                        error: function(data) {
                            $('#loader').fadeOut();

                            $('#submit-all').attr('disabled', false);
                            // Dropzones.removeFile(file);
                            if (data.error) {
                                show_toastr('Error', data.error, 'error');
                            } else {
                                show_toastr('Error', data, 'error');
                            }
                        },
                    });
                }
            } else {
                $.ajax({
                    url: "<?php echo e(route('product.update', $product->id)); ?>",
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    data: fd,
                    contentType: false,
                    processData: false,
                    type: 'POST',
                    success: function(data) {
                        $('#loader').fadeOut();
                        if (data.flag == "success") {
                            $('#submit-all').attr('disabled', true);
                            window.location.href = "<?php echo e(route('product.index')); ?>" + '?id=2';


                        } else {
                            show_toastr('Error', data.msg, 'error');
                            $('#submit-all').attr('disabled', false);
                        }
                    },
                    error: function(data) {
                        $('#loader').fadeOut();

                        $('#submit-all').attr('disabled', false);
                        // Dropzones.removeFile(file);
                        if (data.error) {
                            show_toastr('Error', data.error, 'error');
                        } else {
                            show_toastr('Error', data, 'error');
                        }
                    },
                });
            }
        });  
        
    </script>
<?php $__env->stopPush(); ?>
<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/product/edit.blade.php ENDPATH**/ ?>