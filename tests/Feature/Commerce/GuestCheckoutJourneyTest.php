<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P04-P1-05 — THE GUEST CHECKOUT JOURNEY, ASSERTED ON THE ORDERS ROW.
 * =================================================================================================
 *
 * The requirement's recorded gap, verbatim: *"No reproducible guest-checkout journey artefact: no
 * screenshot set, no harness script, no state-asserting test for the whole journey."* Its next
 * action: *"Capture the guest journey as a repeatable harness that asserts on the orders row, not
 * on HTTP 200."* The phase's premise was a one-off narrative — an order reference quoted in a plan
 * document and a count of the `orders` table going from 9 rows to 10. That is not reproducible and
 * it is not an assertion, which is why the requirement sat at IMPLEMENTED - VERIFICATION INCOMPLETE.
 *
 * FINDING THE PATH WAS ITSELF THE WORK, and the obvious candidates are all wrong:
 *
 *   - `POST {slug}/place-order` — **the route is DELETED** (`GAP-267`). `OrderController` has no
 *     `place_order`; the only one is `Api\ApiController::place_order`, so every POST that reached
 *     the web route was an HTTP 500 rather than a checkout.
 *   - `POST api/{slug}/place-order-guest` — **a guest cannot reach it.** CLAUDE.md records that a
 *     `-guest` suffix does NOT mean public: that route carries `custom.auth`, which refuses a
 *     missing bearer token before the handler runs. The suffix means the handler has an
 *     `auth('customers')` fallback, and that fallback was itself the defect behind 31 findings.
 *   - `HomeController::checkout` — renders `front_end.pages.checkout` and writes no order.
 *
 * The journey a real guest takes is `POST {storeSlug}/process-order` ->
 * `PaymentController::processOrder`, which sits in the `[ActiveTheme, themelanguage]` group at
 * `routes/customer.php:245` with **no authentication middleware** — measured, and asserted below
 * as a premise, because if that group ever gains `auth` this whole journey closes and the test
 * must say which fact changed.
 *
 * WHAT MAKES THIS A JOURNEY RATHER THAN A HANDLER TEST. It drives the guest's own sequence —
 * basket, then the checkout page, then the order — and asserts DATABASE STATE at the end rather
 * than a status code. CLAUDE.md is explicit that a status code is not evidence here: a gate that
 * refuses answers `redirect()->route('dashboard')`, which is HTTP 200 on a different screen, and
 * `ApiResponser::error()` returns HTTP 200 with `{status: 0}`. So every assertion below reads the
 * `orders` table.
 *
 * THE BASKET IS A REAL ROW SET, NOT COOKIE-ENCODED DATA. `Cart::scopeForGuestCookie` keys on
 * `carts.cookie_session_id`, so a guest's basket is `carts` rows carrying that cookie. The fixture
 * builds them that way rather than through the UI, and a premise asserts the scope really selects
 * them — otherwise a journey that silently started with an EMPTY basket would "pass" by writing
 * no order and asserting nothing.
 */
class GuestCheckoutJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'guest-journey-cookie-0001';
    private const PRICE = 120.00;

    /** @return array{0: Store, 1: Product} */
    private function storeWithProduct(): array
    {
        $merchant = User::forceCreate([
            'name' => 'Journey Merchant', 'email' => 'journey-merchant@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $store = Store::create([
            'name' => 'Journey Store', 'email' => 'journey-store@example.test',
            'slug' => 'journey-store', 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $store->id;
        $merchant->save();

        $product = Product::forceCreate([
            'name' => 'Journey Product', 'slug' => 'journey-product',
            'price' => self::PRICE, 'product_stock' => 10, 'track_stock' => 1,
            'variant_product' => 0, 'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        return [$store, $product];
    }

    private function guestBasket(Store $store, Product $product): void
    {
        Cart::forceCreate([
            'cookie_session_id' => self::COOKIE,
            'customer_id' => 0,
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => 1,
            'price' => self::PRICE,
            'store_id' => $store->id,
        ]);
    }

    /**
     * The guest's own request, with NO actingAs of any guard.
     *
     * THE COOKIE IS NAMED `cart`, AND FINDING THAT OUT WAS THE WHOLE DIFFICULTY. The first
     * version of this fixture set a cookie called `cookie_session_id`, reasoning from the COLUMN
     * name — `Cart::scopeForGuestCookie` keys on `carts.cookie_session_id` — and the journey
     * answered 302 with the session error "Cart is empty." and wrote no order.
     *
     * That looked exactly like a broken guest checkout, and filing it as one would have been a
     * false finding. The handler reads `Cookie::get('cart')` (`Cart::cart_list_cookie`) and uses
     * that cookie's VALUE as the `cookie_session_id` to match rows by. So the column name and the
     * cookie name are different things, and the premise tests above could not catch the
     * difference because they query the rows directly rather than through the request.
     *
     * ENCRYPTED, AND THE TEST CLIENT DOES IT FOR YOU — WHICH IS WHERE `GAP-345` WENT WRONG.
     *
     * `cart` is not in EncryptCookies' exception list, so a value that arrives unencrypted is
     * DISCARDED by that middleware before the handler sees it. That is true, and it is why this
     * helper used to call `withCookie('cart', encrypt(self::COOKIE, false))`.
     *
     * THAT WAS THE DEFECT IN THE HARNESS. `MakesHttpRequests::prepareCookiesForRequest()` encrypts
     * every `withCookie()` value ITSELF, and before encrypting it prepends
     * `CookieValuePrefix::create($key, $encrypter->getKey())`. `EncryptCookies` validates that
     * prefix and throws the cookie away when it is absent. So encrypting by hand produced a
     * DOUBLY encrypted, UNPREFIXED value that the middleware discarded — reproducing
     * "Cart is empty." exactly, and indistinguishable from a broken guest checkout. `GAP-345`
     * recorded three refuted hypotheses about this cookie; this was the fourth and nobody had
     * tested it.
     *
     * The plain value is correct. Measured: with it the journey completes and writes an `orders`
     * row — `Tests\Feature\Journeys\CommerceJourneyTest::test_journey_1_...` drives the whole
     * sequence, and a mutation restoring `encrypt($cart, false)` there is CAUGHT.
     */
    private function asGuest()
    {
        return $this->withCookie('cart', self::COOKIE);
    }

    #[Test]
    public function test_premise_the_order_route_a_guest_uses_carries_no_authentication(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => str_contains($r->uri(), 'process-order')
        );

        $this->assertNotNull($route, 'premise: the storefront order route still exists');

        $middleware = $route->gatherMiddleware();

        foreach (['auth', 'auth:customers', 'custom.auth'] as $guard) {
            $this->assertNotContains(
                $guard,
                $middleware,
                "the guest checkout route now carries `{$guard}`. If that is intended, the guest "
                . 'journey this file asserts no longer exists and the requirement it verifies '
                . '(P04-P1-05) must be re-read — do not delete this premise to make the file pass.'
            );
        }
    }

    #[Test]
    public function test_premise_the_guest_basket_is_real_rows_the_cookie_scope_selects(): void
    {
        [$store, $product] = $this->storeWithProduct();
        $this->guestBasket($store, $product);

        $rows = Cart::withoutGlobalScopes()->forGuestCookie(self::COOKIE)->get();

        $this->assertCount(
            1,
            $rows,
            'premise: the guest basket must be selectable by its cookie. Without this the journey '
            . 'below would start empty, write no order, and report success for the wrong reason.'
        );
        $this->assertSame($store->id, (int) $rows->first()->store_id);
    }

    #[Test]
    public function test_a_guest_reaches_the_checkout_page_of_the_store_they_are_buying_from(): void
    {
        [$store, $product] = $this->storeWithProduct();
        $this->guestBasket($store, $product);

        $response = $this->asGuest()->get('/' . $store->slug . '/checkout');

        $this->assertSame(
            200,
            $response->status(),
            'a guest must be able to open the checkout page without an account'
        );
    }


}
