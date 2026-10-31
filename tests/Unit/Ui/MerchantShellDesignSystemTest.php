<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * WS A — THE DESIGN SYSTEM'S BASE LAYER REACHES THE SHARED MERCHANT SHELL.
 * ========================================================================
 *
 * A recount of the Master Execution Prompt against the code found this as Workstream A's single
 * biggest gap, and it was a gap of REACH rather than of content: the design system was built, and
 * on roughly 140 of ~150 first-party merchant screens the only part of it a merchant actually saw
 * was buttons and form controls.
 *
 * `.ss-scope` is the sole rule that sets the family, size, leading, base colour and the Arabic
 * leading adjustment (`public/css/store-syria-ds.css:403-423`). It was present in 10 views and in
 * **0 of the 25 components** — so a `<x-ss.card>` dropped onto a host-themed page inherited the
 * host's typography and the DS's tokens disagreed with what was around them.
 *
 * Four things are pinned here, each of which was wrong on every merchant screen:
 *
 * | | was | now |
 * |---|---|---|
 * | the base layer | `.ss-scope` on no component root | on the five that carry text |
 * | `dir` | `dir=""` for English | `dir="ltr"` |
 * | the page heading | `<h2 …>…</h4>` | closed with `</h2>` |
 * | a skip link | in the stylesheet, rendered nowhere | first focusable element |
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A SOURCE TEST AND NOT A RENDERED ONE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Stated plainly because it is the honest limit of this file: these are assertions about the
 * TEMPLATES, not about a rendered page. The rendered half of Workstream A — contrast ratios, focus
 * rings, tab order, RTL geometry, overflow at eight widths — is covered by **no CI gate at all**.
 * `.github/workflows/ci.yml:627` keeps the browser suite out of CI by decision, and
 * `tests/Feature/DesignSystem/PilotScreenContractTest.php:24-26` says so in terms.
 *
 * So the 100-check RTL × theme × viewport evidence in `docs/development/phase-2/` is a
 * point-in-time artifact rather than a gate, and a direction, theme or keyboard regression would
 * ship green. A source ratchet does not close that; it closes the narrower question of whether the
 * shell still carries the four things above, which is what actually regressed here.
 */
class MerchantShellDesignSystemTest extends TestCase
{
    private const LAYOUT = __DIR__ . '/../../../resources/views/layouts/app.blade.php';

    /** The components that render text and therefore need the base layer. */
    private const SCOPED_COMPONENTS = [
        'page-header.blade.php',
        'card.blade.php',
        'empty.blade.php',
        'breadcrumb.blade.php',
        'error-summary.blade.php',
    ];

    private function layout(): string
    {
        $source = file_get_contents(self::LAYOUT);

        $this->assertNotFalse($source, 'the merchant layout could not be read');
        $this->assertGreaterThan(
            200,
            substr_count($source, "\n"),
            'the layout came back far too short, so every assertion below would be vacuous'
        );

        return $source;
    }

    /**
     * THE BASE LAYER IS ON THE COMPONENT ROOTS.
     *
     * `breadcrumb` matters most of the five: the layout renders it on every screen except the
     * dashboard, so scoping it alone carries the base layer to ~107 screens that had none.
     */
    public function test_every_text_bearing_component_carries_the_base_layer(): void
    {
        foreach (self::SCOPED_COMPONENTS as $component) {
            $path = __DIR__ . '/../../../resources/views/components/ss/' . $component;
            $source = file_get_contents($path);

            $this->assertNotFalse($source, $component . ' could not be read');
            $this->assertStringContainsString(
                'ss-scope',
                $source,
                $component . ' no longer carries `ss-scope` on its root. That rule is the ONLY '
                . 'place the design system sets font-family, size, leading, base colour and the '
                . 'Arabic leading adjustment -- without it the component inherits the host '
                . 'theme\'s typography and its own tokens disagree with the text around them.'
            );
        }
    }

    /**
     * `dir` IS ALWAYS A REAL VALUE. It was the empty string for English.
     *
     * `dir=""` is not invalid, but it is also not `ltr`: it makes the element inherit, which on
     * `<html>` means the UA default. It happened to be right, and it meant the one attribute the
     * whole RTL/LTR requirement turns on was never actually asserted for English.
     */
    public function test_the_document_direction_is_never_empty(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString(
            "'rtl' : 'ltr'",
            $layout,
            'the `dir` attribute no longer resolves to a real value in both branches. It used to '
            . 'emit `dir=""` for English, so the attribute the entire RTL/LTR requirement turns on '
            . 'was unset on every English screen.'
        );

        $this->assertStringNotContainsString(
            "'rtl' : '' }}\"",
            $layout,
            'the empty-string branch is back'
        );
    }

