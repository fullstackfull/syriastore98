<?php

namespace Tests\Feature\Security;

use App\Support\ModuleRouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-31 acceptance criterion 1 -- the vendored generic settings writers are ENUMERATED, and a module that adds one fails the build.
 *
 * Two inputs, both measured here and not remembered:
 *   - a SOURCE SCAN of every vendored module controller for a public method that loops over posted (or built) names and writes them through a
 *     Setting model (`foreach ($post|$request->all()|$data ... as $key ...)` together with `updateOrCreate`/`Setting::`), and
 *   - the resolved ROUTE TABLE with every module loaded (tests/Support/Security/bin/route-census.php, its own process),
 * joined on `Class@method`. Every route that reaches such a method must be a key of ModuleRouteManifest::ADMINISTRATIVE with a settings rule
 * (`settings:store` / `settings:global`) or `operator`; the few that are deliberately something else are listed below WITH the reason, so an
 * exemption is a decision someone made, not a gap.
 */
class ModuleSettingsWriterCensusTest extends TestCase
{
    private const WRITER_FLOOR = 25;

    /**
     * Source-scan hits that are not routed through a settings rule, and why.
     *
     * @var array<string,string> `Module::method` => reason
     */
    private const EXEMPT = [
        'AdditionalCustomFields::destroy' => 'false positive: deletes a custom field; no name comes from the request',
        'AutomaticOrderPrinting::printSettings' => 'checks `Manage Print Setting` itself and writes only print_* names it enumerates',
        // The Marketing handlers below write the HOST `settings` table (an earlier version of this note said "the module's own tables"; that was
        // wrong, corrected by the P1-SEC-04 review) and each checks a module permission of its own. `domainsetting` is NOT exempt: it writes
        // domains / enable_domain / enable_subdomain / subdomain, which the host reserves to Manage Setting, so its mutating methods are
        // `settings:global` in the manifest. The others write only Marketing-specific names under their own permission.
        'Marketing::updatedata' => 'checks `Update Store Data` itself; writes Marketing-specific names',
        'Marketing::blocksetting' => 'checks `Update Block Setting` itself; writes Marketing-specific names',
        'Marketing::saveseo' => 'checks `Update SEO Setting` itself; writes Marketing-specific names',
        'Marketing::savePWA' => 'checks `Update PWA Setting` itself; writes Marketing-specific names',
        'Marketing::saveCookiesetting' => 'checks `Update Cookie Setting` itself; writes Marketing-specific names',
    ];

    /**
     * THE DECISION, PINNED: which screen each settings writer belongs to. `settings:store` = the Store Setting screen (the handler puts
     * `app_setting_tab` in the session, or it is a storefront feature toggle); `settings:global` = an integration CREDENTIAL writer that lives on
     * the Settings screen (`setting_tab`, or a token/secret owner). A change here changes who may save the tab, so it is reviewed as such.
     *
     * @var array<string,string>
     */
    private const EXPECTED_RULES = [
        'POST boost-sales-setting' => 'settings:store', 'POST count-down-setting' => 'settings:store', 'POST store-donation' => 'settings:store',
        'POST free-shipping-popup-setting' => 'settings:store', 'POST partial-payment-settings' => 'settings:store',
        'POST purchase-notification-setting' => 'settings:store', 'POST store-quick-checkout' => 'settings:store',
        'POST recent-orders-settings' => 'settings:store', 'POST club-point-setting' => 'settings:store', 'POST skip-cart-settings' => 'settings:store',
        'POST barcode-settings' => 'settings:store', 'POST recently-view-setting' => 'settings:store', 'POST frequently-product-setting' => 'settings:store',
        'POST productpricing/rulesCondition' => 'settings:store',
        'POST sms-settings' => 'settings:global', 'POST googlelogin-setting' => 'settings:global', 'POST facebooklogin-setting' => 'settings:global',
        'POST linkedin-setting' => 'settings:global', 'POST outlooklogin-setting' => 'settings:global', 'POST github-settings' => 'settings:global',
        'POST bitbucket-settings' => 'settings:global', 'POST slack-settings' => 'settings:global', 'POST twitter-settings' => 'settings:global',
        'POST tawk-to-setting' => 'settings:global', 'POST wizzchat-setting' => 'settings:global', 'POST hubspot/setting/store' => 'settings:global',
        'POST couponemail-settings' => 'settings:global',
        'POST campaigns/campaigns-system-setup' => 'operator',
        'POST marketing/store/domain-store/{id}' => 'settings:global', 'PUT marketing/store/domain-store/{id}' => 'settings:global',
        'PATCH marketing/store/domain-store/{id}' => 'settings:global', 'DELETE marketing/store/domain-store/{id}' => 'settings:global',
    ];

