<?php $__env->startSection('page-title'); ?>
    <?php echo e(__('Plan')); ?>

<?php $__env->stopSection(); ?>

<?php
    $logo = asset(Storage::url('uploads/profile/'));
?>

<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item" aria-current="page"><?php echo e(__('Plan')); ?></li>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('action-button'); ?>
    <?php if (app('laratrust')->hasPermission('Create Plan')) : ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->type == 'super admin'): ?>
        <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="<?php echo e(route('plan.create')); ?>" class="btn btn-sm btn-badge btn-primary" data-bs-toggle="tooltip"
            title="<?php echo e(__('Create New Plan')); ?>">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; // app('laratrust')->permission ?>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>

    <div class="row plan_card_wrp mb-4">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $plans; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $plan): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6 col-12">
                <div class="plan_card">
                    <div class="card price-card price-1 wow animate__fadeInUp" data-wow-delay="0.2s"
                        style="visibility: visible; animation-delay: 0.2s; animation-name: fadeInUp;">
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-between">
                                <span class="price-badge text-dark f-w-600 text-start f-16 ps-0 mb-2"><?php echo e($plan->name); ?></span>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\Auth::user()->type == 'admin' && \Auth::user()->plan_id == $plan->id): ?>
                                    <div class="product-content-top d-flex flex-row-reverse m-0 p-0 plan-active-status btn-primary">
                                        <span class="btn btn-sm btn-icon badges bg-success">
                                            <span class="m-2"><?php echo e(__('Active')); ?></span>
                                        </span>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <div class="d-flex flex-row-reverse gap-1 active-tag mb-2 align-items-center">
                                    <?php if(\Auth::user()->type != 'admin'): ?>
                                        <?php if (app('laratrust')->hasPermission('Edit Plan')) : ?>
                                        <div class="d-inline-flex align-items-center">
                                            <a class="btn btn-sm btn-info"
                                                href="<?php echo e(route('plan.edit', $plan->id)); ?>"
                                                data-bs-toggle="tooltip"
                                                title="<?php echo e(__('Edit')); ?>">
                                                <i class="ti ti-pencil" ></i>
                                            </a>
                                        </div>
                                        <?php endif; // app('laratrust')->permission ?>

                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->price > 0): ?>
                                            <div class="">
                                                <?php echo Form::open([
                                                    'method' => 'DELETE',
                                                    'route' => ['plan.destroy', $plan->id],
                                                    'id' => 'delete-form-' . $plan->id,
                                                ]); ?>

                                                <a href="#!"
                                                    class="bs-pass-para btn btn-danger show_confirm btn-icon btn-sm" data-bs-toggle="tooltip" data-confirm="<?php echo e(__('Are You Sure?')); ?>"
                                                    data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>" data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>" 
                                                    title="<?php echo e(__('Delete')); ?>">
                                                    <i class="ti ti-trash"></i>
                                                </a>
                                                <?php echo Form::close(); ?>

                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if(\Auth::user()->type == 'super admin' && $plan->price > 0): ?>
                                        <div class="form-check form-switch custom-switch-v1 float-end">
                                            <input type="checkbox" name="plan_disable"
                                                class="form-check-input input-primary is_disable" value="1"
                                                data-id='<?php echo e($plan->id); ?>' data-name="<?php echo e(__('plan')); ?>" data-bs-toggle="tooltip"
                                                title="<?php echo e(__('Enable/Disable')); ?>"
                                                <?php echo e($plan->is_disable == 1 ? 'checked' : ''); ?>>
                                            <label class="form-check-label" for="plan_disable"></label>
                                        </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </div>

                            <h3 class="mb-3 f-w-600 text-start text-primary">
                                <?php echo e(!empty($setting['CURRENCY']) ? $setting['CURRENCY'] : '$'); ?><?php echo e($plan->price . ' / ' . (\App\Models\Plan::getDurationsArr()[$plan->duration])); ?></small>
                            </h3>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->price > 0 && $plan->trial != 0): ?>
                                <p class="mb-0">
                                    <?php echo e(__('Free Trial Days : ') . __($plan->trial_days ? $plan->trial_days : 0)); ?><br />
                                </p>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->description): ?>
                                <p class="text-start">
                                    <?php echo strip_tags($plan->description); ?><br />
                                </p>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <div class="row mb-0">
                                <div class="col-4 text-start">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->max_products == '-1'): ?>
                                        <span class="h5 mb-0"><?php echo e(__('Unlimited')); ?></span>
                                    <?php else: ?>
                                        <span class="h5 mb-0"><?php echo e($plan->max_products); ?></span>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <span class="d-block text-sm"><?php echo e(__('Products')); ?></span>
                                </div>
                                <div class="col-4 text-start">
                                    <span class="h5 mb-0">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->max_stores == '-1'): ?>
                                            <span class="h5 mb-0"><?php echo e(__('Unlimited')); ?></span>
                                        <?php else: ?>
                                            <span class="h5 mb-0"><?php echo e($plan->max_stores); ?></span>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </span>
                                    <span class="d-block text-sm"><?php echo e(__('Store')); ?></span>
                                </div>
                                <div class="col-4 text-start">
                                    <span class="h5 mb-0">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->max_users == '-1'): ?>
                                            <span class="h5 mb-0"><?php echo e(__('Unlimited')); ?></span>
                                        <?php else: ?>
                                            <span class="h5 mb-0"><?php echo e($plan->max_users); ?></span>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </span>
                                    <span class="d-block text-sm"><?php echo e(__('Users')); ?></span>
                                </div>
                            </div>
                            <div class="plan-card-detail d-flex text-center">
                                <ul class="list-unstyled d-inline-block my-2">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->enable_domain == 'on'): ?>
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-primary ti ti-circle-plus"></i></span><?php echo e(__('Custom Domain')); ?>

                                        </li>
                                    <?php else: ?>
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-danger ti ti-circle-plus"></i></span><?php echo e(__('Custom Domain')); ?>

                                        </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->enable_subdomain == 'on'): ?>
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span><?php echo e(__('Sub Domain')); ?>

                                        </li>
                                    <?php else: ?>
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-danger ti ti-circle-plus"></i></span><?php echo e(__('Sub Domain')); ?>

                                        </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && $plan->enable_chatgpt == 'on'): ?>
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span><?php echo e(__('Chatgpt')); ?>

                                        </li>
                                    <?php else: ?>
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-danger ti ti-circle-plus"></i></span><?php echo e(__('Chatgpt')); ?>

                                        </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && $plan->pwa_store == 'on'): ?>
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span>
                                            <?php echo e(__('Progressive Web App (PWA)')); ?>

                                        </li>
                                    <?php else: ?>
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-danger ti ti-circle-plus"></i></span><?php echo e(__('Progressive Web App (PWA)')); ?>

                                        </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && $plan->shipping_method == 'on'): ?>
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span>
                                            <?php echo e(__('Shipping Method')); ?>

                                        </li>
                                    <?php else: ?>
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-danger ti ti-circle-plus"></i></span><?php echo e(__('Shipping Method')); ?>

                                        </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && $plan->enable_tax == 'on'): ?>
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span><?php echo e(__('Enable Tax')); ?>

                                        </li>
                                    <?php else: ?>
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-danger ti ti-circle-plus"></i></span><?php echo e(__('Enable Tax')); ?>

                                        </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && $plan->storage_limit != '0.00'): ?>
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span>
                                            <?php echo e($plan->storage_limit); ?><?php echo e(__('MB Storage')); ?>

                                        </li>
                                    <?php else: ?>
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-danger ti ti-circle-plus"></i></span><?php echo e(__('0 MB Storage')); ?>

                                        </li>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </ul>
                            </div>
                            <div class="row d-flex">
                                <?php if(\Auth::user()->type != 'super admin'): ?>
                                    <?php if(\Auth::user()->type == 'admin' && \Auth::user()->trial_expire_date): ?>
                                        <?php if(\Auth::user()->type == 'admin' && \Auth::user()->trial_plan == $plan->id): ?>
                                            <p class="display-total-time mb-0">
                                                <?php echo e(__('Plan Trial Expired : ')); ?>

                                                <?php echo e(!empty(\Auth::user()->trial_expire_date) ? \Auth::user()->dateFormat(\Auth::user()->trial_expire_date) : 'Unlimited'); ?>

                                            </p>
                                        <?php elseif(
                                            \Auth::user()->plan_id == $plan->id &&
                                                !empty(\Auth::user()->trial_expire_date) &&
                                                \Auth::user()->trial_expire_date < date('Y-m-d') &&
                                                $plan->price > 0): ?>
                                            <div class="col-12">
                                                <p
                                                    class="server-plan font-bold text-center bg-primary mb-0 btn btn-primary w-100 text-success">
                                                    <?php echo e(__('Expired')); ?>

                                                </p>
                                            </div>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php else: ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\Auth::user()->type == 'admin' && \Auth::user()->plan_id == $plan->id): ?>
                                            <p class="display-total-time mb-0">
                                                <?php echo e(__('Plan Expired : ')); ?>

                                                <?php echo e(!empty(\Auth::user()->plan_expire_date) ? \Auth::user()->dateFormat(\Auth::user()->plan_expire_date) : 'Unlimited'); ?>

                                            </p>
                                        <?php elseif(
                                            \Auth::user()->plan_id == $plan->id &&
                                                !empty(\Auth::user()->plan_expire_date) &&
                                                \Auth::user()->plan_expire_date < date('Y-m-d') &&
                                                $plan->price > 0): ?>
                                            <div class="col-12">
                                                <p
                                                    class="server-plan font-bold text-center bg-primary mb-0 btn btn-primary w-100 text-success">
                                                    <?php echo e(__('Expired')); ?>

                                                </p>
                                            </div>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->id != \Auth::user()->plan_id): ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->price > 0): ?>
                                            <div class="<?php echo e($plan->id == 1 ? 'col-12' : 'col-12'); ?>">
                                                <div class="row">
                                                    <div class="col-9">
                                                    <a href="<?php echo e(route('stripe', \Illuminate\Support\Facades\Crypt::encrypt($plan->id))); ?>"
                                                        class="btn w-100 btn-badge btn-primary d-flex align-items-center gap-2  justify-content-center"><?php echo e(__('Subscribe')); ?>

                                                        <i class="fas fa-arrow-right"></i>
                                                    </a>
                                                    </div>  
                                                    <div class="col-3">
                                                    <?php if(\Auth::user()->type != 'super admin' && \Auth::user()->plan_id != $plan->id): ?>
                                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && $plan->id != 1): ?>
                                                            <?php if(\Auth::user()->requested_plan != $plan->id): ?>
                                                                <a href="<?php echo e(route('send.request', [\Illuminate\Support\Facades\Crypt::encrypt($plan->id)])); ?>"
                                                                    class="btn btn-badge btn-primary btn-icon w-100"
                                                                    data-title="<?php echo e(__('Send Request')); ?>" data-bs-toggle="tooltip"
                                                                    data-bs-placement="top" title="<?php echo e(__('Send Request')); ?>">
                                                                    <span class="btn-inner--icon"><i class="fas fa-share"></i></span>
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="<?php echo e(route('request.cancel', \Auth::user()->id)); ?>"
                                                                    class="btn btn-badge btn-icon btn-danger w-100"
                                                                    data-title="<?php echo e(__('Cancel Request')); ?>" data-bs-toggle="tooltip"
                                                                    data-bs-placement="top" title="<?php echo e(__('Cancel Request')); ?>">
                                                                    <span class="btn-inner--icon"><i class="fas fa-times"></i></span>
                                                                </a>
                                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    </div>  
                                                </div>  
                                                <div class="row">  
                                                    <div class="col-12">  
                                                        <?php if(\Auth::user()->type != 'super admin' && \Auth::user()->plan_id != $plan->id): ?>
                                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->price > 0 && \Auth::user()->trial_plan == 0 && \Auth::user()->plan_id != $plan->id && $plan->trial == 1): ?>
                                                                <a href="<?php echo e(route('plan.trial', \Illuminate\Support\Facades\Crypt::encrypt($plan->id))); ?>"
                                                                    class="btn btn-lg btn-primary btn-badge btn-icon m-1 w-100"><?php echo e(__('Start Free Trial')); ?></a>
                                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                
                                <?php elseif(\Auth::user()->type == 'super admin' && isset($plan->trial) && ($plan->trial == 1) && isset($plan->trial_days) && !empty($plan->trial_days)): ?>
                                    <p class="display-total-time mb-0">
                                        <?php echo e(__('Plan Expired : ')); ?>

                                        <?php echo e($plan->trial_days .' '. __('Days')); ?>

                                    </p>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <div class="row">
        <div class="col-md-12">
        <?php if (isset($component)) { $__componentOriginal8aaf9779783cdf64609094123653a0b9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8aaf9779783cdf64609094123653a0b9 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.datatable','data' => ['dataTable' => $dataTable]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('datatable'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['dataTable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dataTable)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8aaf9779783cdf64609094123653a0b9)): ?>
