<?php

namespace App\Services;

use App\Models\Utility;

/**
 * THE UPLOAD RULES THE SERVER WILL ACTUALLY APPLY, in one place, so the editor can advertise them.
 * =================================================================================================
 *
 * WHY THIS EXISTS (F75). `Utility::upload_file()` and `Utility::keyWiseUpload_file()` build their
 * validation from platform settings:
 *
 *     $mimes    = $settings['local_storage_validation']        // e.g. 'jpg,jpeg,png,csv,svg,pdf'
 *     $max_size = $settings['local_storage_max_upload_size']   // KILOBYTES, as the settings screen says
 *     $validation = ['mimes:' . $mimes, 'max:' . $max_size];
 *
 * The product editor advertised none of it. Its Dropzone is configured
 * `acceptedFiles: a ? null : "image/*"` and the gallery element carries `data-dropzone-multiple`,
 * so it took the `null` branch and accepted **any** file type, with Dropzone's default 256 MB
 * ceiling; `cover_image`, `downloadable_product` and `preview_video` carried no `accept` at all.
 * So a merchant could queue a GIF or a WebP, be shown no objection, and have the server refuse it
 * — which, for a gallery image, it then refused in silence (F75).
 *
 * THIS CLASS DOES NOT DECIDE ANYTHING. It reads the same settings through the same accessor and
 * reports them, so the client can say in advance what the server already enforces. Narrowing the
 * list here would newly block a file that works today (`pdf` and `csv` really are accepted), so it
 * is reported verbatim rather than filtered down to image types.
 *
 * THE KEY NAMES ARE NOT UNIFORM across the three storage backends, which is the one thing worth
 * knowing before editing this: local uses `local_storage_validation` /
 * `local_storage_max_upload_size`, while S3 and Wasabi use `<disk>_storage_validation` /
 * `<disk>_max_upload_size`. The mapping below mirrors `Utility::upload_file()` exactly.
 */
final class UploadLimits
{
    /** `Utility::upload_file()`'s own fallback when the setting row is absent. */
    private const DEFAULT_MAX_KB = 2048;

    /**
     * @return array{extensions: list<string>, max_kb: int, setting_max_kb: int, php_max_kb: int,
     *               request_max_kb: int, accept: string, max_label: string, request_max_label: string,
     *               storage: string}
     */
    public static function forStore(): array
    {
        $settings = (array) Utility::Setting();
        $storage = strtolower(trim((string) ($settings['storage_setting'] ?? 'local')));

        // Mirrors Utility::upload_file() -- the key names genuinely differ per backend.
        if ($storage === 'wasabi') {
            $rawExt = (string) ($settings['wasabi_storage_validation'] ?? '');
            $maxKb = (string) ($settings['wasabi_max_upload_size'] ?? '');
        } elseif ($storage === 's3') {
            $rawExt = (string) ($settings['s3_storage_validation'] ?? '');
            $maxKb = (string) ($settings['s3_max_upload_size'] ?? '');
        } else {
            $rawExt = (string) ($settings['local_storage_validation'] ?? '');
            $maxKb = (string) ($settings['local_storage_max_upload_size'] ?? '');
        }

        $extensions = [];
        foreach (explode(',', $rawExt) as $ext) {
            $ext = strtolower(ltrim(trim($ext), '.'));
            if ($ext !== '' && ! in_array($ext, $extensions, true)) {
                $extensions[] = $ext;
            }
        }

        $maxKb = $maxKb === '' ? self::DEFAULT_MAX_KB : (int) $maxKb;
        if ($maxKb <= 0) {
            $maxKb = self::DEFAULT_MAX_KB;
        }

        /* THE SETTING IS NOT THE LIMIT. PHP's own `upload_max_filesize` caps every single file and
         * `post_max_size` caps the whole request, and either can be — and here is — far stricter
         * than the platform setting. MEASURED in this container: the setting says 2,048,000 KB
         * (2 GB) while `upload_max_filesize` is 2 MB and `post_max_size` is 8 MB. The editor was
         * advertising Dropzone's 256 MB default on top of that, so all three numbers disagreed and
         * none of them was the one a merchant would hit.
         *
         * Exceeding them does NOT produce a clear message. Over `upload_max_filesize`, PHP drops
         * the file before Laravel sees it, so the gallery loop never receives it at all. Over
         * `post_max_size`, PHP discards the ENTIRE body, so `$_POST` is empty and the save fails
         * with "the name field is required" on a form the merchant filled in. Reporting the
         * effective number up front is the only honest thing the client can do. */
        $phpMaxKb = self::iniKb(ini_get('upload_max_filesize'));
        $requestMaxKb = self::iniKb(ini_get('post_max_size'));
        $effectiveKb = $phpMaxKb > 0 ? min($maxKb, $phpMaxKb) : $maxKb;

        return [
            'storage' => $storage,
            'extensions' => $extensions,
            'max_kb' => $effectiveKb,
            'setting_max_kb' => $maxKb,
            'php_max_kb' => $phpMaxKb,
            'request_max_kb' => $requestMaxKb,
            /* An `accept` attribute and Dropzone's `acceptedFiles` take the same comma list of
             * extensions. EMPTY WHEN NOTHING IS CONFIGURED, deliberately: an empty `accept` means
             * "no restriction", which is the honest rendering of a server that has not been told
             * what to allow -- whereas a list built from nothing would block every file. */
            'accept' => implode(',', array_map(static fn ($e) => '.' . $e, $extensions)),
            'max_label' => self::humanKb($effectiveKb),
            'request_max_label' => self::humanKb($requestMaxKb > 0 ? $requestMaxKb : $effectiveKb),
        ];
    }

    /** `2M` -> 2048. Returns 0 for an unset or unlimited directive, which callers read as "no cap". */
    public static function iniKb($value): int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }

        $unit = strtoupper(substr($value, -1));
        $n = (float) $value;

        return (int) match ($unit) {
            'G' => $n * 1024 * 1024,
            'M' => $n * 1024,
            'K' => $n,
            default => $n / 1024,      // a bare byte count
        };
    }

    /** `2048` -> `2 MB`. The settings screen stores KILOBYTES and labels itself "( In KB)". */
    public static function humanKb(int $kb): string
    {
        if ($kb >= 1048576) {
            return self::trimZeros(round($kb / 1048576, 1)) . ' GB';
        }

        if ($kb >= 1024) {
            return self::trimZeros(round($kb / 1024, 1)) . ' MB';
        }

        return $kb . ' KB';
    }

    private static function trimZeros(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
