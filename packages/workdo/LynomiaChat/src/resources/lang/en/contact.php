<?php

/**
 * THE FOUR REASONS A MERCHANT CANNOT BE SENT AN OPERATIONAL NOTICE (B4).
 *
 * A NAMESPACED PHP GROUP, not flat JSON, for the reason LynomiaFlowBuilderTest records about
 * the Studio's four words: this module's JSON translations share a namespace with the host's
 * own ~2,800-key dictionary, and the host's wins. These keys are short machine-ish words that
 * a host dictionary is entirely likely to have its own opinion about; a namespaced group
 * cannot be overridden by flat JSON.
 *
 * The keys are the refusal strings MerchantContact::refusal returns, which are the same
 * strings already recorded on lynomia_platform_notices.failure_reason -- so an operator
 * reading the notices history and an operator reading this panel see one vocabulary.
 */
return [
    'no_phone_on_record' => 'No number on record',
    'placeholder_phone' => 'A placeholder number, not a real one',
    'phone_not_usable' => 'A number that cannot receive messages',
    'phone_not_verified' => 'A number nobody has confirmed',
];
