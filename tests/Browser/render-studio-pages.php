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
];

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
}
