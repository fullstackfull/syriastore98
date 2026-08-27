<?php $__env->startSection('page-title', __('Order Summary')); ?>

<?php $__env->startSection('action-button'); ?>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item"><a href="<?php echo e(route('plan.index')); ?>"><?php echo e(__('Plan')); ?></a></li>
    <li class="breadcrumb-item"><?php echo e(__('Order Summary')); ?></li>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>

    <div class="row plan-order-sumry-wrp">
        <div class="col-sm-12">
            <div class="row plan-order-sumry-content-wrp">
                <div class="col-xl-3">
                    <div class="sticky-top" style="top:30px">
                        <div class="card ">
                            <div class="list-group list-group-flush" id="useradd-sidenav">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_manually_enabled']) && $admin_payments_details['is_manually_enabled'] == 'on'): ?>
                                    <a href="#manually_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Manually')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_stripe_enabled']) && $admin_payments_details['is_stripe_enabled'] == 'on'): ?>
                                    <a href="#stripe_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Stripe')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paystack_enabled']) && $admin_payments_details['is_paystack_enabled'] == 'on'): ?>
                                    <a href="#paystack_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Paystack')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_razorpay_enabled']) && $admin_payments_details['is_razorpay_enabled'] == 'on'): ?>
                                    <a href="#razorpay_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Razorpay')); ?> <div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_mercado_enabled']) && $admin_payments_details['is_mercado_enabled'] == 'on'): ?>
                                    <a href="#mercado_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Mercado Pago')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_skrill_enabled']) && $admin_payments_details['is_skrill_enabled'] == 'on'): ?>
                                    <a href="#skrill_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Skrill')); ?> <div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paymentwall_enabled']) &&
                                        $admin_payments_details['is_paymentwall_enabled'] == 'on'): ?>
                                    <a href="#paymentwall_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Paymentwall')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paypal_enabled']) && $admin_payments_details['is_paypal_enabled'] == 'on'): ?>
                                    <a href="#paypal_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Paypal')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_flutterwave_enabled']) &&
                                        $admin_payments_details['is_flutterwave_enabled'] == 'on'): ?>
                                    <a href="#flutterwave_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Flutterwave')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paytm_enabled']) && $admin_payments_details['is_paytm_enabled'] == 'on'): ?>
                                    <a href="#paytm_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Paytm')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_mollie_enabled']) && $admin_payments_details['is_mollie_enabled'] == 'on'): ?>
                                    <a href="#mollie_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Mollie')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_coingate_enabled']) && $admin_payments_details['is_coingate_enabled'] == 'on'): ?>
                                    <a href="#coingate_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Coingate')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_sspay_enabled']) && $admin_payments_details['is_sspay_enabled'] == 'on'): ?>
                                    <a href="#sspay_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Sspay')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_toyyibpay_enabled']) && $admin_payments_details['is_toyyibpay_enabled'] == 'on'): ?>
                                    <a href="#toyyibpay_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Toyyibpay')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_bank_transfer_enabled']) &&
                                        $admin_payments_details['is_bank_transfer_enabled'] == 'on'): ?>
                                    <a href="#bank_transfer_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Bank Transfer')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paytabs_enabled']) && $admin_payments_details['is_paytabs_enabled'] == 'on'): ?>
                                    <a href="#paytabs_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Paytabs')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_iyzipay_enabled']) && $admin_payments_details['is_iyzipay_enabled'] == 'on'): ?>
                                    <a href="#iyzipay_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Iyzipay')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_payfast_enabled']) && $admin_payments_details['is_payfast_enabled'] == 'on'): ?>
                                    <a href="#payfast_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('PayFast')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_benefit_enabled']) && $admin_payments_details['is_benefit_enabled'] == 'on'): ?>
                                    <a href="#benefit_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Benefit')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_cashfree_enabled']) && $admin_payments_details['is_cashfree_enabled'] == 'on'): ?>
                                    <a href="#cashfree_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Cashfree')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_aamarpay_enabled']) && $admin_payments_details['is_aamarpay_enabled'] == 'on'): ?>
                                    <a href="#aamarpay_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Aamarpay')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paytr_enabled']) && $admin_payments_details['is_paytr_enabled'] == 'on'): ?>
                                    <a href="#paytr_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Pay TR')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_yookassa_enabled']) && $admin_payments_details['is_yookassa_enabled'] == 'on'): ?>
                                    <a href="#yookassa_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Yookassa')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_Xendit_enabled']) && $admin_payments_details['is_Xendit_enabled'] == 'on'): ?>
                                    <a href="#Xendit_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Xendit')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_midtrans_enabled']) && $admin_payments_details['is_midtrans_enabled'] == 'on'): ?>
                                    <a href="#midtrans_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Midtrans')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_nepalste_enabled']) && $admin_payments_details['is_nepalste_enabled'] == 'on'): ?>
                                    <a href="#nepalste_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Nepalste')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_khalti_enabled']) && $admin_payments_details['is_khalti_enabled'] == 'on'): ?>
                                    <a href="#khalti_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Khalti')); ?><div
                                        class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_payhere_enabled']) && $admin_payments_details['is_payhere_enabled'] == 'on'): ?>
                                    <a href="#payhere_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('PayHere')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_authorizenet_enabled']) && $admin_payments_details['is_authorizenet_enabled'] == 'on'): ?>
                                    <a href="#authorizenet_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('AuthorizeNet')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_tap_enabled']) && $admin_payments_details['is_tap_enabled'] == 'on'): ?>
                                    <a href="#tap_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Tap')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_phonepe_enabled']) && $admin_payments_details['is_phonepe_enabled'] == 'on'): ?>
                                    <a href="#phonepe_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('PhonePe')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paddle_enabled']) && $admin_payments_details['is_paddle_enabled'] == 'on'): ?>
                                    <a href="#paddle_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Paddle')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paiementpro_enabled']) && $admin_payments_details['is_paiementpro_enabled'] == 'on'): ?>
                                    <a href="#paiementpro_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Paiement Pro')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_fedpay_enabled']) && $admin_payments_details['is_fedpay_enabled'] == 'on'): ?>
                                    <a href="#fedpay_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('FedPay')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_cinetpay_enabled']) && $admin_payments_details['is_cinetpay_enabled'] == 'on'): ?>
                                    <a href="#cinetpay_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('CinetPay')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_Senangpay_enabled']) && $admin_payments_details['is_Senangpay_enabled'] == 'on'): ?>
                                <a href="#Senangpay_payment"
                                    class="list-group-item list-group-item-action border-0"><?php echo e(__('SenangPay')); ?><div
                                        class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_cybersource_enabled']) && $admin_payments_details['is_cybersource_enabled'] == 'on'): ?>
                                <a href="#cybersource_payment"
                                    class="list-group-item list-group-item-action border-0"><?php echo e(__('CyberSource')); ?><div
                                        class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_ozow_enabled']) && $admin_payments_details['is_ozow_enabled'] == 'on'): ?>
                                <a href="#ozow_payment"
                                    class="list-group-item list-group-item-action border-0"><?php echo e(__('Ozow')); ?><div
                                        class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_myfatoorah_enabled']) && $admin_payments_details['is_myfatoorah_enabled'] == 'on'): ?>
                                <a href="#myfatoorah_payment"
                                    class="list-group-item list-group-item-action border-0"><?php echo e(__('MyFatoorah')); ?><div
                                        class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_easebuzz_enabled']) && $admin_payments_details['is_easebuzz_enabled'] == 'on'): ?>
                                    <a href="#easebuzz_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Easebuzz')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_nmi_enabled']) && $admin_payments_details['is_nmi_enabled'] == 'on'): ?>
                                <a href="#nmi_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('NMI')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_payu_enabled']) && $admin_payments_details['is_payu_enabled'] == 'on'): ?>
                                    <a href="#payu_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('PayU')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_sofort_enabled']) && $admin_payments_details['is_sofort_enabled'] == 'on'): ?>
                                    <a href="#sofort_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('Sofort')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_esewa_enabled']) && $admin_payments_details['is_esewa_enabled'] == 'on'): ?>
                                    <a href="#sofort_payment"
                                        class="list-group-item list-group-item-action border-0"><?php echo e(__('ESewa')); ?>

                                        <div class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paynow_enabled']) && $admin_payments_details['is_paynow_enabled'] == 'on'): ?>
                                    <a href="#paynow_payment"
                                    class="list-group-item list-group-item-action border-0"><?php echo e(__('Paynow')); ?><div
                                        class="float-end"><i class="ti ti-chevron-right"></i></div>
                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_dpopay_enabled']) && $admin_payments_details['is_dpopay_enabled'] == 'on'): ?>
                                    <a href="#dpopay_payment"
                                        class="border-0 list-group-item list-group-item-action"><?php echo e(__('DPO Pay')); ?><div
                                            class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_braintree_enabled']) && $admin_payments_details['is_braintree_enabled'] == 'on'): ?>
                                <a href="#braintree_payment"
                                    class="list-group-item list-group-item-action border-0"><?php echo e(__('Braintree')); ?><div
                                        class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_powertranz_enabled']) && $admin_payments_details['is_powertranz_enabled'] == 'on'): ?>
                                <a href="#powertranz_payment"
                                    class="list-group-item list-group-item-action border-0"><?php echo e(__('PowerTranz')); ?><div
                                        class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_sslcommerz_enabled']) && $admin_payments_details['is_sslcommerz_enabled'] == 'on'): ?>
                                <a href="#sslcommerz_payment"
                                    class="list-group-item list-group-item-action border-0"><?php echo e(__('SSLCommerz')); ?><div
                                        class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-5">
                            <div class="card price-card price-1 wow animate__fadeInUp" data-wow-delay="0.2s"
                                style="visibility: visible; animation-delay: 0.2s; animation-name: fadeInUp;">
                                <div class="card-body">
                                    <span class="price-badge bg-primary badge rounded-pill"><?php echo e($plan->name); ?></span>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\Auth::user()->type == 'admin' && \Auth::user()->plan_id == $plan->id): ?>
                                        <div class="d-flex flex-row-reverse m-0 p-0 ">
                                            <span class="d-flex align-items-center ">
                                                <i class="f-10 lh-1 fas fa-circle text-primary"></i>
                                                <span class="ms-2"><?php echo e(__('Active')); ?></span>
                                            </span>
                                        </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <h3 class="mb-4 f-w-600  ">
                                        <?php echo e(!empty($admin_payments_details['CURRENCY']) ? $admin_payments_details['CURRENCY'] : '$'); ?><?php echo e($plan->price . ' / ' . (\App\Models\Plan::getDurationsArr()[$plan->duration])); ?></small>
                                        </h1>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->description): ?>
                                            <p class="mb-0">
                                                <?php echo e($plan->description); ?><br />
                                            </p>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <ul class="list-unstyled d-inline-block my-5">
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
                                                        <i
                                                            class="text-primary ti ti-circle-plus"></i></span><?php echo e(__('Sub Domain')); ?>

                                                </li>
                                            <?php else: ?>
                                                <li class="text-danger d-flex align-items-center">
                                                    <span class="theme-avtar">
                                                        <i
                                                            class="text-danger ti ti-circle-plus"></i></span><?php echo e(__('Sub Domain')); ?>

                                                </li>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan && $plan->enable_chatgpt == 'on'): ?>
                                                <li class="d-flex align-items-center">
                                                    <span class="theme-avtar">
                                                        <i
                                                            class="text-primary ti ti-circle-plus"></i></span><?php echo e(__('Chatgpt')); ?>

                                                </li>
                                            <?php else: ?>
                                                <li class="text-danger d-flex align-items-center">
                                                    <span class="theme-avtar">
                                                        <i
                                                            class="text-danger ti ti-circle-plus"></i></span><?php echo e(__('Chatgpt')); ?>

                                                </li>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->pwa_store == 'on'): ?>
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
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->shipping_method == 'on'): ?>
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
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->storage_limit != '0.00'): ?>
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

                                        <div class="row mb-3">
                                            <div class="col-4 text-center">
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->max_products == '-1'): ?>
                                                    <span class="h5 mb-0"><?php echo e(__('Unlimited')); ?></span>
                                                <?php else: ?>
                                                    <span class="h5 mb-0"><?php echo e($plan->max_products); ?></span>
                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                <span class="d-block text-sm"><?php echo e(__('Products')); ?></span>
                                            </div>
                                            <div class="col-4 text-center">
                                                <span class="h5 mb-0">
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($plan->max_stores == '-1'): ?>
                                                        <span class="h5 mb-0"><?php echo e(__('Unlimited')); ?></span>
                                                    <?php else: ?>
                                                        <span class="h5 mb-0"><?php echo e($plan->max_stores); ?></span>
                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                </span>
                                                <span class="d-block text-sm"><?php echo e(__('Store')); ?></span>
                                            </div>
                                            <div class="col-4 text-center">
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
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-9">
                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_manually_enabled']) && $admin_payments_details['is_manually_enabled'] == 'on'): ?>
                        <div id="manually_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Manually')); ?></h5>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_manually_enabled'] == 'on' ): ?>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_manually_enabled'] == 'on' ) == 'on' ? 'active' : ''); ?>"
                                    id="manually_payment">
                                    <form role="form" action="#" method="post"
                                        class="require-validation" id="payment-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="total_price"
                                            class="form-control final-price">
                                        <div class="border p-3 rounded manually-payment-div">
                                            <div class="row">
                                                <div class="col-sm-8">

                                                    <p class="mb-0 pt-1 text-sm">
                                                        <?php echo e(__('Requesting manual payment for the planned amount for the subscriptions plan.

                                                        ')); ?>

                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12 my-2 px-2">
                                            <div class="text-end">
                                                <a href="<?php echo e(route('send.request',[\Illuminate\Support\Facades\Crypt::encrypt($plan->id)])); ?>"
                                                    class="btn btn-xs btn-primary"
                                                    data-title="<?php echo e(__('Send Request')); ?>"  data-bs-toggle="tooltip" data-bs-placement="top"
                                                    title="<?php echo e(__('Send Request')); ?>">
                                                     <span class="btn-inner--icon"><?php echo e(__('Send Request')); ?></span>
                                                 </a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    
                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_stripe_enabled']) && $admin_payments_details['is_stripe_enabled'] == 'on'): ?>
                        <div id="stripe_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Stripe')); ?></h5>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_stripe_enabled'] == 'on' &&
                                    !empty($admin_payments_details['stripe_publishable_key']) &&
                                    !empty($admin_payments_details['stripe_secret_key'])): ?>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_stripe_enabled'] == 'on' && !empty($admin_payments_details['stripe_publishable_key']) && !empty($admin_payments_details['stripe_secret'])) == 'on' ? 'active' : ''); ?>"
                                    id="stripe_payment">
                                    <form role="form" action="<?php echo e(route('stripe.payment')); ?>" method="post"
                                        class="require-validation" id="payment-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded stripe-payment-div">
                                            <div class="row">
                                                <div class="col-sm-8">
                                                    <div class="custom-radio">
                                                        <label
                                                            class="font-16 font-weight-bold"><?php echo e(__('Credit / Debit Card')); ?></label>
                                                    </div>
                                                    <p class="mb-0 pt-1 text-sm">
                                                        <?php echo e(__('Safe money transfer using your bank account. We support Mastercard, Visa, Discover and American express.')); ?>

                                                    </p>
                                                </div>

                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label for="card-name-on"
                                                            class="form-label text-dark"><?php echo e(__('Name on card')); ?></label>
                                                        <input type="text" name="name" id="card-name-on"
                                                            class="form-control required"
                                                            placeholder="<?php echo e(\Auth::user()->name); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-12">
                                                    <div class="form-group w-100">
                                                        <label for="card-element"
                                                            class="form-label"><?php echo e(__('Card Number')); ?></label>
                                                        <div id="card-element" class="form-control">
                                                        </div>
                                                    </div>
                                                    <div id="card-errors" role="alert"></div>
                                                </div>
                                                <div class="col-md-12 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="paypal_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="stripe_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="error" style="display: none;">
                                                        <div class='alert-danger alert'>
                                                            <?php echo e(__('Please correct the errors and try again.')); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="hidden" name="plan_id"
                                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-xs btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    <!-- Paystack -->
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paystack_enabled']) && $admin_payments_details['is_paystack_enabled'] == 'on'): ?>
                        <div id="paystack_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Paystack')); ?></h5>

                            </div>
                            <form role="form" action="<?php echo e(route('plan.pay.with.paystack', [])); ?>" method="post"
                                class="require-validation" id="paystack-payment-form">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="plan_id"
                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                <div id="paystack-payment" class="tabs-card">
                                    <div class="tab-pane ">
                                        <div class="border p-3 mb-3 rounded payment-box">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="paystack_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="paystack_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-12 text-right paymentwall-coupon-tr" style="display: none">
                                                <b><?php echo e(__('Coupon Discount')); ?></b> : <b
                                                    class="paymentwall-coupon-price"></b>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="button" id="pay_with_paystack" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <!-- Paystack end -->

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_razorpay_enabled']) && $admin_payments_details['is_razorpay_enabled'] == 'on'): ?>
                        <div id="razorpay_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Razorpay')); ?> </h5>

                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_razorpay_enabled']) && $admin_payments_details['is_razorpay_enabled'] == 'on'): ?>
                                <form role="form" action="<?php echo e(route('plan.pay.with.razorpay')); ?>" method="post"
                                    class="require-validation" id="razorpay-payment-form">
                                    <?php echo csrf_field(); ?>
                                    <div class="tab-pane " id="razorpay_payment">
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded payment-box">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="razorpay_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="razorpay_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="button" id="pay_with_razorpay" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary">
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_mercado_enabled']) && $admin_payments_details['is_mercado_enabled'] == 'on'): ?>
                        <div id="mercado_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Mercado Pago')); ?></h5>

                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_mercado_enabled']) && $admin_payments_details['is_mercado_enabled'] == 'on'): ?>
                                <div class="tab-pane " id="mercado_payment">

                                    <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                        id="payment-form" action="<?php echo e(route('plan.pay.with.mercado')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded payment-box">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="mercado_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="mercado_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="submit" id="pay_with_mercado" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary">

                                            </div>
                                        </div>
                                    </form>

                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_skrill_enabled']) && $admin_payments_details['is_skrill_enabled'] == 'on'): ?>
                        <div id="skrill_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Skrill')); ?></h5>

                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_skrill_enabled']) && $admin_payments_details['is_skrill_enabled'] == 'on'): ?>
                                <div class="tab-pane " id="skrill_payment">

                                    <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                        id="payment-form" action="<?php echo e(route('plan.pay.with.skrill')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded payment-box">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="skrill_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="skrill_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="submit" id="pay_with_skrill" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary">
                                            </div>
                                        </div>
                                    </form>

                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(isset($admin_payments_details['is_paymentwall_enabled']) &&
                            $admin_payments_details['is_paymentwall_enabled'] == 'on'): ?>
                        <div id="paymentwall_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Paymentwall')); ?></h5>

                            </div>
                            <div class="tab-pane " id="paymentwall_payment">

                                <form role="form" action="<?php echo e(route('plan.paymentwallpayment')); ?>"
                                    method="post" id="paymentwall-payment-form"
                                    class="w3-container w3-display-middle w3-card-4">
                                    <?php echo csrf_field(); ?>
                                    <div class="border p-3 mb-3 rounded payment-box">
                                        <div class="col-md-12 row">
                                            <div class="d-flex align-items-center">
                                                <div class="form-group w-100">
                                                    <label for="paymentwall_coupon"
                                                        class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                    <input type="text" id="paymentwall_coupon" name="coupon"
                                                        class="form-control coupon"
                                                        placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                </div>
                                                <div class="form-group ms-3 mt-4">
                                                    <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                        data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                            class="fas fa-save"></i></a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="hidden" name="plan_id"
                                                value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                            <button class="btn btn-primary" type="submit" id="pay_with_paymentwall">
                                                <?php echo e(__('Pay Now')); ?>

                                            </button>

                                        </div>
                                    </div>
                                </form>

                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paypal_enabled']) && $admin_payments_details['is_paypal_enabled'] == 'on'): ?>
                        <div id="stripe_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Paypal')); ?></h5>
                            </div>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_paypal_enabled'] == 'on' &&
                                    !empty($admin_payments_details['paypal_client_id']) &&
                                    !empty($admin_payments_details['paypal_secret_key'])): ?>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_paypal_enabled'] == 'on' && !empty($admin_payments_details['paypal_client_id']) && !empty($admin_payments_details['paypal_secret_key'])) == 'on' ? 'active' : ''); ?>"
                                    id="stripe_payment">
                                    <form role="form" action="<?php echo e(route('paypal.payment')); ?>" method="post"
                                        class="require-validation" id="payment-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 rounded stripe-payment-div">
                                            <div class="row">
                                                <div class="col-md-12 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="paypal_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="paypal_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip"
                                                                data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="error" style="display: none;">
                                                        <div class='alert-danger alert'>
                                                            <?php echo e(__('Please correct the errors and try again.')); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="hidden" name="plan_id"
                                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-xs btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(isset($admin_payments_details['is_flutterwave_enabled']) &&
                            $admin_payments_details['is_flutterwave_enabled'] == 'on'): ?>
                        <div id="flutterwave_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Flutterwave')); ?></h5>

                            </div>
                            <form role="form" action="<?php echo e(route('flutterwave.payment')); ?>" method="post"
                                class="require-validation" id="flaterwave-payment-form">
                                <?php echo csrf_field(); ?>

                                <div class="tab-pane " id="flutterwave_payment">
                                    <div class="border p-3 mb-3 rounded payment-box">
                                        <div class="form-group mb-1"><label
                                                for="flutterwave_coupon"><?php echo e(__('Coupon')); ?></label>
                                            </div>
                                        <div class="row align-items-center">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <input type="text" id="flutterwave_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-12 text-right stripe-coupon-tr" style="display: none">
                                                <b><?php echo e(__('Coupon Discount')); ?></b> : <b class="stripe-coupon-price"></b>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="hidden" name="plan_id"
                                                value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                            <input type="button" id="pay_with_flaterwave" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary">
                                        </div>
                                    </div>

                                </div>
                            </form>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paytm_enabled']) && $admin_payments_details['is_paytm_enabled'] == 'on'): ?>
                        <div id="paytm_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Paytm')); ?></h5>
                            </div>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_paytm_enabled'] == 'on' &&
                                    !empty($admin_payments_details['paytm_merchant_id']) &&
                                    !empty($admin_payments_details['paytm_industry_type']) &&
                                    !empty($admin_payments_details['paytm_merchant_key'])): ?>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_paytm_enabled'] == 'on' && !empty($admin_payments_details['paytm_merchant_id']) && !empty($admin_payments_details['paytm_merchant_key']) && !empty($admin_payments_details['paytm_industry_type'])) == 'on' ? 'active' : ''); ?>"
                                    id="stripe_payment">
                                    <form role="form" action="<?php echo e(route('plan.pay.with.paytm')); ?>"
                                        method="post" class="require-validation" id="payment-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded stripe-payment-div">
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label for="mobile_number" class="mb-1"><?php echo e(__('Mobile Number')); ?></label>
                                                        <input type="text" id="mobile_number" name="mobile_number"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Mobile Number')); ?>" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-12 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="paytm_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="paytm_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip"
                                                                data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="error" style="display: none;">
                                                        <div class='alert-danger alert'>
                                                            <?php echo e(__('Please correct the errors and try again.')); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="hidden" name="plan_id"
                                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-xs btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_mollie_enabled']) && $admin_payments_details['is_mollie_enabled'] == 'on'): ?>
                        <div id="mollie_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Mollie')); ?></h5>
                            </div>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_mollie_enabled'] == 'on' &&
                                    !empty($admin_payments_details['mollie_api_key']) &&
                                    !empty($admin_payments_details['mollie_profile_id']) &&
                                    !empty($admin_payments_details['mollie_partner_id'])): ?>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_mollie_enabled'] == 'on' && !empty($admin_payments_details['mollie_api_key']) && !empty($admin_payments_details['mollie_profile_id']) && !empty($admin_payments_details['mollie_partner_id'])) == 'on' ? 'active' : ''); ?>"
                                    id="mollie_payment">
                                    <form role="form" action="<?php echo e(route('plan.pay.with.mollie')); ?>"
                                        method="post" class="require-validation" id="payment-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded stripe-payment-div">

                                            <div class="row">

                                                <div class="col-md-12">
                                                    <div id="card-element">
                                                    </div>
                                                    <div id="card-errors" role="alert"></div>
                                                </div>
                                                <div class="col-md-12 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="mollie_coupon_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="mollie_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip"
                                                                data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="error" style="display: none;">
                                                        <div class='alert-danger alert'>
                                                            <?php echo e(__('Please correct the errors and try again.')); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="hidden" name="plan_id"
                                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-xs btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_coingate_enabled']) && $admin_payments_details['is_coingate_enabled'] == 'on'): ?>
                        <div id="coingate_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Coingate')); ?></h5>
                            </div>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_coingate_enabled'] == 'on' &&
                                    !empty($admin_payments_details['coingate_mode']) &&
                                    !empty($admin_payments_details['coingate_auth_token'])): ?>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_coingate_enabled'] == 'on' && !empty($admin_payments_details['coingate_mode']) && !empty($admin_payments_details['coingate_auth_token'])) == 'on' ? 'active' : ''); ?>"
                                    id="coingate_payment">
                                    <form role="form" action="<?php echo e(route('coingate.prepare.plan')); ?>"
                                        method="post" class="require-validation" id="payment-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded stripe-payment-div">

                                            <div class="row">

                                                <div class="col-md-12">
                                                    <div id="card-element">
                                                    </div>
                                                    <div id="card-errors" role="alert"></div>
                                                </div>
                                                <div class="col-md-12 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="coingate_coupon_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="coingate_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip"
                                                                data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="error" style="display: none;">
                                                        <div class='alert-danger alert'>
                                                            <?php echo e(__('Please correct the errors and try again.')); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="hidden" name="plan_id"
                                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-xs btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_sspay_enabled']) && $admin_payments_details['is_sspay_enabled'] == 'on'): ?>
                        <div id="sspay_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Sspay')); ?></h5>
                            </div>
                            <div id="sspay_payment" class="tab-pane">
                                <form role="form" action="<?php echo e(route('sspay.prepare.plan')); ?>" method="post"
                                    id="sspay-payment-form" class="w3-container w3-display-middle w3-card-4">
                                    <?php echo csrf_field(); ?>
                                    <div class="border p-3 mb-3 rounded payment-box">
                                        <div class="d-flex align-items-center">
                                            <div class="form-group w-100">
                                                <label for="sspay_coupan" class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                <input type="text" id="sspay_coupan" name="coupon"
                                                    class="form-control coupon"
                                                    placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                            </div>

                                            <div class="form-group ms-3 mt-4">
                                                <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                    data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                        class="fas fa-save"></i></a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="hidden" name="plan_id"
                                                value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                            <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                                class="form-control final-price">
                                            <button class="btn btn-xs btn-primary" type="submit" id="pay_with_sspay">
                                                <?php echo e(__('Pay Now')); ?>

                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_toyyibpay_enabled']) && $admin_payments_details['is_toyyibpay_enabled'] == 'on'): ?>
                        <div id="toyyibpay_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Toyyibpay')); ?></h5>
                            </div>
                            <div id="Toyyibpay_payment" class="tab-pane">
                                <form role="form" action="<?php echo e(route('toyyibpay.prepare.plan')); ?>"
                                    method="post" id="toyyibpay-payment-form"
                                    class="w3-container w3-display-middle w3-card-4">
                                    <?php echo csrf_field(); ?>
                                    <div class="border p-3 mb-3 rounded payment-box">
                                        <div class="d-flex align-items-center">
                                            <div class="form-group w-100">
                                                <label for="toyyibpay_coupan"
                                                    class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                <input type="text" id="toyyibpay_coupan" name="coupon"
                                                    class="form-control coupon"
                                                    placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                            </div>

                                            <div class="form-group ms-3 mt-4">
                                                <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                    data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                        class="fas fa-save"></i></a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="hidden" name="plan_id"
                                                value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                            <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                                class="form-control final-price">
                                            <button class="btn btn-xs btn-primary" type="submit"
                                                id="pay_with_toyyibpay">
                                                <?php echo e(__('Pay Now')); ?>

                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(isset($admin_payments_details['is_bank_transfer_enabled']) &&
                            $admin_payments_details['is_bank_transfer_enabled'] == 'on'): ?>
                        <div id="bank_transfer_payment" class="card">
                            <form class="" method="POST" action="<?php echo e(route('plan.pay.with.bank')); ?>"
                                enctype='multipart/form-data'>
                                <?php echo csrf_field(); ?>
                                <div class="card-header">
                                    <h5 class=" h6 mb-0"><?php echo e(__('Bank Transfer')); ?></h5>
                                </div>
                                <div class="card-body">
                                    <form>
                                        <div class="row">
                                            <div class="col-6">
                                                <label class="form-label"><b><?php echo e(__('Bank Details:')); ?></b></label>
                                                <div class="form-group">
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['bank_transfer']) && !empty($admin_payments_details['bank_transfer'])): ?>
                                                        <?php echo $admin_payments_details['bank_transfer']; ?>

                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label"> <?php echo e(__('Payment Receipt')); ?></label>
                                                <div class="form-group">
                                                    <input type="file" name="payment_receipt"
                                                        class="form-control mb-3" required>
                                                </div>
                                            </div>
                                        </div>
                                        <form>
                                            <div class="row mt-3">
                                                <div class="col-md-10">
                                                    <div class="form-group">
                                                        <label for="bank_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="bank_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-2 coupon-apply-btn mt-4">
                                                    <div class="form-group apply-bank-btn-coupon">
                                                        <a href="#"
                                                            class="btn btn-primary align-items-center apply-coupon"
                                                            data-from="bank"><?php echo e(__('Apply')); ?></a>
                                                    </div>
                                                </div>

                                                <div class="col-6 text-right">
                                                    <b><?php echo e(__('Plan Price')); ?></b> :
                                                    <?php echo e(isset($admin_payments_details['CURRENCY']) ? $admin_payments_details['CURRENCY'] : '$'); ?><?php echo e($plan->price); ?><b
                                                        class="bank-coupon-price"></b>
                                                </div>
                                                <div class="col-6 text-right ">
                                                    <b><?php echo e(__('Net Amount')); ?></b> :
                                                    <?php echo e(isset($admin_payments_details['CURRENCY']) ? $admin_payments_details['CURRENCY'] : '$'); ?><span
                                                        class="bank-final-price">
                                                        <?php echo e($plan->price); ?> </span></br>
                                                    <small>(After coupon apply)</small>
                                                </div>

                                                <div class="col-sm-12 mt-2 px-2">
                                                    <div class="text-end">
                                                        <input type="hidden" name="plan_id"
                                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                        <input type="hidden" name="total_price"
                                                            value="<?php echo e($plan->price); ?>"
                                                            class="form-control final-price">
                                                        <button class="btn btn-xs btn-primary" type="submit">
                                                            <?php echo e(__('Pay Now')); ?>

                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="error" style="display: none;">
                                                        <div class='alert-danger alert'>
                                                            <?php echo e(__('Please correct the errors and try again.')); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </form>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paytabs_enabled']) && $admin_payments_details['is_paytabs_enabled'] == 'on'): ?>
                        <div id="paytabs_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('paytabs')); ?></h5>
                            </div>
                            <div id="paytabs_payment" class="tab-pane">
                                <form role="form" action="<?php echo e(route('paytabs.prepare.plan')); ?>" method="post"
                                    id="paytabs-payment-form" class="w3-container w3-display-middle w3-card-4">
                                    <?php echo csrf_field(); ?>
                                    <div class="border p-3 mb-3 rounded payment-box">
                                        <div class="d-flex align-items-center">
                                            <div class="form-group w-100">
                                                <label for="paytabs_coupan"
                                                    class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                <input type="text" id="paytabs_coupan" name="coupon"
                                                    class="form-control coupon"
                                                    placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                            </div>

                                            <div class="form-group ms-3 mt-4">
                                                <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                    data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                        class="fas fa-save"></i></a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="hidden" name="plan_id"
                                                value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                            <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                                class="form-control final-price">
                                            <button class="btn btn-xs btn-primary" type="submit" id="pay_with_paytabs">
                                                <?php echo e(__('Pay Now')); ?>

                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                        isset($admin_payments_details['is_iyzipay_enabled']) == 'on' &&
                            !empty($admin_payments_details['iyzipay_private_key']) &&
                            !empty($admin_payments_details['iyzipay_secret_key'])): ?>
                        <div id="iyzipay_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('IyziPay')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_iyzipay_enabled'] != 'on' && $admin_payments_details['is_iyzipay_enabled'] == 'on' && !empty($admin_payments_details['iyzipay_private_key']) && !empty($admin_payments_details['iyzipay_secret_key'])) == 'on' ? 'active' : ''); ?>"
                                id="iyzipay_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST" id="payment-form"
                                    action="<?php echo e(route('iyzipay.payment.init')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="iyzipay_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="iyzipay_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>" class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_payfast_enabled']) && $admin_payments_details['is_payfast_enabled'] == 'on'): ?>
                        <div id="payfast_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Payfast')); ?></h5>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_payfast_enabled'] == 'on' &&
                                    !empty($admin_payments_details['payfast_merchant_id']) &&
                                    !empty($admin_payments_details['payfast_merchant_key']) &&
                                    !empty($admin_payments_details['payfast_salt_passphrase']) &&
                                    !empty($admin_payments_details['payfast_mode'])): ?>
                                <div
                                    class="tab-pane <?php echo e(($admin_payments_details['is_payfast_enabled'] == 'on' && !empty($admin_payments_details['payfast_merchant_id']) && !empty($admin_payments_details['payfast_merchant_key'])) == 'on' ? 'active' : ''); ?>">
                                    <?php
                                        $pfHost = $admin_payments_details['payfast_mode'] == 'sandbox' ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
                                    ?>
                                    <form role="form" action=<?php echo e('https://' . $pfHost . '/eng/process'); ?>

                                        method="post" class="require-validation" id="payfast-form">
                                        <?php echo csrf_field(); ?>
                                        <div class="border p-3 mb-3 rounded">
                                            <div class="row">
                                                <div class="col-md-12 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="payfast_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="payfast_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip"
                                                                data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="get-payfast-inputs"></div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="hidden" name="plan_id" id="plan_id"
                                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    id="payfast-get-status"
                                                    class="btn btn-xs btn-primary payfast_payment">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                        isset($admin_payments_details['is_benefit_enabled']) == 'on' &&
                            !empty($admin_payments_details['benefit_secret_key']) &&
                            !empty($admin_payments_details['benefit_private_key'])): ?>
                        <div id="benefit_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Benefit')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_benefit_enabled'] != 'on' && $admin_payments_details['is_benefit_enabled'] == 'on' && !empty($admin_payments_details['benefit_secret_key']) && !empty($admin_payments_details['benefit_private_key'])) == 'on' ? 'active' : ''); ?>"
                                id="benefit_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST" id="payment-form"
                                    action="<?php echo e(route('benefit.initiate')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="benefit_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="benefit_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>" class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                        isset($admin_payments_details['is_cashfree_enabled']) == 'on' &&
                            !empty($admin_payments_details['cashfree_secret_key']) &&
                            !empty($admin_payments_details['cashfree_key'])): ?>
                        <div id="cashfree_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Cashfree')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_cashfree_enabled'] != 'on' && $admin_payments_details['is_cashfree_enabled'] == 'on' && !empty($admin_payments_details['cashfree_secret_key']) && !empty($admin_payments_details['cashfree_key'])) == 'on' ? 'active' : ''); ?>"
                                id="cashfree_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST" id="payment-form"
                                    action="<?php echo e(route('cashfree.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="cashfree_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="cashfree_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>" class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                        isset($admin_payments_details['is_aamarpay_enabled']) == 'on' &&
                            !empty($admin_payments_details['aamarpay_signature_key']) &&
                            !empty($admin_payments_details['aamarpay_store_id']) &&
                            !empty($admin_payments_details['aamarpay_description'])): ?>
                        <div id="aamarpay_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Aamarpay')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_aamarpay_enabled'] != 'on' && $admin_payments_details['is_aamarpay_enabled'] == 'on' && !empty($admin_payments_details['aamarpay_signature_key']) && !empty($admin_payments_details['aamarpay_store_id']) && !empty($admin_payments_details['aamarpay_description'])) == 'on' ? 'active' : ''); ?>"
                                id="aamarpay_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST" id="payment-form"
                                    action="<?php echo e(route('pay.aamarpay.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="aamarpay_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="aamarpay_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                        isset($admin_payments_details['is_paytr_enabled']) == 'on' &&
                            !empty($admin_payments_details['paytr_merchant_id']) &&
                            !empty($admin_payments_details['paytr_salt_key']) &&
                            !empty($admin_payments_details['paytr_merchant_key'])): ?>
                        <div id="paytr_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Pay TR')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_paytr_enabled'] != 'on' && $admin_payments_details['is_paytr_enabled'] == 'on' && !empty($admin_payments_details['paytr_merchant_id']) && !empty($admin_payments_details['paytr_salt_key']) && !empty($admin_payments_details['paytr_merchant_key'])) == 'on' ? 'active' : ''); ?>"
                                id="paytr_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="paytr-payment-form" action="<?php echo e(route('plan.pay.with.paytr')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="paytr_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="paytr_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                        isset($admin_payments_details['is_yookassa_enabled']) == 'on' &&
                            !empty($admin_payments_details['yookassa_shop_id_key']) &&
                            !empty($admin_payments_details['yookassa_secret_key'])): ?>
                        <div id="yookassa_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Yookassa')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_yookassa_enabled'] != 'on' && $admin_payments_details['is_yookassa_enabled'] == 'on' && !empty($admin_payments_details['yookassa_shop_id_key']) && !empty($admin_payments_details['yookassa_secret_key'])) == 'on' ? 'active' : ''); ?>"
                                id="yookassa_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('pay.yookassa.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="yookassa_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="yookassa_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                        isset($admin_payments_details['is_Xendit_enabled']) == 'on' &&
                            !empty($admin_payments_details['Xendit_api_key']) &&
                            !empty($admin_payments_details['Xendit_token_key'])): ?>
                        <div id="Xendit_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Xendit')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_Xendit_enabled'] != 'on' && $admin_payments_details['is_Xendit_enabled'] == 'on' && !empty($admin_payments_details['Xendit_api_key']) && !empty($admin_payments_details['Xendit_token_key'])) == 'on' ? 'active' : ''); ?>"
                                id="Xendit_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="GET"
                                    id="payment-form" action="<?php echo e(route('pay.Xendit.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="Xendit_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="Xendit_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(isset($admin_payments_details['is_midtrans_enabled']) == 'on' && !empty($admin_payments_details['midtrans_secret_key'])): ?>
                        <div id="midtrans_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Midtrans')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_midtrans_enabled'] != 'on' && $admin_payments_details['is_midtrans_enabled'] == 'on' && !empty($admin_payments_details['midtrans_secret_key'])) == 'on' ? 'active' : ''); ?>"
                                id="midtrans_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('pay.midtrans.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="midtrans_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="midtrans_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(isset($admin_payments_details['is_nepalste_enabled']) == 'on' && !empty($admin_payments_details['nepalste_secret_key'])): ?>
                        <div id="nepalste_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Nepalste')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_nepalste_enabled'] != 'on' && $admin_payments_details['is_nepalste_enabled'] == 'on' && !empty($admin_payments_details['nepalste_secret_key'])) == 'on' ? 'active' : ''); ?>"
                                id="nepalste_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('pay.nepalste.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="nepalste_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="nepalste_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                        <?php if(isset($admin_payments_details['is_payhere_enabled']) == 'on' && !empty($admin_payments_details['payhere_merchant_secret'])): ?>
                            <div id="payhere_payment" class="card">
                                <div class="card-header">
                                    <h5><?php echo e(__('PayHere')); ?></h5>
                                </div>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_payhere_enabled'] != 'on' && $admin_payments_details['is_payhere_enabled'] == 'on' && !empty($admin_payments_details['payhere_merchant_secret'])) == 'on' ? 'active' : ''); ?>"
                                    id="payhere_payment">
                                    <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                        id="payment-form" action="<?php echo e(route('pay.payhere.payment')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">

                                        <div class="border p-3 mb-3 rounded">
                                            <div class="row">
                                                <div class="col-md-12 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="payhere_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="payhere_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                        <?php if(isset($admin_payments_details['is_khalti_enabled']) == 'on' && !empty($admin_payments_details['khalti_secret_key'])): ?>
                            <div id="khalti_payment" class="card">
                                <div class="card-header">
                                    <h5><?php echo e(__('Khalti')); ?></h5>
                                </div>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_khalti_enabled'] != 'on' && $admin_payments_details['is_khalti_enabled'] == 'on' && !empty($admin_payments_details['khalti_secret_key'])) == 'on' ? 'active' : ''); ?>"
                                    id="khalti_payment">
                                    <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                        id="payment-form" action="<?php echo e(route('pay.khalti.payment')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>" class="plan_id">

                                        <div class="border p-3 mb-3 rounded">
                                            <div class="row">
                                                <div class="col-md-12 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="khalti_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="khalti_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary payment_method payment-btn" id="khalti-payment">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                        <?php if(isset($admin_payments_details['is_authorizenet_enabled']) == 'on' && !empty($admin_payments_details['authorizenet_login_id'])): ?>
                            <div id="authorizenet_payment" class="card">
                                <div class="card-header">
                                    <h5><?php echo e(__('AuthorizeNet')); ?></h5>
                                </div>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_authorizenet_enabled'] != 'on' && $admin_payments_details['is_authorizenet_enabled'] == 'on' && !empty($admin_payments_details['authorizenet_login_id'])) == 'on' ? 'active' : ''); ?>"
                                    id="authorizenet_payment">
                                    <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                        id="payment-form" action="<?php echo e(route('pay.authorizenet.payment')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>" class="plan_id">

                                        <div class="border p-3 mb-3 rounded">
                                            <div class="row">
                                                <div class="col-md-12 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="authorizenet_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="authorizenet_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12">
                                            <div class="text-end">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary payment_method payment-btn" id="authorizenet-payment">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(isset($admin_payments_details['is_tap_enabled']) == 'on' && !empty($admin_payments_details['tap_secret_key'])): ?>
                        <div id="tap_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Tap')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_tap_enabled'] != 'on' && $admin_payments_details['is_tap_enabled'] == 'on' && !empty($admin_payments_details['tap_secret_key'])) == 'on' ? 'active' : ''); ?>"
                                id="tap_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('pay.tap.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>" class="plan_id">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="tap_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="tap_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary payment_method payment-btn" id="tap-payment">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(isset($admin_payments_details['is_phonepe_enabled']) == 'on' && !empty($admin_payments_details['phonepe_client_id'])): ?>
                        <div id="phonepe_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('PhonePe')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_phonepe_enabled'] == 'on' && !empty($admin_payments_details['phonepe_client_id'])) ? 'active' : ''); ?>"
                                id="phonepe_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('pay.phonepe.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>" class="plan_id">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="phonepe_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="phonepe_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary payment_method payment-btn" id="phonepe-payment">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(isset($admin_payments_details['is_paddle_enabled']) == 'on' && !empty($admin_payments_details['paddle_vendor_id'])): ?>
                        <div id="paddle_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Paddle')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_paddle_enabled'] != 'on' && $admin_payments_details['is_paddle_enabled'] == 'on' && !empty($admin_payments_details['paddle_vendor_id'])) == 'on' ? 'active' : ''); ?>"
                                id="paddle_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('pay.paddle.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>" class="plan_id">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="paddle_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="paddle_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary payment_method payment-btn" id="paddle-payment">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    


                    
                    <?php if(isset($admin_payments_details['is_paiementpro_enabled']) == 'on' && !empty($admin_payments_details['paiementpro_merchant_id'])): ?>
                        <div id="paiementpro_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Paiement Pro')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_paiementpro_enabled'] != 'on' && $admin_payments_details['is_paiementpro_enabled'] == 'on' && !empty($admin_payments_details['paiementpro_merchant_id'])) == 'on' ? 'active' : ''); ?>"
                                id="paiementpro_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('pay.paiementpro.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>" class="plan_id">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="paiementpro_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="paiementpro_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="form-group col-md-6" id="mobile_div">
                                                <?php echo e(Form::label('mobile_number',__('Mobile Number'),['class'=>'form-label'])); ?>

                                                <input type="text" name="mobile_number" class="form-control font-style mobile_number" id="mobile_number" required>
                                            </div>
                                            <div class="form-group col-md-6" id="channel_div">
                                                <?php echo e(Form::label('channel',__('Channel'),['class'=>'form-label'])); ?>

                                                <input type="text" name="channel" class="form-control font-style channel" id="channel" required>
                                                <small class="text-danger"><?php echo e(__('Example')); ?> : <?php echo e(__('OMCIV2,MOMO,CARD,FLOOZ ,PAYPAL')); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary payment_method payment-btn" id="paiementpro-payment">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(isset($admin_payments_details['is_fedpay_enabled']) == 'on' && !empty($admin_payments_details['fedpay_public_key'])): ?>
                        <div id="fedpay_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('FedPay')); ?></h5>
                            </div>
                            <div class="tab-pane <?php echo e(($admin_payments_details['is_fedpay_enabled'] != 'on' && $admin_payments_details['is_fedpay_enabled'] == 'on' && !empty($admin_payments_details['fedpay_public_key'])) == 'on' ? 'active' : ''); ?>"
                                id="fedpay_payment">
                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('pay.fedpay.payment')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>" class="plan_id">

                                    <div class="border p-3 mb-3 rounded">
                                        <div class="row">
                                            <div class="col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="fedpay_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="fedpay_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12">
                                        <div class="text-end">
                                            <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary payment_method payment-btn" id="fedpay-payment">
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_cinetpay_enabled']) && $admin_payments_details['is_cinetpay_enabled'] == 'on'): ?>
                        <div id="cinetpay_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('CinetPay')); ?></h5>

                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_cinetpay_enabled']) && $admin_payments_details['is_cinetpay_enabled'] == 'on'): ?>
                                <div class="tab-pane " id="cinetpay_payment">

                                    <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                        id="payment-form" action="<?php echo e(route('plan.pay.with.cinetpay')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded payment-box">
                                            <div class="col-md-12 mt-4 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="skrill_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="skrill_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12 my-2 px-2">
                                            <div class="text-end">
                                                <input type="submit" id="pay_with_skrill" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary">
                                            </div>
                                        </div>
                                    </form>

                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_Senangpay_enabled']) && $admin_payments_details['is_Senangpay_enabled'] == 'on'): ?>
                     <div id="Senangpay_payment" class="card">
                         <div class="card-header">
                             <h5><?php echo e(__('SenangPay')); ?></h5>

                         </div>
                         <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_Senangpay_enabled']) && $admin_payments_details['is_Senangpay_enabled'] == 'on'): ?>
                             <div class="tab-pane " id="Senangpay_payment">

                                 <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                     id="payment-form" action="<?php echo e(route('plan.pay.with.senagapay')); ?>">
                                     <?php echo csrf_field(); ?>
                                     <input type="hidden" name="plan_id"
                                         value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                     <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                         class="form-control final-price">
                                     <div class="border p-3 mb-3 rounded payment-box">
                                         <div class="col-md-12 mt-4 row">
                                             <div class="d-flex align-items-center">
                                                 <div class="form-group w-100">
                                                     <label for="skrill_coupon"
                                                         class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                     <input type="text" id="skrill_coupon" name="coupon"
                                                         class="form-control coupon"
                                                         placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                 </div>
                                                 <div class="form-group ms-3 mt-4">
                                                     <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                         data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                             class="fas fa-save"></i></a>
                                                 </div>
                                             </div>
                                         </div>
                                     </div>
                                     <div class="col-sm-12 my-2 px-2">
                                         <div class="text-end">
                                             <input type="submit" id="pay_with_skrill" value="<?php echo e(__('Pay Now')); ?>"
                                                 class="btn btn-primary">
                                         </div>
                                     </div>
                                 </form>

                             </div>
                         <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                     </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_cybersource_enabled']) && $admin_payments_details['is_cybersource_enabled'] == 'on'): ?>
                     <div id="cybersource_payment" class="card">
                         <div class="card-header">
                             <h5><?php echo e(__('CyberSource')); ?></h5>

                         </div>
                         <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_cybersource_enabled']) && $admin_payments_details['is_cybersource_enabled'] == 'on'): ?>
                             <div class="tab-pane " id="cybersource_payment">

                                 <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                     id="payment-form" action="<?php echo e(route('plan.pay.with.cybersource')); ?>">
                                     <?php echo csrf_field(); ?>
                                     <input type="hidden" name="plan_id"
                                         value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                     <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                         class="form-control final-price">
                                     <div class="border p-3 mb-3 rounded payment-box">
                                         <div class="col-md-12 mt-4 row">
                                             <div class="d-flex align-items-center">
                                                 <div class="form-group w-100">
                                                     <label for="skrill_coupon"
                                                         class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                     <input type="text" id="skrill_coupon" name="coupon"
                                                         class="form-control coupon"
                                                         placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                 </div>
                                                 <div class="form-group ms-3 mt-4">
                                                     <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                         data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                             class="fas fa-save"></i></a>
                                                 </div>
                                             </div>
                                         </div>
                                     </div>
                                     <div class="col-sm-12 my-2 px-2">
                                         <div class="text-end">
                                             <input type="submit" id="pay_with_skrill" value="<?php echo e(__('Pay Now')); ?>"
                                                 class="btn btn-primary">
                                         </div>
                                     </div>
                                 </form>

                             </div>
                         <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                     </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_ozow_enabled']) && $admin_payments_details['is_ozow_enabled'] == 'on'): ?>
                     <div id="ozow_payment" class="card">
                         <div class="card-header">
                             <h5><?php echo e(__('Ozow')); ?></h5>

                         </div>
                         <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_ozow_enabled']) && $admin_payments_details['is_ozow_enabled'] == 'on'): ?>
                             <div class="tab-pane " id="ozow_payment">

                                 <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                     id="payment-form" action="<?php echo e(route('plan.pay.with.ozow')); ?>">
                                     <?php echo csrf_field(); ?>
                                     <input type="hidden" name="plan_id"
                                         value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                     <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                         class="form-control final-price">
                                     <div class="border p-3 mb-3 rounded payment-box">
                                         <div class="col-md-12 mt-4 row">
                                             <div class="d-flex align-items-center">
                                                 <div class="form-group w-100">
                                                     <label for="skrill_coupon"
                                                         class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                     <input type="text" id="skrill_coupon" name="coupon"
                                                         class="form-control coupon"
                                                         placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                 </div>
                                                 <div class="form-group ms-3 mt-4">
                                                     <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                         data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                             class="fas fa-save"></i></a>
                                                 </div>
                                             </div>
                                         </div>
                                     </div>
                                     <div class="col-sm-12 my-2 px-2">
                                         <div class="text-end">
                                             <input type="submit" id="pay_with_skrill" value="<?php echo e(__('Pay Now')); ?>"
                                                 class="btn btn-primary">
                                         </div>
                                     </div>
                                 </form>

                             </div>
                         <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                     </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    
                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_myfatoorah_enabled']) && $admin_payments_details['is_myfatoorah_enabled'] == 'on'): ?>
                    <div id="myfatoorah_payment" class="card">
                        <div class="card-header">
                            <h5><?php echo e(__('MyFatoorah')); ?></h5>

                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_myfatoorah_enabled']) && $admin_payments_details['is_myfatoorah_enabled'] == 'on'): ?>
                            <div class="tab-pane " id="myfatoorah_payment">

                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('plan.pay.with.myfatoorah')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                    <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                        class="form-control final-price">
                                    <div class="border p-3 mb-3 rounded payment-box">
                                        <div class="col-md-12 mt-4 row">
                                            <div class="d-flex align-items-center">
                                                <div class="form-group w-100">
                                                    <label for="skrill_coupon"
                                                        class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                    <input type="text" id="skrill_coupon" name="coupon"
                                                        class="form-control coupon"
                                                        placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                </div>
                                                <div class="form-group ms-3 mt-4">
                                                    <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                        data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                            class="fas fa-save"></i></a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12 my-2 px-2">
                                        <div class="text-end">
                                            <input type="submit" id="pay_with_skrill" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>

                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                
                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_easebuzz_enabled']) && $admin_payments_details['is_easebuzz_enabled'] == 'on'): ?>
                        <div id="easebuzz_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Easebuzz')); ?></h5>

                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_easebuzz_enabled']) && $admin_payments_details['is_easebuzz_enabled'] == 'on'): ?>
                                <div class="tab-pane " id="easebuzz_payment">
                                    <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                        id="payment-form" action="<?php echo e(route('plan.pay.with.easebuzz')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded payment-box">
                                            <div class="col-md-12 mt-4 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="easebuzz_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="easebuzz_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12 my-2 px-2">
                                            <div class="text-end">
                                                <input type="submit" id="pay_with_easebuzz" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary">
                                            </div>
                                        </div>
                                    </form>

                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_nmi_enabled']) && $admin_payments_details['is_nmi_enabled'] == 'on'): ?>
                    <div id="nmi_payment" class="card">
                        <div class="card-header">
                            <h5><?php echo e(__('NMI')); ?></h5>

                        </div>
                        <div class="tab-pane" id="nmi_payment">
                            <form class="w3-container w3-display-middle w3-card-4" method="POST" id="payment-form" action="<?php echo e(route('plan.pay.with.nmi.view', [$plan->id])); ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="plan_id" value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>" class="form-control final-price">
                                <div class="border p-3 mb-3 rounded payment-box">
                                    <div class="col-md-12 mt-4 row">
                                        <div class="d-flex align-items-center">
                                            <div class="form-group w-100">
                                                <label for="nmi_coupon" class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                <input type="text" id="nmi_coupon" name="coupon" class="form-control coupon" placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                            </div>
                                            <div class="form-group ms-3 mt-4">
                                                <a href="javascript:void(0)" class="text-muted apply-coupon" data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>">
                                                    <i class="fas fa-save"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12 my-2 px-2">
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-primary" data-ajax-popup="true" data-size="md" data-url="<?php echo e(route('plan.pay.with.nmi.view', [$plan->id])); ?>" data-toggle="tooltip" title="<?php echo e(__('Pay Now')); ?>">
                                            <?php echo e(__('Pay Now')); ?>

                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>

                    </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_payu_enabled']) && $admin_payments_details['is_payu_enabled'] == 'on'): ?>
                        <div id="payu_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('PayU')); ?></h5>
                            </div>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_payu_enabled'] == 'on' &&
                                    !empty($admin_payments_details['payu_merchant_key']) &&
                                    !empty($admin_payments_details['payu_salt_key'])): ?>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_payu_enabled'] == 'on' && !empty($admin_payments_details['payu_merchant_key']) && !empty($admin_payments_details['paytm_merchant_key']) && !empty($admin_payments_details['paytm_industry_type'])) == 'on' ? 'active' : ''); ?>"
                                    id="stripe_payment">
                                    <form role="form" action="<?php echo e(route('plan.pay.with.payu')); ?>"
                                        method="post" class="require-validation" id="payment-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 rounded stripe-payment-div">
                                            <div class="row">
                                                <div class="col-md-12 mt-4 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="payu_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="payu_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip"
                                                                data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="error" style="display: none;">
                                                        <div class='alert-danger alert'>
                                                            <?php echo e(__('Please correct the errors and try again.')); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12 my-2 px-2">
                                            <div class="text-end">
                                                <input type="hidden" name="plan_id"
                                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-xs btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    
                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paynow_enabled']) && $admin_payments_details['is_paynow_enabled'] == 'on'): ?>
                        <div id="paynow_payment" class="card">
                        <div class="card-header">
                            <h5><?php echo e(__('Paynow')); ?></h5>

                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paynow_enabled']) && $admin_payments_details['is_paynow_enabled'] == 'on'): ?>
                            <div class="tab-pane" id="paynow_payment">

                                <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                    id="payment-form" action="<?php echo e(route('plan.pay.with.Paynow')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="plan_id"
                                        value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                    <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                        class="form-control final-price">
                                    <div class="border p-3 mb-3 rounded payment-box">
                                        <div class="col-md-12 mt-4 row">
                                            <div class="d-flex align-items-center">
                                                <div class="form-group w-100">
                                                    <label for="skrill_coupon"
                                                        class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                    <input type="text" id="skrill_coupon" name="coupon"
                                                        class="form-control coupon"
                                                        placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                </div>
                                                <div class="form-group ms-3 mt-4">
                                                    <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                        data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                            class="fas fa-save"></i></a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-12 my-2 px-2">
                                        <div class="text-end">
                                            <input type="submit" id="pay_with_skrill" value="<?php echo e(__('Pay Now')); ?>"
                                                class="btn btn-primary">
                                        </div>
                                    </div>
                                </form>

                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    


                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_sofort_enabled']) && $admin_payments_details['is_sofort_enabled'] == 'on'): ?>
                        <div id="sofort_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('Sofort')); ?></h5>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_sofort_enabled'] == 'on' &&
                                    !empty($admin_payments_details['sofort_publishable_key']) &&
                                    !empty($admin_payments_details['sofort_secret_key'])): ?>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_sofort_enabled'] == 'on' && !empty($admin_payments_details['sofort_publishable_key']) && !empty($admin_payments_details['sofort_secret'])) == 'on' ? 'active' : ''); ?>"
                                    id="sofort_payment">
                                    <form role="form" action="<?php echo e(route('sofort.payment')); ?>" method="post"
                                        class="require-validation" id="payment-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 rounded sofort-payment-div">
                                            <div class="row">
                                                <div class="col-sm-8">
                                                    <div class="custom-radio">
                                                        <label
                                                            class="font-16 font-weight-bold"><?php echo e(__('Credit / Debit Card')); ?></label>
                                                    </div>
                                                    <p class="mb-0 pt-1 text-sm">
                                                        <?php echo e(__('Safe money transfer using your bank account. We support Mastercard, Visa, Discover and American express.')); ?>

                                                    </p>
                                                </div>

                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label for="card-name-on"
                                                            class="form-label text-dark"><?php echo e(__('Name on card')); ?></label>
                                                        <input type="text" name="name" id="card-name-on"
                                                            class="form-control required"
                                                            placeholder="<?php echo e(\Auth::user()->name); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-12">
                                                    <div id="card-element">
                                                        <!-- A sofort Element will be inserted here. -->
                                                    </div>
                                                    <div id="card-errors" role="alert"></div>
                                                </div>
                                                <div class="col-md-12 mt-4 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="paypal_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="sofort_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="error" style="display: none;">
                                                        <div class='alert-danger alert'>
                                                            <?php echo e(__('Please correct the errors and try again.')); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12 my-2 px-2">
                                            <div class="text-end">
                                                <input type="hidden" name="plan_id"
                                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-xs btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_esewa_enabled']) && $admin_payments_details['is_esewa_enabled'] == 'on'): ?>
                        <div id="esewa_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('ESewa')); ?></h5>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
                                $admin_payments_details['is_esewa_enabled'] == 'on' &&
                                    !empty($admin_payments_details['esewa_merchant_key']) &&
                                    !empty($admin_payments_details['esewa_mode'])): ?>
                                <div class="tab-pane <?php echo e(($admin_payments_details['is_esewa_enabled'] == 'on' && !empty($admin_payments_details['esewa_merchant_key']) && !empty($admin_payments_details['esewa_mode'])) == 'on' ? 'active' : ''); ?>"
                                    id="esewa_payment">
                                    <form role="form" action="<?php echo e(route('esewa.payment')); ?>" method="post"
                                        class="require-validation" id="payment-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 rounded esewa-payment-div">
                                            <div class="row">
                                                <div class="col-md-12 mt-4 row">
                                                    <div class="d-flex align-items-center">
                                                        <div class="form-group w-100">
                                                            <label for="paypal_coupon"
                                                                class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                            <input type="text" id="esewa_coupon" name="coupon"
                                                                class="form-control coupon"
                                                                placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                        </div>
                                                        <div class="form-group ms-3 mt-4">
                                                            <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                                data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                    class="fas fa-save"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="error" style="display: none;">
                                                        <div class='alert-danger alert'>
                                                            <?php echo e(__('Please correct the errors and try again.')); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12 my-2 px-2">
                                            <div class="text-end">
                                                <input type="hidden" name="plan_id"
                                                    value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                                <input type="submit" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-xs btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_dpopay_enabled']) && $admin_payments_details['is_dpopay_enabled'] == 'on'): ?>
                        <div id="dpopay_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('DPO Pay')); ?></h5>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_dpopay_enabled']) && $admin_payments_details['is_dpopay_enabled'] == 'on'): ?>
                                <div class="tab-pane " id="dpopay_payment">
                                    <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                        id="payment-form" action="<?php echo e(route('plan.dpo.view')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action"
                                            value="<?php echo e(route('plan.pay.with.dpo')); ?>">
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="p-3 mb-3 border rounded payment-box">
                                            <div class="mt-4 col-md-12 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="dpo_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="dpo_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="mt-4 form-group ms-3">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="px-2 my-2 col-sm-12">
                                            <div class="text-end">
                                                <input type="submit" id="pay_with_dpo"
                                                    value="<?php echo e(__('Pay Now')); ?>" class="btn btn-primary">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    

                
                   <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_braintree_enabled']) && $admin_payments_details['is_braintree_enabled'] == 'on'): ?>
                   <div id="braintree_payment" class="card">
                       <div class="card-header">
                           <h5><?php echo e(__('Braintree')); ?></h5>

                       </div>
                       <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_braintree_enabled']) && $admin_payments_details['is_braintree_enabled'] == 'on'): ?>
                           <div class="tab-pane " id="braintree_payment">

                               <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                   id="payment-form" action="<?php echo e(route('plan.pay.with.braintree')); ?>">
                                   <?php echo csrf_field(); ?>
                                   <input type="hidden" name="plan_id"
                                       value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                   <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                       class="form-control final-price">
                                   <div class="border p-3 mb-3 rounded payment-box">
                                       <div class="col-md-12 mt-4 row">
                                           <div class="d-flex align-items-center">
                                               <div class="form-group w-100">
                                                   <label for="skrill_coupon"
                                                       class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                   <input type="text" id="skrill_coupon" name="coupon"
                                                       class="form-control coupon"
                                                       placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                               </div>
                                               <div class="form-group ms-3 mt-4">
                                                   <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                       data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                           class="fas fa-save"></i></a>
                                               </div>
                                           </div>
                                       </div>
                                   </div>
                                   <div class="col-sm-12 my-2 px-2">
                                       <div class="text-end">
                                           <input type="submit" id="pay_with_skrill" value="<?php echo e(__('Pay Now')); ?>"
                                               class="btn btn-primary">
                                       </div>
                                   </div>
                               </form>

                           </div>
                       <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                   </div>
                  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                  

                  	
                      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_powertranz_enabled']) && $admin_payments_details['is_powertranz_enabled'] == 'on'): ?>
                      <div id="powertranz_payment" class="card">
                          <div class="card-header">
                              <h5><?php echo e(__('PowerTranz')); ?></h5>

                          </div>
                          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_powertranz_enabled']) && $admin_payments_details['is_powertranz_enabled'] == 'on'): ?>
                              <div class="tab-pane " id="powertranz_payment">

                                  <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                      id="payment-form" action="<?php echo e(route('plan.Powertranz.view')); ?>">
                                      <?php echo csrf_field(); ?>
                                      <input type="hidden" name="plan_id"
                                          value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                      <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                          class="form-control final-price">
                                      <div class="border p-3 mb-3 rounded payment-box">
                                          <div class="col-md-12 mt-4 row">

                                              <div class="d-flex align-items-center">
                                                  <div class="form-group w-100">
                                                      <label for="powertranz_coupon"
                                                          class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                      <input type="text" id="powertranz_coupon" name="coupon"
                                                          class="form-control coupon"
                                                          placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                  </div>
                                                  <div class="form-group ms-3 mt-4">
                                                      <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                          data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                              class="fas fa-save"></i></a>
                                                  </div>
                                              </div>
                                          </div>
                                      </div>
                                      <div class="col-sm-12 my-2 px-2">
                                          <div class="text-end">
                                              <input type="submit" id="pay_with_powertranz" value="<?php echo e(__('Pay Now')); ?>"
                                                  class="btn btn-primary">
                                               </a>
                                          </div>
                                      </div>
                                  </form>

                              </div>
                          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                      </div>
                     <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                     

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_sslcommerz_enabled']) && $admin_payments_details['is_sslcommerz_enabled'] == 'on'): ?>
                        <div id="sslcommerz_payment" class="card">
                            <div class="card-header">
                                <h5><?php echo e(__('SSLCommerz')); ?></h5></div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_sslcommerz_enabled']) && $admin_payments_details['is_sslcommerz_enabled'] == 'on'): ?>
                                <div class="tab-pane " id="sslcommerz_payment">
                                    <form class="w3-container w3-display-middle w3-card-4" method="POST"
                                        id="payment-form" action="<?php echo e(route('plan.sslcommerz.view')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="plan_id"
                                            value="<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>">
                                        <input type="hidden" name="total_price" value="<?php echo e($plan->price); ?>"
                                            class="form-control final-price">
                                        <div class="border p-3 mb-3 rounded payment-box">
                                            <div class="col-md-12 mt-4 row">
                                                <div class="d-flex align-items-center">
                                                    <div class="form-group w-100">
                                                        <label for="sslcommerz_coupon"
                                                            class="form-label"><?php echo e(__('Coupon')); ?></label>
                                                        <input type="text" id="sslcommerz_coupon" name="coupon"
                                                            class="form-control coupon"
                                                            placeholder="<?php echo e(__('Enter Coupon Code')); ?>">
                                                    </div>
                                                    <div class="form-group ms-3 mt-4">
                                                        <a href="javascript:void(0)" class="text-muted apply-coupon"
                                                            data-toggle="tooltip" data-title="<?php echo e(__('Apply')); ?>"><i
                                                                class="fas fa-save"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-12 my-2 px-2">
                                            <div class="text-end">
                                                <input type="submit" id="pay_with_powertranz" value="<?php echo e(__('Pay Now')); ?>"
                                                    class="btn btn-primary">
                                                 </a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    
                </div>
            </div>
        </div>
    </div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('custom-script'); ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_nmi_enabled']) && $admin_payments_details['is_nmi_enabled'] == 'on'): ?>
        <script src="https://secure.networkmerchants.com/token/Collect.js"></script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_stripe_enabled']) && $admin_payments_details['is_stripe_enabled'] == 'on'): ?>
        <script src="https://js.stripe.com/v3/"></script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_paystack_enabled']) && $admin_payments_details['is_paystack_enabled'] == 'on'): ?>
        <script src="https://js.paystack.co/v1/inline.js"></script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(isset($admin_payments_details['is_flutterwave_enabled']) && $admin_payments_details['is_flutterwave_enabled'] == 'on'): ?>
        <script src="https://api.ravepay.co/flwv3-pug/getpaidx/api/flwpbf-inline.js"></script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_razorpay_enabled']) && $admin_payments_details['is_razorpay_enabled'] == 'on'): ?>
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_khalti_enabled']) && $admin_payments_details['is_khalti_enabled'] == 'on'): ?>
        <script src="https://khalti.s3.ap-south-1.amazonaws.com/KPG/dist/2020.12.17.0.0.0/khalti-checkout.iffe.js"></script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <script src="http://ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js"></script>
    <script src="<?php echo e(asset('js/jquery.form.js')); ?>"></script>
    <script>
        $(document).on('click', '.list-group-item', function() {
            $('.list-group-item').removeClass('active');
            $('.list-group-item').removeClass('text-primary');
            setTimeout(() => {
                $(this).addClass('active').removeClass('text-primary');
            }, 10);
        });
        var type = window.location.hash.substr(1);
        $('.list-group-item').removeClass('active');
        $('.list-group-item').removeClass('text-primary');
        if (type != '') {
            $('a[href="#' + type + '"]').addClass('active').removeClass('text-primary');
        } else {
            $('.list-group-item:eq(0)').addClass('active').removeClass('text-primary');
        }
        var scrollSpy = new bootstrap.ScrollSpy(document.body, {
            target: '#useradd-sidenav',
            offset: 300
        })
        document.addEventListener('DOMContentLoaded', function() {
            // Select all sidebar links
            const links = document.querySelectorAll('#useradd-sidenav .list-group-item');
            // Add scroll event listener
            window.addEventListener('scroll', function() {
                // Define an offset to ensure the active class is applied before the section is fully reached
                const offset = 200; // Adjust this value if necessary
                links.forEach(link => {
                    // Get the target section's ID from the link's href attribute
                    const sectionId = link.getAttribute('href').substring(1);
                    const section = document.getElementById(sectionId);
                    if (section) {
                        // Get the position of the section
                        const sectionTop = section.offsetTop - offset;
                        const sectionBottom = sectionTop + section.offsetHeight;
                        // Check if the scroll position is within the section boundaries
                        if (window.scrollY >= sectionTop && window.scrollY < sectionBottom) {
                            // Add active class to the link using your existing CSS classes
                            link.classList.add('active'); // or any other classes you've defined for active state
                        } else {
                            // Remove active class from the link
                            link.classList.remove('active');
                        }
                    }
                });
            });
        });

    </script>

    <script type="text/javascript">
        <?php if(
            $plan->price > 0.0 &&
                isset($admin_payments_details['is_stripe_enabled']) &&
                $admin_payments_details['is_stripe_enabled'] == 'on'): ?>

            var stripe = Stripe('<?php echo $admin_payments_details['stripe_publishable_key']; ?>',{locale: '<?php echo app()->getLocale();?>'});

            var elements = stripe.elements();

            // Custom styling can be passed to options when creating an Element.
            var style = {
                base: {
                    // Add your base input styles here. For example:
                    fontSize: '14px',
                    color: '#32325d',
                },
            };

            // Create an instance of the card Element.
            var card = elements.create('card', {
                style: style
            });
            // Add an instance of the card Element into the `card-element` <div>.
            card.mount('#card-element');

            // Create a token or display an error when the form is submitted.
            var form = document.getElementById('payment-form');
            form.addEventListener('submit', function(event) {
                event.preventDefault();

                stripe.createToken(card).then(function(result) {
                    if (result.error) {
                        $("#card-errors").html(result.error.message);
                        show_toastr('Error', result.error.message, 'error');
                    } else {
                        // Send the token to your server.
                        stripeTokenHandler(result.token);
                    }
                });
            });

            function stripeTokenHandler(token) {
                // Insert the token ID into the form so it gets submitted to the server
                var form = document.getElementById('payment-form');
                var hiddenInput = document.createElement('input');
                hiddenInput.setAttribute('type', 'hidden');
                hiddenInput.setAttribute('name', 'stripeToken');
                hiddenInput.setAttribute('value', token.id);
                form.appendChild(hiddenInput);

                // Submit the form
                form.submit();
            }
        <?php endif; ?>
        function preparePayment(ele, payment) {
            var coupon = $(ele).closest('.row').find('.coupon').val();
            var amount = 0;
            $.ajax({
                url: '<?php echo e(route('plan.prepare.amount')); ?>',
                datType: 'json',
                data: {
                    plan_id: '<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>',
                    coupon: coupon
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.is_success == true) {
                        amount = data.price;
                        $('#coupon_use_id').val(data.coupon_id);
                    } else {
                        show_toastr('Error', 'Paymenent request failed', 'error');
                    }

                }
            })
        }

        $(document).ready(function() {
            $(document).on('click', '.apply-coupon', function() {
                var ele = $(this);
                var coupon = ele.closest('.row').find('.coupon').val();
                $.ajax({
                    url: '<?php echo e(route('apply.coupon')); ?>',
                    datType: 'json',
                    data: {
                        plan_id: '<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>',
                        coupon: coupon
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        $('.final-price').val(data.price);
                        $('.bank-final-price').html('');
                        $('.bank-final-price').html(data.price);
                        $('#final_price_pay').val(data.price);
                        $('#stripe_coupon', '#mollie_coupon', 'paytm_coupon', '#paypal_coupon',
                            '#flutterwave_coupon', '#coingate_coupon').val(coupon);
                        if (data.is_success == true) {
                            show_toastr('Success', data.message, 'success');
                        } else if (data.is_success == false) {
                            show_toastr('Error', data.message, 'error');
                        } else {
                            show_toastr('Error', 'Coupon code is required', 'error');
                        }
                    }
                })
            });
        });
    </script>
    <script type="text/javascript">
        <?php if(isset($admin_payments_details['is_paystack_enabled']) && $admin_payments_details['is_paystack_enabled'] == 'on'): ?>

            $("#pay_with_paystack").click(function() {
                $(document).ready(function() {
                    $('#paystack-payment-form').ajaxForm(function(res) {
                        if (res.flag == 1) {
                            var paystack_callback = "<?php echo e(url('/plan-paystack-payment')); ?>";
                            var order_id = '<?php echo e(time()); ?>';
                            var coupon_id = res.coupon;
                            var handler = PaystackPop.setup({
                                key: '<?php echo e($admin_payments_details['paystack_public_key']); ?>',
                                email: res.email,
                                amount: res.total_price * 100,
                                currency: res.currency,
                                ref: 'pay_ref_id' + Math.floor((Math.random() *
                                        1000000000) +
                                    1
                                ), // generates a pseudo-unique reference. Please replace with a reference you generated. Or remove the line entirely so our API will generate one for you
                                metadata: {
                                    custom_fields: [{
                                        display_name: "Email",
                                        variable_name: "email",
                                        value: res.email,
                                    }]
                                },

                                callback: function(response) {
                                    window.location.href = paystack_callback + '/' +
                                        response
                                        .reference + '/' +
                                        '<?php echo e(encrypt($plan->id)); ?>' +
                                        '?coupon_id=' + coupon_id
                                },
                                onClose: function() {
                                    alert('window closed');
                                }
                            });
                            handler.openIframe();
                        } else if (res.flag == 2) {}
                    }).submit();
                });
            });
        <?php endif; ?>

        <?php if(isset($admin_payments_details['is_razorpay_enabled']) && $admin_payments_details['is_razorpay_enabled'] == 'on'): ?>
            // Razorpay Payment
            <?php
                $logo = asset(Storage::url('uploads/logo/'));
                $company_logo = \App\Models\Utility::GetValByName('logo_dark', $store_id ?? null);
                if(!empty($company_logo)) {
                    $company_logo = get_file($company_logo);
                } else {
                    $company_logo = $logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png');
                }
           ?>
            $(document).on("click", "#pay_with_razorpay", function() {
                $('#razorpay-payment-form').ajaxForm(function(res) {
                    if (res.flag == 1) {
                        var razorPay_callback = '<?php echo e(url('/plan-razorpay-payment')); ?>';
                        var totalAmount = res.total_price * 100;
                        var coupon_id = res.coupon;
                        var currency = res.currency;
                        var options = {
                            "key": "<?php echo e($admin_payments_details['razorpay_public_key']); ?>", // your Razorpay Key Id
                            "amount": totalAmount,
                            "name": 'Plan',
                            "currency": currency,
                            "description": "",
                            "image": "<?php echo e($company_logo); ?>",
                            "handler": function(response) {
                                window.location.href = razorPay_callback + '/' + response
                                    .razorpay_payment_id + '/' +
                                    '<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>?coupon_id=' +
                                    coupon_id;
                            },
                            "theme": {
                                "color": "#528FF0"
                            }
                        };
                        var rzp1 = new Razorpay(options);
                        rzp1.open();
                    } else if (res.flag == 2) {

                    } else {
                        show_toastr('error', 'This coupon code is invalid or has expired.');
                    }

                }).submit();
            });
        <?php endif; ?>


        <?php if(
            isset($admin_payments_details['is_payfast_enabled']) == 'on' &&
                    !empty($admin_payments_details['payfast_merchant_id']) &&
                    !empty($admin_payments_details['payfast_merchant_key'])): ?>
                $(document).ready(function() {
                    get_payfast_status(amount = 0, coupon = null);
                })

                function get_payfast_status(amount, coupon) {
                    var plan_id = $('#plan_id').val();

                    $.ajax({
                        url: '<?php echo e(route('payfast.payment')); ?>',
                        method: 'POST',
                        data: {
                            'plan_id': plan_id,
                            'coupon_amount': amount,
                            'coupon_code': coupon
                        },
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        success: function(data) {
                            $('#loader').fadeOut();
                            if (data.success == true) {
                                $('#get-payfast-inputs').append(data.inputs);

                            } else {
                                show_toastr('Error', data.inputs, 'error')
                            }
                        }
                    });
                }
            <?php endif; ?>

