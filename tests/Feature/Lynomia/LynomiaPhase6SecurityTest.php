<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 6's safety properties, pinned as properties of the source rather than of one response.
 *
 * Phase 6 put the installation lifecycle on a page: suspend, resume, re-authenticate and the one
 * transition with no way back. What that moves into the browser is a class of defect no
 * controller test can see -- a control the page should not have drawn, or a guard the page
 * claims to have and does not.
 */
class LynomiaPhase6SecurityTest extends TestCase
{
    private const MODULE = 'packages/workdo/LynomiaChat/src';

    /**
     * THE BUG THIS WAS WRITTEN AFTER.
     *
     * Decommission is irreversible, so it was given a confirm():
     *
     *     onsubmit="return confirm(@json(__('Decommissioning cannot be undone…')))"
     *
     * Blade's @json does not escape double quotes -- unlike Js::from, which is what it is for.
     * Inside a double-quoted attribute the first quote of the JSON string ENDS THE ATTRIBUTE, so
     * the handler shipped as the fragment `return confirm(` and the rest of the sentence landed
     * in the markup as stray attributes. The browser parsed it, threw a syntax error nobody saw,
     * ran no handler, and submitted the form. The irreversible transition had no guard at all.
     *
     * Nothing server-side could notice: the request that arrives is the same either way. It was
     * found by clicking the button in a browser (tests/Browser/p6-operator-console.mjs) and it is
     * kept out by reading the source, because the next one of these will be in a view that
     * harness does not render.
     */
    public function test_no_view_puts_an_unescaped_json_directive_inside_an_html_attribute(): void
    {
        $offenders = [];

        foreach ($this->views() as $path => $source) {
            // An HTML attribute opened with a double quote, with @json before the closing one.
            if (preg_match_all('/\s[a-zA-Z-]+="[^"]*@json\(/', $source, $matches)) {
                foreach ($matches[0] as $match) {
                    $offenders[] = "{$path}: " . trim($match);
                }
            }
        }

        $this->assertSame([], $offenders,
            "@json inside a double-quoted attribute truncates it at the first quote of the JSON "
            . "string. Use {{ Js::from(…) }}, which hex-escapes quotes for exactly this case:\n"
            . implode("\n", $offenders));
    }

    /**
     * Every lifecycle transition is a POST. A GET that suspends an installation is a transition
     * that happens when a crawler, a prefetch, a browser's link preview or a pasted URL touches
     * it -- no click and no operator required.
     */
    public function test_every_lifecycle_transition_is_a_post_route(): void
    {
        // Measured on the booted router, not read off the routes file: the file is a program, and
        // the first draft of this test matched the word POST from one route and the name from
        // another, which is a test that passes while the route is a GET.
        $named = collect(Route::getRoutes())->keyBy(fn ($route) => (string) $route->getName());

        foreach (['approve', 'revoke', 'restore', 'suspend', 'resume', 'decommission', 'reauthenticate'] as $action) {
            $route = $named->get("lynomia.admin.links.{$action}");

            $this->assertNotNull($route, "lynomia.admin.links.{$action} is not registered.");
            $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])),
                "links.{$action} answers " . implode('/', $route->methods())
                . ", so it can be triggered by anything that follows a link.");
        }
    }

    /**
     * The operator page is built from local tables on purpose: the times an operator opens it are
     * the times Lynomia may be the thing that is wrong. One panel reaches across, and it is
     * fetched from the browser so its failure is a sentence in one cell. A second blocking call
     * added to the controller would turn an unreachable Lynomia into a page that will not load.
     */
    public function test_the_operator_page_renders_without_reaching_lynomia(): void
    {
        $controller = file_get_contents(
            base_path(self::MODULE . '/app/Http/Controllers/SuperAdmin/IntegrationController.php')
        );

        // show() is everything up to the next method on the class.
        preg_match('/public function show\(.*?\n    \}/s', $controller, $match);
        $this->assertNotEmpty($match, 'IntegrationController::show was renamed or removed.');

        foreach (['PlatformApiClient', 'CommerceCredential', 'Http::'] as $reachesAcross) {
            $this->assertStringNotContainsString($reachesAcross, $match[0],
                "IntegrationController::show now calls Lynomia, so the page an operator opens when "
                . "Lynomia is unreachable is the page that will not render.");
        }
    }

    /** @return array<string, string> */
    private function views(): array
    {
        $found = [];
        $root = base_path(self::MODULE . '/resources/views');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $found[str_replace($root . '/', '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }

        return $found;
    }
}
