<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M0 — an unknown store slug must fail CLOSED, with a not-found response.
 *
 * MEASURED on the dev fixture before this change, 45 public `{storeSlug?}` GET page routes driven
 * with an unknown slug and no `Referer`:
 *
 *     about  address  order  wishlist                  500   <- a CRASH, and 200 on a real slug
 *     home  login  privacy-policy  forgot-password
 *     reset-password  error                            302   <- to the MERCHANT login page
 *     blog cart checkout collections contact-us faq
 *     product-list track-order order-complete          404   <- already correct
 *     5 AJAX fragments                                 200   <- already refuse in the BODY
 *
 * THE BASELINE REPORT SAID "an unknown slug serves another tenant's shop". IT DOES NOT, and the
 * correction is the useful part: `storeSlug()` answered `redirect()->back()`, so the capture
 * harness — which had navigated from store A — was sent back to store A and photographed it. With
 * no `Referer` the same route goes to `/`, which redirects to the MERCHANT login. No tenant's data
 * was ever served for a slug that does not exist. It is a soft-404 and a wrong-page defect, not a
 * leak, and this file asserts the difference in both directions.
 *
 * THE FIVE AJAX FRAGMENTS ARE DELIBERATELY NOT CHANGED. `search-product` answers
 * `{"html_data":null,"message":"<store does not exist>"}` and `product-filter`
 * `{"product_count":0,"status":false,"html":""}` — a refusal in the body with HTTP 200, which is
 * this codebase's documented convention (`ApiResponser::error()` returns 200 with `status: 0`, and
 * the shipped Flutter app force-logs-out on a new 401). Measured: 0 id-bearing tokens in any of
 * them. They are already fail-closed.
 *
 * EVERY unknown-slug assertion here is PAIRED with the same route on a REAL slug. The directive is
 * explicit that "a test that passes because the route returns 404 [or] the fixture never reaches
 * the handler is not acceptable evidence" — and this test's whole subject IS a 404, so without the
 * pair a typo'd route name, a missing theme or a middleware refusal would make every case pass
 * while proving nothing. The pair is what distinguishes "the store check refused" from "nothing
 * ran".
 */
class UnknownStoreSlugFailsClosedTest extends TestCase
{
    use RefreshDatabase;