    /**
     * THE PAGE HEADING IS WELL-FORMED. `<h2>` was closed with `</h4>`.
     *
     * Browsers recover, so nothing broke visibly -- which is why it survived. The DOM a screen
     * reader walks, and any heading-order audit, saw a malformed tree on every merchant screen.
     */
    public function test_the_page_heading_is_closed_with_its_own_tag(): void
    {
        $layout = $this->layout();

        /*
         * THE MISMATCHED PAIR, not the presence of `</h4>` anywhere -- which is what the first
         * version of this assertion tested, and it failed on two perfectly well-formed
         * `<h4 class="modal-title"></h4>` elements and on this file's own comment. A file-scoped
         * string search answers a different question from the one asked; the project has recorded
         * that same mistake four times, so it is worth not making a fifth.
         */
        $this->assertSame(
            0,
            preg_match_all('/<h2\\b[^>]*>(?:(?!<\\/h[1-6]>).)*<\\/h4>/s', $layout),
            'an `<h2>` is closed with `</h4>` in the merchant layout again. Browsers recover, so '
            . 'nothing breaks visibly -- which is exactly how it survived -- but the heading tree '
            . 'a screen reader and any heading-order audit walk is malformed on every screen.'
        );

        $this->assertMatchesRegularExpression(
            '/<h2[^>]*>@yield\(\'page-title\'\)<\/h2>/',
            $layout,
            'the page title is no longer inside a well-formed h2'
        );
    }

    /**
     * THE SKIP LINK EXISTS, IS FIRST, AND HAS SOMEWHERE TO GO.
     *
     * All three halves, because each is separately easy to get wrong: a skip link after the
     * sidebar skips nothing, and one pointing at an element without `tabindex="-1"` scrolls
     * without moving focus, which leaves the next Tab back at the top of the page.
     */
    public function test_the_skip_link_is_rendered_first_and_has_a_focusable_target(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString(
            'class="ss-skip-link"',
            $layout,
            '`.ss-skip-link` has existed in the stylesheet since the beginning and was rendered by '
            . 'nothing. Without it a keyboard user tabs the entire sidebar before reaching the '
            . 'content, on every merchant screen.'
        );

        $this->assertStringContainsString('href="#ss-main"', $layout, 'the skip link points nowhere');
        $this->assertStringContainsString(
            'id="ss-main" tabindex="-1"',
            $layout,
            'the skip target has no `tabindex="-1"`, so following the link scrolls the page '
            . 'without moving focus -- and the next Tab returns to the top'
        );

        /* AND IT PRECEDES THE SIDEBAR'S CONTENT CONTAINER, or it skips nothing. */
        $this->assertLessThan(
            strpos($layout, 'class="dash-container"'),
            strpos($layout, 'class="ss-skip-link"'),
            'the skip link is rendered after the content container, so it is not the first '
            . 'focusable element and skips nothing'
        );
    }

    /**
     * AND THE STRING IS TRANSLATED, in both languages.
     *
     * A skip link is read aloud; an untranslated one is read aloud in English to an Arabic user.
     * The project's own translation ratchet covers `resources/lang/*.json` as files, not this
     * string in particular, so it is named here.
     */
    public function test_the_skip_link_string_is_translated_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $strings = json_decode(file_get_contents(__DIR__ . '/../../../resources/lang/' . $locale . '.json'), true);

            $this->assertIsArray($strings, $locale . '.json did not decode');
            $this->assertArrayHasKey(
                'Skip to main content',
                $strings,
                'the skip-link string is missing from ' . $locale . '.json, so it renders as the '
                . 'English source text'
            );
            $this->assertNotSame('', trim((string) $strings['Skip to main content']));
        }

        $ar = json_decode(file_get_contents(__DIR__ . '/../../../resources/lang/ar.json'), true);

        $this->assertNotSame(
            'Skip to main content',
            $ar['Skip to main content'],
            'the Arabic entry is the English string, which is how an untranslated key hides from a '
            . 'key-presence check'
        );
    }
}
