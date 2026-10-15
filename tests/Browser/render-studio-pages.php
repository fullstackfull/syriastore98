<?php

/**
 * Render the Automation Studio and the Flow Builder to standalone HTML for browser verification.
 *
 *   php tests/Browser/render-studio-pages.php <output-directory>
 *   ln -s public/assets public/js  into that directory, serve it, then: node tests/Browser/p4c-flow-builder.mjs
 *
 * THE REAL VIEWS, THE REAL TRANSLATIONS AND THE REAL ROUTES, against a stand-in layout that loads
 * the dashboard's own Bootstrap and jQuery and nothing else. The dashboard chrome is left out
 * deliberately: it needs a signed-in user and a database, it is the same chrome Phase 2's inbox
 * was verified against, and what is under test here is the page these views ship.
 */
// Render the two Automation Studio views to standalone HTML, with the real translations and the
// real routes, against a stand-in layout that loads the dashboard's own Bootstrap and jQuery.
require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// The harness layout wins because its path is searched first.
$app['view']->getFinder()->prependLocation(__DIR__ . '/views');

$targets = [
    ['automation', 'lynomia-chat::merchant.automation', ['workspaceName' => 'Damascus Store']],
    ['builder', 'lynomia-chat::merchant.builder', ['workspaceName' => 'Damascus Store', 'flowId' => 42]],
    ['commerce', 'lynomia-chat::merchant.commerce-automation',
        ['workspaceName' => 'Damascus Store', 'canManageConsent' => true]],
    // The same page for a staff member who may build automations but may not record a consent
    // decision: the tab has to be absent, not merely disabled.
    ['commerce-noconsent', 'lynomia-chat::merchant.commerce-automation',
        ['workspaceName' => 'Damascus Store', 'canManageConsent' => false]],
    ['audiences', 'lynomia-chat::merchant.audiences', ['workspaceName' => 'Damascus Store']],
    ['templates', 'lynomia-chat::merchant.templates', ['workspaceName' => 'Damascus Store']],
];

/**
 * The field a shopper actually sees, wrapped the way a storefront wraps it.
 *
 * NOT RENDERED LIKE THE PAGES ABOVE, because it is not a page: it is pushed into a stack
 * inside the merchant's own checkout form, in whichever theme they run. Served bare it has no
 * <html> at all, so a browser check for `dir="rtl"` would be asking the fragment to do
 * something that is the theme's job -- and would fail for the right reason, which is how this
 * wrapper came to exist. The wrapper is the minimum a storefront provides: a document, a
 * direction, a viewport and the same Tailwind-ish utilities the checkout page carries.
 */
$storefront = static function (string $locale, string $fragment): string {
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';

    return <<<HTML
    <!doctype html>
    <html lang="{$locale}" dir="{$dir}">
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout</title>
    <style>body{margin:0;font-family:system-ui,sans-serif}
    .mt-4{margin-top:1rem}.bg-gray-50{background:#f9fafb}.border{border:1px solid #e5e7eb}
    .rounded-lg{border-radius:.5rem}.shadow-sm{box-shadow:0 1px 2px rgba(0,0,0,.05)}
    .p-4{padding:1rem}.flex{display:flex}.items-start{align-items:flex-start}.gap-3{gap:.75rem}
    .mt-1{margin-top:.25rem}.block{display:block}.text-xs{font-size:.75rem}
    .text-gray-500{color:#6b7280}.text-sm{font-size:.875rem}.cursor-pointer{cursor:pointer}</style>
    </head><body><form method="post" action="#"><main style="padding:1rem">{$fragment}</main></form></body></html>
    HTML;
};

$target = $argv[1] ?? sys_get_temp_dir() . '/lynomia-studio-pages';
if (! is_dir($target)) { mkdir($target, 0777, true); }

foreach (['en', 'ar'] as $locale) {
    $app->setLocale($locale);
    foreach ($targets as [$name, $view, $data]) {
        $html = $app['view']->make($view, $data)->render();
        $out = $target . "/{$name}-{$locale}.html";
        file_put_contents($out, $html);
        echo "wrote {$out} (" . strlen($html) . " bytes)\n";
    }

    $optin = $storefront($locale, $app['view']->make('lynomia-chat::storefront.marketing-optin', [
        'optinField' => \Workdo\LynomiaChat\app\Services\CheckoutConsentCapture::FIELD,
        'optinStoreName' => 'Damascus Store',
    ])->render());
    file_put_contents($target . "/checkout-optin-{$locale}.html", $optin);
    echo "wrote {$target}/checkout-optin-{$locale}.html (" . strlen($optin) . " bytes)\n";
}
