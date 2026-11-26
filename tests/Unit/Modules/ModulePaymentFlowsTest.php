<?php

namespace Tests\Unit\Modules;

use App\Support\ModuleRouteManifest;
use App\Support\Modules\ModulePaymentFlows;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * `ModulePaymentFlows` IS A CLAIM ABOUT VENDORED CODE, SO IT IS MEASURED AGAINST THE VENDORED CODE.
 *
 * The table names thirteen `Controller@method` actions in three modules and says what each does to
 * a payment. Nothing loads a WorkDo module in a test run (CLAUDE.md), so the router cannot be asked.
 * The three route FILES can: this reads them, expands each declaration into the `METHOD uri` keys
 * the router would report, and holds three things together —
 *
 *   1. every one of `ModuleRouteManifest`'s 87 `payment-callback` keys resolves to an action the
 *      table classifies. A module update that adds a payment return the table does not know about
 *      reddens this instead of slipping past the disabled-application boundary;
 *   2. every action in the table is really declared, in the file it names, on at least one route;
 *   3. the count of manifest keys the table reaches, per manifest category, is exactly what the
 *      boundary document says it is — including the 24 `storefront` keys and the 6 `guarded` ones,
 *      because "the payment rule touches part of a category the owner has not decided" is a
 *      statement that has to stay true to the key.
 *
 * WHAT A FILE PARSE CANNOT SEE, stated rather than assumed away: route-level middleware, and a
 * route declared somewhere other than these three files. `tests/Support/Security/bin/route-census.php`
 * loads all 70 modules through the production loader in a separate process and is the measurement
 * for both; `RouteCensusGateTest` re-runs it.
 */
class ModulePaymentFlowsTest extends TestCase
{
    use StripsSourceComments;

    /** module => [route file, URI prefix its routes are declared under] */
    private const ROUTE_FILES = [
        'Donation' => ['packages/workdo/Donation/src/routes/web.php', ''],
        'LotteryPrizes' => ['packages/workdo/LotteryPrizes/src/routes/web.php', ''],
        'Campaigns' => ['packages/workdo/Campaigns/src/routes/web.php', 'campaigns'],
    ];

    private const ANY = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * Every `METHOD uri` => `Controller@method` the three route files declare.
     *
     * @return array<string,string>
     */
    private function declaredRoutes(): array
    {
        $routes = [];

        foreach (self::ROUTE_FILES as [$file, $prefix]) {
            $source = $this->sourceWithoutComments(base_path($file));

            preg_match_all('/^use\s+([\w\\\\]+)\\\\(\w+);/m', $source, $uses, PREG_SET_ORDER);
            $classes = [];
            foreach ($uses as $use) {
                $classes[$use[2]] = $use[1] . '\\' . $use[2];
            }

            preg_match_all(
                '/Route::(get|post|put|patch|delete|any)\(\s*\'([^\']*)\'\s*,\s*\[\s*(\w+)::class\s*,\s*\'(\w+)\'\s*\]/',
                $source,
                $declarations,
                PREG_SET_ORDER
            );

            foreach ($declarations as [, $verb, $uri, $class, $method]) {
                $this->assertArrayHasKey($class, $classes, "{$file} routes to {$class}, which it does not import");

                $uri = trim(($prefix !== '' ? $prefix . '/' : '') . trim($uri, '/'), '/');

                foreach ($verb === 'any' ? self::ANY : [strtoupper($verb)] as $httpVerb) {
                    $routes[$httpVerb . ' ' . $uri] = $classes[$class] . '@' . $method;
                }
            }

            /* `Route::resource('campaigns', CampaignsController::class)` — the one resource whose
               `store` starts a payment. Only the key that matters is expanded. */
            if (preg_match('/Route::resource\(\s*\'campaigns\'\s*,\s*CampaignsController::class\s*\)/', $source) === 1) {
                $routes['POST campaigns/campaigns'] = $classes['CampaignsController'] . '@store';
            }
        }

        return $routes;
    }

    public function test_premise_the_three_route_files_were_read(): void
    {
        $routes = $this->declaredRoutes();

        /* If the parse found nothing, every assertion below would pass over an empty set. */
        $this->assertGreaterThan(100, count($routes), 'the route files were not parsed');
        $this->assertSame(
            'Workdo\Donation\app\Http\Controllers\CustomerDonationController@StoreDonationPayment',
            $routes['GET {storeSlug}/store-donation-payment'] ?? null
        );
        $this->assertSame(
            'Workdo\Campaigns\app\Http\Controllers\CampaignsController@paymentSuccess',
            $routes['GET campaigns/campaigns-card-payment'] ?? null,
            'the Campaigns prefix or the trailing slash on campaigns-card-payment/ was not handled'
        );
    }

