<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE CREDENTIAL BOUNDARIES THE OWNER ASKED ABOUT: can merchant, super-admin, customer or
 * delivery-driver credentials cross an account or tenant boundary?
 *
 * Everything here was found by an independent adversarial audit of the password-recovery packages
 * and then confirmed against the code. The recovery handlers themselves held up; what did not was
 * the rest of the surface around them.
 *
 * ONE PATTERN ACCOUNTS FOR MOST OF IT. App\Http\Middleware\CustomAuth ('custom.auth') resolves a
 * Sanctum token and logs its owner into whichever guard matches the tokenable CLASS — Customer to
 * `customers`, User to `web`, DeliveryBoy to `deliveryboy`. A handler that then writes
 *
 *     if (! auth('customers')->user()) { $id = $request->whatever_id; }
 *
 * is reachable by a MERCHANT's or a DRIVER's token with `auth('customers')` null, and takes the
 * request-supplied branch. Four handlers did exactly that:
 *
 *     Api\ApiController::change_password            (closed in the previous package)
 *     Api\DeliveryBoyApiController::resetPassword   (closed in the previous package)
 *     AccountProfileController::store               (closed — CustomerAccountSelfServiceTest)
 *     Api\AuthController::logout                    (closed here)
 *
 * The other half is `store_id` arriving in a QUERY STRING and being used as a tenant predicate. A
 * predicate built from a value the caller supplies compares the caller's own two values against
 * each other and authorises nothing.
 */
class CredentialBoundaryTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'cred-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function driverFor(User $merchant, $store, string $label = 'd'): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver ' . $label,
            'email' => 'driver-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
    }

    private function as(?string $bearer)
    {
        return $this->withHeaders($bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer]);
    }

    // ===================================================================================
    // A.  api/{slug}/logout  — the fourth instance of the request-supplied-target pattern
    // ===================================================================================

    public function test_a_merchant_token_cannot_revoke_a_customers_sessions(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a1');
        $victimToken = $victim->createToken('auth_token');

        $this->as($merchant->createToken('t')->plainTextToken)
            ->postJson('/api/' . $store->slug . '/logout', ['customer_id' => $victim->id]);

        $this->assertNotNull(
            \Laravel\Sanctum\PersonalAccessToken::find($victimToken->accessToken->id),
            "A merchant's token revoked a customer's sessions by numeric id."
        );
    }

    public function test_a_driver_token_cannot_revoke_a_customers_sessions_in_another_store(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'a2');
        $victimToken = $victim->createToken('auth_token');
        $driver = $this->driverFor($merchantB, $storeB, 'a2');

        $this->as($driver->createToken('t')->plainTextToken)
            ->postJson('/api/' . $storeB->slug . '/logout', ['customer_id' => $victim->id]);

        $this->assertNotNull(
            \Laravel\Sanctum\PersonalAccessToken::find($victimToken->accessToken->id),
            "A delivery driver's token revoked a customer's sessions in another store."
        );
    }

    public function test_one_customer_cannot_revoke_anothers_sessions(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a3-victim');
        $attacker = $this->customerIn($store, 'a3-attacker');
        $victimToken = $victim->createToken('auth_token');

        $this->as($attacker->createToken('t')->plainTextToken)
            ->postJson('/api/' . $store->slug . '/logout', ['customer_id' => $victim->id]);

        $this->assertNotNull(
            \Laravel\Sanctum\PersonalAccessToken::find($victimToken->accessToken->id),
            "One customer's token revoked another's sessions.");
    }

    public function test_a_customer_can_still_log_themselves_out(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'a4');
        $own = $customer->createToken('auth_token');

        $this->as($own->plainTextToken)
            ->postJson('/api/' . $store->slug . '/logout', ['customer_id' => $customer->id])
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $this->assertNull(
            \Laravel\Sanctum\PersonalAccessToken::find($own->accessToken->id),
            'A customer can no longer log themselves out; the fix broke the journey.');
    }

    // ===================================================================================
    // B.  Delivery-driver credentials:  create, rename, reset
    //
    // TWO OF THESE THREE ENDPOINTS ARE BROKEN, AND THAT CHANGES WHAT THESE TESTS ASSERT.
    // deliveryboyStore and deliveryboysUpdate both write `commission_percentage` and
    // `is_commission_enabled` -- two columns in DeliveryBoy::$fillable that NO migration creates
    // (measured against the real fully-migrated MySQL 8.0.46 schema). So both answer 500 to every
    // caller, authorised or not, and their authorization holes are LATENT rather than live.
    //
    // A test that asserted "the row did not change" would therefore pass whether or not the gate
    // exists -- the 500 satisfies it. Verified: the first version of these four tests did exactly
    // that and stayed green with the gate removed. They now assert the GATE'S OWN REFUSAL (401 or
    // 404), which only the gate can produce, so they bite.
    //
    // deliveryboysReset-password is NOT broken -- it writes only `password` -- so that hole was
    // live, and it was closed in the previous package.
    // ===================================================================================

    private function createDriver(int $storeId, ?string $bearer, string $email)
    {
        return $this->as($bearer)->postJson(
            '/api/admin/deliveryboyStore?store_id=' . $storeId,
            ['name' => 'Injected', 'email' => $email, 'password' => 'injected-password-1']
        );
    }

    public function test_a_customers_token_cannot_create_a_delivery_driver(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'b1');

        $email = 'injected-b1-' . uniqid() . '@example.test';
        $response = $this->createDriver((int) $store->id, $customer->createToken('t')->plainTextToken, $email);

        $this->assertSame(401, $response->status(),
            "A shopper's token was not refused by the driver-creation gate. Status: "
            . $response->status());

        $this->assertDatabaseMissing('delivery_boys', ['email' => $email]);
    }

    public function test_a_driver_token_cannot_create_a_driver_in_another_store(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();
        $driver = $this->driverFor($merchantB, $storeB, 'b2');

        $email = 'injected-b2-' . uniqid() . '@example.test';
        $response = $this->createDriver((int) $storeA->id, $driver->createToken('t')->plainTextToken, $email);

        $this->assertSame(401, $response->status(),
            "A driver's token was not refused by the driver-creation gate. Status: "
            . $response->status());

        $this->assertDatabaseMissing('delivery_boys', ['email' => $email]);
    }

    public function test_a_merchant_cannot_create_a_driver_in_a_store_they_do_not_own(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $email = 'injected-b3-' . uniqid() . '@example.test';
        $response = $this->createDriver((int) $storeA->id, $merchantB->createToken('t')->plainTextToken, $email);

        $this->assertSame(404, $response->status(),
            'A merchant was not refused for a store they do not own. Status: ' . $response->status());

        $this->assertDatabaseMissing('delivery_boys', ['email' => $email]);
    }

    /**
     * THE HOLE ON THIS ENDPOINT IS LATENT, NOT LIVE, and the distinction is the whole reason this
     * test asserts what it does.
     *
     * An adversarial audit reported deliveryboyStore as a live critical defect: any token holder
     * of any principal class creating a working driver credential in any store. The authorization
     * reading was right. The exploitability was not: DeliveryBoy::$fillable (app/Models/
     * DeliveryBoy.php:19-24) lists `commission_percentage` and `is_commission_enabled`, the
     * handler writes both, and NO MIGRATION CREATES EITHER COLUMN. Measured against the real,
     * fully-migrated MySQL 8.0.46 schema (`php artisan migrate` from empty, exit 0):
     *
     *     delivery_boys: id, name, email, profile_image, type, password, contact,
     *                    created_by, store_id, created_at, updated_at
     *
     * So the insert fails and the endpoint answers 500 to EVERY caller, authorised or not. It is a
     * broken endpoint with a latent authorization hole that goes live the moment those columns are
     * added — which is a schema gap of the same family as GATE F's missing tables, and is reported
     * as one.
     *
     * The gate is therefore still worth having: it closes the hole before the columns arrive. What
     * it must not do is refuse the owning merchant, so this test asserts the gate LETS THEM
     * THROUGH — it does not assert a 201, because no caller can get one on this schema.
     */
    public function test_the_owning_merchant_is_not_refused_by_the_driver_creation_gate(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->createDriver(
            (int) $store->id,
            $merchant->createToken('t')->plainTextToken,
            'legit-b4-' . uniqid() . '@example.test'
        );

        $this->assertNotContains($response->status(), [401, 404],
            'The gate refused the merchant who owns the store, so it would break the journey the '
            . 'admin app depends on once the missing columns are added. Status: ' . $response->status());
    }

    public function test_the_platform_operator_is_not_refused_by_the_driver_creation_gate(): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();

        // The operator-first ordering: creatorId() returns a super admin their own id, so an
        // ownership test alone would refuse them. An independent review flagged that divergence
        // on the first version of this check.
        $response = $this->createDriver(
            (int) $store->id,
            $operator->createToken('t')->plainTextToken,
            'operator-b5-' . uniqid() . '@example.test'
        );

        $this->assertNotContains($response->status(), [401, 404],
            'The gate refused the platform operator. Status: ' . $response->status());
    }

    /**
     * The schema gap behind the latency above, pinned so it cannot be closed silently: adding
     * these columns makes the endpoint live, and it must not go live before its gate does.
     */
    public function test_the_driver_table_still_lacks_the_columns_the_create_handler_writes(): void
    {
        $missing = array_values(array_filter(
            ['commission_percentage', 'is_commission_enabled'],
            fn ($column) => ! \Illuminate\Support\Facades\Schema::hasColumn('delivery_boys', $column)
        ));

        $this->assertSame(['commission_percentage', 'is_commission_enabled'], $missing,
            'delivery_boys has gained the columns deliveryboyStore writes, so that endpoint is now '
            . 'LIVE. Confirm its authorization gate and re-point the two tests above at a real 201 '
            . 'rather than a not-refused assertion. Observed missing: ' . implode(', ', $missing));
    }

    public function test_a_customers_token_cannot_rewrite_a_drivers_login_address(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $driver = $this->driverFor($merchant, $store, 'b6');
        $customer = $this->customerIn($store, 'b6');

        $taken = 'taken-b6-' . uniqid() . '@example.test';

        $response = $this->as($customer->createToken('t')->plainTextToken)->putJson(
            '/api/admin/deliveryboysUpdate/' . $driver->id . '?store_id=' . $store->id,
            ['email' => $taken]
        );

        $this->assertSame(401, $response->status(),
            "A shopper's token was not refused by the driver-update gate. Status: "
            . $response->status());

        $this->assertNotSame($taken, $driver->fresh()->email,
            "A shopper's token rewrote a delivery driver's login address — the address that "
            . 'controls that account\'s recovery.');
    }

    public function test_a_merchant_cannot_rewrite_a_drivers_address_in_another_store(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();
        $driver = $this->driverFor($merchantA, $storeA, 'b7');

        $taken = 'taken-b7-' . uniqid() . '@example.test';

        $response = $this->as($merchantB->createToken('t')->plainTextToken)->putJson(
            '/api/admin/deliveryboysUpdate/' . $driver->id . '?store_id=' . $storeA->id,
            ['email' => $taken]
        );

        $this->assertSame(404, $response->status(),
            'A merchant was not refused for a store they do not own. Status: ' . $response->status());

        $this->assertNotSame($taken, $driver->fresh()->email,
            'A merchant rewrote a driver\'s login address in a store they do not own.');
    }

    public function test_an_admin_forced_driver_reset_ends_the_drivers_sessions(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $driver = $this->driverFor($merchant, $store, 'b8');
        $stolen = $driver->createToken('auth_token');

        $this->as($merchant->createToken('t')->plainTextToken)->postJson(
            '/api/admin/deliveryboysReset-password/' . $driver->id . '?store_id=' . $store->id,
            ['password' => 'merchant-chosen-b8', 'password_confirmation' => 'merchant-chosen-b8']
        )->assertStatus(200);

        $this->assertTrue(Hash::check('merchant-chosen-b8', (string) $driver->fresh()->password));

        $this->assertNull(
            \Laravel\Sanctum\PersonalAccessToken::find($stolen->accessToken->id),
            'An operator forced a driver password reset — usually BECAUSE the account was '
            . 'compromised — and the driver\'s existing token survived it.'
        );
    }

    // ===================================================================================
    // D.  api/{slug}/profile-update — the fifth instance, and it writes the E-MAIL
    // ===================================================================================

    private function apiProfileUpdate($store, ?string $bearer, array $body)
    {
        return $this->as($bearer)->postJson('/api/' . $store->slug . '/profile-update', $body);
    }

    public function test_a_merchant_token_cannot_rewrite_a_customers_email(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'd1');

        $this->apiProfileUpdate($store, $merchant->createToken('t')->plainTextToken, [
            'customer_id' => $victim->id,
            'first_name' => 'Taken',
            'email' => 'attacker-owns-this-d1@example.test',
        ]);

        $this->assertNotSame('attacker-owns-this-d1@example.test', $victim->fresh()->email,
            "A merchant's token rewrote a customer's e-mail address. Owning the address means "
            . 'owning every recovery flow that trusts it, so this is a takeover primitive.');
    }

    public function test_a_driver_token_cannot_rewrite_a_customers_email(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'd2');
        $driver = $this->driverFor($merchant, $store, 'd2');

        $this->apiProfileUpdate($store, $driver->createToken('t')->plainTextToken, [
            'customer_id' => $victim->id,
            'first_name' => 'Taken',
            'email' => 'attacker-owns-this-d2@example.test',
        ]);

        $this->assertNotSame('attacker-owns-this-d2@example.test', $victim->fresh()->email,
            "A delivery driver's token rewrote a customer's e-mail address.");
    }

    public function test_one_customer_cannot_rewrite_anothers_email_through_the_api(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'd3-victim');
        $attacker = $this->customerIn($store, 'd3-attacker');

        $this->apiProfileUpdate($store, $attacker->createToken('t')->plainTextToken, [
            'customer_id' => $victim->id,
            'first_name' => 'Taken',
            'email' => 'attacker-owns-this-d3@example.test',
        ]);

        $this->assertNotSame('attacker-owns-this-d3@example.test', $victim->fresh()->email,
            "One customer's token rewrote another's e-mail.");
    }

    public function test_a_customer_cannot_take_over_anothers_email_as_their_own_through_the_api(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'd4-victim');
        $attacker = $this->customerIn($store, 'd4-attacker');

        $this->apiProfileUpdate($store, $attacker->createToken('t')->plainTextToken, [
            'first_name' => 'Shopper',
            'email' => $victim->email,
        ])->assertJsonPath('status', 0);

        $this->assertNotSame($victim->email, $attacker->fresh()->email,
            'A shopper set their own address to another shopper\'s in the same store.');
    }

    public function test_a_customer_can_still_update_their_own_profile_through_the_api(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd5');

        $new = 'own-new-d5-' . uniqid() . '@example.test';

        $this->apiProfileUpdate($store, $customer->createToken('t')->plainTextToken, [
            'first_name' => 'Renamed',
            'email' => $new,
        ])->assertStatus(200);

        $fresh = $customer->fresh();

        $this->assertSame('Renamed', $fresh->first_name,
            'A customer can no longer update their own profile; the fix broke the journey.');
        $this->assertSame($new, $fresh->email);
    }

    // ===================================================================================
    // C.  No account is created with a password published in this repository
    // ===================================================================================

    public function test_no_application_code_sets_a_fixed_literal_password(): void
    {
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'))
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // COMMENTS ARE STRIPPED FIRST. The docblocks that explain these very defects quote
            // the old literals, and a scanner that matched them would report the explanation as
            // the defect -- which is exactly what the first version of this test did.
            $source = $this->sourceWithoutComments((string) file_get_contents($file->getPathname()));

            // Hash::make / bcrypt applied to a bare single- or double-quoted literal.
            if (preg_match('/(?:Hash::make|bcrypt)\(\s*[\'"][^\'"$]{1,40}[\'"]\s*\)/', $source, $m)) {
                $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ' :: ' . $m[0];
            }
        }

        $this->assertSame([], $offenders,
            "Application code sets a password to a literal committed to this repository, so "
            . 'anyone who reads the source can sign in as any account created that way. Found: '
            . implode(' | ', $offenders));
    }

    /** Strip comments and docblocks, using PHP's own tokeniser rather than a regex. */
    private function sourceWithoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    public function test_a_guest_checkout_registration_does_not_get_a_guessable_password(): void
    {
        $source = $this->sourceWithoutComments(
            (string) file_get_contents(base_path('app/Http/Controllers/PaymentController.php'))
        );

        $this->assertStringNotContainsString("Hash::make('1234')", $source,
            'Guest-checkout registration still writes the literal 1234 as a live credential.');

        $this->assertMatchesRegularExpression(
            '/\$customerData\[.password.\]\s*=\s*Hash::make\(\\\\?Illuminate\\\\Support\\\\Str::random\(/',
            $source,
            'Guest-checkout registration no longer sets an unguessable password.'
        );
    }

    /**
     * The four WorkDo social-login packages carry the same literal and are deliberately NOT
     * modified — packages/workdo is vendored and under a modification guard. This pins that they
     * remain unreachable, which is the only thing keeping them harmless.
     */
    public function test_the_vendored_social_login_literals_remain_unrouted(): void
    {
        $packages = ['SignInWithBitbucket', 'SignInWithOutlook', 'SignInWithTwitter', 'GoogleLogin'];
        $reachable = [];

        foreach ($packages as $package) {
            $dir = base_path('packages/workdo/' . $package);

            if (! is_dir($dir)) {
                continue;
            }

            foreach (app('router')->getRoutes() as $route) {
                $action = $route->getActionName();

                if (str_contains($action, $package)) {
                    $reachable[] = $package . ' => ' . $route->uri();
                }
            }
        }

        $this->assertSame([], $reachable,
            'A vendored social-login package that sets the literal password 1234 now has a '
            . 'registered route, so the latent defect is live. It cannot be fixed in place under '
            . 'the WorkDo modification guard — the route must be refused instead. Found: '
            . implode(', ', $reachable));
    }
}
