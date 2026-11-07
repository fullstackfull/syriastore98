<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreSettingsAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * `SettingController::customMassage` WROTE `settings` ROWS WITH NO AUTHORIZATION CHECK AT ALL.
 * =================================================================================================
 *
 * The one settings writer in the file that `StoreSettingsAccess::permits()` did not guard. Its only
 * live route is `routes/customer.php:188`, declared inside the storefront group
 * `Route::middleware([ActiveTheme::class, 'themelanguage'])` (routes/customer.php:49) -- NO `auth`,
 * NO permission -- while `routes/web.php:1066` holds a commented-out route to
 * `StoreController::customMassage`, a method that does not exist in that class. The merchant view
 * `resources/views/setting/whatsapp_msg_setting.blade.php:16` posts to the named route, so the
 * storefront declaration is the one the Settings screen actually used.
 *
 * WHAT WAS EXPOSED, AND WHAT WAS NOT. It was not an anonymous write: `Auth::user()->id` sat inside
 * `updateOrCreate`'s attribute array, so for a caller with no principal PHP's "attempt to read
 * property on null" became an `ErrorException` before the statement reached the database. MEASURED
 * on this tree before the repair -- an anonymous POST of a VALID payload answered HTTP 500 and
 * wrote no row. The live exposure was the authenticated one: ANY `web`-guard user, a staff member
 * holding no Settings permission included, could overwrite their own store's WhatsApp template.
 * Same tenant, authenticated, no permission check.
 *
 * THE GATE IS IN THE HANDLER, NOT ON THE ROUTE, and that is a decision rather than a shortcut:
 *
 *   - It is the policy the nine sibling handlers in the same file already use, to the character:
 *     `if (! StoreSettingsAccess::permits(auth()->user())) { return redirect()->back()->with('error', __('Permission denied.')); }`
 *     ahead of `session()->put()`, exactly as `StockSettings` does. One decision point, so the
 *     Settings screen's ten writers cannot drift apart.
 *   - `permits(null)` is `false`, so the SAME expression refuses the anonymous caller and the
 *     unpermitted staff member. A route-level `auth` would answer only the first of the owner's
 *     two refusal requirements, and expressing the second on the route would need a new permission
 *     middleware -- a new permission model, which this change is explicitly not allowed to create.
 *   - `permits()` asks the three limbs in the order this codebase requires: the platform operator
 *     FIRST (Laratrust runs with `permissions_as_gates => false`, so `isAbleTo()` alone refuses the
 *     operator), then the tenant OWNER via `creatorId() === id`, then `isAbleTo('Manage Setting')`.
 *
 * THE ROUTE IS DELIBERATELY LEFT IN `routes/customer.php`. Moving or re-declaring it was rejected
 * on measured grounds: the view posts to the route by NAME, the storefront group is shared with
 * every `{storeSlug?}` path, `RouteCollection` keys on method+domain+uri so a second declaration
 * silently replaces the first and takes its middleware with it, and the route carries no CSRF
 * exemption (`bootstrap/app.php:373-387` does not list `custom-msg`), so it already sits behind
 * `VerifyCsrfToken` inside the `web` group -- `routes/customer.php` is `require`d from
 * `routes/web.php:1584`. After the repair an anonymous POST is a 302 carrying "Permission denied."
 * and writes nothing, which is the same answer the other nine writers give. The test below pins
 * that the route still carries no `Authenticate`, so the fact that the protection lives in the
 * handler stays recorded rather than being rediscovered.
 *
 * THE VALIDATION REFUSAL AT :1269 WAS REPAIRED IN THE SAME CHANGE, which it could not be before:
 * its bag is TWO (`whatsapp_content`, `whatsapp_item_variable`) and `$messages->first()` showed one
 * of them. `SettingsRefusalNamesEveryFieldTest` excluded this one site precisely because reaching
 * its refusal branch required an authorization gap; with the gate in place the refusal is reachable
 * by a permitted merchant and is driven here. No validator rule, no `fails()` guard and no refusal
 * target was changed.
 */
class CustomMassageAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const NAMES = ['whatsapp_content', 'whatsapp_item_variable'];

    /** A payload that PASSES the handler's own validator, so a refusal can only be the gate. */
    private const VALID = [
        'whatsapp_content' => 'Hello {customer_name}, order {order_no} is on its way.',
        'whatsapp_item_variable' => '{product_name} x{quantity}',
    ];

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchant, $this->store] = $this->makeTenant();
    }

    /**
     * The row as the table holds it, NOT as Eloquent holds it: `Setting` is `Cachable`, so a model
     * read can answer from the in-memory store rather than from the write that was or was not made.
     */
    private function storedValue(int $storeId, string $name): ?string
    {
        $row = DB::table('settings')
            ->where('store_id', $storeId)
            ->where('name', $name)
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : (string) $row->value;
    }

    private function seedTemplate(int $storeId, string $value): void
    {
        foreach (self::NAMES as $name) {
            Setting::withoutGlobalScopes()->create([
                'name' => $name,
                'value' => $value,
                'store_id' => $storeId,
                'created_by' => $this->merchant->id,
            ]);
        }
    }

    /**
     * PREMISE FOR EVERY CASE BELOW: the route exists and reaches THIS handler. Without it a 404
     * satisfies every "no row was written" assertion in this file, which is the second of this
     * project's recorded ways a regression test passes for the wrong reason.
     */
    public function test_premise_the_route_resolves_to_this_handler(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => str_contains((string) $r->getActionName(), 'SettingController@customMassage')
        );

        $this->assertNotNull($route, 'premise: customMassage is routed somewhere');
        $this->assertContains('POST', $route->methods(), 'premise: it is reachable by POST');
        $this->assertSame(
            'custom-msg/{slug?}',
            $route->uri(),
            'premise: the uri is the storefront-group declaration at routes/customer.php:188'
        );
    }

    /**
     * ANONYMOUS. The bite is the RESPONSE SHAPE, not the absent row: before the repair this path
     * answered 500 because `Auth::user()->id` read a property on null, and "no row was written" was
     * therefore already true for the wrong reason.
     */
    public function test_an_anonymous_caller_is_refused_and_writes_nothing(): void
    {
        $this->assertNull(auth()->user(), 'premise: no principal is signed in');

        $response = $this->post(route('customMassage'), self::VALID);

        $this->assertNotSame(404, $response->getStatusCode(), 'premise: the route was reached');
        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'An anonymous POST still raises an error instead of being refused: the gate must run '
            . 'before anything dereferences Auth::user().'
        );
        $response->assertRedirect();
        $this->assertSame(__('Permission denied.'), (string) session('error'));

        foreach (self::NAMES as $name) {
            $this->assertNull(
                $this->storedValue($this->store->id, $name),
                "An anonymous caller wrote settings.{$name}."
            );
        }
        $this->assertSame(
            0,
            DB::table('settings')->whereIn('name', self::NAMES)->count(),
            'An anonymous caller wrote a whatsapp template row under some other store_id.'
        );
    }

    /**
     * AUTHENTICATED STAFF WITHOUT THE PERMISSION -- the live exposure. Two premises, because the
     * refusal must come from the permission limb and not accidentally from the owner limb.
     */
    public function test_authenticated_staff_without_the_settings_permission_is_denied(): void
    {
        $staff = $this->makeStaff($this->merchant, ['current_store' => $this->store->id]);

        $this->assertNotSame(
            (int) $staff->id,
            (int) $staff->creatorId(),
            'premise: the staff member is NOT the root of the tenant, so permits() limb 2 is false'
        );
        $this->assertFalse(
            $staff->isAbleTo(StoreSettingsAccess::PERMISSION),
            'premise: the staff member really does not hold `Manage Setting`'
        );
        $this->assertFalse(
            StoreSettingsAccess::permits($staff),
            'premise: the existing policy refuses this principal'
        );

        $this->seedTemplate($this->store->id, 'OWNER TEMPLATE');

        $response = $this->actingAs($staff)->post(route('customMassage'), self::VALID);

        $this->assertNotSame(404, $response->getStatusCode(), 'premise: the route was reached');
        $response->assertRedirect();
        $this->assertSame(
            __('Permission denied.'),
            (string) session('error'),
            'Unpermitted staff were not refused by the settings gate.'
        );

        foreach (self::NAMES as $name) {
            $this->assertSame(
                'OWNER TEMPLATE',
                $this->storedValue($this->store->id, $name),
                "Unpermitted staff overwrote settings.{$name} for their own store."
            );
        }
    }

    /** THE POSITIVE CONTROL. A merchant owner keeps the screen -- the gate must not lock them out. */
    public function test_the_tenant_owner_still_writes_the_template(): void
    {
        $this->assertTrue(
            StoreSettingsAccess::permits($this->merchant),
            'premise: the owner limb of the existing policy admits a type=admin merchant'
        );

        $response = $this->actingAs($this->merchant)->post(route('customMassage'), self::VALID);

        $response->assertRedirect();
        $this->assertNull(session('error'), 'The owner was refused: ' . (string) session('error'));
        $this->assertSame(
            __('Whatsapp setting successfully updated.'),
            (string) session('success')
        );

        foreach (self::NAMES as $name) {
            $this->assertSame(
                self::VALID[$name],
                $this->storedValue($this->store->id, $name),
                "The owner's post did not write settings.{$name}."
            );
        }
    }

    /** Staff who DO hold the permission keep the screen too -- the owner's third requirement. */
    public function test_permitted_staff_still_write_the_template(): void
    {
        $staff = $this->makeStaff($this->merchant, ['current_store' => $this->store->id]);
        $this->grantPermission($staff, StoreSettingsAccess::PERMISSION);
        $staff = User::find($staff->id);

        $this->assertTrue(
            $staff->isAbleTo(StoreSettingsAccess::PERMISSION),
            'premise: the grant took effect (Laratrust caching is disabled in SecurityTestCase)'
        );

        $response = $this->actingAs($staff)->post(route('customMassage'), self::VALID);

        $response->assertRedirect();
        $this->assertNull(session('error'), 'Permitted staff were refused: ' . (string) session('error'));

        foreach (self::NAMES as $name) {
            $this->assertSame(
                self::VALID[$name],
                $this->storedValue($this->store->id, $name),
                "Permitted staff did not write settings.{$name}."
            );
        }
    }

    /**
     * CROSS-TENANT. The route takes a `{slug?}` the handler never reads, and the write is keyed on
     * `getCurrentStore()` -- derived from the PRINCIPAL's `current_store`, not from caller input.
     * This pins that: merchant B posts with store A's slug in the path and A's row must be
     * untouched. (It is the property the repair must not lose, not a defect being fixed.)
     */
    public function test_a_merchant_cannot_reach_another_tenants_template_through_the_slug(): void
    {
        $this->seedTemplate($this->store->id, 'STORE A TEMPLATE');

        [$otherMerchant, $otherStore] = $this->makeTenant();

        $this->assertNotSame((int) $this->store->id, (int) $otherStore->id, 'premise: two stores');
        $this->assertSame(
            'STORE A TEMPLATE',
            $this->storedValue($this->store->id, 'whatsapp_content'),
            'premise: store A holds a template before the foreign post'
        );

        $response = $this->actingAs($otherMerchant)
            ->post(route('customMassage', ['slug' => $this->store->slug]), self::VALID);

        $response->assertRedirect();
        $this->assertNull(session('error'), 'premise: merchant B was admitted, so the write ran');

        foreach (self::NAMES as $name) {
            $this->assertSame(
                'STORE A TEMPLATE',
                $this->storedValue($this->store->id, $name),
                "Merchant B rewrote store A's settings.{$name} through the slug."
            );
            $this->assertSame(
                self::VALID[$name],
                $this->storedValue($otherStore->id, $name),
                "Merchant B's own settings.{$name} was not written, so the case proves nothing."
            );
        }
    }

    /**
     * THE VALIDATION REFUSAL, DRIVEN. The bag is TWO, so `$messages->first()` discarded one of the
     * two reasons the merchant's template was rejected. Reachable only now that a permitted caller
     * can get past the gate -- which is why `SettingsRefusalNamesEveryFieldTest` excluded it.
     */
    public function test_a_refused_post_names_both_failing_fields(): void
    {
        $this->actingAs($this->merchant)->post(route('customMassage'), []);

        $flash = (string) session('error');

        $this->assertNotSame('', $flash, 'premise: an empty post is still refused');
        $this->assertNotSame(
            __('Permission denied.'),
            $flash,
            'premise: the merchant passed the gate, so this reading is about VALIDATION'
        );

        // Both fields by name, and two sentences -- the same proxy the settings group uses.
        $this->assertStringContainsString('whatsapp content', $flash, "flash was: {$flash}");
        $this->assertStringContainsString('whatsapp item variable', $flash, "flash was: {$flash}");
        $this->assertSame(2, preg_match_all('/\.(\s|$)/', $flash), "flash was: {$flash}");
        $this->assertSame(trim($flash), $flash, "the join padded the flash: '{$flash}'");

        foreach (self::NAMES as $name) {
            $this->assertNull(
                $this->storedValue($this->store->id, $name),
                "A refused post still wrote settings.{$name}."
            );
        }
    }

    /**
     * THE PLATFORM OPERATOR IS NOT LOCKED OUT. `isAbleTo()` alone refuses a `super admin` here, so
     * an operator-last ordering would be a lockout rather than a tightening -- recorded in
     * `FinancePermission` as having happened once already.
     */
    public function test_the_platform_operator_is_not_locked_out(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => $this->store->id]);

        $this->assertFalse(
            $operator->isAbleTo(StoreSettingsAccess::PERMISSION),
            'premise: the operator holds no role rows, so only the operator limb can admit them'
        );

        $response = $this->actingAs($operator)->post(route('customMassage'), self::VALID);

        $response->assertRedirect();
        $this->assertNull(
            session('error'),
            'The platform operator was locked out of the WhatsApp template: ' . (string) session('error')
        );
        $this->assertSame(
            self::VALID['whatsapp_content'],
            $this->storedValue($this->store->id, 'whatsapp_content')
        );
    }
}
