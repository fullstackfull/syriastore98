<?php

namespace Tests\Feature\Security;

use App\Models\Utility;
use App\Services\PlatformUploadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1-SEC-73 -- THE PLATFORM UPLOADER KEPT THE CLIENT'S EXTENSION, AND ONE OF ITS FORMS RAN NO VALIDATOR AT ALL.
 * ============================================================================================================
 *
 * `Utility::upload_file()`, `keyWiseUpload_file()`, `jsonUpload_file()` and `multi_upload_file()` (helper.php) are the upload of
 * about 180 call sites -- merchant settings screens, product galleries, a shopper's support-ticket attachment, the receipt at a
 * GUEST checkout, a delivery driver's profile picture. P1-SEC-72 fixed the sibling helper `upload_theme_image()`; the mapping that
 * followed it (192 sites) measured this one:
 *
 *   1. THE RULE ASKED OF THE FILE IS `mimes:<list>` ONLY. That reads the file's signature and never its NAME, and the framework
 *      refuses only eight client extensions by name (php, php3-5, php7, php8, phtml, phar). So PNG bytes named `photo.html`, `.pht`,
 *      `.shtml`, `.js`, `.svg` or `shell.php.png` were stored under exactly that name -- and the name is what a web server acts on.
 *   2. THE SIXTH ARGUMENT SWITCHES THE VALIDATOR OFF. A caller that passes `$image` gets no type, name or size check at all
 *      (`if (empty($image))` builds the validator and `if (empty($image) && $validator->fails())` tests it). Eight first-party calls
 *      do (seven in AppSettingController, behind `auth` alone -- no permission is asked -- and the receipt at guest checkout), and
 *      so do both `jsonUpload_file` callers. PHP source named `shell.php` was stored.
 *   3. AN EMPTY LIST PASSES EVERYTHING: `'mimes:'` parses to `[null]` and the rule's comparison is loose.
 *   4. A FAILED WRITE WAS REPORTED AS SUCCESS: `putFileAs()`'s return was discarded.
 *
 * THIS FILE DRIVES THE FUNCTIONS, because the fix is in them and every one of the ~180 callers inherits it; the route-level cases
 * live in PlatformUploaderDoorsTest, which proves the doors above reach them.
 *
 * EVERY CASE USES REAL BYTES ON DISK (`new UploadedFile(...)` over a temporary file): `UploadedFile::fake()` reports its type from
 * its NAME, which would judge a rule that sniffs the content by the name instead.
 */
class PlatformUploaderTypeTest extends SecurityTestCase
{
    use RefreshDatabase;

    // A real 2x2 PNG (the same fixture ThemeImageUploadTypeTest decodes), a PDF header, a CSV, a script-bearing SVG, PHP, HTML.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWM8ISfHwMDAxMDAwMDAAAANBAEIG3S2BgAAAABJRU5ErkJggg==';
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>document.title="ss-probe"</script></svg>';
    private const PHP = "<?php echo 'ss-probe'; ?>\n";
    private const HTML = "<!doctype html><html><body><script>document.title='ss-probe'</script></body></html>\n";

    /** @var list<string> temporary files to remove */
    private array $temporary = [];

    private \App\Models\User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('theme');
        Storage::fake('local');           // multi_upload_file writes to the disk NAMED by the storage setting, which is `local`

        // The platform is user 1 (`Setting::where('created_by', 1)` is what three of the functions read) and the first merchant
        // is user 2, whose own rows are what `multi_upload_file` reads when that merchant is signed in.
        $this->makePlatformAdmin();
        [$this->merchant] = $this->makeTenant();
        $this->assertSame(1, (int) \App\Models\User::orderBy('id')->value('id'), 'Premise: the platform operator is user 1.');
        $this->platformStorage();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------------------------------------------------------------

