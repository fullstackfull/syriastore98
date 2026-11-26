<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * THE PLATFORM OWNER'S LANDING-PAGE EDITOR MUST NOT RENDER FOR SOMEBODY WITHOUT THE PERMISSION —
 * AND TODAY THE ONLY THING STOPPING IT IS AN HTTP 500.
 * =================================================================================================
 *
 * HOW THIS WAS FOUND, AND WHY IT IS NOT A 500 TEST. A census of routes naming a missing view
 * (`tests/Support/Security/bin/census-routes-naming-missing-views.php`) ended at 40 routes that
 * really answer 500, 37 of them the vendored `Workdo\LandingPage` module. The obvious next step was
 * to withdraw them host-side the way four auto-discovered package routes were withdrawn before.
 * Three questions were asked first, and the third one stopped it.
 *
 *   1. IS ANYTHING REFERENCING THEM BY NAME? Yes — 12 of 45, and not only inside the package:
 *      `app/Http/Controllers/FaqController.php` and `app/Http/Middleware/DemoModeMiddleware.php`
 *      name `faq.edit` and `footer_section_create`, and `tests/Unit/Ui/
 *      CatalogueFormLabelAssociationTest.php` names `faq.edit` too. A withdrawal that dropped the
 *      names would break first-party code and a test.
 *
 *   2. IS ANY OF THEM REACHED FROM A RENDERED VIEW? Yes — most of the references are from the
 *      module's own `landingpage/details/<section>/index.blade.php` screens, which DO exist. So a merchant
 *      on one of those screens has buttons that currently 500.
 *
 *   3. DOES THE MODULE SHIP THE VIEW UNDER ANOTHER NAME? YES, FOR 13 OF THE 20 MISSING NAMES, and
 *      this is where the premise "the module does not ship the views its controllers name" breaks.
 *      It ships them under a `details.` or `custom.` infix (and `review` -> `reviews`):
 *
 *          asked for                           shipped as
 *          landingpage.buildtech.index         landingpage.details.buildtech.index
 *          landingpage.footer.index            landingpage.custom.footer.index
 *          landingpage.review.create           landingpage.details.reviews.create
 *
 *      MEASURED, not inferred: a one-line host-side alias at
 *      `resources/views/vendor/landing-page/landingpage/buildtech/card_create.blade.php`
 *      containing `@include('landing-page::landingpage.details.buildtech.card_create')` made
 *      `view()->exists()` true and turned `GET /buildtech/create` from **500 into 200**, with no
 *      edit to `packages/workdo/`. Laravel's own package-override path does it: `loadViewsFrom()`
 *      registers `resources/views/vendor/<namespace>` ahead of the package's own directory.
 *
 * -------------------------------------------------------------------------------------------------
 * AND THAT IS WHY NOTHING WAS REPAIRED. The repair works, and it is the wrong thing to do, because
 * THE 500 IS DOING A JOB NOBODY ASSIGNED IT.
 *
 * MEASURED across the 43 LandingPage routes whose view is missing:
 *
 *       4  carry `isAbleTo('Manage LandingPage')` inside the method -- and those are exactly the
 *          ones that do NOT 500: the gate refuses and redirects before the view is reached.
 *      39  carry NO gate of any kind -- and those are the ones that reach the view and 500.
 *
 * The correlation is the finding: where the permission check exists the 500 cannot happen, and
 * where the 500 happens there is no permission check. So with the view repaired, measured on the
 * sharpest principal available -- a staff member with `type=staff`, **0 roles**, and
 * `isAbleTo('Manage LandingPage') === false` -- `GET /buildtech/create` answered **HTTP 200** and
 * rendered the platform owner's landing-page editor.
 *
 * Repairing the 500 would therefore be a PRIVILEGE ESCALATION dressed as a defect fix. Withdrawal
 * to 404 preserves the accidental closure but is a decision about 37 named public routes, and
 * question 1 shows the names are referenced. Both remedies are reported, neither is taken.
 *
 * -------------------------------------------------------------------------------------------------
 * SO THIS TEST ASSERTS THE PROPERTY, NOT THE DEFECT, and that is deliberate: it is satisfied by
 * every honest remedy and only by those.
 *
 *     withdrawn to 404              -> not 200 -> PASSES
 *     a permission gate added       -> redirect -> PASSES
 *     the view repaired, no gate    -> 200      -> FAILS, which is the whole point
 *     left exactly as it is         -> 500      -> PASSES, accidentally, as today
 *
 * A false-direction ratchet on `view()->exists()` would have pinned "stay broken" instead, and
 * would have gone green for a repair that opens the surface.
 */
class LandingPageEditorIsNotReachableWithoutPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A sample of the ungated family, one per controller, chosen because each takes no route
     * parameter -- so a 404 from a missing model cannot be mistaken for a refusal.
     */
    private const UNGATED_EDITOR_PATHS = [
        '/buildtech/create',
        '/dedicated/create',
        '/review/create',
        '/footer/create',
        '/packagedetails/create',
        '/pricing_plan/create',
        '/join_us/create',
        '/landingpage/create',
    ];

    public function test_a_roleless_staff_member_cannot_render_any_landing_page_editor_screen(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $staff->email_verified_at = now();
        $staff->current_store = $store->id;
        $staff->save();

        /* PREMISE: the principal really is powerless, or every assertion below is vacuous. */
        $this->assertSame('staff', (string) $staff->type);
        $this->assertSame(0, $staff->roles()->count(), 'premise: the staff member has a role');
        $this->assertFalse($staff->isAbleTo('Manage LandingPage'),
            'premise: this staff member HOLDS the permission, so refusing them proves nothing');

        $reached = [];

        foreach (self::UNGATED_EDITOR_PATHS as $path) {
            /* A fresh session per row: `redirect()->back()` otherwise resolves to whichever row ran
               last, which misreported five of eight redirects in the census that found this. */
            $this->flushSession();

            $response = $this->actingAs($staff, 'web')->get($path);

            if ($response->status() === 200) {
                $reached[] = $path . ' -> 200';
            }
        }

        $this->assertSame([], $reached,
            "A staff member with no roles and no `Manage LandingPage` permission RENDERED the "
            . "platform owner's landing-page editor at: " . implode(', ', $reached) . ". Today "
            . 'these paths answer 500 because the module ships its views under a `details.` / '
            . '`custom.` infix that its own controllers do not use, and 39 of the 43 such routes '
            . 'carry no permission check at all -- so the 500 is the only thing refusing them. If '
            . 'this test has just started failing, the view was probably repaired (a '
            . 'resources/views/vendor/landing-page alias is enough, measured) WITHOUT adding the '
            . 'gate. Add `Manage LandingPage` to the handler, or withdraw the route; do not simply '
            . 'make the view resolve.');
    }

    /**
     * THE PREMISE FOR THE WHOLE FILE: the paths are registered and require only `auth`-class
     * middleware, with no permission middleware on the route itself. If a route-level gate were
     * added, the case above would pass for a reason that has nothing to do with the handler.
     */
    public function test_premise_those_paths_are_registered_and_carry_no_permission_middleware(): void
    {
        $router = app('router')->getRoutes();

        foreach (self::UNGATED_EDITOR_PATHS as $path) {
            $uri = ltrim($path, '/');
            $route = collect($router)->first(
                fn ($r) => $r->uri() === $uri && in_array('GET', $r->methods(), true)
            );

            $this->assertNotNull($route, $path . ' is no longer registered; this file pins less than it says.');

            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, $path . ' lost its auth middleware.');

            foreach ($middleware as $m) {
                $this->assertStringNotContainsString('permission', strtolower((string) (is_string($m) ? $m : '')),
                    $path . ' now carries permission middleware on the ROUTE. That is a fine fix, but '
                    . 'the case above would then pass without the handler changing, so move this '
                    . 'premise rather than leaving it stale.');
            }
        }
    }
}