    /** THE POINT: no `payment-callback` key is outside the table. */
    public function test_every_payment_callback_key_in_the_manifest_reaches_a_classified_action(): void
    {
        $routes = $this->declaredRoutes();
        $perModule = [];
        $unclassified = [];

        foreach (ModuleRouteManifest::ANONYMOUS_BY_DESIGN as $key => $category) {
            if ($category !== 'payment-callback') {
                continue;
            }

            $action = $routes[$key] ?? null;

            if ($action === null || ModulePaymentFlows::roleOfAction($action) === null) {
                $unclassified[] = $key . ' -> ' . ($action ?? 'declared in none of the three route files');

                continue;
            }

            $module = explode('\\', $action)[1];
            $perModule[$module] = ($perModule[$module] ?? 0) + 1;
        }

        $this->assertSame([], $unclassified,
            'A payment-callback key reaches a handler `ModulePaymentFlows` does not classify, so a '
            . 'switched-off application would still answer on it. Classify the action there.');

        ksort($perModule);
        $this->assertSame(['Campaigns' => 17, 'Donation' => 37, 'LotteryPrizes' => 33], $perModule);
        $this->assertSame(87, array_sum($perModule));
    }

    /**
     * THE ENTITLEMENT-GATE FLAG IS A CLAIM ABOUT THE VENDORED ROUTE FILES, SO IT IS MEASURED
     * AGAINST THEM — in both directions.
     *
     * `ModulePaymentFlows::completionIsEntitlementGated()` decides whether a disabled module can
     * complete a payment at all. It says Campaigns cannot, because that module declares its writer
     * inside `Route::group(['middleware' => 'PlanModuleCheck:Campaigns'])` and ROUTE middleware runs
     * after the GROUP's — so this boundary decides first and the module's own gate refuses
     * afterwards. Found by independent review, after the first version of the boundary marked such a
     * return `completed` for a request that was then refused.
     *
     * If a module update moves that writer out of the group, or moves another module's writer into
     * one, the flag stops matching the code and the boundary's promise changes in silence. So the
     * flag is DERIVED here from the files and compared with the constant.
     */
    public function test_the_entitlement_gate_flag_matches_the_vendored_route_files(): void
    {
        $gated = [];

        foreach (self::ROUTE_FILES as $module => [$file]) {
            $source = $this->sourceWithoutComments(base_path($file));

            $writers = [];

            foreach (ModulePaymentFlows::roles() as $action => $role) {
                if ($role === ModulePaymentFlows::COMPLETE && str_contains($action, '\\' . $module . '\\')) {
                    $writers[] = explode('@', $action)[1];
                }
            }

            $this->assertNotEmpty($writers, "{$module} has no COMPLETE handler, so this measures nothing for it");

            /* Each `PlanModuleCheck:<Module>` group's body, by brace matching from the group call. */
            $regions = [];
            $offset = 0;
            $needle = "'PlanModuleCheck:" . $module . "'";

            while (($at = strpos($source, $needle, $offset)) !== false) {
                $open = strpos($source, '{', $at);
                $depth = 0;

                for ($i = $open; $i < strlen($source); $i++) {
                    $depth += $source[$i] === '{' ? 1 : ($source[$i] === '}' ? -1 : 0);

                    if ($depth === 0) {
                        $regions[] = substr($source, $open, $i - $open);
                        break;
                    }
                }

                $offset = $at + strlen($needle);
            }

            foreach ($writers as $writer) {
                foreach ($regions as $region) {
                    if (str_contains($region, "'" . $writer . "'")) {
                        $gated[$module] = true;
                        break 2;
                    }
                }
            }
        }

        foreach (array_keys(self::ROUTE_FILES) as $module) {
            $this->assertSame(
                isset($gated[$module]),
                ModulePaymentFlows::completionIsEntitlementGated($module),
                isset($gated[$module])
                    ? "{$module} declares its payment writer inside its own PlanModuleCheck group, so a "
                      . 'disabled application cannot complete one of its payments. '
                      . 'completionIsEntitlementGated() must say so, or the boundary marks an initiation '
                      . '`completed` for a request that is refused after it.'
                    : "{$module} does NOT declare its payment writer behind its own entitlement gate, so "
                      . 'marking it entitlement-gated refuses returns that would have been honoured.'
            );
        }

        /* The premise. A derivation that found none, or all three, would make the comparison above
           pass for the wrong reason. */
        $this->assertSame(['Campaigns'], array_keys($gated));
    }

