<?php

/*
|--------------------------------------------------------------------------
| Merchant sidebar navigation zones (P2-01-11)
|--------------------------------------------------------------------------
|
| Groups the 21 top-level sidebar entries by the merchant's JOB rather than by
| module name, per docs/development/phase-2/design/P2-DESIGN-FOUNDATION.md §3d.
|
| THIS FILE GRANTS NOTHING. It only decides where an item is DRAWN. Whether an
| item exists at all is still decided upstream by App\Classes\Menu::add(), which
| applies the module-entitlement check and the Laratrust permission check before
| anything reaches menuTree(). Adding a name here cannot make a hidden item
| appear; removing one cannot hide an item the gates allowed -- it only moves it
| to the catch-all zone. Keep it that way: no permission name, no module name,
| and no store id belongs in this file.
|
| ORDERING. Zones are drawn in the 'order' below, and items keep their own
| 'order' WITHIN a zone, so the sequence a merchant already knows is preserved
| inside each group. The zone order is explicit rather than derived from the
| items' own order values: deriving it would put Storefront and Admin ahead of
| Sell, because `themecustomize` (21) and `staff` (80) sort before `orders`
| (160), and the daily job would end up below the settings.
|
| UNKNOWN NAMES GO TO 'other', NEVER MISSING. There are 70 WorkDo modules and
| each may register its own top-level entry; the map below covers the 21 that
| CompanyMenuListener registers. Anything else -- another module, a future
| entry, a typo in this file -- falls into the 'other' zone and still renders.
| An item must never disappear because a config file did not mention it.
|
| KILL SWITCH. Set 'zones_enabled' to false and generateMenu() emits exactly
| the flat list it emitted before this change, with no headings. That is also
| how tests/Support/Security/bin/verify-menu-render-parity.php keeps meaning
| something: it pins menuTree() and partision/menu-tree.blade.php, which this
| feature does not touch.
*/

return [

    'zones_enabled' => true,

    /*
     | Drawn above every zone, with no heading of its own. The dashboard is not
     | a category of work; it is where the merchant lands.
     */
    'ungrouped' => ['admin_dashboard'],

    'zones' => [
        'sell' => [
            'title' => 'Sell',
            'order' => 10,
            'items' => ['orders', 'pos', 'customers', 'finance'],
        ],
        'catalogue' => [
            'title' => 'Catalogue',
            'order' => 20,
            'items' => ['products', 'shipping', 'delivery_syria', 'deliveryboy'],
        ],
        'grow' => [
            'title' => 'Grow',
            'order' => 30,
            'items' => ['marketing', 'reports'],
        ],
        'storefront' => [
            'title' => 'Storefront',
            'order' => 40,
            'items' => ['themecustomize', 'cms', 'storesetting', 'mobilescreensetting'],
        ],
        'channels' => [
            'title' => 'Channels',
            'order' => 50,
            'items' => ['WooCommerce', 'shopify'],
        ],
        'administration' => [
            'title' => 'Administration',
            'order' => 60,
            'items' => ['staff', 'settings', 'plan', 'support_ticket.index'],
        ],
        /*
         | The catch-all. It carries no 'items' because membership is "everything
         | the map above did not claim", and it sorts last so a module's own
         | entry never pushes the merchant's daily work down the page.
         */
        'other' => [
            'title' => 'More',
            'order' => 900,
            'items' => [],
        ],
    ],
];
