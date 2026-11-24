<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * What the platform's uploader functions will store, and under which name.  P1-SEC-73.
 *
 * WHAT WENT WRONG. `Utility::upload_file()`, `keyWiseUpload_file()`, `jsonUpload_file()` and helper.php's `multi_upload_file()` --
 * 128 calls in the code (72 first-party, 56 in vendored modules; `php tests/Support/Security/bin/census-platform-uploader-callers.php`)
 * -- asked ONE rule of a file, `mimes:<the configured list>`, and stored it under the name the CALLER built, which for almost every
 * caller ends with `getClientOriginalName()`. `mimes:` reads the file's SIGNATURE and never its name; the framework refuses only
 * eight client extensions by name (php, php3, php4, php5, php7, php8, phtml, phar). So PNG bytes named `photo.html`, `.pht`,
 * `.shtml`, `.js`, `.svg` or `shell.php.png` were stored under exactly that name, and the name is what a web server acts on
 * (EXECUTED on all five forms: PlatformUploaderTypeTest). Three more defects rode along: the SIXTH argument of `upload_file()` switched
 * the validator off altogether (eight first-party calls pass it: seven in AppSettingController, behind `auth` and no permission,
 * and the receipt at a GUEST checkout); an EMPTY configured list parsed to `mimes:[null]`, which the rule compares loosely and which
 * passed PHP source; and a failed write was reported as success.
 *
 * WHAT THIS ENFORCES, once, for every caller -- the callers are too many to edit and 56 of them are vendored:
 *
 *  1. THE NAME THE CALLER BUILT IS JUDGED. It must be a name a disk can hold safely (no control or format character, no slash); its
 *     last extension is not one a server runs or reads as configuration (SCRIPT_CAPABLE); and no extension INSIDE it is one a web
 *     server maps to a handler (INNER_HANDLED: `shell.php.png` is run by a server that applies AddHandler to ANY extension in the
 *     name). A name that fails is REFUSED, not rewritten: every caller persists what the function returns, but a rename is still a
 *     change for every one of them, and a legitimate upload never has such a name. The inner check is deliberately the SHORT list:
 *     `Amazon.com.png`, `node.js.png`, `Acme.Inc.png` and `faktura.pl.pdf` are ordinary names, and no server runs them.
 *  2. THE FILE IS A VALID UPLOAD AND PASSES THE RULE: `mimes:` (the signature), `extensions:` (the CLIENT'S name, drawn from the same
 *     list) and `max:`. The sixth argument no longer skips it, and a caller's own rule array is never left without a `max:`.
 *  3. THE LIST CANNOT BE MADE TO CARRY WHAT A SERVER OR A BROWSER ACTS ON. SCRIPT_CAPABLE is removed from whatever the configuration
 *     says (a merchant can write `local_storage_validation`), every entry must be a plain word (the framework re-reads the list with
 *     its own CSV parser, and a quoted or empty entry is what re-opened `mimes:[null]`), and an empty result refuses everything. The
 *     stored name's last extension must then be in the list: a PNG saved as `photo` or `photo.` is refused, as is a JPEG named
 *     `photo.bmp`. (`jpe` and `jfif`, two names a JPEG is commonly saved under, travel with `jpg` and `jpeg`.)
 *  4. A REQUEST THAT IS NOT A PANEL REQUEST GETS A NARROWER LIST. A shopper's door -- tickets, refund evidence, a guest checkout's
 *     receipt and attachments -- is a storefront route, and two of its callers are vendored (CheckoutAttachment reaches the uploader
 *     from an anonymous checkout) and cannot be told which list to use. An SVG is a document that can carry script on the
 *     application's own origin, so a shopper's upload may not be one. "Panel" is decided by THE ROUTE, not by who happens to be
 *     signed in: a request with a `web` login that reaches a route with no `auth` middleware is still a shopper's door (self-
 *     registration is open, so every visitor can hold a `web` login -- the first form of this rule asked only whether one existed
 *     and the review of it opened the ticket and receipt doors to SVG with that single cookie). The panel keeps the platform
 *     list, SVG included -- D-29.5, as in ThemeImageUpload: the same open question, in the same place.
 *
 * WHAT THIS DOES NOT DO.
 *  - It does not decode the file. `mimes:` is a check of the signature, so a file that begins as an image and continues as anything
 *    at all is an image to this rule (the same known limit as ThemeImageUpload). What a server does with a `.png` is not decided by
 *    its bytes, which is why the second lock -- the host refusing to run or render scripts under `uploads/` -- stays the owner's.
 *  - It does not reach the two uploaders the vendored LandingPage module carries (`LandingPageSetting::upload_file()` and
 *    `::keyWiseUpload_file()`: 19 calls), which are not first-party and are not edited here; nor `upload_theme_image()`, which has
 *    its own rule (ThemeImageUpload); nor an upload that never goes through these functions at all -- `DashboardController::
 *    updateStoreOwnerInfo()` was one (fixed beside this, with a server-built name, not through this class), and P1-SEC-74 in
 *    the work queue carries the others.
 *  - It does not change where a file goes or what it is called when it is acceptable -- with ONE exception, for a stranger's request:
 *    nonReplacingName() gives a shopper's file a new name when its own is already taken (P1-SEC-71, below), because a shopper's
 *    upload is the one thing that must never be a replacement.
 *
 *  5. A SHOPPER'S UPLOAD NEVER REPLACES A FILE. (P1-SEC-71; see nonReplacingName().) And a door that lets the platform name the file
 *     gives a shopper's attachment a name no one can choose in advance (storedName()).
 */