<?php $attributes = $__attributesOriginal8aaf9779783cdf64609094123653a0b9; ?>
<?php unset($__attributesOriginal8aaf9779783cdf64609094123653a0b9); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8aaf9779783cdf64609094123653a0b9)): ?>
<?php $component = $__componentOriginal8aaf9779783cdf64609094123653a0b9; ?>
<?php unset($__componentOriginal8aaf9779783cdf64609094123653a0b9); ?>
<?php endif; ?>
        </div>
    </div>
<?php $__env->stopSection(); ?>
<?php $__env->startPush('custom-script'); ?>
    <script>
        $(document).on('change', '#trial', function() {
            if ($(this).is(':checked')) {
                $('.plan_div').removeClass('d-none');
                $('#trial_days').attr("required", true);

            } else {
                $('.plan_div').addClass('d-none');
                $('#trial_days').removeAttr("required");
            }
        });
    </script>

    <script>
        $(document).on("click", ".is_disable", function() {

            var id = $(this).attr('data-id');
            var is_disable = ($(this).is(':checked')) ? $(this).val() : 0;
            $.ajax({
                url: '<?php echo e(route('plan.disable')); ?>',
                type: 'POST',
                data: {
                    "is_disable": is_disable,
                    "id": id,
                    "_token": "<?php echo e(csrf_token()); ?>",
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.success) {
                        show_toastr('Success', data.success, 'success');
                    } else {
                        show_toastr('error', data.error);

                    }
                }
            });
        });
    </script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/plans/index.blade.php ENDPATH**/ ?>