    private const UNKNOWN = 'no-such-store-p3m0';

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = User::forceCreate([
            'name' => 'Slug Merchant', 'email' => 'slug-merchant@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->store = Store::create([
            'name' => 'Slug Fixture Store', 'email' => 'slug-store@example.test',
            'slug' => 'slug-fixture-store', 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $this->store->id;
        $merchant->save();
    }

    /**
     * The routes that answered 500 or 302 for an unknown slug and a real page for a real one.
     *
     * @return array<string, array{0: string}>
     */
    public static function failClosedRoutes(): array
    {
        return [
            // were 500 — a null store dereferenced in the view composer
            'about' => ['about'],
            'wishlist' => ['wishlist'],
            // were 302 to the merchant login
            'home' => ['home'],
            'privacy-policy' => ['privacy-policy'],
            'error' => ['error'],
            'login' => ['login'],
            'forgot-password' => ['forgot-password'],
            'reset-password' => ['reset-password'],
            // Added by the independent review, which read the handlers rather than the sweep's
            // route list and found three the first pass missed.
            'register' => ['register'],
            'address' => ['address'],
            'order' => ['order'],
        ];
    }

    #[Test]
    #[DataProvider('failClosedRoutes')]
    public function test_an_unknown_slug_is_not_found(string $route): void
    {
        $this->get("/" . self::UNKNOWN . "/{$route}")->assertNotFound();
    }

    /**
     * THE POSITIVE CONTROL. Same route, real slug: the handler must RUN. Without this a 404 from a
     * route that does not exist, a theme that will not resolve or a middleware that refused first
     * would satisfy the case above and prove nothing.
     */
    #[Test]
    #[DataProvider('failClosedRoutes')]
    public function test_the_same_route_reaches_the_handler_for_a_real_slug(string $route): void
    {
        $response = $this->get("/{$this->store->slug}/{$route}");

        // `assertOk`, NOT "neither 404 nor 500" — which is what this asserted first, and the
        // independent review was right that it was too weak to be a control: a 302 or the new
        // `abort(503)` theme arm would both have satisfied it, so the one failure mode this fix
        // could plausibly introduce — refusing a store that EXISTS — was exactly the mode the
        // control could not see.
        //
        // Every route in the provider is a public storefront page that answers 200 for a real
        // store. Measured on the dev fixture, both tenants, all eleven.
        $response->assertOk(
            "/{$this->store->slug}/{$route} did not answer 200 for a store that EXISTS. Either the "
            . 'unknown-slug case above is not evidence of anything, or this fix refuses too much.'
        );
    }

    /* =================================================================================
     * It must not be a redirect, and it must not land on anyone's shop
     * ================================================================================= */

    #[Test]
    #[DataProvider('failClosedRoutes')]
    public function test_an_unknown_slug_does_not_redirect_anywhere(string $route): void
    {
        $response = $this->get("/" . self::UNKNOWN . "/{$route}");

        $this->assertFalse($response->isRedirect(),
            "An unknown slug on /{$route} redirects to "
            . ($response->headers->get('Location') ?? '?')
            . '. A redirect is how the baseline harness came to photograph another store: it was '
            . 'sent BACK to wherever it came from.');
    }

    /**
     * The `Referer` is the whole mechanism of the original mis-reading, so it is driven explicitly.
     * `redirect()->back()` reads it; a fail-closed handler must ignore it.
     */
    #[Test]
    public function test_a_referer_from_a_real_store_cannot_bounce_an_unknown_slug_onto_it(): void
    {
        $response = $this->get(
            '/' . self::UNKNOWN . '/home',
            ['Referer' => url("/{$this->store->slug}/home")]
        );

        $response->assertNotFound();
        $this->assertFalse($response->isRedirect());
        $this->assertNotSame(url("/{$this->store->slug}/home"), $response->headers->get('Location'));
    }

    #[Test]
    public function test_the_not_found_body_does_not_carry_the_real_stores_identity(): void
    {
        $body = $this->get('/' . self::UNKNOWN . '/home')->getContent();

        $this->assertStringNotContainsString('Slug Fixture Store', $body);
        $this->assertStringNotContainsString('slug-store@example.test', $body);
    }

    /**
     * A SOURCE PIN, AND IT IS NOT FALSIFIABLE BY THE ROUTE CASES ABOVE — stated plainly because
     * that is the honest description.
     *
     * `about_page()`, `wishlist()` and `order_list()`'s guest branch have no store check of their
     * own; for an unknown slug their only refusal is the `abort(404)` in ViewServiceProvider's
     * composer. Removing `about_page`'s handler check leaves every route case above GREEN, because
     * the composer still catches it — measured. So the handler check is redundancy, not the
     * load-bearing control, and a behavioural test cannot tell the two apart.
     *
     * It is worth having anyway: the composer guard is one `return response()->json(...)` away
     * from being bypassed by a handler that stops rendering a view, and nothing else would notice.
     * This asserts the redundancy exists, which is the only thing that can be asserted about it.
     */
    #[Test]
    public function test_the_handlers_with_no_store_check_of_their_own_are_the_known_set(): void
    {
        // The body is EXTRACTED first, deliberately. A `/function about_page.*?getStore\(/s`
        // regex passed with the check removed, because `.*?` crossed the closing brace and matched
        // the NEXT handler's `getStore(` — a test that passed for the wrong reason, caught by
        // falsifying it rather than by reading it.
        $body = self::methodBody(
            file_get_contents(app_path('Http/Controllers/HomeController.php')),
            'about_page'
        );

        $this->assertStringContainsString('getStore(', $body,
            'about_page() no longer checks the store itself, so its only refusal for an unknown '
            . 'slug is the view composer. That works today and is one handler change away from '
            . 'not working.');
        $this->assertStringContainsString('abort(404)', $body);

        $orders = self::methodBody(
            file_get_contents(app_path('Http/Controllers/AccountProfileController.php')),
            'order_list'
        );

        $this->assertStringContainsString('abort(404)', $orders,
            'order_list() no longer refuses a null store before reading $store->id, so a '
            . 'SIGNED-IN customer is back to a 500 where a guest gets a 404.');

        // The guard must come BEFORE the first `$store->` read, which is the whole defect.
        //
        // COMMENTS ARE STRIPPED FIRST. Without that the explanatory comment above the guard —
        // which names `$store->id` in prose — was itself the earliest match, and the assertion
        // failed against the test's own documentation. A positional check over source text has to
        // look at code only.
        $code = preg_replace('{//[^\n]*}', '', $orders);

        $guardAt = strpos($code, 'abort(404)');
        $readAt = strpos($code, '$store->');
        $this->assertNotFalse($readAt, 'order_list() no longer reads $store-> at all; re-read this test.');
        $this->assertLessThan($readAt, $guardAt,
            'order_list() refuses AFTER its first $store-> read — the same four-lines-too-late '
            . 'shape as the view composer guard that had never executed.');
    }

    /**
     * The source text of ONE method, bounded by brace depth. Written because the obvious regex is
     * unbounded across methods and made the assertion above vacuous.
     */
    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}(");
        self::assertNotFalse($start, "{$method}() is not in that file at all.");

        $open = strpos($source, '{', $start);
        $depth = 0;

        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        self::fail("{$method}() has unbalanced braces; the extractor is wrong.");
    }

