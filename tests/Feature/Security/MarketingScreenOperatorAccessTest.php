<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * GAP-218 — THE MARKETING READ SCREENS REFUSED THE PLATFORM OPERATOR, AND COUPON ALREADY DID NOT.
 * =============================================================================================
 *
 * Laratrust has no super-admin bypass, so `isAbleTo('Manage X')` alone refuses a `super admin`
 * holding no role — and on a database built from source no role holds these names at all. Four
 * Marketing read doors gated that way:
 *
 *     NewsletterController::index                  'Manage Newsletter'
 *     FlashSaleController::index                   'Manage Flash Sale'
 *     WishlistController::index                    'Manage Wishlist'
 *     CartController::abandon_carts_handled        'Manage Cart'
 *
 * THE FIFTH SITE GAP-218 NAMES IS ALREADY FIXED and that is why this file exists in this shape.
 * `CouponController::couponReadRefusal():221-224` has carried the operator-first limb since an
 * owner decision of **2026-10-28**, which predates GAP-218's own 2026-11-21 measurement — the row
 * was stale the day it was written. `CouponOperatorRightsTest:70` pins it. So this change makes
 * the other four match a decision already taken, rather than taking a new one.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO, and it is the more important half.
 *
 *   THE TENANT OWNER IS STILL REFUSED. `ReportAccess::permits()` grants an owner-without-the-
 *   permission pass and this does NOT reuse it, because `SavedViewPolicy:84` declares
 *   `OWNER_ADMITTED_SCREENS = ['analytics']` and says at `:55` that excluding these screens is
 *   DELIBERATE: admitting one would make SAVING a view more permissive than SEEING the list it
 *   describes. `SavedViewIsolationTest:219` pins that constant by name. Changing it is the
 *   owner's call, not engineering's, and a test below holds the line so a future reader does not
 *   "fix" it by analogy.
 *
 *   NO WRITE GATE MOVES. `FlashSaleTenancyTest:209` pins the operator being REFUSED a flash-sale
 *   write, non-vacuously — it grants `Edit Flash Sale` first precisely so the operator limb is
 *   what the assertion measures. Read and write stay distinct, as `FlashSaleController` already
 *   distinguishes them.
 *
 * AN OPERATOR WITH NO STORE SELECTED IS REFUSED, NOT DEFAULTED. `getCurrentStore()` hands such a
 * principal the hardcoded literal 1, and `getStoreById(1)` normally resolves — so admitting the
 * operator WITHOUT this guard would serve them store 1's marketing data by default. That is
 * GAP-223(b), and the guard is part of admitting the operator safely rather than a separate
 * change.
 */
class MarketingScreenOperatorAccessTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> path => [path, permission] */
    public static function readScreens(): array
    {
        return [
            'newsletter' => ['/newsletter', 'Manage Newsletter'],
            'flash sale' => ['/flash-sale', 'Manage Flash Sale'],
            'wishlist' => ['/wishlist', 'Manage Wishlist'],
            'abandoned carts' => ['/abandon-carts-handled', 'Manage Cart'],
        ];
    }

    /**
     * @dataProvider readScreens
     */
    public function test_the_platform_operator_with_a_selected_store_may_read_the_screen(string $path, string $permission): void
    {
        [$merchant, $store] = $this->makeTenant();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $response = $this->actingAs($operator)->get($path);

        $this->assertSame(200, $response->status(), sprintf(
            'The platform operator was refused %s (HTTP %d). `isAbleTo()` alone has no super-admin '
            . 'bypass, and no role holds "%s" on a database built from source, so the operator '
            . 'cannot reach a screen they are responsible for.',
            $path,
            $response->status(),
            $permission
        ));
    }

    /**
     * @dataProvider readScreens
     */
    public function test_an_operator_with_no_store_selected_is_refused_rather_than_given_store_one(string $path, string $permission): void
    {
        $this->makeTenant();

        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $response = $this->actingAs($operator)->get($path);

        $this->assertNotSame(200, $response->status(), sprintf(
            'An operator with no store selected was served %s. `getCurrentStore()` returns the '
            . 'hardcoded literal 1 for them, so this is store 1\'s data shown to somebody who '
            . 'chose no store — GAP-223(b).',
            $path
        ));
    }

    /**
     * @dataProvider readScreens
     */
    public function test_a_delegated_user_without_the_permission_is_still_refused(string $path, string $permission): void
    {
        [$merchant, $store] = $this->makeTenant();

        $delegate = $this->makeStaff($merchant, ['current_store' => $store->id]);

        $response = $this->actingAs($delegate)->get($path);

        $this->assertNotSame(200, $response->status(), sprintf(
            'A user holding neither "%s" nor operator status reached %s, so the operator limb '
            . 'widened the audience instead of adding one principal to it.',
            $permission,
            $path
        ));
    }

    /**
     * THE LINE THIS CHANGE MUST NOT CROSS, pinned here so it is visible from the change itself.
     *
     * A tenant OWNER whose role does not carry the permission stays refused. The analogy to
     * `ReportAccess::permits()` is tempting and WRONG: `SavedViewPolicy` has already decided the
     * opposite for exactly these screens, and decided it on purpose.
     *
     * @dataProvider readScreens
     */
    public function test_a_tenant_owner_without_the_permission_is_still_refused(string $path, string $permission): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->assertSame((int) $merchant->id, (int) $merchant->creatorId(),
            'Premise: this fixture is not a tenant owner, so the assertion below measures nothing.');

        $response = $this->actingAs($merchant)->get($path);

        $this->assertNotSame(200, $response->status(), sprintf(
            'A tenant owner without "%s" reached %s. That contradicts SavedViewPolicy:84, which '
            . 'declares OWNER_ADMITTED_SCREENS = [\'analytics\'] and states at :55 that excluding '
            . 'these screens is deliberate. Admitting the owner here is an OWNER decision, not an '
            . 'engineering consistency fix.',
            $permission,
            $path
        ));
    }

    private const AJAX = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ];

    /** @return array<string, mixed> the minimum yajra needs to answer a draw. */
    private function dataTableQuery(array $columns): array
    {
        $query = ['draw' => 1, 'start' => 0, 'length' => 10];

        foreach (array_values($columns) as $i => $name) {
            $query["columns[{$i}][data]"] = $name;
            $query["columns[{$i}][name]"] = $name;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'desc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        return $query;
    }

    /**
     * ADMITTING A PRINCIPAL TO A SCREEN IS NOT THE SAME AS SCOPING WHAT THEY SEE, AND ONLY THE
     * FIRST WAS PROVED.
     *
     * Raised by the tenant-authz review of this very change, and it was right: every other test
     * here asserts `status()` alone. Drop `where('store_id', getCurrentStore())` from any of the
     * four DataTables and all of them still pass — so the file proved the operator gets IN and
     * proved nothing about what comes back.
     *
     * That is the assertion that matters most here, because the entire justification for
     * admitting the operator is that they are confined to the store they chose. This drives the
     * REAL second leg — the XHR that yajra answers — against a two-tenant fixture, and measures
     * the row count rather than the HTTP status.
     */
    public function test_the_operator_sees_only_the_store_they_selected(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        // One subscriber per store. `newsletters` is the simplest of the four payloads and is
        // store-scoped by NewsletterDataTable:43, the predicate under test.
        foreach ([[$storeA->id, 'a@scoped.test'], [$storeB->id, 'b@other.test']] as [$storeId, $email]) {
            \Illuminate\Support\Facades\DB::table('newsletters')->insert([
                'email' => $email,
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $operator = $this->makePlatformAdmin(['current_store' => $storeA->id]);

        $response = $this->actingAs($operator)
            ->withHeaders(self::AJAX)
            ->getJson('/newsletter?' . http_build_query($this->dataTableQuery(['email', 'created_at'])));

        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringContainsString('a@scoped.test', $body,
            'Premise: the operator did not receive the selected store\'s own row, so the absence '
            . 'asserted below proves nothing.');

        $this->assertStringNotContainsString('b@other.test', $body,
            'The operator was served another tenant\'s subscriber. Admitting them to the screen '
            . 'is only defensible because the query is confined to the store they chose; without '
            . 'that predicate this change leaks every tenant\'s shopper data to one principal.');
    }

    /**
     * THE REFUSAL SHAPE, not merely "not 200".
     *
     * Also raised by the review: every refusal assertion here is `assertNotSame(200, ...)`, which
     * a 403, a 500 or a JSON body would satisfy equally. The shipped contract is a 302 carrying a
     * flashed `error` the layout renders as a toast, and a merchant who meets a blank 403 instead
     * has lost the explanation.
     */
    public function test_a_refusal_is_a_redirect_carrying_a_flashed_error(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $delegate = $this->makeStaff($merchant, ['current_store' => $store->id]);

        $this->actingAs($delegate)
            ->get('/newsletter')
            ->assertStatus(302)
            ->assertSessionHas('error');
    }

    /**
     * THE ONE BEHAVIOUR CHANGE THIS MAKES FOR A NON-OPERATOR, and the review was right that it
     * was untested.
     *
     * The predicate's final limb refuses anyone whose store does not RESOLVE. Before, such a
     * caller got a list built from `where('store_id', null)` — an `IS NULL` match, the hazard
     * `CartController:1250-1259` already records. It is a narrowing and it fails closed, but it
     * applies to permission holders and not only to the operator.
     *
     * WHICH PRINCIPAL IS ACTUALLY IN THAT CLASS, corrected after a first version of this test
     * asserted the wrong mechanism and failed. Setting a merchant's `current_store` to 0 does NOT
     * make their store unresolvable: 0 is `empty()`, so `getCurrentStore()` (`helper.php:869-878`)
     * falls through to `Store::where('created_by', $user->id)->first()` and finds the store they
     * own. That fallback is correct and this change does not touch it.
     *
     * The limb therefore bites only for a principal who holds the permission AND owns no store of
     * their own AND has no `current_store` — a delegated user whose merchant never selected one.
     * `getCurrentStore()` returns null for exactly that shape, and `getStoreById(null)` is null.
     */
    public function test_a_permission_holder_who_resolves_to_no_store_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();

        // A delegate owns no store of their own, so the `created_by` fallback finds nothing.
        $delegate = $this->makeStaff($merchant, ['current_store' => null]);

        $this->grantPermission($delegate, 'Manage Newsletter');

        $this->assertNull(getCurrentStore($delegate->id),
            'Premise: this principal DOES resolve to a store, so the refusal below would be '
            . 'measuring the permission limb rather than the store limb.');

        $response = $this->actingAs($delegate->fresh())->get('/newsletter');

        $this->assertNotSame(200, $response->status(),
            'A principal who resolves to no store was served the list. The query would be built '
            . 'from `where(\'store_id\', null)`, which matches IS NULL rather than nothing.');
    }

    /**
     * WHAT THE OPERATOR MAY READ, THEY MAY ALSO EXPORT — because the alternative is a button that
     * is visible and always refuses.
     *
     * `NewsletterController::fileExport()` kept a bare `isAbleTo()` after `index()` gained the
     * operator limb, and its docblock justified that with "anyone who can SEE the button already
     * holds the permission". ADMITTING THE OPERATOR TO `index()` KILLED THAT PREMISE: the export
     * button is gated on the Subscribe module and the plan, not on a permission, so the operator
     * could open the list, see the button, and meet `Permission denied.` on every press.
     *
     * It is the 2026-10-28 coupon asymmetry inverted. That decision removed "the operator can
     * export a book they cannot open"; leaving this would have added "the operator can open a
     * list they cannot export". Found by the tenant-authz review of this change.
     */
    public function test_the_platform_operator_may_export_what_they_may_read(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $response = $this->actingAs($operator)->get('/newsletter/export');

        // `assertOk()` rather than a status comparison: a successful export is a Symfony
        // `BinaryFileResponse`, which carries no `status()` method — a refusal is the 302. So
        // "did a file come back" is the question, and it is the one a merchant cares about.
        $response->assertOk();

        $this->assertNotSame(302, $response->getStatusCode(),
            'The operator can open /newsletter but is refused its export, so the page shows them '
            . 'a button that always answers "Permission denied." — the coupon asymmetry inverted.');
    }

    /**
     * THE NULL GUARD, TESTED ON THE PREDICATE RATHER THAN THROUGH A ROUTE.
     *
     * Every one of these routes carries `Authenticate`, so no HTTP request reaches the predicate
     * with a null user and no route-driven test can see this guard -- a mutation proved it,
     * surviving all twenty. That does not make the guard pointless: `refusal()` is a public static
     * that any future caller can reach, and `CartController::abandon_carts_handled` previously
     * called `->isAbleTo()` straight on a possibly-null user, which is the exact shape this
     * replaces.
     *
     * So it is asserted where it is reachable: on the predicate itself.
     */
    public function test_the_predicate_refuses_a_null_principal(): void
    {
        $this->assertSame(
            \App\Services\MarketingScreenAccess::DENIED,
            \App\Services\MarketingScreenAccess::refusal(null, 'Manage Cart'),
            'The predicate admitted a null principal. These routes are behind `auth`, so this is '
            . 'defence in depth -- but it is a public static, and the call site it replaced '
            . 'dereferenced a possibly-null user.'
        );
    }

    /**
     * @dataProvider readScreens
     */
    public function test_a_user_who_holds_the_permission_still_reads_the_screen(string $path, string $permission): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->grantPermission($merchant, $permission);

        $response = $this->actingAs($merchant->fresh())->get($path);

        $this->assertSame(200, $response->status(), sprintf(
            'A principal holding "%s" was refused %s, so the change broke the audience it was '
            . 'meant to leave alone.',
            $permission,
            $path
        ));
    }
}
