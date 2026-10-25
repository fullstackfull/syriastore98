<?php
/**
 * Fixture for the sidebar-navigation preview.
 *
 * Shaped like what App\Classes\Menu::add() leaves behind for a merchant --
 * i.e. AFTER the module-entitlement and Laratrust permission gates. Titles and
 * icons are copied from app/Listeners/CompanyMenuListener.php so the preview
 * shows real navigation rather than invented labels.
 *
 * $state:
 *   full    a merchant with several groups and two levels of nesting
 *   empty   a principal whose gates left nothing (every item filtered out)
 *   noperm  one top-level item only, as a narrowly-scoped staff member sees it
 *   bgoff   the full menu with cust_theme_bg = 'off'
 *
 * cust_theme_bg decides which of TWO navs partision/sidebar.blade.php:15
 * renders, and the difference is legibility, not decoration. It defaults to
 * 'on' (Utility.php:44, helper.php:1106 and 1181, DefaultSetting.php:32, and
 * app.blade.php:17 for an empty stored value), so 'on' is what a merchant
 * actually sees and what every state but `bgoff` uses. `bgoff` exists to keep
 * the measured 1.25:1 of the other branch on the record.
 */

/** @var string $state */
/** @var bool $isRtl */

if (!function_exists('menuTree')) {
    // The real function, read out of the shipped helper so this preview cannot
    // drift from it. The same approach the parity script uses.
    // dirname(__DIR__, 4): this file is at
    // docs/design-system/tools/fixtures/, so three levels up is docs/ -- which
    // is where the first version of this looked, found nothing, and left
    // menuTree undefined so the nav rendered silently EMPTY.
    $src = file_get_contents(dirname(__DIR__, 4) . '/app/Helper/helper.php');
    if ($src === false) {
        fwrite(STDERR, "FIXTURE: could not read app/Helper/helper.php\n");
    }
    if (preg_match('/\n    function menuTree\(\$menuItems, \$parent = null\)\n    \{.*?\n    \}\n/s', $src, $m)) {
        eval('function menuTree($menuItems, $parent = null) ' . substr($m[0], strpos($m[0], '{')));
    }
}
if (!function_exists('hasChildren')) {
    function hasChildren($menuItems, $name)
    {
        foreach ($menuItems as $item) {
            if ($item['parent'] === $name) return true;
        }
        return false;
    }
}

/* Titles are RAW here, and that is deliberate.
 *
 * In production the listeners pass ALREADY-TRANSLATED titles --
 * CompanyMenuListener.php:24 is `'title' => __('Dashboard')` -- and then
 * menuTree() (like the string builder before it) calls __() on that result
 * AGAIN. The second lookup misses and returns its input unchanged, so Arabic
 * still comes out right by accident. Passing raw keys here exercises the
 * translation once, which is what makes the Arabic preview show real Arabic
 * instead of a missing-key warning. The double translation is recorded in the
 * ledger as F7; it is pre-existing and a preview is not the place to fix it.
 */
$registry = [
    ['title' => 'Dashboard', 'icon' => 'home',          'name' => 'admin_dashboard', 'parent' => null,             'order' => 1, 'route' => ''],
    ['title' => 'Dashboard', 'icon' => 'home',          'name' => 'dashboard',       'parent' => 'admin_dashboard','order' => 1, 'route' => 'dashboard'],

    ['title' => 'Order',     'icon' => 'shopping-cart', 'name' => 'order_main',      'parent' => null,             'order' => 2, 'route' => ''],
    ['title' => 'Order',     'icon' => 'x',             'name' => 'order',           'parent' => 'order_main',     'order' => 1, 'route' => 'order.index'],

    ['title' => 'Product',   'icon' => 'package',       'name' => 'product_main',    'parent' => null,             'order' => 3, 'route' => ''],
    ['title' => 'Product',   'icon' => 'x',             'name' => 'product',         'parent' => 'product_main',   'order' => 2, 'route' => 'product.index'],
    ['title' => 'Top Brand', 'icon' => 'x',             'name' => 'brand',           'parent' => 'product_main',   'order' => 1, 'route' => 'product-brand.index'],

    ['title' => 'Customer',  'icon' => 'users',         'name' => 'customer_main',   'parent' => null,             'order' => 4, 'route' => ''],
    ['title' => 'Customer',  'icon' => 'x',             'name' => 'customer',        'parent' => 'customer_main',  'order' => 1, 'route' => 'customer.index'],
];

$custThemeBg = 'on';

if ($state === 'empty') {
    $registry = [];
} elseif ($state === 'noperm') {
    // Everything but the dashboard filtered out by the permission gate.
    $registry = array_slice($registry, 0, 2);
} elseif ($state === 'bgoff') {
    $custThemeBg = 'off';
}

if (!function_exists('menuTree')) {
    fwrite(STDERR, "FIXTURE: menuTree() could not be extracted -- the preview would render an EMPTY nav, which looks like a design decision rather than a broken fixture.\n");
    exit(3);
}
$nodes = menuTree($registry, null);

$GRANTS = ['Manage Dashboard' => true];
