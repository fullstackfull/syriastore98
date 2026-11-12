<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 — a Blade view whose layout cannot print its slot renders a BLANK PAGE, silently.
 * =================================================================================================
 *
 * THE COLLISION, measured once already on `/profile/edit` and found again here.
 *
 * Breeze writes a page as `<x-app-layout> … </x-app-layout>`, which passes the body to the
 * component as `$slot`. This application's layouts are the vendored WorkDo ones, and they emit
 * page content with `@yield('content')`. A layout that YIELDS a section never outputs the SLOT it
 * was handed, so the entire page body is discarded — with no exception, no warning and HTTP 200.
 * The chrome renders perfectly, which is why a route sweep that checks the chrome passes it.
 *
 *     resources/views/layouts/app.blade.php     0 references to `$slot`, emits @yield('content')
 *     resources/views/layouts/guest.blade.php   0 references to `$slot`
 *
 * This is a STATIC check and deliberately so. The behaviour is already proved per-route where it
 * matters; what was missing is the thing that stops the next one appearing. A view can be added in
 * either convention at any time, and the failure is invisible from the outside.
 *
 * WHAT IT DOES NOT CLAIM. It does not say a declared view is fine — only that somebody has written
 * down why it never reaches a browser. Each entry in {@see self::NEVER_RENDERED} carries that
 * reason, and the reason is a fact a reader can check.
 */
class SlotLayoutViewsRenderTest extends TestCase
{
    /**
     * `<x-NAME-layout>` → the layout file `App\View\Components\…Layout::render()` returns.
     */
    private const LAYOUT_COMPONENTS = [
        'x-app-layout' => 'resources/views/layouts/app.blade.php',
        'x-guest-layout' => 'resources/views/layouts/guest.blade.php',
    ];

    /**
     * Views that use a slot-passing layout and are known never to reach a browser, each with the
     * reason. A view listed here but now reachable is a defect this test cannot see, so the reason
     * has to be the kind of claim somebody can re-check.
     */
    private const NEVER_RENDERED = [
        'resources/views/profile/edit.blade.php' =>
            'ProfileController@edit returns Redirect::route(\'profile\') and never renders it. '
            . 'The view is kept because NEW-ROUTE-SHADOW-01 pins the `profile.edit` ROUTE, which '
            . 'ProfileController::update() redirects to by name. See '
            . 'tests/Feature/ProfileEditRendersSomethingTest.',
    ];

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return string[] */
    private function bladeViews(): array
    {
        $root = $this->repositoryRoot() . '/resources/views';

        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $found[] = str_replace($this->repositoryRoot() . '/', '', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Blade comments are not markup, and a docblock that NAMES the broken shape is not the broken
     * shape.
     *
     * Found immediately: the repaired `confirm-password.blade.php` opens with a `{{-- … --}}`
     * block explaining what `<x-guest-layout>` did to it, and the first version of this test
     * failed the repaired file on its own explanation. A check that cannot tell prose from code
     * would have pushed the next person to delete the explanation rather than keep it.
     */
    private function withoutBladeComments(string $source): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    public function test_the_premise_that_these_layouts_cannot_print_a_slot(): void
    {
        // THE PREMISE. If a layout ever starts printing `$slot`, every view using its component
        // renders correctly and this whole test is measuring nothing — it should then be
        // re-pointed rather than left to pass vacuously.
        foreach (self::LAYOUT_COMPONENTS as $component => $layout) {
            $path = $this->repositoryRoot() . '/' . $layout;

            $this->assertFileExists($path, "$layout is gone, so `<$component>` resolves to nothing");

            $this->assertStringNotContainsString(
                '$slot',
                (string) file_get_contents($path),
                "$layout now prints \$slot, so `<$component>` pages render after all. This test "
                . 'exists because it did not; re-point it at whatever the layouts do now.'
            );
        }
    }

    public function test_no_view_passes_a_slot_to_a_layout_that_cannot_print_it(): void
    {
        $offenders = [];

        foreach ($this->bladeViews() as $relative) {
            if (array_key_exists($relative, self::NEVER_RENDERED)) {
                continue;
            }

            $source = $this->withoutBladeComments(
                (string) file_get_contents($this->repositoryRoot() . '/' . $relative)
            );

            foreach (array_keys(self::LAYOUT_COMPONENTS) as $component) {
                if (str_contains($source, '<' . $component)) {
                    $offenders[] = "$relative uses <$component>";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A view passes its body to a layout that emits @yield('content') and never prints "
            . "\$slot, so it renders CHROME AND NOTHING ELSE at HTTP 200.\n  "
            . implode("\n  ", $offenders)
            . "\n\nEither convert it to @extends + @section('content') like its siblings, or — if "
            . 'it genuinely never reaches a browser — declare it in NEVER_RENDERED with the '
            . 'reason. Do not delete this assertion: /profile/edit shipped blank for exactly this '
            . 'reason and a four-combination route sweep passed it, because the chrome was right.'
        );
    }

    public function test_every_declared_exception_still_exists(): void
    {
        // A stale declaration is a hole: the file could be renamed, a new one written in its place
        // in the same broken shape, and this test would skip it by path.
        foreach (array_keys(self::NEVER_RENDERED) as $relative) {
            $this->assertFileExists(
                $this->repositoryRoot() . '/' . $relative,
                "$relative is declared as never-rendered but no longer exists. Remove the "
                . 'declaration rather than leaving a path that excludes nothing.'
            );
        }
    }
}
