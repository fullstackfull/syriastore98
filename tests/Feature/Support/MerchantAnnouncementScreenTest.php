<?php

namespace Tests\Feature\Support;

use App\Http\Controllers\MerchantAnnouncementController;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * PHASE 06 `SUPPORT-002` — THE WEB HALF: THE MERCHANT'S DURABLE ANNOUNCEMENT LIST.
 * =================================================================================================
 *
 * `CTRL-003` already banners the LIVE announcement on every panel page. This screen is the thing a
 * banner cannot be: a merchant who was not signed in during the window has no other way to learn
 * what the platform said. So the ENDED ones are here, flagged, and the DRAFT and the
 * not-yet-started ones are not here at all.
 *
 * -------------------------------------------------------------------------------------------------
 * THE REFUSAL IS DRIVEN BY CALLING THE HANDLER DIRECTLY, AND THAT IS NOT BELT-AND-BRACES.
 *
 * CLAUDE.md records twice that a handler-level check behind an `abort()`ing middleware is INVISIBLE
 * to an HTTP test — `PlanController::planDisable` admitted a `Customer` typed `super admin` at HTTP
 * 200 behind a gate whose HTTP tests were green. The route's `auth` middleware would answer for a
 * `Customer` before the handler ran, so an HTTP probe here would prove nothing about the handler's
 * own `instanceof User` clause. `test_the_handler_itself_refuses_a_customer` calls the method.
 *
 * AND THE ASSERTION IS KEYED ON THE LANDED PATH, NOT THE STATUS. CLAUDE.md: a 302 from a gate and a
 * 302 from a missing row are the same status and opposite facts.
 *
 * -------------------------------------------------------------------------------------------------
 * ABSENCE IS ASSERTED BY TITLE, NEVER BY A COUNT. "the draft is not in the list" and "the list is
 * empty" look identical to an assertion that counts rows, and the second would pass against a
 * screen that renders nothing at all.
 */
class MerchantAnnouncementScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const TABLE = 'platform_maintenance_announcements';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function announcement(array $overrides = []): void
    {
        DB::table(self::TABLE)->insert(array_merge([
            'title_en' => 'Scheduled maintenance',
            'title_ar' => 'صيانة مجدولة',
            'body_en' => 'The panel will be read-only.',
            'body_ar' => 'ستكون اللوحة للقراءة فقط.',
            'severity' => 'notice',
            'starts_at' => null,
            'ends_at' => null,
            'is_published' => 1,
            'created_by' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $overrides));
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

    public function test_a_merchant_reads_the_live_announcement_on_the_list(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->announcement(['title_en' => 'Maintenance tonight']);

        $response = $this->actingAs($merchant)->get(route('merchant.announcements'));

        $response->assertStatus(200);
        $response->assertSee('Maintenance tonight', false);
        $response->assertSee('Happening now', false);
        $response->assertSee('ss-announcement-list-end', false);
    }

    public function test_an_arabic_merchant_reads_the_arabic_half_in_a_right_to_left_document(): void
    {
        [$merchant] = $this->merchantWithStore('ar');
        $this->announcement();

        $response = $this->actingAs($merchant)->get(route('merchant.announcements'));

        $response->assertStatus(200);
        $response->assertSee('صيانة مجدولة', false);
        $response->assertSee('جارٍ الآن', false);
        $this->assertStringContainsString(
            'dir="rtl"',
            (string) $response->getContent(),
            'An Arabic principal was served a document with no RTL direction. Every surface this '
            . 'slice adds has to work in both directions.'
        );
    }

    public function test_an_english_merchant_reads_the_english_half_in_a_left_to_right_document(): void
    {
        [$merchant] = $this->merchantWithStore('en');
        $this->announcement();

        $response = $this->actingAs($merchant)->get(route('merchant.announcements'));

        $body = (string) $response->getContent();

        $response->assertSee('Scheduled maintenance', false);
        $this->assertStringNotContainsString('dir="rtl"', $body);
        $this->assertStringContainsString('dir="ltr"', $body);
    }

    public function test_a_draft_is_not_on_the_screen(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->announcement(['is_published' => 0, 'title_en' => 'Half typed draft']);
        $this->announcement(['title_en' => 'Published notice']);

        $response = $this->actingAs($merchant)->get(route('merchant.announcements'));

        $response->assertSee('Published notice', false);
        $response->assertDontSee('Half typed draft', false);
    }

    public function test_an_announcement_scheduled_for_next_week_is_not_on_the_screen(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->announcement([
            'title_en' => 'Next Thursday',
            'starts_at' => Carbon::now()->addDays(4),
            'ends_at' => Carbon::now()->addDays(5),
        ]);
        $this->announcement(['title_en' => 'Happening already']);

        $response = $this->actingAs($merchant)->get(route('merchant.announcements'));

        $response->assertSee('Happening already', false);
        $response->assertDontSee('Next Thursday', false);
    }

    /**
     * THE ONE REASON THE SCREEN EXISTS BESIDE THE BANNER.
     */
    public function test_an_ended_announcement_is_kept_and_marked_ended(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->announcement([
            'title_en' => 'Last night',
            'starts_at' => Carbon::now()->subDays(2),
            'ends_at' => Carbon::now()->subDay(),
        ]);

        $response = $this->actingAs($merchant)->get(route('merchant.announcements'));

        $response->assertSee('Last night', false);
        $response->assertSee('Ended', false);
        $response->assertDontSee('Happening now', false);
    }

    public function test_an_empty_platform_says_so_rather_than_rendering_an_empty_list(): void
    {
        [$merchant] = $this->merchantWithStore();

        $response = $this->actingAs($merchant)->get(route('merchant.announcements'));

        $response->assertStatus(200);
        $response->assertSee('No announcements yet', false);
    }

    /**
     * A HOSTILE TITLE ARRIVES ESCAPED, with a premise proving it arrived at all.
     *
     * `CTRL-003`'s write routes drop `xss` deliberately — its substring stripper would turn
     * "the javascript error on the checkout page" into "the  error on the checkout page" — so
     * Blade's escaping IS the control on this screen rather than a second line of defence.
     */
    public function test_operator_prose_is_escaped_and_not_rendered_as_markup(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->announcement(['title_en' => '<script>alert(1)</script>Outage']);

        $body = (string) $this->actingAs($merchant)
            ->get(route('merchant.announcements'))->getContent();

        /* THE PREMISE: the row really reached the screen. Without this, a screen that rendered
           nothing at all would pass the escaping assertion below. */
        $this->assertStringContainsString('Outage', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    /**
     * THE HANDLER'S OWN CLAUSE, CALLED DIRECTLY. See the class docblock.
     */
    public function test_the_handler_itself_refuses_a_customer(): void
    {
        [, $store] = $this->merchantWithStore();

        $customer = Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => 'Probe',
            'email' => 'shopper' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
        ]);

        auth('customers')->login($customer);

        $response = app(MerchantAnnouncementController::class)->index();

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertSame(
            route('login'),
            $response->getTargetUrl(),
            'The handler admitted a principal that is not a `User`. CLAUDE.md records a `Customer` '
            . 'typed `super admin` reaching a real handler at HTTP 200 behind a green route gate, '
            . 'which is why this calls the method rather than the route -- and the assertion is on '
            . 'the LANDED PATH, because a 302 from a gate and a 302 from a missing row are the '
            . 'same status and opposite facts.'
        );
    }

    /**
     * AND THE PREMISE OF THAT CASE: the same call with a real merchant returns a VIEW, so the
     * refusal above is the gate rather than the handler being broken for everybody.
     */
    public function test_the_same_handler_call_serves_a_merchant_a_view(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->actingAs($merchant);

        $response = app(MerchantAnnouncementController::class)->index();

        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $response);
        $this->assertSame('announcements.index', $response->name());
    }
}
