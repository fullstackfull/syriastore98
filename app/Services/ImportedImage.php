<?php

namespace App\Services;

/**
 * What an importer may store from a remote payload, and under what name.  P1-SEC-06 (second review).
 *
 * The WooCommerce / Shopify importers wrote the bytes a merchant-controlled URL served under the file name that URL chose, extension
 * included, into uploads/<store>/ -- and the application root IS the web root here (index.php and .htaccess sit beside uploads/, the
 * `theme` disk root is base_path('')), where the web server serves an existing file before the front controller. `.../shell.php` served
 * as PHP source therefore became an executable file. SafeRemoteFetch secures WHERE the fetch goes; this secures WHAT is stored.
 *
 *   - the bytes must be a raster image PHP's own decoder recognises (jpeg, png, gif, webp, bmp, ico) -- not svg or html, which carry script;
 *   - the stored name is built here: the characters the remote chose are reduced to [A-Za-z0-9_-], and the extension is the one the CONTENT
 *     has (the remote's own spelling is kept only where it is an equivalent one, `.jpeg` for a JPEG), so a stored name has exactly one dot
 *     and nothing a handler mapping could read as `.php`.
 *
 * A polyglot (a valid GIF header followed by PHP source) still passes the content check; what protects it is that it is stored as `.gif`.
 */
final class ImportedImage
{
    /** @var array<int, array{0: string, 1: list<string>}> detected type => [canonical extension, equivalent spellings] */
    private const TYPES = [
        IMAGETYPE_JPEG => ['jpg', ['jpg', 'jpeg', 'jpe']],
        IMAGETYPE_PNG => ['png', ['png']],
        IMAGETYPE_GIF => ['gif', ['gif']],
        IMAGETYPE_WEBP => ['webp', ['webp']],
        IMAGETYPE_BMP => ['bmp', ['bmp']],
        IMAGETYPE_ICO => ['ico', ['ico']],
    ];

    /** The file name to store $bytes under, or null when they are not an accepted image. */
    public static function storedName(string $bytes, string $chosen): ?string
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);

        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            return null;
        }

        [$canonical, $equivalents] = self::TYPES[$info[2]];

        // pathinfo() already reduces a name with directories in it ("../../x.php") to its last component
        $spelling = strtolower(pathinfo($chosen, PATHINFO_EXTENSION));
        $stem = preg_replace(['/[^A-Za-z0-9_-]+/', '/_{2,}/'], ['_', '_'], pathinfo($chosen, PATHINFO_FILENAME));
        $stem = substr(trim((string) $stem, '_') === '' ? 'image' : (string) $stem, 0, 120);

        return $stem . '.' . (in_array($spelling, $equivalents, true) ? $spelling : $canonical);
    }
}
