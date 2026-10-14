<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 3's security properties, pinned as PROPERTIES rather than as behaviours.
 *
 * Every assertion here is of the form "this cannot be true of the source", so it keeps holding
 * as the code moves. The behaviour tests next door prove the paths work; these prove the ways
 * they could stop being safe are closed, including for code nobody has written yet.
 */
class LynomiaPhase3SecurityTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const MODULE = 'packages/workdo/LynomiaChat/src';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    /**
     * @return array<string,string> relative path => EXECUTABLE text, for every PHP file in the
     *   module. Comments are stripped, because the files that must not CALL something are
     *   usually the ones whose docblocks explain at length why not -- the same reason
     *   LegacyChatwootRemovedTest strips them before looking for retired classes.
     */
    private function moduleSources(): array
    {
        $files = [];
        $base = base_path(self::MODULE);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[str_replace($base . '/', '', $file->getPathname())] = $this->executableText($file->getPathname());
            }
        }

        return $files;
    }

    /** Only what runs: // # and block comments removed. */
    private function executableText(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    // ---------------------------------------------------------------- tenancy

    /**
     * THE ONE RULE THE COMMERCE SURFACE EXISTS TO OBEY. getCurrentStore() reads
     * users.current_store -- a persisted, mutable column written by the store switcher and
     * shared across a person's sessions and devices. Anything on this surface that called it
     * would silently change tenant when a merchant clicked a different store in another tab.
     */
    public function test_nothing_on_the_commerce_surface_resolves_a_tenant_from_the_session(): void
    {
        $commerce = ['app/Http/Controllers/CommerceApiController.php',
            'app/Http/Middleware/AuthenticateCommerceApi.php',
            'app/Services/CommerceReader.php',
            'app/Services/CommerceCredential.php',
            'app/Services/CommerceEventDispatcher.php',
            'app/Observers/CommerceEventObserver.php'];

        foreach ($commerce as $path) {
            $source = $this->executableText(base_path(self::MODULE . '/' . $path));

            foreach (['getCurrentStore(', 'auth()->', 'Auth::', 'creatorId(', 'session('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source,
                    "{$path} resolves something from the session; the credential names the store and nothing else may");
            }
        }
    }

    /**
     * order_billing_details HAS NO store_id COLUMN, so a read of it that is not joined through
     * `orders` is an unscoped read of a customer's telephone number. One such site existed in
     * this codebase and was deleted; this is what stops a second appearing.
     */
    public function test_no_module_code_reads_billing_details_without_joining_orders(): void
    {
        foreach ($this->moduleSources() as $path => $source) {
            if (! str_contains($source, 'order_billing_details')) {
                continue;
            }

            $this->assertStringContainsString('orders.id', $source,
                "{$path} touches order_billing_details without joining orders, which carries the only store_id");
        }
    }

    // ---------------------------------------------------------------- credentials

    public function test_no_credential_is_ever_written_to_the_database_or_the_audit_trail(): void
    {
        foreach ($this->moduleSources() as $path => $source) {
            if (str_contains($path, 'Services/CommerceCredential.php')) {
                continue;
            }

            foreach (["CommerceCredential::issue(\$link)'", 'commerce_credential' . "' => CommerceCredential"] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$path} persists a commerce credential");
            }
        }

        // The migration carries a VERSION, which is the revocation counter, and never a token.
        $migration = $this->executableText(
            base_path(self::MODULE . '/database/migrations/2026_10_18_000100_add_commerce_credential_version_to_lynomia_store_links.php')
        );
        $this->assertStringNotContainsString('token', $migration);
        $this->assertStringContainsString('commerce_credential_version', $migration);
    }

    /**
     * The outbox carries which store, which order, which customer and what happened. Not a
     * name, not a total, not a phone number: Lynomia re-reads the order from here rather than
     * trusting a delivery, so order data in the table would be customer data outliving the
     * thing that produced it, in a queue nobody reads.
     */
    public function test_the_commerce_outbox_table_holds_no_customer_data(): void
    {
        $migration = $this->executableText(
            base_path(self::MODULE . '/database/migrations/2026_10_19_000100_create_lynomia_commerce_events_table.php')
        );

        foreach (['name', 'email', 'phone', 'telephone', 'total', 'price', 'address', 'payload', 'body'] as $forbidden) {
            $this->assertStringNotContainsString("'{$forbidden}'", $migration,
                "the commerce outbox declares a `{$forbidden}` column; it carries references, never content");
        }
    }

    // ---------------------------------------------------------------- routing

    public function test_every_commerce_route_is_behind_the_credential_middleware_and_its_own_limiter(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/lynomia/commerce/'));

        $this->assertGreaterThanOrEqual(6, $routes->count(), 'the commerce API lost routes');

        foreach ($routes as $route) {
            $stack = array_map(
                static fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : (string) $m),
                app('router')->gatherRouteMiddleware($route)
            );

            $this->assertContains(\Workdo\LynomiaChat\app\Http\Middleware\AuthenticateCommerceApi::class, $stack,
                "{$route->uri()} is reachable without the commerce credential");
            $this->assertContains('Illuminate\\Routing\\Middleware\\ThrottleRequests:lynomia-commerce', $stack,
                "{$route->uri()} is not in the commerce limiter");
            // No session, no CSRF, no cookies: this is server to server, and a cookie on it
            // would make a merchant's browser able to call it.
            $this->assertNotContains('Illuminate\\Session\\Middleware\\StartSession', $stack,
                "{$route->uri()} starts a session");
        }
    }

    /**
     * NO STORE ID IN ANY COMMERCE PATH. The credential names the store, which is also why
     * there is nothing here for a caller to tamper with -- the property is only true while no
     * route grows a store segment.
     */
    public function test_no_commerce_route_takes_a_store_identifier(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/lynomia/commerce/')) {
                continue;
            }

            foreach ($route->parameterNames() as $parameter) {
                $this->assertStringNotContainsStringIgnoringCase('store', $parameter,
                    "{$route->uri()} accepts a store identifier from the caller");
            }
        }
    }

    // ---------------------------------------------------------------- the kill switches

    public function test_the_commerce_feed_and_the_integration_both_ship_off(): void
    {
        $config = require base_path(self::MODULE . '/config/lynomia.php');

        $this->assertFalse($config['enabled'], 'the integration must ship disabled');
        $this->assertFalse($config['commerce_events_enabled'], 'the commerce event feed must ship disabled');
    }

    public function test_the_master_secret_has_no_default_so_a_credential_cannot_be_predictable(): void
    {
        $config = require base_path(self::MODULE . '/config/lynomia.php');

        $this->assertNull($config['webhook_master_secret'] ?: null,
            'a default master secret would make every credential computable by anyone holding this source');
    }
}
