<?php

namespace Tests\Feature\Merchant;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F-validation-errors-lose-the-modal, the STORE + STAFF group -- the last three controllers that
 * still flashed only the FIRST message of a multi-field validation bag.
 * =================================================================================================
 *
 * `ModalFormRefusalFeedbackTest` landed the repair on `CategoryController`;
 * `ValidationRefusalNamesEveryFieldTest` landed it on seven more (18 sites). This file is the
 * remainder -- `StoreController`, `UserController` and `RoleController` -- and applies the one
 * established expression, nothing else:
 *
 *     -  return redirect()->back()->with('error', $messages->first());
 *     +  return redirect()->back()->with('error', implode(' ', $messages->all()));
 *
 * SIX sites are repaired, at StoreController:116, :478, :551, UserController:96 and
 * RoleController:146, :277. FOUR MORE SITES EXIST IN THESE SAME THREE FILES AND ARE DELIBERATELY
 * LEFT ALONE; section 5 pins them as untouched and says why for each.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT WAS MEASURED, NOT ASSUMED. Every rule set below was run through `Validator::make` and the
 * bag counted (scratch script, 2026-11-04), then every site was driven over real HTTP with a real
 * principal. Five of the six are OBSERVABLE -- a real request produces 2 to 4 messages, of which
 * `first()` showed one:
 *
 *     StoreController::store()   super-admin branch   4 messages (storename, name, email, password)
 *     StoreController::update()                       3 messages (name, storename, email)
 *     StoreController::pwaSetting()                   4 messages (title, name, background, theme)
 *     UserController::store()                         2 messages (name, email)
 *     RoleController::store() / ::update()            2 messages (name, permissions)
 *
 * THE SIXTH IS BOTH, AND THAT IS THE INTERESTING ONE. `StoreController::store()` picks its
 * validator by `users.type` at :94 -- `type == 'admin'` (a MERCHANT) gets exactly ONE rule,
 * `['storename' => 'required']`, while every other type gets four. So the single site at :116 is
 * OBSERVABLE for the super admin and provably INERT for the merchant: no request a merchant can
 * make will put a second message in that bag, and for them the join is byte-identical to
 * `first()`. Both halves are driven below, and the merchant half is the negative control --
 * `test_the_merchant_branch_of_store_create_still_flashes_exactly_one_message`.
 *
 * `create.blade.php` agrees: it renders the name/email/password inputs only
 * `@if (Auth::user()->type == 'super admin')`.
 *
 * ------------------------------------------------------------------------------------------------
 * THE TRAP, which section 4 pins. `AppSettingController` builds SIX validators and consults TWO,
 * so handing a DORMANT validator a `fails()` guard would start REFUSING posts that succeed today
 * -- a business-rule change wearing a repair's clothes. MEASURED HERE on comment-stripped source:
 *
 *     StoreController   5 `Validator::make(`   4 `->fails()`
 *     UserController    4                      4
 *     RoleController    2                      2
 *
 * STORECONTROLLER'S 5-vs-4 IS BENIGN AND IS NOT A DORMANT VALIDATOR, which is why this file does
 * NOT copy `ValidationRefusalNamesEveryFieldTest`'s built-equals-consulted assertion. :95 and :102
 * are the two branches of ONE `$validator`, consulted once at :113. Nothing in these three files
 * is built and never checked, so all six sites were already live refusal branches and this change
 * alters only the MESSAGE of a refusal that already happened. No `fails()` guard was added.
 *
 * ------------------------------------------------------------------------------------------------
 * RESPONSE SHAPE. Still a 302, still the `error` flash key. `StoreController` and `RoleController`
 * carry no `withErrors()` / `withInput()` / `expectsJson()` at all. `UserController` carries TWO of
 * each ALREADY, at :429 and :493, and those are the two sites section 5 leaves alone -- so this
 * file pins those counts at their measured values rather than at zero.
 * ------------------------------------------------------------------------------------------------
 */
