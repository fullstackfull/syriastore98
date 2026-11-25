<?php

namespace Tests\Feature\Accounts;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AN ARABIC STORE NAME PRODUCED THE SLUG `-`, OR AN EMPTY ONE.
 * =============================================================================================
 *
 * `User::slugs()` is the mint path for `stores.slug` — called by `StoreController` at three sites,
 * `Auth\RegisteredUserController` at two, and the Flutter store-creation endpoint. It runs **two**
 * sanitisation passes:
 *
 *     1. $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $data);   // Unicode-aware: KEEPS Arabic
 *        ... lowercase, spaces -> hyphens, collapse hyphens
 *        if (empty($slug)) { $slug = 'store-' . uniqid(); }        // the fallback
 *     2. $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $slug);     // ASCII-ONLY: STRIPS Arabic
 *
 * Pass 1 keeps `متجر سوريا` as `متجر-سوريا`. Pass 2 deletes every Arabic byte. **And the
 * `empty()` fallback sits BETWEEN them**, so it is dead code for every Arabic name: it tests a
 * string that is not yet empty, and the pass that empties it runs afterwards.
 *
 * Measured, before this change:
 *
 *     متجر سوريا    -> `-`          (the hyphen from the space, nothing else)
 *     صيدلية الشام  -> `-`          (so the second Arabic store collides -> `-1`, third `-2`)
 *     مطعم          -> ``           (single word, no space: AN EMPTY SLUG)
 *     مقهى 24       -> `-24`
 *     متجر Syria    -> `-syria`
 *     Store Syria   -> `store-syria` (unaffected)
 *
 * WHY THIS MATTERS RATHER THAN BEING COSMETIC. `stores.slug` is the storefront's routing segment:
 * `/{slug}/home`. An empty slug makes that `//home`, and a slug of `-` gives every Arabic-named
 * merchant the same URL until the collision suffix separates them into `-1`, `-2`. On a platform
 * whose first-class languages are Arabic and English, naming a store in Arabic is the ordinary
 * case.
 *
 * ---------------------------------------------------------------------------------------------
 * THE FIX USES THE PROJECT'S OWN FALLBACK AND INVENTS NOTHING.
 *
 * The `empty($slug)` branch already decides what an unusable slug becomes: `'store-' . uniqid()`.
 * That decision is already made and already shipped; it was simply positioned where it could never
 * fire for this input. So the fix MOVES the check to after the ASCII pass and widens "unusable"
 * from `empty()` to "holds no alphanumeric character", which is what `-` and `-` -> `-1` are.
 *
 * TRANSLITERATION IS NOT DONE HERE, deliberately. The commented-out
 * `transliterator_transliterate('Any-Latin; Latin-ASCII', ...)` one line above shows the original
 * intent, and `متجر-سوريا` -> `mtjr-swrya` would be friendlier than `store-a1b2c3`. But it needs
 * the `intl` extension and it is a product decision about what a merchant's URL looks like —
 * engineering must not pick a URL scheme. Carried as an owner question; the fallback is the
 * conservative fix that stops the breakage without choosing an aesthetic.
 *
 * EXISTING STORES ARE UNAFFECTED: `stores.slug` is stored, and this function only mints new ones.
 */
class ArabicStoreNameSlugTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array{0:string}> store names a Syrian merchant would really type */
    public static function arabicNames(): array
    {
        return [
            'two words' => ['متجر سوريا'],
            'single word' => ['مطعم'],
            'pharmacy' => ['صيدلية الشام'],
            'with a number' => ['مقهى 24'],
        ];
    }

    #[DataProvider('arabicNames')]
    public function test_an_arabic_store_name_yields_a_usable_slug(string $name): void
    {
        $slug = User::slugs($name);

        $this->assertNotSame('', (string) $slug,
            'an Arabic store name produced an EMPTY slug. stores.slug is the storefront routing '
            . 'segment, so the store\'s URL becomes //home.');

        $this->assertMatchesRegularExpression('/[a-z0-9]/', (string) $slug, sprintf(
            'the name %s produced the slug "%s", which holds no alphanumeric character at all. '
            . 'Every Arabic-named store gets the same one until the collision suffix separates '
            . 'them. slugs() keeps Arabic in its Unicode pass and then strips it in an ASCII-only '
            . 'pass, with the empty() fallback stranded between the two.',
            $name,
            $slug
        ));

        /* And it must be routable: no spaces, no slashes, not just punctuation. */
        $this->assertSame($slug, trim((string) $slug),
            'the slug has leading or trailing whitespace');
        $this->assertStringNotContainsString('/', (string) $slug);
    }

    /**
     * TWO DIFFERENT ARABIC NAMES MUST NOT COLLAPSE TO THE SAME SLUG — the consequence a merchant
     * actually notices, because the second store is then reachable only at `-1`.
     */
    public function test_two_different_arabic_names_do_not_collapse_to_one_slug(): void
    {
        $merchant = User::forceCreate([
            'name' => 'Slug Merchant', 'email' => 'slug-merchant@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $first = User::slugs('متجر سوريا');

        Store::create([
            'name' => 'متجر سوريا', 'email' => 'first-ar@example.test',
            'slug' => $first, 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'ar',
        ]);

        $second = User::slugs('صيدلية الشام');

        $this->assertNotSame($first, $second,
            'two unrelated Arabic store names minted the same slug');

        /*
         * AND THE SECOND IS NOT MERELY THE FIRST WITH A SUFFIX. `-` then `-1` would satisfy
         * assertNotSame above while still meaning "every Arabic store is called hyphen". This is
         * the assertion that distinguishes a real slug from a collision counter.
         */
        $this->assertNotSame($first . '-1', $second,
            'the second Arabic store got the first store\'s slug with a collision suffix, so both '
            . 'are really the same meaningless slug');
    }

    /** CONTROL: an ASCII name is untouched. The fix must not change what already worked. */
    public function test_an_ascii_name_still_yields_its_own_slug(): void
    {
        $this->assertSame('store-syria', User::slugs('Store Syria'));
        $this->assertSame('damascus-books', User::slugs('Damascus Books'));
    }

    /** CONTROL: a mixed name keeps its Latin part rather than being replaced wholesale. */
    public function test_a_mixed_name_keeps_its_latin_part(): void
    {
        $slug = User::slugs('متجر Syria');

        $this->assertStringContainsString('syria', $slug,
            'the Latin part of a mixed name was discarded, so the merchant lost the only '
            . 'recognisable half of their own URL');
    }
}
