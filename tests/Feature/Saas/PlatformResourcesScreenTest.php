<?php

namespace Tests\Feature\Saas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-195 / OPS-002 — THE PLATFORM OPERATOR'S RESOURCE MEASUREMENTS, AND ITS DOOR.
 * =================================================================================================
 *
 * The figures are facts about the machine every tenant shares, so there is no row-level fallback
 * behind this door: the gate is the whole control, exactly as on `/platform/analytics`, and it is
 * `PlatformOperatorOnly` for the same reason that screen gives — a merchant creates their own roles
 * (`RoleController::store`, reachable with the `Create Role` permission the shared `admin` role
 * carries for every self-registered merchant), so "holds permission X" is substantially
 * self-assignable and is the wrong shape of gate here. Identity is not.
 *
 * THE DIFFERENTIATING TEST IS `test_a_staff_member_holding_manage_reports_is_still_refused`, and it
 * asserts the premise first: the same principal really can open `/analytics`. Without that premise
 * the refusal below would also pass if the permission had never been granted, which is failure mode
 * 3 in the project skill's list of ways a test passes for the wrong reason.
 *
 * `config(['app.debug' => false])` before any body is read: an APP_DEBUG query log was once nearly
 * mis-filed as a disclosure in this repository.
 */
final class PlatformResourcesScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/platform/resources';

    private const PERMISSION = 'Manage Reports';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_signed_out_visitor_cannot_open_it(): void
    {
        $response = $this->get(self::URI);

        $this->assertTrue($response->isRedirect(),
            'the machine-level screen was served to a guest (status ' . $response->status() . ')');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; `auth` runs first and redirects');
    }

    public function test_a_merchant_cannot_open_it(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->get(self::URI)->assertStatus(403);
    }

    public function test_a_staff_member_cannot_open_it(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $this->actingAs($staff->fresh())->get(self::URI)->assertStatus(403);
    }

    /** The test this file exists for: a self-assignable permission must not open the machine. */
    public function test_a_staff_member_holding_manage_reports_is_still_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);

        $holder = $holder->fresh();

        $this->assertTrue($holder->isAbleTo(self::PERMISSION),
            'Premise: this principal really does hold ' . self::PERMISSION . '.');
        $this->actingAs($holder)->get('/analytics')->assertStatus(200);

        $this->actingAs($holder)->get(self::URI)->assertStatus(403);
    }

    public function test_the_platform_operator_can_open_it(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200);
    }

    /**
     * No store needs to be selected. `getCurrentStore()` answers the hardcoded literal 1 for a
     * `super admin`, so a screen that asked would present one tenant's situation as the
     * platform's; this one measures the machine and never asks.
     */
    public function test_the_operator_needs_no_store_selected(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * What the page says
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE FIGURES ON THE PAGE ARE THE MACHINE'S, asserted against a second reading taken here.
     *
     * The disk figure is the one that can be checked from the test without restating the producer:
     * `disk_total_space()` on the same path, formatted the same way, must appear in the rendered
     * body. A page that printed a plausible constant — or a merchant-scoped row count dressed as a
     * system figure, which is what every "system" number in this product was before this slice —
     * fails this.
     */
    public function test_the_page_prints_the_hosts_own_disk_figure(): void
    {
        $total = @disk_total_space(storage_path());

        if ($total === false) {
            $this->markTestSkipped('this host does not report disk space for the storage path');
        }

        $expected = \App\Services\UploadLimits::humanKb((int) round($total / 1024));

        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString($expected, $html,
            'the total size of the filesystem the application writes to is not on the page, so '
            . 'whatever is on it did not come from disk_total_space()');
        $this->assertStringContainsString(storage_path(), $html,
            'the page does not say which path it measured, so the figure cannot be re-derived');
    }

    /** Every measurement states its own source, which is what makes it checkable by hand. */
    public function test_the_page_names_the_function_behind_each_figure(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        foreach (['disk_total_space()', 'memory_get_usage(true)', '/proc/meminfo'] as $source) {
            $this->assertStringContainsString($source, $html,
                "the page prints figures without naming '{$source}' as a source");
        }
    }

    /**
     * REQUEST INPUT IS IGNORED, asserted rather than read off the current code.
     *
     * The handler reads nothing from the request today, which is the design. A probe makes that an
     * asserted property, so a later edit that starts honouring a `path` or a `store_id` reddens
     * instead of quietly turning a machine measurement into a caller-chosen one — and a `path`
     * parameter here would be an arbitrary-path disclosure on an operator screen.
     */
    public function test_a_path_in_the_query_string_changes_nothing(): void
    {
        $operator = $this->makePlatformAdmin();

        $plain = $this->actingAs($operator->fresh())->get(self::URI)->getContent();
        $probed = $this->actingAs($operator->fresh())->get(self::URI . '?path=/etc&store_id=1')->getContent();

        $this->assertStringContainsString(storage_path(), $probed,
            'the measured path changed when one was supplied in the query string');
        $this->assertSame(
            substr_count($plain, storage_path()),
            substr_count($probed, storage_path()),
            'supplying a path in the query string changed what the screen measured'
        );
        $this->assertStringNotContainsString('/etc', $probed,
            'a path from the query string reached the page');
    }

    /**
     * NO VERDICT ON THE PAGE EITHER. The producer is pinned against carrying one; this is the
     * other half, because a threshold could equally be invented in the Blade.
     */
    public function test_the_page_passes_no_judgement_on_the_figures(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $section = $this->between($html, 'ss-resource-measurements', 'ss-resource-measurements-end');

        $this->assertNotSame('', $section, 'premise: the measurement block was not found on the page');

        foreach (['badge bg-danger', 'badge bg-warning', 'badge bg-success', 'text-danger', 'text-success'] as $verdict)
        {
            $this->assertStringNotContainsString($verdict, $section,
                "the measurement block carries '{$verdict}', which states a judgement about a "
                . 'figure. OPS-003 — alerts and thresholds — is not started and no threshold has '
                . 'been set by anyone.');
        }
    }

    /**
     * BILINGUAL, DRIVEN THE WAY THE APPLICATION SETS THE LOCALE.
     *
     * `app()->setLocale('ar')` does not survive the request: `SetLocate` runs inside it and
     * resolves the locale again from session, cookie, then `auth()->user()->language`, so the page
     * comes back in English and a `__()` call after the response reads the English catalogue too —
     * which makes a bilingual test pass while rendering English on both sides. The locale therefore
     * goes on the operator's own `language` column and the expected strings are Arabic literals.
     */
    public function test_the_screen_renders_in_arabic_and_right_to_left(): void
    {
        $operator = $this->makePlatformAdmin();
        $operator->language = 'ar';
        $operator->save();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('dir="rtl"', $html,
            'premise failed: the document is not right-to-left for an Arabic operator');

        /*
         * SCOPED TO THE `<h1>`, and that is not fussiness: the heading string is printed TWICE on
         * this page, once into `page-title` and once into the breadcrumb. A whole-page assertion
         * for the Arabic heading is therefore satisfied by the breadcrumb on the title's behalf —
         * measured, by mutation: hard-coding the title section in English SURVIVED the first
         * version of this assertion. CLAUDE.md's rule is to scope the search to the block.
         */
        $this->assertStringContainsString('موارد المنصة', $this->between($html, 'ss-page-title', '</h1>'),
            'the page heading rendered in English for an Arabic operator');
        /*
         * THE BREADCRUMB LIST, not the first `ss-breadcrumb__item`: the shared component renders
         * `Home` as that first item, so a slice taken from it holds somebody else's label. The
         * `<ol>` holds Home AND this screen's own current-page item, and only the second can
         * contain this string.
         */
        $this->assertStringContainsString('موارد المنصة', $this->between($html, 'ss-breadcrumb mt-2', '</ol>'),
            'the breadcrumb rendered in English for an Arabic operator');
        $this->assertStringContainsString('متوسط التحميل (دقيقة واحدة)', $html,
            'the load-average label rendered in English for an Arabic operator');
        $this->assertStringContainsString('المساحة الكلية', $html,
            'the disk table rendered in English for an Arabic operator');
    }

    /** The English page is the control for the Arabic one: both directions, same run. */
    public function test_the_screen_renders_in_english_and_left_to_right(): void
    {
        $operator = $this->makePlatformAdmin(['language' => 'en']);

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('dir="ltr"', $html,
            'premise failed: the document is not left-to-right for an English operator');
        $this->assertStringContainsString('Platform resources', $this->between($html, 'ss-page-title', '</h1>'));
        $this->assertStringContainsString('Load average (1 minute)', $html);
        $this->assertStringContainsString('Total space', $html);
    }

    /**
     * The slice of `$html` between two markers, so an assertion cannot be satisfied elsewhere.
     *
     * THE CLOSING MARKER IS SOUGHT FROM THE OPENING ONE, not from the start of the document, and
     * that is not a detail: the first version searched the whole string for `$close` and returned
     * '' whenever an earlier, unrelated `</li>` appeared before the marker — which on this page is
     * every sidebar item. An empty slice makes a `assertStringContainsString` fail loudly, so it
     * cost a minute rather than a false pass, but the opposite case (a slice that starts before
     * the block) would not have.
     */
    private function between(string $html, string $open, string $close): string
    {
        $from = strpos($html, $open);

        if ($from === false) {
            return '';
        }

        $to = strpos($html, $close, $from);

        if ($to === false) {
            return '';
        }

        return substr($html, $from, $to - $from);
    }
}
