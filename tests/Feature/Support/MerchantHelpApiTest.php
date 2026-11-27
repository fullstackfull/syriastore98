<?php

namespace Tests\Feature\Support;

use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * PHASE 06 `SUPPORT-002` — THE FLUTTER HALF, DRIVEN THROUGH THE REAL ROUTES.
 * =================================================================================================
 *
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`'s `SUPPORT-002` row records that a case-insensitive
 * search of `routes/api.php` for `ticket|help|announce|faq` returned **zero** matches, and concludes
 * "the Flutter half has no surface to reach parity with". `GET api/admin/announcements` and
 * `GET api/admin/help-topics` are that surface. This file drives both.
 *
 * -------------------------------------------------------------------------------------------------
 * THE THREE TOKEN KINDS, BECAUSE A CUSTOMER-TOKEN PROBE CANNOT TEST THE TRAP.
 *
 * CLAUDE.md: `custom.auth` logs a token's owner into whichever guard matches the TOKENABLE CLASS,
 * so a `Customer`'s and a `DeliveryBoy`'s token both PASS it on an `api/admin` route while
 * `auth('web')` stays null. Thirty-one measured defects in this repository are a handler then
 * falling back to request input. A test that drove only a merchant token would prove nothing about
 * the door, and one that drove only a customer token would prove nothing either — so all three run.
 *
 * -------------------------------------------------------------------------------------------------
 * THE REFUSAL IS ASSERTED AS A 200 WITH `status: 0`, AND THAT ASSERTION IS THE POINT.
 *
 * CLAUDE.md: `ApiResponser::error()` answers HTTP **200** with `{status: 0}` by default, and the
 * shipped Flutter app **forces a logout on any 401**. So `assertStatus(200)` here is not laziness
 * about the refusal — it is the assertion that a driver's token hitting a help endpoint does not log
 * a merchant out of the app. The refusal is read off `status`, which is the contract the app's own
 * `apiRefused()` helper already implements.
 *
 * -------------------------------------------------------------------------------------------------
 * ISOLATION IS ASSERTED AS AN EQUALITY, NOT AS A FILTER.
 *
 * Announcements are the platform's and the table has no `store_id`, so the honest check is that two
 * merchants of two different stores receive BYTE-IDENTICAL payloads: where every principal gets the
 * same answer there is no per-tenant channel to confuse. CLAUDE.md's `null_tenant => 'open'` trap
 * cannot apply to a query with no tenant predicate to lose.
 */
class MerchantHelpApiTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const TABLE = 'platform_maintenance_announcements';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table(self::TABLE)->insert([
            'title_en' => 'Scheduled maintenance tonight',
            'title_ar' => 'صيانة مجدولة الليلة',
            'body_en' => 'The panel will be read-only for an hour.',
            'body_ar' => 'ستكون اللوحة للقراءة فقط لمدة ساعة.',
            'severity' => 'urgent',
            'starts_at' => Carbon::now()->subMinutes(5),
            'ends_at' => Carbon::now()->addHour(),
            'is_published' => 1,
            'created_by' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function merchantWithStore(string $language = 'en'): array
    {
        $merchant = $this->makeMerchant(['language' => $language]);
        $store    = $this->makeStore($merchant);

        $merchant->current_store = $store->id;
        $merchant->save();

        return [User::find($merchant->id), $store];
    }

    private function bearer(string $token)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function tokenFor(object $owner): string
    {
        return $owner->createToken('auth_token')->plainTextToken;
    }

    private function driverIn(Store $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver ' . $this->uniqueFixtureRef(),
            'email' => 'driver' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'contact' => '0100000000',
            // delivery_boys.created_by is NOT nullable.
            'created_by' => $store->created_by,
            'store_id' => $store->id,
        ]);
    }

    private function customerIn(Store $store): \App\Models\Customer
    {
        return \App\Models\Customer::forceCreate([
            'first_name' => 'Help',
            'last_name' => 'Probe',
            'email' => 'helpprobe' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
        ]);
    }

    // =============================================================================================
    // the merchant gets what the platform said
    // =============================================================================================

    public function test_a_merchant_token_is_served_the_live_announcement(): void
    {
        [$merchant] = $this->merchantWithStore();

        $response = $this->bearer($this->tokenFor($merchant))->getJson('api/admin/announcements');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('status'));
        $this->assertSame('en', $response->json('data.locale'));
        $this->assertSame(1, $response->json('data.live_count'));
        $this->assertSame('Scheduled maintenance tonight', $response->json('data.announcements.0.title'));
        $this->assertSame('urgent', $response->json('data.announcements.0.severity'));
        $this->assertTrue($response->json('data.announcements.0.is_live'));
    }

    /**
     * THE PANEL LANGUAGE COMES FROM THE PRINCIPAL, not from the request.
     */
    public function test_an_arabic_merchant_is_served_arabic_without_asking_for_it(): void
    {
        [$merchant] = $this->merchantWithStore('ar');

        $response = $this->bearer($this->tokenFor($merchant))->getJson('api/admin/announcements');

        $this->assertSame('ar', $response->json('data.locale'));
        $this->assertSame('صيانة مجدولة الليلة', $response->json('data.announcements.0.title'));
    }

    /**
     * AND THE APP MAY OVERRIDE IT, because the app's language is a DEVICE choice the server cannot
     * know. A merchant whose `users.language` still says `en` and whose app is set to Arabic must
     * not be served English.
     */
    public function test_the_app_may_ask_for_the_other_language(): void
    {
        [$merchant] = $this->merchantWithStore('en');
        $token      = $this->tokenFor($merchant);

        $response = $this->bearer($token)->getJson('api/admin/announcements?lang=ar');

        $this->assertSame('ar', $response->json('data.locale'));
        $this->assertSame('صيانة مجدولة الليلة', $response->json('data.announcements.0.title'));
    }

    /**
     * A REGIONAL TAG IS WHAT A DEVICE ACTUALLY SENDS. `ar-SY` must not fold to English.
     */
    public function test_a_regional_language_tag_resolves_to_its_base_language(): void
    {
        [$merchant] = $this->merchantWithStore('en');

        $response = $this->bearer($this->tokenFor($merchant))
            ->getJson('api/admin/announcements?lang=ar-SY');

        $this->assertSame('ar', $response->json('data.locale'));
    }

    public function test_an_unknown_language_falls_back_rather_than_failing(): void
    {
        [$merchant] = $this->merchantWithStore('en');

        $response = $this->bearer($this->tokenFor($merchant))
            ->getJson('api/admin/announcements?lang=../../etc/passwd');

        $response->assertStatus(200);
        $this->assertSame('en', $response->json('data.locale'));
    }

    // =============================================================================================
    // the door
    // =============================================================================================

    public function test_a_customer_token_passes_custom_auth_and_is_still_refused(): void
    {
        [, $store] = $this->merchantWithStore();
        $customer  = $this->customerIn($store);

        foreach (['api/admin/announcements', 'api/admin/help-topics'] as $path) {
            $response = $this->bearer($this->tokenFor($customer))->getJson($path);

            $response->assertStatus(200);
            $this->assertSame(
                0,
                $response->json('status'),
                $path . ' served a shopper. `custom.auth` logs a Customer token into the '
                . '`customers` guard, so it PASSES the middleware while `auth(\'web\')` stays null '
                . '-- which is the shape of thirty-one measured defects in this repository.'
            );
            $this->assertNull($response->json('data.announcements'));
            $this->assertNull($response->json('data.surfaces'));
        }
    }

    public function test_a_driver_token_passes_custom_auth_and_is_still_refused(): void
    {
        [, $store] = $this->merchantWithStore();
        $driver    = $this->driverIn($store);

        foreach (['api/admin/announcements', 'api/admin/help-topics'] as $path) {
            $response = $this->bearer($this->tokenFor($driver))->getJson($path);

            $response->assertStatus(200);
            $this->assertSame(0, $response->json('status'), $path . ' served a delivery driver.');
        }
    }

    /**
     * A 401 WOULD BE A USER-VISIBLE REGRESSION. See the class docblock.
     */
    public function test_no_refusal_on_these_paths_is_a_401(): void
    {
        [, $store] = $this->merchantWithStore();

        foreach ([$this->customerIn($store), $this->driverIn($store)] as $principal) {
            foreach (['api/admin/announcements', 'api/admin/help-topics'] as $path) {
                $this->bearer($this->tokenFor($principal))->getJson($path)->assertStatus(200);
            }
        }

        /* And a tokenless request is `custom.auth`'s own answer, which this slice does not change;
           it is asserted only so that a future change to that middleware is visible here. */
        $this->assertNotSame(401, $this->getJson('api/admin/announcements')->getStatusCode());
    }

    // =============================================================================================
    // isolation, as an equality
    // =============================================================================================

    public function test_two_merchants_of_two_stores_receive_identical_payloads(): void
    {
        [$first] = $this->merchantWithStore();
        [$second] = $this->merchantWithStore();

        $a = $this->bearer($this->tokenFor($first))->getJson('api/admin/announcements')->json('data');
        $b = $this->bearer($this->tokenFor($second))->getJson('api/admin/announcements')->json('data');

        $this->assertSame(
            $a,
            $b,
            'Two merchants of two different stores received different announcement payloads. An '
            . 'announcement is the PLATFORM\'s and the table has no `store_id`, so any difference '
            . 'between two principals is a per-tenant channel that should not exist.'
        );
    }

    public function test_the_payload_names_nobody(): void
    {
        [$merchant] = $this->merchantWithStore();

        $row = $this->bearer($this->tokenFor($merchant))
            ->getJson('api/admin/announcements')->json('data.announcements.0');

        foreach (['store_id', 'created_by', 'user_id', 'is_published'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $row,
                'The app payload carries `' . $forbidden . '`. Who wrote an announcement, and '
                . 'whether it is a draft, are the operator\'s business.'
            );
        }
    }

    // =============================================================================================
    // contextual help
    // =============================================================================================

    public function test_the_whole_help_map_comes_back_in_one_call(): void
    {
        [$merchant] = $this->merchantWithStore('ar');

        $response = $this->bearer($this->tokenFor($merchant))->getJson('api/admin/help-topics');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('status'));
        $this->assertSame('ar', $response->json('data.locale'));
        $this->assertSame(['en', 'ar'], $response->json('data.locales'));

        $surfaces = $response->json('data.surfaces');

        $this->assertNotSame([], $surfaces);
        $this->assertSame('overview', $surfaces[0]['surface']);
        $this->assertNotSame([], $surfaces[0]['articles']);
        $this->assertSame('ar', $surfaces[0]['articles'][0]['locale']);
    }

    public function test_one_surface_comes_back_with_its_articles(): void
    {
        [$merchant] = $this->merchantWithStore();

        $response = $this->bearer($this->tokenFor($merchant))
            ->getJson('api/admin/help-topics?surface=products');

        $this->assertSame('products', $response->json('data.surface'));
        $this->assertTrue($response->json('data.known'));
        $this->assertCount(4, $response->json('data.articles'));
        $this->assertStringContainsString('/platform/docs/', $response->json('data.articles.0.url'));
    }

    /**
     * `known` IS WHAT LETS THE APP TELL A DRIFT FROM AN EXPECTED ABSENCE.
     */
    public function test_an_unknown_surface_is_reported_as_unknown_rather_than_as_no_help(): void
    {
        [$merchant] = $this->merchantWithStore();

        $response = $this->bearer($this->tokenFor($merchant))
            ->getJson('api/admin/help-topics?surface=wallet');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('status'));
        $this->assertFalse($response->json('data.known'));
        $this->assertSame([], $response->json('data.articles'));
    }

    /**
     * THE HOSTILE SHAPES, through the real route rather than against the class.
     */
    public function test_a_hostile_surface_resolves_to_no_help_without_a_500(): void
    {
        [$merchant] = $this->merchantWithStore();
        $token      = $this->tokenFor($merchant);

        $hostile = [
            'surface=../../etc/passwd',
            'surface=products/../orders',
            'surface=' . urlencode('products"'),
            'surface=' . urlencode('../en/products/add-a-product'),
        ];

        foreach ($hostile as $query) {
            $response = $this->bearer($token)->getJson('api/admin/help-topics?' . $query);

            $response->assertStatus(200);
            $this->assertSame(
                1,
                $response->json('status'),
                $query . ' did not answer cleanly. A surface key is a lookup in a constant; it '
                . 'must never 500 and never reach a path.'
            );
            $this->assertFalse($response->json('data.known'), $query . ' was reported as known.');
            $this->assertSame([], $response->json('data.articles'), $query . ' resolved to help.');
        }
    }

    /**
     * AN ARRAY-VALUED `surface` IS THE SHAPE THAT WOULD HAVE BEEN A 500, and it is recorded here
     * as a BEHAVIOUR rather than folded into the case above, because the answer is a different
     * one.
     *
     * `?surface[]=products` makes `$request->query('surface')` an ARRAY. A string parameter
     * receiving it is a TypeError and an HTTP 500 — reachable by anybody holding a token — so the
     * handler casts BEFORE using it, and a non-string becomes the empty string. The empty string
     * means "no surface named", so this lands on the WHOLE-MAP branch: `data.surfaces` is present
     * and `data.surface` is absent. That is deliberate — a malformed selector is not a request for
     * a specific screen's help — and it is asserted so a future change to that branch is visible.
     *
     * THE FIRST DRAFT OF THIS SUITE ASSERTED THE SINGLE-SURFACE SHAPE HERE AND WENT RED on exactly
     * this, which is why the two cases are separate.
     */
    public function test_an_array_valued_surface_lands_on_the_whole_map_rather_than_a_500(): void
    {
        [$merchant] = $this->merchantWithStore();

        $response = $this->bearer($this->tokenFor($merchant))
            ->getJson('api/admin/help-topics?surface[]=products');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('status'));
        $this->assertNull($response->json('data.surface'));
        $this->assertNull($response->json('data.articles'));
        $this->assertNotSame([], $response->json('data.surfaces'));
    }

    public function test_the_limit_is_capped_rather_than_trusted(): void
    {
        [$merchant] = $this->merchantWithStore();

        $response = $this->bearer($this->tokenFor($merchant))
            ->getJson('api/admin/announcements?limit=999999');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('status'));
        $this->assertLessThanOrEqual(100, count($response->json('data.announcements')));
    }

    /**
     * A NON-INTEGER PAGE SIZE IS REFUSED BY THE PARSE, NOT COERCED.
     *
     * The tenancy skill's rule: `is_numeric` accepts `'5.9'` and rejects `'5abc'` while MySQL
     * coerces `'5abc'` to 5, so `FILTER_VALIDATE_INT` is the parse. `?limit[]=1` is the shape that
     * would otherwise be an array reaching a cast — and the measured one, because the whole-map
     * branch of `help-topics` reddened on exactly that in this suite.
     *
     * The page size has no row to confuse, so the property asserted is the weaker and honest one:
     * every shape answers cleanly and none of them 500s.
     */
    public function test_a_nonsense_limit_answers_cleanly(): void
    {
        [$merchant] = $this->merchantWithStore();
        $token      = $this->tokenFor($merchant);

        foreach (['limit=5.9', 'limit=5abc', 'limit=-3', 'limit=', 'limit[]=1', 'limit=' . urlencode('1 OR 1=1')] as $query) {
            $response = $this->bearer($token)->getJson('api/admin/announcements?' . $query);

            $response->assertStatus(200);
            $this->assertSame(
                1,
                $response->json('status'),
                $query . ' did not answer cleanly.'
            );
            $this->assertIsArray($response->json('data.announcements'), $query);
        }
    }
}
