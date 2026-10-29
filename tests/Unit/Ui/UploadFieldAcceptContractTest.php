<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * F76 AND THE PER-FIELD `accept` GAPS — the picker must offer what the server will take.
 * =============================================================================================
 *
 * Owner instruction, 2026-10-29: *"عالج F76 وحقول `downloadable_product` و`preview_video` وفق نوع
 * الملفات المسموح فعلياً لكل حقل، وليس `image/*` للجميع."*
 *
 * MEASURED FIRST, because "the permitted types" turned out not to be a per-field thing at all.
 * `Utility::upload_file($request, 'preview_video', …, [])` passes an EMPTY custom-validation array,
 * so the rule falls back to one platform-wide list — `local_storage_validation`, default
 * `jpg,jpeg,png,csv,svg,pdf` (`Utility.php:62`). The same six extensions govern a product image, a
 * downloadable deliverable and a preview video. Neither field had an `accept` attribute at all, so
 * the picker offered everything and the save then refused most of it: F75's defect shape, one field
 * over.
 *
 * WHAT THIS SLICE DOES is make the client tell the truth — `accept` from `UploadLimits::forStore()`,
 * the same source the gallery upload already uses — and fix F76's panel. WHAT IT DOES NOT DO is
 * widen the server's list so a video field can take a video; that is **F190**, and it is a product
 * decision about what a merchant may sell, not a defect to patch inside a UI slice.
 *
 * A UNIT TEST: it reads files and parses them. No container, no database.
 */
class UploadFieldAcceptContractTest extends TestCase
{
    private const VIEWS = [
        'resources/views/product/create.blade.php',
        'resources/views/product/edit.blade.php',
    ];

    /** The two fields the owner named, plus the gallery field that already had this right. */
    private const FIELDS = ['downloadable_product', 'preview_video'];

    public function test_every_named_file_field_advertises_the_servers_own_list(): void
    {
        foreach (self::VIEWS as $relative) {
            $source = $this->read($relative);

            foreach (self::FIELDS as $field) {
                $tag = $this->inputTagFor($source, $field, $relative);

                $this->assertMatchesRegularExpression(
                    '/accept="\{\{\s*\$ssUpload\[\'accept\'\]\s*\}\}"/',
                    $tag,
                    $relative . ': the `' . $field . '` input does not take its `accept` from'
                    . ' UploadLimits. Without it the picker offers every file type and the save'
                    . ' refuses most of them -- which is F75, one field over. Tag: ' . $tag
                );
            }
        }
    }

    /**
     * NOT `image/*`, which is the half of the instruction that names a wrong answer rather than a
     * missing one. A video field and a downloadable deliverable are not images, and hardcoding any
     * list here would drift from the server's.
     */
    public function test_no_named_field_hardcodes_a_type_list(): void
    {
        foreach (self::VIEWS as $relative) {
            $source = $this->read($relative);

            foreach (self::FIELDS as $field) {
                $tag = $this->inputTagFor($source, $field, $relative);

                foreach (['image/*', 'video/*', '.jpg', '.png', '.mp4', 'application/'] as $hardcoded) {
                    $this->assertStringNotContainsString(
                        $hardcoded,
                        $tag,
                        $relative . ': the `' . $field . '` input hardcodes `' . $hardcoded . '`.'
                        . ' The permitted list lives in one setting the server reads; a copy here'
                        . ' drifts from it silently.'
                    );
                }
            }
        }
    }

    /**
     * F76 AND ITS COUPLED HALF, ASSERTED TOGETHER, because that coupling is the whole reason the
     * finding waited.
     *
     * `.ss-upload-hint--on-light` forces `--ss-grey-600` precisely because the panel stayed white
     * in both themes. Theming the panel without moving the hint leaves #5b6665 on #1f2a28 —
     * measured **2.49:1**, an AA failure introduced by fixing an appearance bug. So a future change
     * that themes the panel and forgets the hint must fail here.
     */
    public function test_the_dark_panel_and_its_hint_are_themed_together(): void
    {
        $css = $this->read('public/css/store-syria-ds.css');

        foreach (['[data-ss-theme="dark"]', '[data-ss-theme="auto"]'] as $scope) {
            $this->assertStringContainsString(
                $scope . ' .dropzone',
                $css,
                'F76: the upload panel is not themed for ' . $scope . ', so it computes white in'
                . ' dark mode -- which is the finding.'
            );

            $this->assertStringContainsString(
                $scope . ' .dropzone .ss-upload-hint--on-light',
                $css,
                'the panel is themed for ' . $scope . ' but the hint inside it is not. The hint'
                . ' forces --ss-grey-600 BECAUSE the panel used to stay white; on the themed panel'
                . ' that measures 2.49:1, below AA. The two have to move together.'
            );
        }

        /* Light mode is untouched: the panel stays white and the hint stays the primitive. */
        $this->assertStringContainsString(
            '.ss-upload-hint--on-light { color: var(--ss-grey-600) !important; }',
            $css,
            'the light-mode hint rule is gone, so the hint now switches with the theme on a panel'
            . ' that only switches in dark -- the original F68 defect, inverted'
        );
    }

    /**
     * THE PANEL RULE HAS TO WIN. The host's own `.dropzone` sets white, and this file keeps a
     * documented Host-override defence list for exactly that reason.
     */
    public function test_the_panel_override_is_marked_important(): void
    {
        $css = $this->read('public/css/store-syria-ds.css');

        $block = (string) strstr($css, '[data-ss-theme="dark"] .dropzone');
        $block = substr($block, 0, (int) strpos($block, '}') + 1);

        $this->assertStringContainsString(
            'background-color: var(--ss-surface-sunken) !important',
            $block,
            'the dark panel background is not marked important, so the host stylesheet keeps'
            . ' painting it white and F76 is not actually fixed'
        );
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;

        $this->assertFileExists($path, $relative . ' is gone');

        return (string) file_get_contents($path);
    }

    /** The whole `<input …>` tag for a named field, so an assertion cannot match a neighbour. */
    private function inputTagFor(string $source, string $field, string $relative): string
    {
        $needle = 'name="' . $field . '"';
        $at = strpos($source, $needle);

        $this->assertNotFalse($at, $relative . ': there is no `' . $field . '` input any more');

        $open = strrpos(substr($source, 0, $at), '<input');
        $this->assertNotFalse($open, $relative . ': the `' . $field . '` name is not on an input');

        $close = strpos($source, '>', $at);

        return substr($source, $open, $close - $open + 1);
    }
}
