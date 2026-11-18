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
 * 'required'` and nothing else, and its response returns the stored path. EXECUTED on the unchanged code
 * (ThemeImageUploadTypeTest: 47 of its 50 cases failing): `shell.php`, `shell.phtml`, `shell.phar`, `page.html`, `code.js`,
 * `.htaccess` and `logo.svg` were each stored, under those extensions, by a visitor who had only registered. The other
 * first-party call sites stored `shell.php` for a merchant (executed the same day; P01-SECURITY-WORK-QUEUE, P1-SEC-72 b).
 *
 * WHAT THIS ENFORCES, for every caller, in one place -- the callers are seven and two of them are vendored and cannot be edited:
 *
 *  1. AN UPLOADED FILE, AND A VALID ONE. A string or an array in the file's place used to reach `->getSize()` and answer 500.
 *  2. THE BYTES ARE AN IMAGE OF A LISTED TYPE (`mimes:`, which resolves the type from the file's content -- never `mimetypes:`,
 *     which compares the string the client sent).
 *  3. THE CLIENT'S NAME CARRIES A LISTED EXTENSION TOO (`extensions:`). Owner decision D-29.5 pairs the two for the reason
 *     config/uploads.php records at length: `mimes:` alone let image bytes named `clip.html` through.
 *  4. A SIZE CEILING. See maxKb().
 *  5. THE EXTENSION WRITTEN TO DISK IS THE SERVER'S. It is derived from the sniffed type and must itself be in the list, or
 *     nothing is written. Rules 2 and 3 already imply it; it is held separately because the stored NAME is what a web server acts
 *     on, and a later loosening of the validation must not be able to put a client's extension back on disk.
 *
 * TWO LISTS, AND WHY SVG IS IN ONE OF THEM. An SVG is a document that can carry script, and it is served from the application's
 * own origin. A storefront customer -- anyone who registers -- gets the raster types only. The panel callers keep SVG, and that is
 * the owner's standing position rather than an oversight here: D-29.5 keeps SVG in the platform-wide list for merchant uploads
 * ("would break any merchant using an SVG logo today") until the SVG question is decided, merchants do upload SVG logos (17 of
 * the files tracked under uploads/ are SVG), and a merchant can store one through `Utility::upload_file()` one screen away, so
 * refusing it here would close nothing and break a gateway logo. When that decision is taken, PANEL_TYPES is the line it changes.
 *
 * WHAT THIS DOES NOT DO. It does not make a stored image safe to serve by itself: whether a server executes or renders a file is
 * its configuration, and the deployed document root is the repository root (.htaccess, P1-SEC-40). It does not look at files
 * stored BEFORE this change, and it does not touch the other upload paths (`Utility::upload_file()` and its callers keep the
 * client's extension: discovery SEC-15, advisory review 2026-11-05 section 3.9).
 */
final class ThemeImageUpload
{
    /** What a storefront customer may store as an avatar: raster images, nothing that can carry script. */
    public const CUSTOMER_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** What the panel's callers may store. SVG stays by owner decision D-29.5 -- see the class comment. */
    public const PANEL_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    /**
     * A customer avatar's ceiling, in kilobytes: `Utility::upload_file()`'s own fallback when no limit is configured. It is a
     * constant because each avatar is charged to the MERCHANT's plan storage by someone the merchant has never met.
     */
    public const CUSTOMER_MAX_KB = 2048;

    /** @return list<string> */
    public static function types(bool $forCustomer): array
    {
        return $forCustomer ? self::CUSTOMER_TYPES : self::PANEL_TYPES;
    }

    /**
     * The panel's ceiling is the platform's configured upload size -- the figure the product editor already shows a merchant --
     * so no gateway image that is accepted elsewhere on the panel is newly refused here for its size. If that setting cannot be
     * read, the customer's constant applies: a missing ceiling is not an absent one.
     */
    public static function maxKb(bool $forCustomer): int
    {
        if ($forCustomer) {
            return self::CUSTOMER_MAX_KB;
        }

        try {
            $configured = (int) (UploadLimits::forStore()['setting_max_kb'] ?? 0);
        } catch (\Throwable $e) {
            $configured = 0;
        }

        return $configured > 0 ? $configured : self::CUSTOMER_MAX_KB;
    }

    /**
     * @param  mixed  $file  whatever the request carried under the image's name
     * @return string|null null when the value may be stored; otherwise the sentence to show the caller
     */
    public static function refusal($file, bool $forCustomer): ?string
    {
        $list = implode(',', self::types($forCustomer));

        $validator = Validator::make(
            ['image' => $file],
            ['image' => ['required', 'file', 'mimes:' . $list, 'extensions:' . $list, 'max:' . self::maxKb($forCustomer)]],
            // The project's validation files carry no line for `extensions`, so a refused NAME would print the bare key
            // `validation.extensions`. It is the same sentence as a refused type, in both languages.
            ['image.extensions' => trans('validation.mimes')],
            ['image' => __('Image')]
        );

        if ($validator->fails()) {
            return (string) $validator->errors()->first('image');
        }

        if (self::storedExtension($file, $forCustomer) === null) {
            return (string) str_replace([':attribute', ':values'], [__('Image'), str_replace(',', ', ', $list)], trans('validation.mimes'));
        }

        return null;
    }

    /**
     * What a refused value was, for the log: the extension the client named, the type its bytes are, its size. NOT the client's
     * file name (it is a stranger's text, and may be a person's name) and never the content. The extension is the one piece of
     * client text kept, cut to what an extension can be, because "somebody sent `.php`" is the line worth reading.
     *
     * @param  mixed  $file
     * @return array<string, mixed>
     */
    public static function describe($file): array
    {
        if (! $file instanceof UploadedFile) {
            return ['sent' => get_debug_type($file)];
        }

        $valid = $file->isValid();

        return [
            'sent' => 'file',
            'client_extension' => substr((string) preg_replace('/[^A-Za-z0-9]/', '', $file->getClientOriginalExtension()), 0, 12),
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
