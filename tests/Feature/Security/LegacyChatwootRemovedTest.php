<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The legacy Store Syria <-> Chatwoot integration is retired. This pins the retirement.
 *
 * WHY A TEST AND NOT JUST A DELETION. The removed surface included two routes that had been
 * reachable with no session at all and that drove outbound provider traffic at caller-supplied
 * customer ids. If any of it returns -- restored from a stale branch, reintroduced by a merge,
 * or recreated under the old names by someone building the replacement -- it returns with those
 * properties. The assertions below are deliberately about ABSENCE, which is the only property
 * that cannot regress quietly.
 *
 * WHAT IS NOT ASSERTED HERE, on purpose:
 *
 *   - The `chatwoot_integrations` and `chatwoot_notification_templates` TABLES. They are
 *     retained in production as the store -> account_id/inbox_id mapping the replacement
 *     integration migrates from (database/migrations/_gate_f_pending/README.md). Asserting
 *     either presence or absence would encode a data-retention decision in a code test.
 *   - The App\Events\OrderPaymentStatus event, which is deliberately KEPT with no listeners.
 *     It is dispatched from six sites in App\Models\Order plus the admin and courier APIs and
 *     is the commerce-event surface the approved replacement architecture consumes. Its
 *     listener list being EMPTY is asserted, because a stray re-registration of the old
 *     listener is exactly the regression worth catching.
 */
class LegacyChatwootRemovedTest extends TestCase
{
    /** Every route name the legacy integration registered. */
    private const RETIRED_ROUTE_NAMES = [
        'chatwoot.abandoned-carts',
        'chatwoot.abandoned-carts.send',
        'chatwoot.connect',
        'chatwoot.inbox.save',
        'chatwoot.inboxes',
        'chatwoot.templates',
        'chatwoot.notification.templates.save',
        'chatwoot.notification.templates',
        'chatwoot.test.order.notification',
    ];

    /** Every file the legacy integration owned outright. */
    private const RETIRED_FILES = [
        'app/Http/Controllers/newsyria/ChatwootIntegrationController.php',
        'app/Http/Controllers/newsyria/AbandonedCartController.php',
        'app/Listeners/SendChatwootOrderStatusNotification.php',
        'app/Models/ChatwootIntegration.php',
        'app/Models/ChatwootNotificationTemplate.php',
        'app/Services/ChatwootService.php',
        'app/Services/ChatwootOrderNotificationService.php',
        'app/Services/ChatwootOrderVariableService.php',
        'app/Services/ChatwootAbandonedCartNotificationService.php',
        'app/Services/ChatwootAbandonedCartService.php',
        'resources/views/setting/chatwoot.blade.php',
        'resources/views/setting/partials/abandoned-carts.blade.php',
        'resources/lang/en/chatwoot.php',
        'resources/lang/ar/chatwoot.php',
    ];

    public function test_no_legacy_chatwoot_route_is_registered(): void
    {
        foreach (self::RETIRED_ROUTE_NAMES as $name) {
            $this->assertFalse(
                Route::has($name),
                "Route `{$name}` is registered again. The legacy Chatwoot integration is "
                . 'retired; the replacement belongs to the Store Syria x Lynomia project and '
                . 'must not reuse these route names.'
            );
        }
    }

    public function test_no_route_path_contains_chatwoot(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (stripos($route->uri(), 'chatwoot') !== false) {
                $offenders[] = $route->methods()[0] . ' /' . $route->uri();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Route URIs still mention chatwoot: \n" . implode("\n", $offenders)
        );
    }

    public function test_every_legacy_file_is_deleted(): void
    {
        foreach (self::RETIRED_FILES as $path) {
            $this->assertFileDoesNotExist(
                base_path($path),
                "`{$path}` is back. It belongs to the retired legacy integration."
            );
        }

        $this->assertDirectoryDoesNotExist(
            base_path('app/Http/Controllers/newsyria'),
            'app/Http/Controllers/newsyria held only the two legacy Chatwoot controllers.'
        );
    }

    /**
     * The event survives; its listener does not.
     *
     * Event::getListeners() is read through the real dispatcher, so this catches a
     * re-registration anywhere -- EventServiceProvider, a service provider boot(), or a
     * package -- not just the mapping array.
     */
    public function test_order_payment_status_has_no_listeners(): void
    {
        $this->assertTrue(
            class_exists(\App\Events\OrderPaymentStatus::class),
            'App\Events\OrderPaymentStatus was removed. It is deliberately retained as the '
            . 'commerce-event surface the replacement integration consumes.'
        );

        $this->assertSame(
            [],
            Event::getListeners(\App\Events\OrderPaymentStatus::class),
            'OrderPaymentStatus has a listener again. Its only listener was the retired '
            . 'SendChatwootOrderStatusNotification, which dispatched WhatsApp notifications '
            . 'SYNCHRONOUSLY inside the request that changed an order status.'
        );
    }

    /**
     * The hardcoded integration endpoint must not survive anywhere in application source.
     *
     * Scoped to the directories the legacy code lived in. docs/ is excluded on purpose: the
     * audit record of what the endpoint WAS is history worth keeping, not live configuration.
     */
    public function test_the_hardcoded_integration_endpoint_is_gone_from_source(): void
    {
        $offenders = [];

        foreach (['app', 'routes', 'config', 'resources/views', 'database/migrations'] as $dir) {
            $base = base_path($dir);

            if (! is_dir($base)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (str_contains($contents, 'chat.lynomia.com')) {
                    $offenders[] = str_replace(base_path() . '/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The retired hardcoded endpoint chat.lynomia.com is still referenced in:\n"
            . implode("\n", $offenders)
            . "\nThe replacement integration must take its base URL from configuration."
        );
    }

    /**
     * No class from the retired integration may be referenced from surviving source.
     *
     * A dangling `use App\Models\ChatwootIntegration;` is a fatal at class load, and a dangling
     * reference inside a method body is a fatal the first time that path runs -- which, for a
     * settings tab nobody opens often, can be months later.
     */
    public function test_no_surviving_source_references_a_retired_class(): void
    {
        $needles = [
            'ChatwootIntegration',
            'ChatwootNotificationTemplate',
            'ChatwootService',
            'ChatwootOrderNotificationService',
            'ChatwootOrderVariableService',
            'ChatwootAbandonedCartNotificationService',
            'ChatwootAbandonedCartService',
            'SendChatwootOrderStatusNotification',
            'newsyria',
        ];

        $offenders = [];

        foreach (['app', 'routes', 'config', 'resources/views'] as $dir) {
            $base = base_path($dir);

            if (! is_dir($base)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                $relative = str_replace(base_path() . '/', '', $file->getPathname());

                foreach ($needles as $needle) {
                    // Comments recording the retirement are allowed; executable references are
                    // not. `use X;`, `X::`, `new X` and a type hint all survive comment
                    // stripping, so strip comments first and match on what is left.
                    $code = $this->stripComments($contents);

                    if (str_contains($code, $needle)) {
                        $offenders[] = "{$relative} -> {$needle}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "Executable references to retired Chatwoot classes survive:\n"
            . implode("\n", array_unique($offenders))
        );
    }

    /** Remove // # and block comments so only executable text is matched. */
    private function stripComments(string $php): string
    {
        $out = '';

        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
