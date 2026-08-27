<div class="tab-pane fade <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-review_reminder-tab')): ?> show active <?php endif; ?>" id="pills-review_reminder" role="tabpanel" aria-labelledby="pills-review_reminder-tab">
    <div id="review_reminder_settings">
        <?php echo e(Form::model($currEmailTempLang, ['route' => ['update.review.email.settings', $currEmailTempLang->parent_id], 'method' => 'POST'])); ?>

        <?php echo csrf_field(); ?>
        <input type="hidden" name="app_setting_tab" value="pills-review_reminder-tab">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="m-0"> <?php echo e(__('Review Reminder Settings')); ?> </h5>
                <div class="gap-2 switch-width text-end d-flex">
                    <div class="">
                        <ul class="list-unstyled mb-0">
                            <li class="form-control dropdown dash-h-item bg-info drp-language" style="width: 75px;">
                                <a class="dash-head-link dropdown-toggle arrow-none me-0 rounded-1" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false" id="dropdownLanguage">
                                    <span class="drp-text hide-mob"><?php echo e(Str::upper($currEmailTempLang->language)); ?></span>
                                    <i class="ti ti-chevron-down drp-arrow nocolor email_arrow"></i>
                                </a>
                                <div class="dropdown-menu dash-h-dropdown dropdown-menu-end" aria-labelledby="dropdownLanguage">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $languages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $lang): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <a href="<?php echo e(route('manage.review.email.language', [$emailTemplate->id, $key])); ?>" class="dropdown-item <?php echo e($currEmailTempLang->language == $key ? 'text-primary' : ''); ?>"><?php echo e(Str::upper($lang)); ?></a>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div class="">
                        <div class="form-group mb-0">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="order_review_enabled" name="is_active" id="order_review_enabled" value="1" <?php echo e($emailTemplate->template->is_active == '1' ? 'checked="checked"' : ''); ?>>
                                <label class="custom-control-label" for="order_review_enabled"></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="col-xl-12">
                        <div class="row">
                            <div class="col-lg-12 col-md-12 col-sm-12">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="row text-xs">
                                            <p class="mb-1"><?php echo e(__('Store Name')); ?> : <span class="pull-right text-primary">{store_name}</span></p>
                                            <p class="mb-1"><?php echo e(__('Review URL')); ?> : <span class="pull-right text-primary">{review_url}</span></p>
                                            <p class="mb-1"><?php echo e(__('App Name')); ?> : <span class="pull-right text-primary">{app_name}</span></p>
                                            <p class="mb-1"><?php echo e(__('customer Name')); ?> : <span class="pull-right text-primary">{customer_name}</span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group col-6">
                                <?php echo e(Form::label('subject', __('Subject'), ['class' => 'col-form-label text-dark'])); ?>

                                <?php echo e(Form::text('subject', null, ['class' => 'form-control font-style', 'required' => 'required'])); ?>

                            </div>
                            <div class="form-group col-md-6">
                                <?php echo e(Form::label('from', __('From'), ['class' => 'col-form-label text-dark'])); ?>

                                <?php echo e(Form::text('from', $emailTemplate->from, ['class' => 'form-control font-style', 'required' => 'required'])); ?>

                            </div>
                            <div class="form-group col-12">
                                <?php echo e(Form::label('content', __('Email Message'), ['class' => 'col-form-label text-dark'])); ?>

                                <?php echo e(Form::textarea('content', $currEmailTempLang->content, ['class' => 'summernote-simple', 'required' => 'required'])); ?>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <?php echo e(Form::hidden('language', null)); ?>

                <?php echo e(Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-primary btn-badge'])); ?>

            </div>
        </div>
        <?php echo e(Form::close()); ?>

    </div>
</div>

<?php /**PATH /home/storesyria/public_html/packages/workdo/ReviewReminder/src/app/Providers/../../resources/views/admin/reviewReminderSettingDetails.blade.php ENDPATH**/ ?>