final class PlatformUploadPolicy
{
    /**
     * Extensions nobody may store through the platform uploader as the LAST extension of a name, whatever the configuration says: what
     * a web server runs, what it includes, what it or PHP reads as configuration, and what a browser opened on the file's own
     * address renders as a document. (`user.ini` is not a word of this list: it is `.user.ini`, whose last extension is `ini`.)
     */
    public const SCRIPT_CAPABLE = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'pgif', 'phtm', 'inc',
        'htm', 'html', 'xhtml', 'shtml', 'xht', 'js', 'mjs', 'htaccess', 'htpasswd', 'ini', 'conf', 'config',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'ashx', 'jsp', 'jspx', 'cfm',
        'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'jar', 'hta', 'vbs', 'ps1', 'swf',
    ];

    /**
     * What a web server maps to a HANDLER when it appears INSIDE a name (mod_mime applies AddHandler to every extension in the name,
     * so `shell.php.png` runs). `php`, `php1`, `php5`, `php10`... are matched by pattern. Deliberately not the whole of SCRIPT_CAPABLE:
     * a domain (`.com`), a language (`.pl`), a library (`.js`) or a company (`.Inc`) is a word in an ordinary file name, and a server
     * decides a file's TYPE by its last extension.
     */
    public const INNER_HANDLED = [
        'phtml', 'pht', 'phps', 'phar', 'pgif', 'phtm', 'shtml', 'shtm', 'stm', 'cgi', 'fcgi', 'asp', 'aspx', 'ashx', 'asmx', 'jsp', 'jspx',
    ];

    /** Removed as well from a request that is not a panel request: documents that carry script on the application's own origin. */
    public const SHOPPER_DENIED = ['svg', 'svgz', 'xml', 'xsl', 'xslt'];

    /**
     * The types of a door whose field IS an image by its nature (the guest checkout's receipt, a theme's photo fields): the raster
     * formats a browser shows. A caller that names its own list says so with imageRules(), and the platform list does not narrow it.
     */
    public const IMAGE_TYPES = ['jpg', 'jpeg', 'jpe', 'jfif', 'png', 'gif', 'webp'];

    /** Names a JPEG is commonly saved under, accepted wherever `jpg` or `jpeg` is (ThemeImageUpload::JPEG_NAME_ALIASES says the same). */
    private const JPEG_ALIASES = ['jpe', 'jfif'];

    /**
     * @param  mixed  $file  whatever the caller holds under the file's key
     * @param  string  $name  the stored name the caller built, extension included
     * @param  string  $configured  the configured list, as the settings store it: `jpg,jpeg,png,csv,svg,pdf`
     * @param  array<int, mixed>  $customRules  a caller's own rule array, which REPLACES the configured list (UploadRules::for(), imageRules())
     * @return string|null null when the file may be stored under that name; otherwise the sentence to answer with
     */
    public static function refusal($file, string $name, string $configured, $maxKb, array $customRules = []): ?string
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return self::refused('not_a_file', $file, (string) str_replace(':attribute', __('file'), trans('validation.file')));
        }

        $panel = self::panelSession();
        $custom = $customRules !== [];

        // THE LIST this upload is held to: the configured one, or what the caller's own rules name (`extensions:` first -- it is the
        // rule about the NAME -- else `mimes:`), each cleaned as the configured list is. null: the caller's rules name none.
        $allowed = null;
        $rules = [];

        if ($custom) {
            $fromExtensions = $fromMimes = null;

            foreach (array_values($customRules) as $rule) {
                if (is_string($rule) && str_starts_with($rule, 'extensions:')) {
                    $fromExtensions = self::clean(explode(',', substr($rule, strlen('extensions:'))), $panel, false);
                    $rule = 'extensions:' . implode(',', $fromExtensions);
                } elseif (is_string($rule) && str_starts_with($rule, 'mimes:')) {
                    $fromMimes = self::clean(explode(',', substr($rule, strlen('mimes:'))), $panel, false);
                    $rule = 'mimes:' . implode(',', $fromMimes);
                }

                $rules[] = $rule;
            }

            $allowed = $fromExtensions ?? $fromMimes;

            if ($allowed === []) {
                return self::refused('no_types', $file, __('No file type is enabled for uploads.'));
            }

            if (! self::carries($rules, 'max:')) {
                $rules[] = 'max:' . $maxKb;     // a caller's own list never removes the size ceiling (UploadRules refuses one that would)
            }
        } else {
            $allowed = self::allowed($configured, $panel);

            if ($allowed === []) {
                return self::refused('no_types', $file, __('No file type is enabled for uploads.'));
            }

            $list = implode(',', $allowed);
            $rules = ['mimes:' . $list, 'extensions:' . $list, 'max:' . $maxKb];
        }

        // 1. THE NAME, structurally: a name a disk can hold. (Flysystem refuses a control or format character by itself, with its own
        //    exception text; this says so in the product's words.)
        // (`!== 0`, not `=== 1`: with /u the engine answers FALSE for a subject that is not valid UTF-8, and one bad byte then carried a right-to-left
        //  mark or a C1 control past this check AND past Flysystem's, which uses the same pattern. A name that is not UTF-8 is refused.)
        if ($name === '' || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name) === 1 || preg_match('/\p{C}/u', $name) !== 0) {
            return self::refused('name', $file, __('The file name is not accepted.'));
        }

        // a name with no dot has no extension at all: it is not "its own extension" (`png` is a file called png)
        $segments = explode('.', $name);
        $final = count($segments) >= 2 ? strtolower((string) end($segments)) : '';

        // 2. ... whose last extension is not one a server acts on
        if (self::isScriptWord($final)) {
            return self::refused('name', $file, __('The file name is not accepted.'));
        }

        // 3. ... and with no extension INSIDE it that a server maps to a handler
        foreach (array_slice($segments, 1, -1) as $inner) {
            if (self::isHandlerWord(strtolower($inner))) {
                return self::refused('inner_extension', $file, __('The file name is not accepted.'));
            }
        }

        // 4. THE FILE: the signature, the client's extension, the size -- in the framework's own sentences, which name the types
        $validator = Validator::make(['file' => $file], ['file' => $rules], [], ['file' => __('file')]);

        if ($validator->fails()) {
            return self::refused('rule', $file, (string) $validator->errors()->first('file'));
        }

        // 5. THE STORED NAME'S OWN last extension must be one of the list: the client's extension was checked above, but the caller
        //    may have built a different name (a fixed name over a client upload, a name with no extension at all)
        if ($allowed !== null && ! in_array($final, $allowed, true)) {
            return self::refused('extension', $file, __('The file name must end in one of: :values.', ['values' => implode(', ', $allowed)]));
        }

        // 6. THE CLIENT'S OWN extension is not script-capable either (the framework refuses eight of them by name and no more) -- the rules
        //    above hold it to a list only when the caller named one -- and, for a request that is not a panel request, not an active
        //    document. When the caller's rules name no list at all, the stored name's last extension is held to the same.
        foreach ($allowed === null ? [strtolower($file->getClientOriginalExtension()), $final] : [strtolower($file->getClientOriginalExtension())] as $word) {
            if (self::isScriptWord($word) || (! $panel && in_array($word, self::SHOPPER_DENIED, true))) {
                return self::refused('client_extension', $file, __('The file name is not accepted.'));
            }
        }

        return null;
    }

    /**
     * The rule array of a door whose field is an image by its nature: `IMAGE_TYPES` plus whatever the door adds (`bmp`, a receipt;
     * `svg`, a panel's theme photo -- which a request with no panel session is not given whatever the door says). Pass it as the
     * uploader's `$custom_validation`; the size ceiling is added there.
     *
     * @param  list<string>  $more
     * @return list<string>
     */
    public static function imageRules(array $more = []): array
    {
        $list = implode(',', array_merge(self::IMAGE_TYPES, $more));

        return ['mimes:' . $list, 'extensions:' . $list];
    }

    /**
     * The stored name of a payment receipt or slip: the client's name reduced to letters, digits, `_` and `-` (so no dot of the client's
     * survives into the middle of it -- `shell.php.png` is stored as `shell_php_...png`), the time, EIGHT RANDOM HEX DIGITS (the folder is
     * shared by every store: two shoppers in one second, or a stranger who knows a victim's name, can no longer land on one file), and the
     * client's extension, lower-cased, LAST. The old name was `<client name>_<time>`: `icon.png_1783941141`.
     */
    public static function receiptName(UploadedFile $file): string
    {
        return self::storedName($file, 'receipt');
    }

    /**
     * The stored name of ANY file a stranger sends to a door that lets the platform name it (P1-SEC-71): a ticket's attachment, a reply's,
     * a refund's evidence. The shape is receiptName()'s -- the client's name reduced to letters, digits, `_` and `-`, the time, eight random
     * hex digits, the client's extension lower-cased and LAST -- and for the same reasons; the base is cut at sixty characters, which is NEW
     * for the guest receipt and the plan slip (receiptName() delegates here, so a receipt named over sixty characters is now stored shorter).
     * The ticket doors stored the client's own name, VERBATIM, in a folder every ticket of the store shares (and, on the edit and reply
     * paths, in a folder every STORE shares), so two shoppers who both attached `proof.png` were one file: the second replaced the first
     * under the first one's database row (EXECUTED, two stores' replies). A name that carries eight random hex digits cannot be named in
     * advance.
     *
     * @param  string  $fallback  the base used when nothing of the client's name survives the reduction (a name of `ملف.png`)
     */
    public static function storedName(UploadedFile $file, string $fallback = 'file'): string
    {
        // rtrim AFTER the cut: sixty characters can end on an underscore, and `__` before the time reads as a mistake
        $base = rtrim(substr(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), '_'), 0, 60), '_');

        return ($base !== '' ? $base : $fallback) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($file->getClientOriginalExtension());
    }

    /**
     * A SHOPPER'S UPLOAD NEVER REPLACES A FILE THAT IS ALREADY THERE (P1-SEC-71).
     *
     * The vendored CheckoutAttachment module (`CheckoutAttachmentStore()`, called from every payment path of PaymentService) hands the
     * uploader the client's own file name and the folder `uploads/<store id>` -- which `keyWiseUpload_file()` writes under `uploads/`
     * again, the folder the merchant's product-gallery images are written to under names that are printed in the storefront's own
     * HTML. A visitor at a checkout needs no account: naming an upload after a gallery image replaced it (EXECUTED at function level,
     * with the arguments the module passes). The module is vendored and is not edited, so the uploader says the one thing a shopper's
     * file is never allowed to be: a replacement. When the name is taken the file is stored under that name with the time and sixteen
     * random hex digits before its extension -- the extension is unchanged, so the policy's judgement of it still holds -- and the
     * caller, which persists what the function returns, is none the wiser.
     *
     * A PANEL request is not touched (a merchant replacing their own image under its own name is the product), and neither is a call
     * with no router at all (a console command, a queue job): `shopperRequest()` says why. What this does not close: two shoppers
     * writing the same NEW name at the same instant (neither sees the other's file, and one replaces the other -- both are shoppers'
     * files), a name the shopper guesses before the merchant creates it (nothing is there to replace when they write), and the
     * 2^-64 chance that the random part of the new name is already taken (it is not looked up again).
     *
     * @param  string  $disk  the filesystem disk the caller is about to write to
     * @param  string  $directory  the folder on it, as the caller's `putFileAs()` is given it
     */
    public static function nonReplacingName(string $disk, string $directory, string $name): string
    {
        if (! self::shopperRequest()) {
            return $name;
        }

        $storage = Storage::disk($disk);
        $folder = rtrim($directory, '/');

        try {
            $taken = $storage->exists($folder . '/' . $name);
        } catch (\Throwable $e) {
            // A disk that cannot ANSWER is treated as TAKEN: an s3 or wasabi credential that may write but not read or list makes `exists()` (a
            // HEAD, then a LIST) throw on a 403, and every shopper's upload would then be refused while a merchant's -- who never reaches this --
            // still worked. Renaming can neither replace a file nor refuse an upload, which is the whole of what this is for.
            $taken = true;
        }

        if (! $taken) {
            return $name;
        }

        // Written for whoever asks, afterwards, whether anybody probed for a name (a visitor naming uploads after gallery images renames nothing
        // visible to them): that a stranger's upload was stored under a new name, on which disk and in which folder -- never the file's name.
        // `info`, not `warning`: two shoppers who both attach `IMG_0001.jpg` land here too.
        Log::info('a stranger\'s upload was stored under a new name because its name was taken', ['disk' => $disk, 'folder' => $folder]);

        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        // sixteen random hex digits (64 bits): the name is a stranger's collision escape, so it is long rather than short
        return $base . '_' . time() . '_' . bin2hex(random_bytes(8)) . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * Written for whoever asks, afterwards, whether anybody tried: WHY, whether the request had a panel session, and the client's
     * extension as a word from a fixed list. Never the file's name -- a stranger's text, and perhaps a person's name -- and never its
     * content (the same rule as the `an image upload was refused` line in upload_theme_image()).
     */
    private static function refused(string $reason, $file, string $sentence): string
    {
        Log::warning('a platform upload was refused', [
            'reason' => $reason,
            'sent' => $file instanceof UploadedFile ? 'file' : get_debug_type($file),
            'client_extension' => $file instanceof UploadedFile ? ThemeImageUpload::extensionWord($file->getClientOriginalExtension()) : null,
            'shopper' => ! self::panelSession(),
        ]);

        return $sentence;
    }

    /**
     * @return list<string> the configured extensions, lower-cased, plain words only, minus what no one may store and (with no panel
     *         session) what a shopper may not; `jpg` and `jpeg` travel together, as they do in the framework's own `mimes` rule,
     *         and so do `jpe` and `jfif`
     */
    public static function allowed(string $configured, bool $panelSession): array
    {
        return self::clean(explode(',', $configured), $panelSession, true);
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return list<string>
     */
    private static function clean(array $entries, bool $panelSession, bool $jpegAliases): array
    {
        $list = [];

        foreach ($entries as $entry) {
            $word = strtolower(ltrim(trim((string) $entry), '.'));

            // a plain word only: the framework re-reads the list with its own CSV parser (str_getcsv), so a quote, a wildcard or a dot
            // in an entry is read differently there than here -- an empty entry made `mimes:` compare `null` loosely and pass PHP source
            if (preg_match('/^[a-z0-9]{1,12}$/', $word) === 1) {
                $list[] = $word;
            }
        }

        if (in_array('jpg', $list, true) || in_array('jpeg', $list, true)) {
            $list = array_merge($list, ['jpg', 'jpeg'], $jpegAliases ? self::JPEG_ALIASES : []);
        }

        return array_values(array_unique(array_filter(
            $list,
            static fn (string $word) => ! self::isScriptWord($word) && ($panelSession || ! in_array($word, self::SHOPPER_DENIED, true))
        )));
    }

    private static function isScriptWord(string $word): bool
    {
        return in_array($word, self::SCRIPT_CAPABLE, true) || preg_match('/^php\d*$/', $word) === 1;
    }

    private static function isHandlerWord(string $word): bool
    {
        return in_array($word, self::INNER_HANDLED, true) || preg_match('/^php\d*$/', $word) === 1;
    }

    /** @param  array<int, mixed>  $rules */
    private static function carries(array $rules, string $prefix): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this a PANEL request? Not "is a panel user signed in": a request that reaches a PUBLIC route (the storefront's) is a
     * shopper's whatever cookies it carries, and any visitor can hold a `web` login because registration is open. So: a `web` user, and a
     * route that demands one (`auth`, `auth:web`, or `custom.auth` -- where a merchant's Sanctum token lands in the `web` guard and a
     * customer's or driver's does not). With no router at all (a console command, a direct call), a `web` user is all there is to go on.
     */
    public static function panelSession(): bool
    {
        try {
            if (! auth('web')->check()) {
                return false;
            }

            $route = request()->route();

            return $route instanceof Route ? self::routeDemandsAPanelUser($route) : true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Is this a request a STRANGER can make: it reached a route (so it is a request, not a console command or a queue job that happens
     * to hold an authenticated user or none) and is not a panel request? A call with no router at all is somebody's own code
     * regenerating a file under a name it chose, which is the legitimate case of "replace"; it is not a door.
     */
    private static function shopperRequest(): bool
    {
        return request()->route() instanceof Route && ! self::panelSession();
    }

    private static function routeDemandsAPanelUser(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            [$name, $guards] = array_pad(explode(':', $middleware, 2), 2, '');

            if ($name === 'custom.auth' || (($name === 'auth' || $name === \Illuminate\Auth\Middleware\Authenticate::class) && ($guards === '' || $guards === 'web'))) {
                return true;
            }
        }

        // A vendored route that declares only `web` but is a merchant screen or writer: ModuleAdministrativeRouteGuard makes it require a signed-in `web`
        // user at runtime (App\Support\ModuleRouteManifest), so it IS a panel route. ProductBadgeManagement's `POST Imagebadge-store` declares
        // `['web']` alone and a merchant's badge image was held to the shopper's list (no SVG) and to the shopper's no-replace rule.
        return \App\Support\ModuleRouteManifest::administrativeRule(request()->method(), $route->uri()) !== null;
    }
}
