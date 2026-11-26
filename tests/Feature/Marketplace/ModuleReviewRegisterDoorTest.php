<?php

namespace Tests\Feature\Marketplace;

use App\Http\Controllers\ModuleReviewController;
use App\Models\ModuleReviewRecord;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * MARKET-002 — WHO MAY RECORD A REVIEW DECISION, AND WHAT A DECISION MAY DO.
 *
 * Approving a package is the act that lets third-party code run, so this door decides the same
 * thing `install` does and is gated the same way: the `manage-platform-addons` Gate, a `users.type`
 * string comparison, NOT an `isAbleTo()` permission name. CLAUDE.md §1: Laratrust runs with
 * `permissions_as_gates => false` and has no operator bypass, so a permission check would refuse the
 * platform operator on any database where the name was not seeded for their role.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE HANDLER IS CALLED DIRECTLY AS WELL AS OVER HTTP, DELIBERATELY
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * CLAUDE.md §1: *"A HANDLER-LEVEL CHECK BEHIND AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP
 * TEST."* `can:manage-platform-addons` on the route group answers 403 before the handler runs, so an
 * HTTP probe returns 403 whether or not `ModuleReviewController` checks anything — which is exactly
 * how `PlanController::planDisable` admitted a `Customer` typed `super admin` behind green HTTP
 * tests (`GAP-307`). The HTTP cases here prove the ROUTE carries the gate; the direct cases prove the
 * METHOD does. Both are needed, because SEC-F2 in this very controller family is the record of what
 * happens when only the grouping is trusted.
 */
class ModuleReviewRegisterDoorTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function pendingRecord(string $subject = 'zz9pending'): ModuleReviewRecord
    {
        return ModuleReviewRecord::create([
            'subject_type' => ModuleReviewRecord::TYPE_MODULE,
            'subject' => $subject,
            'provenance' => ModuleReviewRecord::PROVENANCE_UPLOADED,
            'review_state' => ModuleReviewRecord::STATE_PENDING,
        ]);
    }

    #[Test]
    public function test_the_platform_operator_reaches_the_register(): void
    {
        $this->pendingRecord();

        $response = $this->actingAs($this->makePlatformAdmin())->get('/module-review');

        $response->assertOk();
        $response->assertViewIs('module.review');
        $response->assertSee('zz9pending');
    }

    /**
     * THE ROUTE'S OWN MIDDLEWARE, BY NAME.
     *
     * Added because a mutation proved the suite could not tell: moving `module-review` OUT of the
     * `can:manage-platform-addons` group left all twelve other cases green, since the handler's own
     * `authorize()` also answers 403. That is correct defence in depth and it is precisely why the
     * route-level half needs an assertion of its own -- SEC-F2's record is of a control that rested
     * on grouping alone, and the inverse (a control resting on the handler alone) is one refactor
     * away from the same failure.
     */
    #[Test]
    public function test_every_review_route_carries_the_platform_addon_gate(): void
    {
        $expected = ['module.review.index', 'module.review.show', 'module.review.decide'];
        $seen = [];

        foreach (app('router')->getRoutes() as $route) {
            if (in_array($route->getName(), $expected, true)) {
                $seen[] = $route->getName();

                $this->assertContains(
                    'can:manage-platform-addons',
                    $route->gatherMiddleware(),
                    $route->getName() . ' must carry the platform-addon Gate at the route, not only in the handler.'
                );
            }
        }

        $this->assertSame($expected, $seen, 'premise: all three routes are registered, so the loop above measured all three.');
    }

    #[Test]
    public function test_a_merchant_is_refused_the_register_over_http(): void
    {
        $this->actingAs($this->makeMerchant())->get('/module-review')->assertForbidden();
    }

    #[Test]
    public function test_an_anonymous_visitor_is_refused_the_register(): void
    {
        $this->get('/module-review')->assertRedirect(route('login'));
    }

    /**
     * THE METHOD'S OWN GATE, with no middleware in front of it. If the `authorize()` call were
     * removed from `index()` the HTTP case above would still be 403 and this is the only assertion
     * that would notice.
     */
    #[Test]
    public function test_the_handler_itself_refuses_a_merchant(): void
    {
        $this->actingAs($this->makeMerchant());

        $this->expectException(AuthorizationException::class);

        app(ModuleReviewController::class)->index();
    }

    #[Test]
    public function test_the_decide_handler_itself_refuses_a_merchant(): void
    {
        $record = $this->pendingRecord();

        $this->actingAs($this->makeMerchant());

        try {
            app(ModuleReviewController::class)->decide(Request::create('/module-review-decision', 'POST', [
                'subject_type' => ModuleReviewRecord::TYPE_MODULE,
                'subject' => $record->subject,
                'decision' => ModuleReviewRecord::STATE_APPROVED,
            ]));

            $this->fail('decide() must refuse a merchant on its own, not only behind the route gate.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(
            ModuleReviewRecord::STATE_PENDING,
            $record->fresh()->review_state,
            'THE REASON MATTERS, NOT THE EXCEPTION: the row must be unchanged, so a refusal that threw after writing would fail here.'
        );
    }

    /**
     * A CUSTOMER ROW TYPED `super admin` MUST NOT PASS. GAP-307's shape: the Gate reads
     * `users.type`, so a principal from another table carrying that string is the test that tells a
     * type check apart from a table check. `makePlatformAdmin()` builds a `User`; this builds a
     * `User` whose type is a near-miss, which is the reachable half here — self-registration sets
     * `type = 'admin'`, and a merchant can change their own type through the product.
     */
    #[Test]
    public function test_a_tenant_admin_is_not_a_platform_operator(): void
    {
        $merchant = $this->makeMerchant(['type' => 'admin']);

        $this->actingAs($merchant)->get('/module-review')->assertForbidden();

        $this->assertSame('admin', $merchant->fresh()->type, 'premise: this user really is type admin, not super admin.');
    }

    #[Test]
    public function test_the_operator_can_record_an_approval(): void
    {
        $record = $this->pendingRecord();
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post('/module-review-decision', [
            'subject_type' => ModuleReviewRecord::TYPE_MODULE,
            'subject' => $record->subject,
            'decision' => ModuleReviewRecord::STATE_APPROVED,
            'note' => 'read the provider',
        ]);

        $fresh = $record->fresh();

        $this->assertSame(ModuleReviewRecord::STATE_APPROVED, $fresh->review_state);
        $this->assertSame($operator->id, (int) $fresh->reviewed_by, 'A decision must say who made it.');
        $this->assertSame('read the provider', $fresh->review_note);
        $this->assertNotNull($fresh->reviewed_at);
    }

    /**
     * THE ONE SHAPE THAT WOULD DEFEAT THE WHOLE CONTROL: pre-approving a name before uploading it.
     *
     * `decide()` requires the row to EXIST and never creates one, and `ModuleTrust::recordUpload()`
     * resets the state to `pending` on every upload. So an approval can only ever be recorded about
     * bytes that are already on disk, and a replaced archive reopens the review.
     */
    #[Test]
    public function test_a_decision_cannot_create_a_record_for_a_package_that_was_never_uploaded(): void
    {
        $this->actingAs($this->makePlatformAdmin())->post('/module-review-decision', [
            'subject_type' => ModuleReviewRecord::TYPE_MODULE,
            'subject' => 'zz9neverUploaded',
            'decision' => ModuleReviewRecord::STATE_APPROVED,
        ]);

        $this->assertDatabaseMissing('module_review_records', ['subject' => 'zz9neverUploaded']);
    }

    #[Test]
    public function test_an_unknown_subject_type_is_refused(): void
    {
        $record = $this->pendingRecord();

        $this->actingAs($this->makePlatformAdmin())
            ->post('/module-review-decision', [
                'subject_type' => 'anything',
                'subject' => $record->subject,
                'decision' => ModuleReviewRecord::STATE_APPROVED,
            ])
            ->assertSessionHasErrors('subject_type');

        $this->assertSame(ModuleReviewRecord::STATE_PENDING, $record->fresh()->review_state);
    }

    /** `pending` is the absence of a decision, so it is not something a decision may be set to. */
    #[Test]
    public function test_pending_is_not_a_recordable_decision(): void
    {
        $record = $this->pendingRecord();
        $record->update(['review_state' => ModuleReviewRecord::STATE_APPROVED]);

        $this->actingAs($this->makePlatformAdmin())
            ->post('/module-review-decision', [
                'subject_type' => ModuleReviewRecord::TYPE_MODULE,
                'subject' => $record->subject,
                'decision' => ModuleReviewRecord::STATE_PENDING,
            ])
            ->assertSessionHasErrors('decision');

        $this->assertSame(ModuleReviewRecord::STATE_APPROVED, $record->fresh()->review_state);
    }

    #[Test]
    public function test_the_detail_screen_shows_the_declared_review_inputs(): void
    {
        $record = $this->pendingRecord();
        $record->update([
            'declared_version' => '3.4',
            'declared_dependencies' => 'ProductReview,Api',
            'declared_permissions' => json_encode(['Manage Probe', 'Delete Probe']),
        ]);

        $response = $this->actingAs($this->makePlatformAdmin())
            ->get('/module-review/' . ModuleReviewRecord::TYPE_MODULE . '/' . $record->subject);

        $response->assertOk();
        $response->assertViewIs('module.review-detail');
        $response->assertSee('3.4');
        $response->assertSee('ProductReview,Api');
        $response->assertSee('Manage Probe');
        $response->assertSee('Delete Probe');
    }

    /**
     * THE REGISTER IS REACHABLE FROM THE PRODUCT, not only from a URL somebody was told about.
     *
     * A boundary whose only release valve is an unlinked route is a removed feature with extra
     * steps: the operator uploads a package, nothing runs, and the screen that would let them
     * approve it is not referenced anywhere. The install screen is where the link belongs because
     * it is where the upload is made.
     */
    #[Test]
    public function test_the_install_screen_links_to_the_review_register(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->grantPermission($operator, 'Module Add');

        $response = $this->actingAs($operator)->get('/modules/add');

        $response->assertOk();
        $response->assertViewIs('module.add');
        $response->assertSee(route('module.review.index'), false);
    }

    /**
     * BOTH LANGUAGES, BECAUSE A ONE-DIRECTION CHECK IS NOT A CHECK HERE.
     *
     * The product is bilingual Arabic (RTL) / English (LTR), and CLAUDE.md requires any UI change to
     * be checked in both directions. These two screens are new, so every string on them is new: the
     * failure this catches is the ordinary one, an `__()` key with no Arabic entry, which renders the
     * ENGLISH key inside an `dir="rtl"` document and looks like a layout bug rather than a missing
     * translation. `StorefrontMessagesAreDeclaredTest` covers the controllers `routes/customer.php`
     * imports -- it found two of mine -- and covers neither of these views, so this is the only thing
     * that reads them.
     *
     * WHAT THIS DOES NOT COVER, said plainly: contrast. These screens introduce no colour of their
     * own -- no inline style, no hex, no `rgb()`, measured -- and their only toned element is
     * `<x-ss.status-badge>`, whose `.ss-badge--*` tokens are defined for light and dark in
     * `store-syria-ds.css` and are contrast-checked there. A rendered contrast pass is human
     * acceptance, not something asserted here.
     */
    #[Test]
    public function test_both_review_screens_render_in_arabic_and_in_english(): void
    {
        $record = $this->pendingRecord();
        $record->update([
            'declared_version' => '3.4',
            'declared_permissions' => json_encode(['Manage Probe']),
        ]);

        $strings = ['Awaiting review', 'Record a decision', 'Permissions it creates', 'Declared version'];

        /*
         * THE EXPECTED STRINGS ARE RESOLVED BEFORE THE REQUEST, and this is not a detail.
         * `App\Http\Middleware\SetLocate` sets the locale per request from `Session::get('LANGUAGE')`
         * (falling back to the signed-in user's `language`), so by the time a `get()` has returned,
         * `app()->getLocale()` is whatever the MIDDLEWARE chose. The first version of this test called
         * `__()` after the request and compared the Arabic page against ENGLISH keys -- it failed, and
         * had the keys been missing it would have failed for a reason that looked identical.
         */
        $expected = [];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach ($strings as $string) {
                $expected[$locale][$string] = __($string);
            }
        }

        foreach ($strings as $string) {
            $this->assertNotSame(
                $expected['en'][$string],
                $expected['ar'][$string],
                sprintf(
                    'resources/lang/ar.json has no entry for "%s", so __() hands back the English key and an '
                    . 'Arabic operator reads Latin script inside a dir="rtl" document.',
                    $string
                )
            );
        }

        foreach (['en', 'ar'] as $locale) {
            $operator = $this->makePlatformAdmin(['language' => $locale]);

            $list = $this->actingAs($operator)->withSession(['LANGUAGE' => $locale])->get('/module-review');
            $list->assertOk();
            $list->assertViewIs('module.review');
            $list->assertSee($expected[$locale]['Awaiting review'], false);
            $list->assertSee($expected[$locale]['Declared version'], false);

            $detail = $this->actingAs($operator)
                ->withSession(['LANGUAGE' => $locale])
                ->get('/module-review/' . ModuleReviewRecord::TYPE_MODULE . '/' . $record->subject);
            $detail->assertOk();
            $detail->assertViewIs('module.review-detail');
            $detail->assertSee($expected[$locale]['Record a decision'], false);
            $detail->assertSee($expected[$locale]['Permissions it creates'], false);
        }
    }

    #[Test]
    public function test_a_merchant_is_refused_the_detail_screen(): void
    {
        $record = $this->pendingRecord();

        $this->actingAs($this->makeMerchant())
            ->get('/module-review/' . ModuleReviewRecord::TYPE_MODULE . '/' . $record->subject)
            ->assertForbidden();
    }
}
