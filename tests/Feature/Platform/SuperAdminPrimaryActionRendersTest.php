<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-179 / P06-06 — THE DESIGN-SYSTEM PRIMARY ACTION REALLY REACHES THE OPERATOR'S PAGE.
 * =================================================================================================
 *
 * `SuperAdminPremiumSurfaceTest` is a SOURCE census: it proves the views no longer dress a control
 * in the host's `.btn-primary`, which measures 2.58:1 in the dark admin theme. This file answers
 * the other half of the question, and the two are not the same question.
 *
 * WHY BOTH, AND IT IS NOT BELT-AND-BRACES — A REAL CASE IN THIS SLICE SEPARATED THEM.
 *
 *   `setting/index.blade.php:283` carried `class="btn btn-primary"` and was converted with the
 *   rest. The four before/after full-page captures of `/setting` then came back BYTE-IDENTICAL
 *   (sha256 5c348c85daf08704 both sides, dark-en and dark-ar), which is how the reason was found:
 *   that button sits inside `<div id="delivery-syria-content" style="display: none;">`, so it has
 *   never been rendered at all. It measured 0x0 in the browser, it was never among the failing
 *   controls, and the conversion changed not one pixel.
 *
 *   The VISIBLE "Save Changes" on that screen comes from a different file, fetched into the tab
 *   panel by AJAX, and was already on `.ss-btn--primary` from Phase 02.
 *
 * So a source census can be satisfied by a class on markup nobody sees, and a capture can be
 * unchanged while the source is improved. Neither is wrong; they measure different things. This
 * file pins the ones that are really EMITTED, by rendering the page through the application.
 *
 * SCOPE: the screens whose gate is `App\Http\Middleware\PlatformOperatorOnly` ALONE, so the test
 * needs no permission fixture beyond the operator himself. CLAUDE.md records that there is no
 * super-admin bypass — `isAbleTo()` refuses the platform operator — so the screens that also carry
 * a bare permission check are deliberately left to the source census and to the live harness
 * (`tests/Support/bin/measure-superadmin-premium-live.cjs`, which grants the operator production's
 * `super admin` role first, through `tests/Support/bin/grant-dev-operator-role.php`).
 *
 * `Cache::forget('admin_details')` because several handlers read the platform admin through
 * `Cache::remember('admin_details', 3600, …)` and would otherwise see the pre-fixture `User`.
 */
class SuperAdminPrimaryActionRendersTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        (new User)->flushCache();
        Cache::forget('admin_details');
    }

    private function render(string $uri): string
    {
        $response = $this->actingAs(User::find($this->operator->id))->get($uri);

        $this->assertSame(200, $response->status(), "{$uri} did not answer 200 for the operator.");

        $body = $response->getContent();

        /* A redirect that has already been followed, or a handler that answered with the
           dashboard, is still HTTP 200 — `redirect()->back()` is how six operator screens on the
           dev fixture each returned the PREVIOUS page while reporting success. The body is checked
           for its own screen's marker by each caller; this only refuses an empty one. */
        $this->assertGreaterThan(2000, strlen((string) $body), "{$uri} rendered almost nothing.");

        return (string) $body;
    }

    /**
     * Markup the operator's browser would PAINT: the rendered page with its `<script>` blocks
     * removed.
     *
     * THIS SCOPING IS NOT A CONVENIENCE AND THE FIRST VERSION WITHOUT IT FAILED, for a reason
     * worth recording rather than working around. `resources/views/layouts/app.blade.php:894`
     * builds a product-attribute control by string concatenation inside a `<script>`:
     *
     *     '<a href="#" class="btn btn-sm btn-primary add_attribute btn-badge" …'
     *
     * The layout is shared, so that string is in the HTML of EVERY admin page, this operator
     * screen included — as JavaScript source, not as an element. Nothing on `/platform/resources`
     * paints it, and `document.querySelectorAll('.btn-primary').length` really is 0 there
     * (measured in the browser). An assertion over the raw body would therefore have been
     * unsatisfiable without editing the merchant product form from inside a Phase 06 slice.
     *
     * The occurrence is real and is NOT dismissed: it is a `btn-primary` on a control a merchant
     * uses, in the one file that reaches all 70 vendored modules, and it is recorded in
     * `docs/development/phase-6/PHASE-06-SUPER-ADMIN-UX.md` §4 as out of this slice's scope for
     * exactly that reason.
     */
    private function painted(string $uri): string
    {
        return (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $this->render($uri));
    }

    public function test_premise_the_operator_is_refused_nothing_on_the_screens_measured_here(): void
    {
        /* Without this, every assertion below could pass against a redirect. The marker is the
           WorkDo layout's own breadcrumb region (`layouts/app.blade.php:637`), which only renders
           inside the admin shell. */
        foreach (['/platform/resources', '/platform/site-content', '/platform/site-content/home'] as $uri) {
            $this->assertStringContainsString(
                'class="page-header',
                $this->render($uri),
                "{$uri} did not render the admin page shell, so it is not the screen under test."
            );
        }
    }

    public function test_the_platform_resources_screen_emits_its_paths_in_the_design_systems_code_style(): void
    {
        $body = $this->painted('/platform/resources');

        $this->assertStringContainsString('<code class="ss-code">', $body);

        /* AND NO BARE `<code>` SURVIVES. Bootstrap paints an unclassed one at #e83e8c, which
           measures 3.65:1 on this screen in light and 4.05:1 in dark — both below 4.5:1. */
        $this->assertDoesNotMatchRegularExpression('/<code(?!\s+class="ss-code")/', $body);
    }

    public function test_the_official_website_content_editor_still_names_a_design_system_primary_action(): void
    {
        /* The precedent this slice follows, kept honest: `platform/content/edit.blade.php` is the
           screen that was moved onto `.ss-btn--primary` first, with the 2.58:1 measurement in its
           own comment. If it ever regresses, the argument for every other conversion regresses
           with it. */
        $body = $this->painted('/platform/site-content/home');

        $this->assertStringContainsString('ss-btn ss-btn--primary', $body);
        $this->assertStringNotContainsString('btn btn-primary', $body);
    }

    public function test_no_operator_screen_measured_here_emits_the_hosts_primary_fill(): void
    {
        foreach (['/platform/resources', '/platform/site-content', '/platform/site-content/home'] as $uri) {
            $body = $this->painted($uri);

            /* `btn-primary` as a CLASS TOKEN, not as a substring: `btn-primary-outline` and prose
               about it are not the control this refuses. */
            $this->assertDoesNotMatchRegularExpression(
                '/class="[^"]*\bbtn-primary\b[^"]*"/',
                $body,
                "{$uri} still emits an element classed `btn-primary`, which the host dark sheet "
                . 'paints at 2.58:1.'
            );
        }
    }
}
