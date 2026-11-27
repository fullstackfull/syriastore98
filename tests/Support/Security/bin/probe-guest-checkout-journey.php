<?php

/**
 * P04-P1-05 / `GAP-345` — THE GUEST CHECKOUT JOURNEY. **RESOLVED 2026-11-27; IT WAS THE HARNESS.**
 * =================================================================================================
 *
 * WHAT THIS FILE USED TO SAY, and it was honest about not knowing: *"the POST answers 302 to `/`
 * with the session error `Cart is empty.`, and no `orders` row is written [...] it is NOT
 * established whether that is the harness or the product."* Three fixture hypotheses about the
 * `cart` cookie had been tested and refuted.
 *
 * THERE WERE TWO CAUSES, BOTH HARNESS-SIDE, AND BOTH ARE NOW MEASURED:
 *
 *  1. **A FOURTH COOKIE HYPOTHESIS NOBODY HAD TESTED — `CookieValuePrefix`.**
 *     `Illuminate\Foundation\Testing\Concerns\MakesHttpRequests::prepareCookiesForRequest()`
 *     encrypts every `withCookie()` value itself, and prepends
 *     `CookieValuePrefix::create($key, $encrypter->getKey())` before doing so. `EncryptCookies`
 *     validates that prefix and DISCARDS a cookie without it. The old fixture passed
 *     `withCookie('cart', encrypt($value, false))` — doubly encrypted, unprefixed, thrown away by
 *     the middleware, and indistinguishable from an empty basket. `withCookie('cart', $plain)` is
 *     the correct call. (This also explains why hypothesis 3, "`serialise=false` changes the
 *     outcome", was right about Laravel's encrypter and irrelevant to the test client.)
 *
 *  2. **The storefront posts `billing_info[...]`, a NESTED ARRAY.**
 *     `resources/views/front_end/common/checkout/checkout_form.blade.php` opens its form on
 *     `['payment.process', $store->slug]` and names every address field `billing_info[firstname]`,
 *     `billing_info[billing_address]`, `billing_info[delivery_city]` and so on. A POST without
 *     that key reaches `PaymentController::handleCustomerRegistration()`, where `$billing` is null
 *     and `\Validator::make(null, ...)` is a TypeError — HTTP 500.
 *
 * With both corrected, the journey completes end to end: 302 to `{slug}/order-complete`, one
 * `orders` row, the billing detail row, the stock decremented and the basket consumed.
 *
 * SO THE EVIDENCE NOW LIVES IN THE SUITE, which is where it belongs:
 * `tests/Feature/Journeys/CommerceJourneyTest` drives the whole sequence over real routes and
 * asserts the row, and a mutation restoring either cause is CAUGHT (`M9`, `M11`).
 *
 * THIS SCRIPT IS KEPT, NOT DELETED, for two reasons: the two causes above are the only written
 * record of why `GAP-345` was hard, and the route premise it asserts is worth being able to check
 * from a shell on a server where the suite is not set up. It no longer claims anything is
 * unreproduced.
 *
 * PRECONDITIONS: a bootable application. Run from the repository root:
 *     php tests/Support/Security/bin/probe-guest-checkout-journey.php
 *
 * It exits 0 when the storefront order route still carries no authentication, which is the premise
 * the whole journey rests on, and 1 if that has changed.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "P04-P1-05 / GAP-345 guest checkout journey -- RESOLVED, see this file's docblock\n";
echo str_repeat('-', 80), "\n";

$route = collect(app('router')->getRoutes())->first(
    fn ($r) => str_contains($r->uri(), 'process-order')
);

if ($route === null) {
    fwrite(STDERR, "FAIL: no process-order route. The guest journey has no entry point.\n");
    exit(2);
}

$middleware = $route->gatherMiddleware();
$authish = array_values(array_filter(
    $middleware,
    fn ($m) => is_string($m) && (str_contains($m, 'auth') || str_contains($m, 'custom.auth'))
));

printf("route            : %s %s\n", implode('|', $route->methods()), $route->uri());
printf("middleware       : %s\n", implode(', ', array_map(fn ($m) => is_string($m) ? $m : gettype($m), $middleware)));
printf("auth middleware  : %s\n", $authish === [] ? 'none (a guest can reach it)' : implode(', ', $authish));

echo str_repeat('-', 80), "\n";
echo "The completing step is reproduced and asserted in\n";
echo "  tests/Feature/Journeys/CommerceJourneyTest::test_journey_1_a_guest_completes_the_purchase_the_plan_measured\n";
echo "The two harness causes are recorded in this file's docblock.\n";

exit($authish === [] ? 0 : 1);
