<?php

namespace Tests\Feature\Support;

use App\Http\Controllers\ContextualHelpController;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Support\Help\ContextualHelp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Security\SecurityTestCase;

/**
 * PHASE 06 `SUPPORT-002` — THE WEB HALF OF «مساعدة سياقية»: THE HELP SCREEN AND ITS HEADER CONTROL.
 * =================================================================================================
 *
 * The header's help control resolves the merchant's CURRENT route name to a surface key server-side
 * and links here with it, so a screen gains contextual help by one line in
 * `ContextualHelp::routeSegments()` and no view is edited. `test_the_header_help_control_carries_
 * the_surface_of_the_screen_it_is_on` is the case that proves the resolution really happens on the
 * page rather than only in the unit test.
 *
 * THE REFUSAL IS DRIVEN BY CALLING THE HANDLER DIRECTLY, for the reason CLAUDE.md records twice: a
 * handler-level check behind an `abort()`ing middleware is invisible to an HTTP test.
 *
 * THE SURFACE-LABEL CENSUS STRIPS BLADE COMMENTS FIRST, AND SAYS SO. CLAUDE.md records eight
 * occasions on which a census was satisfied — or broken — by prose ABOUT the thing it measures, one
 * of them a Blade comment containing the very literal being hunted. `Tests\Support\
 * StripsSourceComments` handles PHP and `/* * /`-style comments and deliberately does NOT touch
 * `{{-- --}}`, so this file strips those itself and asserts that the strip removed something.
 */
class ContextualHelpScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const VIEW = 'resources/views/help/index.blade.php';

    /** @return array{0: User, 1: Store} */
    private function merchantWithStore(string $language = 'en'): array
    {
        $merchant = $this->makeMerchant(['language' => $language]);
        $store    = $this->makeStore($merchant);

        $merchant->current_store = $store->id;
        $merchant->save();

        return [User::find($merchant->id), $store];
    }

    /** The view source with every `{{-- --}}` block removed. See the class docblock. */
    private function viewWithoutComments(): string
    {
        $path = base_path(self::VIEW);

        $this->assertFileExists($path);

        $raw = (string) file_get_contents($path);

        $stripped = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);

        $this->assertLessThan(
            strlen($raw),
            strlen($stripped),
            'The Blade comment strip removed nothing, so either this view has lost its '
            . 'documentation or the pattern has stopped matching. Either way the census below is '
            . 'now reading prose as if it were markup, which is the failure CLAUDE.md records eight '
            . 'times.'
        );

        return $stripped;
    }

    // =============================================================================================
    // the screen
    // =============================================================================================

    public function test_a_merchant_asking_for_a_surface_is_given_that_screens_guides(): void
    {
        [$merchant] = $this->merchantWithStore();

        $response = $this->actingAs($merchant)
            ->get(route('merchant.help', ['surface' => 'products']));

        $response->assertStatus(200);
        $response->assertSee('ss-help-context-end', false);
        $response->assertSee('ss-help-screen-end', false);

        /* The real article titles, from `resources/docs/en/03-products/`. */
        $body = (string) $response->getContent();

        foreach (ContextualHelp::articlesForSurface('products', 'en') as $article) {
            $this->assertStringContainsString(
                e($article['title']),
                $body,
                'The help screen did not print the article `' . $article['id'] . '`.'
            );
        }
    }

    public function test_an_arabic_merchant_reads_arabic_guides_in_a_right_to_left_document(): void
    {
        [$merchant] = $this->merchantWithStore('ar');

        $response = $this->actingAs($merchant)
            ->get(route('merchant.help', ['surface' => 'products']));

        $response->assertStatus(200);

        $body = (string) $response->getContent();

        $this->assertStringContainsString('dir="rtl"', $body);
        $this->assertStringContainsString('مساعدة هذه الشاشة', $body);

        $arabic = ContextualHelp::articlesForSurface('products', 'ar');

        $this->assertNotSame([], $arabic);
        $this->assertStringContainsString(e($arabic[0]['title']), $body);

        /* AND THE ENGLISH TITLE OF THE SAME ARTICLE IS ABSENT, which is what distinguishes "the
           Arabic corpus was served" from "the page happens to contain some Arabic chrome". */
        $english = ContextualHelp::articlesForSurface('products', 'en');

        $this->assertStringNotContainsString(e($english[0]['title']), $body);
    }

    public function test_an_english_merchant_reads_a_left_to_right_document(): void
    {
        [$merchant] = $this->merchantWithStore('en');

        $body = (string) $this->actingAs($merchant)
            ->get(route('merchant.help', ['surface' => 'products']))->getContent();

        $this->assertStringContainsString('dir="ltr"', $body);
        $this->assertStringNotContainsString('dir="rtl"', $body);
    }

    /**
     * SAID OUT LOUD, because a server that quietly showed the full map would hide a drift between
     * the shipped app's surface keys and this build's.
     */
    public function test_a_surface_this_build_does_not_know_is_said_out_loud(): void
    {
        [$merchant] = $this->merchantWithStore();

        $response = $this->actingAs($merchant)
            ->get(route('merchant.help', ['surface' => 'wallet']));

        $response->assertStatus(200);
        $response->assertSee('There is no help for that screen yet', false);
        $response->assertDontSee('ss-help-context-end', false);
        /* And the full map is still there, so the merchant is not left on an empty screen. */
        $response->assertSee('All guides', false);
    }

    public function test_no_surface_at_all_lands_on_the_full_map(): void
    {
        [$merchant] = $this->merchantWithStore();

        $response = $this->actingAs($merchant)->get(route('merchant.help'));

        $response->assertStatus(200);
        $response->assertSee('All guides', false);
        $response->assertDontSee('There is no help for that screen yet', false);
    }

    public function test_a_hostile_surface_neither_resolves_nor_500s(): void
    {
        [$merchant] = $this->merchantWithStore();

        foreach (['../../etc/passwd', 'products/../orders', '../en/products/add-a-product'] as $hostile) {
            $response = $this->actingAs($merchant)
                ->get(route('merchant.help', ['surface' => $hostile]));

            $response->assertStatus(200);
            $response->assertSee('There is no help for that screen yet', false);
            $response->assertDontSee('ss-help-context-end', false);
        }
    }

    // =============================================================================================
    // the header control
    // =============================================================================================

    /**
     * THE RESOLUTION, ON A REAL PAGE. The merchant opens the product list and the header's help
     * link already carries `surface=products`.
     */
    public function test_the_header_help_control_carries_the_surface_of_the_screen_it_is_on(): void
    {
        [$merchant] = $this->merchantWithStore();

        /*
         * TWO SCREENS, AND NOT THE PRODUCT LIST, FOR A MEASURED REASON.
         *
         * `product.index` was the obvious choice and it answers **302** for a freshly made merchant
         * in this suite -- to `/`, before any view renders -- as do `order.index` (to `dashboard`),
         * `plan.index` and `users.index`. CLAUDE.md records this shape: a merchant with no
         * `add_on_managers` row resolves no activated module and every gate redirects, and a
         * status-code assertion photographs the dashboard as the screen. Measured here: of the
         * mapped segments, `dashboard`, `theme.index` and `profile` answer 200.
         *
         * So the two that render are driven, and they are driven with DIFFERENT surfaces -- a test
         * against one surface cannot tell "the route name was resolved" from "the first entry of
         * the map was printed".
         */
        foreach (['dashboard' => 'overview', 'theme.index' => 'storefront'] as $route => $surface) {
            $body = (string) $this->actingAs($merchant)->get(route($route))->getContent();

            $this->assertStringContainsString(
                e(route('merchant.help', ['surface' => $surface])),
                $body,
                $route . '\'s header help control does not carry `surface=' . $surface . '`. The '
                . 'resolution is server-side from the route name, so these are the only cases that '
                . 'prove it happens on a page rather than only in ContextualHelpTest.'
            );
        }
    }

    public function test_the_header_help_control_carries_no_surface_on_an_unmapped_screen(): void
    {
        [$merchant] = $this->merchantWithStore();

        $body = (string) $this->actingAs($merchant)
            ->get(route('merchant.announcements'))->getContent();

        /* `merchant.announcements` maps to no surface, so the control links to the bare screen. */
        $this->assertStringContainsString(e(route('merchant.help')), $body);
        $this->assertStringNotContainsString('surface=', $body);
    }

    // =============================================================================================
    // the handler's own clause, and the label census
    // =============================================================================================

    public function test_the_handler_itself_refuses_a_customer(): void
    {
        [, $store] = $this->merchantWithStore();

        $customer = Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => 'Probe',
            'email' => 'helpshopper' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
        ]);

        auth('customers')->login($customer);

        $response = app(ContextualHelpController::class)->index(Request::create('/merchant-help'));

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertSame(route('login'), $response->getTargetUrl());
    }

    public function test_the_same_handler_call_serves_a_merchant_a_view(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->actingAs($merchant);

        $response = app(ContextualHelpController::class)->index(Request::create('/merchant-help'));

        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $response);
        $this->assertSame('help.index', $response->name());
    }

    /**
     * EVERY SURFACE HAS A HEADING, so adding one to `ContextualHelp::SURFACES` cannot leave the
     * screen printing a raw key like `storefront` at a merchant.
     *
     * Reading the VIEW rather than the rendered page, because the fallback `?? $entry['surface']`
     * means a missing label renders as the key and the page still looks fine.
     */
    public function test_every_surface_has_a_heading_on_the_screen(): void
    {
        $source = $this->viewWithoutComments();

        foreach (array_keys(ContextualHelp::SURFACES) as $surface) {
            $this->assertStringContainsString(
                "'" . $surface . "' => __(",
                $source,
                'The surface `' . $surface . '` has no heading in ' . self::VIEW . ', so the screen '
                . 'falls back to printing the raw key at a merchant. Add a LITERAL `__()` entry: a '
                . 'concatenated key is invisible to the translation census and renders as the key '
                . 'itself when it is missing from ar.json.'
            );
        }
    }

    /**
     * AND THE LABELS REALLY REACH THE PAGE, in both languages — the other half of the case above,
     * because reading the source alone cannot tell a declared label from a printed one.
     */
    public function test_the_headings_are_printed_in_both_languages(): void
    {
        [$english] = $this->merchantWithStore('en');
        [$arabic] = $this->merchantWithStore('ar');

        $this->actingAs($english)->get(route('merchant.help'))
            ->assertSee('Staff and permissions', false);

        $this->actingAs($arabic)->get(route('merchant.help'))
            ->assertSee('الموظّفون والصلاحيات', false);
    }
}
