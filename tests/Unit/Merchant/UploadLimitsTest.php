<?php

namespace Tests\Unit\Merchant;

use App\Services\UploadLimits;
use PHPUnit\Framework\TestCase;

/**
 * F75 — the editor must advertise the limit a merchant will ACTUALLY hit.
 * =======================================================================
 *
 * THREE NUMBERS DISAGREED AND NONE OF THEM WAS THE REAL ONE. Measured in the development
 * container:
 *
 *   | source                                  | value      |
 *   |-----------------------------------------|------------|
 *   | `local_storage_max_upload_size` setting | 2,048,000 KB (≈2 GB) |
 *   | PHP `upload_max_filesize`               | 2 MB       |
 *   | PHP `post_max_size`                     | 8 MB       |
 *   | what the editor told the merchant       | 256 MB (Dropzone's default) |
 *
 * The one that bites first is PHP's, and exceeding it does not produce a clear message: over
 * `upload_max_filesize` PHP drops the file before Laravel sees it, so the gallery loop never
 * receives it; over `post_max_size` PHP discards the ENTIRE body, so `$_POST` is empty and the save
 * fails with "the name field is required" on a form the merchant filled in.
 *
 * `UploadLimits::forStore()` therefore reports the MINIMUM of the setting and
 * `upload_max_filesize`. The arithmetic is what this file pins; the settings reading is exercised
 * by the live harness, which needs a booted application and a real request.
 */
class UploadLimitsTest extends TestCase
{
    /** @return list<array{0: string, 1: int, 2: string}> */
    public static function iniValues(): array
    {
        return [
            'megabytes'          => ['2M', 2048, 'the common form in php.ini'],
            'megabytes lower'    => ['8m', 8192, 'php.ini is case-insensitive about the suffix'],
            'kilobytes'          => ['512K', 512, ''],
            'gigabytes'          => ['1G', 1048576, ''],
            'bare bytes'         => ['2097152', 2048, 'a value with no suffix is BYTES, not kilobytes'],
            'unlimited'          => ['-1', 0, '0 means "no cap", which callers must not treat as "nothing allowed"'],
            'empty'              => ['', 0, 'an unset directive is not a zero-byte limit'],
            'zero'               => ['0', 0, ''],
        ];
    }

    /** @dataProvider iniValues */
    public function test_an_ini_size_is_read_as_kilobytes(string $raw, int $expected, string $why): void
    {
        $this->assertSame($expected, UploadLimits::iniKb($raw),
            'ini value ' . json_encode($raw) . ' should be ' . $expected . ' KB'
            . ($why !== '' ? ' — ' . $why : ''));
    }

    /** @return list<array{0: int, 1: string}> */
    public static function sizes(): array
    {
        return [
            [512, '512 KB'],
            [1024, '1 MB'],
            [2048, '2 MB'],
            [1536, '1.5 MB'],
            [8192, '8 MB'],
            [2048000, '2 GB'],          // the setting this codebase actually ships
            [1048576, '1 GB'],
            [1073741824, '1024 GB'],
        ];
    }

    /** @dataProvider sizes */
    public function test_a_size_is_labelled_without_trailing_zeros(int $kb, string $expected): void
    {
        $this->assertSame($expected, UploadLimits::humanKb($kb),
            $kb . ' KB should read as "' . $expected . '"');
    }

    public function test_the_label_never_shows_a_bare_decimal_point_or_a_trailing_zero(): void
    {
        // `2.0 MB` and `2. MB` are both wrong; this is the formatting rule, asserted on its own
        // because it is the kind of thing a later rounding change breaks silently.
        foreach ([1024, 2048, 1048576, 10240] as $kb) {
            $label = UploadLimits::humanKb($kb);
            $this->assertStringNotContainsString('.0 ', $label, $kb . ' -> ' . $label);
            $this->assertStringNotContainsString('. ', $label, $kb . ' -> ' . $label);
        }
    }

    public function test_the_service_exposes_everything_the_views_interpolate(): void
    {
        /* A CONTRACT TEST FOR THE BLADE TEMPLATES, which read these keys by name. A renamed key
         * would otherwise surface as an "Undefined array key" warning in the product editor, which
         * no existing test opens. The create and edit views interpolate exactly these. */
        $r = new \ReflectionMethod(UploadLimits::class, 'forStore');
        $this->assertTrue($r->isStatic(), 'the views call it statically');

        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Services/UploadLimits.php');
        foreach (['extensions', 'max_kb', 'accept', 'max_label', 'request_max_label',
                  'setting_max_kb', 'php_max_kb', 'request_max_kb', 'storage'] as $key) {
            $this->assertStringContainsString("'" . $key . "' =>", $source,
                'the views and this test expect forStore() to return a `' . $key . '` key');
        }

        foreach (['create', 'edit'] as $view) {
            $blade = (string) file_get_contents(
                dirname(__DIR__, 3) . '/resources/views/product/' . $view . '.blade.php'
            );
            $this->assertStringContainsString('UploadLimits::forStore()', $blade,
                $view . '.blade.php should read the real limits rather than hardcode any');
            foreach (["\$ssUpload['accept']", "\$ssUpload['max_kb']", "\$ssUpload['max_label']"] as $used) {
                $this->assertStringContainsString($used, $blade,
                    $view . '.blade.php should interpolate ' . $used);
            }
        }
    }

    public function test_the_editor_does_not_advertise_dropzones_default_ceiling(): void
    {
        /* THE REGRESSION THIS PINS. `maxFilesize` is MEGABYTES in Dropzone and its default is 256,
         * which was 128x the real per-file ceiling; `acceptedFiles: a ? null : "image/*"` took the
         * `null` branch on the gallery element and accepted ANY file type. Both are ratcheted
         * because the ordinary next event is somebody copying this block into another editor. */
        foreach (['create', 'edit'] as $view) {
            $blade = (string) file_get_contents(
                dirname(__DIR__, 3) . '/resources/views/product/' . $view . '.blade.php'
            );
            $this->assertMatchesRegularExpression('/maxFilesize:\s*\{\{/', $blade,
                $view . '.blade.php must compute maxFilesize from the real limit, not leave '
                . "Dropzone's 256 MB default in place");
            $this->assertStringNotContainsString('acceptedFiles: a ? null', $blade,
                $view . '.blade.php must not fall back to `null`, which accepts every file type '
                . 'while the server refuses everything outside its configured list. See F75.');
        }
    }
}