    /* =================================================================================
     * Private customer state
     * ================================================================================= */

    #[Test]
    public function test_a_signed_in_customer_is_not_logged_out_by_an_unknown_slug(): void
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Slug', 'last_name' => 'Shopper',
            'email' => 'slug-shopper@example.test', 'password' => bcrypt('x'),
            'store_id' => $this->store->id, 'created_by' => $this->store->created_by,
            'status' => 1, 'email_verified_at' => now(),
        ]);

        $this->actingAs($customer, 'customers');

        $this->get('/' . self::UNKNOWN . '/home')->assertNotFound();

        $this->assertTrue(auth('customers')->check(),
            'An unknown store slug logged the customer out. A refusal must not destroy a session '
            . 'that belongs to a DIFFERENT, real store.');
        $this->assertSame($customer->id, auth('customers')->id());
    }

    #[Test]
    public function test_an_unknown_slug_cannot_read_or_disturb_a_real_stores_cart(): void
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Cart', 'last_name' => 'Shopper',
            'email' => 'cart-shopper@example.test', 'password' => bcrypt('x'),
            'store_id' => $this->store->id, 'created_by' => $this->store->created_by,
            'status' => 1, 'email_verified_at' => now(),
        ]);

        $cart = Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => 1, 'qty' => 3,
            'price' => 10, 'store_id' => $this->store->id,
        ]);

        $this->actingAs($customer, 'customers');

        $this->get('/' . self::UNKNOWN . '/cart')->assertNotFound();
        $this->get('/' . self::UNKNOWN . '/home')->assertNotFound();

        // Asserted through withoutGlobalScopes: `Cart` IS in `scoped_models`, so a plain find()
        // answers against whichever tenant happens to be resolved, not against the table — the
        // false reading recorded in CLAUDE.md.
        $after = Cart::withoutGlobalScopes()->find($cart->id);

        $this->assertNotNull($after, 'The real store\'s cart row was destroyed by a refusal.');
        $this->assertSame(3, (int) $after->qty);
        $this->assertSame($this->store->id, (int) $after->store_id);
    }

    /**
     * `/order` answered 404 for a GUEST and **500 for a signed-in customer** — the same URL, two
     * different answers, decided by who asked. `order_list()` reads `$store->id` before any view
     * renders, so the composer's guard could not reach it. Found by the independent review.
     */
    #[Test]
    public function test_a_signed_in_customer_gets_the_same_refusal_a_guest_gets(): void
    {
        $guest = $this->get('/' . self::UNKNOWN . '/order');

        $customer = Customer::forceCreate([
            'first_name' => 'Order', 'last_name' => 'Shopper',
            'email' => 'order-shopper@example.test', 'password' => bcrypt('x'),
            'store_id' => $this->store->id, 'created_by' => $this->store->created_by,
            'status' => 1, 'email_verified_at' => now(),
        ]);

        $this->actingAs($customer, 'customers');
        $signedIn = $this->get('/' . self::UNKNOWN . '/order');

        $guest->assertNotFound();
        $signedIn->assertNotFound();
        $this->assertSame($guest->getStatusCode(), $signedIn->getStatusCode(),
            'A guest and a signed-in customer get different answers for the same unknown slug.');

        // And the real store's own order page still works for them.
        $this->get("/{$this->store->slug}/order")->assertOk();
    }

    /* =================================================================================
     * Tenant isolation: the refusal must not be slug-guessable into another tenant
     * ================================================================================= */

    #[Test]
    public function test_a_second_tenants_slug_is_still_served_and_is_not_confused_with_a_refusal(): void
    {
        $other = Store::create([
            'name' => 'Second Tenant Store', 'email' => 'second@example.test',
            'slug' => 'second-tenant-store', 'theme_id' => 'stylique',
            'created_by' => $this->store->created_by, 'is_active' => 1, 'default_language' => 'en',
        ]);

        // A real second tenant is public and must still answer.
        $this->assertNotSame(404, $this->get("/{$other->slug}/home")->getStatusCode(),
            'A real second tenant now 404s, so the fix refuses too much.');

        // And the unknown slug must not resolve to it either.
        $body = $this->get('/' . self::UNKNOWN . '/home')->getContent();
        $this->assertStringNotContainsString('Second Tenant Store', $body);
    }

    /**
     * `!empty($storeSlug)` is false for the STRING '0'. A store whose slug is `0` was unreachable
     * (403 "The Link is not active."), and an unknown slug of `0` answered 403 instead of 404 —
     * a different refusal for the same question, decided by a PHP falsy-string rule.
     */
    #[Test]
    public function test_a_slug_of_zero_is_a_slug_and_not_an_empty_one(): void
    {
        $this->get('/0/home')->assertNotFound();

        $zero = Store::create([
            'name' => 'Zero Slug Store', 'email' => 'zero@example.test',
            'slug' => '0', 'theme_id' => 'stylique',
            'created_by' => $this->store->created_by, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->assertNotSame(404, $this->get("/{$zero->slug}/home")->getStatusCode(),
            'A store whose slug is "0" is unreachable, because !empty("0") is false in PHP.');
        $this->assertNotSame(403, $this->get("/{$zero->slug}/home")->getStatusCode());
    }

    /* =================================================================================
     * The AJAX fragments: already fail-closed, pinned so a later change cannot widen them
     * ================================================================================= */

    /** @return array<string, array{0: string}> */
    public static function fragmentRoutes(): array
    {
        return [
            'search-product' => ['search-product'],
            'product-filter' => ['product-filter'],
            'cart-list-sidebar' => ['cart-list-sidebar'],
            'paymentlist' => ['paymentlist'],
            'additionalnote' => ['additionalnote'],
        ];
    }

    #[Test]
    #[DataProvider('fragmentRoutes')]
    public function test_a_fragment_returns_no_tenant_data_for_an_unknown_slug(string $route): void
    {
        $body = $this->get('/' . self::UNKNOWN . "/{$route}")->getContent();

        $this->assertStringNotContainsString('Slug Fixture Store', $body);
        $this->assertStringNotContainsString('slug-store@example.test', $body);
        $this->assertLessThan(2048, strlen($body),
            "/{$route} returned " . strlen($body) . ' bytes for a store that does not exist. '
            . 'Measured on the dev fixture: 44-117 bytes, all of it a refusal message.');
    }
}
