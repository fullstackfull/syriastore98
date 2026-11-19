<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * What `upload_theme_image()` will store, and under which extension.  P1-SEC-72.
 *
 * WHAT WENT WRONG. The helper (app/Helper/helper.php) is the image upload of seven call sites -- a storefront customer's avatar,
 * three payment-setting handlers of the admin API, the web payment settings, and two vendored sign-in settings handlers -- and it
 * applied NO type rule at all: it took the stored extension from `getClientOriginalExtension()` and wrote through the `theme`
 * disk, whose root is the application root. The first of those call sites needs nothing but a registration: `POST
 * api/{slug}/register` is public and answers with a Bearer token, `POST api/{slug}/update-user-image` asked `'image' =>
 * 'required'` and nothing else, and its response returns the stored path. EXECUTED on the unchanged code (the first form of
 * ThemeImageUploadTypeTest: 47 of its 50 cases failing): `shell.php`, `shell.phtml`, `shell.phar`, `page.html`, `code.js`,
 * `.htaccess` and `logo.svg` were each stored, under those extensions, by a visitor who had only registered. The other
 * first-party call sites stored `shell.php` for a merchant (executed the same day; P01-SECURITY-WORK-QUEUE, P1-SEC-72 b).
 *
 * WHAT THIS ENFORCES, for every caller, in one place -- the callers are seven and two of them are vendored and cannot be edited:
 *
 *  1. AN UPLOADED FILE, AND A VALID ONE. A string or an array in the file's place used to reach `->getSize()` and answer 500.
 *  2. THE CONTENT BEGINS AS A LISTED IMAGE TYPE (`mimes:`). The type is read from the file's own bytes by fileinfo -- its
 *     SIGNATURE. The file is not decoded: this is a statement about how the file starts, not a promise that it is a working
 *     picture. See WHAT THIS DOES NOT DO.
 *  3. THE CLIENT'S NAME CARRIES AN IMAGE EXTENSION TOO (`extensions:`). Owner decision D-29.5 pairs the two rules. Here the name
 *     on disk is never the client's (rule 5), so this rule decides what is REFUSED, not what can land on disk: a file that is
 *     not named as an image is not treated as one. Two names a JPEG is commonly saved under are accepted beside the types
 *     (JPEG_NAME_ALIASES); a name with no extension is refused.
 *  4. A SIZE CEILING. See maxKb().
 *  5. THE EXTENSION WRITTEN TO DISK IS THE SERVER'S. It is derived from the sniffed type and must itself be in the list, or
 *     nothing is written. Rule 2 already implies it; it is held separately because the stored NAME is what a web server acts
 *     on, and a later loosening of the validation must not be able to put a client's extension back on disk.
 *
 * WHY `mimes:` AND NOT `mimetypes:`. Both read the bytes in this framework version (measured on 11.57.1; an earlier comment here,
 * and config/uploads.php, said `mimetypes:` trusts the string the client sent -- it does not: its rule asks the file for its
 * sniffed type, and the client's string is a different method that no rule reads). `mimes:` is used because it maps the sniffed
 * type onto an EXTENSION list, and the extension is what the stored name has to carry.
 *
 * TWO LISTS, AND WHY SVG IS IN ONE OF THEM. An SVG is a document that can carry script, and it is served from the application's
 * own origin. A storefront customer -- anyone who registers -- gets the raster types only. The panel callers keep SVG, and that is
 * a reading of the owner's standing position, not a settled one. The decision register (PHASE-02-DECISION-REGISTER, D-29.5) says
 * that the SVG exposure the old list permits "must be brought under control rather than left", with "no blanket change to every
 * WorkDo file or every merchant"; the two lists it produced carry no `svg`, and PANEL_TYPES is a NEW per-field list that does.
 * config/uploads.php reads the decision as keeping SVG for merchants because refusing it "would break any merchant using an SVG
 * logo today" -- that sentence is the engineer's, not the register's. Two store folders in the tracked snapshot of `uploads/`
 * hold SVG uploads (17 files, fifteen of them one logo saved repeatedly), and a merchant can store an SVG through
 * `Utility::upload_file()` one screen away, so refusing it here would close nothing and break a gateway logo. The question goes
 * to the owner in the register's own words (P01-SECURITY-WORK-QUEUE, P1-SEC-72 f). When it is decided, PANEL_TYPES is the line
 * it changes. "SVG" here means what fileinfo calls `image/svg+xml`: a file that opens with a blank line or a comment before
 * `<svg`, or carries a long header before it, is typed as text and refused although it is one (measured); and an XHTML page
 * with an `<svg>` inside it is typed as SVG and accepted. Whoever decides the SVG question cannot lean on the sniffed type.
 *
 * WHAT THIS DOES NOT DO.
 *  - IT DOES NOT MAKE THE CONTENT SAFE. A file that begins with an image signature and continues with anything at all -- PHP,
 *    HTML, an archive -- passes rule 2 and is stored, under an image extension and the server's own name (pinned as a known
 *    limit in ThemeImageUploadTypeTest). What a server then does with a `.gif` is not decided by its bytes. Re-encoding the
 *    image on the way in would remove such content, at a price (animation, orientation, memory) that is a product decision.
 *  - It does not make a stored image safe to serve by itself: whether a server executes or renders a file is its
 *    configuration, and the deployed document root is the repository root (.htaccess, P1-SEC-40).
 *  - It does not look at files stored BEFORE this change, and it does not touch the other upload paths
 *    (`Utility::upload_file()` and its callers keep the client's file name: P1-SEC-73).
 */
final class ThemeImageUpload
{
    /** What a storefront customer may store as an avatar: raster types, no document type that can carry script. */
    public const CUSTOMER_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** What the panel's callers may store. SVG stays by owner decision D-29.5 -- see the class comment. */
    public const PANEL_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    /**
     * Names a JPEG is commonly saved under (`.jfif` is what Chrome and Edge on Windows call one). Accepted on the CLIENT'S
     * NAME only: nothing is ever stored under them, because the stored extension is `jpg`, from the bytes.
     */
    public const JPEG_NAME_ALIASES = ['jpe', 'jfif'];

    /**
     * A customer avatar's ceiling, in kilobytes: `Utility::upload_file()`'s own fallback when no limit is configured. It is a
     * constant because each avatar is charged to the MERCHANT's plan storage by someone the merchant has never met.
     */
    public const CUSTOMER_MAX_KB = 2048;

    /**
     * The panel's ceiling, in kilobytes: the platform's shipped default for an upload (`local_storage_max_upload_size` in
     * Utility::Setting()). It is about 1.95 GB, so IN EFFECT THIS RULE ADDS NO SIZE LIMIT FOR THE PANEL and PHP's own upload limit
     * remains the real one -- which is what applied before, and why no gateway image is newly refused for its size.
     *
     * It was first read from the caller's store settings and described as "the platform's configured upload size". It was not:
     * for a merchant that read returns their OWN store's row or this same default, never the platform's row (measured by the
     * review). A figure a tenant can write has no business inside this rule, so it is a constant.
     */
    public const PANEL_MAX_KB = 2048000;

    /**
     * The only extensions a log line may name. An extension is text the client chose: `scan.JaneRoe1985` has the "extension"
     * `JaneRoe1985`. So the log carries one of these words, `none`, or `other` -- never the client's own characters.
     */
    private const LOGGED_EXTENSIONS = [
        'jpg', 'jpeg', 'jpe', 'jfif', 'png', 'gif', 'webp', 'svg', 'svgz', 'bmp', 'ico', 'tif', 'tiff', 'avif', 'heic', 'heif',
        'pdf', 'txt', 'csv', 'zip', 'json', 'xml', 'css',
        'html', 'htm', 'xhtml', 'shtml', 'js', 'mjs',
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'htaccess',
        'exe', 'sh', 'bat', 'cmd', 'py', 'pl', 'cgi', 'asp', 'aspx', 'jsp',
    ];

    /** @return list<string> the types that may be stored; the stored extension is always one of these */
    public static function types(bool $forCustomer): array
    {
        return $forCustomer ? self::CUSTOMER_TYPES : self::PANEL_TYPES;
    }

    /** @return list<string> the extensions the CLIENT'S file name may end in */
    public static function clientNameExtensions(bool $forCustomer): array
    {
        return array_merge(self::types($forCustomer), self::JPEG_NAME_ALIASES);
    }

    public static function maxKb(bool $forCustomer): int
    {
        return $forCustomer ? self::CUSTOMER_MAX_KB : self::PANEL_MAX_KB;
    }

    /**
     * The `accept` attribute for a file input that posts to this helper, derived from the list the server enforces so that
     * the picker and the rule cannot disagree (the gateway-image control said `image/*`, which offers BMP, ICO, AVIF, TIFF and
     * HEIC -- all refused).
     */
    public static function acceptAttribute(bool $forCustomer): string
    {
        return implode(',', array_map(static fn (string $extension) => '.' . $extension, self::clientNameExtensions($forCustomer)));
    }

    /**
     * @param  mixed  $file  whatever the request carried under the image's name
     * @return string|null null when the value may be stored; otherwise the sentence to show the caller
     */
    public static function refusal($file, bool $forCustomer): ?string
    {
        $attribute = __('image file');

        // Not an upload at all. Said here, and not left to the rules below: `extensions:` calls a method only an upload has.
        if (! $file instanceof UploadedFile) {
            return (string) str_replace(':attribute', $attribute, trans('validation.file'));
        }

        $types = implode(',', self::types($forCustomer));

        $validator = Validator::make(
            ['image' => $file],
            ['image' => ['mimes:' . $types, 'extensions:' . implode(',', self::clientNameExtensions($forCustomer)), 'max:' . self::maxKb($forCustomer)]],
            // The project's validation files carry no line for `extensions`, so the framework's own ENGLISH default would be
            // shown -- to an Arabic reader too. A sentence of our own exists in both languages, and it says what is wrong: the
            // file may well BE an image of an accepted type.
            ['image.extensions' => __('The file name must end in one of: :values.')],
            ['image' => $attribute]
        );

        if ($validator->fails()) {
            return (string) $validator->errors()->first('image');
        }

        if (self::storedExtension($file, $forCustomer) === null) {
            return (string) str_replace([':attribute', ':values'], [$attribute, str_replace(',', ', ', $types)], trans('validation.mimes'));
        }

        return null;
    }

    /**
     * What a refused value was, for the log: the extension the client named (as a word from a fixed list, never the client's own
     * characters), the type its bytes are, its size. NOT the file's name -- it is a stranger's text, and may be a person's --
     * and never the content.
     *
     * @param  mixed  $file
     * @return array<string, mixed>
     */
    public static function describe($file): array
    {
        if (! $file instanceof UploadedFile) {
            return ['sent' => get_debug_type($file)];
        }

        // An upload PHP itself refused (over upload_max_filesize, cut short) has no readable file behind it.
        $valid = $file->isValid();
        $named = strtolower($file->getClientOriginalExtension());

        return [
            'sent' => 'file',
            'client_extension' => $named === '' ? 'none' : (in_array($named, self::LOGGED_EXTENSIONS, true) ? $named : 'other'),
            'sniffed_type' => $valid ? (string) $file->getMimeType() : null,
            'kilobytes' => $valid ? (int) ceil($file->getSize() / 1024) : null,
        ];
    }

    /**
     * The extension the file is stored under: decided from its BYTES and drawn from the list, or null -- in which case nothing
     * is written. `jpeg` and `jpg` are one type and are stored as `jpg`.
     *
     * @param  mixed  $file
     */
    public static function storedExtension($file, bool $forCustomer): ?string
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return null;
        }

        $guessed = strtolower((string) $file->guessExtension());

        if ($guessed === 'jpeg' || $guessed === 'jpe') {
            $guessed = 'jpg';
        }

        return $guessed !== '' && in_array($guessed, self::types($forCustomer), true) ? $guessed : null;
    }
}
