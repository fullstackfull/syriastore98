<?php
    $adminSetting = getSuperAdminAllSetting();
    config([
        'captcha.secret' => $adminSetting['NOCAPTCHA_SECRET'] ?? '',
        'captcha.sitekey' => $adminSetting['NOCAPTCHA_SITEKEY'] ?? '',
        'options' => [
            'timeout' => 30,
        ],
    ]);
?>



<?php $__env->startSection('page-title'); ?>
    <?php echo e(__('Register')); ?>

<?php $__env->stopSection(); ?>



<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($adminSetting['cust_darklayout']) && $adminSetting['cust_darklayout'] == 'on'): ?>
    <style>
        .g-recaptcha {
            filter: invert(1) hue-rotate(180deg) !important;
        }
    </style>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<?php $__env->startSection('content'); ?>
    <div class="text-center">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?>
            <div class="mb-4 font-medium text-lg text-green-600 text-danger">
                <?php echo e(session('status') ?? __('Email SMTP settings does not configured so please contact to your site admin.')); ?>

            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <h2 class="mb-2 h3 f-w-600"><?php echo e(__('Create an account')); ?></h2>
        <div class="text-border-bottom"></div>
        <p><?php echo e(__('Enter your details below to create your account')); ?></p>
    </div>
    <div class="">
        <!-- Validation Errors -->
        <?php if (isset($component)) { $__componentOriginal0ff1ee8966084a5d418f848c5e125b44 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0ff1ee8966084a5d418f848c5e125b44 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.auth-validation-errors','data' => ['class' => 'mb-4','errors' => $errors]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('auth-validation-errors'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'mb-4','errors' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($errors)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0ff1ee8966084a5d418f848c5e125b44)): ?>
<?php $attributes = $__attributesOriginal0ff1ee8966084a5d418f848c5e125b44; ?>
<?php unset($__attributesOriginal0ff1ee8966084a5d418f848c5e125b44); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0ff1ee8966084a5d418f848c5e125b44)): ?>
<?php $component = $__componentOriginal0ff1ee8966084a5d418f848c5e125b44; ?>
<?php unset($__componentOriginal0ff1ee8966084a5d418f848c5e125b44); ?>
<?php endif; ?>
        <form method="POST" action="<?php echo e(route('register')); ?>">
            <?php echo csrf_field(); ?>
            <input name="plan_id" type="hidden" value="<?php echo e(request()->query('plan_id')); ?>">
            <div class="form-group mb-3">
                <label class="form-label" for="name"><?php echo e(__('Name')); ?></label>
                <input id="name" class="form-control" type="text" name="name" :value="old('name')" required
                placeholder="<?php echo e(__('Enter Name')); ?>" autofocus />
            </div>
            <div class="form-group mb-3">
                <label class="form-label" for="name"><?php echo e(__('Store name')); ?></label>
                <input id="name" class="form-control" type="text" name="store_name" :value="old('store_name')"
                placeholder="<?php echo e(__('Enter Store name')); ?>" required autofocus />
            </div>
            <div class="form-group mb-3">
                <label class="form-label" for="email"><?php echo e(__('Email')); ?></label>
                <input id="email" class="form-control" type="email" name="email" :value="old('email')" placeholder="<?php echo e(__('Enter Email')); ?>" required />
            </div>
            <div class="form-group mb-3">
                <label class="form-label" for="password"><?php echo e(__('Password')); ?></label>
                <input id="password" class="form-control" type="password" name="password" placeholder="<?php echo e(__('Enter Password')); ?>" required
                    autocomplete="new-password" />
            </div>
            <div class="form-group mb-3">
                <label class="form-label" for="password_confirmation"><?php echo e(__('Confirm Password')); ?></label>
                <input id="password_confirmation" class="form-control" type="password" name="password_confirmation"
                placeholder="<?php echo e(__('Enter Confirm Password')); ?>" required />
            </div>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($adminSetting['RECAPTCHA_MODULE']) && $adminSetting['RECAPTCHA_MODULE'] == 'yes'): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($adminSetting['NOCAPTCHA_VERSON']) && $adminSetting['NOCAPTCHA_VERSON'] == 'v2'): ?>
                    <div class="form-group col-lg-12 col-md-12 mt-3">
                        <?php echo NoCaptcha::display((isset($adminSetting['cust_darklayout']) && $adminSetting['cust_darklayout'] == 'on') ? ['data-theme' => 'dark'] : []); ?>

                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['g-recaptcha-response'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                            <span class="small text-danger" role="alert">
                                <strong><?php echo e($message); ?></strong>
                            </span>
                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="form-group col-lg-12 col-md-12 mt-3">
                        <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response" class="form-control">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['g-recaptcha-response'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                            <span class="error small text-danger" role="alert">
                                <strong><?php echo e($message); ?></strong>
                            </span>
                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <div class="d-grid">
                <button class="btn btn-primary btn-block mt-2" type="submit"> <?php echo e(__('Register')); ?> </button>
            </div>
        </form>
    </div>
    <p class="mt-4 mb-0 text-center">
       <?php echo e(__(' Already have an account?')); ?>

        <a href="<?php echo e(route('login')); ?>" class="f-w-400 text-primary"><?php echo e(__('Login')); ?></a>
    </p>
<?php $__env->stopSection(); ?>


<?php $__env->startPush('scripts'); ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($adminSetting['RECAPTCHA_MODULE']) && $adminSetting['RECAPTCHA_MODULE'] == 'yes'): ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($adminSetting['NOCAPTCHA_VERSON']) && $adminSetting['NOCAPTCHA_VERSON'] == 'v2'): ?>
            <?php echo NoCaptcha::renderJs(); ?>

    <?php else: ?>
        <script src="https://www.google.com/recaptcha/api.js?render=<?php echo e($adminSetting['NOCAPTCHA_SITEKEY']); ?>"></script>
        <script>
            $(document).ready(function() {
                grecaptcha.ready(function() {
                    grecaptcha.execute('<?php echo e($adminSetting['NOCAPTCHA_SITEKEY']); ?>', {
                        action: 'submit'
                    }).then(function(token) {
                        $('#g-recaptcha-response').val(token);
                    });
                });
            });
        </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.guest', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/auth/register.blade.php ENDPATH**/ ?>