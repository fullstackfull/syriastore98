<?php

namespace Tests\Contract;

use App\Models\User;
use App\Support\Help\ContextualHelp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `SUPPORT-002` — THE SHAPE THE SHIPPED FLUTTER APP PARSES, PINNED FIELD BY FIELD.
 * =================================================================================================
 *
 * `GET api/admin/announcements` and `GET api/admin/help-topics` are new, so nothing is installed
 * against them yet — and that is exactly when the contract is worth writing down. Repo B's
 * `announcement_model.dart` reads these keys through `apiValueAt` / `apiText` / `jsonList`, so a
 * server-side rename does not throw: it silently yields an EMPTY list or a blank title on a screen
 * whose whole job is to carry maintenance notices. There is no exception and no 404 — just a
 * merchant who is told nothing. That is what a contract test is for.
 *
 * -------------------------------------------------------------------------------------------------
 * THREE THINGS ARE PINNED, AND THE THIRD IS THE ONE A UNIT TEST COULD NOT SEE.
 *
 *   1. THE KEYS. `data.locale`, `data.live_count`, `data.announcements[]` with
 *      `id/title/body/severity/starts_at/ends_at/is_live`; and `data.surfaces[].surface` /
 *      `.articles[].{id,title,summary,url,locale,is_fallback}`.
 *
 *   2. THE TYPES. `is_live` and `is_fallback` arrive as JSON BOOLEANS and `id` as an INTEGER.
 *      `result.dart` records the measurement behind this: 132 of 353 rows red across 25 of 32
 *      response models, from a server sending a type the model did not expect, and
 *      `loyality_controller` crashing on an unquoted number assigned into a non-nullable String.
 *
 *   3. THE ENVELOPE IS `status: 1` AND THE REFUSAL IS A 200. CLAUDE.md: `ApiResponser::error()`
 *      answers HTTP 200 with `status: 0` by default, and the shipped app **forces a logout on any
 *      401**. So "the refusal is not a 401" is a CONTRACT property, not an implementation detail:
 *      a 401 on a path the app calls logs a merchant out of the app.
 *
 * `SUPPORT-002`'s surface vocabulary is pinned too. The app sends a surface KEY, which it compiles
 * in, so a key renamed on the server empties the help in every installed copy — the same hazard the
 * three order endpoints above `base_api.dart`'s tail record.
 */
class SupportHelpEndpointContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const TABLE = 'platform_maintenance_announcements';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table(self::TABLE)->insert([
            'title_en' => 'Scheduled maintenance tonight',
            'title_ar' => 'صيانة مجدولة الليلة',
            'body_en' => 'The panel will be read-only.',
            'body_ar' => 'ستكون اللوحة للقراءة فقط.',
            'severity' => 'urgent',
            'starts_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->addHour(),
            'is_published' => 1,
            'created_by' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function merchant(string $language = 'en'): User
    {
        $merchant = $this->makeMerchant(['language' => $language]);
        $store    = $this->makeStore($merchant);

        $merchant->current_store = $store->id;
        $merchant->save();

        return User::find($merchant->id);
    }

    private function asApp(User $merchant)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        );
    }

    public function test_the_announcement_envelope_and_keys_are_the_ones_the_app_reads(): void
    {
        $response = $this->asApp($this->merchant())->getJson('api/admin/announcements');

        $response->assertStatus(200);

        $body = $response->json();

        $this->assertSame(1, $body['status'], 'The app reads `status` and treats 0 as a refusal.');
        $this->assertArrayHasKey('data', $body);

        foreach (['locale', 'live_count', 'announcements'] as $key) {
            $this->assertArrayHasKey($key, $body['data'], 'data.' . $key . ' is what the app reads.');
        }

        $row = $body['data']['announcements'][0];

        $this->assertSame(
            ['id', 'title', 'body', 'severity', 'starts_at', 'ends_at', 'is_live'],
            array_keys($row),
            "The announcement row's key set has changed. Repo B's `Announcement.fromJson` reads "
            . 'each of these by name through the shape-safe helpers, so a rename does not throw -- '
            . 'it yields a blank title on the one screen that carries maintenance notices.'
        );

        $this->assertIsInt($row['id']);
        $this->assertIsString($row['title']);
        $this->assertIsString($row['severity']);
        $this->assertIsBool(
            $row['is_live'],
            '`is_live` must be a JSON boolean. The app reads `row[\'is_live\'] == true`, and the '
            . 'four-state rule (draft / scheduled / live / ended) is the SERVER\'s so the web list '
            . 'and the app cannot disagree -- a client that re-derived it from `ends_at` would also '
            . 'be wrong whenever the phone\'s clock is.'
        );
        $this->assertIsInt($body['data']['live_count']);
    }

    /**
     * THE ONE THE APP CANNOT COMPUTE ITSELF: the severity vocabulary.
     */
    public function test_the_severity_vocabulary_is_the_two_words_the_app_branches_on(): void
    {
        $response = $this->asApp($this->merchant())->getJson('api/admin/announcements');

        $this->assertContains(
            $response->json('data.announcements.0.severity'),
            ['notice', 'urgent'],
            'Repo B asks `severity == \'urgent\'` and treats everything else as the quieter badge. '
            . 'A third value would render as the quiet one silently.'
        );
    }

    public function test_the_help_topics_envelope_and_keys_are_the_ones_the_app_reads(): void
    {
        $response = $this->asApp($this->merchant())
            ->getJson('api/admin/help-topics?surface=products');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('status'));

        foreach (['locale', 'surface', 'known', 'articles'] as $key) {
            $this->assertArrayHasKey($key, $response->json('data'));
        }

        $this->assertIsBool(
            $response->json('data.known'),
            '`known` must be a JSON boolean: the app shows a DIFFERENT sentence when the server '
            . 'does not recognise the surface key it compiled in, which is the only way a drift '
            . 'between the two repositories becomes visible to anybody.'
        );

        $article = $response->json('data.articles.0');

        $this->assertSame(
            ['id', 'title', 'summary', 'url', 'locale', 'is_fallback'],
            array_keys($article)
        );

        $this->assertIsBool($article['is_fallback']);
        $this->assertIsString($article['url']);
        $this->assertNotSame('', $article['url']);
    }

    public function test_the_whole_map_call_carries_the_surface_vocabulary(): void
    {
        $response = $this->asApp($this->merchant())->getJson('api/admin/help-topics');

        $this->assertSame(
            ['locale', 'locales', 'surfaces'],
            array_keys($response->json('data'))
        );

        $this->assertSame(
            array_keys(ContextualHelp::SURFACES),
            array_column($response->json('data.surfaces'), 'surface'),
            'The surface vocabulary the app receives has changed. The app COMPILES surface keys in '
            . '(`AnnouncementScreen.helpSurface`), so a renamed key empties the help in every '
            . 'installed copy with no error anywhere -- the same hazard as a renamed endpoint.'
        );
    }

    /**
     * THE PROPERTY THAT PROTECTS AN INSTALLED APP: no refusal on these paths is a 401.
     */
    public function test_a_refusal_is_a_200_and_never_a_401(): void
    {
        $merchant = $this->merchant();
        $store    = $merchant->current_store;

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Contract',
            'last_name' => 'Probe',
            'email' => 'contract' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store,
        ]);

        foreach (['api/admin/announcements', 'api/admin/help-topics'] as $path) {
            $response = $this->withHeader(
                'Authorization',
                'Bearer ' . $customer->createToken('auth_token')->plainTextToken
            )->getJson($path);

            $this->assertSame(
                200,
                $response->getStatusCode(),
                $path . ' answered a non-merchant with a status other than 200. The shipped Flutter '
                . 'app force-logs-out on any 401, so a 401 here would log a merchant out of the '
                . 'app because somebody else\'s token reached a help endpoint.'
            );
            $this->assertSame(0, $response->json('status'));
        }
    }

    /**
     * THE LANGUAGE IS A FIRST-CLASS PART OF THIS CONTRACT, because the app's language is a DEVICE
     * choice the server cannot otherwise know.
     */
    public function test_the_app_can_select_either_language_and_the_payload_says_which(): void
    {
        $merchant = $this->merchant('en');
        $token    = $merchant->createToken('auth_token')->plainTextToken;

        foreach (['en' => 'Scheduled maintenance tonight', 'ar' => 'صيانة مجدولة الليلة'] as $lang => $title) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson('api/admin/announcements?lang=' . $lang);

            $this->assertSame($lang, $response->json('data.locale'));
            $this->assertSame($title, $response->json('data.announcements.0.title'));
        }
    }
}