class StoreAndStaffRefusalNamesEveryFieldTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The three owned files, with the MEASURED split between repaired sites and the sites left
     * alone. [total `getMessageBag()` sites, sites carrying the join, sites still on `first()`].
     */
    private const OWNED = [
        'app/Http/Controllers/StoreController.php' => [4, 3, 1],
        'app/Http/Controllers/UserController.php' => [4, 1, 3],
        'app/Http/Controllers/RoleController.php' => [2, 2, 0],
    ];

    /** Measured 2026-11-04 on comment-stripped source: [Validator::make(, ->fails()]. */
    private const VALIDATOR_CENSUS = [
        'app/Http/Controllers/StoreController.php' => [5, 4],
        'app/Http/Controllers/UserController.php' => [4, 4],
        'app/Http/Controllers/RoleController.php' => [2, 2],
    ];

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        foreach (['Manage User', 'Create User', 'Edit User',
            'Manage Role', 'Create Role', 'Edit Role'] as $permission) {
            $this->grantPermission(User::find($this->merchant->id), $permission);
        }

        $this->merchant = User::find($this->merchant->id);
    }

    /**
     * A controller's source with every comment REMOVED, via `token_get_all()`.
     *
     * The project skill's rule: "strip comment lines from any source-grep ratchet -- a docblock
     * quoting the string a ratchet greps for makes the ratchet satisfiable by prose." Every
     * assertion in section 4 and 5 is a COUNT, and THIS FILE'S OWN HEADER quotes both needles, so
     * the rule is not hypothetical here. T_COMMENT and T_DOC_COMMENT are dropped; nothing inside a
     * string literal is touched.
     */
    private function codeWithoutComments(string $relative): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(base_path($relative))) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * Drive a refusal and hand back the flashed `error` string, having first proved the premise:
     * a 302 back to the referer that is neither a 403, a 404, a 500, nor the permission branch.
     */
    private function refusalFlash(
        User $actor,
        string $uri,
        array $payload,
        string $referer = '/dashboard',
        string $method = 'post'
    ): string {
        $response = $this->actingAs($actor)->from($referer)->{$method}($uri, $payload);

        /* THE PREMISE, all four of the ways the project skill records a refusal test passing for
           the wrong reason: a 404 (route gone), a 403/500 (never reached the validator), or the
           permission branch (fixture missing a permission). */
        $this->assertSame(302, $response->status(),
            "{$uri} answered {$response->status()}, not the 302 of a validation refusal, so this "
            . 'test is not measuring the validation branch');
        $response->assertRedirect($referer);

        $flash = (string) $response->getSession()->get('error');

        $this->assertNotSame('', $flash,
            "{$uri} flashed nothing at all, so this is not the validation branch");
        $this->assertStringNotContainsString('Permission denied', $flash,
            "{$uri} answered with the PERMISSION refusal, not the validation one -- the fixture is "
            . 'missing a permission and the test is measuring the wrong return');

        return $flash;
    }

    // =============================================================================================
    //  1. THE SUPER-ADMIN STORE SCREENS -- Phase 06 surfaces, driven as the platform operator
    // =============================================================================================

    /**
     * `StoreController::store()` :116 on its SUPER-ADMIN branch: four `required` rules, one
     * message shown. This is the branch `create.blade.php` renders the extra three inputs for.
     */
    public function test_the_four_rule_super_admin_store_create_refusal_names_all_four_fields(): void
    {
        $operator = $this->makePlatformAdmin();

        $flash = $this->refusalFlash($operator, '/stores', []);

        $expected = implode(' ', \Validator::make([], [
            'storename' => 'required',
            'name' => 'required',
            'email' => 'required|unique:users,email',
            'password' => 'required',
        ])->getMessageBag()->all());

        $this->assertSame($expected, $flash,
            'the super-admin store-create refusal does not flash the whole message bag joined by '
            . 'one space');

        foreach (['storename', 'name', 'email', 'password'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the refusal does not name '{$field}', so that field is still discarded");
        }

        $this->assertSame(4, substr_count($flash, 'is required'),
            'the refusal does not carry four messages, so some failing field is still discarded');

        /* THE CONTROL ON THE CONTROL: first() really did hide three of them. */
        $first = (string) \Validator::make([], [
            'storename' => 'required', 'name' => 'required',
            'email' => 'required', 'password' => 'required',
        ])->getMessageBag()->first();
        $this->assertSame('The storename field is required.', $first,
            'first() no longer returns one message, so the defect being closed is not the defect '
            . 'that was measured');
        $this->assertStringNotContainsString('password', $first);

        /* AND NOTHING WAS WRITTEN: the refusal still precedes the insert. */
        $this->assertSame(1, Store::withoutGlobalScopes()->count(),
            'the refused store was created anyway');
    }

    /**
     * `StoreController::update()` :478 -- three rules, and the only one shown was `name`. Driven
     * as the platform operator, because the whole method is gated on `type == 'super admin'`
     * at :463.
     */
    public function test_the_three_rule_super_admin_store_update_refusal_names_all_three_fields(): void
    {
        $operator = $this->makePlatformAdmin();

        $flash = $this->refusalFlash($operator, '/stores/' . $this->store->id, [], '/dashboard', 'put');

        $expected = implode(' ', \Validator::make([], [
            'name' => 'required|max:120',
            'storename' => 'required|max:120',
            'email' => 'required',
        ])->getMessageBag()->all());

        $this->assertSame($expected, $flash);

        foreach (['name', 'storename', 'email'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the store update refusal does not name '{$field}'");
        }
        $this->assertSame(3, substr_count($flash, 'is required'));

        /* THE STORE IS UNTOUCHED -- read raw, because Store is Cachable. */
        $this->assertSame($this->store->name,
            (string) \DB::table('stores')->where('id', $this->store->id)->value('name'),
            'the refused update wrote to the store anyway');
    }

    /**
     * THE ONE GENUINE MERCHANT SURFACE IN THIS GROUP. `StoreController::pwaSetting()` :551 --
     * Settings -> PWA, four rules, one message. The validator runs only when `pwa_store == 'on'`
     * (:539), which is what the real form posts, and it sits BELOW `resolveOwnedStore()`/
     * `abort(403)`, so the wider message discloses nothing to a non-owner.
     */
    public function test_the_four_rule_merchant_pwa_refusal_names_all_four_fields(): void
    {
        $flash = $this->refusalFlash(
            $this->merchant,
            '/pwa-settings/' . $this->store->id,
            ['pwa_store' => 'on']
        );

        $expected = implode(' ', \Validator::make([], [
            'pwa_app_title' => 'required|max:100',
            'pwa_app_name' => 'required|max:50',
            'pwa_app_background_color' => 'required|max:15',
            'pwa_app_theme_color' => 'required|max:15',
        ])->getMessageBag()->all());

        $this->assertSame($expected, $flash);

        foreach (['pwa app title', 'pwa app name', 'pwa app background color',
            'pwa app theme color'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the PWA refusal does not name '{$field}', so the merchant still has to guess");
        }
        $this->assertSame(4, substr_count($flash, 'is required'));
    }

    /**
     * AND A NON-OWNER STILL GETS 403 RATHER THAN THE WIDER FIELD LIST. The ownership gate stays
     * above the validator, so the four field names are not readable by another tenant.
     */
    public function test_a_non_owner_gets_403_from_the_pwa_screen_and_never_sees_the_field_list(): void
    {
        $intruder = $this->makeMerchant();
        $intruderStore = $this->makeStore($intruder);
        $intruder->current_store = $intruderStore->id;
        $intruder->save();

        $response = $this->actingAs(User::find($intruder->id))
            ->from('/dashboard')
            ->post('/pwa-settings/' . $this->store->id, ['pwa_store' => 'on']);

        $response->assertStatus(403);
        $this->assertStringNotContainsString('pwa app title', $response->getContent(),
            'the PWA field list leaked to a merchant who does not own the store');
    }

    /**
     * THE ONE PRINCIPAL WHOSE MESSAGE GENUINELY WIDENS WITHOUT HOLDING ANY PERMISSION, pinned here
     * rather than left for somebody to rediscover.
     *
     * `StoreController::store()` runs its validator at :113 and only then branches on `users.type`
     * -- the merchant limb at :124, the operator limb at :251, and `Permission denied.` at :427.
     * So a STAFF member, who is neither, reads the validation refusal first and the permission
     * refusal only once the form validates. That ordering is PRE-EXISTING and is deliberately NOT
     * changed here: moving a gate above a validator changes WHICH principals are refused, which is
     * an authorization change and not this repair.
     *
     * WHAT THE WIDENING COSTS, MEASURED. Before: a staff member saw `storename`. After: all four
     * of `storename`, `name`, `email`, `password`. Those are the four static field names of the
     * super-admin Create Store form and nothing else -- no row, no id, no tenant data, and no
     * existence oracle: the bag is identical whatever the staff member posts, and a VALID post
     * still answers `Permission denied.` (asserted below, so this is not a guess about the gate).
     * Accepted as bounded; recorded so a future reviewer weighs it rather than meeting it by
     * surprise.
     */
    public function test_an_unpermitted_staff_member_reads_the_field_list_but_is_still_refused(): void
    {
        $staff = $this->makeStaff(User::find($this->merchant->id), ['current_store' => $this->store->id]);
        $staff = User::find($staff->id);

        $flash = $this->refusalFlash($staff, '/stores', []);

        /* The four field names, and provably NOTHING ELSE: byte-identical to the bag. */
        $this->assertSame(implode(' ', \Validator::make([], [
            'storename' => 'required',
            'name' => 'required',
            'email' => 'required|unique:users,email',
            'password' => 'required',
        ])->getMessageBag()->all()), $flash,
            'the staff refusal now carries something other than the four static field names, so '
            . 'the disclosure this test accepts as bounded is no longer bounded');

        /* AND THE GATE STILL HOLDS: a staff member who satisfies the validator is refused, so the
           widened wording buys no access. If this ever flips, the repair stopped being a wording
           change and became an authorization change. */
        $valid = $this->actingAs($staff)->from('/dashboard')->post('/stores', [
            'storename' => 'Staff probe',
            'name' => 'Staff probe',
            'email' => 'staff-probe@example.test',
            'password' => 'secret-probe-123',
        ]);

        $this->assertStringContainsString('Permission denied.',
            (string) $valid->getSession()->get('error'),
            'a staff member who satisfies the validator is no longer refused by the permission '
            . 'branch, so this change did more than reword a refusal');
        $this->assertSame(1, Store::withoutGlobalScopes()->count(),
            'a staff member created a store');
    }

    // =============================================================================================
    //  2. THE MERCHANT STAFF AND ROLE SCREENS
    // =============================================================================================

    /**
     * `UserController::store()` :96 -- staff create. `name` and `email` both fail and only `name`
     * was reported.
     *
     * The widened message enumerates nothing a caller could not already learn: the `unique` rule
     * here is scoped to the caller's OWN `created_by`, and an unscoped cross-tenant probe already
     * answers above it at :77-79.
     */
    public function test_the_two_rule_staff_create_refusal_names_both_fields(): void
    {
        $before = User::withoutGlobalScopes()->count();

        $flash = $this->refusalFlash($this->merchant, '/users', []);

        $expected = implode(' ', \Validator::make([], [
            'name' => 'required',
            'email' => 'required',
        ])->getMessageBag()->all());

        $this->assertSame($expected, $flash,
            'the staff-create refusal does not flash both failing fields');
        $this->assertStringContainsString('email', $flash,
            'the email failure -- the message first() discarded -- is still not reported');
        $this->assertSame(2, substr_count($flash, 'is required'));

        /* NOT `where('created_by', $merchant->id)`: `makeMerchant()` sets `created_by => 1` and
           the merchant IS user 1 in this fixture, so that predicate counts the merchant itself and
           the assertion read 1 where it meant 0 (measured). A before/after total cannot. */
        $this->assertSame($before, User::withoutGlobalScopes()->count(),
            'the refused staff user was created anyway');
    }

    /**
     * `RoleController::store()` :146 -- `name` and `permissions`, and `permissions` was discarded
     * on a form whose permission grid is the entire point.
     */
    public function test_the_role_create_refusal_reports_the_missing_permissions_too(): void
    {
        $flash = $this->refusalFlash($this->merchant, '/roles', []);

        $expected = implode(' ', \Validator::make([], [
            'name' => ['required', 'max:100'],
            'permissions' => 'required',
        ])->getMessageBag()->all());

        $this->assertSame($expected, $flash);
        $this->assertStringContainsString('permissions', $flash,
            'the role-create refusal still discards "permissions is required" on a form whose '
            . 'permission grid is the point');
        $this->assertSame(2, substr_count($flash, 'is required'));

        $this->assertSame(0, Role::where('store_id', $this->store->id)
            ->where('name', '')->count(), 'the refused role was created anyway');
    }

    /**
     * THE RESERVED-NAME AND LENGTH REFUSALS ARE THE ONES A MERCHANT MOST NEEDS TO READ ALONGSIDE
     * the permissions failure. A 101-character name fails `max:100` AND leaves `permissions`
     * failing, and `first()` showed only the length.
     */
    public function test_the_role_create_length_refusal_still_reports_the_missing_permissions(): void
    {
        $flash = $this->refusalFlash($this->merchant, '/roles', ['name' => str_repeat('n', 111)]);

        $expected = implode(' ', \Validator::make(['name' => str_repeat('n', 111)], [
            'name' => ['required', 'max:100'],
            'permissions' => 'required',
        ])->getMessageBag()->all());

        $this->assertSame($expected, $flash);
        $this->assertStringContainsString('100', $flash, 'the length failure is not reported');
        $this->assertStringContainsString('permissions', $flash,
            'the permissions failure is still discarded behind the length failure');
    }

    /**
     * `RoleController::update()` :277, driven as the merchant who really OWNS the role, so
     * `authorize('update', $role)` is passed rather than bypassed. Renaming is this site's blind
     * spot (see the controller comment at :264-267), which is exactly why its refusal has to be
     * readable.
     */
    public function test_the_role_update_refusal_reports_the_missing_permissions_too(): void
    {
        $role = $this->makeRole(User::find($this->merchant->id), $this->store, 'Shop floor');

        $flash = $this->refusalFlash($this->merchant, '/roles/' . $role->id, [], '/dashboard', 'put');

        $expected = implode(' ', \Validator::make([], [
            'name' => ['required', 'max:100'],
            'permissions' => 'required',
        ])->getMessageBag()->all());

        $this->assertSame($expected, $flash);
        $this->assertStringContainsString('permissions', $flash,
            'the role-update refusal still discards the permissions failure');
        $this->assertSame(2, substr_count($flash, 'is required'));

        /* THE ROLE IS UNTOUCHED. Role is one of the 16 non-Cachable models, but read raw anyway. */
        $this->assertSame('Shop floor',
            (string) \DB::table('roles')->where('id', $role->id)->value('name'),
            'the refused update renamed the role anyway');
    }

    // =============================================================================================
    //  3. THE NEGATIVE CONTROL -- the branch that is provably INERT must stay byte-identical
    // =============================================================================================

    /**
     * THE POINT OF SITE :116, AND THE CONTROL THE REPAIR HAS TO SURVIVE.
     *
     * `store()` chooses its validator by `users.type`. A MERCHANT (`type == 'admin'`) gets exactly
     * one rule, `['storename' => 'required']`, so no request a merchant can make will put a second
     * message in that bag and the join is provably the same bytes as `first()`. A repair satisfied
     * by `implode()` over something that pads, reorders or re-separates a one-message bag dies
     * here -- and so does one that changed the wording on a live merchant screen by accident.
     */
    public function test_the_merchant_branch_of_store_create_still_flashes_exactly_one_message(): void
    {
        $flash = $this->refusalFlash($this->merchant, '/stores', []);

        $expected = (string) \Validator::make([], ['storename' => 'required'])
            ->getMessageBag()->first();

        $this->assertSame($expected, $flash,
            'the MERCHANT branch of store create no longer flashes exactly the validator\'s own '
            . 'message. Its bag can only ever hold one message, so a join that pads, reorders or '
            . 're-separates it is a user-visible wording change on a merchant screen that was not '
            . 'meant to change at all.');

        $this->assertSame($flash, trim($flash), 'the joined single message gained outer padding');
        $this->assertSame(1, substr_count($flash, '.'),
            'the merchant store-create refusal now carries more than one sentence');
        $this->assertStringNotContainsString('password', $flash,
            'the merchant branch is now reporting the SUPER-ADMIN branch\'s field list, so the '
            . 'change did not stay inside the message expression');
    }

    /**
     * AND THE REFUSALS DID NOT WIDEN. The positive control for the trap: this change may alter the
     * WORDING of a refusal that already happened, never WHETHER one happens. A valid role create
     * must still be accepted, and `pwa_store=off` must still skip the validator entirely.
     */
    public function test_a_valid_post_is_still_accepted_on_the_screens_this_change_touched(): void
    {
        $response = $this->actingAs($this->merchant)->from('/dashboard')->post('/roles', [
            'name' => 'Shop floor',
            'permissions' => ['Manage User'],
        ]);

        $this->assertSame('', (string) $response->getSession()->get('error'),
            'roles.store refused a VALID post. The repair was supposed to change the message of '
            . 'an existing refusal, not create a new one.');
        $this->assertSame(1, Role::where('store_id', $this->store->id)
            ->where('name', 'Shop floor')->count(),
            'roles.store did not create the role for a valid post');

        /* And the PWA validator still does not fire when the panel is switched off, which is the
           only way `pwaSetting` can be posted without those four fields. */
        $off = $this->actingAs($this->merchant)->from('/dashboard')
            ->post('/pwa-settings/' . $this->store->id, ['pwa_store' => 'off']);

        $this->assertStringNotContainsString('pwa app title',
            (string) $off->getSession()->get('error'),
            'pwaSetting now refuses a post with the PWA panel switched off, so a fails() guard or '
            . 'a rule changed -- which is a business-rule change and not this repair');
    }

    // =============================================================================================
    //  4. THE RATCHET, and the trap pinned on the source
    // =============================================================================================

    /**
     * Each owned controller must carry the join at every REPAIRED site, and `first()` must survive
     * at exactly the sites section 5 leaves alone. Asserted on the source because the counts are
     * what pin the four excluded sites as untouched.
     */
    public function test_each_owned_controller_carries_the_join_at_exactly_the_repaired_sites(): void
    {
        $joined = 0;

        foreach (self::OWNED as $relative => [$sites, $repaired, $leftAlone]) {
            $source = $this->codeWithoutComments($relative);

            /* THE PREMISE: the measured site count still covers the file. A new validator refusal
               would otherwise slip past both counts below. */
            $this->assertSame($sites, substr_count($source, 'getMessageBag()'),
                "{$relative} no longer has exactly {$sites} validator refusal sites, so the counts "
                . 'below no longer cover the file. Re-read the handler before trusting this test.');

            $this->assertSame($repaired, substr_count($source, "implode(' ', \$messages->all())"),
                "{$relative} must flash the JOINED message bag at exactly {$repaired} refusal "
                . 'sites (WCAG 3.3.1: every failing field named).');

            $this->assertSame($leftAlone, substr_count($source, "\$messages->first())"),
                "{$relative} must keep \$messages->first() at exactly {$leftAlone} sites -- the "
                . 'ones section 5 excludes. More means a repaired site regressed; fewer means an '
                . 'excluded site was changed without reading why it is excluded.');

            $joined += $repaired;
        }

        $this->assertSame(6, $joined, 'the six repaired refusal sites are no longer six');
    }

    /**
     * THE TRAP. `AppSettingController` builds six validators and consults two; activating a
     * dormant one would start REFUSING posts that succeed today.
     *
     * This does NOT assert built == consulted, because `StoreController` is legitimately 5 vs 4:
     * :95 and :102 are the two branches of ONE `$validator`, consulted once at :113. The measured
     * pair is pinned per file instead, which fails in both directions -- a new `fails()` guard
     * (consulted rises) or a new validator (built rises).
     */
    public function test_no_owned_controller_gained_a_validator_or_a_fails_guard(): void
    {
        foreach (self::VALIDATOR_CENSUS as $relative => [$expectedBuilt, $expectedConsulted]) {
            $source = $this->codeWithoutComments($relative);

            $built = substr_count($source, 'Validator::make(');
            $consulted = substr_count($source, '->fails()');

            $this->assertSame($expectedConsulted, $consulted,
                "{$relative} now consults {$consulted} validators, not {$expectedConsulted}. "
                . 'Giving a validator a fails() guard it did not have starts REFUSING posts that '
                . 'succeed today, which is a business-rule change and not this repair. '
                . '(AppSettingController is the live example: 6 built, 2 consulted.)');

            $this->assertSame($expectedBuilt, $built,
                "{$relative} now builds {$built} validators, not {$expectedBuilt}. A new "
                . 'validator is a new refusal, not a reworded one.');
        }
    }

    /** The response shape is untouched on every owned file. */
    public function test_the_refusal_shape_did_not_change_on_any_owned_controller(): void
    {
        /* NOT zero for UserController: :429 and :493 ALREADY return withErrors()+withInput(), and
           they are two of the four sites section 5 leaves alone. Pinned at their measured values
           so neither a removal nor a copy-paste onto a repaired site goes unnoticed. */
        foreach ([
            'app/Http/Controllers/StoreController.php' => ['withErrors(' => 0, 'withInput(' => 0, 'expectsJson(' => 0],
            'app/Http/Controllers/UserController.php' => ['withErrors(' => 2, 'withInput(' => 2, 'expectsJson(' => 0],
            'app/Http/Controllers/RoleController.php' => ['withErrors(' => 0, 'withInput(' => 0, 'expectsJson(' => 0],
        ] as $relative => $needles) {
            $source = $this->codeWithoutComments($relative);

            foreach ($needles as $needle => $expected) {
                $this->assertSame($expected, substr_count($source, $needle),
                    "{$relative} now calls {$needle} {$this->occurrences($source, $needle)} times, not "
                    . "{$expected}. This repair changes the message expression and nothing else.");
            }
        }

        /* And the flash key is still `error` at every repaired site. */
        foreach (self::OWNED as $relative => [, $repaired,]) {
            $this->assertSame($repaired, substr_count(
                $this->codeWithoutComments($relative),
                "->with('error', implode(' ', \$messages->all()))"
            ), "{$relative} changed the flash key or the redirect at a repaired refusal site");
        }
    }

    private function occurrences(string $source, string $needle): int
    {
        return substr_count($source, $needle);
    }

    // =============================================================================================
    //  5. THE FOUR SITES IN THESE FILES THAT ARE DELIBERATELY NOT REPAIRED
    // =============================================================================================

    /**
     * These four are in the owned files and keep `$messages->first()`. Recorded here so nobody
     * "finishes the job" without reading why, and asserted so the exclusion is a fact about the
     * source rather than a note in a report.
     *
     * TWO ARE PASSWORD SCREENS WHOSE TWO MESSAGES RESTATE EACH OTHER. `StoreController:750`
     * (`storeResetPasswordUpdate`) and `UserController:386` (`updatePassword`) both validate
     * `'password' => 'required|confirmed|same:password_confirmation'`, and `confirmed` and
     * `same:password_confirmation` are the SAME CHECK WRITTEN TWICE. Measured: a mismatch bag is
     * ['The password confirmation does not match.', 'The password and password confirmation must
     * match.'] -- two sentences carrying one fact, naming no field `first()` did not. Joining them
     * prints the same instruction twice, which is strictly worse than printing it once. The
     * redundant rule pair is the real defect there and is a separate change.
     *
     * THE EXCLUSION RESTS ON THE REDUNDANCY ALONE, NOT ON READING TIME, because reading time is
     * not the constraint here: `custom.js:342-358` gives an ERROR toast a 0ms timeout -- it
     * persists until dismissed -- whenever `ss_errors_persist()` is true, and it is true on every
     * one of these screens because `#commanModelOver` is rendered by `layouts/app.blade.php:575`
     * itself. Measured. A three- or four-sentence refusal is therefore readable, which is what
     * makes the join worth doing at the other six sites and leaves no reading-time argument for
     * these two.
     *
     * TWO ALREADY DELIVER THE WHOLE BAG BY A BETTER ROUTE. `UserController:429` (`editprofile`)
     * and `:493` (`password_update`) return `->withErrors($validator)->withInput(...)` beside the
     * toast, so every message reaches the `@error` blocks in `users/profile.blade.php` field by
     * field -- the Class A shape pinned by `ProfileValidationFeedbackTest`. The WCAG 3.3.1 failure
     * the join closes elsewhere is already closed there, and the one-line toast is deliberate.
     */
    public function test_the_four_excluded_sites_are_untouched(): void
    {
        $excluded = [
            'app/Http/Controllers/StoreController.php' => [
                750 => 'password reset: `confirmed` and `same:password_confirmation` are one check '
                    . 'written twice, so the bag says the same thing twice',
            ],
            'app/Http/Controllers/UserController.php' => [
                386 => 'the same duplicate rule pair as StoreController:750',
                429 => 'editprofile already returns withErrors()+withInput(), so the bag reaches '
                    . 'the per-field @error blocks; the toast is a deliberate summary',
                493 => 'password_update: the same Class A shape, and a password screen',
            ],
        ];

        foreach ($excluded as $relative => $lines) {
            $source = explode("\n", (string) file_get_contents(base_path($relative)));

            foreach ($lines as $line => $why) {
                $this->assertStringContainsString("\$messages->first()", $source[$line - 1],
                    "{$relative}:{$line} no longer carries \$messages->first(). It is excluded "
                    . "because {$why}. Either the exclusion was overridden without reading it, or "
                    . 'an edit above it moved the line -- re-measure before changing this number.');
            }
        }
    }

    /**
     * AND THE DUPLICATE-RULE FACT THE EXCLUSION RESTS ON IS MEASURED, NOT ASSERTED IN PROSE.
     *
     * If somebody later removes `same:password_confirmation` from either password validator, the
     * bag stops repeating itself and the exclusion above stops being justified. This fails then,
     * which is the point.
     */
    public function test_the_excluded_password_sites_really_do_say_the_same_thing_twice(): void
    {
        $bag = \Validator::make(
            ['password' => 'abc', 'password_confirmation' => 'xyz'],
            ['password' => 'required|confirmed|same:password_confirmation']
        )->getMessageBag();

        $this->assertCount(2, $bag->all(),
            'the password validator no longer produces two messages for one mismatch');

        foreach ($bag->all() as $message) {
            $this->assertStringContainsString('match', $message,
                'the two password messages no longer both restate the confirmation mismatch, so '
                . 'the HARMFUL-TO-FIX exclusion in test_the_four_excluded_sites_are_untouched no '
                . 'longer holds and those two sites should be re-measured');
        }

        /* Both sentences are about `password`, so the join names no field first() did not. */
        $this->assertSame([], array_values(array_diff(array_keys($bag->messages()), ['password'])),
            'the password bag now carries a message about a field other than `password`, so the '
            . 'join WOULD add information and the exclusion should be reconsidered');
    }
}
