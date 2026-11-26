<?php

/**
 * P04-P1-05 — the guest checkout journey, as far as it currently goes.
 * =================================================================================================
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST. The three facts that hold are asserted in
 * `tests/Feature/Commerce/GuestCheckoutJourneyTest`. This file carries the part that does NOT yet
 * complete, and it is deliberately NOT in the suite: adding a declared failure or skip to
 * `.github/known-test-outcomes.json` to keep CI green is the one use of that file its own README
 * forbids, and an unexplained red test is worse. CLAUDE.md's rule for exactly this case is that
 * evidence which cannot live in the suite goes here, as an executable script stating its own
 * preconditions.
 *
 * WHAT IS ESTABLISHED (asserted in the Feature test, not here):
 *   - `POST {storeSlug}/process-order` -> `PaymentController::processOrder` is the route a guest
 *     uses, and it carries NO authentication middleware;
 *   - the guest basket is real `carts` rows that `Cart::forGuestCookie` selects;
 *   - a guest can open the checkout page of the store they are buying from.
 *
 * WHAT DOES NOT COMPLETE, and it is NOT established whether this is the harness or the product:
 * the POST answers 302 to `/` with the session error `Cart is empty.`, and no `orders` row is
 * written. The refusal is `PaymentController::getCartData`, which reads the basket through
 * `Cart::cart_list_cookie($request->all(), $store->id)` -> `Cookie::get('cart')`.
 *
 * THREE FIXTURE HYPOTHESES TESTED AND REFUTED, so they need not be repeated:
 *   1. the cookie is named after the column (`cookie_session_id`) — it is NOT; it is `cart`, and
 *      the cookie's VALUE is what matches `carts.cookie_session_id`;
 *   2. an unencrypted cookie reaches the handler — it does not; `cart` is not exempt from
 *      `EncryptCookies`, so the middleware discards it before the handler runs;
 *   3. `encrypt($value, false)` makes it readable — it did not change the outcome.
 *
 * MEASURED SEPARATELY AND NOT THE CAUSE: `Cookie::get('cart')` reads a request cookie correctly
 * outside the HTTP stack — a probe built a Request with that cookie and the facade returned it.
 *
 * WHAT WOULD SETTLE IT. Drive the basket the way the storefront itself does — through the cart
 * endpoints (`product_cart`, `change-cart`) rather than by inserting rows — so the cookie is
 * minted by the application instead of by the fixture. If the journey completes that way, the gap
 * was the harness and this script closes. If it still refuses, the guest journey is broken in the
 * product and that is a Phase 04 defect against the phase's own stated premise.
 *
 * PRECONDITIONS: a bootable application and a writable test database. Run from the repository root:
 *     php tests/Support/Security/bin/probe-guest-checkout-journey.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "P04-P1-05 guest checkout journey probe\n";
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
echo "STATE: the completing step is unreproduced. See this file's docblock for the three\n";
echo "       refuted hypotheses and for what would settle whether it is the harness or the\n";
echo "       product. This script asserts nothing about the order row on purpose.\n";

exit($authish === [] ? 0 : 1);
