<?php
/**
 * Fixture for the Phase 02 component gallery.
 *
 * `$state` selects the form's validation state:
 *   full    a clean form
 *   empty   a clean form (the gallery has no empty variant of its own)
 *   noperm  a FAILED submit, so the validation summary renders and the
 *           browser checks can drive its focus behaviour
 */

/** @var string $state */
/** @var bool $isRtl */

$previewCustomer = $isRtl ? 'نور ح.' : 'Nour H.';
$previewDate     = $isRtl ? '١٢ تشرين الأول، ١٤:٢٢' : '12 Oct, 14:22';

$errors = $state === 'noperm'
    ? new ErrorBagStub([
        'name'  => [$isRtl ? 'الاسم مطلوب.' : 'The name field is required.'],
        'price' => [$isRtl ? 'يجب أن يكون السعر رقماً.' : 'The price must be a number.'],
      ])
    : new ErrorBagStub();

$GRANTS = ['Manage Order' => true, 'Manage Products' => true];