    /**
     * EVERY MODULE THAT TAKES PAYMENTS DECLARES THE FLOW KEY ITS OWN SERVICE WRITES.
     *
     * The table-absent path requires that key instead of this host's record, so the claim that the
     * module really writes it is what makes that path admit an in-flight payer rather than nobody.
     */
    public function test_every_payment_module_declares_the_flow_key_its_own_service_writes(): void
    {
        foreach (ModulePaymentFlows::modules() as $module) {
            $key = ModulePaymentFlows::vendoredFlowKey($module);

            $this->assertIsString($key, "{$module} declares no vendored flow key");

            $service = base_path('packages/workdo/' . $module . '/src/app/Services/'
                . ($module === 'LotteryPrizes' ? 'LotteryPaymentService' : $module . 'PaymentService') . '.php');

            $this->assertFileExists($service);

            $this->assertStringContainsString(
                "Session::put('" . $key . "'",
                $this->sourceWithoutComments($service),
                "{$module}'s payment service does not write `{$key}` to the session, so requiring it on "
                . 'the table-absent path would refuse every in-flight payer.'
            );
        }
    }

    public function test_every_classified_action_is_declared_on_a_route(): void
    {
        $declared = array_unique(array_values($this->declaredRoutes()));

        foreach (array_keys(ModulePaymentFlows::roles()) as $action) {
            $this->assertContains($action, $declared,
                "`ModulePaymentFlows` classifies {$action}, which no route in the three module route files points at. "
                . 'A stale entry would hide a future route that reuses the name.');

            [$class, $method] = explode('@', $action);
            $file = base_path('packages/workdo/' . explode('\\', $class)[1] . '/src/app/Http/Controllers/' . class_basename($class) . '.php');

            $this->assertFileExists($file);
            $this->assertMatchesRegularExpression(
                '/function\s+' . preg_quote($method, '/') . '\s*\(/',
                (string) file_get_contents($file),
                "{$class} has no method {$method}"
            );
        }
    }

    /**
     * WHICH MANIFEST KEYS THE PAYMENT RULE REACHES, BY CATEGORY AND BY ROLE.
     *
     * The numbers the boundary document quotes. `storefront` is the one to read: the category as a
     * whole is still the owner's decision, and 24 of its 100 keys are a payment — two that start
     * one and twenty-two that reach a completion writer.
     */
    public function test_the_manifest_keys_the_payment_rule_reaches_are_exactly_these(): void
    {
        $reached = [];

        foreach ($this->declaredRoutes() as $key => $action) {
            $role = ModulePaymentFlows::roleOfAction($action);

            if ($role === null) {
                continue;
            }

            if (isset(ModuleRouteManifest::ANONYMOUS_BY_DESIGN[$key])) {
                $category = explode(':', ModuleRouteManifest::ANONYMOUS_BY_DESIGN[$key])[0];
            } elseif (isset(ModuleRouteManifest::ADMINISTRATIVE[$key])) {
                $category = 'ADMINISTRATIVE';
            } else {
                /* Carries its own route-level authentication, so the manifest does not list it. */
                $category = 'route-level authentication';
            }

            $reached[$category][$role] = ($reached[$category][$role] ?? 0) + 1;
        }

        ksort($reached);
        array_walk($reached, fn (&$roles) => ksort($roles));

        $this->assertSame([
            'ADMINISTRATIVE' => [
                ModulePaymentFlows::START => 1,
                ModulePaymentFlows::STEP => 25,
            ],
            'guarded' => [
                ModulePaymentFlows::COMPLETE => 6,
            ],
            'payment-callback' => [
                ModulePaymentFlows::COMPLETE => 70,
                ModulePaymentFlows::RELAY => 12,
                ModulePaymentFlows::STEP => 5,
            ],
            'storefront' => [
                ModulePaymentFlows::COMPLETE => 22,
                ModulePaymentFlows::START => 2,
            ],
        ], $reached);

        $this->assertSame(100, count(array_filter(
            ModuleRouteManifest::ANONYMOUS_BY_DESIGN,
            fn ($category) => $category === 'storefront'
        )), 'premise: the storefront category is the 100 keys the boundary document says it is');
    }
}
