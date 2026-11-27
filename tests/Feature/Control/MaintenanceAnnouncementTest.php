<?php

namespace Tests\Feature\Control;

use App\Http\Middleware\PlatformOperatorOnly;
use App\Models\Customer;
use App\Models\PlatformControlChange;
use App\Models\PlatformMaintenanceAnnouncement;
use App\Models\User;
use App\Support\Control\MaintenanceAnnouncements;
use App\Support\Control\PlatformChangeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `CTRL-003`, LIMB TWO — "إعلانات صيانة" : MAINTENANCE ANNOUNCEMENTS.
 * =================================================================================================
 *
 * *"سياسات عامة وإعلانات صيانة وسجل تغييرات"* —
 * `docs/development/master-plan/DECISION-ID-REGISTER.md:251`, Phase 06 §06-H.
 *
 * MEASURED ABSENT BEFORE THIS WORK, and "absent" is the measurement: Laravel's own
 * `PreventRequestsDuringMaintenance` is `php artisan down` — an outage, not an announcement, not
 * reachable from any operator screen, and it REFUSES the request rather than telling anybody
 * anything. There was no announcement table, model, screen or banner.
 *
 * -------------------------------------------------------------------------------------------------
 * THE FOUR SITUATIONS, AND WHY THREE OF THEM NEEDED THEIR OWN CASE.
 *
 * A draft, a published announcement whose window has not opened, and a published one whose window
 * has closed ALL show a merchant nothing — so a test that only drives "published and in window"
 * versus "not published" cannot tell a working window from one that is ignored. Each of the four is
 * driven, and the live case is driven in BOTH directions (shown, and not shown) so "nothing is
 * rendered" can never pass because the whole banner is broken.
 *
 * THE ABSENCE IS ASSERTED BY A MARKER, NOT BY A STATUS. The banner is included from
 * `layouts/app.blade.php`, so it is on every merchant screen: "the page rendered" is true whether
 * or not it is there, and CLAUDE.md records that a gate's redirect to the dashboard is HTTP 200
 * "which a status-code assertion photographs as the screen". The component emits an HTML comment
 * after its closing tag — an HTML one and not a Blade one, because a Blade comment never reaches
 * the response.
 */
class MaintenanceAnnouncementTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    private const ROUTES = [
        'platform.maintenance.index' => ['GET', '/platform/maintenance'],
        'platform.maintenance.apply' => ['POST', '/platform/maintenance'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `APP_DEBUG` OFF. CLAUDE.md: with it on, the debug bar's badges are part of the rendered
         * page and a harness measures markup that is not in the product. This file asserts on
         * markup, so the same hazard applies.
         */
        config(['app.debug' => false]);
    }

    private function operator(array $attributes = []): User
    {
        $operator = $this->makePlatformAdmin(array_merge(['name' => 'Platform Operator'], $attributes));
        $this->makeStore($operator, ['default_language' => 'en']);

        (new User)->flushCache();
        Cache::forget('admin_details');

        return User::find($operator->id);
    }

    /** A merchant with a store, signed in, so the panel layout renders for them. */
    private function merchant(?string $language = null): User
    {
        [$merchant] = $this->makeTenant();

        if ($language !== null) {
            $merchant->language = $language;
            $merchant->save();
            (new User)->flushCache();
        }

        return User::find($merchant->id);
    }

    /**
     * The banner's own markup as a merchant sees it, or '' when it is not rendered at all.
     *
     * DRIVEN THROUGH `/dashboard` BECAUSE THAT IS WHERE THE LAYOUT IS. The component is included
     * from `layouts/app.blade.php`, so asserting on it means rendering a real panel page as a real
     * principal — a direct call to the component would prove the component works and nothing about
     * whether it reaches a merchant.
     */
    private function banner(User $principal): string
    {
        $response = $this->actingAs($principal)->get('/dashboard');

        $response->assertOk();

        $body = (string) $response->getContent();

        $from = strpos($body, 'ss-maintenance-notice');
        $to = $from === false ? false : strpos($body, 'ss-maintenance-notice-end', $from);

        if ($from === false || $to === false) {
            return '';
        }

        return substr($body, $from, $to - $from);
    }

    private function announcement(array $attributes = []): PlatformMaintenanceAnnouncement
    {
        return PlatformMaintenanceAnnouncement::create(array_merge([
            'title_en' => 'Scheduled maintenance tonight',
            'title_ar' => 'صيانة مجدولة الليلة',
            'body_en' => 'Checkout will be unavailable for twenty minutes.',
            'body_ar' => 'سيتوقف الدفع لمدة عشرين دقيقة.',
            'severity' => PlatformMaintenanceAnnouncement::SEVERITY_NOTICE,
            'is_published' => true,
            'starts_at' => null,
            'ends_at' => null,
        ], $attributes));
    }

    // ---------------------------------------------------------------------------------------------
    // the banner, and the four situations
    // ---------------------------------------------------------------------------------------------

    /**
     * NOTHING LIVE MEANS NOTHING RENDERED — not an empty wrapper, not a margin.
     *
     * This is the first requirement rather than a nicety: the component is on all 334 merchant
     * screens, so any markup at all with nothing to say would be a layout change to every one of
     * them, to be re-measured in two themes and two directions, in exchange for nothing.
     */
    #[Test]
    public function test_a_merchant_sees_no_banner_when_nothing_is_live(): void
    {
        $this->assertSame(
            '',
            $this->banner($this->merchant()),
            'The maintenance banner rendered with no live announcement. It is included from the '
            . 'layout, so an empty wrapper is a spacing change on every merchant screen.'
        );
    }

    /** THE CONTROL for every absence assertion in this file: a live announcement IS shown. */
    #[Test]
    public function test_a_merchant_sees_a_live_announcement(): void
    {
        $this->announcement();

        $banner = $this->banner($this->merchant('en'));

        $this->assertNotSame('', $banner,
            'A published announcement with no window at either end did not reach a merchant, so '
            . 'every "not shown" case in this file is satisfied by a banner that never renders.');

        $this->assertStringContainsString('Scheduled maintenance tonight', $banner);
        $this->assertStringContainsString('Checkout will be unavailable for twenty minutes.', $banner);
    }

    #[Test]
    public function test_a_draft_reaches_nobody(): void
    {
        $this->announcement(['is_published' => false]);

        $this->assertSame(
            '',
            $this->banner($this->merchant()),
            'An unpublished draft reached a merchant. The whole point of writing first and '
            . 'publishing second is that a half-typed notice cannot.'
        );
    }

    #[Test]
    public function test_an_announcement_whose_window_has_not_opened_reaches_nobody(): void
    {
        $this->announcement(['starts_at' => Carbon::now()->addDay()]);

        $this->assertSame(
            '',
            $this->banner($this->merchant()),
            'A scheduled announcement was shown before its window opened. `is_published` and the '
            . 'window are different questions; if only the flag is read, scheduling does nothing.'
        );
    }

    #[Test]
    public function test_an_announcement_whose_window_has_closed_reaches_nobody(): void
    {
        $this->announcement(['ends_at' => Carbon::now()->subDay()]);

        $this->assertSame(
            '',
            $this->banner($this->merchant()),
            'An expired announcement was still being shown. A banner about last week\'s '
            . 'maintenance is the thing merchants learn to ignore.'
        );
    }

    /** A window that straddles now, from both sides, so neither bound is ignored. */
    #[Test]
    public function test_an_announcement_inside_its_window_reaches_a_merchant(): void
    {
        $this->announcement([
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->addHour(),
        ]);

        $this->assertStringContainsString(
            'Scheduled maintenance tonight',
            $this->banner($this->merchant('en')),
            'An announcement whose window is open right now did not reach a merchant, so one of '
            . 'the two bounds is being compared the wrong way round.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the language, and the fallback
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_an_arabic_merchant_reads_the_arabic_wording(): void
    {
        $this->announcement();

        $banner = $this->banner($this->merchant('ar'));

        $this->assertStringContainsString('صيانة مجدولة الليلة', $banner,
            'An Arabic merchant was not shown the Arabic title.');
        $this->assertStringNotContainsString('Scheduled maintenance tonight', $banner,
            'An Arabic merchant was shown the ENGLISH title as well, so the resolver is printing '
            . 'both languages rather than choosing one.');
    }

    /**
     * THE FALLBACK, IN BOTH DIRECTIONS, AND IT IS THE POINT OF THE DESIGN.
     *
     * An operator writing at 2am will not always write both languages. An Arabic merchant seeing
     * NOTHING about tonight's outage is worse than seeing it in English — so the resolver falls
     * back. A test that only drove the Arabic-missing case would pass against a resolver that
     * always returned English, which is why the English-missing case is here too.
     */
    #[Test]
    public function test_a_reader_whose_language_is_missing_is_shown_the_other_one(): void
    {
        $this->announcement(['title_ar' => null, 'body_ar' => null]);

        $this->assertStringContainsString(
            'Scheduled maintenance tonight',
            $this->banner($this->merchant('ar')),
            'An Arabic merchant was shown nothing at all because the Arabic wording was missing. '
            . 'Seeing it in English is worse than seeing it in Arabic and better than not knowing '
            . 'the platform is going down.'
        );

        PlatformMaintenanceAnnouncement::query()->delete();
        $this->announcement(['title_en' => null, 'body_en' => null]);

        $this->assertStringContainsString(
            'صيانة مجدولة الليلة',
            $this->banner($this->merchant('en')),
            'An English merchant was shown nothing because the English wording was missing -- so '
            . 'the "fallback" is really just "always English", and the Arabic case above passed '
            . 'for the wrong reason.'
        );
    }

    /** With no title in EITHER language there is genuinely nothing to show, and nothing is. */
    #[Test]
    public function test_an_announcement_with_no_title_in_either_language_is_skipped(): void
    {
        $this->announcement(['title_en' => null, 'title_ar' => null]);

        $this->assertSame(
            '',
            $this->banner($this->merchant()),
            'An announcement with no title in either language rendered an empty banner. It is a '
            . 'published row that reaches nobody and looks configured -- the shape this workstream '
            . 'refuses at every door.'
        );
    }

    /**
     * URGENT BEFORE QUIET, AND THE ALPHABET IS THE WRONG WAY ROUND.
     *
     * A merchant with two live announcements reads the top one. `severity` is a string, and
     * `notice` sorts BEFORE `urgent` alphabetically — so an implementation that leaned on the
     * column's natural order would put the quiet one on top, which is exactly the one they can
     * afford to miss. The quiet one is created SECOND here so that newest-first would also put it
     * on top, and only a real severity ordering passes.
     */
    #[Test]
    public function test_an_urgent_announcement_is_shown_above_a_quiet_one(): void
    {
        $this->announcement([
            'title_en' => 'Urgent: checkout is down',
            'title_ar' => 'عاجل: الدفع متوقف',
            'severity' => PlatformMaintenanceAnnouncement::SEVERITY_URGENT,
        ]);

        $this->announcement([
            'title_en' => 'A quiet notice written later',
            'title_ar' => 'تنويه هادئ كُتب لاحقًا',
            'severity' => PlatformMaintenanceAnnouncement::SEVERITY_NOTICE,
        ]);

        $banner = $this->banner($this->merchant('en'));

        $urgent = strpos($banner, 'Urgent: checkout is down');
        $quiet = strpos($banner, 'A quiet notice written later');

        $this->assertNotFalse($urgent, 'The urgent announcement is not on the page at all.');
        $this->assertNotFalse($quiet, 'The quiet announcement is not on the page at all.');

        $this->assertLessThan(
            $quiet,
            $urgent,
            'The quiet announcement is above the urgent one. `notice` sorts before `urgent` '
            . 'alphabetically and it was written later, so BOTH a natural column order and a plain '
            . 'newest-first would get this wrong -- which is why it is asserted.'
        );
    }

    /** An unrecognised severity reads as the QUIETER one, which is the opposite of the flag's rule. */
    #[Test]
    public function test_a_severity_the_code_does_not_recognise_reads_as_the_quiet_one(): void
    {
        $row = $this->announcement(['severity' => PlatformMaintenanceAnnouncement::SEVERITY_URGENT]);

        DB::table('platform_maintenance_announcements')->where('id', $row->id)->update(['severity' => 'urgen']);

        $live = MaintenanceAnnouncements::live('en');

        $this->assertSame(
            PlatformMaintenanceAnnouncement::SEVERITY_NOTICE,
            $live[0]['severity'] ?? null,
            'A truncated severity read as `urgent`. Production runs non-strict sql_mode, so '
            . 'truncation is the real shape -- and a corrupted column shouting at every merchant '
            . 'on the platform is the worse of the two directions. (This is deliberately the '
            . 'OPPOSITE of PlatformFeatureFlag::resolvedState(), which fails closed because an '
            . 'availability control must.)'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the operator surface
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_both_routes_resolve_to_this_controller_and_carry_the_operator_gate(): void
    {
        foreach (self::ROUTES as $name => [$verb, $uri]) {
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $verb));

            $this->assertSame($name, $route->getName(),
                $verb . ' ' . $uri . ' resolves to `' . (string) $route->getName() . '`. The public '
                . 'group earlier in routes/web.php owns `platform/{literal}` for seven page names, '
                . 'and Laravel matches in declaration order.');

            $this->assertStringContainsString(
                \App\Http\Controllers\PlatformMaintenanceController::class,
                (string) $route->getActionName()
            );

            $carries = false;

            foreach (array_filter($route->middleware(), 'is_string') as $middleware) {
                if (str_contains($middleware, 'PlatformOperatorOnly')) {
                    $carries = true;
                }
            }

            $this->assertTrue($carries, $verb . ' ' . $uri . ' does not carry `PlatformOperatorOnly`.');
        }
    }

    /** PREMISE: the gate is real and the operator is admitted, so a 403 is a refusal. */
    #[Test]
    public function test_premise_the_gate_refuses_a_merchant_and_admits_the_operator(): void
    {
        $this->assertSame(403, $this->actingAs($this->merchant())->get('/platform/maintenance')->getStatusCode());
        $this->assertSame(200, $this->actingAs($this->operator())->get('/platform/maintenance')->getStatusCode());
    }

    /**
     * THE HANDLER'S OWN CHECK, WITH THE ROUTE GATE REMOVED — the only way to see it at all.
     *
     * EXACTLY 403, not `assertNotSame(200, ...)`: without the `instanceof User` clause the
     * `Customer` reaches the handler and raises a 500, and a 500 is not a refusal.
     */
    #[Test]
    public function test_a_customer_typed_super_admin_is_refused_by_the_handler(): void
    {
        [, $store] = $this->makeTenant();

        $customer = Customer::create([
            'first_name' => 'Imp',
            'last_name' => 'Ostor',
            'email' => 'impostor' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'type' => 'super admin',
        ]);

        $this->assertSame('super admin', (string) $customer->fresh()->getAttribute('type'),
            'premise: `type` is fillable on Customer and the value persisted.');

        foreach (self::ROUTES as [$verb, $uri]) {
            $response = $this->withoutMiddleware(PlatformOperatorOnly::class)
                ->actingAs($customer, 'customers')
                ->call($verb, $uri, ['action' => 'withdraw', 'id' => 1]);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                $verb . ' ' . $uri . ' answered ' . $response->getStatusCode() . ' to a Customer '
                . 'typed `super admin` once the route gate was removed. The handler must check the '
                . 'principal\'s CLASS before its `type` means anything.'
            );
        }
    }

    #[Test]
    public function test_the_operator_can_write_publish_and_withdraw_an_announcement(): void
    {
        $operator = $this->operator();
        $merchant = $this->merchant('en');

        $this->actingAs($operator)->post(route('platform.maintenance.apply'), [
            'action' => 'save',
            'title_en' => 'Scheduled maintenance tonight',
            'title_ar' => 'صيانة مجدولة الليلة',
            'body_en' => 'Checkout will be unavailable for twenty minutes.',
            'severity' => PlatformMaintenanceAnnouncement::SEVERITY_URGENT,
        ]);

        $row = DB::table('platform_maintenance_announcements')->first();

        $this->assertNotNull($row, 'The announcement was not written. The redirect is a 302 either '
            . 'way, so the stored row is the only thing that says which branch ran.');

        $this->assertSame(
            0,
            (int) $row->is_published,
            'A newly written announcement was published immediately. It must be a DRAFT: a '
            . 'half-typed notice reaching every merchant the moment the operator tabbed away is '
            . 'the failure the two-step exists to prevent.'
        );

        $this->assertSame('', $this->banner($merchant), 'A draft reached a merchant.');

        $this->actingAs($operator)->post(route('platform.maintenance.apply'), [
            'action' => 'publish', 'id' => $row->id,
        ]);

        $this->assertStringContainsString(
            'Scheduled maintenance tonight',
            $this->banner($merchant),
            'Publishing through the screen did not reach a merchant.'
        );

        $this->actingAs($operator)->post(route('platform.maintenance.apply'), [
            'action' => 'withdraw', 'id' => $row->id,
        ]);

        $this->assertSame('', $this->banner($merchant), 'Withdrawing did not stop the banner.');

        /*
         * `CTRL-004`'s SHAPE APPLIED HERE: withdrawing is availability, so the wording and the
         * dates survive and it can be published again. Asserted on the stored columns, because
         * "the banner is gone" is equally true of a deleted row.
         */
        $kept = DB::table('platform_maintenance_announcements')->where('id', $row->id)->first();

        $this->assertNotNull($kept, 'Withdrawing DELETED the announcement. What the platform said, '
            . 'and when, is the record somebody asks for after an incident.');
        $this->assertSame('Scheduled maintenance tonight', (string) $kept->title_en);
        $this->assertSame('صيانة مجدولة الليلة', (string) $kept->title_ar);
    }

    /**
     * THE OPERATOR IS TOLD WHICH OF THE FOUR SITUATIONS AN ANNOUNCEMENT IS IN.
     *
     * Three of the four show a merchant nothing — an unpublished draft, a published announcement
     * whose window has not opened, and a published one whose window has closed — so a screen that
     * printed only `is_published` would answer "published" to an operator asking why nobody can see
     * it. Each of the four is asserted, through `all()`, which is what the screen renders.
     */
    #[Test]
    public function test_the_screen_distinguishes_a_draft_a_schedule_a_live_one_and_an_ended_one(): void
    {
        $draft = $this->announcement(['title_en' => 'A draft', 'is_published' => false]);
        $scheduled = $this->announcement(['title_en' => 'Scheduled', 'starts_at' => Carbon::now()->addDay()]);
        $live = $this->announcement(['title_en' => 'Live now']);
        $ended = $this->announcement(['title_en' => 'Over', 'ends_at' => Carbon::now()->subDay()]);

        $states = collect(MaintenanceAnnouncements::all())->pluck('state', 'id')->all();

        $this->assertSame(
            [
                $draft->id => 'draft',
                $scheduled->id => 'scheduled',
                $live->id => 'live',
                $ended->id => 'ended',
            ],
            [
                $draft->id => $states[$draft->id] ?? null,
                $scheduled->id => $states[$scheduled->id] ?? null,
                $live->id => $states[$live->id] ?? null,
                $ended->id => $states[$ended->id] ?? null,
            ],
            'The screen cannot tell the four situations apart. An operator asking "why can\'t they '
            . 'see it" has to be told WHICH of the three invisible cases it is, not just that it '
            . 'is published.'
        );
    }

    /** Publishing or withdrawing something that is not there says so rather than reporting success. */
    #[Test]
    public function test_publishing_an_announcement_that_does_not_exist_says_so(): void
    {
        $operator = $this->operator();

        foreach (['publish', 'withdraw'] as $action) {
            $this->actingAs($operator)->post(route('platform.maintenance.apply'), [
                'action' => $action, 'id' => 987654,
            ])->assertSessionHas('error', __('That announcement no longer exists.'));
        }

        $this->assertFalse(MaintenanceAnnouncements::publish(987654));
        $this->assertFalse(MaintenanceAnnouncements::withdraw(987654));
    }

    #[Test]
    public function test_an_announcement_with_no_title_at_all_is_refused_by_the_screen(): void
    {
        $this->actingAs($this->operator())->post(route('platform.maintenance.apply'), [
            'action' => 'save', 'body_en' => 'A body with no title.',
        ])->assertSessionHas('error');

        $this->assertSame(
            0,
            (int) DB::table('platform_maintenance_announcements')->count(),
            'A titleless announcement was stored. `live()` skips it, so it would be a published '
            . 'row that reaches nobody while looking configured.'
        );
    }

    /**
     * AN OPERATOR CAN CLEAR A WINDOW, which is a normal action and had nothing asserting it.
     *
     * Changing a scheduled announcement to open-ended means submitting two empty date boxes. The
     * chain that makes that work is the framework's own `ConvertEmptyStringsToNull`, which is in
     * the DEFAULT global stack (`Foundation/Configuration/Middleware.php:461`) and which
     * `bootstrap/app.php` does not remove — so `''` reaches the handler as null, `nullable|date`
     * accepts it and the cast stores null. Measured rather than assumed, because if that
     * middleware were ever removed the empty string would reach a `datetime` cast and the
     * operator would get a 500 while trying to clear a date.
     */
    #[Test]
    public function test_an_operator_can_clear_a_window_by_emptying_both_dates(): void
    {
        $operator = $this->operator();

        $row = $this->announcement([
            'starts_at' => Carbon::now()->addDay(),
            'ends_at' => Carbon::now()->addDays(2),
        ]);

        $this->assertNotNull($row->fresh()->starts_at, 'The fixture has no window to clear.');

        $this->actingAs($operator)->post(route('platform.maintenance.apply'), [
            'action' => 'save',
            'id' => $row->id,
            'title_en' => 'Now open-ended',
            'starts_at' => '',
            'ends_at' => '',
        ]);

        $stored = DB::table('platform_maintenance_announcements')->where('id', $row->id)->first();

        $this->assertNull($stored->starts_at,
            'Emptying the start date did not clear it, so an operator cannot change a scheduled '
            . 'announcement to an open-ended one.');
        $this->assertNull($stored->ends_at, 'Emptying the end date did not clear it.');
        $this->assertSame('Now open-ended', (string) $stored->title_en,
            'The save did not land at all, so the two null assertions above are satisfied by a '
            . 'request that was refused.');
    }

    #[Test]
    public function test_a_window_that_ends_before_it_starts_is_refused(): void
    {
        $this->actingAs($this->operator())->post(route('platform.maintenance.apply'), [
            'action' => 'save',
            'title_en' => 'An impossible window',
            'starts_at' => Carbon::now()->addDay()->format('Y-m-d\TH:i'),
            'ends_at' => Carbon::now()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('ends_at');

        $this->assertSame(0, (int) DB::table('platform_maintenance_announcements')->count(),
            'A window that can never open was stored, so the operator would discover it by '
            . 'noticing that no merchant ever saw the notice.');
    }

    /**
     * THERE IS NO DELETE, AND THE ABSENCE IS ASSERTED RATHER THAN LEFT TO BE NOTICED.
     *
     * Withdrawing unpublishes and keeps everything, because what the platform published and when is
     * the record somebody asks for afterwards — `CTRL-004`'s shape applied to this surface. A later
     * reader adding a `destroy` because the screen "obviously needs one" reddens this.
     */
    #[Test]
    public function test_there_is_no_way_to_delete_an_announcement(): void
    {
        $row = $this->announcement();

        $this->actingAs($this->operator())->post(route('platform.maintenance.apply'), [
            'action' => 'delete', 'id' => $row->id,
        ])->assertSessionHasErrors('action');

        $this->assertSame(1, (int) DB::table('platform_maintenance_announcements')->count());

        /*
         * READ WITHOUT COMMENTS. This controller's docblock says the words "there is no destroy at
         * all", and a census that reads prose about the thing it measures is the failure CLAUDE.md
         * records eight times. `StripsSourceComments` takes a path relative to the repository root.
         */
        $code = $this->sourceWithoutComments('app/Http/Controllers/PlatformMaintenanceController.php');

        $this->assertStringNotContainsString('->delete()', $code,
            'The maintenance controller deletes something. Withdrawing is the operation; deleting '
            . 'an announcement destroys the record of what the platform told its merchants.');
        $this->assertStringNotContainsString('forceDelete', $code);
    }

    // ---------------------------------------------------------------------------------------------
    // the `xss` exemption, both halves
    // ---------------------------------------------------------------------------------------------

    /**
     * A TITLE CONTAINING `javascript` OR `alert` IS PUBLISHED AS TYPED.
     *
     * This route is the strongest instance in this change of `platform/site-content`'s case: its
     * whole purpose is to carry prose about tonight's maintenance, and `XSS` deletes
     * `['&lt;', '&gt;', 'javascript', 'alert']` from every string it handles. "We are fixing the
     * javascript error on the checkout page" would publish, to every merchant on the platform, as
     * "We are fixing the  error on the checkout page" — with nothing anywhere to say it happened.
     */
    #[Test]
    public function test_prose_containing_a_stripped_substring_is_published_as_typed(): void
    {
        /*
         * `XSS::STRIPPED_SUBSTRINGS` is READ, not retyped. `CredentialSanitisationTest`'s own
         * docblock records the trap: a premise that re-declares those four strings stays green
         * while the thing it guards silently stops being guarded.
         */
        $this->assertContains('javascript', \App\Http\Middleware\XSS::STRIPPED_SUBSTRINGS,
            'The XSS sweep no longer strips `javascript`, so this test no longer measures '
            . 'anything. Check whether the withoutMiddleware(\'xss\') is still needed at all '
            . 'before deleting this case.');

        $prose = 'We are fixing the javascript error on the checkout page; no alert is needed.';

        $this->actingAs($this->operator())->post(route('platform.maintenance.apply'), [
            'action' => 'save', 'title_en' => 'Checkout fix tonight', 'body_en' => $prose,
        ]);

        $this->assertSame(
            $prose,
            (string) DB::table('platform_maintenance_announcements')->value('body_en'),
            'The announcement was mangled on the way in. The operator wrote one sentence and the '
            . 'platform stored another, silently -- and would have published it to every merchant.'
        );
    }

    /** AND DROPPING `xss` DID NOT OPEN AN OUTPUT HOLE, which is the other half of the decision. */
    #[Test]
    public function test_markup_in_an_announcement_is_escaped_in_the_merchant_banner(): void
    {
        $operator = $this->operator();
        $merchant = $this->merchant('en');

        $this->actingAs($operator)->post(route('platform.maintenance.apply'), [
            'action' => 'save',
            'title_en' => '<script>alert(1)</script>',
            'body_en' => '<img src=x onerror="alert(1)">',
        ]);

        $id = (int) DB::table('platform_maintenance_announcements')->value('id');

        $this->actingAs($operator)->post(route('platform.maintenance.apply'), ['action' => 'publish', 'id' => $id]);

        /* THE PREMISE: the sweep really is off, so the title arrived with its angle brackets. */
        $this->assertSame(
            '<script>alert(1)</script>',
            (string) DB::table('platform_maintenance_announcements')->value('title_en'),
            'The title did not arrive intact, so the escaping assertion below is satisfied by the '
            . 'input sweep rather than by the view -- and the sweep is deliberately off here.'
        );

        $banner = $this->banner($merchant);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $banner,
            'An operator-authored title was rendered as markup on every merchant\'s panel page. '
            . 'With `xss` off on the write route, Blade\'s `{{ }}` is the only thing standing '
            . 'between a stored announcement and the browser.');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $banner,
            'The title is neither rendered nor escaped, so it has gone missing -- which would make '
            . 'the assertion above pass for the wrong reason.');
        $this->assertStringNotContainsString('onerror="alert(1)"', $banner,
            'The body was rendered as markup.');
    }

    // ---------------------------------------------------------------------------------------------
    // the change log
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_writing_publishing_and_withdrawing_are_each_on_the_record(): void
    {
        $operator = $this->operator();
        $row = $this->announcement(['is_published' => false]);

        MaintenanceAnnouncements::publish($row->id, $operator);
        MaintenanceAnnouncements::withdraw($row->id, $operator);

        $entries = PlatformChangeLog::forSubjectType(PlatformChangeLog::SUBJECT_ANNOUNCEMENT);

        $this->assertSame(
            [PlatformChangeLog::ACTION_WITHDRAWN, PlatformChangeLog::ACTION_PUBLISHED],
            $entries->pluck('action')->all(),
            'Publishing and withdrawing are not both on the record, newest first. A switch nobody '
            . 'can account for afterwards is not a safe switch, which is why CTRL-003 puts the '
            . 'change log in the same sentence as the announcements.'
        );

        $this->assertSame((string) $row->id, (string) $entries[0]->subject_key,
            'The entry does not name the announcement it is about.');
        $this->assertSame($operator->id, $entries[0]->actor_id);
        $this->assertSame('Platform Operator', $entries[0]->actor_name);

        $this->assertSame(['is_published' => true], array_intersect_key($entries[0]->before, ['is_published' => true]),
            'The withdrawal entry does not record that it HAD been published.');
    }

    /** The announcement feed is this subject's only, not the whole platform's. */
    #[Test]
    public function test_the_announcement_history_does_not_show_feature_flag_changes(): void
    {
        \App\Support\Control\FeatureControl::define('some-feature', 'Some feature');

        $row = $this->announcement(['is_published' => false]);
        MaintenanceAnnouncements::publish($row->id);

        $this->assertSame(
            [PlatformChangeLog::SUBJECT_ANNOUNCEMENT],
            PlatformChangeLog::forSubjectType(PlatformChangeLog::SUBJECT_ANNOUNCEMENT)
                ->pluck('subject_type')->unique()->values()->all(),
            'The announcement history carried another subject\'s entries, so every row the '
            . 'operator reads is one they have to decide is irrelevant.'
        );

        $this->assertGreaterThan(
            1,
            PlatformControlChange::query()->count(),
            'The premise fails: there is no feature-flag entry in the table at all, so the filter '
            . 'above is not being tested.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // an absent table
    // ---------------------------------------------------------------------------------------------

    /**
     * A READ SURVIVES AN ABSENT TABLE AND A WRITE REFUSES.
     *
     * The migration is approved for development and isolated database testing only, so first-party
     * code really may run without it — and this component is on every merchant panel page, so a
     * read that could throw would 500 the whole panel.
     */
    #[Test]
    public function test_the_banner_survives_an_absent_table_and_a_write_refuses(): void
    {
        $merchant = $this->merchant();

        \Illuminate\Support\Facades\Schema::drop('platform_maintenance_announcements');

        $this->assertSame([], MaintenanceAnnouncements::live());
        $this->assertSame([], MaintenanceAnnouncements::all());

        $this->assertSame(
            '',
            $this->banner($merchant),
            'The merchant panel did not render with the announcements table absent. A banner that '
            . 'can take the panel down is worse than a banner that misses an announcement.'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('platform_maintenance_announcements is not present');

        MaintenanceAnnouncements::save(['title_en' => 'Anything']);
    }

    // ---------------------------------------------------------------------------------------------
    // bilingual, and the view's own discipline
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_the_operator_screen_renders_in_arabic(): void
    {
        $operator = $this->operator(['name' => 'المشغّل', 'language' => 'ar']);
        $this->announcement();

        $body = (string) $this->actingAs($operator)->get('/platform/maintenance')->getContent();

        $this->assertStringContainsString('إعلانات الصيانة', $body,
            'The Arabic operator sees the English heading. Check that every key this view prints '
            . 'is declared in resources/lang/ar.json -- `__()` falls back to its own key silently.');
        $this->assertStringContainsString('سجل الإعلانات', $body,
            'The history section is not translated.');
    }

    #[Test]
    public function test_the_operator_screen_renders_in_english(): void
    {
        $operator = $this->operator(['language' => 'en']);
        $this->announcement();

        $body = (string) $this->actingAs($operator)->get('/platform/maintenance')->getContent();

        $this->assertStringContainsString('Maintenance announcements', $body);
        $this->assertStringContainsString('Announcement history', $body);
    }

    /**
     * NEITHER VIEW DECLARES A COLOUR OR PINS A DIRECTION.
     *
     * A census and not a pixel measurement, and the difference is stated: a contrast reading needs
     * a running server and the project's live harness, with `APP_DEBUG` off and `.phpdebugbar`
     * excluded by ancestor. What is asserted here is the thing that MAKES the contrast correct in
     * both themes — every colour-bearing class is a design-system one, which re-themes by token —
     * and that neither view pins a side, which the rendered-Arabic test above cannot see because
     * the page still renders, in Arabic, with the spacing on the wrong side.
     *
     * Comments stripped first: both files' docblocks name the host classes while explaining that
     * neither is used, and CLAUDE.md records this census being satisfied by such prose eight times.
     */
    #[Test]
    public function test_neither_view_declares_a_colour_or_pins_a_direction(): void
    {
        foreach ([
            'resources/views/platform/maintenance.blade.php',
            'resources/views/components/ss/maintenance-notice.blade.php',
        ] as $relative) {
            $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path($relative)));

            $this->assertStringNotContainsString('style="', $code, $relative . ' carries an inline style.');
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $code,
                $relative . ' contains a hex colour; every colour must come from a '
                . 'store-syria-ds.css token or dark mode is a separate measurement nobody takes.');
            $this->assertDoesNotMatchRegularExpression('/\b(rgb|rgba|hsl|hsla)\(/', $code,
                $relative . ' declares a colour function.');

            foreach (['btn-primary', 'btn-danger', 'text-danger', 'text-success', 'bg-warning', 'badge-danger'] as $hostClass) {
                $this->assertStringNotContainsString($hostClass, $code,
                    $relative . ' uses the host theme\'s `' . $hostClass . '`, which is not '
                    . 'token-driven, so one of the two admin themes is wrong and nobody measured '
                    . 'which.');
            }

            $this->assertDoesNotMatchRegularExpression(
                '/(?:"|\s)(?:m|p)(?:s|e|l|r)-(?:[0-5]|auto)\b/',
                $code,
                $relative . ' pins a side with a directional spacing utility. The layout owns '
                . '`dir`, so a side pinned in a view is correct in exactly one of the two '
                . 'languages. Use `mb-*` (block direction) or a flex gap.'
            );

            foreach (['text-left', 'text-right', 'float-left', 'float-right', 'dir=', 'direction:'] as $sided) {
                $this->assertStringNotContainsString($sided, $code,
                    $relative . ' pins a direction with `' . $sided . '`.');
            }

            $this->assertStringNotContainsString('{!!', $code,
                $relative . ' prints something raw. Both of these views carry operator-authored '
                . 'prose from a route that drops the `xss` sweep, so Blade escaping is the control.');
        }

        /* THE PREMISE for the spacing probe: it matches what it is for and not what it is not. */
        $this->assertMatchesRegularExpression('/(?:"|\s)(?:m|p)(?:s|e|l|r)-(?:[0-5]|auto)\b/', '<p class="ms-2">');
        $this->assertDoesNotMatchRegularExpression('/(?:"|\s)(?:m|p)(?:s|e|l|r)-(?:[0-5]|auto)\b/', '<p class="mb-0">');
    }

    /** The operator view uses the admin's own layout, not the component shape that renders nothing. */
    #[Test]
    public function test_the_operator_view_uses_the_admins_own_layout(): void
    {
        $code = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(base_path('resources/views/platform/maintenance.blade.php'))
        );

        $this->assertStringContainsString("@extends('layouts.app')", $code);
        $this->assertStringNotContainsString('$slot', $code,
            'The view references $slot. `layouts/app.blade.php` contains zero $slot references, so '
            . 'this renders perfect chrome and an EMPTY content area -- HTTP 200, nothing on the '
            . 'page, in all four language/theme combinations.');
    }
}
