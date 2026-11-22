<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P4-1 -- THE ANSWER A SHOPPER GETS IS IN THE LANGUAGE OF THE PAGE THEY ARE ON.
 * =============================================================================================
 *
 * `StorefrontLocaleFollowsTheStoreTest` already pins which locale the MIDDLEWARE resolves. This
 * is the other half of P4-1's acceptance criterion -- "a storefront harness driving cart and
 * checkout in both languages, asserting the message language matches the page" -- and it is a
 * different question, because a correctly resolved locale still answers in English for any string
 * that was never translated. Both defects below were found that way, by driving the handlers
 * rather than by reading the resolver:
 *
 *   1. `Please choose a quantity of at least 1.` -- the refusal the P01-SEC-14 F1 quantity guard
 *      emits -- was declared in NEITHER `ar.json` nor `en.json`, so an Arabic storefront refused
 *      an Arabic shopper in English.
 *
 *   2. `:product added successfully.` WAS translated, into `:تم إضافة المنتج بنجاح` -- which drops
 *      the `:product` placeholder. Laravel substitutes placeholders in the TRANSLATED line, so the
 *      name was never substituted at all: measured, `ar` rendered `:تم إضافة المنتج بنجاح` with a
 *      stray leading colon where the product name belongs, on the single most frequent
 *      confirmation in the storefront. `TranslationFileIntegrityTest` now pins that class
 *      file-wide; this test pins it where a shopper actually meets it.
 *
 * WHY EVERY CASE ASSERTS TWICE. The Arabic message is compared to `ar.json`'s own value AND to the
 * English store's message. The second assertion is what catches a MISSING translation: `__()`
 * falls back to the key, so an untranslated string makes both storefronts answer identically --
 * and an assertion that only checked "the Arabic store said something" would pass on the defect.
 */
class StorefrontCartMessagesAreBilingualTest extends TestCase
{
    use RefreshDatabase;

    private Store $english;
    private Store $arabic;
    private Product $englishProduct;
    private Product $arabicProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = User::forceCreate([
            'name' => 'P4 Bilingual Merchant', 'email' => 'p4-bilingual@p4.test',
            'password' => bcrypt('x'), 'type' => 'admin', 'created_by' => 1,
            'email_verified_at' => now(), 'language' => 'ar',
        ]);

        $this->english = $this->storeFor($merchant, 'en', 'p4-cart-en');
        $this->arabic = $this->storeFor($merchant, 'ar', 'p4-cart-ar');

        $this->englishProduct = $this->productIn($this->english);
        $this->arabicProduct = $this->productIn($this->arabic);

        Cache::forget('admin_details');
        Cache::forget('super_admin_details');
    }

    private function storeFor(User $merchant, string $language, string $slug): Store
    {
        $store = Store::create([
            'name' => 'Shop ' . $slug, 'email' => $slug . '@p4.test', 'slug' => $slug,
            'theme_id' => 'stylique', 'created_by' => $merchant->id, 'is_active' => 1,
            'default_language' => $language,
        ]);

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $store;
    }

    private function productIn(Store $store): Product
    {
        return Product::forceCreate([
            'name' => 'Bilingual ' . $store->slug, 'slug' => 'bilingual-' . $store->slug,
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** The Arabic file's own value for a key -- the specification this test measures against. */
    private function arabicValue(string $key): string
    {
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertIsArray($ar);
        $this->assertArrayHasKey($key, $ar,
            'ar.json declares no value for "' . $key . '", so the Arabic storefront answers in English.');

        return (string) $ar[$key];
    }

    private function addToCart(Store $store, $productId, $qty): TestResponse
    {
        return $this->post('/' . $store->slug . '/product_cart', [
            'product_id' => $productId,
            'variant_id' => 0,
            'qty' => $qty,
        ]);
    }

    private function messageOf(TestResponse $response): string
    {
        return (string) $response->json('data.message');
    }

    /**
     * THE QUANTITY REFUSAL. Declared in neither file before this package, so both storefronts
     * answered with the English key.
     */
    public function test_the_quantity_refusal_is_in_the_language_of_the_storefront(): void
    {
        $key = 'Please choose a quantity of at least 1.';

        $en = $this->messageOf($this->addToCart($this->english, $this->englishProduct->id, 0));
        $ar = $this->messageOf($this->addToCart($this->arabic, $this->arabicProduct->id, 0));

        $this->assertSame($key, $en, 'The English storefront did not emit the refusal at all.');
        $this->assertSame($this->arabicValue($key), $ar,
            'The Arabic storefront refused the quantity in a language that is not the page it is on.');
        $this->assertNotSame($en, $ar,
            'Both storefronts answered identically, which is what an untranslated string looks like: __() fell back to the English key.');
    }

    /**
     * THE ADD CONFIRMATION, AND ITS PLACEHOLDER. The Arabic value must both be Arabic AND carry
     * the product's name -- a translation that drops `:product` passes the first test and fails
     * the shopper, which is exactly what shipped.
     */
    public function test_the_add_confirmation_is_in_the_storefronts_language_and_names_the_product(): void
    {
        $key = ':product added successfully.';

        $en = $this->messageOf($this->addToCart($this->english, $this->englishProduct->id, 1));
        $ar = $this->messageOf($this->addToCart($this->arabic, $this->arabicProduct->id, 1));

        $this->assertSame(
            str_replace(':product', $this->englishProduct->name, $key),
            $en,
            'The English confirmation is not the English string with the product substituted.'
        );

        $this->assertSame(
            str_replace(':product', $this->arabicProduct->name, $this->arabicValue($key)),
            $ar,
            'The Arabic confirmation is not ar.json\'s value with the product substituted.'
        );

        $this->assertStringContainsString($this->arabicProduct->name, $ar,
            'The Arabic confirmation does not name the product: the translation drops the :product placeholder, so Laravel never substitutes it and the shopper is told only that "the product" was added.');

        $this->assertNotSame(str_replace(':product', $this->arabicProduct->name, $key), $ar,
            'The Arabic storefront answered with the English string, so this string is untranslated.');
    }

    /** A product that is not there: the oldest message on this handler, and the control case. */
    public function test_the_missing_product_refusal_is_in_the_language_of_the_storefront(): void
    {
        $key = 'Product not found.';

        $en = $this->messageOf($this->addToCart($this->english, 99999999, 1));
        $ar = $this->messageOf($this->addToCart($this->arabic, 99999999, 1));

        $this->assertSame($key, $en);
        $this->assertSame($this->arabicValue($key), $ar,
            'The Arabic storefront reported a missing product in English.');
        $this->assertNotSame($en, $ar);
    }

    /**
     * THE PREMISE. Every assertion above rests on the two storefronts actually resolving different
     * locales; if they both resolved `en`, "the messages differ" would fail for the right reason
     * but "the Arabic value was used" would fail for the wrong one. Asserted separately so a
     * locale regression reads as a locale regression.
     */
    public function test_the_two_storefronts_resolve_different_locales(): void
    {
        $this->addToCart($this->english, $this->englishProduct->id, 1);
        $resolvedEnglish = app()->getLocale();

        $this->addToCart($this->arabic, $this->arabicProduct->id, 1);
        $resolvedArabic = app()->getLocale();

        $this->assertSame('en', $resolvedEnglish, 'The English storefront did not resolve English.');
        $this->assertSame('ar', $resolvedArabic, 'The Arabic storefront did not resolve Arabic.');
    }
}
