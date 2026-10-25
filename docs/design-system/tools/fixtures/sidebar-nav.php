<?php
/**
 * Fixture for the sidebar-navigation preview.
 *
 * Shaped like what App\Classes\Menu::add() leaves behind for a merchant --
 * i.e. AFTER the module-entitlement and Laratrust permission gates. Names,
 * titles, icons and order values are copied from
 * app/Listeners/CompanyMenuListener.php so the preview shows real navigation
 * rather than invented labels.
 *
 * THE NAMES MATTER NOW, not just the titles. config/merchant-navigation.php
 * maps top-level `name` values to zones, so a fixture with invented names
 * (`order_main`, `product_main`) would drop every item into the 'other'
 * catch-all and the preview would show one undifferentiated list while
 * claiming to show zones. These are the real names.
 *
 * $state:
 *   full    a merchant with several zones and two levels of nesting
 *   empty   a principal whose gates left nothing (every item filtered out)
 *   noperm  one top-level item only, as a narrowly-scoped staff member sees it
 *   bgoff   the full menu with cust_theme_bg = 'off'
 *   flat    zones disabled -- the pre-P2-01-11 rendering, for the before shot
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

$__ssRepo = dirname(__DIR__, 4);

/* The real functions, read out of the shipped helper so this preview cannot
 * drift from them. The same approach the parity script uses.
 *
 * dirname(__DIR__, 4): this file is at docs/design-system/tools/fixtures/, so
 * four levels up is the repo root. The first version said 3, resolved to
 * docs/, found nothing, and left menuTree undefined so the nav rendered
 * silently EMPTY -- which looks like a design decision rather than a broken
 * fixture. Hence the loud exit below. */
$__ssHelper = file_get_contents($__ssRepo . '/app/Helper/helper.php');
if ($__ssHelper === false) {
    fwrite(STDERR, "FIXTURE: could not read app/Helper/helper.php\n");
    exit(3);
}

if (!function_exists('menuTree')) {
    if (preg_match('/\n    function menuTree\(\$menuItems, \$parent = null\)\n    \{.*?\n    \}\n/s', $__ssHelper, $m)) {
        eval('function menuTree($menuItems, $parent = null) ' . substr($m[0], strpos($m[0], '{')));
    }
}
if (!function_exists('menuZones')) {
    if (preg_match('/\n    function menuZones\(array \$nodes\)\n    \{.*?\n    \}\n/s', $__ssHelper, $m)) {
        eval('function menuZones(array $nodes) ' . substr($m[0], strpos($m[0], '{')));
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

/* config() stand-in, reading the REAL config file rather than a copy of its
 * values -- so adding a zone to config/merchant-navigation.php shows up here
 * without anyone remembering to update this fixture. */
if (!function_exists('config')) {
    function config($key, $default = null)
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require dirname(__DIR__, 4) . '/config/merchant-navigation.php';
        }
        $parts = explode('.', $key);
        if (array_shift($parts) !== 'merchant-navigation') {
            return $default;
        }
        $v = $cfg;
        foreach ($parts as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) return $default;
            $v = $v[$p];
        }
        return $v;
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
    // --- ungrouped: the landing place -----------------------------------
    ['title' => 'Dashboard',   'icon' => 'home',          'name' => 'admin_dashboard', 'parent' => null,              'order' => 1,   'route' => ''],
    ['title' => 'Dashboard',   'icon' => 'x',             'name' => 'dashboard',       'parent' => 'admin_dashboard', 'order' => 1,   'route' => 'dashboard'],

    // --- Sell -----------------------------------------------------------
    ['title' => 'Orders',      'icon' => 'shopping-cart', 'name' => 'orders',          'parent' => null,              'order' => 160, 'route' => ''],
    ['title' => 'Order',       'icon' => 'x',             'name' => 'order',           'parent' => 'orders',          'order' => 1,   'route' => 'order.index'],
    ['title' => 'Customers',   'icon' => 'users',         'name' => 'customers',       'parent' => null,              'order' => 180, 'route' => ''],
    ['title' => 'Customer',    'icon' => 'x',             'name' => 'customer',        'parent' => 'customers',       'order' => 1,   'route' => 'customer.index'],

    // --- Catalogue ------------------------------------------------------
    ['title' => 'Products',    'icon' => 'package',       'name' => 'products',        'parent' => null,              'order' => 120, 'route' => ''],
    ['title' => 'Product',     'icon' => 'x',             'name' => 'product',         'parent' => 'products',        'order' => 2,   'route' => 'product.index'],
    ['title' => 'Top Brand',   'icon' => 'x',             'name' => 'brand',           'parent' => 'products',        'order' => 1,   'route' => 'product-brand.index'],

    // --- Grow -----------------------------------------------------------
    ['title' => 'Reports',     'icon' => 'bar-chart-2',   'name' => 'reports',         'parent' => null,              'order' => 200, 'route' => ''],
    ['title' => 'Order Reports','icon' => 'x',             'name' => 'order_reports',   'parent' => 'reports',         'order' => 1,   'route' => 'order.index'],

    // --- Administration -------------------------------------------------
    ['title' => 'Staff',       'icon' => 'user-check',    'name' => 'staff',           'parent' => null,              'order' => 80,  'route' => ''],
    ['title' => 'User',        'icon' => 'x',             'name' => 'user',            'parent' => 'staff',           'order' => 1,   'route' => 'customer.index'],

    // --- unmapped: must land in the 'More' catch-all, never vanish ------
    ['title' => 'Support Ticket', 'icon' => 'life-buoy',  'name' => 'some_future_module', 'parent' => null,           'order' => 999, 'route' => ''],
    ['title' => 'Support Ticket','icon' => 'x',           'name' => 'tickets',         'parent' => 'some_future_module', 'order' => 1, 'route' => 'dashboard'],
];

$custThemeBg = 'on';
$zonesEnabled = (bool) config('merchant-navigation.zones_enabled', false);

if ($state === 'empty') {
    $registry = [];
} elseif ($state === 'noperm') {
    // Everything but the dashboard filtered out by the permission gate.
    $registry = array_slice($registry, 0, 2);
} elseif ($state === 'bgoff') {
    $custThemeBg = 'off';
} elseif ($state === 'flat') {
    // The BEFORE shot: exactly what shipped before P2-01-11.
    $zonesEnabled = false;
}

if (!function_exists('menuTree') || !function_exists('menuZones')) {
    fwrite(STDERR, "FIXTURE: menuTree()/menuZones() could not be extracted -- the preview would render an EMPTY nav, which looks like a design decision rather than a broken fixture.\n");
    exit(3);
}

$nodes = menuTree($registry, null);
$zones = $zonesEnabled ? menuZones($nodes) : [];

$GRANTS = ['Manage Dashboard' => true];

/* The workspace chip's data. THREE stores, because the multi-store branch is
 * the one with the listbox, the keyboard handling and the aria-selected state
 * -- i.e. everything worth photographing and checking. The single-store branch
 * is the harness default and is exercised by every other preview.
 *
 * Names are mixed Arabic and Latin on purpose: the chip isolates the name with
 * `unicode-bidi: isolate` because a store name is user data that may run the
 * other way from the page, and a preview with three English names would never
 * show whether that works. */
$SS_STORES = [
    new PreviewStore(1, 'Linen & Co'),
    new PreviewStore(2, 'متجر دمشق'),
    new PreviewStore(3, 'Aleppo Textiles'),
];
