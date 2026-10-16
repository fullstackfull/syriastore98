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
    ['campaigns', 'lynomia-chat::merchant.campaigns', ['workspaceName' => 'Damascus Store']],
    // THE OPERATOR PAGE (Phase 6). The only non-merchant view here, and it is here because
    // Phase 6 put four of its five workstreams on it: installation lifecycle controls, the
    // far side's health, the consent-delivery panel and the merchant-notice history. Served
    // with fixture data rather than a database, like every other target -- what is under
    // test is the page these views ship, in both languages, not the queries behind it.
    ['superadmin-integration', 'lynomia-chat::superadmin.integration', superAdminFixture()],
];

/**
 * Everything SuperAdmin\IntegrationController::show passes, as plain values.
 *
 * Deliberately exercising the INTERESTING states rather than an empty installation: a
 * suspended link (so the resume, re-authenticate and decommission controls render), a
 * revoked one (restore), a pending claim (the approval form), expired consent decisions and
 * an indeterminate notice -- the three warning banners Phase 6 added only appear when there
 * is something to warn about.
 */
function superAdminFixture(): array
{
    $link = static function (int $id, int $storeId, string $state, string $mode = 'provisioned') {
        return (object) [
            'id' => $id, 'store_id' => $storeId, 'state' => $state, 'link_mode' => $mode,
            'lynomia_account_id' => 7000 + $id,
        ];
    };

    $links = new Illuminate\Pagination\LengthAwarePaginator(
        [
            $link(1, 11, 'active'),
            $link(2, 12, 'suspended'),
            $link(3, 13, 'revoked'),
            $link(4, 14, 'pending_verification', 'link_existing'),
            $link(5, 15, 'decommissioned'),
        ],
        5, 25, 1, ['path' => '/super-admin/lynomia-integration']
    );

    return [
        'integrationEnabled' => true,
        'integrationConfigured' => true,
        'links' => $links,
        'pendingCount' => 1,
        'planSeats' => collect([
            (object) ['id' => 1, 'name' => 'Starter', 'lynomia_agent_seats' => 2],
            (object) ['id' => 2, 'name' => 'Growth', 'lynomia_agent_seats' => 10],
        ]),
        'seatedStores' => collect([11 => 2, 12 => 5]),
        'eventHealth' => ['total' => 1420, 'last_at' => '3 minutes ago', 'stores_reporting' => 4],
        'commerceHealth' => ['enabled' => true, 'pending' => 6, 'stuck' => 2,
            'oldest' => '2 hours ago', 'stores' => [12 => 2]],
        'consentHealth' => ['pending' => 3, 'stuck' => 1, 'expired' => 2,
            'oldest' => '5 days ago', 'stores' => [12 => 1]],
        'platformComms' => [
            'configured' => true, 'workspace' => 9001, 'inbox' => 31,
            'sent' => 48, 'failed' => 3, 'indeterminate' => 1,
            'recent' => collect([
                (object) ['created_at' => now()->subMinutes(9), 'store_id' => 12,
                    'kind' => 'integration_recovery', 'status' => 'sent', 'failure_reason' => null],
                (object) ['created_at' => now()->subHours(2), 'store_id' => 14,
                    'kind' => 'onboarding', 'status' => 'failed', 'failure_reason' => 'placeholder_phone'],
                (object) ['created_at' => now()->subHours(5), 'store_id' => 13,
                    'kind' => 'security', 'status' => 'indeterminate', 'failure_reason' => 'kind_unavailable'],
            ]),
        ],
        'automationAccess' => collect([
            ['name' => 'lynomia automation manage', 'roles' => 2, 'platform_wide' => 1, 'stores' => 3],
            ['name' => 'lynomia consent manage', 'roles' => 1, 'platform_wide' => 0, 'stores' => 1],
        ]),
    ];
}

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
