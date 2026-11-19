<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * What the platform's uploader functions will store, and under which name.  P1-SEC-73.
 *
 * WHAT WENT WRONG. `Utility::upload_file()`, `keyWiseUpload_file()`, `jsonUpload_file()` and helper.php's `multi_upload_file()` --
 * the upload of about 180 call sites, two dozen of them vendored -- asked ONE rule of a file, `mimes:<the configured list>`, and
 * stored it under the name the CALLER built, which for almost every caller ends with `getClientOriginalName()`. `mimes:` reads the
 * file's SIGNATURE and never its name; the framework refuses only eight client extensions by name (php, php3, php4, php5, php7, php8,
 * phtml, phar). So PNG bytes named `photo.html`, `.pht`, `.shtml`, `.js`, `.svg` or `shell.php.png` were stored under exactly that
 * name, and the name is what a web server acts on (EXECUTED on all five functions: ThemeImageUploadTypeTest's sibling,
 * PlatformUploaderTypeTest). Three more defects rode along: the SIXTH argument of `upload_file()` switched the validator off
 * altogether (eight first-party calls pass it: seven in AppSettingController, behind `auth` and no permission, and the receipt at a
 * GUEST checkout); an EMPTY configured list parsed to `mimes:[null]`, which the rule compares loosely and which passed PHP source;
 * and a failed write was reported as success.
 *
 * WHAT THIS ENFORCES, once, for every caller -- the callers are too many to edit and some are vendored:
 *
 *  1. THE FILE IS A VALID UPLOAD AND PASSES THE CONFIGURED RULE: `mimes:` (the signature), `extensions:` (the CLIENT'S name, drawn
 *     from the same list) and `max:`. The sixth argument no longer skips it.
 *  2. THE LIST CANNOT BE MADE TO CARRY WHAT A SERVER OR A BROWSER ACTS ON. SCRIPT_CAPABLE is removed from whatever the configuration
 *     says (a merchant can write `local_storage_validation`), and an empty result refuses everything.
 *  3. A REQUEST WITH NO PANEL SESSION IS A SHOPPER'S, AND GETS A NARROWER LIST. Tickets, refund evidence, a guest checkout's receipt
 *     and attachments, a delivery driver's profile picture: none has a `web` user, two of the callers are vendored (CheckoutAttachment
 *     reaches the uploader from an anonymous checkout) and cannot be told which list to use. An SVG is a document that can carry
 *     script on the application's own origin, so a shopper's upload may not be one. The panel keeps the platform list, SVG included --
 *     D-29.5, as in ThemeImageUpload: the same open question, in the same place.
 *  4. THE NAME THE CALLER BUILT IS JUDGED TOO: it must end in an extension from the list, and no segment before it may be a script
 *     extension (`shell.php.png` is run by a server that maps handlers by ANY extension in the name). A name that fails is REFUSED,
 *     not rewritten: every caller persists what the function returns, but a rename is still a change for every one of them, and a
 *     legitimate upload never has such a name.
 *
 * WHAT THIS DOES NOT DO.
 *  - It does not decode the file. `mimes:` is a check of the signature, so a file that begins as an image and continues as anything
 *    at all is an image to this rule (the same known limit as ThemeImageUpload). What a server does with a `.png` is not decided by
 *    its bytes, which is why the second lock -- the host refusing to run or render scripts under `uploads/` -- stays the owner's.
 *  - It does not reach the two uploaders the vendored LandingPage module carries (`LandingPageSetting::upload_file()` and
 *    `::keyWiseUpload_file()`), which are not first-party and are not edited here.
 *  - It does not change where a file goes or what it is called when it is acceptable.
 */
