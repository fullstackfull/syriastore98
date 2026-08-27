<div class="tab-pane fade <?php if(isset($app_setting_tab) && $app_setting_tab == 'pills-hours-tab'): ?> show active <?php endif; ?>" id="pills-hours" role="tabpanel"
    aria-labelledby="pills-hours-tab">
    <div id="hours">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> <?php echo e(__('Hours Settings')); ?> </h5>
            </div>
            <?php echo e(Form::model($business_hours, ['route' => 'theme.hours.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

            <div class="card-body p-4">
                <input type="hidden" name="app_setting_tab" value="pills-hours-tab">
                <div class="bussiness-hours">
                    <div class="row align-items-center gy-4">
                        <div class="col-lg-12">
                            <div class="bussiness-hours-header">
                                <div class="row">
                                    <div class="col-lg-2">
                                        <strong><?php echo e(__('Day')); ?></strong>
                                    </div>
                                    <div class="col-lg-5">
                                        <strong><?php echo e(__('Start Time')); ?></strong>
                                    </div>
                                    <div class="col-lg-5">
                                        <strong><?php echo e(__('End Time')); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $days; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k => $day): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="col-12">
                                <div class="row cust-day-row gy-2 gx-2 align-items-center">
                                    <div class="col-lg-auto flex-grow-1 col-md-12">
                                        <div class="form-check">
                                            <input class="form-check-input days" name="days_<?php echo e($k); ?>"
                                                type="checkbox" id="days_<?php echo e($k); ?>"
                                                <?php if(!is_null($business_hours)): ?> <?php echo e(isset($business_hours->$k) && $business_hours->$k->days == 'on' ? 'checked' : ''); ?> <?php endif; ?>>
                                            <label class="form-check-label f-4" for="days_<?php echo e($k); ?>">
                                                <?php echo e(__($day)); ?>

                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-xxl-5 col-lg-4 col-md-6 col-sm-6">
                                        <div class="form-group mb-0">
                                            <input type="time" id="days_<?php echo e($k); ?>_start"
                                                data-id="days_<?php echo e($k); ?>" name="start-<?php echo e($k); ?>"
                                                class="form-control timepicker" placeholder="08:10"
                                                value="<?php echo e(!is_null($business_hours) && isset($business_hours->$k) && $business_hours->$k->days == 'on'
                                                    ? $business_hours->$k->start_time
                                                    : ''); ?>"
                                                onchange="changeFunction(this.id)">
                                        </div>
                                    </div>
                                    <div class="col-xxl-5 col-lg-4 col-md-6 col-sm-6">
                                        <div class="form-group mb-0">
                                            <input type="time" id="days_<?php echo e($k); ?>_end"
                                                data-id="days_<?php echo e($k); ?>" name="end-<?php echo e($k); ?>"
                                                class="form-control timepicker" onchange="changeFunction(this.id)"
                                                placeholder="08:10"
                                                value="<?php echo e(!is_null($business_hours) && isset($business_hours->$k) && $business_hours->$k->days == 'on' ? $business_hours->$k->end_time : ''); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                
                <input type="submit" value="<?php echo e(__('Save Changes')); ?>" class="btn-submit btn btn-primary btn-badge">
                <?php echo Form::close(); ?>

                
            </div>
            <?php echo Form::close(); ?>

        </div>
    </div>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/MarketPlaceList/src/app/Providers/../../resources/views/buttons/appSettingViewHours.blade.php ENDPATH**/ ?>