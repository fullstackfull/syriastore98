#!/usr/bin/env php
<?php
/**
 * RENDER THE REAL CART STALE-LINE NOTICE, FOR THE CONTRAST MEASUREMENT TO LOAD.
 * =============================================================================
 *
 * THROUGH THE APPLICATION'S OWN VIEW FACTORY, deliberately. Hand-copied markup in a harness is
 * the commonest way a UI measurement stops measuring the thing it names: it keeps passing after
 * the real view changes. This boots the framework and renders
 * `front_end/partials/cart-unavailable-notice.blade.php` with the payload shape that
 * `ApiController::cart_list()` and `Cart::cart_list_cookie()` actually publish, so the fragment is
 * whatever the partial currently produces.
 *
 * Both locales, because the message is translated server-side and the Arabic string is what a
 * shopper on an RTL page reads.
 *
 * Writes `notice-en.html` and `notice-ar.html` into the directory given as argv[1].
 * Run by `measure-cart-notice-contrast.cjs`; it needs no database.
 */
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = $argv[1] ?? null;
if (! $out || ! is_dir($out)) {
    fwrite(STDERR, "usage: render-cart-notice-fragments.php <existing output directory>\n");
    exit(2);
}

/* The shape the two basket readers publish. One dropped line WITH a name, which is the variant
   case -- a deleted PRODUCT cannot be named at all (`carts` holds no name column), and the
   nameless wording is covered by the Feature test rather than here. */
$response = json_decode(json_encode([
    'data' => [
        'unavailable_products' => [
            ['cart_id' => 7, 'product_id' => 42, 'variant_id' => 3,
             'name' => 'Aleppo Laurel Soap', 'reason' => 'variant_unavailable'],
        ],
        'unavailable_message' => '',
    ],
]));

foreach (['en' => 'Aleppo Laurel Soap', 'ar' => 'صابون الغار الحلبي'] as $locale => $productName) {
    app()->setLocale($locale);

    $response->data->unavailable_message = __(
        'Some items are no longer available and have been removed from your cart: :products',
        ['products' => $productName]
    );

    $html = view('front_end.partials.cart-unavailable-notice', ['response' => $response])->render();

    if (! str_contains($html, 'ss-alert--warning')) {
        fwrite(STDERR, "FAIL premise: the partial rendered without the alert component ({$locale}).\n");
        exit(1);
    }

    file_put_contents($out . '/notice-' . $locale . '.html', $html);
    printf("  rendered %s  %d bytes\n", $locale, strlen($html));
}
