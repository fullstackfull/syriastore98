<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * P1-SEC-25 (REFUTED, PINNED) -- the LandingPage module's pricing view prints a merchant's whole settings array, and no
 * request can reach it.
 * =============================================================================================
 *
 * The P1-SEC-18 F4 review READ `packages/workdo/LandingPage/src/Resources/views/layouts/pricing.blade.php:13`:
 * `$super_admin_settings = json_encode(getAdminAllSetting());`, echoed (HTML-escaped) into twelve `formatCurrency(...)`
 * calls. For a caller with no user and no store in the route, `getAdminAllSetting()` resolves the lowest-id `admin`-type
 * user -- a MERCHANT in this product; the operator is `super admin` -- and returns every setting of every store they own.
 * Read as "an anonymous GET /pricing prints a merchant's gateway secrets and SMTP password". It was reported as probable,
 * not executed.
 *
 * EXECUTED here, it is not reachable. `HomeController::Pricing()` renders that view only inside
 * `if (module_is_active('LandingPage'))`, and it does so only AFTER `if (auth()->check()) { redirect }`. So the branch is
 * entered only by a caller who is not logged in; but `module_is_active()` with no route slug answers from the authenticated
 * user (helper.php: `Auth::check()` / `$user_id`) and returns FALSE when there is none. The two conditions exclude each
 * other: the module view is dead code behind /pricing, whether or not the module is enabled. The host's own
 * `marketplace.pricing` is what an anonymous visitor gets, and it echoes only the public reCAPTCHA site key.
 *
 * WHAT THIS PINS. Not a fix -- there is nothing to fix in the host -- but the premise, so that the day someone changes
 * `module_is_active()` (e.g. to give anonymous callers the landing module) the disclosure is not silently switched on.
 * The failure message says what to do: override the view under resources/views/vendor/landing-page (packages/workdo/ cannot
 * be edited) before enabling the branch. A host override was written and DELETED: it would be an 800-line copy of a module
 * file guarding a view nothing renders.
 */
class PricingSettingsDisclosureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SECRETS = [
        'MAIL_PASSWORD' => 'FIXTURE-SMTP-PASSWORD-9a1',
        'stripe_secret' => 'FIXTURE-STRIPE-SECRET-9a2',
        'chat_gpt_key' => 'FIXTURE-OPENAI-KEY-9a3',
    ];

    private bool $moduleViewRendered = false;

    protected function setUp(): void
    {
        parent::setUp();

        // The module is ENABLED -- the strongest case for the disclosure.
        AddOnManager::create([
            'module' => 'LandingPage', 'name' => 'LandingPage',
            'monthly_price' => 0, 'yearly_price' => 0, 'is_enable' => 1, 'package_name' => 'workdo/landing-page',
        ]);
        Cache::flush();

        View::composer('landing-page::layouts.pricing', function () {
            $this->moduleViewRendered = true;
        });
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function merchantWithSecrets(): array
    {
        [$merchant, $store] = $this->makeTenant();
        foreach (self::SECRETS as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$merchant, $store];
    }

    public function test_premise_the_row_is_enabled_and_the_secrets_are_what_an_anonymous_settings_read_returns(): void
    {
        $this->merchantWithSecrets();

        $this->assertTrue(AddOnManager::where('module', 'LandingPage')->where('is_enable', 1)->exists());
        $this->assertTrue(view()->exists('landing-page::layouts.pricing'), 'Premise: the module view must be resolvable.');

        // The hazard is real: a caller with no user and no route store gets the merchant's whole array...
        $this->assertSame('FIXTURE-SMTP-PASSWORD-9a1', getAdminAllSetting()['MAIL_PASSWORD'] ?? null);
    }

    public function test_an_anonymous_visitor_to_pricing_is_never_served_the_module_view(): void
    {
        $this->merchantWithSecrets();

        $this->get('/pricing');

        $this->assertFalse(module_is_active('LandingPage'),
            'module_is_active() now answers true for a caller with no user. /pricing would render the LandingPage pricing view, '
            . 'whose line 13 prints json_encode(getAdminAllSetting()) -- a merchant\'s gateway secrets and SMTP password -- '
            . 'to the anonymous visitor. Override resources/views/vendor/landing-page/layouts/pricing.blade.php first.');
        $this->assertFalse($this->moduleViewRendered,
            '/pricing rendered landing-page::layouts.pricing for an anonymous visitor (see the class docblock).');
    }

    public function test_a_logged_in_caller_is_redirected_before_the_module_branch(): void
    {
        [$merchant] = $this->merchantWithSecrets();

        $response = $this->actingAs($merchant)->get('/pricing');

        $response->assertRedirect();
        $this->assertFalse($this->moduleViewRendered);
    }
}
