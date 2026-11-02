<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;

/**
 * F259 — every `x-ss.panel` must carry its own loading and error text.
 *
 * THE JAVASCRIPT HAS NO TRANSLATOR AND CANNOT HAVE ONE. `loadPanel()` fills the panel body before
 * and after its fetch, and the strings it writes come from `data-ss-loading-text` /
 * `data-ss-error-text` on the element — attributes the Blade component renders from its props.
 * Where a caller passes neither, `public/js/store-syria-ds.js` falls through to a hardcoded
 * English literal:
 *
 *     body.getAttribute('data-ss-loading-text') || 'Loading…'
 *     body.getAttribute('data-ss-error-text')   || 'This could not be loaded.'
 *
 * Three of the four declarations passed both strings through `__()`. The fourth —
 * `layouts/app.blade.php`'s AI panel, which is on EVERY merchant screen — passed neither, so an
 * Arabic merchant opening it was shown English inside a `dir="rtl"` document. The component's own
 * docblock warns about this; nothing enforced it.
 *
 * WHY THIS IS A SOURCE TEST AND WHAT THAT COSTS. The strings only become visible during a fetch
 * that is in flight or has failed, which a Feature test cannot produce — it renders markup, it
 * does not run `loadPanel`. A browser check could drive it, and
 * `docs/development/phase-2/tools/check-ai-panel-live.cjs` already drives this panel; what it
 * cannot easily do is prove the ABSENCE of a future fifth panel declared without strings. That is
 * what this ratchet is for: it fails on a declaration nobody has driven yet.
 *
 * Comments are stripped before the census, because a `{{-- --}}` block explaining this defect
 * would otherwise satisfy it — the failure mode this repository has now hit four times.
 */
class PanelStringContractTest extends TestCase
{
    /** @return array<string, string> path => source with Blade comments removed */
    private function viewsDeclaringAPanel(): array
    {
        $root = base_path('resources/views');
        $found = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

            if (! str_contains($code, '<x-ss.panel')) {
                continue;
            }

            $found[str_replace(base_path() . '/', '', $file->getPathname())] = $code;
        }

        return $found;
    }

    public function test_the_census_finds_the_panels_it_is_supposed_to_check(): void
    {
        // ANTI-VACUITY. A census that finds nothing passes, and this one scans by a literal that
        // a rename would break.
        $this->assertGreaterThanOrEqual(
            4,
            count($this->viewsDeclaringAPanel()),
            'fewer panel declarations were found than are known to exist, so this ratchet is '
            . 'measuring nothing'
        );
    }

    public function test_every_panel_declares_its_own_loading_and_error_text(): void
    {
        $missing = [];

        foreach ($this->viewsDeclaringAPanel() as $path => $code) {
            // Each `<x-ss.panel` occurrence up to its closing `>`, so a view with two panels is
            // checked twice rather than passing because its sibling carries the props.
            preg_match_all('/<x-ss\.panel\b[^>]*>/s', $code, $matches);

            foreach ($matches[0] as $declaration) {
                foreach (['loading-text', 'error-text'] as $prop) {
                    if (! str_contains($declaration, $prop)) {
                        $missing[] = "{$path}: a panel declares no {$prop}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing) . "\n\n"
            . 'A panel without these props falls through to the hardcoded English in '
            . 'public/js/store-syria-ds.js, which an Arabic merchant reads inside an RTL document. '
            . 'The JavaScript has no translator; the strings have to come from the Blade.');
    }

    /**
     * And the strings must be TRANSLATED, not just present. `loading-text="Loading…"` would
     * satisfy the test above while shipping the same defect in a different file.
     */
    public function test_the_panel_strings_go_through_the_translator(): void
    {
        $untranslated = [];

        foreach ($this->viewsDeclaringAPanel() as $path => $code) {
            preg_match_all('/<x-ss\.panel\b[^>]*>/s', $code, $matches);

            foreach ($matches[0] as $declaration) {
                foreach (['loading-text', 'error-text', 'title'] as $prop) {
                    if (! preg_match('/[:]?' . preg_quote($prop, '/') . '="([^"]*)"/', $declaration, $m)) {
                        continue;
                    }
                    if (! str_contains($m[1], '__(')) {
                        $untranslated[] = "{$path}: {$prop} is a literal, not a __() call";
                    }
                }
            }
        }

        $this->assertSame([], $untranslated, implode("\n", $untranslated));
    }

    /**
     * The Arabic values must actually exist. A `__()` call whose key is absent from `ar.json`
     * returns the English key — readable, and not the merchant's language.
     */
    public function test_every_panel_string_has_an_arabic_value(): void
    {
        $arabic = json_decode((string) file_get_contents(resource_path('lang/ar.json')), true);
        $this->assertIsArray($arabic);

        $missing = [];

        foreach ($this->viewsDeclaringAPanel() as $path => $code) {
            preg_match_all('/<x-ss\.panel\b[^>]*>/s', $code, $matches);

            foreach ($matches[0] as $declaration) {
                preg_match_all("/__\('((?:[^'\\\\]|\\\\.)*)'\)/", $declaration, $keys);

                foreach ($keys[1] as $key) {
                    $key = str_replace("\\'", "'", $key);
                    if (! array_key_exists($key, $arabic) || trim((string) $arabic[$key]) === '') {
                        $missing[] = "{$path}: no Arabic value for \"{$key}\"";
                    }
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }
}
