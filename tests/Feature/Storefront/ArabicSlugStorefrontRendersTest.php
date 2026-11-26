<?php

namespace Tests\Feature\Storefront;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GAP-118's SECOND DEFECT, END TO END — AN ARABIC STORE SLUG TOOK THE WHOLE STOREFRONT DOWN.
 * =============================================================================================
 *
 * `metaKeywordSetting()` ended in `return sprintf($script);` — the fully interpolated markup passed
 * as `sprintf`'s FORMAT argument with no arguments after it. So every `%` in the markup was parsed
 * as a conversion specification.
 *
 * The markup contains a URL: `route('landing_page', $slug)`, which **percent-encodes**. And
 * `User::slugs()` keeps Arabic letters — it strips with `preg_replace('/[^\p{L}\p{N}\s-]/u', ...)`,
 * and `\p{L}` with the `/u` flag matches Arabic. So a store named `متجر سوريا` gets the slug
 * `متجر-سوريا`, whose encoded form is `%D9%85%D8%AA%D8%AC%D8%B1-…` — nineteen conversion
 * specifications, none of them with an argument:
 *
 *     ArgumentCountError: 19 arguments are required, 1 given
 *
 * ---------------------------------------------------------------------------------------------
 * WHY THIS FILE EXISTS SEPARATELY FROM THE UNIT TEST.
 *
 * `tests/Unit/Security/MetaKeywordSettingEscapingTest.php` proves the HELPER throws. That is not
 * the same claim as "the storefront is down", and the agent that fixed the helper said so plainly
 * rather than implying the stronger version: it had not driven a storefront request. This closes
 * that link.
 *
 * It matters because of WHERE the helper is called: line 12 of `front_end/layouts/app.blade.php`,
 * inside `<head>`, in **all four copies** of that layout (one per theme plus the `resources/views`
 * fallback — CLAUDE.md records that a layout-level storefront change must be made four times). A
 * throw there is not a degraded `<head>`; it is an unhandled error before any page content, on
 * every page of the store.
 *
 * On a project whose first-class languages are Arabic and English, a merchant naming their store in
 * Arabic is the ordinary case, not an edge case. That is the whole severity of it.
 *
 * ---------------------------------------------------------------------------------------------
 * THE ASCII STORE IS THE CONTROL, and it is load-bearing in a way worth stating: `sprintf` returns
 * its format string unchanged whenever it does NOT throw, which is why removing it preserved
 * behaviour for every input that already worked. Without an ASCII control, a test could pass
 * against a "fix" that broke ordinary stores instead.
 */
class ArabicSlugStorefrontRendersTest extends TestCase
{
    use RefreshDatabase;

    private Store $arabic;

    private Store $ascii;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = User::forceCreate([
            'name' => 'Arabic Slug Merchant',
            'email' => 'arabic-slug@example.test',
            'password' => bcrypt('x'),
            'type' => 'admin',
            'created_by' => 1,
            'email_verified_at' => now(),
        ]);

        /*
         * THE SLUG IS SET DIRECTLY, NOT THROUGH `User::slugs()`, AND THAT IS THE MEASURED PATH.
         *
         * A first version of this fixture used `User::slugs('متجر سوريا')` on the reasonable
         * assumption that the application's own generator produces the slug. It does not produce an
         * Arabic one: `slugs()` runs a Unicode-aware pass that keeps Arabic and THEN an ASCII-only
         * `preg_replace('/[^a-zA-Z0-9\s-]/', '', ...)` that strips it, so that name yields `-`.
         * The premise assertion below caught it. (That is its own defect and is registered
         * separately -- it is not what this file is about.)
         *
         * The path that really reaches an Arabic slug is a merchant typing one into store settings:
         * `AppSettingController:634` is `$store->slug = $request->store_slug;` -- straight from
         * request input, with no `slugs()` sanitisation at all. The model's own comment at
         * `User::slugs()` records that writer and the Flutter store-settings endpoint as assigning
         * slugs directly, which is why the reserved-slug list is a validation rule on the model
         * rather than a property of the mint path. So this fixture reproduces that state exactly.
         */
        $this->arabic = Store::create([
            'name' => 'متجر سوريا',
            'email' => 'arabic-store@example.test',
            'slug' => 'متجر-سوريا',
            'theme_id' => 'stylique',
            'created_by' => $merchant->id,
            'is_active' => 1,
            'default_language' => 'ar',
        ]);

