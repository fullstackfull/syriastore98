<?php $__env->startSection('page-title', __('Themes')); ?>

<?php $__env->startSection('breadcrumb'); ?>
 <li class="breadcrumb-item"><?php echo e(__('Themes')); ?></li>
<?php $__env->stopSection(); ?>


<?php $__env->startSection('content'); ?>
<div class="tab-content mb-4">
    <div class="tab-pane fade show active" id="theme-setting" role="tabpanel" aria-labelledby="theme-setting-tab">
        <div class="card p-3 mb-0">
            <div class="card-body p-0">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="theme-layout" role="tabpanel"
                        aria-labelledby="theme-layout-tab">
                        <div class="row row-gap-2">
                             <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $themes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $folder): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!in_array($folder, $addons)): ?>
                                    <?php continue; ?>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <div class="col-xxl-3 col-lg-4 col-sm-6 col-12 business-view-card">
                                    <label for="theme-1">
                                        <div class="business-view-inner">
                                            <div class="buisness-img mb-3">
                                                <img src="<?php echo e(asset('themes/'.$folder.'/theme_img/img_1.png')); ?>" alt="theme-img" loading="lazy">
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between gap-2">
                                                <h2 class="h5 mb-0 text-break"><?php echo e(ucfirst($folder)); ?></h2>
                                                 <div class="d-flex align-items-center justify-content-between gap-1">
                                                    <a class="btn btn-sm btn-primary text-end" href="<?php echo e(route('theme.pages',$folder)); ?>">
                                                    <?php echo e(__('Customize')); ?>

                                                    </a>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(APP_THEME() != $folder): ?>
                                                        <?php echo Form::open(['method' => 'POST', 'route' => ['theme-preview.make-active'], 'class' => 'd-inline']); ?>

                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="theme_id" value="<?php echo e($folder); ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary text-end" <?php echo e((APP_THEME()  == $folder ? 'disabled' : '')); ?>>
                                                            <?php echo e(__('Make Active')); ?>

                                                            </button>
                                                        <?php echo Form::close(); ?>

                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('custom-script'); ?>
<script type="text/javascript">
    $(document).on('keyup','#theme-search', function() {
        var value = $(this).val().toLowerCase();
        $('#theme-list .theme-item').filter(function() {
            $(this).toggle($(this).find('.theme-card-lable').text().toLowerCase().indexOf(value) > -1)
        });
    });
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/theme_customize/index.blade.php ENDPATH**/ ?>