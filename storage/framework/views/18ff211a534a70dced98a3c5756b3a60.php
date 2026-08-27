<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['count', 'label', 'icon', 'columnClass' => 'col-xxl-3 col-sm-6 col-12', 'url' => '#']));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter((['count', 'label', 'icon', 'columnClass' => 'col-xxl-3 col-sm-6 col-12', 'url' => '#']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<div class="<?php echo e($columnClass); ?>">
    <div class="details-card">
        <div class="bg-img">
            <img src="<?php echo e(asset('images/details-card-bg.png')); ?>" alt="card-bg">
        </div>
        <div class="gap-3 d-flex align-items-center position-relative h-100">
            <a href="<?php echo e($url ?? route('order.index')); ?>" class="card-icon fs-4">
                 <?php echo $icon; ?>

             
            </a>
            <div class="card-content">
                <h2 class="h5 mb-0 f-w-500"><?php echo e($label ?? ''); ?></h2>
                <h3 class="h3 m-0"><?php echo e($count ?? 0); ?> </h3>
            </div>

        </div>
    </div>
</div><?php /**PATH /home/storesyria/public_html/resources/views/components/dashboard-card.blade.php ENDPATH**/ ?>