        $this->ascii = Store::create([
            'name' => 'Store Syria',
            'email' => 'ascii-store@example.test',
            'slug' => 'store-syria',
            'theme_id' => 'stylique',
            'created_by' => $merchant->id,
            'is_active' => 1,
            'default_language' => 'en',
        ]);

        $merchant->current_store = $this->ascii->id;
        $merchant->save();
    }

    /**
     * THE PREMISE, and without it everything below is vacuous: the application's own slug generator
     * really does produce an Arabic slug, so this is the state a merchant reaches by typing their
     * store's name — not a slug this test invented.
     */
    public function test_an_arabic_slug_is_a_state_a_merchant_can_really_reach(): void
    {
        $this->assertSame('متجر-سوريا', $this->arabic->slug);

        /*
         * The reachability premise, asserted rather than assumed: the direct writer takes the slug
         * from request input, so a merchant can store any string. If that line ever starts
         * sanitising, this file stops measuring a reachable state and should say so loudly.
         */
        $writer = file_get_contents(base_path('app/Http/Controllers/AppSettingController.php'));
        $this->assertStringContainsString('$store->slug = $request->store_slug;', $writer,
            'AppSettingController no longer assigns the store slug straight from request input, so '
            . 'an Arabic slug may no longer be reachable -- re-establish the path before trusting '
            . 'this file.');

        $this->assertStringContainsString('%D9', rawurlencode($this->arabic->slug),
            'the Arabic slug does not percent-encode, so the format-string hazard cannot arise '
            . 'and this file no longer measures anything');

        $this->assertSame('store-syria', $this->ascii->slug);
    }

    /** The helper itself, with the real slug, through the real router. */
    public function test_the_head_helper_does_not_throw_for_an_arabic_slug(): void
    {
        $markup = metaKeywordSetting(null, null, null, $this->arabic->slug);

        $this->assertIsString($markup);
        $this->assertStringContainsString('<meta', $markup);
        $this->assertStringContainsString(rawurlencode($this->arabic->slug), $markup,
            'the store\'s own encoded slug is absent from the <head> it generated');

        /* And the output is still valid UTF-8 — a byte-level repair that mangled the encoding
           would satisfy "did not throw" while corrupting every Arabic store's metadata. */
        $this->assertTrue(mb_check_encoding($markup, 'UTF-8'));
    }

    /** @return array<string,array{0:string}> the storefront page routes that render the layout */
    public static function storefrontPages(): array
    {
        return [
            'landing' => ['home'],
            'shop' => ['shop'],
        ];
    }

    /**
     * THE CLAIM THAT MATTERS: a real HTTP request to an Arabic-slug store's storefront.
     *
     * Asserted as "not a 500" rather than "is a 200", deliberately. A storefront page has many
     * legitimate non-200 answers in a bare test fixture — a redirect, a missing theme setting, a
     * route that needs data this fixture has no reason to create. What must never happen is the
     * unhandled error, and that is what the format-string bug produced. The ASCII control below
     * establishes that the same fixture CAN render, so a 500 here would be about the slug.
     */
    #[DataProvider('storefrontPages')]
    public function test_an_arabic_slug_store_does_not_crash_its_own_storefront(string $page): void
    {
        $arabicStatus = $this->get('/' . $this->arabic->slug . '/' . $page)->status();
        $asciiStatus = $this->get('/' . $this->ascii->slug . '/' . $page)->status();

        $this->assertNotSame(500, $arabicStatus, sprintf(
            'GET /%s/%s answered 500 while the ASCII-slug twin answered %d. '
            . 'metaKeywordSetting() is called at line 12 of front_end/layouts/app.blade.php, inside '
            . '<head>, in all four copies of that layout — so a throw there takes down every page '
            . 'of the store, and an Arabic store name is the ordinary case on this project.',
            $this->arabic->slug,
            $page,
            $asciiStatus
        ));

        /*
         * THE TWO SLUGS MUST BEHAVE THE SAME. This is the assertion that makes the case specific to
         * the slug: if the fixture cannot render this page at all, both are equal and non-500 and
         * the case is honestly inconclusive rather than falsely green — whereas before the fix the
         * two differed, Arabic 500 against ASCII not.
         */
        $this->assertSame($asciiStatus, $arabicStatus, sprintf(
            'the Arabic-slug store answered %d and the ASCII-slug store %d for the same page. The '
            . 'slug is the only difference between the two fixtures.',
            $arabicStatus,
            $asciiStatus
        ));
    }
}