    private static ?array $census = null;

    private function census(): array
    {
        if (self::$census === null) {
            $out = [];
            $code = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/tests/Support/Security/bin/route-census.php') . ' 2>&1', $out, $code);
            $json = json_decode(implode("\n", $out), true);

            $this->assertSame(0, $code);
            $this->assertIsArray($json, substr(implode("\n", $out), 0, 300));
            self::$census = $json;
        }

        return self::$census;
    }

    /** @return array<string,string> `Class@method` => `Module::method` */
    private function writers(): array
    {
        $found = [];

        foreach (glob(dirname(__DIR__, 3) . '/packages/workdo/*/src/app/Http/Controllers/*.php') as $file) {
            $source = (string) file_get_contents($file);
            $namespace = preg_match('/namespace\s+([^;]+);/', $source, $m) ? $m[1] : '';
            $class = preg_match('/class\s+(\w+)/', $source, $m) ? $m[1] : '';
            $module = explode('/', str_replace(dirname(__DIR__, 3) . '/packages/workdo/', '', $file))[0];

            foreach (preg_split('/(?=\n\s*public function \w+\()/', $source) as $chunk) {
                if (! preg_match('/^\n\s*public function (\w+)\(/', $chunk, $m)) {
                    continue;
                }

                $loops = preg_match('/foreach\s*\(\s*\$(post|request->all\(\)|data|settings|input|validated)\b[^)]*as\s*\$key/', $chunk) === 1;
                $writes = preg_match('/updateOrCreate|Setting::|Settings::|SetSetting|setSetting/', $chunk) === 1;

                if ($loops && $writes) {
                    $found[$namespace . '\\' . $class . '@' . $m[1]] = $module . '::' . $m[1];
                }
            }
        }

        return $found;
    }

    public function test_the_scan_finds_the_writers_it_is_meant_to_find(): void
    {
        $writers = $this->writers();

        $this->assertGreaterThanOrEqual(self::WRITER_FLOOR, count($writers), 'The source scan found suspiciously few settings writers.');
        $this->assertContains('BoostSales::boostSalesSettings', $writers);
        $this->assertContains('GoogleLogin::googleLoginSetting', $writers);
    }

    public function test_every_route_reaching_a_settings_writer_is_routed_through_a_settings_rule(): void
    {
        $writers = $this->writers();
        $unrouted = [];
        $reached = 0;

        foreach ($this->census()['routes'] as $row) {
            $key = $row['action'];

            if (! isset($writers[$key]) || isset(self::EXEMPT[$writers[$key]]) || in_array($row['method'], ['GET', 'OPTIONS', 'HEAD'], true)) {
                continue;
            }

            $reached++;
            $rule = ModuleRouteManifest::ADMINISTRATIVE[$row['method'] . ' ' . $row['uri']] ?? null;

            if (! in_array($rule, ['settings:store', 'settings:global', 'operator'], true)) {
                $unrouted[] = "{$writers[$key]}  {$row['method']} {$row['uri']}  (rule: " . ($rule ?? 'none') . ')';
            }
        }

        $this->assertGreaterThanOrEqual(self::WRITER_FLOOR - 5, $reached, 'The join found suspiciously few routes for the writers.');
        $this->assertSame([], $unrouted, "A module settings writer is reachable without a settings rule (add the route to ModuleRouteManifest::ADMINISTRATIVE as 'settings:store' or 'settings:global', or exempt the method here with a reason):\n" . implode("\n", $unrouted));
    }

    public function test_an_exemption_still_names_a_real_writer(): void
    {
        $writers = array_flip($this->writers());
        $stale = array_values(array_filter(array_keys(self::EXEMPT), fn ($k) => ! isset($writers[$k])));

        $this->assertSame([], $stale, 'Exempted methods the scan no longer finds as writers (remove the exemption).');
    }

    public function test_each_writer_route_keeps_the_rule_that_was_decided_for_it(): void
    {
        $actual = [];

        foreach (self::EXPECTED_RULES as $key => $rule) {
            $actual[$key] = ModuleRouteManifest::ADMINISTRATIVE[$key] ?? 'MISSING';
        }

        $this->assertSame(self::EXPECTED_RULES, $actual, 'A settings writer changed its rule (or left the manifest). That decides who may save the tab: review it.');
    }
}