</script>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(
        !empty($admin_payments_details['is_flutterwave_enabled']) &&
            isset($admin_payments_details['is_flutterwave_enabled']) &&
            $admin_payments_details['is_flutterwave_enabled'] == 'on'): ?>
       
        <script>
            // is_flutterwave_enabled Payment

            $(document).on("click", "#pay_with_flaterwave", function() {
                $('#flaterwave-payment-form').ajaxForm(function(res) {
                    if (res.flag == 1) {
                        var coupon_id = res.coupon;

                        var API_publicKey = '<?php echo e($admin_payments_details['flutterwave_public_key']); ?>';
                        var nowTim = "<?php echo e(date('d-m-Y-h-i-a')); ?>";
                        var flutter_callback = "<?php echo e(url('/plan-flaterwave-payment')); ?>";
                        var x = getpaidSetup({
                            PBFPubKey: API_publicKey,
                            customer_email: '<?php echo e(Auth::user()->email); ?>',
                            amount: res.total_price,
                            currency: res.currency,
                            txref: nowTim + '__' + Math.floor((Math.random() * 1000000000)) +
                                'fluttpay_online-' +
                                <?php echo e(date('Y-m-d')); ?>,
                            meta: [{
                                metaname: "payment_id",
                                metavalue: "id"
                            }],
                            onclose: function() {},
                            callback: function(response) {
                                var txref = response.tx.txRef;
                                if (
                                    response.tx.chargeResponseCode == "00" ||
                                    response.tx.chargeResponseCode == "0"
                                ) {
                                    window.location.href = flutter_callback + '/' + txref +
                                        '/' +
                                        '<?php echo e(\Illuminate\Support\Facades\Crypt::encrypt($plan->id)); ?>?coupon_id=' +
                                        coupon_id + '&payment_frequency=' + res
                                        .payment_frequency;
                                } else {
                                    // redirect to a failure page.
                                }
                                x
                                    .close(); // use this to close the modal immediately after payment.
                            }
                        });
                    } else if (res.flag == 2) {

                    } else {
                        show_toastr('Error', data.message, 'msg');
                    }

                }).submit();
            });
        </script>

    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($admin_payments_details['is_khalti_enabled']) && $admin_payments_details['is_khalti_enabled'] == 'on'): ?>
    <script>
        var config = {
            "publicKey": "<?php echo e(isset($admin_payments_details['khalti_public_key']) ? $admin_payments_details['khalti_public_key'] : ''); ?>",
            "productIdentity": "1234567890",
            "productName": "demo",
            "productUrl": "<?php echo e(env('APP_URL')); ?>",
            "paymentPreference": [
                "KHALTI",
                "EBANKING",
                "MOBILE_BANKING",
                "CONNECT_IPS",
                "SCT",
            ],
            "eventHandler": {
                onSuccess (payload) {
                    if(payload.status==200) {
                        $.ajaxSetup({
                                headers: {
                                    'X-CSRF-Token': '<?php echo e(csrf_token()); ?>'
                                }
                            });
                        $.ajax({
                            url: '<?php echo e(route('plan.get.khalti.status')); ?>',
                            method: 'POST',
                            data : {
                                'payload' : payload,
                                'coupon_code' : $('#khalti_coupon').val(),
                                'plan_id' : $('.plan_id').val(),
                            },
                            beforeSend: function () {

                            },
                            success: function(data) {
                                $('#loader').fadeOut();
                                if(data.status_code === 200){
                                    show_toastr('Success','Plan activated Successfully.', 'success');
                                    setTimeout(() => {
                                        window.location.href = '<?php echo e(route("plan.index")); ?>';
                                    }, 2000);
                                }
                                else{
                                    show_toastr('Error','Payment Failed', 'error');
                                }
                            },
                            error: function(err) {
                                $('#loader').fadeOut();
                                show_toastr('Error', err.response, 'error')
                            },
                        });
                    }
                },
                onError (error) {
                    show_toastr('Error', error, 'error')
                },
                onClose () {
                }
            }

        };

        var checkout = new KhaltiCheckout(config);
        var btn = document.getElementsByClassName("payment-btn")[0];
    </script>

    <script>
          $(document).on("click", "#khalti-payment", function(event) {
            event.preventDefault()
            get_khalti_status();
        })

        function get_khalti_status(){
            var coupon_code = $('#khalti_coupon').val();
            var plan_id = $('.plan_id').val();
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });
            $.ajax({
                url: '<?php echo e(route('pay.khalti.payment')); ?>',
                method: 'POST',
                data : {
                    'coupon_code' : coupon_code,
                    'plan_id' : plan_id,
                },

                beforeSend: function () {

                },
                success: function (data) {
                    $('#loader').fadeOut();
                    if(data == 0)
                    {
                        show_toastr('Success','Plan activated Successfully.', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    }
                    else
                    {
                        let price = data*100;
                        checkout.show({amount: price});
                    }
                }
            });
        }
    </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php $__env->stopPush(); ?>



<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/plans/stripe.blade.php ENDPATH**/ ?>