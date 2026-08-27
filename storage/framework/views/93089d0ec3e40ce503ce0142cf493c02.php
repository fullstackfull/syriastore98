<div class="tab-pane fade <?php if(isset($app_setting_tab) && $app_setting_tab == 'pills-partial_payment-tab'): ?> show active <?php endif; ?>" id="pills-partial_payment" role="tabpanel"
    aria-labelledby="pills-partial_payment-tab">
    <div id="partial_payment_settings">
        <div class="card">
            <?php echo e(Form::model($settings, ['route' => ['update.partial.payment.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data', 'id' => 'partial-payment-setting'])); ?>

            <?php echo csrf_field(); ?>
            <input type="hidden" name="app_setting_tab" value="pills-partial_payment-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> <?php echo e(__('Partial Payment Settings')); ?> </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class=""
                            name="enable_partial_payment" id="enable_partial_payment"
                            <?php echo e($settings['enable_partial_payment'] ?? '' == 'on' ? 'checked="checked"' : ''); ?>>
                        <label class="custom-control-label" for="enable_partial_payment"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="row col-md-12">
                        <div class="form-group col-md-4">
                            <div class="custom-control form-switch p-0">
                                <label class="form-check-label form-label"
                                    for="force_deposit"><?php echo e(__('Force Deposit')); ?></label><br>
                                <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                    data-onstyle="primary" name="force_deposit" id="force_deposit"
                                    <?php echo e(isset($settings['force_deposit']) && $settings['force_deposit'] == 'on' ? 'checked="checked"' : ''); ?>>
                            </div>
                        </div>
                        <div class="form-group col-md-4">
                            <?php echo Form::label('', __('Deposit Type'), ['class' => 'form-label']); ?>

                            <?php echo Form::select('deposit_type', ['percentage' => 'Percentage', 'flat' => 'Flat'], null, [
                                'class' => 'form-control',
                                'id' => 'category',
                            ]); ?>

                        </div>
                        <div class="form-group col-md-4">
                            <?php echo e(Form::label('deposit_amount', __('Deposit Amount'), ['class' => 'form-label'])); ?>

                            <?php echo e(Form::number('deposit_amount', null, ['class' => 'form-control', 'required' => 'required', 'min' => '1', 'step' => '0.01'])); ?>

                        </div>
                    </div>
                    <div class="row col-md-12">
                        <div class="form-group col-md-4">
                            <?php echo Form::label('', __('Deposit Selection'), ['class' => 'form-label']); ?>

                            <?php echo Form::select(
                                'deposit_selection',
                                ['pay_deposit' => 'Pay Deposit', 'full_amount' => 'Full Amount'],
                                old('deposit_selection'),
                                ['class' => 'form-control'],
                            ); ?>

                        </div>
                        <div class="form-group col-md-4">
                            <?php echo Form::label('', __('Manage Status'), ['class' => 'form-label']); ?>

                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="on" id="set_order_status"
                                    name="set_order_status[]" <?php echo e(in_array('on', $options) ? 'checked' : ''); ?>>
                                <label class="form-check-label" for="set_order_status">
                                    <?php echo e(__('Adjust orders with partial payment status.')); ?>

                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <h5 class="form-group"><?php echo e(__('Manage Calculation')); ?></h5>
                <div class="row mt-2">
                    <div class="row col-md-12">
                        <div class="form-group col-md-4">
                            <?php echo Form::label('', __('Manage Tax'), ['class' => 'form-label']); ?>

                            <?php echo Form::select(
                                'tax_option',
                                [
                                    'with_future_payment' => 'Count in Future Payment',
                                    'split_amount' => 'Divide in proportion to deposit values',
                                    'with_deposit' => 'Count in Deposit',
                                ],
                                old('tax_option'),
                                ['class' => 'form-control', 'id' => 'category'],
                            ); ?>

                        </div>
                        <div class="form-group col-md-4">
                            <?php echo Form::label('', __('Manage Shipping'), ['class' => 'form-label']); ?>

                            <?php echo Form::select(
                                'shipping_option',
                                [
                                    'with_future_payment' => 'Count in Future Payment',
                                    'split_amount' => 'Divide in proportion to deposit values',
                                    'with_deposit' => 'Count in Deposit',
                                ],
                                old('shipping_option'),
                                ['class' => 'form-control', 'id' => 'category'],
                            ); ?>

                        </div>
                        <div class="form-group col-md-4">
                            <?php echo Form::label('', __('Manage Coupon'), ['class' => 'form-label']); ?>

                            <?php echo Form::select(
                                'coupon_option',
                                [
                                    'with_future_payment' => 'Count in Future Payment',
                                    'split_amount' => 'Divide in proportion to deposit values',
                                    'with_deposit' => 'Count in Deposit',
                                ],
                                old('coupon_option'),
                                ['class' => 'form-control', 'id' => 'category'],
                            ); ?>

                        </div>
                    </div>
                </div>
                <h5 class="form-group"><?php echo e(__('Manage Payments')); ?></h5>
                <div class="row mt-2">
                    <div class="row col-md-12">
                        <div class="form-group col-md-6">
                            <?php echo e(Form::label('deposite_payment_methods', __('Payment Methods for Deposit'), ['class' => 'col-form-label'])); ?>

                            <?php echo Form::select('deposite_payment_methods[]', $Payments, $deposit_enablePaymentdata, [
                                'class' => 'form-control deposite_payment_methods',
                                'id' => 'deposite_payment_methods',
                                'multiple',
                                'data-role' => 'tagsinput',
                            ]); ?>

                        </div>
                        <div class="form-group col-md-6">
                            <?php echo e(Form::label('partial_payment_methods', __('Payment Methods for Partial Payment'), ['class' => 'col-form-label'])); ?>

                            <?php echo Form::select('partial_payment_methods[]', $Payments, $enablePaymentdata, [
                                'class' => 'form-control partial_payment_methods',
                                'id' => 'partial_payment_methods',
                                'multiple',
                                'data-role' => 'tagsinput',
                            ]); ?>

                        </div>
                    </div>
                </div>
                <h5 class="form-group"><?php echo e(__('Remainder Mail Setting')); ?></h5>
                <div class="row mt-2">
                    <div class="row col-md-12">
                        <div class="form-group col-md-6">
                            <?php echo Form::label('', __('Duration Type'), ['class' => 'form-label']); ?>

                            <?php echo Form::select(
                                'remainder_duration_type',
                                ['month' => 'Month', 'year' => 'Year'],
                                old('remainder_duration_type'),
                                ['class' => 'form-control', 'id' => 'category'],
                            ); ?>

                        </div>
                        <div class="form-group col-md-4">
                            <?php echo e(Form::label('remainder_duration_period', __('Duration Period'), ['class' => 'form-label'])); ?>

                            <?php echo Form::number('remainder_duration_period', null, [
                                'class' => 'form-control',
                                'placeholder' => 'Please Enter Duration Period Number.',
                                'id' => 'remainder_duration_period',
                            ]); ?>

                            <div id="validation-message" class="text-danger" style="display: none;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <input type="submit" value="<?php echo e(__('Save Changes')); ?>" class="btn-submit btn btn-badge btn-primary">
            </div>
            <?php echo e(Form::close()); ?>

        </div>
        <div class="card">
            <?php echo e(Form::model($currEmailTempLang, ['route' => ['update.payment.email.settings', $currEmailTempLang->parent_id], 'method' => 'PUT'])); ?>

            <?php echo csrf_field(); ?>
            <input type="hidden" name="app_setting_tab" value="pills-partial_payment-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> <?php echo e(__('Payment Remainder Settings')); ?> </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages gap-2">
                        <ul class="list-unstyled m-0">
                            <li class="dropdown dash-h-item drp-language ">
                                <a class="dash-head-link dropdown-toggle arrow-none me-0 bg-info rounded-1"
                                    data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false"
                                    aria-expanded="false" id="dropdownLanguage">
                                    <span
                                        class="drp-text hide-mob payment-language"><?php echo e(Str::upper($currEmailTempLang->language)); ?></span>
                                    <i class="ti ti-chevron-down drp-arrow nocolor email_arrow"></i>
                                </a>
                                <div class="dropdown-menu dash-h-dropdown dropdown-menu-end"
                                    aria-labelledby="dropdownLanguage">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $languages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $lang): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <a href="<?php echo e(route('manage.payment.email.language', [$emailTemplate->id, $key])); ?>"
                                            class="dropdown-item languages-option <?php echo e($currEmailTempLang->language == $key ? 'text-primary' : ''); ?>"
                                            data-lang-key="<?php echo e($key); ?>"><?php echo e(Str::upper($lang)); ?></a>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </li>
                        </ul>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class=""
                            name="customer_email_enabled" id="customer_email_enabled"
                            <?php echo e($emailTemplate->template->is_active == '1' ? 'checked="checked"' : ''); ?>>
                        <label class="custom-control-label" for="customer_email_enabled"></label>
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
                                            <p class="mb-1"><?php echo e(__('Customer Name')); ?> : <span
                                                    class="pull-right text-primary">{customer_name}</span></p>
                                            <p class="mb-1"><?php echo e(__('Paid Amount')); ?> : <span
                                                    class="pull-right text-primary">{amount}</span></p>
                                            <p class="mb-1"><?php echo e(__('Date')); ?> : <span
                                                    class="pull-right text-primary">{date}</span></p>
                                            <p class="mb-1"><?php echo e(__('Pending Amount')); ?> : <span
                                                    class="pull-right text-primary">{remaining_amount}</span></p>
                                            <p class="mb-1"><?php echo e(__('Remaining Date')); ?> : <span
                                                    class="pull-right text-primary">{remaining_date}</span></p>
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

                                <?php echo e(Form::textarea('content', $currEmailTempLang->content, ['class' => 'summernote-simple payment-email-language', 'required' => 'required'])); ?>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <?php echo e(Form::hidden('language', null, ['class' => 'language-code'])); ?>

                <?php echo e(Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-badge btn-primary'])); ?>

            </div>
            <?php echo e(Form::close()); ?>

        </div>
    </div>
</div>

<?php $__env->startPush('scripts'); ?>
    <script>
        $(document).ready(function() {
            $('.languages-option').on('click', function(event) {
                event.preventDefault();

                var url = $(this).attr('href');
                var langKey = $(this).data('lang-key');

                $.ajax({
                    url: url,
                    type: 'GET',
                    success: function(response) {
                        $('.payment-email-language').summernote('code', response.data
                            .currentEmailTemplateLang.content);
                        $('.payment-language').text(response.data.currentEmailTemplateLang
                            .language.toUpperCase());
                        $('.languages-option').removeClass('text-primary');
                        $('.language-code').val(response.data.currentEmailTemplateLang
                            .language);
                        $('a[data-lang-key="' + langKey + '"]').addClass('text-primary');
                    },
                    error: function(xhr) {
                        console.error('Error changing language:', xhr);
                    }
                });
            });
        });
    </script>
    <script>
        $(document).ready(function() {
            $('#remainder_duration_period').on('input', function() {
                var value = $(this).val();
                if (value > 12) {
                    $('#validation-message').text('Duration period must be at least 12.').show();
                } else {
                    $('#validation-message').hide();
                }
            });
        });
    </script>
<?php $__env->stopPush(); ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/PartialPayments/src/app/Providers/../../resources/views/admin/partialpayments-form.blade.php ENDPATH**/ ?>