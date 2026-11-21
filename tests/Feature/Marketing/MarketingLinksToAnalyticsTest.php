<?php

namespace Tests\Feature\Marketing;

use App\Http\Middleware\ReportPermission;
use App\Services\ReportAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * MKT-001 — "An independent marketing section, LINKED TO ANALYTICS."
 * =================================================================================================
 *
 * The marketing→analytics direction of that requirement did not exist. Measured 2026-11-17 and
 * re-measured 2026-11-21: the five Marketing-menu surfaces held ZERO references to any reports,
 * analytics or finance route. `/analytics` has existed since P5-4b, so the gap was a link.
 *
 * THE INTERESTING HALF OF THIS TEST IS THE NEGATIVE ONE, and it is why the link does not use
 * `@permission`. A link to a screen the reader will be refused on arrival is worse than no link,
 * and a link HIDDEN from someone who may open the screen is the same bug seen from the other side —
 * `CompanyMenuListener` says exactly that about this very route. `/analytics` carries
 * `ReportPermission`, which admits the platform OPERATOR and the TENANT OWNER before it consults
 * Laratrust at all, so the three principals have to be driven separately:
 *
 *   - the owner, who passes on `creatorId() === id` and holds no report permission row;
 *   - a staff member who may run the marketing screens but holds NO report permission — must see
 *     no link;
 *   - the same staff member granted `Manage Reports` — must see it.
 *
 * `@permission('Manage Reports')` would have passed the middle case and FAILED the first, silently,
 * on every merchant whose seeded role predates the permission name.
 */
final class MarketingLinksToAnalyticsTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /**
     * The five Marketing-menu surfaces, with the permission each screen itself requires.
     *
     * The permission here is the SCREEN's own, taken from `CompanyMenuListener`'s Marketing group —
     * not the report permission. Granting these and withholding `Manage Reports` is what makes the
     * negative case real rather than a refusal at the door.
     */
    private const SCREENS = [
        'coupon' => ['/coupon', 'Manage Coupon'],
        'newsletter' => ['/newsletter', 'Manage Newsletter'],
        'flash sale' => ['/flash-sale', 'Manage Flash Sale'],
        'wishlist' => ['/wishlist', 'Manage Wishlist'],
        'abandoned carts' => ['/abandon-carts-handled', 'Manage Cart'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /**
     * MEASURED 2026-11-21, AND IT IS WHY THIS FIXTURE EXISTS: all five of these screens gate on
     * `isAbleTo()` ALONE -- `CouponController::couponReadRefusal()`, and a bare
     * `auth()->user()->isAbleTo('Manage X')` in `NewsletterController`, `FlashSaleController`,
     * `WishlistController` and `CartController::abandon_carts_handled`. None of them admits the
     * platform operator, and none gives the tenant owner the `creatorId() === id` pass that
     * `ReportPermission` and `StoreSettingsAccess` both give. A freshly made owner therefore gets a
     * 302 from their own coupon screen.
     *
     * In production the merchant's seeded `admin` role carries these names, so an owner passes; the
     * fixture has to say so explicitly. That inconsistency is REAL but it is not MKT-001's to fix --
     * changing owner-pass semantics on five live gates is a behaviour change, and this project has
     * already had a permission fix refuse the store owner against two tests that encoded the old
     * behaviour as the contract. It is recorded as `GAP-218` instead.
     *
     * Granting the five SCREEN permissions and withholding `Manage Reports` is also what makes the
     * owner case sharp: the owner is then admitted to the link by `creatorId() === id` and by
     * nothing else, which is exactly the principal `@permission('Manage Reports')` would have hidden
     * it from.
     */
    private function grantTheScreensOwnPermissions($user): void
    {
        foreach (self::SCREENS as [$_uri, $permission]) {
            $this->grantPermission($user, $permission);
        }
    }

    /**
     * A MARKER ONLY THE PARTIAL EMITS, because `route('analytics.index')` is NOT one.
     *
     * MEASURED, after an independent review raised it: the SIDEBAR on these same five pages
     * already emits `/analytics` for any principal holding `Manage Reports`
     * (`layouts/app` -> `partision.sidebar` -> `getMenu()` -> `menu-tree`, whose Analytics entry is
     * `route => analytics.index`, `permission => Manage Reports`). So a test that only looked for
     * that URL proved nothing for a principal who holds the row. Proved by deleting the
     * `@include` from all five views: the owner case and the Arabic case went red, and
     * `test_the_same_staff_member_granted_manage_reports_is_shown_the_link` STAYED GREEN.
     *
     * The owner and negative cases were never affected -- both run against a principal with NO
     * `Manage Reports` row, and `Menu::permits()` is `isAbleTo()` alone, so the sidebar entry is
     * filtered out for them and the partial is the only possible source. But one of the five tests
     * was passing for the wrong reason, which is the failure mode this project warns about most.
     *
     * `ti ti-chart-line` is the partial's own icon; the sidebar entry's icon is `home`. A test
     * asserts below that this marker really is absent once the include is gone.
     */
    private const PARTIAL_MARKER = 'ti ti-chart-line';

    private function body(string $uri, $as): string
    {
        $response = $this->actingAs($as)->get($uri);

        $response->assertStatus(200);

        return $response->getContent();
    }

    public function test_the_premise_that_all_five_marketing_screens_render_for_the_owner(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantTheScreensOwnPermissions($merchant);

        foreach (self::SCREENS as $name => [$uri, $_permission]) {
            $response = $this->actingAs($merchant->fresh())->get($uri);

            $this->assertSame(200, $response->status(), sprintf(
                'The %s screen answered %d for the store owner, so this file cannot measure whether '
                . 'it carries the analytics link.',
                $name,
                $response->status()
            ));
        }
    }

    public function test_every_marketing_screen_links_to_the_analytics_centre(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantTheScreensOwnPermissions($merchant);

        $this->assertFalse($merchant->fresh()->isAbleTo(ReportAccess::REPORTS),
            'Premise: this owner must NOT hold the report permission as a ROW, so that the link '
            . 'below can only be reaching them through the creatorId() owner pass -- the pass an '
            . '@permission directive would have skipped.');

        foreach (self::SCREENS as $name => [$uri, $_permission]) {
            $body = $this->body($uri, $merchant->fresh());

            $this->assertStringContainsString(route('analytics.index'), $body, sprintf(
                'The %s screen does not link to the analytics centre. MKT-001 asks for the '
                . 'marketing section to be LINKED to analytics, and this screen is one of the five '
                . 'the requirement was measured against.',
                $name
            ));

            $this->assertStringContainsString('>' . __('Analytics') . '<', $body, sprintf(
                'The %s screen has the analytics URL but no visible label for it.',
                $name
            ));

            $this->assertStringContainsString(self::PARTIAL_MARKER, $body, sprintf(
                'The %s screen has the analytics URL but not the PARTIAL that is supposed to put '
                . 'it there. The sidebar emits that URL too, so the URL alone is not evidence.',
                $name
            ));
        }
    }

    /**
     * THE NEGATIVE CASE. A staff member who runs the marketing screens but may not read reports is
     * shown no link, because following it would be refused.
     */
    public function test_a_staff_member_without_the_report_permission_is_shown_no_link(): void
    {
        [$merchant] = $this->makeTenant();

        $staff = $this->makeStaff($merchant);

        foreach (self::SCREENS as [$_uri, $permission]) {
            $this->grantPermission($staff, $permission);
        }

        $this->assertFalse(ReportAccess::permits($staff->fresh()),
            'Premise: this staff member must NOT pass the report gate, or the negative case proves '
            . 'nothing. If they do, makeStaff() has started resolving creatorId() to their own id.');

        foreach (self::SCREENS as $name => [$uri, $_permission]) {
            $body = $this->body($uri, $staff->fresh());

            $this->assertStringNotContainsString(route('analytics.index'), $body, sprintf(
                'The %s screen offered the analytics link to a staff member who would be refused '
                . 'by ReportPermission on arrival.',
                $name
            ));

            $this->assertStringNotContainsString(self::PARTIAL_MARKER, $body, sprintf(
                'The %s screen rendered the analytics partial for a principal the route refuses.',
                $name
            ));
        }
    }

    /** And the same staff member, once granted the permission, is shown it. */
    public function test_the_same_staff_member_granted_manage_reports_is_shown_the_link(): void
    {
        [$merchant] = $this->makeTenant();

        $staff = $this->makeStaff($merchant);

        foreach (self::SCREENS as [$_uri, $permission]) {
            $this->grantPermission($staff, $permission);
        }

        $this->grantPermission($staff, ReportAccess::REPORTS);

        $this->assertTrue(ReportAccess::permits($staff->fresh()),
            'Premise: the grant did not take, so the positive case below would be meaningless.');

        foreach (self::SCREENS as $name => [$uri, $_permission]) {
            /*
             * THE MARKER, NOT THE URL. This principal HOLDS `Manage Reports`, so the sidebar emits
             * `/analytics` on every one of these pages whether the partial is included or not --
             * asserting the URL here is what made this test unable to fail.
             */
            $this->assertStringContainsString(self::PARTIAL_MARKER, $this->body($uri, $staff->fresh()),
                "The {$name} screen withheld the analytics link from a staff member holding "
                . ReportAccess::REPORTS . '.');
        }
    }

    /**
     * THE MARKER'S OWN PREMISE: it must be absent from these pages when the partial is not there.
     *
     * Without this, `PARTIAL_MARKER` could drift onto the shared chrome one day and quietly make
     * every positive case above vacuous again -- which is exactly how this file's granted-staff
     * case was broken in the first place. The check is cheap: the marker must appear in the
     * partial and in no other view these five screens render.
     */
    public function test_the_marker_belongs_to_the_partial_and_not_to_the_shared_chrome(): void
    {
        $partial = (string) file_get_contents(
            base_path('resources/views/marketing/_analytics_link.blade.php')
        );

        $this->assertStringContainsString(self::PARTIAL_MARKER, $partial,
            'The partial no longer carries the marker every positive case looks for.');

        foreach ([
            'resources/views/layouts/app.blade.php',
            'resources/views/partision/sidebar.blade.php',
            'resources/views/partision/menu-tree.blade.php',
            'resources/views/partision/header.blade.php',
        ] as $chrome) {
            $this->assertStringNotContainsString(
                self::PARTIAL_MARKER,
                (string) file_get_contents(base_path($chrome)),
                $chrome . ' now carries the marker this file uses to prove the partial rendered, '
                . 'so every positive assertion above has stopped measuring the partial.'
            );
        }
    }

    /**
     * THE LINK ASKS FOR THE PERMISSION THE ROUTE ENFORCES — the ARGUMENT, not just the predicate.
     *
     * Raised by an independent review, and it is a real gap: the test below proves both sides call
     * `ReportAccess::permits()`, but `ReportAccess::REPORTS` and `ReportPermission::ACTIONS['index']`
     * are two independent literals. If they ever diverged, the link would be hidden from a staff
     * member `/analytics` still admits — precisely the failure the partial exists to prevent — and
     * nothing else in the suite would notice, because the marketing tests grant one constant and
     * `MerchantAnalyticsScreenTest` grants the other as a literal.
     */
    public function test_the_links_permission_is_the_one_the_analytics_route_enforces(): void
    {
        $this->assertSame(
            ReportAccess::REPORTS,
            ReportPermission::ACTIONS['index'],
            'The permission the analytics link asks about is no longer the permission '
            . "ReportPermission enforces for MerchantAnalyticsController@index. One of the two "
            . 'literals moved, and the link now disagrees with the gate behind it.'
        );
    }

    /**
     * The label is translated. `Analytics` was already a key in both catalogues, which is why no new
     * string was added — but "already there" is a claim about a file, so it is asserted.
     */
    public function test_an_arabic_merchant_sees_the_arabic_label(): void
    {
        [$merchant] = $this->makeTenant();

        $this->grantTheScreensOwnPermissions($merchant);

        $merchant->language = 'ar';
        $merchant->save();

        $arabic = 'التحليلات';

        foreach (self::SCREENS as $name => [$uri, $_permission]) {
            $body = $this->body($uri, $merchant->fresh());

            $this->assertStringContainsString($arabic, $body,
                "The {$name} screen showed an Arabic merchant an untranslated analytics label.");
        }
    }

    /**
     * THE PREDICATE IS THE ROUTE'S OWN, not a second rule. If the view's gate and the middleware's
     * gate could disagree, the link would eventually point at a refusal — so the view calls what
     * the middleware calls.
     */
    public function test_the_links_gate_is_the_same_predicate_the_route_enforces(): void
    {
        /*
         * COMMENTS STRIPPED, and that is not a detail. The first version of this test read the raw
         * file and failed its own `@permission` assertion by matching the partial's docblock, which
         * EXPLAINS why @permission must not be used. `StripsSourceComments` exists because that
         * exact mistake had already been made five times in this repository; this was the sixth.
         */
        $partial = $this->sourceWithoutComments('resources/views/marketing/_analytics_link.blade.php');

        $this->assertStringContainsString('ReportAccess::permits', $partial,
            'The analytics link no longer asks ReportAccess::permits(). A second copy of the three '
            . 'checks, or an @permission directive, would hide the link from the operator and from '
            . 'a tenant owner whose seeded role predates the permission name.');

        $this->assertStringNotContainsString('@permission', $partial,
            'The link is gated by @permission, which is isAbleTo() alone: it refuses the platform '
            . 'operator (Laratrust has no super-admin bypass) and the tenant owner, both of whom '
            . 'ReportPermission admits.');

        $middleware = $this->sourceWithoutComments('app/Http/Middleware/ReportPermission.php');

        $this->assertStringContainsString('ReportAccess::permits', $middleware,
            'ReportPermission no longer delegates to ReportAccess::permits(), so the link and the '
            . 'gate are now two rules that can drift.');
    }
}