final class PlatformUploadPolicy
{
    /**
     * Extensions nobody may store through the platform uploader, whatever the configuration says: what a web server runs, what it
     * includes, what it reads as configuration, and what a browser opened on the file's own address renders as a document.
     */
    public const SCRIPT_CAPABLE = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'pgif', 'phtm', 'inc',
        'htm', 'html', 'xhtml', 'shtml', 'xht', 'js', 'mjs', 'htaccess', 'htpasswd', 'user.ini',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'ashx', 'jsp', 'jspx', 'cfm',
        'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'jar', 'hta', 'vbs', 'ps1', 'swf',
    ];

    /** Removed as well from a request with no panel session: documents that carry script on the application's own origin. */
    public const SHOPPER_DENIED = ['svg', 'svgz', 'xml', 'xsl', 'xslt'];

    /**
     * @param  mixed  $file  whatever the caller holds under the file's key
     * @param  string  $name  the stored name the caller built, extension included
     * @param  string  $configured  the configured list, as the settings store it: `jpg,jpeg,png,csv,svg,pdf`
     * @param  array<int, mixed>  $customRules  a caller's own rule array, which REPLACES the configured list (UploadRules::for())
     * @return string|null null when the file may be stored under that name; otherwise the sentence to answer with
     */
    public static function refusal($file, string $name, string $configured, $maxKb, array $customRules = []): ?string
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return self::refused('not_a_file', $file, (string) str_replace(':attribute', __('file'), trans('validation.file')));
        }

        $allowed = self::allowed($configured, self::panelSession());

        // A caller's own list narrows the types it asks for; it never widens past what is script-capable, and the name is judged below.
        if ($customRules === [] && $allowed === []) {
            return self::refused('no_types', $file, __('No file type is enabled for uploads.'));
        }

        $segments = explode('.', $name);
        $final = strtolower((string) end($segments));

        if ($name === '' || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name) === 1 || count($segments) < 2 || $final === ''
            || in_array($final, self::SCRIPT_CAPABLE, true) || ($customRules === [] && ! in_array($final, $allowed, true))) {
            return self::refused('name', $file, __('The file name is not accepted.'));
        }

        // every segment between the first and the last: a script extension INSIDE the name
        foreach (array_slice($segments, 1, -1) as $inner) {
            if (in_array(strtolower($inner), self::SCRIPT_CAPABLE, true)) {
                return self::refused('inner_extension', $file, __('The file name is not accepted.'));
            }
        }

        $list = implode(',', $allowed);
        $rules = $customRules !== [] ? array_values($customRules) : ['mimes:' . $list, 'extensions:' . $list, 'max:' . $maxKb];

        $validator = Validator::make(['file' => $file], ['file' => $rules], [], ['file' => __('file')]);

        if ($validator->fails()) {
            return self::refused('rule', $file, (string) $validator->errors()->first('file'));
        }

        // THE CLIENT'S OWN extension, when the caller's rules are its own: it must not be script-capable either (the framework
        // refuses eight of them by name and no more).
        if ($customRules !== [] && in_array(strtolower($file->getClientOriginalExtension()), self::SCRIPT_CAPABLE, true)) {
            return self::refused('client_extension', $file, __('The file name is not accepted.'));
        }

        return null;
    }

    /**
     * Written for whoever asks, afterwards, whether anybody tried: WHY, whether the request had a panel session, and the client's
     * extension as a word from a fixed list. Never the file's name -- a stranger's text, and perhaps a person's name -- and never its
     * content (the same rule as the `an image upload was refused` line in upload_theme_image()).
     */
    private static function refused(string $reason, $file, string $sentence): string
    {
        \Illuminate\Support\Facades\Log::warning('a platform upload was refused', [
            'reason' => $reason,
            'sent' => $file instanceof UploadedFile ? 'file' : get_debug_type($file),
            'client_extension' => $file instanceof UploadedFile ? ThemeImageUpload::extensionWord($file->getClientOriginalExtension()) : null,
            'shopper' => ! self::panelSession(),
        ]);

        return $sentence;
    }

    /**
     * @return list<string> the configured extensions, lower-cased, minus what no one may store and (with no panel session) what a
     *         shopper may not; `jpg` and `jpeg` travel together, as they do in the framework's own `mimes` rule
     */
    public static function allowed(string $configured, bool $panelSession): array
    {
        $list = array_values(array_filter(array_map(
            static fn (string $extension) => strtolower(ltrim(trim($extension), '.')),
            explode(',', $configured)
        ), static fn (string $extension) => $extension !== ''));

        if (in_array('jpg', $list, true) || in_array('jpeg', $list, true)) {
            $list = array_merge($list, ['jpg', 'jpeg']);
        }

        $denied = $panelSession ? self::SCRIPT_CAPABLE : array_merge(self::SCRIPT_CAPABLE, self::SHOPPER_DENIED);

        return array_values(array_unique(array_diff($list, $denied)));
    }

    /**
     * Is a panel user signed in? The `web` guard is where a merchant's session AND a merchant's Sanctum token land (custom.auth),
     * and it is empty for a storefront customer (`customers`), a delivery driver (`deliveryboy`) and a visitor.
     */
    public static function panelSession(): bool
    {
        try {
            return auth('web')->check();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