    /** An upload as PHP hands it to the framework: real bytes on disk, the client's own name. */
    private function upload(string $clientName, string $bytes): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ssup');
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG);
    }

    /**
     * The storage configuration, written for each of the three readers: `Setting::where('created_by', 1)` (upload_file and
     * jsonUpload_file), `Setting::where('store_id', 1)` (keyWiseUpload_file) and the signed-in merchant's own rows
     * (`getAdminAllSetting()`, which multi_upload_file reads).
     */
    private function platformStorage(?string $validation = 'jpg,jpeg,png,csv,svg,pdf', string $maxKb = '2048000'): void
    {
        foreach ([1, (int) $this->merchant->id] as $createdBy) {
            foreach (['storage_setting' => 'local', 'local_storage_validation' => $validation, 'local_storage_max_upload_size' => $maxKb] as $name => $value) {
                DB::table('settings')->updateOrInsert(
                    ['name' => $name, 'store_id' => 1, 'created_by' => $createdBy],
                    ['value' => (string) $value, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
        (new \App\Models\Setting)->flushCache();
    }

    /** What each of the four first-party uploader functions answers for one file, in the shapes the callers use. */
    private function through(string $form, UploadedFile $file, string $name): array
    {
        $path = 'uploads/1';

        return match ($form) {
            // the four-argument form: the validator runs
            'upload_file' => Utility::upload_file(['file' => $file], 'file', $name, $path),
            // the six-argument form: AppSettingController's seven calls and the guest checkout's receipt -- the validator did NOT run
            'upload_file with the sixth argument' => Utility::upload_file(['file' => $file], 'file', $name, $path, [], $file),
            'keyWiseUpload_file' => Utility::keyWiseUpload_file(Request::create('/x', 'POST', [], [], ['file' => [$file]]), 'file', $name, $path, 0),
            // the form both of its callers use: the file handed in as `$image`
            'jsonUpload_file' => Utility::jsonUpload_file(Request::create('/x', 'POST'), 'file', $file, $name, $path),
            // reads the SIGNED-IN merchant's rows (and the platform's, for a visitor): it is only meaningful with a session
            'multi_upload_file' => $this->actingAsTheMerchant() ?? multi_upload_file($file, 'file', $name, $path),
        };
    }

    /** @return list<array{0: string}> the forms; every case below runs through each */
    public static function theForms(): array
    {
        return [
            'upload_file' => ['upload_file'],
            'upload_file, sixth argument' => ['upload_file with the sixth argument'],
            'keyWiseUpload_file' => ['keyWiseUpload_file'],
            'jsonUpload_file' => ['jsonUpload_file'],
            'multi_upload_file' => ['multi_upload_file'],
        ];
    }

    private function actingAsTheMerchant()
    {
        $this->actingAs($this->merchant);

        return null;
    }

    /** @return list<string> every file either disk holds (the `theme` disk, and `local` for multi_upload_file) */
    private function stored(): array
    {
        return array_merge(Storage::disk('theme')->allFiles(), array_map(fn ($f) => 'local:' . $f, Storage::disk('local')->allFiles()));
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // what must not be stored
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2?: string}> the client's file name, what is in the file, and optionally the
     *         STORED name the caller built (default `x_<client name>`; a caller with a fixed name, or a hostile one, is not the client).
     *         The comment on each says which rule refuses it, because a case that would be refused anyway says nothing about the lock
     *         it seems to be about.
     */
    public static function whatMustNotBeStored(): array
    {
        $png = (string) base64_decode(self::PNG);

        return [
            // the bytes are an image; the NAME is what a server acts on. `mimes:` never looked at it.
            'image bytes under an HTML name' => ['page.html', $png],
            'image bytes under an .htm name' => ['page.htm', $png],
            'image bytes under an XHTML name' => ['page.xhtml', $png],
            'image bytes under a server-side-include name' => ['page.shtml', $png],
            'image bytes under a .pht name' => ['module.pht', $png],
            'image bytes under a .phps name' => ['module.phps', $png],
            'image bytes under a script name' => ['code.js', $png],
            'image bytes under a module-script name' => ['code.mjs', $png],
            'image bytes with no extension at all' => ['photo', $png],
            'image bytes under a name that ends in a dot' => ['photo.png.', $png],
            'image bytes under a per-directory server override name' => ['.htaccess', $png],
            // an INNER extension: a server that maps handlers by any extension in the name (mod_mime's AddHandler) acts on it
            'image bytes with a PHP extension inside the name' => ['shell.php.png', $png],
            'image bytes with an uppercase PHP5 extension inside the name' => ['shell.PHP5.png', $png],
            'image bytes with an HTML extension inside the name' => ['page.html.png', $png],
            // the bytes are not what the name says
            'PHP source under an image name' => ['photo.png', self::PHP],
            'PHP source under its own name' => ['shell.php', self::PHP],
            'PHP source under .phtml' => ['shell.phtml', self::PHP],
            'an HTML page under an image name' => ['photo.png', self::HTML],
            'an HTML page under its own name' => ['page.html', self::HTML],
            'an SVG carrying a script under an HTML name' => ['logo.html', self::SVG],
            'a PDF header under an HTML name' => ['doc.html', self::PDF],
            // the STORED name is the caller's, and it is judged on its own
            'a stored name that is only an extension' => ['logo.png', $png, 'png'],
            'a stored name with a path in it' => ['logo.png', $png, 'sub/../logo.png'],
            'a stored name with a backslash in it' => ['logo.png', $png, 'sub\\logo.png'],
            'a stored name with a NUL in it' => ['logo.png', $png, "logo\0.png"],
            'a stored name with a control character in it' => ['logo.png', $png, "lo\x07go.png"],
            // the CLIENT's name is judged even when the caller's own name is harmless (a fixed name over a client-named upload)
            'an HTML name the caller replaced with a fixed one' => ['page.html', $png, 'fixed_logo.png'],
            // the shape the guest checkout's receipt used to have: the extension is not the LAST thing in the name
            'an extension followed by a suffix, as the receipt was named' => ['logo.png', $png, 'logo.png_1795000000'],
        ];
    }

    #[DataProvider('theForms')]
    public function test_every_form_of_the_uploader_refuses_what_a_server_would_act_on(string $form): void
    {
        foreach (self::whatMustNotBeStored() as $label => $case) {
            [$clientName, $bytes] = $case;
            $result = $this->through($form, $this->upload($clientName, $bytes), $case[2] ?? 'x_' . $clientName);

            $this->assertSame(0, (int) ($result['flag'] ?? -1), "{$form} stored or crashed on: {$label} ({$clientName}). Answer: " . json_encode($result));
            $this->assertSame([], $this->stored(), "{$form} left a file on disk for: {$label} ({$clientName}): " . json_encode($this->stored()));
            $this->assertIsString($result['msg'] ?? null);
            $this->assertNotSame('', $result['msg']);
        }
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // what must still be stored -- unchanged
    // ---------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2?: string}> the client's name, the bytes, and optionally the stored name (default `41_1795000000_<client name>`) */
    public static function whatMustStillBeStored(): array
    {
        return [
            'a PNG' => ['logo.png', (string) base64_decode(self::PNG)],
            'a PNG under an uppercase extension' => ['LOGO.PNG', (string) base64_decode(self::PNG)],
            'a PDF' => ['invoice.pdf', self::PDF],
            'a name with spaces and brackets, as a phone saves one' => ['IMG 0042 (1).png', (string) base64_decode(self::PNG)],
            'a name with a version in it' => ['logo-v2.final.png', (string) base64_decode(self::PNG)],
            // a script word as the BASE of a name is not a script extension: a technology logo is a legitimate upload
            'a logo named after a language' => ['js.png', (string) base64_decode(self::PNG)],
            'a logo named after a markup language' => ['html.png', (string) base64_decode(self::PNG)],
            // ... including when the language word is the whole BASE of the stored name (a fixed name, not a prefixed one)
            'a stored name whose base is a script word' => ['js.png', (string) base64_decode(self::PNG), 'js.png'],
            'a stored name whose base is php' => ['php.png', (string) base64_decode(self::PNG), 'php.png'],
        ];
    }

    #[DataProvider('theForms')]
    public function test_every_form_of_the_uploader_still_stores_a_file_under_the_name_the_caller_chose(string $form): void
    {
        foreach (self::whatMustStillBeStored() as $label => $case) {
            [$clientName, $bytes] = $case;
            $storedName = $case[2] ?? '41_1795000000_' . $clientName;
            Storage::fake('theme');
            Storage::fake('local');

            $result = $this->through($form, $this->upload($clientName, $bytes), $storedName);

            $this->assertSame(1, (int) ($result['flag'] ?? -1), "{$form} refused: {$label} ({$clientName} as {$storedName}). Answer: " . json_encode($result));
            $stored = $this->stored();
            $this->assertCount(1, $stored, "{$form}: exactly one file is stored for {$label}.");
            $this->assertStringEndsWith($storedName, $stored[0], "{$form} renamed {$label}: the callers persist what the function returns, but a rename is a change for every one of them.");
        }
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the configuration cannot be made to pass everything
    // ---------------------------------------------------------------------------------------------------------------------------

    /** `'mimes:'` with an empty list parses to `[null]`, which the rule compares loosely: PHP source was stored as `.png`. */
    public function test_an_empty_list_refuses_everything_and_does_not_pass_everything(): void
    {
        $this->platformStorage(validation: '');      // an EMPTY value: `!empty()` is false, so the code's own fallback is `''`

        $php = Utility::upload_file(['file' => $this->upload('x.png', self::PHP)], 'file', 'x.png', 'uploads/1');

        $this->assertSame(0, (int) $php['flag'], 'PHP source was stored under an image name because the configured list was empty: ' . json_encode($php));
        $this->assertSame('No file type is enabled for uploads.', $php['msg'], 'An empty list is refused as one, not by whichever rule happens to fail first.');
        $this->assertSame([], $this->stored());
    }

    /** The configured size ceiling still applies on every form, the sixth-argument one included. */
    public function test_the_configured_size_ceiling_applies_to_every_form(): void
    {
        $this->platformStorage(maxKb: '1');
        $big = $this->png() . str_repeat("\0", 3 * 1024);

        foreach (self::theForms() as $form => [$name]) {
            Storage::fake('theme');
            Storage::fake('local');

            $over = $this->through($name, $this->upload('big.png', $big), '41_1795000000_big.png');
            $this->assertSame(0, (int) ($over['flag'] ?? -1), "{$form} stored a 3 KB file under a 1 KB ceiling: " . json_encode($over));
            $this->assertSame([], $this->stored(), "{$form} left an over-size file on disk.");

            $under = $this->through($name, $this->upload('small.png', $this->png()), '41_1795000000_small.png');
            $this->assertSame(1, (int) ($under['flag'] ?? -1), "{$form} refused a small file under a 1 KB ceiling: " . json_encode($under));
        }
    }

    /** A merchant can write the platform list; it can not be made to carry what a server would run. */
    public function test_a_configured_list_cannot_carry_a_script_extension(): void
    {
        $this->platformStorage(validation: 'jpg,png,html,js,php,svg');

        foreach (['page.html' => self::HTML, 'code.js' => "document.title='x';\n"] as $name => $bytes) {
            $result = Utility::upload_file(['file' => $this->upload($name, $bytes)], 'file', 'x_' . $name, 'uploads/1');

            $this->assertSame(0, (int) $result['flag'], "{$name} was stored because the configured list named its extension: " . json_encode($result));
        }

        $this->assertSame([], $this->stored());
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // who is uploading
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * A REQUEST WITH NO PANEL SESSION IS A SHOPPER'S. Tickets, refund evidence, a guest checkout's receipt and attachments, a
     * delivery driver's profile picture: none has a `web` user, two of the callers are vendored and cannot be told which list to
     * use, and an SVG is a document that can carry script on the application's own origin. The panel keeps the platform list.
     */
    public function test_a_request_with_no_panel_session_may_not_store_an_svg_and_a_panel_user_may(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="2" height="2"><rect width="2" height="2"/></svg>';

        $shopper = Utility::upload_file(['file' => $this->upload('logo.svg', $svg)], 'file', 'shopper_logo.svg', 'uploads/1');
        $this->assertSame(0, (int) $shopper['flag'], 'A request with no panel session stored an SVG: ' . json_encode($shopper));
        $this->assertSame([], $this->stored());

        $this->actingAs($this->merchant);

        $panel = Utility::upload_file(['file' => $this->upload('logo.svg', $svg)], 'file', 'panel_logo.svg', 'uploads/1');
        $this->assertSame(1, (int) $panel['flag'], 'A panel user can no longer store an SVG logo: ' . json_encode($panel));
        $this->assertCount(1, $this->stored());
    }

    /** The lists as literals: a rule that follows its own constants proves nothing about their values. */
    public function test_the_allowed_list_is_the_configured_one_minus_what_nobody_may_store(): void
    {
        $this->assertSame(['jpg', 'jpeg', 'png', 'csv', 'svg', 'pdf'], PlatformUploadPolicy::allowed('jpg,jpeg,png,csv,svg,pdf', true));
        $this->assertSame(['jpg', 'jpeg', 'png', 'csv', 'pdf'], PlatformUploadPolicy::allowed('jpg,jpeg,png,csv,svg,pdf', false), 'A shopper\'s list carries an SVG.');
        $this->assertSame(['png', 'jpg', 'jpeg'], PlatformUploadPolicy::allowed('png,jpg', true), 'jpg and jpeg travel together, as they do in the framework\'s own mimes rule.');
        $this->assertSame([], PlatformUploadPolicy::allowed('html,js,php,PHP5,phtml,pht', true), 'A script extension survived a configured list.');
        $this->assertSame([], PlatformUploadPolicy::allowed('', true));
        $this->assertSame(['png'], PlatformUploadPolicy::allowed(' .PNG , png ,, ', true), 'The list is trimmed, lower-cased and de-duplicated.');
        $this->assertSame(['htm', 'html', 'xhtml', 'shtml', 'js', 'mjs', 'htaccess', 'php', 'php5', 'pht', 'phtml', 'phar'], array_values(array_intersect(['htm', 'html', 'xhtml', 'shtml', 'js', 'mjs', 'htaccess', 'php', 'php5', 'pht', 'phtml', 'phar'], PlatformUploadPolicy::SCRIPT_CAPABLE)));
        $this->assertSame(['svg', 'svgz', 'xml', 'xsl', 'xslt'], PlatformUploadPolicy::SHOPPER_DENIED);
    }

    /** A caller's OWN rule array (UploadRules::for()) replaces the configured list; it still may not carry a script name. */
    public function test_a_callers_own_rules_still_judge_the_name_and_the_clients_extension(): void
    {
        $rules = ['mimes:mp4,webm,mov', 'extensions:mp4,webm,mov', 'max:51200'];
        $mp4 = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2mp41";

        $ok = Utility::upload_file(['file' => $this->upload('clip.mp4', $mp4)], 'file', 'video_1795000000.mp4', 'uploads/1/video', $rules);
        $this->assertSame(1, (int) $ok['flag'], 'A real clip under a listed name was refused: ' . json_encode($ok));

        Storage::fake('theme');
        foreach (['clip.html' => 'video_1795000000.html', 'clip.php.mp4' => 'video_1795000000.php.mp4', 'clip.mp4' => 'video_1795000000.js'] as $client => $stored) {
            $result = Utility::upload_file(['file' => $this->upload($client, $mp4)], 'file', $stored, 'uploads/1/video', $rules);
            $this->assertSame(0, (int) $result['flag'], "{$client} stored as {$stored} was accepted: " . json_encode($result));
        }
        $this->assertSame([], $this->stored());

        // A caller's rules that name NO `extensions:` (UploadRules refuses such a list, but a direct caller can pass one): the client's own
        // extension is still not allowed to be a script, whatever name the caller then gives the file.
        $bare = Utility::upload_file(['file' => $this->upload('page.html', $this->png())], 'file', 'fixed_logo.png', 'uploads/1', ['mimes:png', 'max:100']);
        $this->assertSame(0, (int) $bare['flag'], 'A client-named HTML file was stored under a fixed name because the caller\'s own rules named no extension: ' . json_encode($bare));
        $this->assertSame([], $this->stored());
    }

    /** `jsonUpload_file()` called `Utility::Seting()`, which does not exist: wherever the platform had no storage row it answered with the exception text. */
    public function test_json_upload_file_works_when_the_platform_has_no_storage_setting_row(): void
    {
        DB::table('settings')->delete();
        (new \App\Models\Setting)->flushCache();

        $result = Utility::jsonUpload_file(Request::create('/x', 'POST'), 'file', $this->upload('logo.png', $this->png()), '41_1795000000_logo.png', 'uploads/1');

        $this->assertSame(1, (int) $result['flag'], 'jsonUpload_file crashed without a storage row: ' . json_encode($result));
        $this->assertCount(1, $this->stored());
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the refusal leaves a line, and not the client's name
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_refusal_is_logged_with_a_reason_and_a_word_and_never_the_clients_name(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function ($event) use (&$logged) {
            if ($event->message === 'a platform upload was refused') {
                $logged[] = $event;
            }
        });

        Utility::upload_file(['file' => $this->upload('Jane Roe passport.html', $this->png())], 'file', 'Jane Roe passport.html', 'uploads/1');
        Utility::upload_file(['file' => $this->upload('scan.JaneRoe1985', $this->png())], 'file', 'scan.JaneRoe1985', 'uploads/1');
        Utility::upload_file(['file' => 'uploads/logo/logo-dark.png'], 'file', 'x.png', 'uploads/1');
        Utility::upload_file(['file' => $this->upload('shell.php.png', $this->png())], 'file', 'shell.php.png', 'uploads/1');
        Utility::upload_file(['file' => $this->upload('photo.png', self::PHP)], 'file', 'photo.png', 'uploads/1');

        $this->assertCount(5, $logged);
        $this->assertSame('warning', $logged[0]->level);
        $this->assertSame(['reason' => 'name', 'sent' => 'file', 'client_extension' => 'html', 'shopper' => true], $logged[0]->context);
        $this->assertSame(['name', 'other'], [$logged[1]->context['reason'], $logged[1]->context['client_extension']], 'An extension that is a person\'s name was logged.');
        $this->assertSame(['not_a_file', 'string', null], [$logged[2]->context['reason'], $logged[2]->context['sent'], $logged[2]->context['client_extension']]);
        $this->assertSame('inner_extension', $logged[3]->context['reason']);
        $this->assertSame('rule', $logged[4]->context['reason']);
        $this->assertDoesNotMatchRegularExpression('/jane|roe|passport|1985/i', (string) json_encode(array_map(fn ($e) => $e->context, $logged)), 'The log carries text the client typed.');

        $this->actingAs($this->merchant);
        Utility::upload_file(['file' => $this->upload('page.html', $this->png())], 'file', 'page.html', 'uploads/1');
        $this->assertFalse($logged[5]->context['shopper'], 'A panel user was logged as a shopper.');
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // a write that failed is a failure
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * The `theme` disk is configured `throw => false`, so a failed write is a `false` return that used to be reported as success --
     * by every one of the writers, each of which has its own check. `multi_upload_file` writes to the disk NAMED by the storage setting.
     */
    #[DataProvider('theForms')]
    public function test_a_write_that_failed_is_reported_as_a_failure_by_every_form(string $form): void
    {
        $failing = new class {
            public function exists(...$arguments)
            {
                return true;
            }

            public function path(...$arguments)
            {
                return sys_get_temp_dir();
            }

            public function makeDirectory(...$arguments)
            {
                return true;
            }

            public function putFileAs(...$arguments)
            {
                return false;
            }
        };

        Storage::set($form === 'multi_upload_file' ? 'local' : 'theme', $failing);

        $result = $this->through($form, $this->upload('logo.png', $this->png()), '41_1795000000_logo.png');

        $this->assertSame(0, (int) ($result['flag'] ?? -1), "{$form} reported a write that failed as a stored file: " . json_encode($result));
    }
}
